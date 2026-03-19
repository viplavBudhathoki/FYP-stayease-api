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

    if (!isset($_POST['hotel_id'])) {
        echo json_encode(['success' => false, 'message' => 'Hotel ID required']);
        exit;
    }

    $hotel_id = (int) $_POST['hotel_id'];

    $hotelStmt = $con->prepare("SELECT image_url FROM hotels WHERE hotel_id = ?");
    $hotelStmt->bind_param("i", $hotel_id);
    $hotelStmt->execute();
    $hotelResult = $hotelStmt->get_result();

    if ($hotelResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Hotel not found']);
        exit;
    }

    $hotel = $hotelResult->fetch_assoc();
    $mainImage = $hotel['image_url'] ?? '';

    mysqli_begin_transaction($con);

    try {
        $roomIds = [];
        $roomMainImages = [];

        $roomRes = mysqli_query($con, "SELECT room_id, image_url FROM rooms WHERE hotel_id = '$hotel_id'");

        if ($roomRes) {
            while ($row = mysqli_fetch_assoc($roomRes)) {
                $roomIds[] = (int) $row['room_id'];

                if (!empty($row['image_url'])) {
                    $roomMainImages[] = $row['image_url'];
                }
            }
        }

        if (!empty($roomIds)) {
            $roomIdsStr = implode(',', $roomIds);

            $roomImgRes = mysqli_query($con, "SELECT image_url FROM room_images WHERE room_id IN ($roomIdsStr)");
            if ($roomImgRes) {
                while ($img = mysqli_fetch_assoc($roomImgRes)) {
                    if (!empty($img['image_url']) && file_exists(__DIR__ . '/../' . $img['image_url'])) {
                        @unlink(__DIR__ . '/../' . $img['image_url']);
                    }
                }
            }

            mysqli_query($con, "DELETE FROM room_images WHERE room_id IN ($roomIdsStr)");
            mysqli_query($con, "DELETE FROM ratings WHERE room_id IN ($roomIdsStr) OR hotel_id = '$hotel_id'");
            mysqli_query($con, "DELETE FROM bookings WHERE room_id IN ($roomIdsStr)");
        }

        foreach ($roomMainImages as $img) {
            if (
                !empty($img) &&
                $img !== 'uploads/rooms/placeholder.png' &&
                file_exists(__DIR__ . '/../' . $img)
            ) {
                @unlink(__DIR__ . '/../' . $img);
            }
        }

        mysqli_query($con, "DELETE FROM rooms WHERE hotel_id = '$hotel_id'");

        $galleryRes = mysqli_query($con, "SELECT image_url FROM hotel_images WHERE hotel_id = '$hotel_id'");
        if ($galleryRes) {
            while ($img = mysqli_fetch_assoc($galleryRes)) {
                if (!empty($img['image_url']) && file_exists(__DIR__ . '/../' . $img['image_url'])) {
                    @unlink(__DIR__ . '/../' . $img['image_url']);
                }
            }
        }

        mysqli_query($con, "DELETE FROM hotel_images WHERE hotel_id = '$hotel_id'");

        if (
            !empty($mainImage) &&
            $mainImage !== 'uploads/hotels/placeholder.png' &&
            file_exists(__DIR__ . '/../' . $mainImage)
        ) {
            @unlink(__DIR__ . '/../' . $mainImage);
        }

        $stmt = $con->prepare("DELETE FROM hotels WHERE hotel_id = ?");
        $stmt->bind_param("i", $hotel_id);

        if (!$stmt->execute()) {
            throw new Exception('Failed to delete hotel');
        }

        mysqli_commit($con);

        echo json_encode([
            'success' => true,
            'message' => 'Hotel deleted successfully'
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