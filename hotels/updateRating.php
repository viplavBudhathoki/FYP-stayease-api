<?php

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'], $_POST['booking_id'], $_POST['rating'])) {
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
$rating = (float) $_POST['rating'];
$review = isset($_POST['review_message'])
    ? mysqli_real_escape_string($con, trim($_POST['review_message']))
    : null;

if ($rating < 0.5 || $rating > 5) {
    echo json_encode([
        "success" => false,
        "message" => "Rating must be between 0.5 and 5"
    ]);
    exit;
}

if (fmod($rating * 10, 5) !== 0.0) {
    echo json_encode([
        "success" => false,
        "message" => "Rating must be in 0.5 steps"
    ]);
    exit;
}

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

$booking = mysqli_fetch_assoc($checkBooking);

if ($booking['status'] !== 'completed') {
    echo json_encode([
        "success" => false,
        "message" => "Only completed bookings can update reviews"
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
        "message" => "No review found to update"
    ]);
    exit;
}

$sql = "
    UPDATE ratings
    SET rating='$rating',
        review_message=" . ($review !== null && $review !== "" ? "'$review'" : "NULL") . "
    WHERE booking_id='$booking_id' AND user_id='$user_id'
";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to update review"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Review updated successfully"
]);