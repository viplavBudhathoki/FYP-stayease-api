<?php
try {
    include '../helpers/connection.php';
    include '../helpers/auth.php';

    header("Content-Type: application/json");

    // Token check
    if (!isset($_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Token required']);
        die();
    }

    $token = $_POST['token'];

    // Only admins can delete hotels
    if (!isAdmin($token)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        die();
    }

    if (!isset($_POST['hotel_id'])) {
        echo json_encode(['success' => false, 'message' => 'Hotel ID required']);
        die();
    }

    $hotel_id = intval($_POST['hotel_id']);

    // Delete hotel
    $stmt = mysqli_prepare($con, "DELETE FROM hotels WHERE hotel_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $hotel_id);
    $exec = mysqli_stmt_execute($stmt);

    if ($exec) {
        echo json_encode(['success' => true, 'message' => 'Hotel deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete hotel']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
