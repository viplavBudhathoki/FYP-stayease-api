<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

$token = '';

if (isset($_GET['token'])) {
    $token = trim($_GET['token']);
} elseif (isset($_POST['token'])) {
    $token = trim($_POST['token']);
}

if (!$token) {
    echo json_encode([
        "success" => false,
        "message" => "Token is required"
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

$admin_id = (int) getUserIdByToken($token);

if (!$admin_id) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

$sqlSettings = "SELECT * FROM system_settings ORDER BY setting_id ASC LIMIT 1";
$resultSettings = mysqli_query($con, $sqlSettings);

if (!$resultSettings) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch system settings"
    ]);
    exit;
}

$settings = mysqli_fetch_assoc($resultSettings);

if (!$settings) {
    echo json_encode([
        "success" => false,
        "message" => "System settings not found"
    ]);
    exit;
}

$sqlAdmin = "SELECT user_id, full_name, email, profile_photo FROM users WHERE user_id = ? AND role = 'admin' LIMIT 1";
$stmtAdmin = mysqli_prepare($con, $sqlAdmin);

if (!$stmtAdmin) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare admin query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtAdmin, "i", $admin_id);
mysqli_stmt_execute($stmtAdmin);
$resultAdmin = mysqli_stmt_get_result($stmtAdmin);
$admin = mysqli_fetch_assoc($resultAdmin);

echo json_encode([
    "success" => true,
    "data" => [
        "settings" => [
            "platform_name" => $settings["platform_name"] ?? "StayEase",
            "support_email" => $settings["support_email"] ?? "",
            "support_phone" => $settings["support_phone"] ?? "",
            "default_currency" => $settings["default_currency"] ?? "Rs.",
            "timezone" => $settings["timezone"] ?? "Asia/Kathmandu",
            "min_booking_nights" => (int) ($settings["min_booking_nights"] ?? 1),
            "max_booking_nights" => (int) ($settings["max_booking_nights"] ?? 30),
            "allow_same_day_booking" => (int) ($settings["allow_same_day_booking"] ?? 1),
            "default_check_in_time" => $settings["default_check_in_time"] ?? "14:00",
            "default_check_out_time" => $settings["default_check_out_time"] ?? "12:00",
            "free_cancellation_days" => (int) ($settings["free_cancellation_days"] ?? 1),
            "no_show_charge_type" => $settings["no_show_charge_type"] ?? "one_night",
            "cancellation_policy_text" => $settings["cancellation_policy_text"] ?? "",
            "notify_new_booking" => (int) ($settings["notify_new_booking"] ?? 1),
            "notify_booking_cancelled" => (int) ($settings["notify_booking_cancelled"] ?? 1),
            "notify_new_review" => (int) ($settings["notify_new_review"] ?? 1),
            "notify_vendor_registration" => (int) ($settings["notify_vendor_registration"] ?? 1)
        ],
        "admin" => [
            "full_name" => $admin["full_name"] ?? "",
            "email" => $admin["email"] ?? "",
            "profile_photo" => $admin["profile_photo"] ?? ""
        ]
    ]
]);