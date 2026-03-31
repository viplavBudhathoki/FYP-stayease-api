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

    if (
        !isset($_POST['hotel_id'], $_POST['name'], $_POST['price'], $_POST['type'], $_POST['status']) ||
        !isset($_FILES['image'])
    ) {
        echo json_encode([
            'success' => false,
            'message' => 'hotel_id, name, price, type, status and image are required'
        ]);
        exit;
    }

    $hotel_id = (int) $_POST['hotel_id'];
    $name = trim($_POST['name']);
    $type = trim($_POST['type']);
    $status = strtolower(trim($_POST['status']));
    $price = (float) $_POST['price'];
    $capacity = isset($_POST['capacity']) ? (int) $_POST['capacity'] : 1;
    $total_rooms = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 1;
    $description = isset($_POST['description']) ? trim($_POST['description']) : null;

    if ($hotel_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid hotel']);
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

    $checkHotelSql = "SELECT hotel_id FROM hotels WHERE hotel_id = ? AND vendor_id = ? LIMIT 1";
    $checkHotelStmt = mysqli_prepare($con, $checkHotelSql);

    if (!$checkHotelStmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to verify hotel']);
        exit;
    }

    mysqli_stmt_bind_param($checkHotelStmt, "ii", $hotel_id, $vendor_id);
    mysqli_stmt_execute($checkHotelStmt);
    $checkHotelRes = mysqli_stmt_get_result($checkHotelStmt);

    if (!$checkHotelRes || mysqli_num_rows($checkHotelRes) === 0) {
        echo json_encode(['success' => false, 'message' => 'You do not own this hotel']);
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
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_exts, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid image']);
        exit;
    }

    if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Image must be less than 5MB']);
        exit;
    }

    $new_name = uniqid('room_', true) . '.' . $ext;
    $server_path = $upload_dir . $new_name;
    $db_path = 'uploads/rooms/' . $new_name;

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $server_path)) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
        exit;
    }

    $sql = "
        INSERT INTO rooms (
            hotel_id,
            vendor_id,
            name,
            total_rooms,
            type,
            status,
            price,
            capacity,
            description,
            amenities,
            image_url
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to prepare insert']);
        exit;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "iisissdisss",
        $hotel_id,
        $vendor_id,
        $name,
        $total_rooms,
        $type,
        $status,
        $price,
        $capacity,
        $description,
        $amenities_csv,
        $db_path
    );

    if (!mysqli_stmt_execute($stmt)) {
        if (file_exists($server_path)) {
            @unlink($server_path);
        }
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save room type: ' . mysqli_error($con)
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Room type added successfully'
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}