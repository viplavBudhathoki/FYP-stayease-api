<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

if (!isset($_POST['token']) || !isset($_FILES['photo'])) {
    echo json_encode([
        "success" => false,
        "message" => "Token and photo are required"
    ]);
    exit;
}

$token = trim($_POST['token']);

if (!isAdmin($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized admin"
    ]);
    exit;
}

$admin_id = (int) getUserIdByToken($token);

if (!$admin_id) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to upload photo"
    ]);
    exit;
}

$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
$fileMimeType = mime_content_type($_FILES['photo']['tmp_name']);

if (!in_array($fileMimeType, $allowedMimeTypes, true)) {
    echo json_encode([
        "success" => false,
        "message" => "Only JPG, PNG, and WEBP images are allowed"
    ]);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/admins/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
if (!$extension) {
    $extension = 'jpg';
}

$fileName = 'admin_' . $admin_id . '_' . time() . '.' . $extension;
$targetPath = $uploadDir . $fileName;

if (!move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to save uploaded photo"
    ]);
    exit;
}

$photoPath = 'uploads/admins/' . $fileName;

$sqlOld = "SELECT profile_photo FROM users WHERE user_id = ? AND role = 'admin' LIMIT 1";
$stmtOld = mysqli_prepare($con, $sqlOld);

if (!$stmtOld) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare old photo query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtOld, "i", $admin_id);
mysqli_stmt_execute($stmtOld);
$resultOld = mysqli_stmt_get_result($stmtOld);
$oldUser = mysqli_fetch_assoc($resultOld);

$sqlUpdate = "UPDATE users SET profile_photo = ? WHERE user_id = ? AND role = 'admin'";
$stmtUpdate = mysqli_prepare($con, $sqlUpdate);

if (!$stmtUpdate) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare photo update query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtUpdate, "si", $photoPath, $admin_id);

if (!mysqli_stmt_execute($stmtUpdate)) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to update profile photo"
    ]);
    exit;
}

if (!empty($oldUser['profile_photo'])) {
    $oldPath = __DIR__ . '/../' . $oldUser['profile_photo'];
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

echo json_encode([
    "success" => true,
    "message" => "Profile photo updated successfully",
    "photo" => $photoPath
]);