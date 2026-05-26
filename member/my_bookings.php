<?php
// member/my_bookings.php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    }
    $booking_id = intval($_POST['booking_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (!$error && $booking_id > 0 && $action === 'cancel') {
        $checkStmt = $pdo->prepare("SELECT id FROM bookings WHERE id = ? AND user_id = ? AND status = 'pending'");
        $checkStmt->execute([$booking_id, $user_id]);
        if ($checkStmt->fetch()) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")->execute([$booking_id]);
                $pdo->prepare("INSERT INTO feeds (booking_id, message) VALUES (?, ?)")->execute([$booking_id, "ยกเลิกคำขอจอง ❌ (โดยผู้ใช้)"]);
                $pdo->commit();
                $success = "ยกเลิกการจองเรียบร้อยแล้ว";
            } catch (Exception $e) { $pdo->rollBack(); $error = "เกิดข้อผิดพลาด กรุณาลองใหม่"; }
        } else { $error = "ไม่พบรายการหรือไม่สามารถยกเลิกได้"; }
    } elseif (!$error && $booking_id > 0 && $action === 'return') {
        // Member returns equipment with photo evidence
        $return_image = null;
        if (isset($_FILES['return_image']) && $_FILES['return_image']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['return_image']['size'] > 8 * 1024 * 1024) {
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

        /* FIX: skip DB when upload pre-check failed (e.g. file too large) */
        if (empty($error)):
        $checkStmt = $pdo->prepare("SELECT id FROM bookings WHERE id = ? AND user_id = ? AND booking_type = 'equipment' AND status IN ('approved','pending_return')");
        $checkStmt->execute([$booking_id, $user_id]);
        if ($checkStmt->fetch()) {
            try {
                $pdo->beginTransaction();
                if ($return_image) {
                    $pdo->prepare("UPDATE bookings SET status = 'pending_return', return_image_path = ? WHERE id = ?")->execute([$return_image, $booking_id]);
                } else {
                    $pdo->prepare("UPDATE bookings SET status = 'pending_return' WHERE id = ?")->execute([$booking_id]);
                }
                $feedReturnMsg = $return_image ? "ส่งคืนอุปกรณ์ 📦 พร้อมหลักฐาน — รอ admin ตรวจสอบ" : "ส่งคืนอุปกรณ์ 📦 (ไม่มีรูป) — รอ admin ตรวจสอบ";
                $pdo->prepare("INSERT INTO feeds (booking_id, message) VALUES (?, ?)")->execute([$booking_id, $feedReturnMsg]);
                $pdo->commit();
                $success = "ส่งคืนเรียบร้อย! รอผู้ดูแลระบบตรวจสอบ";
            } catch (Exception $e) { $pdo->rollBack(); $error = "เกิดข้อผิดพลาด กรุณาลองใหม่"; }
        } else { $error = "ไม่พบรายการนี้หรือไม่สามารถคืนได้"; }
        endif; // empty($error) guard
    }
}

