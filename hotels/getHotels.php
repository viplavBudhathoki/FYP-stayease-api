<?php
try {
    include __DIR__ . '/../helpers/connection.php';
    include __DIR__ . '/../helpers/auth.php';

    header("Content-Type: application/json; charset=UTF-8");

    if (!isset($_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Token required']);
        exit;
    }

    $token = $_POST['token'];

    if (!isAdmin($token)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $sql = "
        SELECT 
            h.hotel_id,
            h.name,
            h.location,
            h.description,
            h.image_url,
            h.vendor_id,
            v.full_name AS vendor_name,
            COUNT(hi.image_id) AS gallery_count
        FROM hotels h
        LEFT JOIN users v ON h.vendor_id = v.user_id
        LEFT JOIN hotel_images hi ON h.hotel_id = hi.hotel_id
        GROUP BY 
            h.hotel_id, h.name, h.location, h.description, h.image_url, h.vendor_id, v.full_name
        ORDER BY h.hotel_id DESC
    ";

    $result = mysqli_query($con, $sql);

    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }

    $hotels = [];

    while ($row = mysqli_fetch_assoc($result)) {
        if (empty($row['image_url']) || !file_exists(__DIR__ . '/../' . $row['image_url'])) {
            $row['image_url'] = "uploads/hotels/placeholder.png";
        }

        $row['gallery_count'] = (int) ($row['gallery_count'] ?? 0);
        $hotels[] = $row;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Hotels fetched successfully',
        'data' => $hotels
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}