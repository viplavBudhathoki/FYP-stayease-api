<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'])) {
  echo json_encode(['success'=>false,'message'=>'Token required']);
  exit;
}
if (!isset($_POST['hotel_id'])) {
  echo json_encode(['success'=>false,'message'=>'hotel_id required']);
  exit;
}

$token = $_POST['token'];
$hotel_id = (int)$_POST['hotel_id'];

if (!isVendor($token)) {
  echo json_encode(['success'=>false,'message'=>'Unauthorized vendor']);
  exit;
}

$vendor_id = getUserIdByToken($token);
if (!$vendor_id) {
  echo json_encode(['success'=>false,'message'=>'Invalid token']);
  exit;
}

$db = isset($con) ? $con : (isset($conn) ? $conn : null);
if (!$db) {
  echo json_encode(['success'=>false,'message'=>'DB connection variable not found ($con/$conn)']);
  exit;
}

$stmt = mysqli_prepare($db, "
  SELECT hotel_id, name, location, description, image_url
  FROM hotels
  WHERE hotel_id = ? AND vendor_id = ?
  LIMIT 1
");
mysqli_stmt_bind_param($stmt, "ii", $hotel_id, $vendor_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$row = mysqli_fetch_assoc($res);
if (!$row) {
  echo json_encode(['success'=>false,'message'=>'Hotel not found or not yours']);
  exit;
}

echo json_encode(['success'=>true,'data'=>$row]);