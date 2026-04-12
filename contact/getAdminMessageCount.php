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

if (!isAdmin($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized admin"
    ]);
    exit;
}

$sql = "
    SELECT COUNT(*) AS count
    FROM contact_messages
    WHERE is_read = 0
      AND deleted_by_admin = 0
";

$result = mysqli_query($con, $sql);
$row = mysqli_fetch_assoc($result);

echo json_encode([
    "success" => true,
    "count" => (int) ($row['count'] ?? 0)
]);
exit;