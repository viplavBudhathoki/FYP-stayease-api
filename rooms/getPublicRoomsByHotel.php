<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/offer_helper.php';

header("Content-Type: application/json; charset=UTF-8");

$hotel_id = isset($_POST['hotel_id']) ? (int) $_POST['hotel_id'] : 0;
$check_in = isset($_POST['check_in']) ? trim($_POST['check_in']) : '';
$check_out = isset($_POST['check_out']) ? trim($_POST['check_out']) : '';
$rooms_requested = isset($_POST['rooms_requested']) ? max(1, (int) $_POST['rooms_requested']) : 1;

if ($hotel_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'hotel_id is required'
    ]);
    exit;
}

$checkHotelStmt = mysqli_prepare($con, "
    SELECT hotel_id, name, location, status
    FROM hotels
    WHERE hotel_id = ?
      AND status = 'active'
    LIMIT 1
");

if (!$checkHotelStmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to verify hotel'
    ]);
    exit;
}

mysqli_stmt_bind_param($checkHotelStmt, "i", $hotel_id);
mysqli_stmt_execute($checkHotelStmt);
$checkHotel = mysqli_stmt_get_result($checkHotelStmt);

if (!$checkHotel || mysqli_num_rows($checkHotel) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Hotel not found'
    ]);
    exit;
}

$hotelInfo = mysqli_fetch_assoc($checkHotel);

$useDateFilter = false;
$nights = 1;

if ($check_in !== '' && $check_out !== '') {
    $checkInDate = DateTime::createFromFormat('Y-m-d', $check_in);
    $checkOutDate = DateTime::createFromFormat('Y-m-d', $check_out);

    if (
        $checkInDate &&
        $checkInDate->format('Y-m-d') === $check_in &&
        $checkOutDate &&
        $checkOutDate->format('Y-m-d') === $check_out &&
        $check_out > $check_in
    ) {
        $useDateFilter = true;
        $diff = $checkInDate->diff($checkOutDate);
        $nights = max(1, (int) $diff->days);
    }
}

$sql = "
    SELECT
        r.room_id,
        r.hotel_id,
        r.vendor_id,
        r.name,
        r.total_rooms,
        r.type,
        r.status,
        r.price,
        r.capacity,
        r.description,
        r.amenities,
        r.image_url,
        r.created_at
";

$params = [];
$types = "";

if ($useDateFilter) {
    $sql .= ",
        (
            SELECT COUNT(*)
            FROM booking_rooms br
            INNER JOIN bookings b ON b.booking_id = br.booking_id
            WHERE br.room_id = r.room_id
              AND b.status IN ('confirmed', 'checked_in')
              AND (? < b.check_out)
              AND (? > b.check_in)
        ) AS booked_rooms
    ";
    $params[] = $check_in;
    $params[] = $check_out;
    $types .= "ss";
} else {
    $sql .= ",
        NULL AS booked_rooms
    ";
}

$sql .= "
    FROM rooms r
    WHERE r.hotel_id = ?
    ORDER BY r.type ASC, r.price ASC, r.room_id ASC
";

$params[] = $hotel_id;
$types .= "i";

$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Prepare failed: ' . mysqli_error($con)
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Query failed: ' . mysqli_error($con)
    ]);
    exit;
}

$data = [];

