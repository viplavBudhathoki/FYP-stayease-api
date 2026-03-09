<?php
include __DIR__ . '/../helpers/connection.php';

header("Content-Type: application/json; charset=UTF-8");

$sql = "SELECT hotel_id, name, location, description, image_url, status, created_at
        FROM hotels
        WHERE status = 'active'
        ORDER BY hotel_id DESC";

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
    if (empty($row['image_url']) || !file_exists(__DIR__ . '/../' . $row['image_url'])) {
        $row['image_url'] = 'uploads/hotels/placeholder.png';
    }
    $hotels[] = $row;
}

echo json_encode([
    'success' => true,
    'data' => $hotels
]);