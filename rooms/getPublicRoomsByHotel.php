<?php
include __DIR__ . '/../helpers/connection.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['hotel_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'hotel_id is required'
    ]);
    exit;
}

$hotel_id = (int)$_POST['hotel_id'];

$hotelCheck = mysqli_query($con, "SELECT hotel_id FROM hotels WHERE hotel_id='$hotel_id' AND status='active' LIMIT 1");
if (!$hotelCheck || mysqli_num_rows($hotelCheck) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Hotel not found'
    ]);
    exit;
}

$sql = "SELECT room_id, hotel_id, vendor_id, name, type, status, price, capacity, description, amenities, image_url, created_at
        FROM rooms
        WHERE hotel_id='$hotel_id' AND status='available'
        ORDER BY room_id DESC";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);
    exit;
}

$rooms = [];
while ($row = mysqli_fetch_assoc($result)) {
    if (empty($row['image_url']) || !file_exists(__DIR__ . '/../' . $row['image_url'])) {
        $row['image_url'] = 'uploads/rooms/placeholder.png';
    }
    $rooms[] = $row;
}

echo json_encode([
    'success' => true,
    'data' => $rooms
]);