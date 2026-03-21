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

$range = isset($_POST['range']) ? trim($_POST['range']) : '7days';

$whereSql = "";

if ($range === '7days') {
    $whereSql = "WHERE DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} elseif ($range === '30days') {
    $whereSql = "WHERE DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
} elseif ($range === 'all') {
    $whereSql = "";
} else {
    $whereSql = "WHERE DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
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
    $whereSql
    ORDER BY b.booking_id DESC
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
exit;