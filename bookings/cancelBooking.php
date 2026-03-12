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

$check = mysqli_query(
    $con,
    "SELECT booking_id, status FROM bookings WHERE booking_id='$booking_id' AND user_id='$user_id' LIMIT 1"
);

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

$sql = "UPDATE bookings SET status='cancelled' WHERE booking_id='$booking_id' AND user_id='$user_id'";
$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to cancel booking"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Booking cancelled successfully"
]);