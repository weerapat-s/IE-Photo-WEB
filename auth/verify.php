<?php
// auth/verify.php — ระบบยืนยันอีเมลถูกปิดใช้งานแล้ว
// redirect ทุก request ไปหน้า login
header("Location: login.php");
exit;
