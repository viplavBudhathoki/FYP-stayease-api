<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'], $_POST['hotel_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'token and hotel_id are required'
    ]);
    exit;
}

$token = $_POST['token'];
$hotel_id = (int) $_POST['hotel_id'];

if (!isAdmin($token) && !isVendor($token)) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized user'
    ]);
    exit;
}

$checkHotel = mysqli_query($con, "SELECT hotel_id FROM hotels WHERE hotel_id='$hotel_id' LIMIT 1");
if (!$checkHotel || mysqli_num_rows($checkHotel) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Hotel not found'
    ]);
    exit;
}

$sql = "SELECT image_id, hotel_id, image_url, created_at
        FROM hotel_images
        WHERE hotel_id='$hotel_id'
        ORDER BY image_id DESC";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);
    exit;
}

$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    if (!empty($row['image_url']) && file_exists(__DIR__ . '/../' . $row['image_url'])) {
        $data[] = $row;
    }
}

echo json_encode([
    'success' => true,
    'data' => $data
]);