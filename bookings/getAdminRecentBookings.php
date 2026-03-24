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

$range = isset($_POST['range']) ? trim($_POST['range']) : '7days';

$whereSql = "";
$params = [];
$types = "";

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
        b.rooms_requested,

        c.full_name AS customer_name,

        h.name AS hotel_name,
        h.location AS hotel_location,

        GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') AS room_name,
        GROUP_CONCAT(DISTINCT r.type ORDER BY r.type SEPARATOR ', ') AS room_type,

        v.full_name AS vendor_name

    FROM bookings b
    INNER JOIN users c ON c.user_id = b.user_id
    INNER JOIN booking_rooms br ON br.booking_id = b.booking_id
    INNER JOIN rooms r ON r.room_id = br.room_id
    INNER JOIN hotels h ON h.hotel_id = r.hotel_id
    INNER JOIN users v ON v.user_id = r.vendor_id
    $whereSql
    GROUP BY
        b.booking_id,
        b.check_in,
        b.check_out,
        b.total_price,
        b.status,
        b.created_at,
        b.rooms_requested,
        c.full_name,
        h.name,
        h.location,
        v.full_name
    ORDER BY b.booking_id DESC
";

$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare recent admin bookings query"
    ]);
    exit;
}

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

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
    $row['rooms_requested'] = (int) ($row['rooms_requested'] ?? 1);
    $data[] = $row;
}

echo json_encode([
    "success" => true,
    "data" => $data
]);
exit;