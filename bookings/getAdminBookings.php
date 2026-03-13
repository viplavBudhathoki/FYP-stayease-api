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

$token = $_POST['token'];

if (!isAdmin($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized admin"
    ]);
    exit;
}

$admin_id = getUserIdByToken($token);

if (!$admin_id) {
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

        c.user_id AS customer_id,
        c.full_name AS customer_name,
        c.email AS customer_email,

        r.room_id,
        r.name AS room_name,
        r.type AS room_type,
        r.image_url AS room_image,

        h.hotel_id,
        h.name AS hotel_name,
        h.location AS hotel_location,

        v.user_id AS vendor_id,
        v.full_name AS vendor_name,
        v.email AS vendor_email

    FROM bookings b
    INNER JOIN users c ON c.user_id = b.user_id
    INNER JOIN rooms r ON r.room_id = b.room_id
    INNER JOIN hotels h ON h.hotel_id = r.hotel_id
    INNER JOIN users v ON v.user_id = r.vendor_id

    ORDER BY b.booking_id DESC
";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch admin bookings",
        "error" => mysqli_error($con)
    ]);
    exit;
}

$data = [];

while ($row = mysqli_fetch_assoc($result)) {
    $data[] = $row;
}

echo json_encode([
    "success" => true,
    "data" => $data
]);