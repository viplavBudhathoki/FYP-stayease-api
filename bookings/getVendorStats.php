<!-- SELECT 
  COUNT(*) AS total_bookings,
  COUNT(DISTINCT b.user_id) AS total_users
FROM bookings b
JOIN rooms r ON r.room_id = b.room_id
WHERE r.vendor_id = :vendor_id; -->