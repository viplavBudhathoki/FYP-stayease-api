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

$sqlBooking = "
    SELECT 
        b.booking_id,
        b.user_id,
        b.room_id,
        b.status,
        r.hotel_id
    FROM bookings b
    INNER JOIN rooms r ON r.room_id = b.room_id
    WHERE b.booking_id = '$booking_id'
      AND b.user_id = '$user_id'
    LIMIT 1
";

$resultBooking = mysqli_query($con, $sqlBooking);

if (!$resultBooking || mysqli_num_rows($resultBooking) === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Booking not found"
    ]);
    exit;
}

$booking = mysqli_fetch_assoc($resultBooking);

if ($booking['status'] !== 'completed') {
    echo json_encode([
        "success" => false,
        "message" => "Review is allowed only after completed stay"
    ]);
    exit;
}

$check = mysqli_query(
    $con,
    "SELECT rating_id FROM ratings WHERE booking_id = '$booking_id' LIMIT 1"
);

if (!$check) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to check previous review"
    ]);
    exit;
}

if (mysqli_num_rows($check) > 0) {
    echo json_encode([
        "success" => false,
        "message" => "You already reviewed this booking"
    ]);
    exit;
}

$hotel_id = (int) $booking['hotel_id'];
$room_id = (int) $booking['room_id'];

$sqlInsert = "
    INSERT INTO ratings (
        booking_id,
        hotel_id,
        room_id,
        user_id,
        rating,
        review_message
    ) VALUES (
        '$booking_id',
        '$hotel_id',
        '$room_id',
        '$user_id',
        '$rating',
        " . ($review !== null && $review !== "" ? "'$review'" : "NULL") . "
    )
";

$resultInsert = mysqli_query($con, $sqlInsert);

if (!$resultInsert) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to submit review"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Review submitted successfully"
]);