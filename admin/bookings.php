<?php
// admin/bookings.php
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

function generateIcs($start, $end, $summary, $description) {
    $dtstart = gmdate('Ymd\THis\Z', strtotime($start));
    $dtend = gmdate('Ymd\THis\Z', strtotime($end));
    $dtstamp = gmdate('Ymd\THis\Z');
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//IE-Photo KMITL//Booking//EN\r\nBEGIN:VEVENT\r\nUID:".uniqid()."@iephoto.online\r\nDTSTAMP:{$dtstamp}\r\nDTSTART:{$dtstart}\r\nDTEND:{$dtend}\r\nSUMMARY:{$summary}\r\nDESCRIPTION:{$description}\r\nEND:VEVENT\r\nEND:VCALENDAR";
}

// Check if consent_token column exists (handles migration gracefully)
$hasConsentColumn = false;
try {
    $checkCol = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'consent_token'");
    $hasConsentColumn = $checkCol->rowCount() > 0;
} catch (Exception $e) { /* ignore */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── CSRF ─────────────────────────────────────────────────────────────────
    if (!csrf_verify()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    }

    $booking_id = intval($_POST['booking_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (empty($error) && $booking_id > 0 && in_array($action, ['approve', 'reject', 'return'])) {
        $status = match($action) { 'approve'=>'approved', 'reject'=>'rejected', 'return'=>'returned', default=>$action };

        // Handle return image upload — ตรวจสอบทั้ง extension และ MIME type จริง
        $return_image = null;
        if ($action === 'return' && isset($_FILES['return_image']) && $_FILES['return_image']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['return_image']['size'] > 8 * 1024 * 1024) {
                /* FIX: ใช้ $error แทน throw เพื่อไม่ให้ได้ 500 page */
                $error = 'ไฟล์ใหญ่เกิน 8 MB';
            } else {
                $ext = strtolower(pathinfo($_FILES['return_image']['name'], PATHINFO_EXTENSION));
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['return_image']['tmp_name']);
                finfo_close($finfo);
                $allowedMimes = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                if (in_array($ext, ['jpg','jpeg','png','webp']) && isset($allowedMimes[$mime])) {
                    $return_image = 'return_' . $booking_id . '_' . time() . '.' . $allowedMimes[$mime];
                    $upload_dir = __DIR__ . '/../uploads/returns/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    if (!move_uploaded_file($_FILES['return_image']['tmp_name'], $upload_dir . $return_image)) {
                        $return_image = null;
                    }
                }
            }
        }

        if (empty($error)):
        $pdo->beginTransaction();
        try {
            // Update booking status
            if ($return_image) {
                $stmt = $pdo->prepare("UPDATE bookings SET status = ?, return_image_path = ? WHERE id = ?");
                $stmt->execute([$status, $return_image, $booking_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ?");
                $stmt->execute([$status, $booking_id]);
            }

            // Get booking details
            $bStmt = $pdo->prepare("
                SELECT b.*, u.email as member_email, u.student_id,
                       e.name as eq_name, s.name as studio_name
                FROM bookings b
                LEFT JOIN users u ON b.user_id = u.id
                LEFT JOIN equipments e ON b.item_id = e.id AND b.booking_type = 'equipment'
                LEFT JOIN studios s ON b.item_id = s.id AND b.booking_type = 'studio'
                WHERE b.id = ?
            ");
            $bStmt->execute([$booking_id]);
            $b = $bStmt->fetch();

            $itemName = $b['booking_type'] === 'equipment' ? $b['eq_name'] : $b['studio_name'];
            $userEmail = $b['member_email'] ?: ($b['guest_email'] ?? '');
            $userName = $b['student_id'] ?: ($b['guest_name'] ?? 'Guest');

            // Update equipment status — ตรวจสอบ action ก่อนเปลี่ยน
            if ($b['booking_type'] === 'equipment') {
                if ($action === 'approve') {
                    // อนุมัติ → set borrowed
                    $eqUpdate = $pdo->prepare("UPDATE equipments SET status = 'borrowed' WHERE id = ?");
                    $eqUpdate->execute([$b['item_id']]);
                } elseif ($action === 'return') {
                    // คืนแล้ว → set available
                    $eqUpdate = $pdo->prepare("UPDATE equipments SET status = 'available' WHERE id = ?");
                    $eqUpdate->execute([$b['item_id']]);
                }
                // reject บน pending booking → ไม่ต้องเปลี่ยน equipment status (ยังไม่ถูก approve)
            }

            // Feed entry
            $statusText = match($status) {
                'approved' => 'ได้รับการอนุมัติ ✅',
                'rejected' => 'ถูกปฏิเสธ ❌',
                'returned' => 'คืนอุปกรณ์แล้ว 📦',
                default => $status,
            };
            $feedMsg = "อัปเดต: การจอง #{$booking_id} {$itemName} — {$statusText}";
            $feedInsert = $pdo->prepare("INSERT INTO feeds (booking_id, message) VALUES (?, ?)");
            $feedInsert->execute([$booking_id, $feedMsg]);

            // Send email on approve
            if ($action === 'approve' && $userEmail) {
                $consentToken = null;
                if ($hasConsentColumn) {
                    $consentToken = bin2hex(random_bytes(32));
                    $tokenUpdate = $pdo->prepare("UPDATE bookings SET consent_token = ? WHERE id = ?");
                    $tokenUpdate->execute([$consentToken, $booking_id]);
                }
                $emailBody = getApprovedEmailTemplate($userName, $itemName, $b['start_datetime'], $b['end_datetime'], $consentToken);
                sendEmail($userEmail, "การจองของคุณได้รับการอนุมัติ: {$itemName}", $emailBody);
            }

            $pdo->commit();
            $success = "บันทึกสำเร็จ: " . match($status) { 'approved'=>'อนุมัติ', 'rejected'=>'ปฏิเสธ', 'returned'=>'รับคืน', default=>$status };
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "ดำเนินการไม่สำเร็จ: " . $e->getMessage();
        }
        endif; // empty($error)
    }
}

// Fetch bookings
$filter = $_GET['filter'] ?? 'all';
$where = '';
if ($filter === 'pending') $where = "WHERE b.status = 'pending'";
elseif ($filter === 'approved') $where = "WHERE b.status = 'approved'";
elseif ($filter === 'rejected') $where = "WHERE b.status = 'rejected'";
elseif ($filter === 'pending_return') $where = "WHERE b.status = 'pending_return'";
elseif ($filter === 'returned') $where = "WHERE b.status = 'returned'";

$query = "
    SELECT b.id, b.booking_type, b.start_datetime, b.end_datetime, b.status,
           b.form_image_path, b.usage_reason, b.usage_type, b.return_image_path,
           COALESCE(u.student_id, b.guest_name) as borrower,
           COALESCE(e.name, s.name) as item_name,
           r.student_id as responsible_name
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.id
    LEFT JOIN equipments e ON b.item_id = e.id AND b.booking_type = 'equipment'
    LEFT JOIN studios s ON b.item_id = s.id AND b.booking_type = 'studio'
    LEFT JOIN users r ON b.responsible_user_id = r.id
    {$where}
    ORDER BY b.created_at DESC
    LIMIT 300
";
$bookings = $pdo->query($query)->fetchAll();

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bookings_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID','Type','Item','Borrower','Responsible','Reason','Start','End','Status']);
    foreach ($bookings as $b) {
        fputcsv($output, [$b['id'],$b['booking_type'],$b['item_name'],$b['borrower'],$b['responsible_name']??'-',$b['usage_reason'],$b['start_datetime'],$b['end_datetime'],$b['status']]);
    }
    fclose($output);
    exit;
}

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2>จัดการรายการจอง</h2>
    <p>ตรวจสอบ อนุมัติ หรือปฏิเสธคำขอการใช้งาน</p>
