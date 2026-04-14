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

$input = json_decode(file_get_contents("php://input"), true);

if (!$input || !isset($input['token'])) {
    echo json_encode([
        "success" => false,
        "message" => "Token is required"
    ]);
    exit;
}

$token = trim($input['token']);

if (!isAdmin($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized admin"
    ]);
    exit;
}

$admin_id = (int) getUserIdByToken($token);
$current_password = $input['current_password'] ?? '';
$new_password = $input['new_password'] ?? '';
$confirm_password = $input['confirm_password'] ?? '';

if (!$admin_id) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

if ($current_password === '' || $new_password === '' || $confirm_password === '') {
    echo json_encode([
        "success" => false,
        "message" => "All password fields are required"
    ]);
    exit;
}

if ($new_password !== $confirm_password) {
    echo json_encode([
        "success" => false,
        "message" => "New password and confirm password do not match"
    ]);
    exit;
}

if (strlen($new_password) < 6) {
    echo json_encode([
        "success" => false,
        "message" => "New password must be at least 6 characters"
    ]);
    exit;
}

$sqlAdmin = "SELECT password FROM users WHERE user_id = ? AND role = 'admin' LIMIT 1";
$stmtAdmin = mysqli_prepare($con, $sqlAdmin);

if (!$stmtAdmin) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare password check query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtAdmin, "i", $admin_id);
mysqli_stmt_execute($stmtAdmin);
$resultAdmin = mysqli_stmt_get_result($stmtAdmin);
$admin = mysqli_fetch_assoc($resultAdmin);

if (!$admin) {
    echo json_encode([
        "success" => false,
        "message" => "Admin not found"
    ]);
    exit;
}

if (!password_verify($current_password, $admin['password'])) {
    echo json_encode([
        "success" => false,
        "message" => "Current password is incorrect"
    ]);
    exit;
}

$new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

$sqlUpdate = "UPDATE users SET password = ? WHERE user_id = ? AND role = 'admin'";
$stmtUpdate = mysqli_prepare($con, $sqlUpdate);

if (!$stmtUpdate) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare password update query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtUpdate, "si", $new_hashed_password, $admin_id);

if (!mysqli_stmt_execute($stmtUpdate)) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to update password"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Password updated successfully"
]);