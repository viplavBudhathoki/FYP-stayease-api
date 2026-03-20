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

$sql = "
    SELECT
        b.booking_id,
        b.check_in,
        b.check_out,
        b.total_price,
        b.status,
        b.created_at,
        r.room_id,
        r.name AS room_name,
        r.type AS room_type,
        r.image_url AS room_image,
        h.hotel_id,
        h.name AS hotel_name,
        h.location AS hotel_location,
        CASE
            WHEN b.status = 'confirmed' AND CURDATE() < b.check_in THEN 1
            ELSE 0
        END AS can_modify_dates
    FROM bookings b
    INNER JOIN rooms r ON r.room_id = b.room_id
    INNER JOIN hotels h ON h.hotel_id = r.hotel_id
    WHERE b.user_id = '$user_id'
    ORDER BY b.booking_id DESC
";

$result = mysqli_query($con, $sql);

$data = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row['can_modify_dates'] = (int)($row['can_modify_dates'] ?? 0);
        $data[] = $row;
    }
}

echo json_encode([
    "success" => true,
    "data" => $data
]);