</div>

<div class="flex-between" style="margin-bottom:1.2rem;">
    <div class="filter-bar">
        <a href="bookings.php" class="btn <?php echo $filter==='all'?'btn-primary':'btn-outline'; ?> btn-sm">ทั้งหมด</a>
        <a href="bookings.php?filter=pending" class="btn <?php echo $filter==='pending'?'btn-primary':'btn-outline'; ?> btn-sm">รอตรวจสอบ</a>
        <a href="bookings.php?filter=approved" class="btn <?php echo $filter==='approved'?'btn-primary':'btn-outline'; ?> btn-sm">อนุมัติแล้ว</a>
        <a href="bookings.php?filter=pending_return" class="btn <?php echo $filter==='pending_return'?'btn-primary':'btn-outline'; ?> btn-sm" style="<?php echo $filter==='pending_return'?'':'color:var(--warning);border-color:var(--warning);'; ?>">รอตรวจคืน</a>
        <a href="bookings.php?filter=returned" class="btn <?php echo $filter==='returned'?'btn-primary':'btn-outline'; ?> btn-sm">คืนแล้ว</a>
        <a href="bookings.php?filter=rejected" class="btn <?php echo $filter==='rejected'?'btn-primary':'btn-outline'; ?> btn-sm">ปฏิเสธแล้ว</a>
    </div>
    <a href="bookings.php?export=csv" class="btn btn-outline btn-sm"><i class="ph-bold ph-download-simple"></i> ส่งออก CSV</a>