$query = "
    SELECT b.id, b.booking_type, b.start_datetime, b.end_datetime, b.status, b.return_image_path,
           COALESCE(e.name, s.name) as item_name,
           r.student_id as responsible_name
    FROM bookings b
    LEFT JOIN equipments e ON b.item_id = e.id AND b.booking_type = 'equipment'
    LEFT JOIN studios s ON b.item_id = s.id AND b.booking_type = 'studio'
    LEFT JOIN users r ON b.responsible_user_id = r.id
    WHERE b.user_id = ?
    ORDER BY b.created_at DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$my_bookings = $stmt->fetchAll();

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-container">
    <div class="page-header">
        <h2>การจองของฉัน</h2>
        <p>ประวัติการยืมอุปกรณ์และจองสตูดิโอ</p>
    </div>

    <?php if($success):?><div class="alert alert-success"><i class="ph-bold ph-check-circle"></i> <?php echo $success;?></div><?php endif;?>
    <?php if($error):?><div class="alert alert-danger"><i class="ph-bold ph-warning-circle"></i> <?php echo htmlspecialchars($error);?></div><?php endif;?>

    <!-- Desktop Table -->
    <div class="glass-card desktop-table" style="padding:1rem;">
        <p class="table-scroll-hint"><i class="ph ph-arrow-left"></i> เลื่อนดูข้อมูลเพิ่มเติม <i class="ph ph-arrow-right"></i></p>
        <div class="table-responsive">
            <table class="glass-table">
                <thead><tr><th>ID</th><th>ประเภท</th><th>รายการ</th><th>ผู้รับผิดชอบ</th><th>ระยะเวลา</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
                <tbody>
                <?php foreach($my_bookings as $b): ?>
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
                        <td><span class="badge" style="background:rgba(0,0,0,.04);"><?php echo $b['booking_type']==='equipment'?'📦':'🎬';?></span></td>
                        <td style="font-weight:500;"><?php echo htmlspecialchars($b['item_name']);?></td>
                        <td><?php echo $b['responsible_name']?htmlspecialchars($b['responsible_name']):'<span class="text-muted">-</span>';?></td>
                        <td style="font-size:.8rem;">
                            <div style="color:var(--success);"><?php echo date('d M, H:i',strtotime($b['start_datetime']));?></div>
                            <div style="color:var(--danger);"><?php echo date('d M, H:i',strtotime($b['end_datetime']));?></div>
                        </td>
                        <td><span class="badge <?php echo $badgeClass;?>"><?php echo $label;?></span></td>
                        <td>
                            <?php if($b['status']==='pending'):?>
                                <form method="POST"><input type="hidden" name="booking_id" value="<?php echo $b['id'];?>"><input type="hidden" name="action" value="cancel"><?php echo csrf_input();?>
                                    <button class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger);padding:.3rem .5rem;"><i class="ph-bold ph-x-circle"></i></button></form>
                            <?php elseif($b['status']==='approved' && $b['booking_type']==='equipment'):?>
                                <button class="btn btn-outline btn-sm" style="color:var(--info);border-color:var(--info);" onclick="openReturnModal(<?php echo $b['id'];?>)"><i class="ph-bold ph-camera"></i> คืน</button>
                            <?php else:?><span class="text-muted">—</span><?php endif;?>
                        </td>
                    </tr>
                <?php endforeach;?>
                <?php if(empty($my_bookings)):?><tr><td colspan="7" class="text-center text-muted" style="padding:3rem;">คุณยังไม่เคยทำรายการจอง</td></tr><?php endif;?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Mobile Cards -->
    <div class="mobile-cards">
        <?php foreach($my_bookings as $b):?>
            <?php
                $label='รอตรวจสอบ';$badgeClass='badge-pending';
                if($b['status']=='approved'){$label='อนุมัติแล้ว';$badgeClass='badge-approved';}
                if($b['status']=='rejected'){$label='ปฏิเสธแล้ว';$badgeClass='badge-rejected';}
                if($b['status']=='returned'){$label='คืนแล้ว';$badgeClass='badge-returned';}
                if($b['status']=='cancelled'){$label='ยกเลิก';$badgeClass='badge-cancelled';}
                if($b['status']=='pending_return'){$label='รอตรวจคืน';$badgeClass='badge-pending';}
            ?>
            <div class="mobile-card animate-in">
                <div class="mc-header"><strong>#<?php echo $b['id'];?> — <?php echo htmlspecialchars($b['item_name']);?></strong><span class="badge <?php echo $badgeClass;?>"><?php echo $label;?></span></div>
                <div class="mc-row"><span class="mc-label">ประเภท</span><span><?php echo $b['booking_type']==='equipment'?'📦 อุปกรณ์':'🎬 สตูดิโอ';?></span></div>
                <?php if($b['responsible_name']):?><div class="mc-row"><span class="mc-label">ผู้รับผิดชอบ</span><span><?php echo htmlspecialchars($b['responsible_name']);?></span></div><?php endif;?>
                <div class="mc-row"><span class="mc-label">เริ่ม</span><span style="color:var(--success);font-size:.85rem;"><?php echo date('d M Y, H:i',strtotime($b['start_datetime']));?></span></div>
                <div class="mc-row"><span class="mc-label">สิ้นสุด</span><span style="color:var(--danger);font-size:.85rem;"><?php echo date('d M Y, H:i',strtotime($b['end_datetime']));?></span></div>
                <?php if($b['status']==='pending'):?>
                <div class="mc-actions">
                    <form method="POST" style="flex:1"><input type="hidden" name="booking_id" value="<?php echo $b['id'];?>"><input type="hidden" name="action" value="cancel"><?php echo csrf_input();?>
                        <button class="btn btn-outline btn-sm w-100" style="color:var(--danger);border-color:var(--danger);"><i class="ph-bold ph-x-circle"></i> ยกเลิก</button></form>
                </div>
                <?php elseif($b['status']==='approved' && $b['booking_type']==='equipment'):?>
                <div class="mc-actions">
                    <button class="btn btn-outline btn-sm w-100" style="color:var(--info);border-color:var(--info);" onclick="openReturnModal(<?php echo $b['id'];?>)"><i class="ph-bold ph-camera"></i> คืนพร้อมหลักฐาน</button>
                </div>
                <?php endif;?>
            </div>
        <?php endforeach;?>
        <?php if(empty($my_bookings)):?><div class="empty-state"><i class="ph ph-calendar-blank"></i><p class="text-muted">คุณยังไม่เคยทำรายการจอง</p></div><?php endif;?>
    </div>
