<?php
include __DIR__ . '/../helpers/connection.php';

header("Content-Type: application/json; charset=UTF-8");

$sql = "
SELECT 
    h.hotel_id,
    h.name,
    h.location,
    h.description,
    h.image_url,
    h.status,
    h.created_at,

    COALESCE(rt.avg_rating, 0) AS rating,
    COALESCE(rt.review_count, 0) AS review_count,
    COALESCE(rm.starting_price, 0) AS starting_price

FROM hotels h

LEFT JOIN (
    SELECT 
        hotel_id,
        ROUND(AVG(rating), 1) AS avg_rating,
        COUNT(rating_id) AS review_count
    FROM ratings
    GROUP BY hotel_id
) rt ON rt.hotel_id = h.hotel_id

LEFT JOIN (
    SELECT 
        hotel_id,
        MIN(price) AS starting_price
    FROM rooms
    GROUP BY hotel_id
) rm ON rm.hotel_id = h.hotel_id

WHERE h.status = 'active'
ORDER BY h.hotel_id DESC
";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . mysqli_error($con)
    ]);
    exit;
}

$hotels = [];

while ($row = mysqli_fetch_assoc($result)) {
    if (empty($row['image_url']) || !file_exists(__DIR__ . '/../' . $row['image_url'])) {
        $row['image_url'] = 'uploads/hotels/placeholder.png';
    }

    $row['rating'] = (float) $row['rating'];
    $row['review_count'] = (int) $row['review_count'];
    $row['starting_price'] = (float) $row['starting_price'];

    $hotels[] = $row;
}

echo json_encode([
    'success' => true,
    'data' => $hotels
]);