<?php
include __DIR__ . '/../helpers/connection.php';

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);

try {
    $sql = "
        SELECT 
            r.rating_id,
            r.rating,
            r.review_message,
            r.created_at,
            r.user_id,
            r.hotel_id,
            u.full_name AS customer_name,
            u.profile_photo AS customer_photo,
            h.name AS hotel_name
        FROM ratings r
        INNER JOIN users u ON r.user_id = u.user_id
        INNER JOIN hotels h ON r.hotel_id = h.hotel_id
        WHERE r.review_message IS NOT NULL
          AND TRIM(r.review_message) != ''
        ORDER BY r.created_at DESC, r.rating_id DESC
        LIMIT 4
    ";

    $result = mysqli_query($con, $sql);

    if (!$result) {
        echo json_encode([
            "success" => false,
            "message" => "Query failed",
            "error" => mysqli_error($con)
        ]);
        exit;
    }

    $reviews = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $row['rating_id'] = (int) $row['rating_id'];
        $row['rating'] = (float) $row['rating'];
        $row['user_id'] = (int) $row['user_id'];
        $row['hotel_id'] = (int) $row['hotel_id'];

        if (
            empty($row['customer_photo']) ||
            !file_exists(__DIR__ . '/../' . $row['customer_photo'])
        ) {
            $row['customer_photo'] = null;
        }

        $reviews[] = $row;
    }

    echo json_encode([
        "success" => true,
        "data" => $reviews
    ]);
} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
}