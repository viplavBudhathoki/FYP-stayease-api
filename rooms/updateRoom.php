<?php
try {
    include __DIR__ . '/../helpers/connection.php';
    include __DIR__ . '/../helpers/auth.php';

    header("Content-Type: application/json; charset=UTF-8");

    if (!isset($_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Token is required']);
        exit;
    }

    $token = trim($_POST['token']);

    if (!isVendor($token)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized vendor']);
        exit;
    }

    $vendor_id = (int) getUserIdByToken($token);
    if (!$vendor_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }

    if (!isset($_POST['room_id'], $_POST['name'], $_POST['price'], $_POST['type'], $_POST['status'])) {
        echo json_encode([
            'success' => false,
            'message' => 'room_id, name, price, type, status are required'
        ]);
        exit;
    }

    $room_id = (int) $_POST['room_id'];
    $name = trim($_POST['name']);
    $type = trim($_POST['type']);
    $status = strtolower(trim($_POST['status']));
    $price = (float) $_POST['price'];
    $capacity = isset($_POST['capacity']) ? (int) $_POST['capacity'] : 1;
    $total_rooms = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 1;
    $description = isset($_POST['description']) ? trim($_POST['description']) : null;

    if ($room_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid room id']);
        exit;
    }

    if ($name === '' || $type === '') {
        echo json_encode(['success' => false, 'message' => 'Name and type are required']);
        exit;
    }

    if ($price < 0) {
        echo json_encode(['success' => false, 'message' => 'Price must be a valid positive number']);
        exit;
    }

    if ($capacity < 1) {
        echo json_encode(['success' => false, 'message' => 'Capacity must be at least 1']);
        exit;
    }

    if ($total_rooms < 1) {
        echo json_encode(['success' => false, 'message' => 'Total rooms must be at least 1']);
        exit;
    }

    $allowed_statuses = ['available', 'maintenance'];
    if (!in_array($status, $allowed_statuses, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }

    $checkSql = "
        SELECT room_id, hotel_id, image_url
        FROM rooms
        WHERE room_id = ? AND vendor_id = ?
        LIMIT 1
    ";
    $checkStmt = mysqli_prepare($con, $checkSql);

    if (!$checkStmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to prepare ownership check']);
        exit;
    }

    mysqli_stmt_bind_param($checkStmt, "ii", $room_id, $vendor_id);
    mysqli_stmt_execute($checkStmt);
    $checkRes = mysqli_stmt_get_result($checkStmt);

    if (!$checkRes || mysqli_num_rows($checkRes) === 0) {
        echo json_encode(['success' => false, 'message' => 'You do not own this room type']);
        exit;
    }

    $existingRoom = mysqli_fetch_assoc($checkRes);
    $old_image = $existingRoom['image_url'] ?? '';

    $activeBookedSql = "
        SELECT COUNT(*) AS active_booked
        FROM booking_rooms br
        INNER JOIN bookings b ON b.booking_id = br.booking_id
        WHERE br.room_id = ?
          AND b.status IN ('confirmed', 'checked_in')
    ";
    $activeBookedStmt = mysqli_prepare($con, $activeBookedSql);

    if (!$activeBookedStmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to validate active bookings']);
        exit;
    }

    mysqli_stmt_bind_param($activeBookedStmt, "i", $room_id);
    mysqli_stmt_execute($activeBookedStmt);
    $activeBookedRes = mysqli_stmt_get_result($activeBookedStmt);
    $activeBookedRow = $activeBookedRes ? mysqli_fetch_assoc($activeBookedRes) : null;
    $activeBookedCount = (int) ($activeBookedRow['active_booked'] ?? 0);

    if ($total_rooms < $activeBookedCount) {
        echo json_encode([
            'success' => false,
            'message' => "Total rooms cannot be reduced below {$activeBookedCount} because of active bookings"
        ]);
        exit;
    }

    $amenities_csv = null;
    if (isset($_POST['amenities']) && $_POST['amenities'] !== '') {
        $decoded = json_decode($_POST['amenities'], true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $cleanAmenities = [];
            foreach ($decoded as $item) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $cleanAmenities[] = $item;
                }
            }
            if (!empty($cleanAmenities)) {
                $amenities_csv = implode(", ", $cleanAmenities);
            }
        } else {
            $plainAmenities = trim((string) $_POST['amenities']);
            if ($plainAmenities !== '') {
                $amenities_csv = $plainAmenities;
            }
        }
    }

    $upload_dir = __DIR__ . '/../uploads/rooms/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
    $image_sql = "";
    $db_path = null;
    $new_uploaded_server_path = null;

    if (isset($_FILES['image']) && !empty($_FILES['image']['tmp_name'])) {
        $image = $_FILES['image'];
        $ext = strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_exts, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid image type']);
            exit;
        }

        if ($image['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Image must be less than 5MB']);
            exit;
        }

        $new_name = uniqid('room_', true) . '.' . $ext;
        $new_uploaded_server_path = $upload_dir . $new_name;
        $db_path = 'uploads/rooms/' . $new_name;

        if (!move_uploaded_file($image['tmp_name'], $new_uploaded_server_path)) {
            echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
            exit;
        }

        $image_sql = ", image_url = ?";
    }

    $sql = "
        UPDATE rooms SET
            name = ?,
            type = ?,
            status = ?,
            price = ?,
            capacity = ?,
            total_rooms = ?,
            description = ?,
            amenities = ?
            $image_sql
        WHERE room_id = ? AND vendor_id = ?
    ";

    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        if ($new_uploaded_server_path && file_exists($new_uploaded_server_path)) {
            @unlink($new_uploaded_server_path);
        }
        echo json_encode(['success' => false, 'message' => 'Failed to prepare update']);
        exit;
    }

    if ($image_sql !== "") {
        mysqli_stmt_bind_param(
            $stmt,
            "sssdiisssii",
            $name,
            $type,
            $status,
            $price,
            $capacity,
            $total_rooms,
            $description,
            $amenities_csv,
            $db_path,
            $room_id,
            $vendor_id
        );
    } else {
        mysqli_stmt_bind_param(
            $stmt,
            "sssdiissii",
            $name,
            $type,
            $status,
            $price,
            $capacity,
            $total_rooms,
            $description,
            $amenities_csv,
            $room_id,
            $vendor_id
        );
    }

    if (!mysqli_stmt_execute($stmt)) {
        if ($new_uploaded_server_path && file_exists($new_uploaded_server_path)) {
            @unlink($new_uploaded_server_path);
        }
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update room type: ' . mysqli_error($con)
        ]);
        exit;
    }

    if ($image_sql !== "" && !empty($old_image) && $old_image !== 'uploads/rooms/placeholder.png') {
        $old_image_path = __DIR__ . '/../' . $old_image;
        if (file_exists($old_image_path)) {
            @unlink($old_image_path);
        }
    }

    if (isset($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
        $existingGalleryCountRes = mysqli_query(
            $con,
            "SELECT COUNT(*) AS total FROM room_images WHERE room_id = '$room_id'"
        );
        $existingGalleryCountRow = mysqli_fetch_assoc($existingGalleryCountRes);
        $existingGalleryCount = (int) ($existingGalleryCountRow['total'] ?? 0);

        $galleryNames = $_FILES['gallery_images']['name'];
        $galleryTmpNames = $_FILES['gallery_images']['tmp_name'];
        $gallerySizes = $_FILES['gallery_images']['size'];
        $galleryErrors = $_FILES['gallery_images']['error'];

        $newGalleryCount = count($galleryNames);
        $maxGalleryImages = 4;

        if (($existingGalleryCount + $newGalleryCount) > $maxGalleryImages) {
            echo json_encode([
                'success' => false,
                'message' => 'Maximum 4 gallery images are allowed per room type'
            ]);
            exit;
        }

        for ($i = 0; $i < $newGalleryCount; $i++) {
            if ($galleryErrors[$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            $gExt = strtolower(pathinfo($galleryNames[$i], PATHINFO_EXTENSION));

            if (!in_array($gExt, $allowed_exts, true)) {
                continue;
            }

            if ($gallerySizes[$i] > 5 * 1024 * 1024) {
                continue;
            }

            $galleryFileName = uniqid('room_gallery_', true) . '.' . $gExt;
            $galleryServerPath = $upload_dir . $galleryFileName;
            $galleryDbPath = 'uploads/rooms/' . $galleryFileName;

            if (move_uploaded_file($galleryTmpNames[$i], $galleryServerPath)) {
                $galleryStmt = mysqli_prepare(
                    $con,
                    "INSERT INTO room_images (room_id, image_url) VALUES (?, ?)"
                );

                if ($galleryStmt) {
                    mysqli_stmt_bind_param($galleryStmt, "is", $room_id, $galleryDbPath);
                    mysqli_stmt_execute($galleryStmt);
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Room type updated successfully'
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}