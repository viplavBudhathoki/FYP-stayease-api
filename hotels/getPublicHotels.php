<?php
include __DIR__ . '/../helpers/connection.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");

try {
    $destination = isset($_REQUEST['destination']) ? trim($_REQUEST['destination']) : '';
    $check_in = isset($_REQUEST['check_in']) ? trim($_REQUEST['check_in']) : '';
    $check_out = isset($_REQUEST['check_out']) ? trim($_REQUEST['check_out']) : '';
    $adults = isset($_REQUEST['adults']) ? (int) $_REQUEST['adults'] : 2;
    $children = isset($_REQUEST['children']) ? (int) $_REQUEST['children'] : 0;
    $rooms_requested = isset($_REQUEST['rooms']) ? (int) $_REQUEST['rooms'] : 1;

    if ($adults < 1) $adults = 1;
    if ($children < 0) $children = 0;
    if ($rooms_requested < 1) $rooms_requested = 1;

    $total_guests = $adults + $children;

    $whereParts = [];
    $whereParts[] = "h.status = 'active'";

    $availabilityParams = [];
    $availabilityTypes = "";

    $whereParams = [];
    $whereTypes = "";

    if ($destination !== '') {
        $whereParts[] = "(h.name LIKE ? OR h.location LIKE ?)";
        $destinationLike = "%$destination%";
        $whereParams[] = $destinationLike;
        $whereParams[] = $destinationLike;
        $whereTypes .= "ss";
    }

    $availabilityFilter = "r.status != 'maintenance'";

    $useDateFilter = false;
    if ($check_in !== '' && $check_out !== '') {
        $checkInDate = DateTime::createFromFormat('Y-m-d', $check_in);
        $checkOutDate = DateTime::createFromFormat('Y-m-d', $check_out);

        if (
            $checkInDate && $checkInDate->format('Y-m-d') === $check_in &&
            $checkOutDate && $checkOutDate->format('Y-m-d') === $check_out &&
            $check_out > $check_in
        ) {
            $useDateFilter = true;
        }
    }

    if ($useDateFilter) {
        $availabilityFilter .= "
            AND r.room_id NOT IN (
                SELECT br.room_id
                FROM booking_rooms br
                INNER JOIN bookings b ON b.booking_id = br.booking_id
                WHERE b.status IN ('confirmed', 'checked_in')
                  AND (? < b.check_out)
                  AND (? > b.check_in)
            )
        ";
        $availabilityParams[] = $check_in;
        $availabilityParams[] = $check_out;
        $availabilityTypes .= "ss";
    }

    $whereSql = implode(" AND ", $whereParts);

    $sql = "
    SELECT
        h.hotel_id,
        h.name,
        h.location,
        h.description,
        h.image_url,
        h.status,
        h.created_at,
        COALESCE(rt.avg_rating, 0) AS rating,
        COALESCE(rt.review_count, 0) AS review_count,
        COALESCE(av.starting_price, 0) AS starting_price,
        COALESCE(av.available_rooms, 0) AS available_rooms
    FROM hotels h
    LEFT JOIN (
        SELECT
            hotel_id,
            ROUND(AVG(rating), 1) AS avg_rating,
            COUNT(rating_id) AS review_count
        FROM ratings
        GROUP BY hotel_id
    ) rt ON rt.hotel_id = h.hotel_id
    LEFT JOIN (
        SELECT
            r.hotel_id,
            MIN(r.price) AS starting_price,
            COUNT(r.room_id) AS available_rooms,
            SUM(r.capacity) AS total_capacity
        FROM rooms r
        WHERE $availabilityFilter
        GROUP BY r.hotel_id
    ) av ON av.hotel_id = h.hotel_id
    WHERE $whereSql
      AND COALESCE(av.available_rooms, 0) >= ?
      AND COALESCE(av.total_capacity, 0) >= ?
    ORDER BY h.hotel_id DESC
    ";

    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        throw new Exception("Prepare failed: " . mysqli_error($con));
    }

    $finalParams = array_merge(
        $availabilityParams,
        $whereParams,
        [$rooms_requested, $total_guests]
    );

    $finalTypes = $availabilityTypes . $whereTypes . "ii";

    mysqli_stmt_bind_param($stmt, $finalTypes, ...$finalParams);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (!$result) {
        throw new Exception("Query failed: " . mysqli_error($con));
    }

    $hotels = [];

    while ($row = mysqli_fetch_assoc($result)) {
        if (
            empty($row['image_url']) ||
            !file_exists(__DIR__ . '/../' . $row['image_url'])
        ) {
            $row['image_url'] = 'uploads/hotels/placeholder.png';
        }

        $row['rating'] = (float) ($row['rating'] ?? 0);
        $row['review_count'] = (int) ($row['review_count'] ?? 0);
        $row['starting_price'] = (float) ($row['starting_price'] ?? 0);
        $row['available_rooms'] = (int) ($row['available_rooms'] ?? 0);

        $hotels[] = $row;
    }

    echo json_encode([
        "success" => true,
        "count" => count($hotels),
        "data" => $hotels
    ]);
} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}