</div>

<?php if($success): ?><div class="alert alert-success"><i class="ph-bold ph-check-circle"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger"><i class="ph-bold ph-warning-circle"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<!-- Desktop Table -->
<div class="glass-card desktop-table" style="padding:1rem;">
    <p class="table-scroll-hint"><i class="ph ph-arrow-left"></i> เลื่อนดูข้อมูลเพิ่มเติม <i class="ph ph-arrow-right"></i></p>
    <div class="table-responsive">
        <table class="glass-table">
            <thead><tr>
                <th>ID</th><th>ประเภท</th><th>รายการ</th><th>ผู้จอง</th><th>ผู้รับผิดชอบ</th><th>ระยะเวลา</th><th>หลักฐาน</th><th>สถานะ</th><th>จัดการ</th>
            </tr></thead>
            <tbody>
            <?php foreach($bookings as $b): ?>
                <?php
                    $label='รอตรวจสอบ';$badgeClass='badge-pending';
                    if($b['status']=='approved'){$label='อนุมัติแล้ว';$badgeClass='badge-approved';}
                    if($b['status']=='rejected'){$label='ปฏิเสธแล้ว';$badgeClass='badge-rejected';}
                    if($b['status']=='returned'){$label='คืนแล้ว';$badgeClass='badge-returned';}
                    if($b['status']=='cancelled'){$label='ยกเลิก';$badgeClass='badge-cancelled';}
                    if($b['status']=='pending_return'){$label='รอตรวจคืน';$badgeClass='badge-pending';}
                ?>
                <tr>
                    <td><strong>#<?php echo $b['id'];?></strong></td>
                    <td><span class="badge" style="background:rgba(0,0,0,.04);"><?php echo $b['booking_type']==='equipment'?'📦 อุปกรณ์':'🎬 สตูดิโอ';?></span></td>
                    <td style="font-weight:500;"><?php echo htmlspecialchars($b['item_name']);?></td>
                    <td><?php echo htmlspecialchars($b['borrower']);?></td>
                    <td><?php echo $b['responsible_name'] ? htmlspecialchars($b['responsible_name']) : '<span class="text-muted">-</span>'; ?></td>
                    <td style="font-size:.78rem;white-space:nowrap;">
                        <div style="color:var(--success);"><?php echo date('d M, H:i',strtotime($b['start_datetime']));?></div>
                        <div style="color:var(--danger);"><?php echo date('d M, H:i',strtotime($b['end_datetime']));?></div>
                    </td>
                    <td>
                        <?php if($b['form_image_path']):?><a href="../uploads/booking_forms/<?php echo $b['form_image_path'];?>" target="_blank" class="btn btn-outline btn-sm" style="padding:.3rem .5rem;"><i class="ph-bold ph-eye"></i></a><?php endif;?>
                        <?php if(!empty($b['return_image_path'])):?><a href="../uploads/returns/<?php echo $b['return_image_path'];?>" target="_blank" class="btn btn-outline btn-sm" style="padding:.3rem .5rem;color:var(--info);border-color:var(--info);"><i class="ph-bold ph-image"></i></a><?php endif;?>
                        <?php if(!$b['form_image_path'] && empty($b['return_image_path'])):?><span class="text-muted">-</span><?php endif;?>
                    </td>
                    <td><span class="badge <?php echo $badgeClass;?>"><?php echo $label;?></span></td>
                    <td>
                        <?php if($b['status']==='pending'):?>
                            <div style="display:flex;gap:.3rem;">
                                <form method="POST"><?php echo csrf_input();?><input type="hidden" name="booking_id" value="<?php echo $b['id'];?>"><button name="action" value="approve" class="btn btn-success btn-sm" style="padding:.35rem .6rem;"><i class="ph-bold ph-check"></i></button></form>
                                <form method="POST"><?php echo csrf_input();?><input type="hidden" name="booking_id" value="<?php echo $b['id'];?>"><button name="action" value="reject" class="btn btn-danger btn-sm" style="padding:.35rem .6rem;"><i class="ph-bold ph-x"></i></button></form>
                            </div>
                        <?php elseif(($b['status']==='approved' || $b['status']==='pending_return') && $b['booking_type']==='equipment'):?>
                            <button class="btn btn-outline btn-sm" style="color:var(--info);border-color:var(--info);" onclick="openReturnModal(<?php echo $b['id'];?>)"><i class="ph-bold ph-arrow-counter-clockwise"></i> คืน</button>
                        <?php else:?><span class="text-muted">—</span><?php endif;?>
                    </td>
                </tr>
            <?php endforeach;?>
            <?php if(empty($bookings)):?><tr><td colspan="9" class="text-center text-muted" style="padding:3rem;">ไม่พบรายการจอง</td></tr><?php endif;?>
            </tbody>
        </table>
    </div>
