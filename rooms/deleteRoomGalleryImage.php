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

if (!isVendor($token)) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized vendor'
    ]);
    exit;
}

$vendor_id = (int) getUserIdByToken($token);
if (!$vendor_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid token'
    ]);
    exit;
}

$sql = "
    SELECT ri.image_id, ri.image_url, ri.room_id
    FROM room_images ri
    INNER JOIN rooms r ON r.room_id = ri.room_id
    WHERE ri.image_id = '$image_id'
      AND r.vendor_id = '$vendor_id'
    LIMIT 1
";

$result = mysqli_query($con, $sql);

if (!$result || mysqli_num_rows($result) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Image not found or access denied'
    ]);
    exit;
}

$image = mysqli_fetch_assoc($result);
$image_path = $image['image_url'];

$deleteSql = "DELETE FROM room_images WHERE image_id = '$image_id' LIMIT 1";
$deleteResult = mysqli_query($con, $deleteSql);

if (!$deleteResult) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to delete image from database'
    ]);
    exit;
}

$fullPath = __DIR__ . '/../' . $image_path;
if (!empty($image_path) && file_exists($fullPath)) {
    @unlink($fullPath);
}

echo json_encode([
    'success' => true,
    'message' => 'Room gallery image deleted successfully'
]);