<?php
include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['token'], $input['offer_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'token and offer_id required'
    ]);
    exit;
}

$token = trim($input['token']);
$offer_id = (int) $input['offer_id'];

$user_id = getUserIdByToken($token);

if (!$user_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid token'
    ]);
    exit;
}

$isVendorUser = isVendor($token);
$isAdminUser = isAdmin($token);

if (!$isVendorUser && !$isAdminUser) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

if ($isVendorUser) {
    // Vendor → can delete only own offers
    $checkStmt = mysqli_prepare(
        $con,
        "SELECT offer_id FROM offers WHERE offer_id = ? AND vendor_id = ? LIMIT 1"
    );

    mysqli_stmt_bind_param($checkStmt, "ii", $offer_id, $user_id);
    mysqli_stmt_execute($checkStmt);
    $result = mysqli_stmt_get_result($checkStmt);

    if (!$result || mysqli_num_rows($result) === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Offer not found or not owned by you'
        ]);
        exit;
    }
} else {
    // Admin → can delete any offer
    $checkStmt = mysqli_prepare(
        $con,
        "SELECT offer_id FROM offers WHERE offer_id = ? LIMIT 1"
    );

    mysqli_stmt_bind_param($checkStmt, "i", $offer_id);
    mysqli_stmt_execute($checkStmt);
    $result = mysqli_stmt_get_result($checkStmt);

    if (!$result || mysqli_num_rows($result) === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Offer not found'
        ]);
        exit;
    }
}

// Delete offer
$deleteStmt = mysqli_prepare(
    $con,
    "DELETE FROM offers WHERE offer_id = ?"
);

if (!$deleteStmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare delete'
    ]);
    exit;
}

mysqli_stmt_bind_param($deleteStmt, "i", $offer_id);

if (!mysqli_stmt_execute($deleteStmt)) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to delete offer: ' . mysqli_stmt_error($deleteStmt)
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Offer deleted successfully'
]);