<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'])) {
  echo json_encode(['success' => false, 'message' => 'Token is required']);
  exit;
}

$token = $_POST['token'];

if (!isAdmin($token)) {
  echo json_encode(['success' => false, 'message' => 'Unauthorized user']);
  exit;
}

if (!isset($_POST['name'], $_POST['location'], $_POST['description'], $_POST['vendor_id'])) {
  echo json_encode(['success' => false, 'message' => 'name, location, description, vendor_id are required']);
  exit;
}

$name = trim($_POST['name']);
$location = trim($_POST['location']);
$description = trim($_POST['description']);
$vendor_id = (int) $_POST['vendor_id'];

if ($name === '' || $location === '' || $description === '' || $vendor_id <= 0) {
  echo json_encode(['success' => false, 'message' => 'Invalid hotel data']);
  exit;
}

$image_path = "uploads/hotels/placeholder.png";

if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
  $image = $_FILES['image'];
  $tmp = $image['tmp_name'];
  $imgName = $image['name'];
  $size = $image['size'];

  $ext = strtolower(pathinfo($imgName, PATHINFO_EXTENSION));
  $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

  if (!in_array($ext, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid main image extension']);
    exit;
  }

  if ($size > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'Main image must be less than 5MB']);
    exit;
  }

  $new_name = uniqid('hotel_') . '.' . $ext;

  $uploadDir = __DIR__ . '/../uploads/hotels/';
  if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
  }

  $image_path = 'uploads/hotels/' . $new_name;

  if (!move_uploaded_file($tmp, __DIR__ . '/../' . $image_path)) {
    echo json_encode(['success' => false, 'message' => 'Failed to upload main image']);
    exit;
  }
}

mysqli_begin_transaction($con);

try {
  $stmt = $con->prepare("INSERT INTO hotels (name, location, description, vendor_id, image_url) VALUES (?, ?, ?, ?, ?)");
  $stmt->bind_param("sssis", $name, $location, $description, $vendor_id, $image_path);

  if (!$stmt->execute()) {
    throw new Exception('Failed to add hotel');
  }

  $hotel_id = $stmt->insert_id;

  if (isset($_FILES['gallery']) && is_array($_FILES['gallery']['name'])) {
    $gallery = $_FILES['gallery'];
    $totalGallery = min(count($gallery['name']), 4);

    for ($i = 0; $i < $totalGallery; $i++) {
      if (!isset($gallery['error'][$i]) || $gallery['error'][$i] !== 0) {
        continue;
      }

      $galleryName = $gallery['name'][$i];
      $galleryTmp = $gallery['tmp_name'][$i];
      $gallerySize = $gallery['size'][$i];

      $galleryExt = strtolower(pathinfo($galleryName, PATHINFO_EXTENSION));
      $allowedGallery = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

      if (!in_array($galleryExt, $allowedGallery)) {
        continue;
      }

      if ($gallerySize > 5 * 1024 * 1024) {
        continue;
      }

      $newGalleryName = uniqid('hotel_gallery_') . '.' . $galleryExt;
      $galleryPath = 'uploads/hotels/' . $newGalleryName;
      $fullGalleryPath = __DIR__ . '/../' . $galleryPath;

      if (move_uploaded_file($galleryTmp, $fullGalleryPath)) {
        $galleryStmt = $con->prepare("INSERT INTO hotel_images (hotel_id, image_url) VALUES (?, ?)");
        $galleryStmt->bind_param("is", $hotel_id, $galleryPath);
        $galleryStmt->execute();
      }
    }
  }

  mysqli_commit($con);

  echo json_encode([
    'success' => true,
    'message' => 'Hotel added successfully',
    'data' => [
      'hotel_id' => $hotel_id,
      'image_url' => $image_path,
      'name' => $name,
      'location' => $location,
      'description' => $description,
      'vendor_id' => $vendor_id
    ]
  ]);
} catch (Exception $e) {
  mysqli_rollback($con);

  if (!empty($image_path) && $image_path !== 'uploads/hotels/placeholder.png' && file_exists(__DIR__ . '/../' . $image_path)) {
    @unlink(__DIR__ . '/../' . $image_path);
  }

  echo json_encode([
    'success' => false,
    'message' => $e->getMessage()
  ]);
}