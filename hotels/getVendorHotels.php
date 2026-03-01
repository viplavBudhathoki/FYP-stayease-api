<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'])) {
  echo json_encode(['success'=>false,'message'=>'Token required']);
  exit;
}

$token = $_POST['token'];

if (!isVendor($token)) {
  echo json_encode(['success'=>false,'message'=>'Unauthorized vendor']);
  exit;
}

$vendor_id = getUserIdByToken($token);
if (!$vendor_id) {
  echo json_encode(['success'=>false,'message'=>'Invalid token']);
  exit;
}

/**
 * IMPORTANT: use the correct mysqli connection variable.
 * If connection.php defines $con use $con
 * If it defines $conn use $conn
 */
$db = isset($con) ? $con : (isset($conn) ? $conn : null);

if (!$db) {
  echo json_encode(['success'=>false,'message'=>'DB connection variable not found ($con/$conn)']);
  exit;
}

$stmt = mysqli_prepare($db, "
  SELECT hotel_id, name, location, description, status, image_url, created_at
  FROM hotels
  WHERE vendor_id = ?
  ORDER BY hotel_id DESC
");
mysqli_stmt_bind_param($stmt, "i", $vendor_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$data = [];
while ($row = mysqli_fetch_assoc($res)) {
  $data[] = $row;
}

echo json_encode(['success'=>true,'data'=>$data]);