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

$checkStmt = mysqli_prepare(
    $con,
    "SELECT hotel_id FROM hotels WHERE hotel_id = ? AND vendor_id = ? LIMIT 1"
);

if (!$checkStmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to verify hotel ownership'
    ]);
    exit;
}

mysqli_stmt_bind_param($checkStmt, "ii", $hotel_id, $vendor_id);
mysqli_stmt_execute($checkStmt);
$check = mysqli_stmt_get_result($checkStmt);

if (!$check || mysqli_num_rows($check) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'You do not own this hotel'
    ]);
    exit;
}

$sql = "
    SELECT
        room_id,
        hotel_id,
        vendor_id,
        name,
        total_rooms,
        type,
        status,
        price,
        capacity,
        description,
        amenities,
        image_url,
        created_at
    FROM rooms
    WHERE hotel_id = ? AND vendor_id = ?
    ORDER BY room_id DESC
";

$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Database prepare error: ' . mysqli_error($con)
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, "ii", $hotel_id, $vendor_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

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
    $row['total_rooms'] = max(1, (int) ($row['total_rooms'] ?? 1));

    $gallery = [];
    $room_id = (int) $row['room_id'];

    $imgStmt = mysqli_prepare($con, "
        SELECT image_id, room_id, image_url, created_at
        FROM room_images
        WHERE room_id = ?
        ORDER BY image_id DESC
    ");

    if ($imgStmt) {
        mysqli_stmt_bind_param($imgStmt, "i", $room_id);
        mysqli_stmt_execute($imgStmt);
        $imgResult = mysqli_stmt_get_result($imgStmt);

        if ($imgResult) {
            while ($imgRow = mysqli_fetch_assoc($imgResult)) {
                if (!empty($imgRow['image_url']) && file_exists(__DIR__ . '/../' . $imgRow['image_url'])) {
                    $gallery[] = $imgRow;
                }
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