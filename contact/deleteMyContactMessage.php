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

$token = trim($input['token'] ?? '');
$message_id = (int) ($input['message_id'] ?? 0);

if ($token === '' || $message_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Token and message_id are required"
    ]);
    exit;
}

$user_id = (int) getUserIdByToken($token);

if (!$user_id) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized user"
    ]);
    exit;
}

$sqlUser = "SELECT email, role FROM users WHERE user_id = ? LIMIT 1";
$stmtUser = mysqli_prepare($con, $sqlUser);

if (!$stmtUser) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare user query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtUser, "i", $user_id);
mysqli_stmt_execute($stmtUser);
$resultUser = mysqli_stmt_get_result($stmtUser);
$user = mysqli_fetch_assoc($resultUser);

if (!$user || $user['role'] !== 'user') {
    echo json_encode([
        "success" => false,
        "message" => "Only customers can delete their messages"
    ]);
    exit;
}

$email = trim($user['email'] ?? '');

$sql = "
    UPDATE contact_messages
    SET deleted_by_user = 1
    WHERE message_id = ? AND email = ?
";
$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare delete query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, "is", $message_id, $email);

if (!mysqli_stmt_execute($stmt)) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to delete message"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Message removed from your inbox"
]);
exit;