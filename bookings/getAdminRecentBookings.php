<?php

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

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

$sql = "
    SELECT
        b.booking_id,
        b.check_in,
        b.check_out,
        b.total_price,
        b.status,
        b.created_at,
        c.full_name AS customer_name,
        h.name AS hotel_name,
        r.name AS room_name,
        v.full_name AS vendor_name
    FROM bookings b
    INNER JOIN users c ON c.user_id = b.user_id
    INNER JOIN rooms r ON r.room_id = b.room_id
    INNER JOIN hotels h ON h.hotel_id = r.hotel_id
    INNER JOIN users v ON v.user_id = r.vendor_id
    ORDER BY b.booking_id DESC
    LIMIT 5
";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch recent admin bookings",
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