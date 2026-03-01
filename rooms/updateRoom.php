<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'])) {
  echo json_encode(['success'=>false,'message'=>'Token is required']);
  exit;
}

$token = $_POST['token'];

if (!isVendor($token)) {
  echo json_encode(['success'=>false,'message'=>'Unauthorized vendor']);
  exit;
}

$vendor_id = (int)getUserIdByToken($token);
if (!$vendor_id) {
  echo json_encode(['success'=>false,'message'=>'Invalid token']);
  exit;
}

if (!isset($_POST['room_id'], $_POST['hotel_id'], $_POST['name'], $_POST['price'], $_POST['type'], $_POST['status'])) {
  echo json_encode(['success'=>false,'message'=>'room_id, hotel_id, name, price, type, status required']);
  exit;
}

$room_id  = (int)$_POST['room_id'];
$hotel_id = (int)$_POST['hotel_id'];

$name = mysqli_real_escape_string($con, $_POST['name']);
$price = (float)$_POST['price'];
$type = mysqli_real_escape_string($con, $_POST['type']);
$status = strtolower(mysqli_real_escape_string($con, $_POST['status']));
$capacity = isset($_POST['capacity']) ? (int)$_POST['capacity'] : 1;
$description = isset($_POST['description']) ? mysqli_real_escape_string($con, $_POST['description']) : null;

// amenities: JSON -> "WiFi, AC"
$amenities_safe = null;
if (isset($_POST['amenities'])) {
  $arr = json_decode($_POST['amenities'], true);
  if (is_array($arr)) {
    $amenities_safe = mysqli_real_escape_string($con, implode(", ", $arr));
  }
}

// own check
$check = mysqli_query($con, "SELECT room_id FROM rooms WHERE room_id='$room_id' AND vendor_id='$vendor_id' AND hotel_id='$hotel_id' LIMIT 1");
if (!$check || mysqli_num_rows($check) === 0) {
  echo json_encode(['success'=>false,'message'=>'You do not own this room']);
  exit;
}

// optional image update
$image_sql = "";
if (isset($_FILES['image']) && $_FILES['image']['tmp_name']) {
  $image = $_FILES['image'];
  $ext = strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
  $allowed = ['jpg','jpeg','png','webp'];

  if (!in_array($ext, $allowed)) {
    echo json_encode(['success'=>false,'message'=>'Invalid image type']);
    exit;
  }
  if ($image['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success'=>false,'message'=>'Image must be less than 5MB']);
    exit;
  }

  $upload_dir = __DIR__ . '/../uploads/rooms/';
  if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

  $new_name = uniqid('room_') . '.' . $ext;
  $server_path = $upload_dir . $new_name;
  $db_path = 'uploads/rooms/' . $new_name;

  if (!move_uploaded_file($image['tmp_name'], $server_path)) {
    echo json_encode(['success'=>false,'message'=>'Failed to upload image']);
    exit;
  }

  $image_sql = ", image_url='$db_path'";
}

$sql = "
  UPDATE rooms SET
    name='$name',
    type='$type',
    status='$status',
    price='$price',
    capacity='$capacity',
    description=" . ($description ? "'$description'" : "NULL") . ",
    amenities=" . ($amenities_safe ? "'$amenities_safe'" : "NULL") . "
    $image_sql
  WHERE room_id='$room_id' AND vendor_id='$vendor_id' AND hotel_id='$hotel_id'
";

$result = mysqli_query($con, $sql);
if (!$result) {
  echo json_encode(['success'=>false,'message'=>'DB ERROR: '.mysqli_error($con)]);
  exit;
}

echo json_encode(['success'=>true,'message'=>'Room updated successfully']);