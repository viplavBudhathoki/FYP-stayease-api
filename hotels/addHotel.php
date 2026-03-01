<?php
try {
    include '../helpers/connection.php';
    include '../helpers/auth.php';

    header("Content-Type: application/json");

    if (!isset($_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Token is required']);
        exit;
    }
    $token = $_POST['token'];

    if (!isAdmin($token)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized user']);
        exit;
    }

    if (!isset($_POST['name'], $_POST['location'], $_POST['description'], $_POST['vendor_id'], $_FILES['image'])) {
        echo json_encode(['success' => false, 'message' => 'name, location, description, vendor_id, and image are required']);
        exit;
    }

    $name = $_POST['name'];
    $location = $_POST['location'];
    $description = $_POST['description'];
    $vendor_id = (int)$_POST['vendor_id'];

    $image = $_FILES['image'];
    $image_tmp_name = $image['tmp_name'];
    $image_name = $image['name'];
    $image_size = $image['size'];

    $image_ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
    $allowed_ext = ['jpg','jpeg','png','gif','webp'];

    if (!in_array($image_ext, $allowed_ext)) {
        echo json_encode(['success' => false, 'message' => 'Invalid image extension']);
        exit;
    }

    if ($image_size > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Image must be less than 5MB']);
        exit;
    }

    $new_name = uniqid('hotel_') . '.' . $image_ext;

    $uploadDir = '../uploads/hotels/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

    $image_path = 'uploads/hotels/' . $new_name;

    if (!move_uploaded_file($image_tmp_name, '../' . $image_path)) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
        exit;
    }

    $stmt = $con->prepare("INSERT INTO hotels (name, location, description, vendor_id, image_url) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssis", $name, $location, $description, $vendor_id, $image_path);

    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Failed to add hotel']);
        exit;
    }

    $hotel_id = $stmt->insert_id;

    echo json_encode([
        'success' => true,
        'message' => 'Hotel added successfully',
        'data' => [
            'hotel_id' => $hotel_id,
            'image_url' => $image_path, 
            'name' => $name,
            'location' => $location,
            'description' => $description,
            'vendor_id' => $vendor_id
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}