</div>

<!-- Mobile Cards -->
<div class="mobile-cards">
    <?php foreach($bookings as $b):?>
        <?php
            $label='รอตรวจสอบ';$badgeClass='badge-pending';
            if($b['status']=='approved'){$label='อนุมัติแล้ว';$badgeClass='badge-approved';}
            if($b['status']=='rejected'){$label='ปฏิเสธแล้ว';$badgeClass='badge-rejected';}
            if($b['status']=='returned'){$label='คืนแล้ว';$badgeClass='badge-returned';}
            if($b['status']=='cancelled'){$label='ยกเลิก';$badgeClass='badge-cancelled';}
            if($b['status']=='pending_return'){$label='รอตรวจคืน';$badgeClass='badge-pending';}
        ?>
        <div class="mobile-card">
            <div class="mc-header"><strong>#<?php echo $b['id'];?> — <?php echo htmlspecialchars($b['item_name']);?></strong><span class="badge <?php echo $badgeClass;?>"><?php echo $label;?></span></div>
            <div class="mc-row"><span class="mc-label">ประเภท</span><span><?php echo $b['booking_type']==='equipment'?'📦 อุปกรณ์':'🎬 สตูดิโอ';?></span></div>
            <div class="mc-row"><span class="mc-label">ผู้จอง</span><span><?php echo htmlspecialchars($b['borrower']);?></span></div>
            <?php if($b['responsible_name']):?><div class="mc-row"><span class="mc-label">ผู้รับผิดชอบ</span><span><?php echo htmlspecialchars($b['responsible_name']);?></span></div><?php endif;?>
            <div class="mc-row"><span class="mc-label">เริ่ม</span><span style="color:var(--success);font-size:.85rem;"><?php echo date('d M Y, H:i',strtotime($b['start_datetime']));?></span></div>
            <div class="mc-row"><span class="mc-label">สิ้นสุด</span><span style="color:var(--danger);font-size:.85rem;"><?php echo date('d M Y, H:i',strtotime($b['end_datetime']));?></span></div>
            <?php if($b['status']==='pending'):?>
            <div class="mc-actions">
                <form method="POST" style="flex:1"><?php echo csrf_input();?><input type="hidden" name="booking_id" value="<?php echo $b['id'];?>"><button name="action" value="approve" class="btn btn-success btn-sm w-100"><i class="ph-bold ph-check"></i> อนุมัติ</button></form>
                <form method="POST" style="flex:1"><?php echo csrf_input();?><input type="hidden" name="booking_id" value="<?php echo $b['id'];?>"><button name="action" value="reject" class="btn btn-danger btn-sm w-100"><i class="ph-bold ph-x"></i> ปฏิเสธ</button></form>
            </div>
            <?php elseif(($b['status']==='approved' || $b['status']==='pending_return') && $b['booking_type']==='equipment'):?>
            <div class="mc-actions">
                <button class="btn btn-outline btn-sm w-100" style="color:var(--info);border-color:var(--info);" onclick="openReturnModal(<?php echo $b['id'];?>)"><i class="ph-bold ph-arrow-counter-clockwise"></i> คืนพร้อมหลักฐาน</button>
            </div>
            <?php endif;?>
        </div>
    <?php endforeach;?>
    <?php if(empty($bookings)):?><div class="empty-state"><i class="ph ph-calendar-blank"></i><p class="text-muted">ไม่พบรายการจอง</p></div><?php endif;?>
