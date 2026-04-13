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

if (!isAdmin($token)) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized admin"
    ]);
    exit;
}

$admin_id = (int) getUserIdByToken($token);

if (!$admin_id) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

/**
 * 1. Platform summary
 */
$sqlSummary = "
    SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'vendor') AS total_vendors,
        (SELECT COUNT(*) FROM users WHERE role = 'user') AS total_users,
        (SELECT COUNT(*) FROM hotels WHERE status = 'active') AS total_hotels,
        (SELECT COUNT(*) FROM rooms) AS total_room_types,
        (SELECT COALESCE(SUM(total_rooms), 0) FROM rooms) AS total_room_inventory
";

$resultSummary = mysqli_query($con, $sqlSummary);

if (!$resultSummary) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to load summary: " . mysqli_error($con)
    ]);
    exit;
}

$summaryBase = mysqli_fetch_assoc($resultSummary) ?: [
    "total_vendors" => 0,
    "total_users" => 0,
    "total_hotels" => 0,
    "total_room_types" => 0,
    "total_room_inventory" => 0,
];

/**
 * 2. Booking counts and revenue
 * No SUM(DISTINCT total_price) here.
 */
$sqlBookings = "
    SELECT
        COUNT(*) AS total_bookings,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_bookings,
        SUM(CASE WHEN status = 'checked_in' THEN 1 ELSE 0 END) AS checked_in_bookings,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_bookings,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_bookings,
        COALESCE(SUM(CASE
            WHEN status IN ('confirmed', 'checked_in', 'completed') THEN total_price
            ELSE 0
        END), 0) AS gross_revenue,
        COALESCE(SUM(CASE
            WHEN status = 'completed' THEN total_price
            ELSE 0
        END), 0) AS completed_revenue,
        SUM(CASE
            WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1
            ELSE 0
        END) AS bookings_last_7_days,
        SUM(CASE
            WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1
            ELSE 0
        END) AS bookings_last_30_days
    FROM bookings
";

$resultBookings = mysqli_query($con, $sqlBookings);

if (!$resultBookings) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to load booking stats: " . mysqli_error($con)
    ]);
    exit;
}

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
 * 3. Occupied room units
 */
$sqlOccupied = "
    SELECT COUNT(*) AS occupied_room_units
    FROM booking_rooms br
    INNER JOIN bookings b ON b.booking_id = br.booking_id
    WHERE b.status = 'checked_in'
";

$resultOccupied = mysqli_query($con, $sqlOccupied);

if (!$resultOccupied) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to load occupancy stats: " . mysqli_error($con)
    ]);
    exit;
}

$occupiedStats = mysqli_fetch_assoc($resultOccupied) ?: [
    "occupied_room_units" => 0
];

$totalRoomInventory = (int) ($summaryBase["total_room_inventory"] ?? 0);
$occupiedRoomUnits = (int) ($occupiedStats["occupied_room_units"] ?? 0);

if ($occupiedRoomUnits > $totalRoomInventory && $totalRoomInventory > 0) {
    $occupiedRoomUnits = $totalRoomInventory;
}

$occupancyRate = $totalRoomInventory > 0
    ? round(($occupiedRoomUnits / $totalRoomInventory) * 100, 2)
    : 0;

/**
 * 4. Hotel-wise performance
 * Aggregate bookings once per hotel.
 */
$sqlHotels = "
    SELECT
        h.hotel_id,
        h.name AS hotel_name,
        h.location,
        h.vendor_id,
        u.full_name AS vendor_name
    FROM hotels h
    LEFT JOIN users u ON u.user_id = h.vendor_id
    ORDER BY h.name ASC
";

$resultHotels = mysqli_query($con, $sqlHotels);

