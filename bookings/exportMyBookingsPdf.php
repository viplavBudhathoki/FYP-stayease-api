<?php

include __DIR__ . '/../helpers/connection.php';
include __DIR__ . '/../helpers/auth.php';

if (!isset($_GET['token'])) {
    die("Token required");
}

$user_id = getUserIdByToken($_GET['token']);

if (!$user_id) {
    die("Invalid token");
}

$sql = "
    SELECT
        b.booking_id,
        b.check_in,
        b.check_out,
        b.total_price,
        b.status,
        b.created_at,
        r.name AS room_name,
        r.type AS room_type,
        h.name AS hotel_name,
        h.location AS hotel_location
    FROM bookings b
    INNER JOIN rooms r ON r.room_id = b.room_id
    INNER JOIN hotels h ON h.hotel_id = r.hotel_id
    WHERE b.user_id = '$user_id'
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My Bookings Report</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 30px; color: #0f172a; background: #fff; }
    .topbar { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; margin-bottom: 24px; }
    h1 { margin: 0 0 8px 0; font-size: 28px; }
    p { margin: 4px 0; color: #475569; }
    .summary { margin: 20px 0; padding: 16px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 14px; }
    th, td { border: 1px solid #cbd5e1; padding: 10px; text-align: left; vertical-align: top; }
    th { background: #0f172a; color: #fff; }
    tr:nth-child(even) { background: #f8fafc; }
    .printBtn { padding: 10px 16px; border: none; border-radius: 8px; background: #f8740f; color: white; font-weight: 700; cursor: pointer; }
    @media print {
      .printBtn { display: none; }
      body { padding: 0; }
    }
  </style>
</head>
<body>
  <div class="topbar">
    <div>
      <h1>My Bookings Report</h1>
      <p>Generated on: <?php echo $today; ?></p>
    </div>

    <button class="printBtn" onclick="window.print()">Print / Save PDF</button>
  </div>

  <div class="summary">
    <p><strong>Total Bookings:</strong> <?php echo count($rows); ?></p>
    <p><strong>Total Amount:</strong> Rs. <?php echo number_format($totalAmount, 2); ?></p>
  </div>

  <table>
    <thead>
      <tr>
        <th>Booking ID</th>
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
      <?php if (count($rows) === 0): ?>
        <tr>
          <td colspan="10">No bookings found.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><?php echo htmlspecialchars($row['booking_id']); ?></td>
            <td><?php echo htmlspecialchars($row['hotel_name']); ?></td>
            <td><?php echo htmlspecialchars($row['hotel_location']); ?></td>
            <td><?php echo htmlspecialchars($row['room_name']); ?></td>
            <td><?php echo htmlspecialchars($row['room_type']); ?></td>
            <td><?php echo htmlspecialchars($row['check_in']); ?></td>
            <td><?php echo htmlspecialchars($row['check_out']); ?></td>
            <td><?php echo htmlspecialchars($row['status']); ?></td>
            <td>Rs. <?php echo number_format((float)$row['total_price'], 2); ?></td>
            <td><?php echo htmlspecialchars($row['created_at']); ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</body>
</html>