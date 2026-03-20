<?php

include __DIR__ . '/../helpers/connection.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_GET['hotel_id'])) {
    echo json_encode([
        "success" => false,
        "message" => "Hotel id required"
    ]);
    exit;
}

$hotel_id = (int) $_GET['hotel_id'];

$sql = "
    SELECT 
        r.rating_id,
        r.booking_id,
        r.room_id,
        r.rating,
        r.review_message,
        r.created_at,
        u.full_name
    FROM ratings r
    INNER JOIN users u ON u.user_id = r.user_id
    WHERE r.hotel_id = '$hotel_id'
    ORDER BY r.created_at DESC
";

$result = mysqli_query($con, $sql);

$data = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
}

echo json_encode([
    "success" => true,
    "data" => $data
]);