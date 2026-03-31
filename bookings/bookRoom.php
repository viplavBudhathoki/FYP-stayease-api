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

$room_ids_raw = $_POST['room_ids'];
if (!is_array($room_ids_raw)) {
    $room_ids_raw = [$room_ids_raw];
}

$room_ids_raw = array_map('intval', $room_ids_raw);
$room_ids_raw = array_values(array_filter($room_ids_raw, fn($id) => $id > 0));

if (count($room_ids_raw) === 0 || !$check_in || !$check_out) {
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

$rooms_requested = count($room_ids_raw);
$total_guests = $adults + $children;

$requestedByRoomId = [];
foreach ($room_ids_raw as $rid) {
    if (!isset($requestedByRoomId[$rid])) {
        $requestedByRoomId[$rid] = 0;
    }
    $requestedByRoomId[$rid]++;
}

$unique_room_ids = array_keys($requestedByRoomId);
$placeholders = implode(',', array_fill(0, count($unique_room_ids), '?'));
$types = str_repeat('i', count($unique_room_ids));

$sqlRooms = "
    SELECT room_id, hotel_id, name, price, status, capacity, image_url, type, total_rooms
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

mysqli_stmt_bind_param($stmtRooms, $types, ...$unique_room_ids);
mysqli_stmt_execute($stmtRooms);
$resultRooms = mysqli_stmt_get_result($stmtRooms);

if (!$resultRooms || mysqli_num_rows($resultRooms) !== count($unique_room_ids)) {
    echo json_encode([
        "success" => false,
        "message" => "One or more selected room types are invalid"
    ]);
    exit;
}

$rooms = [];
$hotel_id = null;
$total_capacity = 0;
$total_price_per_night = 0;

while ($row = mysqli_fetch_assoc($resultRooms)) {
    $rid = (int) $row['room_id'];
    $requestedQty = (int) ($requestedByRoomId[$rid] ?? 0);
    $total_rooms = max(1, (int) ($row['total_rooms'] ?? 1));

    if ($row['status'] !== 'available') {
        echo json_encode([
            "success" => false,
            "message" => "{$row['name']} is not available for booking"
        ]);
        exit;
    }

    if ($hotel_id === null) {
        $hotel_id = (int) $row['hotel_id'];
    } elseif ($hotel_id !== (int) $row['hotel_id']) {
        echo json_encode([
            "success" => false,
            "message" => "All selected room types must belong to the same hotel"
        ]);
        exit;
    }

    if ($requestedQty > $total_rooms) {
        echo json_encode([
            "success" => false,
            "message" => "Requested quantity exceeds total rooms for {$row['name']}"
        ]);
        exit;
    }

    $total_capacity += ((int) ($row['capacity'] ?? 1)) * $requestedQty;
    $total_price_per_night += ((float) $row['price']) * $requestedQty;

    $row['requested_qty'] = $requestedQty;
    $row['total_rooms'] = $total_rooms;
    $rooms[] = $row;
}

if ($total_guests > $total_capacity) {
    echo json_encode([
        "success" => false,
        "message" => "Selected guests exceed the total capacity of chosen rooms"
    ]);
    exit;
}

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
    $sqlOverlapCount = "
        SELECT COUNT(*) AS booked_count
        FROM booking_rooms br
        INNER JOIN bookings b ON b.booking_id = br.booking_id
        WHERE br.room_id = ?
          AND b.status IN ('confirmed', 'checked_in')
          AND (? < b.check_out)
          AND (? > b.check_in)
    ";

    $stmtOverlap = mysqli_prepare($con, $sqlOverlapCount);

    if (!$stmtOverlap) {
        throw new Exception("Failed to prepare overlap query");
    }

    foreach ($rooms as $room) {
        $rid = (int) $room['room_id'];
        $requestedQty = (int) $room['requested_qty'];
        $totalRooms = (int) $room['total_rooms'];

        mysqli_stmt_bind_param($stmtOverlap, "iss", $rid, $check_in, $check_out);
        mysqli_stmt_execute($stmtOverlap);
        $resultOverlap = mysqli_stmt_get_result($stmtOverlap);

        $bookedCount = 0;
        if ($resultOverlap) {
            $overlapRow = mysqli_fetch_assoc($resultOverlap);
            $bookedCount = (int) ($overlapRow['booked_count'] ?? 0);
        }

        $availableCount = max(0, $totalRooms - $bookedCount);

        if ($requestedQty > $availableCount) {
            throw new Exception("Only {$availableCount} room(s) available for {$room['name']} in the selected dates");
        }
    }

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
        $requestedQty = (int) $room['requested_qty'];

        for ($i = 0; $i < $requestedQty; $i++) {
            mysqli_stmt_bind_param(
                $stmtInsertBookingRoom,
                "iid",
                $booking_id,
                $rid,
                $price_per_night
            );

            if (!mysqli_stmt_execute($stmtInsertBookingRoom)) {
                throw new Exception("Failed to save booked room item");
            }
        }
    }

    mysqli_commit($con);

    echo json_encode([
        "success" => true,
        "message" => "Rooms booked successfully",
        "booking_id" => $booking_id,
        "rooms_requested" => $rooms_requested,
        "nights" => $nights,
        "total_price" => $total_price
    ]);
} catch (Throwable $e) {
    mysqli_rollback($con);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}