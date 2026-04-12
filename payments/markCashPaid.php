<?php

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!isset($_POST['token'], $_POST['booking_id'])) {
    echo json_encode([
        "success" => false,
        "message" => "Token and booking_id are required"
    ]);
    exit;
}

$token = trim($_POST['token']);
$booking_id = (int) $_POST['booking_id'];

$is_vendor = isVendor($token);
$is_admin = isAdmin($token);

if (!$is_vendor && !$is_admin) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized user"
    ]);
    exit;
}

$actor_id = getUserIdByToken($token);

if (!$actor_id) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

if ($booking_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid booking ID"
    ]);
    exit;
}

/**
 * If vendor:
 * - only allow bookings belonging to that vendor
 *
 * If admin:
 * - allow any booking
 */
if ($is_vendor) {
    $sqlCheck = "
        SELECT
            b.booking_id,
            b.status AS booking_status,
            p.payment_id,
            p.payment_method,
            p.status AS payment_status,
            p.remaining_amount
        FROM bookings b
        INNER JOIN booking_rooms br ON br.booking_id = b.booking_id
        INNER JOIN rooms r ON r.room_id = br.room_id
        INNER JOIN payments p ON p.booking_id = b.booking_id
        WHERE b.booking_id = ?
          AND r.vendor_id = ?
        GROUP BY
            b.booking_id,
            b.status,
            p.payment_id,
            p.payment_method,
            p.status,
            p.remaining_amount
        LIMIT 1
    ";

    $stmtCheck = mysqli_prepare($con, $sqlCheck);

    if (!$stmtCheck) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare payment verification query"
        ]);
        exit;
    }

    mysqli_stmt_bind_param($stmtCheck, "ii", $booking_id, $actor_id);
} else {
    $sqlCheck = "
        SELECT
            b.booking_id,
            b.status AS booking_status,
            p.payment_id,
            p.payment_method,
            p.status AS payment_status,
            p.remaining_amount
        FROM bookings b
        INNER JOIN payments p ON p.booking_id = b.booking_id
        WHERE b.booking_id = ?
        LIMIT 1
    ";

    $stmtCheck = mysqli_prepare($con, $sqlCheck);

    if (!$stmtCheck) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare admin payment verification query"
        ]);
        exit;
    }

    mysqli_stmt_bind_param($stmtCheck, "i", $booking_id);
}

mysqli_stmt_execute($stmtCheck);
$resultCheck = mysqli_stmt_get_result($stmtCheck);

if (!$resultCheck || mysqli_num_rows($resultCheck) === 0) {
    echo json_encode([
        "success" => false,
        "message" => $is_vendor
            ? "Booking not found for this vendor"
            : "Booking not found"
    ]);
    exit;
}

$row = mysqli_fetch_assoc($resultCheck);

$payment_id = (int) $row['payment_id'];
$payment_method = strtolower(trim((string) ($row['payment_method'] ?? '')));
$payment_status = strtolower(trim((string) ($row['payment_status'] ?? 'pending')));
$booking_status = strtolower(trim((string) ($row['booking_status'] ?? '')));

if ($payment_method !== 'cash') {
    echo json_encode([
        "success" => false,
        "message" => "Only pay-at-hotel bookings can be marked as received here"
    ]);
    exit;
}

if ($payment_status === 'paid') {
    echo json_encode([
        "success" => true,
        "message" => "Payment is already marked as paid"
    ]);
    exit;
}

if (!in_array($booking_status, ['confirmed', 'checked_in', 'completed'], true)) {
    echo json_encode([
        "success" => false,
        "message" => "Payment can only be marked for confirmed, checked-in, or completed bookings"
    ]);
    exit;
}

$sqlUpdate = "
    UPDATE payments
    SET
        status = 'paid',
        remaining_amount = 0.00,
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

mysqli_stmt_bind_param($stmtUpdate, "i", $payment_id);

if (!mysqli_stmt_execute($stmtUpdate)) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to mark payment as paid"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Cash payment marked as received successfully"
]);