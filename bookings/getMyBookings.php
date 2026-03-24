<?php

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'])) {
    echo json_encode([
        "success" => false,
        "message" => "Token required"
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

$sqlBookings = "
    SELECT
        b.booking_id,
        b.check_in,
        b.check_out,
        b.total_price,
        b.status,
        b.created_at,
        b.adults,
        b.children,
        b.rooms_requested,
        CASE
            WHEN b.status = 'confirmed' AND CURDATE() < b.check_in THEN 1
            ELSE 0
        END AS can_modify_dates
    FROM bookings b
    WHERE b.user_id = ?
    ORDER BY b.booking_id DESC
";

$stmtBookings = mysqli_prepare($con, $sqlBookings);

if (!$stmtBookings) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare bookings query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtBookings, "i", $user_id);
mysqli_stmt_execute($stmtBookings);
$resultBookings = mysqli_stmt_get_result($stmtBookings);

$data = [];

$sqlRooms = "
    SELECT
        br.room_id,
        br.price_per_night,
        r.name AS room_name,
        r.type AS room_type,
        r.image_url AS room_image,
        h.hotel_id,
        h.name AS hotel_name,
        h.location AS hotel_location
    FROM booking_rooms br
    INNER JOIN rooms r ON r.room_id = br.room_id
    INNER JOIN hotels h ON h.hotel_id = r.hotel_id
    WHERE br.booking_id = ?
    ORDER BY br.booking_room_id ASC
";

$stmtRooms = mysqli_prepare($con, $sqlRooms);

if (!$stmtRooms) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare booking rooms query"
    ]);
    exit;
}

if ($resultBookings) {
    while ($row = mysqli_fetch_assoc($resultBookings)) {
        $booking_id = (int) $row['booking_id'];

        mysqli_stmt_bind_param($stmtRooms, "i", $booking_id);
        mysqli_stmt_execute($stmtRooms);
        $resultRooms = mysqli_stmt_get_result($stmtRooms);

        $rooms = [];
        $roomNames = [];
        $roomTypes = [];
        $firstRoomImage = null;
        $hotel_id = null;
        $hotel_name = null;
        $hotel_location = null;

        if ($resultRooms) {
            while ($room = mysqli_fetch_assoc($resultRooms)) {
                $room['room_id'] = (int) $room['room_id'];
                $room['price_per_night'] = (float) $room['price_per_night'];
                $room['hotel_id'] = (int) $room['hotel_id'];

                if ($firstRoomImage === null) {
                    $firstRoomImage = $room['room_image'];
                    $hotel_id = $room['hotel_id'];
                    $hotel_name = $room['hotel_name'];
                    $hotel_location = $room['hotel_location'];
                }

                $roomNames[] = $room['room_name'];
                $roomTypes[] = $room['room_type'];
                $rooms[] = $room;
            }
        }

        $row['can_modify_dates'] = (int) ($row['can_modify_dates'] ?? 0);
        $row['adults'] = (int) ($row['adults'] ?? 1);
        $row['children'] = (int) ($row['children'] ?? 0);
        $row['rooms_requested'] = (int) ($row['rooms_requested'] ?? 1);

        // keep old frontend-friendly fields
        $row['hotel_id'] = $hotel_id;
        $row['hotel_name'] = $hotel_name;
        $row['hotel_location'] = $hotel_location;
        $row['room_name'] = implode(', ', $roomNames);
        $row['room_type'] = implode(', ', array_unique($roomTypes));
        $row['room_image'] = $firstRoomImage;

        // new proper multi-room data
        $row['rooms'] = $rooms;

        $data[] = $row;
    }
}

echo json_encode([
    "success" => true,
    "data" => $data
]);