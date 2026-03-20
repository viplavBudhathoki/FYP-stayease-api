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
        COUNT(DISTINCT r.room_id) AS totalRooms,

        SUM(CASE WHEN r.status = 'occupied' THEN 1 ELSE 0 END) AS occupied,
        SUM(CASE WHEN r.status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance,

        COUNT(DISTINCT CASE WHEN b.status = 'confirmed' THEN b.booking_id END) AS confirmedBookings,
        COUNT(DISTINCT CASE WHEN b.status = 'completed' THEN b.booking_id END) AS completedBookings,
        COUNT(DISTINCT CASE WHEN b.status = 'cancelled' THEN b.booking_id END) AS cancelledBookings,

        COALESCE(SUM(CASE WHEN b.status IN ('confirmed', 'completed') THEN b.total_price ELSE 0 END), 0) AS revenue

    FROM rooms r
    LEFT JOIN bookings b ON b.room_id = r.room_id
    WHERE r.vendor_id = '$vendor_id'
";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch dashboard stats",
        "error" => mysqli_error($con)
    ]);
    exit;
}

$data = mysqli_fetch_assoc($result);

echo json_encode([
    "success" => true,
    "data" => [
        "totalRooms" => (int)($data['totalRooms'] ?? 0),
        "occupied" => (int)($data['occupied'] ?? 0),
        "maintenance" => (int)($data['maintenance'] ?? 0),
        "confirmedBookings" => (int)($data['confirmedBookings'] ?? 0),
        "completedBookings" => (int)($data['completedBookings'] ?? 0),
        "cancelledBookings" => (int)($data['cancelledBookings'] ?? 0),
        "revenue" => (float)($data['revenue'] ?? 0),
    ]
]);