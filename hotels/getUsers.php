<?php
include '../helpers/connection.php';
include '../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'])) {
    echo json_encode(['success'=>false,'message'=>'Token required']);
    exit;
}

$token = $_POST['token'];

if (!isAdmin($token)) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

$sql = "SELECT user_id, full_name, email, role, created_at
        FROM users
        WHERE role='user'
        ORDER BY user_id DESC";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode(['success'=>false,'message'=>'Database error']);
    exit;
}

$users = [];
while ($row = mysqli_fetch_assoc($result)) {
    $users[] = $row;
}

echo json_encode(['success'=>true,'data'=>$users]);