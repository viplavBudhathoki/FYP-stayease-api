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
    echo json_encode(["success" => false, "message" => "Token is required"]);
    exit;
}

$token = trim($_POST['token']);
$user_id = (int) getUserIdByToken($token);

if (!$user_id) {
    echo json_encode(["success" => false, "message" => "Unauthorized user"]);
    exit;
}

$sqlUser = "SELECT email, role FROM users WHERE user_id = ? LIMIT 1";
$stmtUser = mysqli_prepare($con, $sqlUser);
mysqli_stmt_bind_param($stmtUser, "i", $user_id);
mysqli_stmt_execute($stmtUser);
$resultUser = mysqli_stmt_get_result($stmtUser);
$user = mysqli_fetch_assoc($resultUser);

if (!$user || $user['role'] !== 'user') {
    echo json_encode(["success" => false, "message" => "Only customers can access this"]);
    exit;
}

$email = trim($user['email'] ?? '');

$sql = "
    SELECT COUNT(*) AS count
    FROM contact_messages
    WHERE email = ?
      AND reply IS NOT NULL
      AND user_read = 0
      AND deleted_by_user = 0
";

$stmt = mysqli_prepare($con, $sql);
mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

echo json_encode([
    "success" => true,
    "count" => (int) ($row['count'] ?? 0)
]);
exit;