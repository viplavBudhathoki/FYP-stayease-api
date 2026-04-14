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
$full_name = trim($input['full_name'] ?? '');
$email = trim($input['email'] ?? '');

if (!$admin_id) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

if ($full_name === '' || $email === '') {
    echo json_encode([
        "success" => false,
        "message" => "Full name and email are required"
    ]);
    exit;
}

$sqlCheck = "SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1";
$stmtCheck = mysqli_prepare($con, $sqlCheck);

if (!$stmtCheck) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare email check query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtCheck, "si", $email, $admin_id);
mysqli_stmt_execute($stmtCheck);
$resultCheck = mysqli_stmt_get_result($stmtCheck);

if ($resultCheck && mysqli_num_rows($resultCheck) > 0) {
    echo json_encode([
        "success" => false,
        "message" => "Email is already in use"
    ]);
    exit;
}

$sqlUpdate = "UPDATE users SET full_name = ?, email = ? WHERE user_id = ? AND role = 'admin'";
$stmtUpdate = mysqli_prepare($con, $sqlUpdate);

if (!$stmtUpdate) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare admin profile update query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtUpdate, "ssi", $full_name, $email, $admin_id);

if (!mysqli_stmt_execute($stmtUpdate)) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to update admin profile"
    ]);
    exit;
}

if (mysqli_affected_rows($con) === 0) {
    echo json_encode([
        "success" => true,
        "changed" => false,
        "message" => "No changes to save"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "changed" => true,
    "message" => "Admin profile updated successfully",
    "user" => [
        "full_name" => $full_name,
        "email" => $email
    ]
]);