<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'])) {
    echo json_encode(['success' => false, 'message' => 'Token is required']);
    exit;
}

if (!isset($_POST['hotel_id'])) {
    echo json_encode(['success' => false, 'message' => 'hotel_id is required']);
    exit;
}

$token = $_POST['token'];
$hotel_id = $_POST['hotel_id'];

if (!isVendor($token)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized vendor']);
    exit;
}

$vendor_id = getUserIdByToken($token);
if (!$vendor_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

$check = mysqli_query($con, "SELECT hotel_id FROM hotels WHERE hotel_id='$hotel_id' AND vendor_id='$vendor_id'");
if (!$check || mysqli_num_rows($check) === 0) {
    echo json_encode(['success' => false, 'message' => 'You do not own this hotel']);
    exit;
}

$sql = "SELECT room_id, hotel_id, vendor_id, name, type, status, price, capacity, description, amenities, image_url, created_at
        FROM rooms
        WHERE hotel_id='$hotel_id' AND vendor_id='$vendor_id'
        ORDER BY room_id DESC";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$rooms = [];
while ($row = mysqli_fetch_assoc($result)) {
    $rooms[] = $row;
}

echo json_encode([
    'success' => true,
    'message' => 'Rooms fetched successfully',
    'data' => $rooms
]);