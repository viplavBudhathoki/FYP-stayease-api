<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';
include __DIR__ . '/../helpers/notification_helper.php';

if (!isset($_POST['token'], $_POST['pidx'])) {
    echo json_encode([
        "success" => false,
        "message" => "Token and pidx are required"
    ]);
    exit;
}

$token = trim($_POST['token']);
$pidx = trim($_POST['pidx']);

$user_id = getUserIdByToken($token);

if (!$user_id) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

if ($pidx === '') {
    echo json_encode([
        "success" => false,
        "message" => "Invalid pidx"
    ]);
    exit;
}

$khaltiSecretKey = "ee1b82b5d1c840d58e0957abbcf5dc69";
$khaltiLookupUrl = "https://dev.khalti.com/api/v2/epayment/lookup/";

$sqlPayment = "
    SELECT
        p.payment_id,
        p.booking_id,
        p.user_id,
        p.amount,
        p.payment_method,
        p.payment_type,
        p.status,
        p.pidx,
        p.advance_amount,
        p.remaining_amount,
        p.transaction_id,
        p.purchase_order_id,
        p.purchase_order_name,
        b.status AS booking_status,
        b.total_price,
        b.check_in,
        b.check_out
    FROM payments p
    INNER JOIN bookings b ON b.booking_id = p.booking_id
    WHERE p.pidx = ?
      AND p.user_id = ?
    LIMIT 1
";

$stmtPayment = mysqli_prepare($con, $sqlPayment);

if (!$stmtPayment) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare payment lookup"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtPayment, "si", $pidx, $user_id);
mysqli_stmt_execute($stmtPayment);
$resultPayment = mysqli_stmt_get_result($stmtPayment);

if (!$resultPayment || mysqli_num_rows($resultPayment) === 0) {
    mysqli_stmt_close($stmtPayment);

    echo json_encode([
        "success" => false,
        "message" => "Payment not found"
    ]);
    exit;
}

$payment = mysqli_fetch_assoc($resultPayment);
mysqli_stmt_close($stmtPayment);

$paymentMethod = strtolower(trim((string) ($payment['payment_method'] ?? '')));
$paymentType = strtolower(trim((string) ($payment['payment_type'] ?? 'full')));
$currentStatus = strtolower(trim((string) ($payment['status'] ?? 'pending')));
$remainingAmount = round((float) ($payment['remaining_amount'] ?? 0), 2);
$purchaseOrderId = (string) ($payment['purchase_order_id'] ?? '');

if ($paymentMethod !== 'khalti') {
    echo json_encode([
        "success" => false,
        "message" => "This payment is not a Khalti payment"
    ]);
    exit;
}

if ($currentStatus === 'paid') {
    echo json_encode([
        "success" => true,
        "message" => "Payment already verified",
        "data" => [
            "booking_id" => (int) $payment['booking_id'],
            "payment_id" => (int) $payment['payment_id'],
            "payment_status" => 'paid',
            "payment_type" => $paymentType,
            "pidx" => $pidx,
            "transaction_id" => $payment['transaction_id'] ?? null,
            "remaining_amount" => 0.00
        ]
    ]);
    exit;
}

$payload = ["pidx" => $pidx];

$ch = curl_init($khaltiLookupUrl);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Key {$khaltiSecretKey}",
        "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $curlError) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to connect to Khalti lookup API",
        "error" => $curlError
    ]);
    exit;
}

$data = json_decode($response, true);

if (!is_array($data)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid response from Khalti",
        "raw_response" => $response
    ]);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    echo json_encode([
        "success" => false,
        "message" => $data['detail'] ?? $data['message'] ?? "Khalti lookup failed",
        "khalti_response" => $data
    ]);
    exit;
}

$khaltiStatus = trim((string) ($data['status'] ?? ''));
$transactionId = isset($data['transaction_id']) ? trim((string) $data['transaction_id']) : null;
$totalAmountPaisa = (int) ($data['total_amount'] ?? 0);
$refunded = !empty($data['refunded']);

mysqli_begin_transaction($con);

