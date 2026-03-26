<?php

require __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

if (!isset($_GET['token'], $_GET['booking_id'])) {
    die("Token and booking_id are required");
}

$token = $_GET['token'];
$booking_id = (int) $_GET['booking_id'];

$user_id = getUserIdByToken($token);

if (!$user_id) {
    die("Invalid token");
}

$user_id = (int) $user_id;

$sql = "
    SELECT
        b.booking_id,
        b.check_in,
        b.check_out,
        b.total_price,
        b.status,
        b.created_at,
        b.rooms_requested,
        b.adults,
        b.children,
        h.name AS hotel_name,
        h.location AS hotel_location,
        GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') AS room_name,
        GROUP_CONCAT(DISTINCT r.type ORDER BY r.type SEPARATOR ', ') AS room_type
    FROM bookings b
    INNER JOIN booking_rooms br ON br.booking_id = b.booking_id
    INNER JOIN rooms r ON r.room_id = br.room_id
    INNER JOIN hotels h ON h.hotel_id = r.hotel_id
    WHERE b.booking_id = ?
      AND b.user_id = ?
    GROUP BY
        b.booking_id,
        b.check_in,
        b.check_out,
        b.total_price,
        b.status,
        b.created_at,
        b.rooms_requested,
        b.adults,
        b.children,
        h.name,
        h.location
    LIMIT 1
";

$stmt = mysqli_prepare($con, $sql);

if (!$stmt) {
    die("Failed to prepare booking query");
}

mysqli_stmt_bind_param($stmt, "ii", $booking_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) === 0) {
    die("Booking not found");
}

$row = mysqli_fetch_assoc($result);
$today = date("Y-m-d H:i:s");

$html = '
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Booking Invoice</title>
  <style>
    body {
      font-family: DejaVu Sans, Arial, sans-serif;
      font-size: 12px;
      color: #0f172a;
    }
    .topbar {
      margin-bottom: 20px;
    }
    h1 {
      margin: 0 0 8px 0;
      font-size: 24px;
    }
    p {
      margin: 4px 0;
      color: #475569;
    }
    .box {
      margin: 16px 0;
      padding: 14px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      background: #f8fafc;
    }
    .label {
      font-weight: bold;
      color: #0f172a;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 16px;
      font-size: 11px;
    }
    th, td {
      border: 1px solid #cbd5e1;
      padding: 8px;
      text-align: left;
      vertical-align: top;
    }
    th {
      background: #0f172a;
      color: #ffffff;
    }
  </style>
</head>
<body>
  <div class="topbar">
    <h1>Booking Invoice</h1>
    <p>Generated on: ' . htmlspecialchars($today) . '</p>
  </div>

  <div class="box">
    <p><span class="label">Booking ID:</span> ' . htmlspecialchars($row['booking_id']) . '</p>
    <p><span class="label">Hotel:</span> ' . htmlspecialchars($row['hotel_name']) . '</p>
    <p><span class="label">Location:</span> ' . htmlspecialchars($row['hotel_location']) . '</p>
    <p><span class="label">Rooms:</span> ' . htmlspecialchars($row['room_name']) . '</p>
    <p><span class="label">Types:</span> ' . htmlspecialchars($row['room_type']) . '</p>
    <p><span class="label">Room Count:</span> ' . htmlspecialchars($row['rooms_requested']) . '</p>
    <p><span class="label">Guests:</span> ' . htmlspecialchars($row['adults']) . ' Adults, ' . htmlspecialchars($row['children']) . ' Children</p>
    <p><span class="label">Check-in:</span> ' . htmlspecialchars($row['check_in']) . '</p>
    <p><span class="label">Check-out:</span> ' . htmlspecialchars($row['check_out']) . '</p>
    <p><span class="label">Status:</span> ' . htmlspecialchars($row['status']) . '</p>
    <p><span class="label">Booked On:</span> ' . htmlspecialchars($row['created_at']) . '</p>
    <p><span class="label">Total Price:</span> Rs. ' . number_format((float)$row['total_price'], 2) . '</p>
  </div>

  <table>
    <thead>
      <tr>
        <th>Booking ID</th>
        <th>Hotel</th>
        <th>Location</th>
        <th>Rooms</th>
        <th>Types</th>
        <th>Room Count</th>
        <th>Check-in</th>
        <th>Check-out</th>
        <th>Status</th>
        <th>Total Price</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>' . htmlspecialchars($row['booking_id']) . '</td>
        <td>' . htmlspecialchars($row['hotel_name']) . '</td>
        <td>' . htmlspecialchars($row['hotel_location']) . '</td>
        <td>' . htmlspecialchars($row['room_name']) . '</td>
        <td>' . htmlspecialchars($row['room_type']) . '</td>
        <td>' . htmlspecialchars($row['rooms_requested']) . '</td>
        <td>' . htmlspecialchars($row['check_in']) . '</td>
        <td>' . htmlspecialchars($row['check_out']) . '</td>
        <td>' . htmlspecialchars($row['status']) . '</td>
        <td>Rs. ' . number_format((float)$row['total_price'], 2) . '</td>
      </tr>
    </tbody>
  </table>
</body>
</html>
';

$options = new Options();
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'booking_invoice_' . $booking_id . '.pdf';
$dompdf->stream($filename, ["Attachment" => true]);
exit;