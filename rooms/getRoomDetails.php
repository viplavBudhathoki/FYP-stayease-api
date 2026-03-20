<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'], $_POST['room_id'])) {
  echo json_encode(['success'=>false,'message'=>'token and room_id required']);
  exit;
}

$token = $_POST['token'];
$room_id = (int)$_POST['room_id'];

if (!isVendor($token)) {
  echo json_encode(['success'=>false,'message'=>'Unauthorized vendor']);
  exit;
}

$vendor_id = (int)getUserIdByToken($token);
if (!$vendor_id) {
  echo json_encode(['success'=>false,'message'=>'Invalid token']);
  exit;
}

/* ✅ room must belong to vendor */
$stmt = mysqli_prepare($con, "
  SELECT r.*, h.name AS hotel_name, h.location AS hotel_location
  FROM rooms r
  JOIN hotels h ON h.hotel_id = r.hotel_id
  WHERE r.room_id=? AND r.vendor_id=?
  LIMIT 1
");
mysqli_stmt_bind_param($stmt, "ii", $room_id, $vendor_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$room = mysqli_fetch_assoc($res);
if (!$room) {
  echo json_encode(['success'=>false,'message'=>'Room not found or not yours']);
  exit;
}

/* fetch images */
$imgs = [];
$imgRes = mysqli_query($con, "SELECT image_id, image_url FROM room_images WHERE room_id='$room_id' ORDER BY image_id DESC");
while ($row = mysqli_fetch_assoc($imgRes)) $imgs[] = $row;

/* include main image first */
$main = $room['image_url'] ? $room['image_url'] : "uploads/rooms/placeholder.png";

echo json_encode([
  'success'=>true,
  'data'=>[
    'room'=>$room,
    'main_image'=>$main,
    'images'=>$imgs
  ]
]);