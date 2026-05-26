<?php
// admin/run_migration_super_admin.php
// ใช้ครั้งเดียว — อัปเดต role column และตั้ง super_admin ให้ student_id 68030271
// ลบไฟล์นี้ทิ้งหลังรัน!
session_start();
require_once __DIR__ . '/../config/database.php';

// ป้องกัน: ต้อง login เป็น admin หรือ super_admin ก่อน
if (!isset($_SESSION['user_id']) || !is_admin()) {
    die('Access denied. Please login as admin first.');
}

$results = [];

// ── 1. ปรับ role column ให้รองรับ super_admin ──────────────────────────────
try {
    // ลอง ALTER TABLE ก่อน (กรณีเป็น ENUM)
    // ถ้าเป็น VARCHAR ก็ไม่ต้อง ALTER แต่ไม่เสียหาย
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('member','admin','super_admin') DEFAULT 'member'");
    $results[] = ['status' => 'ok', 'msg' => 'ALTER TABLE users: role column ขยายเป็น ENUM ที่รองรับ super_admin'];
} catch (PDOException $e) {
    // อาจจะเป็น VARCHAR อยู่แล้ว หรือ column ชื่ออื่น
    $results[] = ['status' => 'warn', 'msg' => 'ALTER TABLE: ' . $e->getMessage() . ' (อาจไม่จำเป็น)'];
}

// ── 2. ตั้ง role = 'super_admin' ให้ student_id = '68030271' ──────────────
try {
    $stmt = $pdo->prepare("UPDATE users SET role = 'super_admin' WHERE student_id = ?");
    $stmt->execute(['68030271']);
    $affected = $stmt->rowCount();
    if ($affected > 0) {
        $results[] = ['status' => 'ok', 'msg' => "อัปเดต student_id 68030271 เป็น super_admin สำเร็จ ({$affected} row)"];
    } else {
        $results[] = ['status' => 'warn', 'msg' => 'ไม่พบ student_id 68030271 ในระบบ (0 rows updated)'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'msg' => 'UPDATE users: ' . $e->getMessage()];
}

// ── 3. แสดงผล ────────────────────────────────────────────────────────────────
$users = $pdo->query("SELECT student_id, first_name, last_name, role FROM users ORDER BY role DESC, student_id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Migration: Super Admin</title>
<style>
body { font-family: sans-serif; max-width: 700px; margin: 40px auto; padding: 0 20px; }
.ok    { color: #16a34a; background: #f0fdf4; border: 1px solid #86efac; padding: 10px 14px; border-radius: 8px; margin: 8px 0; }
.warn  { color: #ca8a04; background: #fefce8; border: 1px solid #fde047; padding: 10px 14px; border-radius: 8px; margin: 8px 0; }
.error { color: #dc2626; background: #fef2f2; border: 1px solid #fca5a5; padding: 10px 14px; border-radius: 8px; margin: 8px 0; }
table  { width: 100%; border-collapse: collapse; margin-top: 20px; }
th, td { padding: 8px 12px; border: 1px solid #e5e7eb; text-align: left; }
th     { background: #f9fafb; }
.super_admin { background: linear-gradient(135deg,#7c3aed,#4f46e5); color: #fff; padding: 3px 10px; border-radius: 999px; font-size: .8rem; }
.admin  { background: #d1fae5; color: #065f46; padding: 3px 10px; border-radius: 999px; font-size: .8rem; }
.member { background: #f3f4f6; color: #6b7280; padding: 3px 10px; border-radius: 999px; font-size: .8rem; }
.del-box { margin-top: 30px; padding: 16px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; }
</style>
</head>
<body>
<h2>🚀 Migration: Super Admin Role</h2>

<?php foreach ($results as $r): ?>
    <div class="<?php echo $r['status']; ?>">
        <?php echo htmlspecialchars($r['msg']); ?>
    </div>
<?php endforeach; ?>

<h3>รายชื่อผู้ใช้ปัจจุบัน</h3>
<table>
    <tr><th>รหัสนักศึกษา</th><th>ชื่อ-สกุล</th><th>Role</th></tr>
    <?php foreach ($users as $u): ?>
    <tr>
        <td><?php echo htmlspecialchars($u['student_id'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name'])); ?></td>
        <td><span class="<?php echo $u['role']; ?>"><?php echo htmlspecialchars($u['role']); ?></span></td>
    </tr>
    <?php endforeach; ?>
</table>

<div class="del-box">
    <strong>⚠️ ลบไฟล์นี้ทิ้งทันทีหลังรัน!</strong><br>
    <code>C:\xampp\htdocs\IE-Photo-WEB\admin\run_migration_super_admin.php</code><br><br>
    หรือบน CleverCloud ให้รัน: <code>clever ssh</code> แล้ว <code>rm /app/admin/run_migration_super_admin.php</code>
</div>
</body>
</html>
