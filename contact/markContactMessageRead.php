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

if (!isAdmin($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized admin"
    ]);
    exit;
}

$sql = "UPDATE contact_messages SET is_read = 1 WHERE message_id = ?";
$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare update query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, "i", $message_id);

if (!mysqli_stmt_execute($stmt)) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to mark message as read"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Message marked as read"
]);