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

$vendor_id = getUserIdByToken($token);
if (!$vendor_id) {
  echo json_encode(['success'=>false,'message'=>'Invalid token']);
  exit;
}

$sql = "
  SELECT 
    r.room_id, r.hotel_id, r.vendor_id, r.name, r.type, r.status, r.price,
    r.capacity, r.description, r.amenities, r.image_url, r.created_at,
    h.name AS hotel_name, h.location AS hotel_location
  FROM rooms r
  INNER JOIN hotels h ON h.hotel_id = r.hotel_id
  WHERE r.vendor_id = ?
  ORDER BY r.room_id DESC
";

$stmt = mysqli_prepare($con, $sql);
mysqli_stmt_bind_param($stmt, "i", $vendor_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$data = [];
while ($row = mysqli_fetch_assoc($res)) {
  $data[] = $row;
}

echo json_encode(['success'=>true,'data'=>$data]);