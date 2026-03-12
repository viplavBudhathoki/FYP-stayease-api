<?php

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'], $_POST['room_id'], $_POST['check_in'], $_POST['check_out'])) {
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

$room_id = (int) $_POST['room_id'];
$check_in = trim($_POST['check_in']);
$check_out = trim($_POST['check_out']);

if (!$room_id || !$check_in || !$check_out) {
    echo json_encode([
        "success" => false,
        "message" => "All booking fields are required"
    ]);
    exit;
}

if ($check_out <= $check_in) {
    echo json_encode([
        "success" => false,
        "message" => "Check-out must be after check-in"
    ]);
    exit;
}

/* get room */
$sqlRoom = "SELECT room_id, hotel_id, name, price, status FROM rooms WHERE room_id='$room_id' LIMIT 1";
$resultRoom = mysqli_query($con, $sqlRoom);

if (!$resultRoom || mysqli_num_rows($resultRoom) === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Room not found"
    ]);
    exit;
}

$room = mysqli_fetch_assoc($resultRoom);

if ($room['status'] !== 'available') {
    echo json_encode([
        "success" => false,
        "message" => "This room is not available for booking"
    ]);
    exit;
}

/* optional overlap check */
$sqlOverlap = "
    SELECT booking_id
    FROM bookings
    WHERE room_id = '$room_id'
      AND status IN ('confirmed', 'completed')
      AND (
            ('$check_in' < check_out) AND ('$check_out' > check_in)
          )
    LIMIT 1
";

$resultOverlap = mysqli_query($con, $sqlOverlap);

if ($resultOverlap && mysqli_num_rows($resultOverlap) > 0) {
    echo json_encode([
        "success" => false,
        "message" => "Room already booked for selected dates"
    ]);
    exit;
}

/* calculate nights */
$start = new DateTime($check_in);
$end = new DateTime($check_out);
$diff = $start->diff($end);
$nights = (int) $diff->days;

if ($nights <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid booking duration"
    ]);
    exit;
}

$total_price = $nights * (float) $room['price'];

$sqlInsert = "
    INSERT INTO bookings (user_id, room_id, check_in, check_out, total_price, status)
    VALUES ('$user_id', '$room_id', '$check_in', '$check_out', '$total_price', 'confirmed')
";

$resultInsert = mysqli_query($con, $sqlInsert);

if (!$resultInsert) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to book room"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Room booked successfully",
    "data" => [
        "booking_id" => mysqli_insert_id($con),
        "room_id" => $room_id,
        "check_in" => $check_in,
        "check_out" => $check_out,
        "nights" => $nights,
        "total_price" => $total_price,
        "status" => "confirmed"
    ]
]);