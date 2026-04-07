<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

if (!isset($_POST['token'])) {
    echo json_encode([
        "success" => false,
        "message" => "Token is required"
    ]);
    exit;
}

$token = trim($_POST['token']);

if (!isVendor($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized vendor"
    ]);
    exit;
}

$vendor_id = (int) getUserIdByToken($token);

if (!$vendor_id) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

/**
 * 1. Basic inventory stats
 */
$sqlInventory = "
    SELECT
        COUNT(DISTINCT h.hotel_id) AS total_hotels,
        COUNT(DISTINCT r.room_id) AS total_room_types,
        COALESCE(SUM(r.total_rooms), 0) AS total_room_inventory
    FROM hotels h
    LEFT JOIN rooms r ON r.hotel_id = h.hotel_id
    WHERE h.vendor_id = ?
      AND h.status = 'active'
";

$stmtInventory = mysqli_prepare($con, $sqlInventory);
if (!$stmtInventory) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare inventory query: " . mysqli_error($con)
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtInventory, "i", $vendor_id);
mysqli_stmt_execute($stmtInventory);
$resultInventory = mysqli_stmt_get_result($stmtInventory);
$inventory = mysqli_fetch_assoc($resultInventory) ?: [
    "total_hotels" => 0,
    "total_room_types" => 0,
    "total_room_inventory" => 0,
];

/**
 * 2. Booking counts and revenue
 */
$sqlBookings = "
    SELECT
        COUNT(DISTINCT b.booking_id) AS total_bookings,
        COUNT(DISTINCT CASE WHEN b.status = 'confirmed' THEN b.booking_id END) AS confirmed_bookings,
        COUNT(DISTINCT CASE WHEN b.status = 'checked_in' THEN b.booking_id END) AS checked_in_bookings,
        COUNT(DISTINCT CASE WHEN b.status = 'completed' THEN b.booking_id END) AS completed_bookings,
        COUNT(DISTINCT CASE WHEN b.status = 'cancelled' THEN b.booking_id END) AS cancelled_bookings,
        COALESCE(SUM(DISTINCT CASE
            WHEN b.status IN ('confirmed', 'checked_in', 'completed')
            THEN (b.booking_id * 0 + b.total_price)
            ELSE NULL
        END), 0) AS gross_revenue,
        COALESCE(SUM(DISTINCT CASE
            WHEN b.status = 'completed'
            THEN (b.booking_id * 0 + b.total_price)
            ELSE NULL
        END), 0) AS completed_revenue,
        COUNT(DISTINCT CASE
            WHEN DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            THEN b.booking_id
        END) AS bookings_last_7_days,
        COUNT(DISTINCT CASE
            WHEN DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            THEN b.booking_id
        END) AS bookings_last_30_days
    FROM rooms r
    LEFT JOIN booking_rooms br ON br.room_id = r.room_id
    LEFT JOIN bookings b ON b.booking_id = br.booking_id
    WHERE r.vendor_id = ?
";

$stmtBookings = mysqli_prepare($con, $sqlBookings);
if (!$stmtBookings) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare booking stats query: " . mysqli_error($con)
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtBookings, "i", $vendor_id);
mysqli_stmt_execute($stmtBookings);
$resultBookings = mysqli_stmt_get_result($stmtBookings);
$bookingStats = mysqli_fetch_assoc($resultBookings) ?: [
    "total_bookings" => 0,
    "confirmed_bookings" => 0,
    "checked_in_bookings" => 0,
    "completed_bookings" => 0,
    "cancelled_bookings" => 0,
    "gross_revenue" => 0,
    "completed_revenue" => 0,
    "bookings_last_7_days" => 0,
    "bookings_last_30_days" => 0,
];

/**
 * 3. Current occupied room units
 */
$sqlOccupied = "
    SELECT COUNT(*) AS occupied_room_units
    FROM booking_rooms br
    INNER JOIN bookings b ON b.booking_id = br.booking_id
    INNER JOIN rooms r ON r.room_id = br.room_id
    WHERE r.vendor_id = ?
      AND b.status = 'checked_in'
";

$stmtOccupied = mysqli_prepare($con, $sqlOccupied);
if (!$stmtOccupied) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare occupancy query: " . mysqli_error($con)
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtOccupied, "i", $vendor_id);
mysqli_stmt_execute($stmtOccupied);
$resultOccupied = mysqli_stmt_get_result($stmtOccupied);
$occupiedStats = mysqli_fetch_assoc($resultOccupied) ?: [
    "occupied_room_units" => 0
];

$totalRoomInventory = (int) ($inventory["total_room_inventory"] ?? 0);
$occupiedRoomUnits = (int) ($occupiedStats["occupied_room_units"] ?? 0);

if ($occupiedRoomUnits > $totalRoomInventory && $totalRoomInventory > 0) {
    $occupiedRoomUnits = $totalRoomInventory;
}

$occupancyRate = $totalRoomInventory > 0
    ? round(($occupiedRoomUnits / $totalRoomInventory) * 100, 2)
    : 0;