while ($row = mysqli_fetch_assoc($result)) {
    if (empty($row['image_url']) || !file_exists(__DIR__ . '/../' . $row['image_url'])) {
        $row['image_url'] = 'uploads/rooms/placeholder.png';
    }

    $row['price'] = (float) $row['price'];
    $row['capacity'] = (int) $row['capacity'];
    $row['total_rooms'] = max(1, (int) ($row['total_rooms'] ?? 1));
    $row['is_under_maintenance'] = $row['status'] === 'maintenance';

    if ($useDateFilter) {
        $row['booked_rooms'] = max(0, (int) ($row['booked_rooms'] ?? 0));

        if ($row['booked_rooms'] > $row['total_rooms']) {
            $row['booked_rooms'] = $row['total_rooms'];
        }

        $row['available_rooms'] = max(0, $row['total_rooms'] - $row['booked_rooms']);
        $row['is_booked_for_dates'] = !$row['is_under_maintenance'] && $row['available_rooms'] <= 0;
        $row['can_book'] = !$row['is_under_maintenance'] && $row['available_rooms'] > 0;
        $row['has_checked_dates'] = true;

        if ($row['is_under_maintenance']) {
            $row['availability_label'] = 'Under maintenance';
        } elseif ($row['available_rooms'] <= 0) {
            $row['availability_label'] = 'Sold out for selected dates';
        } elseif ($row['available_rooms'] <= 2) {
            $row['availability_label'] = 'Only ' . $row['available_rooms'] . ' room(s) left';
        } else {
            $row['availability_label'] = $row['available_rooms'] . ' room(s) available';
        }
    } else {
        $row['booked_rooms'] = null;
        $row['available_rooms'] = null;
        $row['is_booked_for_dates'] = false;
        $row['can_book'] = false;
        $row['has_checked_dates'] = false;
        $row['availability_label'] = $row['is_under_maintenance']
            ? 'Under maintenance'
            : 'Select travel dates to check availability';
    }

    if (!empty($row['amenities'])) {
        $row['amenities_array'] = array_values(
            array_filter(array_map('trim', explode(',', $row['amenities'])))
        );
    } else {
        $row['amenities_array'] = [];
    }

    $gallery = [];
    $room_id = (int) $row['room_id'];

    $imgStmt = mysqli_prepare($con, "
        SELECT image_id, image_url
        FROM room_images
        WHERE room_id = ?
        ORDER BY image_id DESC
    ");

    if ($imgStmt) {
        mysqli_stmt_bind_param($imgStmt, "i", $room_id);
        mysqli_stmt_execute($imgStmt);
        $imgResult = mysqli_stmt_get_result($imgStmt);

        if ($imgResult) {
            while ($img = mysqli_fetch_assoc($imgResult)) {
                if (!empty($img['image_url']) && file_exists(__DIR__ . '/../' . $img['image_url'])) {
                    $gallery[] = $img;
                }
            }
        }
    }

    $mainAlreadyExists = false;
    foreach ($gallery as $img) {
        if ($img['image_url'] === $row['image_url']) {
            $mainAlreadyExists = true;
            break;
        }
    }

    if (!$mainAlreadyExists) {
        array_unshift($gallery, [
            'image_id' => 0,
            'image_url' => $row['image_url']
        ]);
    }

    $row['gallery'] = $gallery;

    $originalPrice = (float) $row['price'];

    $bestOffer = getBestApplicableOffer(
        $con,
        (int) $row['hotel_id'],
        (int) $row['room_id'],
        $check_in,
        $check_out,
        $nights,
        $rooms_requested
    );

    $promoOffer = getBestPromoOffer(
        $con,
        (int) $row['hotel_id'],
        (int) $row['room_id'],
        $check_in,
        $check_out
    );

    $finalPrice = $originalPrice;
    $pricingBreakdown = null;

    if ($bestOffer && $useDateFilter) {
        $pricingBreakdown = buildPartialOfferPricing(
            $originalPrice,
            $bestOffer,
            $check_in,
            $check_out,
            1
        );

        $formattedAppliedOffer = formatOfferForResponse($bestOffer, $originalPrice);
        $formattedAppliedOffer['pricing_breakdown'] = $pricingBreakdown;

        if (($pricingBreakdown['overlap_nights'] ?? 0) > 0) {
            if (!empty($pricingBreakdown['is_fully_discounted'])) {
                $finalPrice = (float) $formattedAppliedOffer['final_price'];
            } else {
                $finalPrice = round(
                    (float) $pricingBreakdown['total_price'] / max(1, (int) $pricingBreakdown['total_nights']),
                    2
                );
            }
        }

        $row['offer'] = $formattedAppliedOffer;
        $row['has_offer'] = (($pricingBreakdown['overlap_nights'] ?? 0) > 0);
        $row['offer_applicable'] = (($pricingBreakdown['overlap_nights'] ?? 0) > 0);
        $row['offer_note'] = !empty($pricingBreakdown) && empty($pricingBreakdown['is_fully_discounted'])
            ? 'Discount applies for ' . (int) $pricingBreakdown['overlap_nights'] . ' of ' . (int) $pricingBreakdown['total_nights'] . ' night(s)'
            : null;
    } else {
        $row['offer'] = null;
        $row['has_offer'] = false;
        $row['offer_applicable'] = false;
        $row['offer_note'] = null;
    }

    if ($promoOffer) {
        $row['promo_offer'] = formatOfferForResponse($promoOffer, $originalPrice);
        $row['has_promo_offer'] = true;

        if (!$row['has_offer']) {
            $row['offer_note'] = buildOfferEligibilityMessage(
                $promoOffer,
                $nights,
                $rooms_requested,
                $check_in,
                $check_out
            );
        }
    } else {
        $row['promo_offer'] = null;
        $row['has_promo_offer'] = false;
    }

    $row['original_price'] = $originalPrice;
    $row['final_price'] = $finalPrice;
    $row['pricing_breakdown'] = $pricingBreakdown;

    $data[] = $row;
}

echo json_encode([
    'success' => true,
    'hotel_id' => $hotel_id,
    'hotel_name' => $hotelInfo['name'],
    'hotel_location' => $hotelInfo['location'],
    'check_in' => $check_in,
    'check_out' => $check_out,
    'nights' => $nights,
    'rooms_requested' => $rooms_requested,
    'has_checked_dates' => $useDateFilter,
    'count' => count($data),
    'data' => $data
]);