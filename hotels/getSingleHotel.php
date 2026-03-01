<?php
try {
    include '../helpers/connection.php';
    include '../helpers/auth.php';

    header("Content-Type: application/json; charset=UTF-8");

    if (!isset($_POST['token'], $_POST['hotel_id'])) {
        echo json_encode(['success'=>false,'message'=>'Token and hotel_id are required']);
        exit;
    }

    $token = $_POST['token'];
    $hotel_id = (int)$_POST['hotel_id'];

    // Allow admin OR vendor (optional design)
    // If want ONLY admin, keep isAdmin only.
    if (!isAdmin($token) && !isVendor($token)) {
        echo json_encode(['success'=>false,'message'=>'Unauthorized']);
        exit;
    }

    // Prepared statement (safe)
    $stmt = $con->prepare("
        SELECT h.hotel_id, h.name, h.location, h.description, h.image_url, h.vendor_id,
               u.full_name AS vendor_name
        FROM hotels h
        JOIN users u ON h.vendor_id = u.user_id
        WHERE h.hotel_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $hotel_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success'=>false,'message'=>'Hotel not found']);
        exit;
    }

    $hotel = $result->fetch_assoc();

    // Ensure relative placeholder if missing
    if (empty($hotel['image_url']) || !file_exists("../" . $hotel['image_url'])) {
        $hotel['image_url'] = "uploads/hotels/placeholder.png";
    }

    echo json_encode([
        'success'=>true,
        'message'=>'Hotel fetched successfully',
        'data'=>$hotel
    ]);

} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}