/**
 * 4. Hotel-wise performance
 */
$sqlHotels = "
    SELECT
        h.hotel_id,
        h.name AS hotel_name,
        h.location
    FROM hotels h
    WHERE h.vendor_id = ?
    ORDER BY h.name ASC
";

$stmtHotels = mysqli_prepare($con, $sqlHotels);
if (!$stmtHotels) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare hotels query: " . mysqli_error($con)
    ]);
    exit;
}

mysqli_stmt_bind_param($stmtHotels, "i", $vendor_id);
mysqli_stmt_execute($stmtHotels);
$resultHotels = mysqli_stmt_get_result($stmtHotels);

$hotelPerformance = [];

while ($hotel = mysqli_fetch_assoc($resultHotels)) {
    $hotel_id = (int) $hotel["hotel_id"];

    $sqlHotelRoomStats = "
        SELECT
            COUNT(*) AS room_types,
            COALESCE(SUM(total_rooms), 0) AS total_rooms
        FROM rooms
        WHERE hotel_id = ?
    ";
    $stmtHotelRoomStats = mysqli_prepare($con, $sqlHotelRoomStats);
    mysqli_stmt_bind_param($stmtHotelRoomStats, "i", $hotel_id);
    mysqli_stmt_execute($stmtHotelRoomStats);
    $resultHotelRoomStats = mysqli_stmt_get_result($stmtHotelRoomStats);
    $roomStats = mysqli_fetch_assoc($resultHotelRoomStats) ?: [
        "room_types" => 0,
        "total_rooms" => 0,
    ];

    $sqlHotelBookingStats = "
        SELECT
            COUNT(DISTINCT b.booking_id) AS total_bookings,
            COALESCE(SUM(DISTINCT CASE
                WHEN b.status IN ('confirmed', 'checked_in', 'completed')
                THEN (b.booking_id * 0 + b.total_price)
                ELSE NULL
            END), 0) AS revenue
        FROM rooms r
        LEFT JOIN booking_rooms br ON br.room_id = r.room_id
        LEFT JOIN bookings b ON b.booking_id = br.booking_id
        WHERE r.hotel_id = ?
    ";
    $stmtHotelBookingStats = mysqli_prepare($con, $sqlHotelBookingStats);
    mysqli_stmt_bind_param($stmtHotelBookingStats, "i", $hotel_id);
    mysqli_stmt_execute($stmtHotelBookingStats);
    $resultHotelBookingStats = mysqli_stmt_get_result($stmtHotelBookingStats);
    $bookingStatsPerHotel = mysqli_fetch_assoc($resultHotelBookingStats) ?: [
        "total_bookings" => 0,
        "revenue" => 0,
    ];

    $hotelPerformance[] = [
        "hotel_id" => $hotel_id,
        "hotel_name" => $hotel["hotel_name"],
        "location" => $hotel["location"],
        "room_types" => (int) ($roomStats["room_types"] ?? 0),
        "total_rooms" => (int) ($roomStats["total_rooms"] ?? 0),
        "total_bookings" => (int) ($bookingStatsPerHotel["total_bookings"] ?? 0),
        "revenue" => (float) ($bookingStatsPerHotel["revenue"] ?? 0),
    ];
}

usort($hotelPerformance, function ($a, $b) {
    if ($a["revenue"] === $b["revenue"]) {
        if ($a["total_bookings"] === $b["total_bookings"]) {
            return strcmp($a["hotel_name"], $b["hotel_name"]);
        }
        return $b["total_bookings"] <=> $a["total_bookings"];
    }
    return $b["revenue"] <=> $a["revenue"];
});

echo json_encode([
    "success" => true,
    "data" => [
        "summary" => [
            "total_hotels" => (int) ($inventory["total_hotels"] ?? 0),
            "total_room_types" => (int) ($inventory["total_room_types"] ?? 0),
            "total_room_inventory" => $totalRoomInventory,
            "total_bookings" => (int) ($bookingStats["total_bookings"] ?? 0),
            "confirmed_bookings" => (int) ($bookingStats["confirmed_bookings"] ?? 0),
            "checked_in_bookings" => (int) ($bookingStats["checked_in_bookings"] ?? 0),
            "completed_bookings" => (int) ($bookingStats["completed_bookings"] ?? 0),
            "cancelled_bookings" => (int) ($bookingStats["cancelled_bookings"] ?? 0),
            "gross_revenue" => (float) ($bookingStats["gross_revenue"] ?? 0),
            "completed_revenue" => (float) ($bookingStats["completed_revenue"] ?? 0),
            "bookings_last_7_days" => (int) ($bookingStats["bookings_last_7_days"] ?? 0),
            "bookings_last_30_days" => (int) ($bookingStats["bookings_last_30_days"] ?? 0),
            "occupied_room_units" => $occupiedRoomUnits,
            "occupancy_rate" => $occupancyRate
        ],
        "hotel_performance" => $hotelPerformance
    ]
]);