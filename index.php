<?php
// index.php — Entry point
// ใช้ @ ป้องกัน session warning ใน container แล้ว redirect ตาม login state
@session_start();

$loc = '/guest/studio_booking.php';
if (!empty($_SESSION['user_id'])) {
    $loc = ($_SESSION['role'] === 'admin') ? '/admin/dashboard.php' : '/member/feed.php';
}

header('Location: ' . $loc);
header('Cache-Control: no-store, no-cache');
exit;
