<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON payload'
    ]);
    exit;
}

$requiredFields = [
    'token',
    'offer_id',
    'hotel_id',
    'title',
    'offer_type',
    'discount_type',
    'discount_value',
    'start_date',
    'end_date',
    'min_nights',
    'min_rooms',
    'status',
    'is_featured'
];

foreach ($requiredFields as $field) {
    if (!isset($input[$field])) {
        echo json_encode([
            'success' => false,
            'message' => "$field is required"
        ]);
        exit;
    }
}

$token = trim($input['token']);
$offer_id = (int) $input['offer_id'];
$hotel_id = (int) $input['hotel_id'];
$title = trim($input['title']);
$description = isset($input['description']) ? trim($input['description']) : '';
$offer_type = trim($input['offer_type']);
$discount_type = trim($input['discount_type']);
$discount_value = (float) $input['discount_value'];
$room_id = isset($input['room_id']) && $input['room_id'] !== null && $input['room_id'] !== ''
    ? (int) $input['room_id']
    : null;
$start_date = trim($input['start_date']);
$end_date = trim($input['end_date']);
$min_nights = (int) $input['min_nights'];
$min_rooms = (int) $input['min_rooms'];
$status = trim($input['status']);
$is_featured = (int) $input['is_featured'];

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

if ($offer_id <= 0 || $hotel_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid offer or hotel id'
    ]);
    exit;
}

if ($title === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Offer title is required'
    ]);
    exit;
}

if (!in_array($offer_type, ['hotel', 'room'], true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid offer type'
    ]);
    exit;
}

if (!in_array($discount_type, ['percentage', 'fixed'], true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid discount type'
    ]);
    exit;
}

if (!in_array($status, ['active', 'inactive', 'expired'], true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid status'
    ]);
    exit;
}

if ($discount_value <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Discount value must be greater than 0'
    ]);
    exit;
}

if ($discount_type === 'percentage' && $discount_value > 100) {
    echo json_encode([
        'success' => false,
        'message' => 'Percentage discount cannot be more than 100'
    ]);
    exit;
}

if ($min_nights < 1 || $min_rooms < 1) {
    echo json_encode([
        'success' => false,
        'message' => 'Minimum nights and rooms must be at least 1'
    ]);
    exit;
}

$startObj = DateTime::createFromFormat('Y-m-d', $start_date);
$endObj = DateTime::createFromFormat('Y-m-d', $end_date);

if (
    !$startObj || $startObj->format('Y-m-d') !== $start_date ||
    !$endObj || $endObj->format('Y-m-d') !== $end_date
) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid start date or end date'
    ]);
    exit;
}

if ($end_date < $start_date) {
    echo json_encode([
        'success' => false,
        'message' => 'End date cannot be before start date'
    ]);
    exit;
}

$checkOfferStmt = mysqli_prepare(
    $con,
    "SELECT offer_id FROM offers WHERE offer_id = ? AND vendor_id = ? LIMIT 1"
);

if (!$checkOfferStmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to verify offer ownership'
    ]);
    exit;
}

mysqli_stmt_bind_param($checkOfferStmt, "ii", $offer_id, $vendor_id);
mysqli_stmt_execute($checkOfferStmt);
$offerResult = mysqli_stmt_get_result($checkOfferStmt);

if (!$offerResult || mysqli_num_rows($offerResult) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Offer not found or not owned by you'
    ]);
    exit;
}

$checkHotelStmt = mysqli_prepare(
    $con,
    "SELECT hotel_id FROM hotels WHERE hotel_id = ? AND vendor_id = ? LIMIT 1"
);

if (!$checkHotelStmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to verify hotel ownership'
    ]);
    exit;
}

mysqli_stmt_bind_param($checkHotelStmt, "ii", $hotel_id, $vendor_id);
mysqli_stmt_execute($checkHotelStmt);
$hotelResult = mysqli_stmt_get_result($checkHotelStmt);

if (!$hotelResult || mysqli_num_rows($hotelResult) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'You do not own this hotel'
    ]);
    exit;
}

if ($offer_type === 'hotel') {
    $room_id = null;
} else {
    if (!$room_id || $room_id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Room is required for room offer'
        ]);
        exit;
    }

    $checkRoomStmt = mysqli_prepare(
        $con,
        "SELECT room_id FROM rooms WHERE room_id = ? AND hotel_id = ? AND vendor_id = ? LIMIT 1"
    );

    if (!$checkRoomStmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to verify room ownership'
        ]);
        exit;
    }

    mysqli_stmt_bind_param($checkRoomStmt, "iii", $room_id, $hotel_id, $vendor_id);
    mysqli_stmt_execute($checkRoomStmt);
    $roomResult = mysqli_stmt_get_result($checkRoomStmt);

    if (!$roomResult || mysqli_num_rows($roomResult) === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Selected room does not belong to this hotel'
        ]);
        exit;
    }
}

if ($offer_type === 'hotel') {
    $sql = "
        UPDATE offers
        SET hotel_id = ?,
            room_id = NULL,
            title = ?,
            description = ?,
            offer_type = ?,
            discount_type = ?,
            discount_value = ?,
            start_date = ?,
            end_date = ?,
            min_nights = ?,
            min_rooms = ?,
            status = ?,
            is_featured = ?
        WHERE offer_id = ? AND vendor_id = ?
    ";

    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare offer update: ' . mysqli_error($con)
        ]);
        exit;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "issssdssiisii",
        $hotel_id,
        $title,
        $description,
        $offer_type,
        $discount_type,
        $discount_value,
        $start_date,
        $end_date,
        $min_nights,
        $min_rooms,
        $status,
        $is_featured,
        $offer_id,
        $vendor_id
    );
} else {
    $sql = "
        UPDATE offers
        SET hotel_id = ?,
            room_id = ?,
            title = ?,
            description = ?,
            offer_type = ?,
            discount_type = ?,
            discount_value = ?,
            start_date = ?,
            end_date = ?,
            min_nights = ?,
            min_rooms = ?,
            status = ?,
            is_featured = ?
        WHERE offer_id = ? AND vendor_id = ?
    ";

    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare offer update: ' . mysqli_error($con)
        ]);
        exit;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "iissssdssiisiii",
        $hotel_id,
        $room_id,
        $title,
        $description,
        $offer_type,
        $discount_type,
        $discount_value,
        $start_date,
        $end_date,
        $min_nights,
        $min_rooms,
        $status,
        $is_featured,
        $offer_id,
        $vendor_id
    );
}

if (!mysqli_stmt_execute($stmt)) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update offer: ' . mysqli_stmt_error($stmt)
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Offer updated successfully'
]);