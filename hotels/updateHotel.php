<?php
try {
    include '../helpers/connection.php';   // DB connection
    include '../helpers/auth.php';         // Auth functions (isAdmin)

    header("Content-Type: application/json");

    // Check token
    if (!isset($_POST['token'])) {
        echo json_encode(['success'=>false,'message'=>'Token is required']);
        die();
    }
    $token = $_POST['token'];

    // Check if admin
    if (!isAdmin($token)) {
        echo json_encode(['success'=>false,'message'=>'Unauthorized user']);
        die();
    }

    // Check hotel_id
    if (!isset($_POST['hotel_id'])) {
        echo json_encode(['success'=>false,'message'=>'Hotel ID is required']);
        die();
    }

    $hotel_id = intval($_POST['hotel_id']);
    $name = $_POST['name'] ?? '';
    $location = $_POST['location'] ?? '';
    $description = $_POST['description'] ?? '';
    $vendor_id = intval($_POST['vendor_id'] ?? 0); // ensure integer

    // Fetch current hotel
    $stmt = $con->prepare("SELECT image_url FROM hotels WHERE hotel_id=?");
    $stmt->bind_param("i", $hotel_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success'=>false,'message'=>'Hotel not found']);
        die();
    }

    $hotel = $result->fetch_assoc();
    $currentImage = $hotel['image_url']; // existing image path
    $image_url = $currentImage; // default: keep existing image

    // Handle new image upload (optional)
    if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
        $image = $_FILES['image'];
        $image_size = $image['size'];
        $image_tmp_name = $image['tmp_name'];
        $image_name = $image['name'];
        $image_ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
        $allowed_ext = ['jpg','jpeg','png','gif','webp'];

        if (!in_array($image_ext, $allowed_ext)) {
            echo json_encode(['success'=>false,'message'=>'Invalid image extension']);
            die();
        }

        if ($image_size > 1024*1024*5) {
            echo json_encode(['success'=>false,'message'=>'Image size must be <5MB']);
            die();
        }

        $newImageName = uniqid("hotel_") . '.' . $image_ext;
        $uploadDir = '../uploads/hotels/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

        $image_path = $uploadDir . $newImageName;

        if (!move_uploaded_file($image_tmp_name, $image_path)) {
            echo json_encode(['success'=>false,'message'=>'Failed to upload image']);
            die();
        }

        // Delete old image if exists
        if ($currentImage && file_exists("../".$currentImage)) {
            unlink("../".$currentImage);
        }

        $image_url = 'uploads/hotels/' . $newImageName;
    }

    // Update hotel in database
    $stmt = $con->prepare("UPDATE hotels SET name=?, location=?, description=?, vendor_id=?, image_url=? WHERE hotel_id=?");
    $stmt->bind_param("sssisi", $name, $location, $description, $vendor_id, $image_url, $hotel_id);
    $stmt->execute();

    if ($stmt->affected_rows >= 0) {
        echo json_encode([
            'success'=>true,
            'message'=>'Hotel updated successfully',
            'data'=>[
                'hotel_id'=>$hotel_id,
                'image_url'=>$image_url,
                'name'=>$name,
                'location'=>$location,
                'description'=>$description,
                'vendor_id'=>$vendor_id
            ]
        ]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Failed to update hotel']);
    }

} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
