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
$full_name = trim($input['full_name'] ?? '');
$email = trim($input['email'] ?? '');
$subject = trim($input['subject'] ?? '');
$message = trim($input['message'] ?? '');

if ($token === '') {
    echo json_encode([
        "success" => false,
        "message" => "Please log in to send a message"
    ]);
    exit;
}

$user_id = (int) getUserIdByToken($token);

if (!$user_id) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid token. Please log in again"
    ]);
    exit;
}

$sqlUser = "SELECT full_name, email, role FROM users WHERE user_id = ? LIMIT 1";
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
        "message" => "Only logged in customers can send messages"
    ]);
    exit;
}

if ($full_name === '' || $email === '' || $message === '') {
    echo json_encode([
        "success" => false,
        "message" => "Full name, email and message are required"
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid email address"
    ]);
    exit;
}

$sql = "INSERT INTO contact_messages (full_name, email, subject, message) VALUES (?, ?, ?, ?)";
$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare contact message query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, "ssss", $full_name, $email, $subject, $message);

if (!mysqli_stmt_execute($stmt)) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to save message"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Your message has been sent successfully"
]);