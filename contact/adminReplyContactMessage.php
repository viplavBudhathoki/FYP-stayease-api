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
$reply = trim($input['reply'] ?? '');

if ($token === '' || $message_id <= 0 || $reply === '') {
    echo json_encode([
        "success" => false,
        "message" => "Token, message_id and reply are required"
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

$sqlCheck = "
    SELECT message_id
    FROM contact_messages
    WHERE message_id = ?
      AND deleted_by_admin = 0
    LIMIT 1
";
$stmtCheck = mysqli_prepare($con, $sqlCheck);

if (!$stmtCheck) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare message check query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtCheck, "i", $message_id);
mysqli_stmt_execute($stmtCheck);
$resultCheck = mysqli_stmt_get_result($stmtCheck);

if (!$resultCheck || mysqli_num_rows($resultCheck) === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Message not found"
    ]);
    exit;
}

$sql = "
    UPDATE contact_messages
    SET reply = ?, replied_at = NOW(), is_read = 1, user_read = 0
    WHERE message_id = ?
";
$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare reply query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, "si", $reply, $message_id);

if (!mysqli_stmt_execute($stmt)) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to save reply"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Reply saved successfully"
]);
exit;