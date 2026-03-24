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

$where = ["r.vendor_id = ?"];
$params = [$vendor_id];
$types = "i";

if ($status !== "" && $status !== "all") {
    $allowed_statuses = ['confirmed', 'checked_in', 'completed', 'cancelled'];
    if (!in_array($status, $allowed_statuses, true)) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid status filter"
        ]);
        exit;
    }

    $where[] = "b.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($from_date !== "") {
    $d = DateTime::createFromFormat('Y-m-d', $from_date);
    if (!$d || $d->format('Y-m-d') !== $from_date) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid from_date format"
        ]);
        exit;
    }

    $where[] = "b.check_in >= ?";
    $params[] = $from_date;
    $types .= "s";
}

if ($to_date !== "") {
    $d = DateTime::createFromFormat('Y-m-d', $to_date);
    if (!$d || $d->format('Y-m-d') !== $to_date) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid to_date format"
        ]);
        exit;
    }

    $where[] = "b.check_out <= ?";
    $params[] = $to_date;
    $types .= "s";
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
        b.rooms_requested,

        u.user_id,
        u.full_name AS customer_name,
        u.email AS customer_email,

        MIN(r.room_id) AS room_id,
        GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') AS room_name,
        GROUP_CONCAT(DISTINCT r.type ORDER BY r.type SEPARATOR ', ') AS room_type,
        MIN(r.image_url) AS room_image,

        h.hotel_id,
        h.name AS hotel_name,
        h.location AS hotel_location

    FROM bookings b
    INNER JOIN users u ON u.user_id = b.user_id
    INNER JOIN booking_rooms br ON br.booking_id = b.booking_id
    INNER JOIN rooms r ON r.room_id = br.room_id
    INNER JOIN hotels h ON h.hotel_id = r.hotel_id

    WHERE $where_sql
    GROUP BY
        b.booking_id,
        b.check_in,
        b.check_out,
        b.total_price,
        b.status,
        b.created_at,
        b.rooms_requested,
        u.user_id,
        u.full_name,
        u.email,
        h.hotel_id,
        h.name,
        h.location
    ORDER BY b.booking_id DESC
";

$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare vendor bookings query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch vendor bookings"
    ]);
    exit;
}

$data = [];

while ($row = mysqli_fetch_assoc($result)) {
    $row['rooms_requested'] = (int) ($row['rooms_requested'] ?? 1);
    $data[] = $row;
}

echo json_encode([
    "success" => true,
    "data" => $data
]);