<?php

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_POST['token'], $_POST['booking_id'], $_POST['check_out'])) {
    echo json_encode([
        "success" => false,
        "message" => "Required fields missing"
    ]);
    exit;
}

$token = trim($_POST['token']);
$user_id = getUserIdByToken($token);

if (!$user_id) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

$booking_id = (int) $_POST['booking_id'];
$new_check_out = trim($_POST['check_out']);

if ($booking_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid booking ID"
    ]);
    exit;
}

$newCheckOutDate = DateTime::createFromFormat('Y-m-d', $new_check_out);
if (!$newCheckOutDate || $newCheckOutDate->format('Y-m-d') !== $new_check_out) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid check-out date"
    ]);
    exit;
}

/**
 * Load booking settings
 */
$sqlSettings = "
    SELECT
        min_booking_nights,
        max_booking_nights
    FROM system_settings
    ORDER BY setting_id ASC
    LIMIT 1
";

$resultSettings = mysqli_query($con, $sqlSettings);
$settings = $resultSettings ? mysqli_fetch_assoc($resultSettings) : [];

$min_booking_nights = max(1, (int) ($settings['min_booking_nights'] ?? 1));
$max_booking_nights = max($min_booking_nights, (int) ($settings['max_booking_nights'] ?? 30));

/**
 * Load booking and make sure it belongs to the logged-in customer
 */
$sqlBooking = "
    SELECT
        booking_id,
        user_id,
        check_in,
        check_out,
        total_price,
        status,
        adults,
        children,
        rooms_requested
    FROM bookings
    WHERE booking_id = ?
      AND user_id = ?
    LIMIT 1
";

$stmtBooking = mysqli_prepare($con, $sqlBooking);

if (!$stmtBooking) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare booking query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtBooking, "ii", $booking_id, $user_id);
mysqli_stmt_execute($stmtBooking);
$resultBooking = mysqli_stmt_get_result($stmtBooking);

if (!$resultBooking || mysqli_num_rows($resultBooking) === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Booking not found"
    ]);
    exit;
}

$booking = mysqli_fetch_assoc($resultBooking);
$current_status = strtolower(trim($booking['status'] ?? ''));

if (!in_array($current_status, ['confirmed', 'checked_in'], true)) {
    echo json_encode([
        "success" => false,
        "message" => "Only confirmed or checked-in bookings can be updated"
    ]);
    exit;
}

$check_in = trim($booking['check_in']);
$old_check_out = trim($booking['check_out']);

$checkInDate = DateTime::createFromFormat('Y-m-d', $check_in);
if (!$checkInDate || $checkInDate->format('Y-m-d') !== $check_in) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid existing check-in date"
    ]);
    exit;
}

if ($new_check_out <= $check_in) {
    echo json_encode([
        "success" => false,
        "message" => "Check-out must be after check-in"
    ]);
    exit;
}

$today = new DateTime(date('Y-m-d'));

/**
 * Optional business rule:
 * Do not allow modifying a completed/cancelled booking.
 * Already enforced above.
 *
 * For confirmed bookings:
 * - allow shortening or extending as long as rules + availability pass
 *
 * For checked_in bookings:
 * - allow changing only if the new check-out is today or later than current date
 */
if ($current_status === 'checked_in' && $newCheckOutDate < $today) {
    echo json_encode([
        "success" => false,
        "message" => "Checked-in booking cannot be updated to a past check-out date"
    ]);
    exit;
}

$nights = (int) $checkInDate->diff($newCheckOutDate)->days;

if ($nights < $min_booking_nights) {
    echo json_encode([
        "success" => false,
        "message" => "Minimum booking is {$min_booking_nights} night(s)"
    ]);
    exit;
}

if ($nights > $max_booking_nights) {
    echo json_encode([
        "success" => false,
        "message" => "Maximum booking is {$max_booking_nights} night(s)"
    ]);
    exit;
}

/**
 * Load all booked room items for this booking.
 * We update the booking total based on the saved booking_room rows.
 */
$sqlBookedRooms = "
    SELECT
        br.booking_room_id,
        br.room_id,
        br.original_price_per_night,
        br.discounted_price_per_night,
        br.price_per_night,
        br.offer_id,
        r.name AS room_name,
        r.total_rooms,
        r.status AS room_status
    FROM booking_rooms br
    INNER JOIN rooms r ON r.room_id = br.room_id
    WHERE br.booking_id = ?
    ORDER BY br.booking_room_id ASC
";

$stmtBookedRooms = mysqli_prepare($con, $sqlBookedRooms);

if (!$stmtBookedRooms) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare booked rooms query"
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtBookedRooms, "i", $booking_id);
mysqli_stmt_execute($stmtBookedRooms);
$resultBookedRooms = mysqli_stmt_get_result($stmtBookedRooms);

if (!$resultBookedRooms || mysqli_num_rows($resultBookedRooms) === 0) {
    echo json_encode([
        "success" => false,
        "message" => "No booked rooms found for this booking"
    ]);
    exit;
}

$bookingRoomItems = [];
$requestedByRoomId = [];

