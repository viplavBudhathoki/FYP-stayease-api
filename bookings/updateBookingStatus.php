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
        COUNT(br.room_id) AS room_count
    FROM bookings b
    INNER JOIN booking_rooms br ON br.booking_id = b.booking_id
    INNER JOIN rooms r ON r.room_id = br.room_id
    WHERE b.booking_id = ?
      AND r.vendor_id = ?
    GROUP BY b.booking_id, b.status
    LIMIT 1
";

$stmtCheck = mysqli_prepare($con, $sqlCheck);

if (!$stmtCheck) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare booking check query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtCheck, "ii", $booking_id, $vendor_id);
mysqli_stmt_execute($stmtCheck);
$resultCheck = mysqli_stmt_get_result($stmtCheck);

if (!$resultCheck || mysqli_num_rows($resultCheck) === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Booking not found"
    ]);
    exit;
}

$booking = mysqli_fetch_assoc($resultCheck);
$current_status = $booking['status'];

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
        SET status = ?
        WHERE booking_id = ?
    ";

    $stmtUpdateBooking = mysqli_prepare($con, $sqlUpdateBooking);

    if (!$stmtUpdateBooking) {
        throw new Exception("Failed to prepare booking update");
    }

    mysqli_stmt_bind_param($stmtUpdateBooking, "si", $new_status, $booking_id);
    $resultUpdateBooking = mysqli_stmt_execute($stmtUpdateBooking);

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
        $sqlGetRooms = "
            SELECT r.room_id, r.status
            FROM booking_rooms br
            INNER JOIN rooms r ON r.room_id = br.room_id
            WHERE br.booking_id = ?
        ";

        $stmtGetRooms = mysqli_prepare($con, $sqlGetRooms);

        if (!$stmtGetRooms) {
            throw new Exception("Failed to prepare room fetch query");
        }

        mysqli_stmt_bind_param($stmtGetRooms, "i", $booking_id);
        mysqli_stmt_execute($stmtGetRooms);
        $resultRooms = mysqli_stmt_get_result($stmtGetRooms);

        $sqlUpdateRoom = "
            UPDATE rooms
            SET status = ?
            WHERE room_id = ?
        ";

        $stmtUpdateRoom = mysqli_prepare($con, $sqlUpdateRoom);

        if (!$stmtUpdateRoom) {
            throw new Exception("Failed to prepare room update");
        }

        while ($room = mysqli_fetch_assoc($resultRooms)) {
            $room_id = (int) $room['room_id'];
            $current_room_status = strtolower(trim($room['status'] ?? ''));

            if ($current_room_status === 'maintenance') {
                continue;
            }

            mysqli_stmt_bind_param($stmtUpdateRoom, "si", $new_room_status, $room_id);
            $resultRoom = mysqli_stmt_execute($stmtUpdateRoom);

            if (!$resultRoom) {
                throw new Exception("Failed to update room status");
            }
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