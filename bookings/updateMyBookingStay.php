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
$new_check_in = isset($_POST['check_in']) ? trim($_POST['check_in']) : null;

if (!$booking_id || !$new_check_out) {
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

if ($new_check_in !== null && $new_check_in !== '') {
    $checkInDate = DateTime::createFromFormat('Y-m-d', $new_check_in);
    if (!$checkInDate || $checkInDate->format('Y-m-d') !== $new_check_in) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid check-in date"
        ]);
        exit;
    }
}

$sqlBooking = "
    SELECT 
        booking_id,
        user_id,
        check_in,
        check_out,
        total_price,
        status
    FROM bookings
    WHERE booking_id = ?
      AND user_id = ?
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

if (!in_array($booking['status'], ['confirmed', 'checked_in'])) {
    echo json_encode([
        "success" => false,
        "message" => "Only active bookings can be modified"
    ]);
    exit;
}

$final_check_in = $booking['check_in'];

if ($booking['status'] === 'confirmed' && $new_check_in && $new_check_in !== $booking['check_in']) {
    $today = date('Y-m-d');
    if ($new_check_in < $today) {
        echo json_encode([
            "success" => false,
            "message" => "Check-in date cannot be in the past"
        ]);
        exit;
    }
    $final_check_in = $new_check_in;
}

if ($new_check_out <= $final_check_in) {
    echo json_encode([
        "success" => false,
        "message" => "Check-out must be after check-in"
    ]);
    exit;
}

/* overlap check for all rooms in this booking */
$sqlOverlap = "
    SELECT 1
    FROM booking_rooms current_br
    INNER JOIN booking_rooms other_br ON other_br.room_id = current_br.room_id
    INNER JOIN bookings other_b ON other_b.booking_id = other_br.booking_id
    WHERE current_br.booking_id = ?
      AND other_b.booking_id != ?
      AND other_b.status IN ('confirmed', 'checked_in')
      AND (? < other_b.check_out)
      AND (? > other_b.check_in)
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

mysqli_stmt_bind_param($stmtOverlap, "iiss", $booking_id, $booking_id, $final_check_in, $new_check_out);
mysqli_stmt_execute($stmtOverlap);
$resultOverlap = mysqli_stmt_get_result($stmtOverlap);

if ($resultOverlap && mysqli_num_rows($resultOverlap) > 0) {
    echo json_encode([
        "success" => false,
        "message" => "One of the booked rooms is already reserved for the updated date range"
    ]);
    exit;
}

/* calculate updated price from booking_rooms */
$sqlPrice = "
    SELECT COALESCE(SUM(price_per_night), 0) AS total_price_per_night
    FROM booking_rooms
    WHERE booking_id = ?
";

$stmtPrice = mysqli_prepare($con, $sqlPrice);

if (!$stmtPrice) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare price query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtPrice, "i", $booking_id);
mysqli_stmt_execute($stmtPrice);
$resultPrice = mysqli_stmt_get_result($stmtPrice);
$priceRow = mysqli_fetch_assoc($resultPrice);

$total_price_per_night = (float) ($priceRow['total_price_per_night'] ?? 0);

$start = new DateTime($final_check_in);
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

$total_price = $nights * $total_price_per_night;

$sqlUpdate = "
    UPDATE bookings
    SET check_in = ?,
        check_out = ?,
        total_price = ?
    WHERE booking_id = ?
      AND user_id = ?
";

$stmtUpdate = mysqli_prepare($con, $sqlUpdate);

if (!$stmtUpdate) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare booking update"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtUpdate, "ssdii", $final_check_in, $new_check_out, $total_price, $booking_id, $user_id);
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
    "message" => "Booking stay updated successfully",
    "data" => [
        "booking_id" => $booking_id,
        "check_in" => $final_check_in,
        "check_out" => $new_check_out,
        "nights" => $nights,
        "total_price" => $total_price
    ]
]);