while ($room = mysqli_fetch_assoc($resultBookedRooms)) {
    $room_id = (int) $room['room_id'];

    $room['booking_room_id'] = (int) $room['booking_room_id'];
    $room['room_id'] = $room_id;
    $room['original_price_per_night'] = $room['original_price_per_night'] !== null
        ? (float) $room['original_price_per_night']
        : 0.0;
    $room['discounted_price_per_night'] = $room['discounted_price_per_night'] !== null
        ? (float) $room['discounted_price_per_night']
        : 0.0;
    $room['price_per_night'] = $room['price_per_night'] !== null
        ? (float) $room['price_per_night']
        : 0.0;
    $room['offer_id'] = $room['offer_id'] !== null ? (int) $room['offer_id'] : null;
    $room['total_rooms'] = max(1, (int) ($room['total_rooms'] ?? 1));

    if (!isset($requestedByRoomId[$room_id])) {
        $requestedByRoomId[$room_id] = 0;
    }
    $requestedByRoomId[$room_id]++;

    $bookingRoomItems[] = $room;
}

/**
 * Re-check overlap availability for the new date range.
 * Exclude the current booking itself.
 */
mysqli_begin_transaction($con);

try {
    $sqlOverlap = "
        SELECT COUNT(*) AS booked_count
        FROM booking_rooms br
        INNER JOIN bookings b ON b.booking_id = br.booking_id
        WHERE br.room_id = ?
          AND b.booking_id <> ?
          AND b.status IN ('confirmed', 'checked_in')
          AND (? < b.check_out)
          AND (? > b.check_in)
    ";

    $stmtOverlap = mysqli_prepare($con, $sqlOverlap);

    if (!$stmtOverlap) {
        throw new Exception("Failed to prepare overlap query");
    }

    foreach ($requestedByRoomId as $room_id => $requestedQty) {
        /**
         * We need total_rooms and room_name from one matching booked item
         */
        $matchedRoom = null;
        foreach ($bookingRoomItems as $item) {
            if ((int) $item['room_id'] === (int) $room_id) {
                $matchedRoom = $item;
                break;
            }
        }

        if (!$matchedRoom) {
            throw new Exception("Booked room item not found");
        }

        if (strtolower(trim($matchedRoom['room_status'] ?? '')) === 'maintenance') {
            throw new Exception("{$matchedRoom['room_name']} is under maintenance");
        }

        $totalRooms = (int) $matchedRoom['total_rooms'];

        mysqli_stmt_bind_param(
            $stmtOverlap,
            "iiss",
            $room_id,
            $booking_id,
            $check_in,
            $new_check_out
        );
        mysqli_stmt_execute($stmtOverlap);
        $resultOverlap = mysqli_stmt_get_result($stmtOverlap);

        $bookedCount = 0;
        if ($resultOverlap) {
            $overlapRow = mysqli_fetch_assoc($resultOverlap);
            $bookedCount = (int) ($overlapRow['booked_count'] ?? 0);
        }

        $availableCount = max(0, $totalRooms - $bookedCount);

        if ($requestedQty > $availableCount) {
            throw new Exception("Only {$availableCount} room(s) available for {$matchedRoom['room_name']} in the selected dates");
        }
    }

    /**
     * Recalculate total price from saved price_per_night of each booking_room row.
     * This matches your current design because booked prices are preserved at booking time.
     */
    $new_total_price = 0.0;
    $new_original_total_price = 0.0;
    $new_total_savings = 0.0;

    foreach ($bookingRoomItems as $item) {
        $effectivePricePerNight = (float) $item['price_per_night'];
        $originalPricePerNight = (float) $item['original_price_per_night'];

        $lineFinal = round($effectivePricePerNight * $nights, 2);
        $lineOriginal = round($originalPricePerNight * $nights, 2);

        $new_total_price += $lineFinal;
        $new_original_total_price += $lineOriginal;
    }

    $new_total_price = round($new_total_price, 2);
    $new_original_total_price = round($new_original_total_price, 2);
    $new_total_savings = max(0, round($new_original_total_price - $new_total_price, 2));

    $sqlUpdateBooking = "
        UPDATE bookings
        SET check_out = ?, total_price = ?
        WHERE booking_id = ?
          AND user_id = ?
    ";

    $stmtUpdateBooking = mysqli_prepare($con, $sqlUpdateBooking);

    if (!$stmtUpdateBooking) {
        throw new Exception("Failed to prepare booking update");
    }

    mysqli_stmt_bind_param(
        $stmtUpdateBooking,
        "sdii",
        $new_check_out,
        $new_total_price,
        $booking_id,
        $user_id
    );

    if (!mysqli_stmt_execute($stmtUpdateBooking)) {
        throw new Exception("Failed to update booking");
    }

    mysqli_commit($con);

    echo json_encode([
        "success" => true,
        "message" => "Booking updated successfully",
        "booking_id" => $booking_id,
        "check_in" => $check_in,
        "old_check_out" => $old_check_out,
        "new_check_out" => $new_check_out,
        "nights" => $nights,
        "total_price" => $new_total_price,
        "original_total_price" => $new_original_total_price,
        "total_savings" => $new_total_savings
    ]);
} catch (Exception $e) {
    mysqli_rollback($con);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}