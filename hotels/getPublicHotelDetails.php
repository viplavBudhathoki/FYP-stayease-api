<?php
include __DIR__ . '/../helpers/connection.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['hotel_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'hotel_id is required'
    ]);
    exit;
}

$hotel_id = (int) $_POST['hotel_id'];

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
    WHERE status IN ('available', 'occupied', 'maintenance')
    GROUP BY hotel_id
) rm ON rm.hotel_id = h.hotel_id

WHERE h.hotel_id = '$hotel_id'
  AND h.status = 'active'
LIMIT 1
";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . mysqli_error($con)
    ]);
    exit;
}

if (mysqli_num_rows($result) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Hotel not found'
    ]);
    exit;
}

$data = mysqli_fetch_assoc($result);

if (empty($data['image_url']) || !file_exists(__DIR__ . '/../' . $data['image_url'])) {
    $data['image_url'] = 'uploads/hotels/placeholder.png';
}

$data['rating'] = (float) $data['rating'];
$data['review_count'] = (int) $data['review_count'];
$data['starting_price'] = (float) $data['starting_price'];

/* hotel gallery images */
$gallery = [];

$imgSql = "
    SELECT image_id, image_url
    FROM hotel_images
    WHERE hotel_id = '$hotel_id'
    ORDER BY image_id DESC
";

$imgResult = mysqli_query($con, $imgSql);

if ($imgResult) {
    while ($imgRow = mysqli_fetch_assoc($imgResult)) {
        if (!empty($imgRow['image_url']) && file_exists(__DIR__ . '/../' . $imgRow['image_url'])) {
            $gallery[] = $imgRow;
        }
    }
}

/* ensure main image is available in gallery too */
$mainAlreadyExists = false;
foreach ($gallery as $img) {
    if ($img['image_url'] === $data['image_url']) {
        $mainAlreadyExists = true;
        break;
    }
}

if (!$mainAlreadyExists) {
    array_unshift($gallery, [
        'image_id' => 0,
        'image_url' => $data['image_url']
    ]);
}

$data['gallery'] = $gallery;

echo json_encode([
    'success' => true,
    'data' => $data
]);