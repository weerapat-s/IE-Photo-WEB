<?php
// admin/contact_manage.php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../includes/email_templates.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif (isset($_POST['send_reminder'])) {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        // Sanitize custom_msg ก่อน embed ใน HTML email
        $custom_msg = htmlspecialchars(trim($_POST['custom_msg'] ?? ''), ENT_QUOTES, 'UTF-8');
        $stmt = $pdo->prepare("
            SELECT b.*, u.email, u.student_id, e.name as eq_name
            FROM bookings b
            LEFT JOIN users u ON b.user_id = u.id
            LEFT JOIN equipments e ON b.item_id = e.id AND b.booking_type = 'equipment'
            WHERE b.id = ? AND b.booking_type = 'equipment'
        ");
        $stmt->execute([$booking_id]);
        $b = $stmt->fetch();
        if ($b && $b['email']) {
            $emailBody = getReminderEmailTemplate($b['student_id'], $b['eq_name'] ?? '-', $custom_msg);
            sendEmail($b['email'], "แจ้งเตือนการคืนอุปกรณ์: " . ($b['eq_name'] ?? '-'), $emailBody);
            $success = "ส่งอีเมลแจ้งเตือนถึง {$b['student_id']} แล้ว";
        } else { $error = "ไม่พบข้อมูลสมาชิก"; }
    } elseif (isset($_POST['resolve_call'])) {
        $call_id = intval($_POST['call_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE urgent_contacts SET status = 'resolved' WHERE id = ?");
        if($stmt->execute([$call_id])) { $success = "จัดการเคสเรียกด่วนเรียบร้อยแล้ว"; }
    }
}

// LEFT JOIN แทน INNER JOIN เพื่อไม่ให้ booking หายถ้า equipment ถูกลบ
$overdueStmt = $pdo->query("
    SELECT b.id, b.end_datetime, u.student_id, COALESCE(e.name,'(ไม่ระบุ)') as item_name
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.id
    LEFT JOIN equipments e ON b.item_id = e.id AND b.booking_type = 'equipment'
    WHERE b.booking_type = 'equipment' AND b.status = 'approved'
    ORDER BY b.end_datetime ASC
");
$active_borrowed = $overdueStmt->fetchAll();

$callsStmt = $pdo->query("
    SELECT uc.id, uc.created_at, uc.status, s.student_id as sender, r.student_id as receiver
    FROM urgent_contacts uc JOIN users s ON uc.sender_id = s.id JOIN users r ON uc.receiver_id = r.id
    WHERE uc.status != 'resolved' ORDER BY uc.created_at DESC
");
$urgent_calls = $callsStmt->fetchAll();

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2>จัดการสมาชิกและการติดต่อ</h2>
    <p>ส่งอีเมลแจ้งเตือนและจัดการเคสเรียกด่วน</p>
</div>

<?php if($success):?><div class="alert alert-success"><i class="ph-bold ph-check-circle"></i> <?php echo htmlspecialchars($success);?></div><?php endif;?>
<?php if($error):?><div class="alert alert-danger"><i class="ph-bold ph-warning-circle"></i> <?php echo htmlspecialchars($error);?></div><?php endif;?>

<div class="grid-2">
    <div class="glass-card animate-in">
        <h3 style="font-size:1.05rem;margin-bottom:1rem;"><i class="ph-bold ph-envelope"></i> ส่งอีเมลแจ้งเตือนคืนอุปกรณ์</h3>
        <p class="text-muted" style="font-size:.85rem;margin-bottom:1rem;">ส่งอีเมลถึงสมาชิกที่กำลังยืมอุปกรณ์อยู่</p>
        <form method="POST">
            <?php echo csrf_input();?>
            <div class="form-group">
                <label>เลือกรายการที่ยืมอยู่</label>
                <select name="booking_id" class="form-control" required>
                    <option value="">-- เลือกสมาชิกและอุปกรณ์ --</option>
                    <?php foreach($active_borrowed as $ab):?>
                        <?php $isOverdue = strtotime($ab['end_datetime']) < time(); ?>
                        <option value="<?php echo $ab['id'];?>"><?php echo htmlspecialchars("{$ab['student_id']} — {$ab['item_name']} (กำหนดคืน: ".date('d M, H:i',strtotime($ab['end_datetime'])).")"); ?><?php echo $isOverdue?' ⚠️ เลยกำหนด!':''; ?></option>
                    <?php endforeach;?>
                </select>
            </div>
            <div class="form-group">
                <label>ข้อความเพิ่มเติม (ไม่บังคับ)</label>
                <textarea name="custom_msg" class="form-control" rows="3" placeholder="เช่น กรุณาคืนอุปกรณ์โดยเร็ว เนื่องจาก..."></textarea>
            </div>
            <button type="submit" name="send_reminder" class="btn btn-danger w-100"><i class="ph-bold ph-paper-plane-right"></i> ส่งอีเมลแจ้งเตือน</button>
        </form>
    </div>

    <div class="glass-card animate-in">
        <h3 style="font-size:1.05rem;margin-bottom:1rem;"><i class="ph-bold ph-phone-call"></i> เคสเรียกด่วนที่ยังไม่จัดการ</h3>

        <?php if(empty($urgent_calls)):?>
            <div class="empty-state" style="padding:2rem;"><i class="ph ph-check-circle" style="color:var(--success);"></i><p class="text-muted">ไม่มีเคสที่ค้างอยู่</p></div>
        <?php else:?>
            <?php foreach($urgent_calls as $uc):?>
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;padding:.8rem;background:rgba(245,158,11,.05);border-radius:var(--radius-xs);margin-bottom:.6rem;border:1px solid rgba(245,158,11,.15);">
                    <div style="min-width:0;flex:1;">
                        <div style="font-weight:600;font-size:.9rem;word-break:break-word;"><?php echo htmlspecialchars($uc['sender']);?> → <?php echo htmlspecialchars($uc['receiver']);?></div>
                        <div style="font-size:.78rem;color:var(--text-muted);"><i class="ph ph-clock"></i> <?php echo date('d M, H:i',strtotime($uc['created_at']));?></div>
                    </div>
                    <form method="POST" style="flex-shrink:0;"><?php echo csrf_input();?><input type="hidden" name="call_id" value="<?php echo $uc['id'];?>">
                        <button name="resolve_call" class="btn btn-outline btn-sm">จัดการแล้ว</button></form>
                </div>
            <?php endforeach;?>
        <?php endif;?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
