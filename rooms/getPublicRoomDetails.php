<?php
include __DIR__ . '/../helpers/connection.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['room_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'room_id is required'
    ]);
    exit;
}

$room_id = (int) $_POST['room_id'];

if ($room_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid room id'
    ]);
    exit;
}

$sql = "
    SELECT
        r.room_id,
        r.hotel_id,
        r.vendor_id,
        r.name,
        r.type,
        r.status,
        r.price,
        r.capacity,
        r.description,
        r.amenities,
        r.image_url,
        r.created_at,

        h.name AS hotel_name,
        h.location AS hotel_location,
        h.description AS hotel_description,
        h.status AS hotel_status,
        h.image_url AS hotel_image_url

    FROM rooms r
    INNER JOIN hotels h ON h.hotel_id = r.hotel_id
    WHERE r.room_id = '$room_id'
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
        'message' => 'Room not found'
    ]);
    exit;
}

$data = mysqli_fetch_assoc($result);

if (empty($data['image_url']) || !file_exists(__DIR__ . '/../' . $data['image_url'])) {
    $data['image_url'] = 'uploads/rooms/placeholder.png';
}

if (empty($data['hotel_image_url']) || !file_exists(__DIR__ . '/../' . $data['hotel_image_url'])) {
    $data['hotel_image_url'] = 'uploads/hotels/placeholder.png';
}

$data['price'] = (float) $data['price'];
$data['capacity'] = (int) $data['capacity'];

/* room gallery images */
$gallery = [];

$imgSql = "
    SELECT image_id, image_url
    FROM room_images
    WHERE room_id = '$room_id'
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

/* ensure main room image is included */
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