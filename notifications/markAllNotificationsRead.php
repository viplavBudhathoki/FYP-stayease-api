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

if (!$input || !isset($input['token'])) {
    echo json_encode([
        'success' => false,
        'message' => 'token is required'
    ]);
    exit;
}

$token = trim($input['token']);
$user_id = (int) getUserIdByToken($token);

if (!$user_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid token'
    ]);
    exit;
}

$sql = "
    UPDATE notifications
    SET is_read = 1
    WHERE user_id = ?
      AND is_read = 0
";

$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare update query'
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, "i", $user_id);

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to mark all notifications as read'
    ]);
    exit;
}

$affectedRows = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);

echo json_encode([
    'success' => true,
    'message' => 'All notifications marked as read',
    'affected_rows' => $affectedRows
]);