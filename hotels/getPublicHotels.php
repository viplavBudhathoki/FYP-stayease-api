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

    ROUND(AVG(r.rating),1) AS rating,
    COUNT(r.rating_id) AS review_count,

    MIN(ro.price) AS starting_price

FROM hotels h

LEFT JOIN ratings r 
ON r.hotel_id = h.hotel_id

LEFT JOIN rooms ro 
ON ro.hotel_id = h.hotel_id

WHERE h.status = 'active'

GROUP BY h.hotel_id
ORDER BY h.hotel_id DESC
";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);
    exit;
}

$hotels = [];

while ($row = mysqli_fetch_assoc($result)) {

    /* fallback image */
    if (empty($row['image_url']) || !file_exists(__DIR__ . '/../' . $row['image_url'])) {
        $row['image_url'] = 'uploads/hotels/placeholder.png';
    }

    /* rating fallback */
    if ($row['rating'] === null) {
        $row['rating'] = 0;
    }

    /* review fallback */
    if ($row['review_count'] === null) {
        $row['review_count'] = 0;
    }

    /* price fallback */
    if ($row['starting_price'] === null) {
        $row['starting_price'] = 0;
    }

    $hotels[] = $row;
}

echo json_encode([
    'success' => true,
    'data' => $hotels
]);