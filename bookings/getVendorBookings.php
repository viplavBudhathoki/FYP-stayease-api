<?php

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'])) {
    echo json_encode([
        "success" => false,
        "message" => "Token required"
    ]);
    exit;
}

$token = $_POST['token'];

if (!isVendor($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized vendor"
    ]);
    exit;
}

$vendor_id = getUserIdByToken($token);

if (!$vendor_id) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

$status = isset($_POST['status']) ? trim($_POST['status']) : "";
$from_date = isset($_POST['from_date']) ? trim($_POST['from_date']) : "";
$to_date = isset($_POST['to_date']) ? trim($_POST['to_date']) : "";

$where = ["r.vendor_id = '$vendor_id'"];

if ($status !== "" && $status !== "all") {
    $status_safe = mysqli_real_escape_string($con, $status);
    $where[] = "b.status = '$status_safe'";
}

if ($from_date !== "") {
    $from_date_safe = mysqli_real_escape_string($con, $from_date);
    $where[] = "b.check_in >= '$from_date_safe'";
}

if ($to_date !== "") {
    $to_date_safe = mysqli_real_escape_string($con, $to_date);
    $where[] = "b.check_out <= '$to_date_safe'";
}

$where_sql = implode(" AND ", $where);

$sql = "
    SELECT
        b.booking_id,
        b.check_in,
        b.check_out,
        b.total_price,
        b.status,
        b.created_at,

        u.user_id,
        u.full_name AS customer_name,
        u.email AS customer_email,

        r.room_id,
        r.name AS room_name,
        r.type AS room_type,
        r.image_url AS room_image,

        h.hotel_id,
        h.name AS hotel_name,
        h.location AS hotel_location

    FROM bookings b
    INNER JOIN users u ON u.user_id = b.user_id
    INNER JOIN rooms r ON r.room_id = b.room_id
    INNER JOIN hotels h ON h.hotel_id = r.hotel_id

    WHERE $where_sql
    ORDER BY b.booking_id DESC
";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch vendor bookings",
        "error" => mysqli_error($con)
    ]);
    exit;
}

$data = [];

while ($row = mysqli_fetch_assoc($result)) {
    $data[] = $row;
}

echo json_encode([
    "success" => true,
    "data" => $data
]);