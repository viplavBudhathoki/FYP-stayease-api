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

if (!isAdmin($token)) {
    die("Unauthorized admin");
}

$admin_id = getUserIdByToken($token);

if (!$admin_id) {
    die("Invalid token");
}

if (!isset($_GET['from_date']) || !isset($_GET['to_date'])) {
    die("from_date and to_date are required");
}

$from_date = trim($_GET['from_date']);
$to_date = trim($_GET['to_date']);
$status = isset($_GET['status']) ? trim($_GET['status']) : 'all';

if ($from_date === '' || $to_date === '') {
    die("from_date and to_date are required");
}

if ($to_date < $from_date) {
    die("Invalid date range");
}

$statusSql = "";
if ($status !== "all") {
    $allowed = ['confirmed', 'checked_in', 'completed', 'cancelled'];
    if (in_array($status, $allowed, true)) {
        $statusSql = " AND b.status = '$status' ";
    }
}

$sql = "
    SELECT
        b.booking_id,
        b.check_in,
        b.check_out,
        b.total_price,
        b.status,
        b.created_at,

        c.user_id AS customer_id,
        c.full_name AS customer_name,
        c.email AS customer_email,

        r.room_id,
        r.name AS room_name,
        r.type AS room_type,

        h.hotel_id,
        h.name AS hotel_name,
        h.location AS hotel_location,

        v.user_id AS vendor_id,
        v.full_name AS vendor_name,
        v.email AS vendor_email

    FROM bookings b
    INNER JOIN users c ON c.user_id = b.user_id
    INNER JOIN rooms r ON r.room_id = b.room_id
    INNER JOIN hotels h ON h.hotel_id = r.hotel_id
    INNER JOIN users v ON v.user_id = r.vendor_id

    WHERE DATE(b.created_at) BETWEEN '$from_date' AND '$to_date'
    $statusSql

    ORDER BY b.booking_id DESC
";

$result = mysqli_query($con, $sql);

if (!$result) {
    die("Failed to fetch admin bookings");
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
  <title>Admin Bookings Report</title>
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
    <h1>Admin Bookings Report</h1>
    <p>Generated on: ' . htmlspecialchars($today) . '</p>
    <p>Status Filter: ' . htmlspecialchars($status) . '</p>
    <p>From Date: ' . htmlspecialchars($from_date) . '</p>
    <p>To Date: ' . htmlspecialchars($to_date) . '</p>
  </div>

  <div class="summary">
    <p><strong>Total Bookings:</strong> ' . count($rows) . '</p>
    <p><strong>Total Amount:</strong> Rs. ' . number_format($totalAmount, 2) . '</p>
  </div>

  <table>
    <thead>
      <tr>
        <th>Booking ID</th>
        <th>Customer</th>
        <th>Customer Email</th>
        <th>Vendor</th>
        <th>Vendor Email</th>
        <th>Hotel</th>
        <th>Location</th>
        <th>Room</th>
        <th>Type</th>
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
        <td colspan="14">No bookings found for selected filters.</td>
      </tr>
    ';
} else {
    foreach ($rows as $row) {
        $html .= '
        <tr>
          <td>' . htmlspecialchars($row['booking_id']) . '</td>
          <td>' . htmlspecialchars($row['customer_name']) . '</td>
          <td>' . htmlspecialchars($row['customer_email']) . '</td>
          <td>' . htmlspecialchars($row['vendor_name']) . '</td>
          <td>' . htmlspecialchars($row['vendor_email']) . '</td>
          <td>' . htmlspecialchars($row['hotel_name']) . '</td>
          <td>' . htmlspecialchars($row['hotel_location']) . '</td>
          <td>' . htmlspecialchars($row['room_name']) . '</td>
          <td>' . htmlspecialchars($row['room_type']) . '</td>
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

$filename = 'admin_bookings_report_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ["Attachment" => true]);
exit;