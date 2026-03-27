<?php
include __DIR__ . '/../helpers/connection.php';

header("Content-Type: application/json; charset=UTF-8");

$hotel_id = isset($_POST['hotel_id']) ? (int) $_POST['hotel_id'] : 0;
$check_in = isset($_POST['check_in']) ? trim($_POST['check_in']) : '';
$check_out = isset($_POST['check_out']) ? trim($_POST['check_out']) : '';

if ($hotel_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'hotel_id is required'
    ]);
    exit;
}

$checkHotel = mysqli_query($con, "
    SELECT hotel_id, name, location, status
    FROM hotels
    WHERE hotel_id = '$hotel_id'
      AND status = 'active'
    LIMIT 1
");

if (!$checkHotel || mysqli_num_rows($checkHotel) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Hotel not found'
    ]);
    exit;
}

$useDateFilter = false;
if ($check_in !== '' && $check_out !== '') {
    $checkInDate = DateTime::createFromFormat('Y-m-d', $check_in);
    $checkOutDate = DateTime::createFromFormat('Y-m-d', $check_out);

    if (
        $checkInDate &&
        $checkInDate->format('Y-m-d') === $check_in &&
        $checkOutDate &&
        $checkOutDate->format('Y-m-d') === $check_out &&
        $check_out > $check_in
    ) {
        $useDateFilter = true;
    }
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
        r.created_at
    FROM rooms r
    WHERE r.hotel_id = ?
      AND r.status = 'available'
";

$params = [$hotel_id];
$types = "i";

if ($useDateFilter) {
    $sql .= "
      AND r.room_id NOT IN (
          SELECT br.room_id
          FROM booking_rooms br
          INNER JOIN bookings b ON b.booking_id = br.booking_id
          WHERE b.status IN ('confirmed', 'checked_in')
            AND (? < b.check_out)
            AND (? > b.check_in)
      )
    ";
    $params[] = $check_in;
    $params[] = $check_out;
    $types .= "ss";
}

$sql .= " ORDER BY r.price ASC, r.room_id DESC";

$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Prepare failed: ' . mysqli_error($con)
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Query failed: ' . mysqli_error($con)
    ]);
    exit;
}

$data = [];

while ($row = mysqli_fetch_assoc($result)) {
    if (empty($row['image_url']) || !file_exists(__DIR__ . '/../' . $row['image_url'])) {
        $row['image_url'] = 'uploads/rooms/placeholder.png';
    }

    $row['price'] = (float) $row['price'];
    $row['capacity'] = (int) $row['capacity'];

    if (!empty($row['amenities'])) {
        $row['amenities_array'] = array_values(array_filter(array_map('trim', explode(',', $row['amenities']))));
    } else {
        $row['amenities_array'] = [];
    }

    $gallery = [];
    $imgResult = mysqli_query($con, "
        SELECT image_id, image_url
        FROM room_images
        WHERE room_id = '{$row['room_id']}'
        ORDER BY image_id DESC
    ");

    if ($imgResult) {
        while ($img = mysqli_fetch_assoc($imgResult)) {
            if (!empty($img['image_url']) && file_exists(__DIR__ . '/../' . $img['image_url'])) {
                $gallery[] = $img;
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
            'image_url' => $row['image_url']
        ]);
    }

    $row['gallery'] = $gallery;

    $data[] = $row;
}

echo json_encode([
    'success' => true,
    'hotel_id' => $hotel_id,
    'check_in' => $check_in,
    'check_out' => $check_out,
    'count' => count($data),
    'data' => $data
]);