<?php

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'], $_POST['booking_id'], $_POST['status'])) {
    echo json_encode([
        "success" => false,
        "message" => "Required fields missing"
    ]);
    exit;
}

$token = $_POST['token'];

if (!isVendor($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized vendor"
    ]);
    exit;
}

$vendor_id = getUserIdByToken($token);

if (!$vendor_id) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

$booking_id = (int) $_POST['booking_id'];
$new_status = trim($_POST['status']);

$allowed_statuses = ['confirmed', 'checked_in', 'completed', 'cancelled'];

if (!in_array($new_status, $allowed_statuses, true)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid booking status"
    ]);
    exit;
}

$sqlCheck = "
    SELECT 
        b.booking_id,
        b.status,
        r.room_id,
        r.status AS room_status
    FROM bookings b
    INNER JOIN rooms r ON r.room_id = b.room_id
    WHERE b.booking_id = '$booking_id'
      AND r.vendor_id = '$vendor_id'
    LIMIT 1
";

$resultCheck = mysqli_query($con, $sqlCheck);

if (!$resultCheck || mysqli_num_rows($resultCheck) === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Booking not found"
    ]);
    exit;
}

$booking = mysqli_fetch_assoc($resultCheck);
$current_status = $booking['status'];
$room_id = (int) $booking['room_id'];

if ($current_status === 'cancelled') {
    echo json_encode([
        "success" => false,
        "message" => "Cancelled booking cannot be updated"
    ]);
    exit;
}

if ($current_status === 'completed') {
    echo json_encode([
        "success" => false,
        "message" => "Completed booking cannot be changed"
    ]);
    exit;
}

/*
Allowed transitions:
confirmed -> checked_in
confirmed -> cancelled
checked_in -> completed
*/

if ($current_status === 'confirmed' && !in_array($new_status, ['checked_in', 'cancelled'], true)) {
    echo json_encode([
        "success" => false,
        "message" => "Confirmed booking can only be checked in or cancelled"
    ]);
    exit;
}

if ($current_status === 'checked_in' && $new_status !== 'completed') {
    echo json_encode([
        "success" => false,
        "message" => "Checked-in booking can only be completed"
    ]);
    exit;
}

mysqli_begin_transaction($con);

try {
    $sqlUpdateBooking = "
        UPDATE bookings
        SET status = '$new_status'
        WHERE booking_id = '$booking_id'
    ";

    $resultUpdateBooking = mysqli_query($con, $sqlUpdateBooking);

    if (!$resultUpdateBooking) {
        throw new Exception("Failed to update booking status");
    }

    $new_room_status = null;

    if ($new_status === 'checked_in') {
        $new_room_status = 'occupied';
    } elseif ($new_status === 'completed' || $new_status === 'cancelled') {
        $new_room_status = 'available';
    }

    if ($new_room_status !== null) {
        $sqlUpdateRoom = "
            UPDATE rooms
            SET status = '$new_room_status'
            WHERE room_id = '$room_id'
        ";

        $resultUpdateRoom = mysqli_query($con, $sqlUpdateRoom);

        if (!$resultUpdateRoom) {
            throw new Exception("Failed to update room status");
        }
    }

    mysqli_commit($con);

    echo json_encode([
        "success" => true,
        "message" => "Booking status updated successfully"
    ]);
} catch (Exception $e) {
    mysqli_rollback($con);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}