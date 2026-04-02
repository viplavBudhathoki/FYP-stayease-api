<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';
include __DIR__ . '/../helpers/notification_helper.php';

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$token = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = isset($_GET['token']) ? trim($_GET['token']) : '';
} else {
    $token = isset($_POST['token']) ? trim($_POST['token']) : '';
}

if ($token === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Token is required'
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

$limit = 20;
if (isset($_GET['limit'])) {
    $limit = (int) $_GET['limit'];
} elseif (isset($_POST['limit'])) {
    $limit = (int) $_POST['limit'];
}
$limit = max(1, min($limit, 100));

$sql = "
    SELECT
        notification_id,
        user_id,
        title,
        message,
        type,
        related_id,
        is_read,
        created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY notification_id DESC
    LIMIT ?
";

$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare notifications query'
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, "ii", $user_id, $limit);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch notifications'
    ]);
    exit;
}

$notifications = [];

while ($row = mysqli_fetch_assoc($result)) {
    $row['notification_id'] = (int) $row['notification_id'];
    $row['user_id'] = (int) $row['user_id'];
    $row['related_id'] = $row['related_id'] !== null ? (int) $row['related_id'] : null;
    $row['is_read'] = (int) $row['is_read'];

    $notifications[] = $row;
}

$unreadCount = getUnreadNotificationCount($con, $user_id);

echo json_encode([
    'success' => true,
    'message' => 'Notifications fetched successfully',
    'unread_count' => $unreadCount,
    'count' => count($notifications),
    'data' => $notifications
]);