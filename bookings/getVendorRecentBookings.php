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

$range = isset($_POST['range']) ? trim($_POST['range']) : '7days';

$whereDateSql = "";

if ($range === '7days') {
    $whereDateSql = "AND DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} elseif ($range === '30days') {
    $whereDateSql = "AND DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
} elseif ($range === 'all') {
    $whereDateSql = "";
} else {
    $whereDateSql = "AND DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
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
        u.full_name AS customer_name,
        GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') AS room_name,
        h.name AS hotel_name
    FROM bookings b
    INNER JOIN users u ON u.user_id = b.user_id
    INNER JOIN booking_rooms br ON br.booking_id = b.booking_id
    INNER JOIN rooms r ON r.room_id = br.room_id
    INNER JOIN hotels h ON h.hotel_id = r.hotel_id
    WHERE r.vendor_id = ?
    $whereDateSql
    GROUP BY
        b.booking_id,
        b.check_in,
        b.check_out,
        b.total_price,
        b.status,
        b.created_at,
        b.rooms_requested,
        u.full_name,
        h.name
    ORDER BY b.booking_id DESC
";

$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare recent bookings query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, "i", $vendor_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch recent bookings"
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