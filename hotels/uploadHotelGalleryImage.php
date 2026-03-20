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

if (!isAdmin($token)) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized user'
    ]);
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Image is required'
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

/* limit gallery to max 4 */
$countRes = mysqli_query($con, "SELECT COUNT(*) AS total FROM hotel_images WHERE hotel_id='$hotel_id'");
$countRow = mysqli_fetch_assoc($countRes);
$totalImages = (int) ($countRow['total'] ?? 0);

if ($totalImages >= 4) {
    echo json_encode([
        'success' => false,
        'message' => 'Maximum 4 gallery images allowed for one hotel'
    ]);
    exit;
}

$image = $_FILES['image'];
$image_name = $image['name'];
$image_tmp = $image['tmp_name'];
$image_size = $image['size'];

$ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'webp'];

if (!in_array($ext, $allowed)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid image type'
    ]);
    exit;
}

if ($image_size > 5 * 1024 * 1024) {
    echo json_encode([
        'success' => false,
        'message' => 'Image must be less than 5MB'
    ]);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/hotels/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$newName = uniqid('hotel_gallery_') . '.' . $ext;
$relativePath = 'uploads/hotels/' . $newName;
$fullPath = __DIR__ . '/../' . $relativePath;

if (!move_uploaded_file($image_tmp, $fullPath)) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to upload image'
    ]);
    exit;
}

$stmt = $con->prepare("INSERT INTO hotel_images (hotel_id, image_url) VALUES (?, ?)");
$stmt->bind_param("is", $hotel_id, $relativePath);

if (!$stmt->execute()) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save gallery image'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Gallery image uploaded successfully',
    'data' => [
        'image_id' => $stmt->insert_id,
        'hotel_id' => $hotel_id,
        'image_url' => $relativePath
    ]
]);