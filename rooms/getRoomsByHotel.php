<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Token is required'
    ]);
    exit;
}

if (!isset($_POST['hotel_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'hotel_id is required'
    ]);
    exit;
}

$token = $_POST['token'];
$hotel_id = (int) $_POST['hotel_id'];

if (!isVendor($token)) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized vendor'
    ]);
    exit;
}

$vendor_id = (int) getUserIdByToken($token);
if (!$vendor_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid token'
    ]);
    exit;
}

$check = mysqli_query(
    $con,
    "SELECT hotel_id FROM hotels WHERE hotel_id='$hotel_id' AND vendor_id='$vendor_id' LIMIT 1"
);

if (!$check || mysqli_num_rows($check) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'You do not own this hotel'
    ]);
    exit;
}

$sql = "SELECT room_id, hotel_id, vendor_id, name, type, status, price, capacity, description, amenities, image_url, created_at
        FROM rooms
        WHERE hotel_id='$hotel_id' AND vendor_id='$vendor_id'
        ORDER BY room_id DESC";

$result = mysqli_query($con, $sql);

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . mysqli_error($con)
    ]);
    exit;
}

$rooms = [];

while ($row = mysqli_fetch_assoc($result)) {
    if (empty($row['image_url']) || !file_exists(__DIR__ . '/../' . $row['image_url'])) {
        $row['image_url'] = 'uploads/rooms/placeholder.png';
    }

    $row['price'] = (float) $row['price'];
    $row['capacity'] = (int) $row['capacity'];

    $gallery = [];
    $room_id = (int) $row['room_id'];

    $imgSql = "SELECT image_id, room_id, image_url, created_at
               FROM room_images
               WHERE room_id='$room_id'
               ORDER BY image_id DESC";

    $imgResult = mysqli_query($con, $imgSql);

    if ($imgResult) {
        while ($imgRow = mysqli_fetch_assoc($imgResult)) {
            if (!empty($imgRow['image_url']) && file_exists(__DIR__ . '/../' . $imgRow['image_url'])) {
                $gallery[] = $imgRow;
            }
        }
    }

    $mainAlreadyExists = false;
    foreach ($gallery as $img) {
        if ($img['image_url'] === $row['image_url']) {
            $mainAlreadyExists = true;
            break;
        }
    }

    if (!$mainAlreadyExists) {
        array_unshift($gallery, [
            'image_id' => 0,
            'room_id' => $room_id,
            'image_url' => $row['image_url'],
            'created_at' => null
        ]);
    }

    $row['gallery'] = $gallery;

    $rooms[] = $row;
}

echo json_encode([
    'success' => true,
    'message' => 'Rooms fetched successfully',
    'data' => $rooms
]);