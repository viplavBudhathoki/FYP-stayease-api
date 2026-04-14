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
$checkResult = mysqli_stmt_get_result($checkStmt);

if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'You do not own this hotel'
    ]);
    exit;
}

$sql = "
    SELECT
        offer_id,
        vendor_id,
        hotel_id,
        room_id,
        title,
        description,
        offer_type,
        discount_type,
        discount_value,
        start_date,
        end_date,
        min_nights,
        min_rooms,
        max_discount_amount,
        status,
        is_featured,
        created_at,
        updated_at
    FROM offers
    WHERE hotel_id = ? AND vendor_id = ?
    ORDER BY offer_id DESC
";

$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare offers query: ' . mysqli_error($con)
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, "ii", $hotel_id, $vendor_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch offers: ' . mysqli_error($con)
    ]);
    exit;
}

$data = [];

while ($row = mysqli_fetch_assoc($result)) {
    $row['offer_id'] = (int) $row['offer_id'];
    $row['vendor_id'] = (int) $row['vendor_id'];
    $row['hotel_id'] = (int) $row['hotel_id'];
    $row['room_id'] = $row['room_id'] !== null ? (int) $row['room_id'] : null;
    $row['discount_value'] = (float) $row['discount_value'];
    $row['min_nights'] = (int) $row['min_nights'];
    $row['min_rooms'] = (int) $row['min_rooms'];
    $row['max_discount_amount'] = $row['max_discount_amount'] !== null
        ? (float) $row['max_discount_amount']
        : null;
    $row['is_featured'] = (int) $row['is_featured'];

    $data[] = $row;
}

echo json_encode([
    'success' => true,
    'message' => 'Offers fetched successfully',
    'data' => $data
]);