<?php
try {
    include __DIR__ . '/../helpers/connection.php';
    include __DIR__ . '/../helpers/auth.php';

    header("Content-Type: application/json; charset=UTF-8");

    if (!isset($_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Token is required']);
        exit;
    }

    $token = $_POST['token'];

    if (!isVendor($token)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized vendor']);
        exit;
    }

    $vendor_id = (int) getUserIdByToken($token);
    if (!$vendor_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }

    if (!isset($_POST['hotel_id'], $_POST['name'], $_POST['price'], $_POST['type'], $_POST['status']) || !isset($_FILES['image'])) {
        echo json_encode(['success' => false, 'message' => 'hotel_id, name, price, type, status and image are required']);
        exit;
    }

    $hotel_id = (int)$_POST['hotel_id'];
    $name = mysqli_real_escape_string($con, $_POST['name']);
    $price = (float)$_POST['price'];
    $type = mysqli_real_escape_string($con, $_POST['type']);
    $status = strtolower(mysqli_real_escape_string($con, $_POST['status']));
    $capacity = isset($_POST['capacity']) ? (int)$_POST['capacity'] : 1;
    $description = isset($_POST['description']) ? mysqli_real_escape_string($con, $_POST['description']) : null;

    // amenities: JSON array -> "WiFi, AC"
    $amenities_safe = null;
    if (isset($_POST['amenities'])) {
        $arr = json_decode($_POST['amenities'], true);
        if (is_array($arr)) {
            $amenities_safe = mysqli_real_escape_string($con, implode(", ", $arr));
        }
    }

    // hotel ownership check
    $hotel_check_sql = "SELECT hotel_id FROM hotels WHERE hotel_id='$hotel_id' AND vendor_id='$vendor_id'";
    $hotel_check_res = mysqli_query($con, $hotel_check_sql);
    if (!$hotel_check_res || mysqli_num_rows($hotel_check_res) === 0) {
        echo json_encode(['success' => false, 'message' => 'You do not own this hotel']);
        exit;
    }

    // image upload
    $image = $_FILES['image'];
    $image_size = $image['size'];
    $image_tmp_name = $image['tmp_name'];
    $image_name = $image['name'];

    $image_ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($image_ext, $allowed_ext)) {
        echo json_encode(['success' => false, 'message' => 'Invalid image type (jpg, jpeg, png, webp only)']);
        exit;
    }

    if ($image_size > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Image size should be less than 5MB']);
        exit;
    }

    $new_image_name = uniqid('room_') . '.' . $image_ext;

    $upload_dir = __DIR__ . '/../uploads/rooms/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $server_path = $upload_dir . $new_image_name;
    $db_path = 'uploads/rooms/' . $new_image_name;

    if (!move_uploaded_file($image_tmp_name, $server_path)) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
        exit;
    }

    $sql = "INSERT INTO rooms (hotel_id, vendor_id, name, type, status, price, capacity, description, amenities, image_url)
            VALUES (
                '$hotel_id',
                '$vendor_id',
                '$name',
                '$type',
                '$status',
                '$price',
                '$capacity',
                " . ($description ? "'$description'" : "NULL") . ",
                " . ($amenities_safe ? "'$amenities_safe'" : "NULL") . ",
                '$db_path'
            )";

    $result = mysqli_query($con, $sql);
    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'DB ERROR: ' . mysqli_error($con)]);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Room added successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}