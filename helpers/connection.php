<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$host = "localhost";
$user = "root";
$pass = "";
$db   = "stayease";
$port = 3306;

$con = mysqli_connect($host, $user, $pass, $db, $port);

if (!$con) {
    echo json_encode([
        "success" => false,
        "message" => "Connection failed"
    ]);
    exit;
}

mysqli_set_charset($con, "utf8mb4");