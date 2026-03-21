<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Token required'
    ]);
    exit;
}

$token = $_POST['token'];

if (!isAdmin($token)) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

function getCount($con, $sql) {
    $res = mysqli_query($con, $sql);

    if (!$res) {
        return 0;
    }

    $row = mysqli_fetch_assoc($res);
    return (float) ($row['total'] ?? 0);
}

$totalUsers = (int) getCount($con, "SELECT COUNT(*) as total FROM users WHERE role='user'");
$totalVendors = (int) getCount($con, "SELECT COUNT(*) as total FROM users WHERE role='vendor'");
$totalHotels = (int) getCount($con, "SELECT COUNT(*) as total FROM hotels");
$totalBookings = (int) getCount($con, "SELECT COUNT(*) as total FROM bookings");

$revenue = getCount($con, "
    SELECT COALESCE(SUM(total_price), 0) as total
    FROM bookings
    WHERE status IN ('checked_in', 'completed')
");

echo json_encode([
    'success' => true,
    'data' => [
        'totalUsers' => $totalUsers,
        'totalVendors' => $totalVendors,
        'totalHotels' => $totalHotels,
        'totalBookings' => $totalBookings,
        'revenue' => $revenue
    ]
]);
exit;