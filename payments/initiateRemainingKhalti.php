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

if (!isset($_POST['token'], $_POST['booking_id'])) {
    echo json_encode([
        "success" => false,
        "message" => "Token and booking_id are required"
    ]);
    exit;
}

$token = trim($_POST['token']);
$booking_id = (int) $_POST['booking_id'];

$user_id = getUserIdByToken($token);

if (!$user_id) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

if ($booking_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid booking id"
    ]);
    exit;
}

$khaltiSecretKey = "ee1b82b5d1c840d58e0957abbcf5dc69";
$khaltiInitiateUrl = "https://dev.khalti.com/api/v2/epayment/initiate/";
$returnUrl = "http://localhost:5173/payment/khalti-return";
$websiteUrl = "http://localhost:5173";

$sql = "
    SELECT
        p.payment_id,
        p.booking_id,
        p.user_id,
        p.amount,
        p.payment_method,
        p.payment_type,
        p.status,
        p.pidx,
        p.purchase_order_id,
        p.purchase_order_name,
        p.advance_amount,
        p.remaining_amount,
        u.full_name,
        u.email,
        u.phone
    FROM payments p
    INNER JOIN users u ON u.user_id = p.user_id
    WHERE p.booking_id = ?
      AND p.user_id = ?
    LIMIT 1
";

$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare payment lookup"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, "ii", $booking_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) === 0) {
    mysqli_stmt_close($stmt);
    echo json_encode([
        "success" => false,
        "message" => "Payment record not found for this booking"
    ]);
    exit;
}

$payment = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

$paymentMethod = strtolower(trim((string) ($payment['payment_method'] ?? '')));
$currentStatus = strtolower(trim((string) ($payment['status'] ?? 'pending')));
$remainingAmount = round((float) ($payment['remaining_amount'] ?? 0), 2);

if ($paymentMethod !== 'khalti') {
    echo json_encode([
        "success" => false,
        "message" => "This booking is not a Khalti booking"
    ]);
    exit;
}

if ($currentStatus === 'paid') {
    echo json_encode([
        "success" => false,
        "message" => "This booking is already fully paid"
    ]);
    exit;
}

if ($currentStatus !== 'partial') {
    echo json_encode([
        "success" => false,
        "message" => "Remaining payment is only available after advance payment completion"
    ]);
    exit;
}

if ($remainingAmount <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "No remaining amount left to pay"
    ]);
    exit;
}

/**
 * Very important:
 * remaining payment must use remaining_amount only
 */
$amountInPaisa = (int) round($remainingAmount * 100);

/**
 * Make purchase order unique every time to avoid session reuse/mismatch
 */
$uniqueSuffix = time() . '-' . $booking_id;

$basePurchaseOrderId = !empty($payment['purchase_order_id'])
    ? (string) $payment['purchase_order_id']
    : 'BOOK-' . $booking_id;

$purchaseOrderId = $basePurchaseOrderId . '-REMAINING-' . $uniqueSuffix;

$basePurchaseOrderName = !empty($payment['purchase_order_name'])
    ? (string) $payment['purchase_order_name']
    : 'StayEase Booking #' . $booking_id;

$purchaseOrderName = $basePurchaseOrderName . ' Remaining Payment';

$payload = [
    "return_url" => $returnUrl,
    "website_url" => $websiteUrl,
    "amount" => $amountInPaisa,
    "purchase_order_id" => $purchaseOrderId,
    "purchase_order_name" => $purchaseOrderName,
    "customer_info" => [
        "name" => (string) ($payment['full_name'] ?? 'StayEase Customer'),
        "email" => (string) ($payment['email'] ?? 'customer@example.com'),
        "phone" => (string) ($payment['phone'] ?? '9800000000')
    ],
    "merchant_username" => "stayease",
    "merchant_extra" => "booking_id:" . $booking_id . "|mode:remaining|remaining_amount:" . $remainingAmount
];

$ch = curl_init($khaltiInitiateUrl);

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
        "message" => "Failed to connect to Khalti",
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

if ($httpCode < 200 || $httpCode >= 300 || empty($data['pidx']) || empty($data['payment_url'])) {
    echo json_encode([
        "success" => false,
        "message" => $data['detail'] ?? $data['message'] ?? "Khalti initiation failed",
        "khalti_response" => $data
    ]);
    exit;
}

$newPidx = trim((string) $data['pidx']);

/**
 * Save the fresh remaining-payment session
 */
$sqlUpdate = "
    UPDATE payments
    SET
        pidx = ?,
        purchase_order_id = ?,
        purchase_order_name = ?,
        status = 'initiated',
        updated_at = CURRENT_TIMESTAMP
    WHERE payment_id = ?
    LIMIT 1
";

$stmtUpdate = mysqli_prepare($con, $sqlUpdate);

if (!$stmtUpdate) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare payment update"
    ]);
    exit;
}

$payment_id = (int) $payment['payment_id'];

mysqli_stmt_bind_param(
    $stmtUpdate,
    "sssi",
    $newPidx,
    $purchaseOrderId,
    $purchaseOrderName,
    $payment_id
);

if (!mysqli_stmt_execute($stmtUpdate)) {
    mysqli_stmt_close($stmtUpdate);
    echo json_encode([
        "success" => false,
        "message" => "Failed to save Khalti remaining payment session"
    ]);
    exit;
}

mysqli_stmt_close($stmtUpdate);

echo json_encode([
    "success" => true,
    "message" => "Remaining Khalti payment initiated successfully",
    "data" => [
        "payment_id" => $payment_id,
        "booking_id" => $booking_id,
        "pidx" => $newPidx,
        "payment_url" => $data['payment_url'],
        "expires_at" => $data['expires_at'] ?? null,
        "expires_in" => $data['expires_in'] ?? null,
        "amount" => $remainingAmount,
        "amount_in_paisa" => $amountInPaisa,
        "payment_type" => "remaining",
        "payment_method" => $paymentMethod,
        "purchase_order_id" => $purchaseOrderId
    ]
]);