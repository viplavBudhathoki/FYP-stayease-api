<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!$input || !isset($input['token'], $input['notification_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'token and notification_id are required'
    ]);
    exit;
}

$token = trim($input['token']);
$notification_id = (int) $input['notification_id'];

if ($notification_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid notification id'
    ]);
    exit;
}

$user_id = (int) getUserIdByToken($token);

if (!$user_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid token'
    ]);
    exit;
}

$checkStmt = mysqli_prepare(
    $con,
    "SELECT notification_id FROM notifications WHERE notification_id = ? AND user_id = ? LIMIT 1"
);

if (!$checkStmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to verify notification'
    ]);
    exit;
}

mysqli_stmt_bind_param($checkStmt, "ii", $notification_id, $user_id);
mysqli_stmt_execute($checkStmt);
$checkResult = mysqli_stmt_get_result($checkStmt);

if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Notification not found'
    ]);
    exit;
}

$updateStmt = mysqli_prepare(
    $con,
    "UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?"
);

if (!$updateStmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare notification update'
    ]);
    exit;
}

mysqli_stmt_bind_param($updateStmt, "ii", $notification_id, $user_id);

if (!mysqli_stmt_execute($updateStmt)) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to mark notification as read'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Notification marked as read'
]);