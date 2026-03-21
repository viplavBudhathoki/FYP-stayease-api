<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'])) {
  echo json_encode([
    'success' => false,
    'message' => 'Token is required'
  ]);
  exit;
}

$token = $_POST['token'];

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

if (!isset($_POST['room_id'], $_POST['name'], $_POST['price'], $_POST['type'], $_POST['status'])) {
  echo json_encode([
    'success' => false,
    'message' => 'room_id, name, price, type, status required'
  ]);
  exit;
}

$room_id = (int) $_POST['room_id'];

$name = trim($_POST['name']);
$type = trim($_POST['type']);
$status = strtolower(trim($_POST['status']));
$price = (float) $_POST['price'];
$capacity = isset($_POST['capacity']) ? (int) $_POST['capacity'] : 1;
$description = isset($_POST['description']) ? trim($_POST['description']) : null;

if ($name === '' || $type === '') {
  echo json_encode([
    'success' => false,
    'message' => 'Name and type cannot be empty'
  ]);
  exit;
}

if ($price < 0) {
  echo json_encode([
    'success' => false,
    'message' => 'Price must be a valid positive number'
  ]);
  exit;
}

if ($capacity < 1) {
  echo json_encode([
    'success' => false,
    'message' => 'Capacity must be at least 1'
  ]);
  exit;
}

$allowed_statuses = ['available', 'occupied', 'maintenance'];

if (!in_array($status, $allowed_statuses, true)) {
  echo json_encode([
    'success' => false,
    'message' => 'Invalid room status'
  ]);
  exit;
}

$name = mysqli_real_escape_string($con, $name);
$type = mysqli_real_escape_string($con, $type);
$status = mysqli_real_escape_string($con, $status);
$description_safe = $description !== null && $description !== ''
  ? mysqli_real_escape_string($con, $description)
  : null;

// amenities JSON -> CSV
$amenities_safe = null;
if (isset($_POST['amenities']) && $_POST['amenities'] !== '') {
  $arr = json_decode($_POST['amenities'], true);

  if (json_last_error() === JSON_ERROR_NONE && is_array($arr)) {
    $cleanAmenities = [];

    foreach ($arr as $item) {
      $item = trim((string)$item);
      if ($item !== '') {
        $cleanAmenities[] = $item;
      }
    }

    if (!empty($cleanAmenities)) {
      $amenities_safe = mysqli_real_escape_string($con, implode(", ", $cleanAmenities));
    }
  } else {
    // if amenities was sent as plain string
    $plainAmenities = trim($_POST['amenities']);
    if ($plainAmenities !== '') {
      $amenities_safe = mysqli_real_escape_string($con, $plainAmenities);
    }
  }
}

// ownership check
$checkSql = "SELECT room_id, image_url, status FROM rooms WHERE room_id = ? AND vendor_id = ? LIMIT 1";
$checkStmt = mysqli_prepare($con, $checkSql);

if (!$checkStmt) {
  echo json_encode([
    'success' => false,
    'message' => 'Failed to prepare ownership check'
  ]);
  exit;
}

mysqli_stmt_bind_param($checkStmt, "ii", $room_id, $vendor_id);
mysqli_stmt_execute($checkStmt);
$checkRes = mysqli_stmt_get_result($checkStmt);

if (!$checkRes || mysqli_num_rows($checkRes) === 0) {
  echo json_encode([
    'success' => false,
    'message' => 'You do not own this room'
  ]);
  exit;
}

$existing = mysqli_fetch_assoc($checkRes);
$old_image = $existing['image_url'] ?? '';
$current_status = strtolower(trim($existing['status'] ?? ''));

// optional image update
$image_sql = "";

if (isset($_FILES['image']) && !empty($_FILES['image']['tmp_name'])) {
  $image = $_FILES['image'];
  $ext = strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
  $allowed = ['jpg', 'jpeg', 'png', 'webp'];

  if (!in_array($ext, $allowed, true)) {
    echo json_encode([
      'success' => false,
      'message' => 'Invalid image type'
    ]);
    exit;
  }

  if ($image['size'] > 5 * 1024 * 1024) {
    echo json_encode([
      'success' => false,
      'message' => 'Image must be less than 5MB'
    ]);
    exit;
  }

  $upload_dir = __DIR__ . '/../uploads/rooms/';
  if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
  }

  $new_name = uniqid('room_', true) . '.' . $ext;
  $server_path = $upload_dir . $new_name;
  $db_path = 'uploads/rooms/' . $new_name;

  if (!move_uploaded_file($image['tmp_name'], $server_path)) {
    echo json_encode([
      'success' => false,
      'message' => 'Failed to upload image'
    ]);
    exit;
  }

  if (
    $old_image &&
    $old_image !== 'uploads/rooms/placeholder.png' &&
    file_exists(__DIR__ . '/../' . $old_image)
  ) {
    @unlink(__DIR__ . '/../' . $old_image);
  }

  $image_sql = ", image_url='" . mysqli_real_escape_string($con, $db_path) . "'";
}

/*
  Optional protection:
  Prevent manual update from forcing maintenance room into wrong state accidentally.
  If room is currently maintenance, allow keeping maintenance or changing it intentionally.
  This file should not silently override vendor choice.
*/

$sql = "
  UPDATE rooms SET
    name = '$name',
    type = '$type',
    status = '$status',
    price = '$price',
    capacity = '$capacity',
    description = " . ($description_safe !== null ? "'$description_safe'" : "NULL") . ",
    amenities = " . ($amenities_safe !== null ? "'$amenities_safe'" : "NULL") . "
    $image_sql
  WHERE room_id = '$room_id' AND vendor_id = '$vendor_id'
";

$result = mysqli_query($con, $sql);

if (!$result) {
  echo json_encode([
    'success' => false,
    'message' => 'DB ERROR: ' . mysqli_error($con)
  ]);
  exit;
}

echo json_encode([
  'success' => true,
  'message' => 'Room updated successfully'
]);