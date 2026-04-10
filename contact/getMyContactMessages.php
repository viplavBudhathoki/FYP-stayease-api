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

if (!isset($_POST['token'])) {
    echo json_encode([
        "success" => false,
        "message" => "Token is required"
    ]);
    exit;
}

$token = trim($_POST['token']);
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
        "message" => "Only customers can access messages"
    ]);
    exit;
}

$email = trim($user['email'] ?? '');

$sql = "
    SELECT
        message_id,
        full_name,
        email,
        subject,
        message,
        reply,
        is_read,
        user_read,
        created_at,
        replied_at
    FROM contact_messages
    WHERE email = ?
      AND deleted_by_user = 0
    ORDER BY created_at DESC
";

$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare messages query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$messages = [];

while ($row = mysqli_fetch_assoc($result)) {
    $row['message_id'] = (int) $row['message_id'];
    $row['is_read'] = (int) $row['is_read'];
    $row['user_read'] = (int) $row['user_read'];
    $messages[] = $row;
}

echo json_encode([
    "success" => true,
    "data" => $messages
]);
exit;