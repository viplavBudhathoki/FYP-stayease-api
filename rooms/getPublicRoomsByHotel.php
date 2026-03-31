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

$checkHotelStmt = mysqli_prepare($con, "
    SELECT hotel_id, name, location, status
    FROM hotels
    WHERE hotel_id = ?
      AND status = 'active'
    LIMIT 1
");

if (!$checkHotelStmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to verify hotel'
    ]);
    exit;
}

mysqli_stmt_bind_param($checkHotelStmt, "i", $hotel_id);
mysqli_stmt_execute($checkHotelStmt);
$checkHotel = mysqli_stmt_get_result($checkHotelStmt);

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
        r.total_rooms,
        r.type,
        r.status,
        r.price,
        r.capacity,
        r.description,
        r.amenities,
        r.image_url,
        r.created_at
";

$params = [];
$types = "";

if ($useDateFilter) {
    $sql .= ",
        (
            SELECT COUNT(*)
            FROM booking_rooms br
            INNER JOIN bookings b ON b.booking_id = br.booking_id
            WHERE br.room_id = r.room_id
              AND b.status IN ('confirmed', 'checked_in')
              AND (? < b.check_out)
              AND (? > b.check_in)
        ) AS booked_rooms
    ";
    $params[] = $check_in;
    $params[] = $check_out;
    $types .= "ss";
} else {
    $sql .= ",
        0 AS booked_rooms
    ";
}

$sql .= "
    FROM rooms r
    WHERE r.hotel_id = ?
    ORDER BY r.type ASC, r.price ASC, r.room_id ASC
";

$params[] = $hotel_id;
$types .= "i";

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
    $row['total_rooms'] = max(1, (int) ($row['total_rooms'] ?? 1));
    $row['booked_rooms'] = max(0, (int) ($row['booked_rooms'] ?? 0));

    if ($row['booked_rooms'] > $row['total_rooms']) {
        $row['booked_rooms'] = $row['total_rooms'];
    }

    $row['available_rooms'] = max(0, $row['total_rooms'] - $row['booked_rooms']);
    $row['is_booked_for_dates'] = $row['available_rooms'] <= 0;
    $row['can_book'] = $row['status'] === 'available' && $row['available_rooms'] > 0;

    if ($row['available_rooms'] <= 0) {
        $row['availability_label'] = 'Sold out for selected dates';
    } elseif ($row['available_rooms'] <= 2) {
        $row['availability_label'] = 'Only ' . $row['available_rooms'] . ' room(s) left';
    } else {
        $row['availability_label'] = $row['available_rooms'] . ' room(s) available';
    }

    if (!empty($row['amenities'])) {
        $row['amenities_array'] = array_values(
            array_filter(array_map('trim', explode(',', $row['amenities'])))
        );
    } else {
        $row['amenities_array'] = [];
    }

    $gallery = [];
    $room_id = (int) $row['room_id'];

    $imgStmt = mysqli_prepare($con, "
        SELECT image_id, image_url
        FROM room_images
        WHERE room_id = ?
        ORDER BY image_id DESC
    ");

    if ($imgStmt) {
        mysqli_stmt_bind_param($imgStmt, "i", $room_id);
        mysqli_stmt_execute($imgStmt);
        $imgResult = mysqli_stmt_get_result($imgStmt);

        if ($imgResult) {
            while ($img = mysqli_fetch_assoc($imgResult)) {
                if (!empty($img['image_url']) && file_exists(__DIR__ . '/../' . $img['image_url'])) {
                    $gallery[] = $img;
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