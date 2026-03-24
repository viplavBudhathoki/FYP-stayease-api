<?php

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (
    !isset(
        $_POST['token'],
        $_POST['check_in'],
        $_POST['check_out'],
        $_POST['adults'],
        $_POST['children']
    ) || !isset($_POST['room_ids'])
) {
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

$check_in = trim($_POST['check_in']);
$check_out = trim($_POST['check_out']);
$adults = (int) $_POST['adults'];
$children = (int) $_POST['children'];

$room_ids = $_POST['room_ids'];
if (!is_array($room_ids)) {
    $room_ids = [$room_ids];
}

$room_ids = array_values(array_unique(array_map('intval', $room_ids)));
$room_ids = array_filter($room_ids, fn($id) => $id > 0);

if (count($room_ids) === 0 || !$check_in || !$check_out) {
    echo json_encode([
        "success" => false,
        "message" => "All booking fields are required"
    ]);
    exit;
}

if ($adults < 1) {
    echo json_encode([
        "success" => false,
        "message" => "At least 1 adult is required"
    ]);
    exit;
}

if ($children < 0) {
    echo json_encode([
        "success" => false,
        "message" => "Children count cannot be negative"
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

if ($check_in < date("Y-m-d")) {
    echo json_encode([
        "success" => false,
        "message" => "Check-in date cannot be in the past"
    ]);
    exit;
}

$checkInDate = DateTime::createFromFormat('Y-m-d', $check_in);
$checkOutDate = DateTime::createFromFormat('Y-m-d', $check_out);

if (
    !$checkInDate || $checkInDate->format('Y-m-d') !== $check_in ||
    !$checkOutDate || $checkOutDate->format('Y-m-d') !== $check_out
) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid booking dates"
    ]);
    exit;
}

$rooms_requested = count($room_ids);
$total_guests = $adults + $children;

$placeholders = implode(',', array_fill(0, $rooms_requested, '?'));
$types = str_repeat('i', $rooms_requested);

$sqlRooms = "
    SELECT room_id, hotel_id, name, price, status, capacity, image_url, type
    FROM rooms
    WHERE room_id IN ($placeholders)
";

$stmtRooms = mysqli_prepare($con, $sqlRooms);

if (!$stmtRooms) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare rooms query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtRooms, $types, ...$room_ids);
mysqli_stmt_execute($stmtRooms);
$resultRooms = mysqli_stmt_get_result($stmtRooms);

if (!$resultRooms || mysqli_num_rows($resultRooms) !== $rooms_requested) {
    echo json_encode([
        "success" => false,
        "message" => "One or more selected rooms are invalid"
    ]);
    exit;
}

$rooms = [];
$hotel_id = null;
$total_capacity = 0;
$total_price_per_night = 0;

while ($row = mysqli_fetch_assoc($resultRooms)) {
    if ($row['status'] === 'maintenance') {
        echo json_encode([
            "success" => false,
            "message" => "One of the selected rooms is under maintenance"
        ]);
        exit;
    }

    if ($hotel_id === null) {
        $hotel_id = (int) $row['hotel_id'];
    } elseif ($hotel_id !== (int) $row['hotel_id']) {
        echo json_encode([
            "success" => false,
            "message" => "All selected rooms must belong to the same hotel"
        ]);
        exit;
    }

    $total_capacity += (int) ($row['capacity'] ?? 1);
    $total_price_per_night += (float) $row['price'];
    $rooms[] = $row;
}

if ($total_guests > $total_capacity) {
    echo json_encode([
        "success" => false,
        "message" => "Selected guests exceed the total capacity of chosen rooms"
    ]);
    exit;
}

/* overlap check for every selected room */
$sqlOverlap = "
    SELECT 1
    FROM booking_rooms br
    INNER JOIN bookings b ON b.booking_id = br.booking_id
    WHERE br.room_id = ?
      AND b.status IN ('confirmed', 'checked_in')
      AND (? < b.check_out)
      AND (? > b.check_in)
    LIMIT 1
";

$stmtOverlap = mysqli_prepare($con, $sqlOverlap);

if (!$stmtOverlap) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare overlap query"
    ]);
    exit;
}

foreach ($rooms as $room) {
    $rid = (int) $room['room_id'];

    mysqli_stmt_bind_param($stmtOverlap, "iss", $rid, $check_in, $check_out);
    mysqli_stmt_execute($stmtOverlap);
    $resultOverlap = mysqli_stmt_get_result($stmtOverlap);

    if ($resultOverlap && mysqli_num_rows($resultOverlap) > 0) {
        echo json_encode([
            "success" => false,
            "message" => "One of the selected rooms is already booked for the selected dates"
        ]);
        exit;
    }
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

$total_price = $nights * $total_price_per_night;

mysqli_begin_transaction($con);

try {
    $sqlInsertBooking = "
        INSERT INTO bookings (
            user_id,
            check_in,
            check_out,
            total_price,
            status,
            adults,
            children,
            rooms_requested
        )
        VALUES (?, ?, ?, ?, 'confirmed', ?, ?, ?)
    ";

    $stmtInsertBooking = mysqli_prepare($con, $sqlInsertBooking);

    if (!$stmtInsertBooking) {
        throw new Exception("Failed to prepare booking insert");
    }

    mysqli_stmt_bind_param(
        $stmtInsertBooking,
        "issdiii",
        $user_id,
        $check_in,
        $check_out,
        $total_price,
        $adults,
        $children,
        $rooms_requested
    );

    if (!mysqli_stmt_execute($stmtInsertBooking)) {
        throw new Exception("Failed to create booking");
    }

    $booking_id = mysqli_insert_id($con);

    $sqlInsertBookingRoom = "
        INSERT INTO booking_rooms (booking_id, room_id, price_per_night)
        VALUES (?, ?, ?)
    ";

    $stmtInsertBookingRoom = mysqli_prepare($con, $sqlInsertBookingRoom);

    if (!$stmtInsertBookingRoom) {
        throw new Exception("Failed to prepare booking rooms insert");
    }

    foreach ($rooms as $room) {
        $rid = (int) $room['room_id'];
        $price_per_night = (float) $room['price'];

        mysqli_stmt_bind_param(
            $stmtInsertBookingRoom,
            "iid",
            $booking_id,
            $rid,
            $price_per_night
        );

        if (!mysqli_stmt_execute($stmtInsertBookingRoom)) {
            throw new Exception("Failed to save selected rooms");
        }
    }

    mysqli_commit($con);

    echo json_encode([
        "success" => true,
        "message" => "Rooms booked successfully",
        "data" => [
            "booking_id" => $booking_id,
            "room_ids" => array_map(fn($r) => (int) $r['room_id'], $rooms),
            "check_in" => $check_in,
            "check_out" => $check_out,
            "adults" => $adults,
            "children" => $children,
            "rooms_requested" => $rooms_requested,
            "nights" => $nights,
            "total_price" => $total_price,
            "status" => "confirmed"
        ]
    ]);
} catch (Exception $e) {
    mysqli_rollback($con);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}