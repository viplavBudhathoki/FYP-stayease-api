<?php
try {
    include '../helpers/connection.php';
    include '../helpers/auth.php';

    header("Content-Type: application/json");

    if (!isset($_POST['token'])) {
        echo json_encode(['success'=>false,'message'=>'Token required']);
        exit;
    }

    $token = $_POST['token'];

    if (!isAdmin($token)) {
        echo json_encode(['success'=>false,'message'=>'Unauthorized']);
        exit;
    }

    $sql = "SELECT h.hotel_id, h.name, h.location, h.description, h.image_url,
                   h.vendor_id,
                   v.full_name AS vendor_name
            FROM hotels h
            LEFT JOIN users v ON h.vendor_id = v.user_id
            ORDER BY h.hotel_id DESC";

    $result = mysqli_query($con, $sql);
    if (!$result) {
        echo json_encode(['success'=>false,'message'=>'Database error']);
        exit;
    }

    $hotels = [];
    while ($row = mysqli_fetch_assoc($result)) {
        if (empty($row['image_url']) || !file_exists("../" . $row['image_url'])) {
            $row['image_url'] = "uploads/hotels/placeholder.png";
        }

        $hotels[] = $row;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Hotels fetched successfully',
        'data' => $hotels
    ]);

} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}