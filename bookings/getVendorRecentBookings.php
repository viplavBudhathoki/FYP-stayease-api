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

$sql = "
    SELECT
        b.booking_id,
        b.check_in,
        b.check_out,
        b.total_price,
        b.status,
        b.created_at,
        u.full_name AS customer_name,
        r.name AS room_name,
        h.name AS hotel_name
    FROM bookings b
    INNER JOIN users u ON u.user_id = b.user_id
    INNER JOIN rooms r ON r.room_id = b.room_id
    INNER JOIN hotels h ON h.hotel_id = r.hotel_id
    WHERE r.vendor_id = '$vendor_id'
    ORDER BY b.booking_id DESC
    LIMIT 5
";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch recent bookings",
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