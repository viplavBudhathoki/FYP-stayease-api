<?php
include '../helpers/connection.php';
include '../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'])) {
    echo json_encode(['success'=>false,'message'=>'Token required']);
    exit;
}

$token = $_POST['token'];

if (!isAdmin($token)) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

function getCount($con, $sql) {
    $res = mysqli_query($con, $sql);
    if (!$res) return 0;
    $row = mysqli_fetch_assoc($res);
    return (int)($row['total'] ?? 0);
}

$totalUsers   = getCount($con, "SELECT COUNT(*) as total FROM users WHERE role='user'");
$totalVendors = getCount($con, "SELECT COUNT(*) as total FROM users WHERE role='vendor'");

// Optional (only if we have these tables)
$totalHotels    = getCount($con, "SELECT COUNT(*) as total FROM hotels");
$totalBookings  = getCount($con, "SELECT COUNT(*) as total FROM bookings");

// revenue optional (if we have payments table)
// $revenue = getCount($con, "SELECT COALESCE(SUM(amount),0) as total FROM payments");
$revenue = 0;

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