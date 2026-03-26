<?php

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'], $_POST['booking_id'], $_POST['check_out'])) {
    echo json_encode([
        "success" => false,
        "message" => "token, booking_id and check_out are required"
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

if (!$booking_id || $new_check_out === '') {
    echo json_encode([
        "success" => false,
        "message" => "Invalid booking data"
    ]);
    exit;
}

$checkOutDate = DateTime::createFromFormat('Y-m-d', $new_check_out);
if (!$checkOutDate || $checkOutDate->format('Y-m-d') !== $new_check_out) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid check-out date"
    ]);
    exit;
}

$sqlBooking = "
    SELECT
        b.booking_id,
        b.user_id,
        b.check_in,
        b.check_out,
        b.status,
        b.rooms_requested,
        b.adults,
        b.children
    FROM bookings b
    WHERE b.booking_id = ?
      AND b.user_id = ?
    LIMIT 1
";

$stmtBooking = mysqli_prepare($con, $sqlBooking);

if (!$stmtBooking) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare booking query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtBooking, "ii", $booking_id, $user_id);
mysqli_stmt_execute($stmtBooking);
$resultBooking = mysqli_stmt_get_result($stmtBooking);

if (!$resultBooking || mysqli_num_rows($resultBooking) === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Booking not found"
    ]);
    exit;
}

$booking = mysqli_fetch_assoc($resultBooking);

if (!in_array($booking['status'], ['confirmed', 'checked_in'], true)) {
    echo json_encode([
        "success" => false,
        "message" => "Only confirmed or checked-in bookings can be updated"
    ]);
    exit;
}

$check_in = $booking['check_in'];
$old_check_out = $booking['check_out'];

if ($new_check_out <= $check_in) {
    echo json_encode([
        "success" => false,
        "message" => "Check-out must be after check-in"
    ]);
    exit;
}

if ($new_check_out === $old_check_out) {
    echo json_encode([
        "success" => false,
        "message" => "No date changes found"
    ]);
    exit;
}

/*
  Rules:
  - confirmed: customer can update before check-in date
  - checked_in: customer can extend or shorten during active stay
*/
if ($booking['status'] === 'confirmed' && date('Y-m-d') >= $check_in) {
    echo json_encode([
        "success" => false,
        "message" => "Confirmed booking dates cannot be modified on or after check-in date"
    ]);
    exit;
}

/* get all booked rooms and prices */
$sqlRooms = "
    SELECT br.room_id, br.price_per_night
    FROM booking_rooms br
    WHERE br.booking_id = ?
";

$stmtRooms = mysqli_prepare($con, $sqlRooms);

if (!$stmtRooms) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare booking rooms query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtRooms, "i", $booking_id);
mysqli_stmt_execute($stmtRooms);
$resultRooms = mysqli_stmt_get_result($stmtRooms);

$room_ids = [];
$total_price_per_night = 0;

while ($room = mysqli_fetch_assoc($resultRooms)) {
    $room_ids[] = (int) $room['room_id'];
    $total_price_per_night += (float) $room['price_per_night'];
}

if (count($room_ids) === 0) {
    echo json_encode([
        "success" => false,
        "message" => "No rooms found for this booking"
    ]);
    exit;
}

/* overlap check for every booked room against other bookings */
foreach ($room_ids as $room_id) {
    $sqlOverlap = "
        SELECT b.booking_id
        FROM booking_rooms br
        INNER JOIN bookings b ON b.booking_id = br.booking_id
        WHERE br.room_id = ?
          AND b.booking_id != ?
          AND b.status IN ('confirmed', 'checked_in')
          AND (
                (? < b.check_out) AND (? > b.check_in)
              )
        LIMIT 1
    ";

    $stmtOverlap = mysqli_prepare($con, $sqlOverlap);

    if (!$stmtOverlap) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare overlap check"
        ]);
        exit;
    }

    mysqli_stmt_bind_param($stmtOverlap, "iiss", $room_id, $booking_id, $check_in, $new_check_out);
    mysqli_stmt_execute($stmtOverlap);
    $resultOverlap = mysqli_stmt_get_result($stmtOverlap);

    if ($resultOverlap && mysqli_num_rows($resultOverlap) > 0) {
        echo json_encode([
            "success" => false,
            "message" => "One or more booked rooms are unavailable for the updated date range"
        ]);
        exit;
    }
}

/* recalculate total */
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

$new_total_price = $nights * $total_price_per_night;

$sqlUpdate = "
    UPDATE bookings
    SET check_out = ?,
        total_price = ?
    WHERE booking_id = ?
      AND user_id = ?
      AND status IN ('confirmed', 'checked_in')
";

$stmtUpdate = mysqli_prepare($con, $sqlUpdate);

if (!$stmtUpdate) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare booking update"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtUpdate, "sdii", $new_check_out, $new_total_price, $booking_id, $user_id);
$resultUpdate = mysqli_stmt_execute($stmtUpdate);

if (!$resultUpdate) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to update booking"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Booking dates updated successfully",
    "data" => [
        "booking_id" => $booking_id,
        "check_in" => $check_in,
        "check_out" => $new_check_out,
        "nights" => $nights,
        "total_price" => $new_total_price
    ]
]);