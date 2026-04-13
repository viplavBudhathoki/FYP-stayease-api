<?php
include __DIR__ . '/../helpers/connection.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['room_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'room_id is required'
    ]);
    exit;
}

$room_id = (int) $_POST['room_id'];

if ($room_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid room id'
    ]);
    exit;
}

$sql = "SELECT image_id, room_id, image_url, created_at
        FROM room_images
        WHERE room_id = '$room_id'
        ORDER BY image_id DESC";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'DB ERROR: ' . mysqli_error($con)
    ]);
    exit;
}

$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $data[] = $row;
}

echo json_encode([
    'success' => true,
    'data' => $data
]);