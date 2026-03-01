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

$check = mysqli_query($con, "SELECT room_id FROM rooms WHERE room_id='$room_id' AND vendor_id='$vendor_id' LIMIT 1");
if (!$check || mysqli_num_rows($check) === 0) {
  echo json_encode(['success'=>false,'message'=>'You do not own this room']);
  exit;
}

$result = mysqli_query($con, "DELETE FROM rooms WHERE room_id='$room_id' AND vendor_id='$vendor_id'");
if (!$result) {
  echo json_encode(['success'=>false,'message'=>'DB ERROR: '.mysqli_error($con)]);
  exit;
}

echo json_encode(['success'=>true,'message'=>'Room deleted successfully']);