</div>

<!-- Return Modal -->
<div id="returnModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="returnModalTitle">
    <div class="glass-card modal-inner" style="max-width:420px;">
        <button onclick="closeReturnModal()" aria-label="ปิด" style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--text-muted);min-width:44px;min-height:44px;display:flex;align-items:center;justify-content:center;"><i class="ph-bold ph-x"></i></button>
        <h3 id="returnModalTitle" style="font-size:1.1rem;margin-bottom:1rem;"><i class="ph-bold ph-camera"></i> คืนอุปกรณ์</h3>
        <p style="font-size:.88rem;color:var(--text-secondary);margin-bottom:1rem;">ถ่ายรูปอุปกรณ์เพื่อยืนยันสภาพก่อนส่งคืน</p>
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="booking_id" id="return_booking_id" value="">
            <input type="hidden" name="action" value="return">
            <div class="form-group">
                <div class="upload-zone">
                    <i class="ph-bold ph-camera" style="font-size:2.5rem;"></i>
                    <p>ถ่ายรูปอุปกรณ์ หรือเลือกจากคลัง</p>
                    <input type="file" name="return_image" accept="image/*">
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100"><i class="ph-bold ph-check-circle"></i> ส่งคืนอุปกรณ์</button>
        </form>
    </div>
</div>

<script>
var _bn = null;
document.addEventListener('DOMContentLoaded', function(){ _bn = document.getElementById('bottom-nav'); });

function openReturnModal(id){
    document.getElementById('return_booking_id').value=id;
    window.scrollTo(0,0);
    document.getElementById('returnModal').classList.add('open');
    document.body.style.overflow='hidden';
    if (_bn){ _bn.style.pointerEvents='none'; _bn.style.visibility='hidden'; }
}
function closeReturnModal(){
    document.getElementById('returnModal').classList.remove('open');
    document.body.style.overflow='';
    if (_bn){ _bn.style.pointerEvents=''; _bn.style.visibility=''; }
}
document.addEventListener('DOMContentLoaded',function(){
    document.getElementById('returnModal').addEventListener('click',function(e){if(e.target===this)closeReturnModal();});
});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeReturnModal();});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
