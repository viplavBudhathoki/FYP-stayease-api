<?php

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'], $_POST['booking_id'])) {
    echo json_encode([
        "success" => false,
        "message" => "Required fields missing"
    ]);
    exit;
}

$user_id = getUserIdByToken($_POST['token']);

if (!$user_id) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

$booking_id = (int) $_POST['booking_id'];

$sqlCheck = "
    SELECT booking_id, status
    FROM bookings
    WHERE booking_id = ?
      AND user_id = ?
    LIMIT 1
";

$stmtCheck = mysqli_prepare($con, $sqlCheck);

if (!$stmtCheck) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare booking check"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtCheck, "ii", $booking_id, $user_id);
mysqli_stmt_execute($stmtCheck);
$check = mysqli_stmt_get_result($stmtCheck);

if (!$check || mysqli_num_rows($check) === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Booking not found"
    ]);
    exit;
}

$booking = mysqli_fetch_assoc($check);

if ($booking['status'] !== 'confirmed') {
    echo json_encode([
        "success" => false,
        "message" => "Only confirmed bookings can be cancelled"
    ]);
    exit;
}

mysqli_begin_transaction($con);

try {
    $sql = "UPDATE bookings SET status = 'cancelled' WHERE booking_id = ? AND user_id = ?";
    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        throw new Exception("Failed to prepare booking cancel query");
    }

    mysqli_stmt_bind_param($stmt, "ii", $booking_id, $user_id);
    $result = mysqli_stmt_execute($stmt);

    if (!$result) {
        throw new Exception("Failed to cancel booking");
    }

    mysqli_commit($con);

    echo json_encode([
        "success" => true,
        "message" => "Booking cancelled successfully"
    ]);
} catch (Exception $e) {
    mysqli_rollback($con);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}