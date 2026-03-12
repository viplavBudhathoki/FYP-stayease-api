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

$sql = "
    SELECT rating_id, booking_id, room_id, hotel_id, rating, review_message, created_at
    FROM ratings
    WHERE booking_id='$booking_id' AND user_id='$user_id'
    LIMIT 1
";

$result = mysqli_query($con, $sql);

if ($result && mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);

    echo json_encode([
        "success" => true,
        "has_review" => true,
        "data" => $row
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "has_review" => false
]);