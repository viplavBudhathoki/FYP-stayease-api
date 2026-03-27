<?php
try {
    include '../helpers/connection.php';
    include '../helpers/auth.php';

    header("Content-Type: application/json; charset=UTF-8");

    if (!isset($_POST['token'], $_POST['room_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Token and room_id are required'
        ]);
        exit;
    }

    $token = $_POST['token'];
    $room_id = (int) $_POST['room_id'];

    if (!isAdmin($token) && !isVendor($token)) {
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized'
        ]);
        exit;
    }

    $user_id = (int) getUserIdByToken($token);
    if (!$user_id) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid token'
        ]);
        exit;
    }

    if (isAdmin($token)) {
        $stmt = $con->prepare("
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
                r.created_at,
                h.name AS hotel_name,
                h.location AS hotel_location,
                u.full_name AS vendor_name
            FROM rooms r
            JOIN hotels h ON r.hotel_id = h.hotel_id
            LEFT JOIN users u ON r.vendor_id = u.user_id
            WHERE r.room_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $room_id);
    } else {
        $stmt = $con->prepare("
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
                r.created_at,
                h.name AS hotel_name,
                h.location AS hotel_location,
                u.full_name AS vendor_name
            FROM rooms r
            JOIN hotels h ON r.hotel_id = h.hotel_id
            LEFT JOIN users u ON r.vendor_id = u.user_id
            WHERE r.room_id = ?
              AND r.vendor_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $room_id, $user_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Room not found'
        ]);
        exit;
    }

    $room = $result->fetch_assoc();

    if (empty($room['image_url']) || !file_exists("../" . $room['image_url'])) {
        $room['image_url'] = "uploads/rooms/placeholder.png";
    }

    $room['price'] = (float) $room['price'];
    $room['capacity'] = (int) $room['capacity'];

    echo json_encode([
        'success' => true,
        'message' => 'Room fetched successfully',
        'data' => $room
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}