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

if (!isset($con) || !$con) {
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}

if (!isset($_POST['token'])) {
    echo json_encode([
        "success" => false,
        "message" => "Token is required"
    ]);
    exit;
}

$token = trim($_POST['token']);

if ($token === '') {
    echo json_encode([
        "success" => false,
        "message" => "Token cannot be empty"
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

$sql = "
    SELECT
        message_id,
        full_name,
        email,
        subject,
        message,
        is_read,
        reply,
        replied_at,
        user_read,
        created_at
    FROM contact_messages
    WHERE deleted_by_admin = 0
    ORDER BY created_at DESC
";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch contact messages: " . mysqli_error($con)
    ]);
    exit;
}

$messages = [];
$unread_count = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $row['message_id'] = (int) $row['message_id'];
    $row['is_read'] = (int) $row['is_read'];
    $row['user_read'] = (int) $row['user_read'];

    if ($row['is_read'] === 0) {
        $unread_count++;
    }

    $messages[] = $row;
}

echo json_encode([
    "success" => true,
    "unread_count" => $unread_count,
    "data" => $messages
]);
exit;