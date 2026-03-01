<!-- SELECT 
  b.booking_id, b.check_in, b.check_out, b.status, b.total_price, b.created_at,
  u.user_id, u.full_name, u.email,
  h.hotel_id, h.name AS hotel_name,
  r.room_id, r.name AS room_name
FROM bookings b
JOIN users u ON u.user_id = b.user_id
JOIN rooms r ON r.room_id = b.room_id
JOIN hotels h ON h.hotel_id = r.hotel_id
WHERE r.vendor_id = :vendor_id
ORDER BY b.booking_id DESC; -->