</div>

<!-- Return Modal -->
<div id="returnModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="adminReturnTitle">
    <div class="glass-card modal-inner" style="max-width:450px;">
        <button onclick="closeReturnModal()" aria-label="ปิด" style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--text-muted);min-width:44px;min-height:44px;display:flex;align-items:center;justify-content:center;"><i class="ph-bold ph-x"></i></button>
        <h3 id="adminReturnTitle" style="font-size:1.1rem;margin-bottom:1rem;"><i class="ph-bold ph-camera"></i> คืนอุปกรณ์พร้อมหลักฐาน</h3>
        <form method="POST" enctype="multipart/form-data" id="returnForm">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="booking_id" id="return_booking_id" value="">
            <input type="hidden" name="action" value="return">
            <div class="form-group">
                <label><i class="ph ph-image"></i> ถ่ายรูปอุปกรณ์ตอนคืน</label>
                <div class="upload-zone">
                    <i class="ph-bold ph-camera"></i>
                    <p>ถ่ายรูปอุปกรณ์เพื่อยืนยันสภาพ</p>
                    <input type="file" name="return_image" accept="image/*">
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100"><i class="ph-bold ph-check-circle"></i> ยืนยันการคืน</button>
        </form>
    </div>
</div>

<script>
/* BUG-022 fix: ต้องรอ DOM load ครบก่อน — #bottom-nav อยู่ใน footer.php ซึ่ง render หลัง script นี้ */
var _bn = null;
document.addEventListener('DOMContentLoaded', function(){ _bn = document.getElementById('bottom-nav'); });

function openReturnModal(bookingId) {
    document.getElementById('return_booking_id').value = bookingId;
    window.scrollTo(0, 0); /* BUG-024 fix: ใช้ legacy form รองรับ Safari/Android เก่า */
    document.getElementById('returnModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    if (_bn) { _bn.style.pointerEvents = 'none'; _bn.style.visibility = 'hidden'; }
}
function closeReturnModal() {
    document.getElementById('returnModal').classList.remove('open');
    document.body.style.overflow = '';
    if (_bn) { _bn.style.pointerEvents = ''; _bn.style.visibility = ''; }
}
document.addEventListener('DOMContentLoaded', function(){
    document.getElementById('returnModal').addEventListener('click', function(e) {
        if (e.target === this) closeReturnModal();
    });
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeReturnModal(); });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
