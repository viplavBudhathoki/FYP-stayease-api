<?php

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'], $_POST['booking_id'], $_POST['check_out'])) {
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
$new_check_out = trim($_POST['check_out']);

if (!$booking_id || !$new_check_out) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid booking data"
    ]);
    exit;
}

$sqlBooking = "
    SELECT 
        b.booking_id,
        b.user_id,
        b.room_id,
        b.check_in,
        b.check_out,
        b.total_price,
        b.status,
        r.price AS room_price
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

if ($booking['status'] !== 'confirmed') {
    echo json_encode([
        "success" => false,
        "message" => "Only confirmed bookings can be modified"
    ]);
    exit;
}

$check_in = $booking['check_in'];
$room_id = (int) $booking['room_id'];

if ($new_check_out <= $check_in) {
    echo json_encode([
        "success" => false,
        "message" => "Check-out must be after check-in"
    ]);
    exit;
}

/* overlap check excluding current booking */
$sqlOverlap = "
    SELECT booking_id
    FROM bookings
    WHERE room_id = '$room_id'
      AND booking_id != '$booking_id'
      AND status IN ('confirmed', 'checked_in')
      AND (
            ('$check_in' < check_out) AND ('$new_check_out' > check_in)
          )
    LIMIT 1
";

$resultOverlap = mysqli_query($con, $sqlOverlap);

if ($resultOverlap && mysqli_num_rows($resultOverlap) > 0) {
    echo json_encode([
        "success" => false,
        "message" => "This room is already booked for the updated date range"
    ]);
    exit;
}

/* calculate updated price */
$start = new DateTime($check_in);
$end = new DateTime($new_check_out);
$diff = $start->diff($end);
$nights = (int) $diff->days;

if ($nights <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid booking duration"
    ]);
    exit;
}

$room_price = (float) $booking['room_price'];
$total_price = $nights * $room_price;

$sqlUpdate = "
    UPDATE bookings
    SET check_out = '$new_check_out',
        total_price = '$total_price'
    WHERE booking_id = '$booking_id'
      AND user_id = '$user_id'
";

$resultUpdate = mysqli_query($con, $sqlUpdate);

if (!$resultUpdate) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to update booking"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Booking stay updated successfully",
    "data" => [
        "booking_id" => $booking_id,
        "check_in" => $check_in,
        "check_out" => $new_check_out,
        "nights" => $nights,
        "total_price" => $total_price
    ]
]);