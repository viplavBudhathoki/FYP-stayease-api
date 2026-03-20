<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'], $_POST['image_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'token and image_id are required'
    ]);
    exit;
}

$token = $_POST['token'];
$image_id = (int) $_POST['image_id'];

if (!isAdmin($token)) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized user'
    ]);
    exit;
}

$check = mysqli_query($con, "SELECT image_id, image_url FROM hotel_images WHERE image_id='$image_id' LIMIT 1");
if (!$check || mysqli_num_rows($check) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Gallery image not found'
    ]);
    exit;
}

$image = mysqli_fetch_assoc($check);

$delete = mysqli_query($con, "DELETE FROM hotel_images WHERE image_id='$image_id' LIMIT 1");

if (!$delete) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to delete gallery image'
    ]);
    exit;
}

if (!empty($image['image_url']) && file_exists(__DIR__ . '/../' . $image['image_url'])) {
    @unlink(__DIR__ . '/../' . $image['image_url']);
}

echo json_encode([
    'success' => true,
    'message' => 'Gallery image deleted successfully'
]);