try {
    $payment_id = (int) $payment['payment_id'];
    $booking_id = (int) $payment['booking_id'];

    $isRemainingPayment = stripos($purchaseOrderId, '-REMAINING-') !== false;

    if ($isRemainingPayment && $remainingAmount > 0) {
        $expectedAmount = round($remainingAmount, 2);
        $verificationMode = 'remaining';
    } elseif ($paymentType === 'partial') {
        $expectedAmount = round((float) ($payment['advance_amount'] ?? 0), 2);
        $verificationMode = 'advance';
    } else {
        $expectedAmount = round((float) ($payment['amount'] ?? 0), 2);
        $verificationMode = 'full';
    }

    $expectedAmountPaisa = (int) round($expectedAmount * 100);

    if ($khaltiStatus === 'Completed') {
        if ($totalAmountPaisa !== $expectedAmountPaisa) {
            throw new Exception("Khalti paid amount does not match expected payment amount");
        }

        if ($verificationMode === 'remaining') {
            $newPaymentStatus = 'paid';
            $newRemainingAmount = 0.00;
            $successMessage = "Remaining payment verified successfully";
            $notificationTitle = "Remaining payment successful";
            $notificationMessage = "Remaining payment for booking #{$booking_id} was completed successfully.";
        } elseif ($verificationMode === 'advance') {
            $newPaymentStatus = 'partial';
            $newRemainingAmount = round((float) ($payment['remaining_amount'] ?? 0), 2);
            $successMessage = "Advance payment verified successfully";
            $notificationTitle = "Advance payment successful";
            $notificationMessage = "Advance payment for booking #{$booking_id} was completed successfully.";
        } else {
            $newPaymentStatus = 'paid';
            $newRemainingAmount = 0.00;
            $successMessage = "Payment verified successfully";
            $notificationTitle = "Payment successful";
            $notificationMessage = "Full payment for booking #{$booking_id} was completed successfully.";
        }

        $sqlUpdatePayment = "
            UPDATE payments
            SET
                status = ?,
                transaction_id = ?,
                remaining_amount = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE payment_id = ?
            LIMIT 1
        ";

        $stmtUpdatePayment = mysqli_prepare($con, $sqlUpdatePayment);

        if (!$stmtUpdatePayment) {
            throw new Exception("Failed to prepare payment update");
        }

        mysqli_stmt_bind_param(
            $stmtUpdatePayment,
            "ssdi",
            $newPaymentStatus,
            $transactionId,
            $newRemainingAmount,
            $payment_id
        );

        if (!mysqli_stmt_execute($stmtUpdatePayment)) {
            mysqli_stmt_close($stmtUpdatePayment);
            throw new Exception("Failed to update payment status");
        }

        mysqli_stmt_close($stmtUpdatePayment);

        if (function_exists('createNotification')) {
            @createNotification(
                $con,
                $user_id,
                "payment",
                $notificationTitle,
                $notificationMessage,
                $booking_id
            );
        }

        mysqli_commit($con);

        echo json_encode([
            "success" => true,
            "message" => $successMessage,
            "data" => [
                "booking_id" => $booking_id,
                "payment_id" => $payment_id,
                "pidx" => $pidx,
                "transaction_id" => $transactionId,
                "khalti_status" => $khaltiStatus,
                "payment_status" => $newPaymentStatus,
                "payment_type" => $paymentType,
                "verification_mode" => $verificationMode,
                "paid_amount_paisa" => $totalAmountPaisa,
                "paid_amount" => round($totalAmountPaisa / 100, 2),
                "remaining_amount" => $newRemainingAmount
            ]
        ]);
        exit;
    }

    if ($khaltiStatus === 'Refunded' || $refunded) {
        $sqlRefunded = "
            UPDATE payments
            SET
                status = 'refunded',
                transaction_id = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE payment_id = ?
            LIMIT 1
        ";

        $stmtRefunded = mysqli_prepare($con, $sqlRefunded);

        if (!$stmtRefunded) {
            throw new Exception("Failed to prepare refund update");
        }

        mysqli_stmt_bind_param($stmtRefunded, "si", $transactionId, $payment_id);

        if (!mysqli_stmt_execute($stmtRefunded)) {
            mysqli_stmt_close($stmtRefunded);
            throw new Exception("Failed to update refund status");
        }

        mysqli_stmt_close($stmtRefunded);
        mysqli_commit($con);

        echo json_encode([
            "success" => true,
            "message" => "Payment has been refunded",
            "data" => [
                "booking_id" => $booking_id,
                "payment_id" => $payment_id,
                "payment_status" => "refunded",
                "khalti_status" => $khaltiStatus
            ]
        ]);
        exit;
    }

    if (in_array($khaltiStatus, ['Pending', 'Initiated'], true)) {
        $sqlPending = "
            UPDATE payments
            SET
                status = 'initiated',
                updated_at = CURRENT_TIMESTAMP
            WHERE payment_id = ?
            LIMIT 1
        ";

        $stmtPending = mysqli_prepare($con, $sqlPending);

        if (!$stmtPending) {
            throw new Exception("Failed to prepare pending payment update");
        }

        mysqli_stmt_bind_param($stmtPending, "i", $payment_id);

        if (!mysqli_stmt_execute($stmtPending)) {
            mysqli_stmt_close($stmtPending);
            throw new Exception("Failed to update initiated payment status");
        }

        mysqli_stmt_close($stmtPending);
        mysqli_commit($con);

        echo json_encode([
            "success" => true,
            "message" => "Payment is not completed yet",
            "data" => [
                "booking_id" => $booking_id,
                "payment_id" => $payment_id,
                "payment_status" => "initiated",
                "khalti_status" => $khaltiStatus
            ]
        ]);
        exit;
    }

    $sqlFailed = "
        UPDATE payments
        SET
            status = 'failed',
            updated_at = CURRENT_TIMESTAMP
        WHERE payment_id = ?
        LIMIT 1
    ";

    $stmtFailed = mysqli_prepare($con, $sqlFailed);

    if (!$stmtFailed) {
        throw new Exception("Failed to prepare failed payment update");
    }

    mysqli_stmt_bind_param($stmtFailed, "i", $payment_id);

    if (!mysqli_stmt_execute($stmtFailed)) {
        mysqli_stmt_close($stmtFailed);
        throw new Exception("Failed to update failed payment status");
    }

    mysqli_stmt_close($stmtFailed);
    mysqli_commit($con);

    echo json_encode([
        "success" => false,
        "message" => "Payment was not completed",
        "data" => [
            "booking_id" => $booking_id,
            "payment_id" => $payment_id,
            "payment_status" => "failed",
            "khalti_status" => $khaltiStatus
        ]
    ]);
} catch (Exception $e) {
    mysqli_rollback($con);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}