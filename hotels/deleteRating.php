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

$checkBooking = mysqli_query(
    $con,
    "SELECT booking_id, status FROM bookings WHERE booking_id='$booking_id' AND user_id='$user_id' LIMIT 1"
);

if (!$checkBooking || mysqli_num_rows($checkBooking) === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Booking not found"
    ]);
    exit;
}

$check = mysqli_query(
    $con,
    "SELECT rating_id FROM ratings WHERE booking_id='$booking_id' AND user_id='$user_id' LIMIT 1"
);

if (!$check || mysqli_num_rows($check) === 0) {
    echo json_encode([
        "success" => false,
        "message" => "No review found to delete"
    ]);
    exit;
}

$sql = "DELETE FROM ratings WHERE booking_id='$booking_id' AND user_id='$user_id'";
$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to delete review"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Review deleted successfully"
]);