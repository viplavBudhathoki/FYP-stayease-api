<?php

require __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

if (!isset($_GET['token'])) {
    die("Token required");
}

$token = $_GET['token'];
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
        h.name AS hotel_name,
        h.location AS hotel_location,
        GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') AS room_name,
        GROUP_CONCAT(DISTINCT r.type ORDER BY r.type SEPARATOR ', ') AS room_type
    FROM bookings b
    INNER JOIN booking_rooms br ON br.booking_id = b.booking_id
    INNER JOIN rooms r ON r.room_id = br.room_id
    INNER JOIN hotels h ON h.hotel_id = r.hotel_id
    WHERE b.user_id = $user_id
    GROUP BY
        b.booking_id,
        b.check_in,
        b.check_out,
        b.total_price,
        b.status,
        b.created_at,
        b.rooms_requested,
        h.name,
        h.location
    ORDER BY b.booking_id DESC
";

$result = mysqli_query($con, $sql);

if (!$result) {
    die("Failed to fetch bookings");
}

$rows = [];
$totalAmount = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $rows[] = $row;
    $totalAmount += (float) $row['total_price'];
}

$today = date("Y-m-d H:i:s");

$html = '
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My Bookings Report</title>
  <style>
    body {
      font-family: DejaVu Sans, Arial, sans-serif;
      font-size: 11px;
      color: #0f172a;
    }
    .topbar {
      margin-bottom: 18px;
    }
    h1 {
      margin: 0 0 8px 0;
      font-size: 22px;
    }
    p {
      margin: 4px 0;
      color: #475569;
    }
    .summary {
      margin: 16px 0 20px 0;
      padding: 12px;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      background: #f8fafc;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 14px;
      font-size: 10px;
    }
    th, td {
      border: 1px solid #cbd5e1;
      padding: 7px;
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
    <h1>My Bookings Report</h1>
    <p>Generated on: ' . htmlspecialchars($today) . '</p>
  </div>

  <div class="summary">
    <p><strong>Total Bookings:</strong> ' . count($rows) . '</p>
    <p><strong>Total Amount:</strong> Rs. ' . number_format($totalAmount, 2) . '</p>
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
        <th>Booked On</th>
      </tr>
    </thead>
    <tbody>
';

if (count($rows) === 0) {
    $html .= '
      <tr>
        <td colspan="11">No bookings found.</td>
      </tr>
    ';
} else {
    foreach ($rows as $row) {
        $html .= '
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
          <td>' . htmlspecialchars($row['created_at']) . '</td>
        </tr>
        ';
    }
}

$html .= '
    </tbody>
  </table>
</body>
</html>
';

$options = new Options();
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$filename = 'my_bookings_report_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ["Attachment" => true]);
exit;