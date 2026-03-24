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

$hotel_id = (int)$_POST['hotel_id'];
$check_in = isset($_POST['check_in']) ? trim($_POST['check_in']) : '';
$check_out = isset($_POST['check_out']) ? trim($_POST['check_out']) : '';

$sqlHotelCheck = "SELECT hotel_id FROM hotels WHERE hotel_id = ? AND status = 'active' LIMIT 1";
$stmtHotelCheck = mysqli_prepare($con, $sqlHotelCheck);

if (!$stmtHotelCheck) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare hotel check'
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtHotelCheck, "i", $hotel_id);
mysqli_stmt_execute($stmtHotelCheck);
$hotelCheck = mysqli_stmt_get_result($stmtHotelCheck);

if (!$hotelCheck || mysqli_num_rows($hotelCheck) === 0) {
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
        $checkInDate && $checkInDate->format('Y-m-d') === $check_in &&
        $checkOutDate && $checkOutDate->format('Y-m-d') === $check_out &&
        $check_out > $check_in
    ) {
        $useDateFilter = true;
    }
}

if ($useDateFilter) {
    $sql = "SELECT 
                r.room_id, r.hotel_id, r.vendor_id, r.name, r.type, r.status, 
                r.price, r.capacity, r.description, r.amenities, r.image_url, r.created_at,
                EXISTS (
                    SELECT 1
                    FROM booking_rooms br
                    INNER JOIN bookings b ON b.booking_id = br.booking_id
                    WHERE br.room_id = r.room_id 
                      AND b.status IN ('confirmed', 'checked_in')
                      AND (? < b.check_out)
                      AND (? > b.check_in)
                ) AS is_booked_for_dates
            FROM rooms r
            WHERE r.hotel_id = ?
              AND r.status IN ('available', 'occupied', 'maintenance')
            ORDER BY r.room_id DESC";

    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare rooms query'
        ]);
        exit;
    }

    mysqli_stmt_bind_param($stmt, "ssi", $check_in, $check_out, $hotel_id);
} else {
    $sql = "SELECT 
                r.room_id, r.hotel_id, r.vendor_id, r.name, r.type, r.status, 
                r.price, r.capacity, r.description, r.amenities, r.image_url, r.created_at,
                0 AS is_booked_for_dates
            FROM rooms r
            WHERE r.hotel_id = ?
              AND r.status IN ('available', 'occupied', 'maintenance')
            ORDER BY r.room_id DESC";

    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare rooms query'
        ]);
        exit;
    }

    mysqli_stmt_bind_param($stmt, "i", $hotel_id);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);
    exit;
}

$rooms = [];
while ($row = mysqli_fetch_assoc($result)) {
    if (empty($row['image_url']) || !file_exists(__DIR__ . '/../' . $row['image_url'])) {
        $row['image_url'] = 'uploads/rooms/placeholder.png';
    }

    $row['is_booked_for_dates'] = (bool)$row['is_booked_for_dates'];
    $rooms[] = $row;
}

echo json_encode([
    'success' => true,
    'data' => $rooms
]);