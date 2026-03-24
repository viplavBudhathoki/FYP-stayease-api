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

$sql = "
    SELECT
        COUNT(DISTINCT r.room_id) AS totalRooms,
        SUM(CASE WHEN r.status = 'occupied' THEN 1 ELSE 0 END) AS occupied,
        SUM(CASE WHEN r.status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance,

        COUNT(DISTINCT CASE WHEN b.status = 'confirmed' THEN b.booking_id END) AS confirmedBookings,
        COUNT(DISTINCT CASE WHEN b.status = 'checked_in' THEN b.booking_id END) AS checkedInBookings,
        COUNT(DISTINCT CASE WHEN b.status = 'completed' THEN b.booking_id END) AS completedBookings,
        COUNT(DISTINCT CASE WHEN b.status = 'cancelled' THEN b.booking_id END) AS cancelledBookings,

        COALESCE(SUM(
            DISTINCT CASE
                WHEN b.status IN ('checked_in', 'completed') THEN b.booking_id * 0 + b.total_price
                ELSE NULL
            END
        ), 0) AS revenue

    FROM rooms r
    LEFT JOIN booking_rooms br ON br.room_id = r.room_id
    LEFT JOIN bookings b ON b.booking_id = br.booking_id
    WHERE r.vendor_id = ?
";

$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare dashboard stats query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, "i", $vendor_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch dashboard stats"
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
        "checkedInBookings" => (int)($data['checkedInBookings'] ?? 0),
        "completedBookings" => (int)($data['completedBookings'] ?? 0),
        "cancelledBookings" => (int)($data['cancelledBookings'] ?? 0),
        "revenue" => (float)($data['revenue'] ?? 0),
    ]
]);