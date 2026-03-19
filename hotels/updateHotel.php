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

    if (!isAdmin($token)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized user']);
        exit;
    }

    if (!isset($_POST['hotel_id'])) {
        echo json_encode(['success' => false, 'message' => 'Hotel ID is required']);
        exit;
    }

    $hotel_id = (int) $_POST['hotel_id'];
    $name = trim($_POST['name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $vendor_id = (int) ($_POST['vendor_id'] ?? 0);

    if ($name === '' || $location === '' || $description === '' || $vendor_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid hotel data']);
        exit;
    }

    $stmt = $con->prepare("SELECT image_url FROM hotels WHERE hotel_id = ?");
    $stmt->bind_param("i", $hotel_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Hotel not found']);
        exit;
    }

    $hotel = $result->fetch_assoc();
    $currentImage = $hotel['image_url'] ?? '';
    $image_url = $currentImage;

    if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
        $image = $_FILES['image'];
        $image_size = $image['size'];
        $image_tmp_name = $image['tmp_name'];
        $image_name = $image['name'];
        $image_ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($image_ext, $allowed_ext)) {
            echo json_encode(['success' => false, 'message' => 'Invalid image extension']);
            exit;
        }

        if ($image_size > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Image size must be less than 5MB']);
            exit;
        }

        $newImageName = uniqid("hotel_") . '.' . $image_ext;
        $uploadDir = __DIR__ . '/../uploads/hotels/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $dbPath = 'uploads/hotels/' . $newImageName;
        $fullPath = __DIR__ . '/../' . $dbPath;

        if (!move_uploaded_file($image_tmp_name, $fullPath)) {
            echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
            exit;
        }

        if (
            !empty($currentImage) &&
            $currentImage !== 'uploads/hotels/placeholder.png' &&
            file_exists(__DIR__ . '/../' . $currentImage)
        ) {
            @unlink(__DIR__ . '/../' . $currentImage);
        }

        $image_url = $dbPath;
    }

    mysqli_begin_transaction($con);

    try {
        $stmt = $con->prepare("UPDATE hotels SET name = ?, location = ?, description = ?, vendor_id = ?, image_url = ? WHERE hotel_id = ?");
        $stmt->bind_param("sssisi", $name, $location, $description, $vendor_id, $image_url, $hotel_id);

        if (!$stmt->execute()) {
            throw new Exception('Failed to update hotel');
        }

        if (isset($_FILES['gallery']) && is_array($_FILES['gallery']['name'])) {
            $countRes = mysqli_query($con, "SELECT COUNT(*) AS total FROM hotel_images WHERE hotel_id = '$hotel_id'");
            $countRow = mysqli_fetch_assoc($countRes);
            $existingCount = (int) ($countRow['total'] ?? 0);

            $gallery = $_FILES['gallery'];
            $incomingCount = count($gallery['name']);
            $allowedRemaining = max(0, 4 - $existingCount);
            $totalGallery = min($incomingCount, $allowedRemaining);

            for ($i = 0; $i < $totalGallery; $i++) {
                if (!isset($gallery['error'][$i]) || $gallery['error'][$i] !== 0) {
                    continue;
                }

                $galleryName = $gallery['name'][$i];
                $galleryTmp = $gallery['tmp_name'][$i];
                $gallerySize = $gallery['size'][$i];

                $galleryExt = strtolower(pathinfo($galleryName, PATHINFO_EXTENSION));
                $allowedGallery = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                if (!in_array($galleryExt, $allowedGallery)) {
                    continue;
                }

                if ($gallerySize > 5 * 1024 * 1024) {
                    continue;
                }

                $newGalleryName = uniqid('hotel_gallery_') . '.' . $galleryExt;
                $galleryPath = 'uploads/hotels/' . $newGalleryName;
                $fullGalleryPath = __DIR__ . '/../' . $galleryPath;

                if (move_uploaded_file($galleryTmp, $fullGalleryPath)) {
                    $galleryStmt = $con->prepare("INSERT INTO hotel_images (hotel_id, image_url) VALUES (?, ?)");
                    $galleryStmt->bind_param("is", $hotel_id, $galleryPath);
                    $galleryStmt->execute();
                }
            }
        }

        mysqli_commit($con);

        echo json_encode([
            'success' => true,
            'message' => 'Hotel updated successfully',
            'data' => [
                'hotel_id' => $hotel_id,
                'image_url' => $image_url,
                'name' => $name,
                'location' => $location,
                'description' => $description,
                'vendor_id' => $vendor_id
            ]
        ]);
    } catch (Exception $e) {
        mysqli_rollback($con);
        throw $e;
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}