if (!$resultHotels) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to load hotels: " . mysqli_error($con)
    ]);
    exit;
}

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

    if (!$stmtHotelRoomStats) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare hotel room stats query: " . mysqli_error($con)
        ]);
        exit;
    }

    mysqli_stmt_bind_param($stmtHotelRoomStats, "i", $hotel_id);
    mysqli_stmt_execute($stmtHotelRoomStats);
    $resultHotelRoomStats = mysqli_stmt_get_result($stmtHotelRoomStats);

    $roomStats = mysqli_fetch_assoc($resultHotelRoomStats) ?: [
        "room_types" => 0,
        "total_rooms" => 0,
    ];

    $sqlHotelBookingStats = "
        SELECT
            COUNT(*) AS total_bookings,
            COALESCE(SUM(CASE
                WHEN x.status IN ('confirmed', 'checked_in', 'completed') THEN x.total_price
                ELSE 0
            END), 0) AS revenue
        FROM (
            SELECT DISTINCT b.booking_id, b.status, b.total_price
            FROM rooms r
            INNER JOIN booking_rooms br ON br.room_id = r.room_id
            INNER JOIN bookings b ON b.booking_id = br.booking_id
            WHERE r.hotel_id = ?
        ) x
    ";
    $stmtHotelBookingStats = mysqli_prepare($con, $sqlHotelBookingStats);

    if (!$stmtHotelBookingStats) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare hotel booking stats query: " . mysqli_error($con)
        ]);
        exit;
    }

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
        "vendor_id" => (int) ($hotel["vendor_id"] ?? 0),
        "vendor_name" => $hotel["vendor_name"] ?: "Unassigned",
        "room_types" => (int) ($roomStats["room_types"] ?? 0),
        "total_rooms" => (int) ($roomStats["total_rooms"] ?? 0),
        "total_bookings" => (int) ($bookingStatsPerHotel["total_bookings"] ?? 0),
        "revenue" => (float) ($bookingStatsPerHotel["revenue"] ?? 0),
    ];
}

/**
 * 5. Vendor-wise performance
 * Aggregate bookings once per vendor.
 */
$sqlVendors = "
    SELECT
        u.user_id AS vendor_id,
        u.full_name AS vendor_name
    FROM users u
    WHERE u.role = 'vendor'
    ORDER BY u.full_name ASC
";

$resultVendors = mysqli_query($con, $sqlVendors);

if (!$resultVendors) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to load vendors: " . mysqli_error($con)
    ]);
    exit;
}

$vendorPerformance = [];

while ($vendor = mysqli_fetch_assoc($resultVendors)) {
    $vendor_id = (int) $vendor["vendor_id"];

    $sqlVendorStats = "
        SELECT
            COUNT(DISTINCT h.hotel_id) AS total_hotels,
            COUNT(*) AS total_bookings,
            COALESCE(SUM(CASE
                WHEN x.status IN ('confirmed', 'checked_in', 'completed') THEN x.total_price
                ELSE 0
            END), 0) AS revenue
        FROM hotels h
        LEFT JOIN (
            SELECT DISTINCT
                h2.vendor_id,
                b.booking_id,
                b.status,
                b.total_price
            FROM hotels h2
            INNER JOIN rooms r ON r.hotel_id = h2.hotel_id
            INNER JOIN booking_rooms br ON br.room_id = r.room_id
            INNER JOIN bookings b ON b.booking_id = br.booking_id
        ) x ON x.vendor_id = h.vendor_id
        WHERE h.vendor_id = ?
    ";

    $stmtVendorStats = mysqli_prepare($con, $sqlVendorStats);

    if (!$stmtVendorStats) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare vendor stats query: " . mysqli_error($con)
        ]);
        exit;
    }

    mysqli_stmt_bind_param($stmtVendorStats, "i", $vendor_id);
    mysqli_stmt_execute($stmtVendorStats);
    $resultVendorStats = mysqli_stmt_get_result($stmtVendorStats);

    $vendorStats = mysqli_fetch_assoc($resultVendorStats) ?: [
        "total_hotels" => 0,
        "total_bookings" => 0,
        "revenue" => 0,
    ];

    $vendorPerformance[] = [
        "vendor_id" => $vendor_id,
        "vendor_name" => $vendor["vendor_name"],
        "total_hotels" => (int) ($vendorStats["total_hotels"] ?? 0),
        "total_bookings" => (int) ($vendorStats["total_bookings"] ?? 0),
        "revenue" => (float) ($vendorStats["revenue"] ?? 0),
    ];
}

usort($hotelPerformance, function ($a, $b) {
    if ($a["revenue"] === $b["revenue"]) {
        return $b["total_bookings"] <=> $a["total_bookings"];
    }
    return $b["revenue"] <=> $a["revenue"];
});

usort($vendorPerformance, function ($a, $b) {
    if ($a["revenue"] === $b["revenue"]) {
        return $b["total_bookings"] <=> $a["total_bookings"];
    }
    return $b["revenue"] <=> $a["revenue"];
});

echo json_encode([
    "success" => true,
    "data" => [
        "summary" => [
            "total_vendors" => (int) ($summaryBase["total_vendors"] ?? 0),
            "total_users" => (int) ($summaryBase["total_users"] ?? 0),
            "total_hotels" => (int) ($summaryBase["total_hotels"] ?? 0),
            "total_room_types" => (int) ($summaryBase["total_room_types"] ?? 0),
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
        "hotel_performance" => $hotelPerformance,
        "vendor_performance" => $vendorPerformance
    ]
]);