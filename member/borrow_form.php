<?php
// member/borrow_form.php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Get available equipments
$stmt = $pdo->query("SELECT id, name, type FROM equipments WHERE status = 'available' ORDER BY type, name");
$equipments = $stmt->fetchAll();

// Get members for "responsible person" dropdown
// Admin can see all members; regular members only see themselves
$is_admin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'super_admin']);
if ($is_admin) {
    $memberStmt = $pdo->query("SELECT id, student_id, first_name, last_name FROM users ORDER BY student_id ASC");
    $members = $memberStmt->fetchAll();
} else {
    $memberStmt = $pdo->prepare("SELECT id, student_id, first_name, last_name FROM users WHERE id = ?");
    $memberStmt->execute([$user_id]);
    $members = $memberStmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Initialize variables (กัน undefined variable warning) ───────────────
    $item_ids            = [];
    $start_datetime      = '';
    $end_datetime        = '';
    $responsible_user_id = $user_id;
    $form_image          = '';

    // ── CSRF ─────────────────────────────────────────────────────────────────
    if (!csrf_verify()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $item_ids            = $_POST['item_ids'] ?? [];
        $start_datetime      = $_POST['start_datetime'] ?? '';
        $end_datetime        = $_POST['end_datetime'] ?? '';
        $responsible_user_id = intval($_POST['responsible_user_id'] ?? $user_id);

        // Security: non-admin members can only set themselves as responsible
        if (!$is_admin) { $responsible_user_id = $user_id; }

        // ── File upload — ตรวจสอบ MIME + ขนาด ────────────────────────────
        if (isset($_FILES['form_image']) && $_FILES['form_image']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['form_image']['size'] > 8 * 1024 * 1024) {
                $error = 'ไฟล์ใหญ่เกิน 8 MB กรุณาเลือกไฟล์ขนาดเล็กกว่า';
            } else {
                $tmp_name = $_FILES['form_image']['tmp_name'];
                $ext = strtolower(pathinfo(basename($_FILES['form_image']['name']), PATHINFO_EXTENSION));
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $tmp_name);
                finfo_close($finfo);
                $allowedMimes = ['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'];
                if (in_array($ext, ['jpg','jpeg','png','pdf']) && isset($allowedMimes[$mime])) {
                    $fname = 'booking_' . time() . '_' . $user_id . '.' . $allowedMimes[$mime];
                    $upload_dir = __DIR__ . '/../uploads/booking_forms/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    if (move_uploaded_file($tmp_name, $upload_dir . $fname)) {
                        $form_image = $fname;
                    } else {
                        $error = 'อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่';
                    }
                } else {
                    $error = 'รูปแบบไฟล์ไม่รองรับ (รองรับ JPG, PNG, PDF เท่านั้น)';
                }
            }
        } elseif (isset($_FILES['form_image']) && $_FILES['form_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $error = 'เกิดปัญหาในการอัปโหลดไฟล์ (error code: ' . (int)$_FILES['form_image']['error'] . ')';
        }
    } // end csrf/input block

    if (!$error) {
        if (empty($item_ids) || empty($start_datetime) || empty($end_datetime) || empty($form_image)) {
            $error = 'กรุณาเลือกอุปกรณ์อย่างน้อย 1 ชิ้น กรอกวันเวลา และแนบเอกสาร';
        } elseif (strtotime($start_datetime) < time() - 3600) {
            $error = 'ไม่สามารถจองวันเวลาในอดีตได้';
        } elseif (strtotime($start_datetime) >= strtotime($end_datetime)) {
            $error = 'เวลาสิ้นสุดต้องอยู่หลังเวลาเริ่มต้น';
        } else {
            // ตรวจสอบ booking conflict — อุปกรณ์ชิ้นนั้นถูกจองซ้อนในช่วงเวลาเดียวกันไหม
            $conflictIds = [];
            foreach ($item_ids as $check_id) {
                $check_id = intval($check_id);
                if ($check_id <= 0) continue;
                $cStmt = $pdo->prepare("
                    SELECT id FROM bookings
                    WHERE item_id = ? AND booking_type = 'equipment'
                      AND status IN ('pending','approved')
                      AND start_datetime < ? AND end_datetime > ?
                ");
                $cStmt->execute([$check_id, $end_datetime, $start_datetime]);
                if ($cStmt->fetch()) {
                    $eqStmt = $pdo->prepare("SELECT name FROM equipments WHERE id = ?");
                    $eqStmt->execute([$check_id]);
                    $conflictIds[] = $eqStmt->fetchColumn();
                }
            }
            if (!empty($conflictIds)) {
                $error = 'อุปกรณ์ต่อไปนี้ถูกจองในช่วงเวลาดังกล่าวแล้ว: ' . implode(', ', $conflictIds);
            }
        }
        if (!$error && !empty($item_ids)) {
            $pdo->beginTransaction();
            try {
                $usrStmt = $pdo->prepare("SELECT student_id FROM users WHERE id = ?");
                $usrStmt->execute([$user_id]);
                $studentId = $usrStmt->fetchColumn();

                $eqNames = [];
                $bookingIds = [];

                foreach ($item_ids as $item_id) {
                    $item_id = intval($item_id);
                    if ($item_id <= 0) continue;

                    // Insert booking for each item
                    $insert = $pdo->prepare("INSERT INTO bookings (booking_type, item_id, user_id, responsible_user_id, start_datetime, end_datetime, form_image_path, status) VALUES ('equipment', ?, ?, ?, ?, ?, ?, 'pending')");
                    $insert->execute([$item_id, $user_id, $responsible_user_id, $start_datetime, $end_datetime, $form_image]);
                    $booking_id = $pdo->lastInsertId();
                    $bookingIds[] = $booking_id;

                    // Get equipment name
                    $eqStmt = $pdo->prepare("SELECT name FROM equipments WHERE id = ?");
                    $eqStmt->execute([$item_id]);
                    $eqName = $eqStmt->fetchColumn();
                    $eqNames[] = $eqName;

                    // Feed entry
                    $feedMsg = "สมาชิก {$studentId} ส่งคำขอยืม 📸 {$eqName} — รอการอนุมัติ";
                    $feedInsert = $pdo->prepare("INSERT INTO feeds (booking_id, message) VALUES (?, ?)");
                    $feedInsert->execute([$booking_id, $feedMsg]);
                }

                $pdo->commit();

                $itemCount = count($eqNames);
                $itemList = implode(', ', $eqNames);
                $success = "ส่งคำขอยืมอุปกรณ์ {$itemCount} ชิ้นเรียบร้อย ({$itemList}) — รอการอนุมัติ";

                // Notify all admins
                require_once __DIR__ . '/../config/mail.php';
                require_once __DIR__ . '/../includes/email_templates.php';
                sendEmailToAllAdmins($pdo,
                    "IE-Photo: คำขอยืมอุปกรณ์ {$itemCount} ชิ้นจาก {$studentId}",
                    getBookingPendingEmailTemplate($studentId, $itemList, 'equipment')
                );
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
    }
}

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-container-md">
    <div class="page-header">
        <h2>ยืมอุปกรณ์ถ่ายภาพ</h2>
        <p>เลือกอุปกรณ์ที่ต้องการ (เลือกได้หลายชิ้น) กำหนดผู้รับผิดชอบ แนบเอกสาร</p>
    </div>

    <div class="glass-card animate-in">
        <?php if($success): ?>
            <div class="alert alert-success"><i class="ph-bold ph-check-circle"></i> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="alert alert-danger"><i class="ph-bold ph-warning-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="borrow_form.php" enctype="multipart/form-data">
            <?php echo csrf_input(); ?>
            <!-- Equipment Multi-Select -->
            <div class="form-group">
                <label><i class="ph-bold ph-camera"></i> เลือกอุปกรณ์ <span style="color:var(--text-muted);font-weight:400;">(เลือกได้หลายชิ้น)</span></label>
                <?php if(empty($equipments)): ?>
                    <div style="padding:1.5rem;text-align:center;background:rgba(239,68,68,.04);border-radius:var(--radius-sm);border:1px dashed var(--danger);">
                        <i class="ph ph-warning" style="font-size:1.5rem;color:var(--danger);"></i>
                        <p style="color:var(--danger);margin:.5rem 0 0;font-size:.9rem;">ไม่มีอุปกรณ์ว่างในขณะนี้</p>
                    </div>
                <?php else: ?>
                    <div id="selected-count" style="font-size:.82rem;color:var(--primary);font-weight:600;margin-bottom:.5rem;display:none;">
                        <i class="ph-bold ph-check-circle"></i> เลือกแล้ว <span id="count-num">0</span> ชิ้น
                    </div>
                    <div class="eq-checklist" style="max-height:280px;overflow-y:auto;border:2px solid #e8ecf0;border-radius:var(--radius-sm);padding:.5rem;">
                        <?php
                        $currentType = '';
                        foreach($equipments as $eq):
                            $typeLabel = $eq['type'] === 'camera' ? '📷 กล้อง' : ($eq['type'] === 'lens' ? '🔍 เลนส์' : '📦 อุปกรณ์เสริม');
                            if ($eq['type'] !== $currentType):
                                $currentType = $eq['type'];
                        ?>
                            <div style="font-size:.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;padding:.5rem .8rem .2rem;margin-top:.3rem;"><?php echo $typeLabel; ?></div>
                        <?php endif; ?>
                        <label class="eq-item" style="display:flex;align-items:center;gap:.7rem;padding:.6rem .8rem;border-radius:8px;cursor:pointer;transition:background .15s;" onmouseover="this.style.background='rgba(242,83,28,.04)'" onmouseout="this.style.background='transparent'">
                            <input type="checkbox" name="item_ids[]" value="<?php echo $eq['id']; ?>" class="eq-checkbox" style="width:18px;height:18px;accent-color:var(--primary);cursor:pointer;">
                            <span style="font-size:.92rem;"><?php echo htmlspecialchars($eq['name']); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Responsible Person -->
            <div class="form-group">
                <label for="responsible_user_id"><i class="ph-bold ph-user-focus"></i> ผู้รับผิดชอบหลัก</label>
                <select id="responsible_user_id" name="responsible_user_id" class="form-control">
                    <?php foreach($members as $m): ?>
                        <option value="<?php echo $m['id']; ?>" <?php echo $m['id'] == $user_id ? 'selected' : ''; ?>>
                            <?php
                                $display = htmlspecialchars($m['student_id']);
                                $fullname = trim($m['first_name'] . ' ' . $m['last_name']);
                                if ($fullname) $display .= ' - ' . htmlspecialchars($fullname);
                                if ($m['id'] == $user_id) $display .= ' (ฉัน)';
                                echo $display;
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted" style="font-size:.8rem;display:block;margin-top:.3rem;"><i class="ph ph-info"></i> ผู้รับผิดชอบกรณีอุปกรณ์เสียหาย<?php echo $is_admin ? ' (แอดมินสามารถเลือกแทนสมาชิกได้)' : ' (เฉพาะตัวคุณเอง)'; ?></small>
            </div>

            <!-- Date/Time -->
            <div class="form-group">
                <label for="start_datetime"><i class="ph-bold ph-calendar"></i> วันเวลาที่ยืม</label>
                <input type="datetime-local" id="start_datetime" name="start_datetime" class="form-control" required
                       min="<?php echo date('Y-m-d\TH:i'); ?>">
            </div>
            <div class="form-group">
                <label for="end_datetime"><i class="ph-bold ph-calendar-check"></i> วันเวลาที่คืน</label>
                <input type="datetime-local" id="end_datetime" name="end_datetime" class="form-control" required
                       min="<?php echo date('Y-m-d\TH:i'); ?>">
                <div id="dt-error" style="display:none;color:var(--danger);font-size:.82rem;margin-top:.3rem;">
                    <i class="ph ph-warning-circle"></i> เวลาคืนต้องอยู่หลังเวลายืม
                </div>
            </div>

            <!-- Document Upload -->
            <div class="form-group">
                <label><i class="ph-bold ph-upload"></i> อัปโหลดเอกสารขออนุญาต</label>
                <div class="upload-zone" id="drop-zone">
                    <i class="ph-bold ph-image"></i>
                    <p>ถ่ายรูป/ลากไฟล์ หรือคลิกเพื่อเลือก</p>
                    <input type="file" id="form_image" name="form_image" required accept="image/*,.pdf">
                    <span id="file-name-display" class="badge" style="background:var(--primary);color:#fff;display:none;margin-top:8px;"></span>
                </div>
                <small class="text-muted" style="font-size:.8rem;display:block;margin-top:.3rem;"><i class="ph ph-info"></i> รองรับ JPG, PNG, PDF</small>
            </div>

            <button type="submit" class="btn btn-primary w-100 mt-2" <?php echo empty($equipments) ? 'disabled' : ''; ?>>
                <i class="ph-bold ph-paper-plane-tilt"></i> ส่งคำขอยืมอุปกรณ์
            </button>
        </form>
    </div>
</div>

<script>
// Track selected count
document.querySelectorAll('.eq-checkbox').forEach(cb => {
    cb.addEventListener('change', () => {
        const checked = document.querySelectorAll('.eq-checkbox:checked').length;
        const display = document.getElementById('selected-count');
        document.getElementById('count-num').textContent = checked;
        display.style.display = checked > 0 ? 'block' : 'none';
    });
});

// File upload feedback
document.getElementById('form_image').addEventListener('change', function() {
    if(this.files && this.files[0]) {
        const d = document.getElementById('file-name-display');
        d.innerText = '📎 ' + this.files[0].name;
        d.style.display = 'inline-block';
    }
});

// Datetime validation — กันเลือก end ก่อน start
(function(){
    var startEl = document.getElementById('start_datetime');
    var endEl   = document.getElementById('end_datetime');
    var errEl   = document.getElementById('dt-error');
    var btnEl   = document.querySelector('button[type="submit"]');

    function nowLocal(){
        var d = new Date(); d.setSeconds(0,0);
        return d.toISOString().slice(0,16);
    }
    startEl.min = nowLocal();

    function validate(){
        var s = startEl.value, e = endEl.value;
        var now = nowLocal();
        if(s && s < now){ startEl.value = now; s = now; }
        if(s) endEl.min = s;
        var invalid = s && e && e <= s;
        errEl.style.display = invalid ? 'flex' : 'none';
        endEl.classList.toggle('is-invalid', !!invalid);
        if(btnEl) btnEl.disabled = !!invalid;
    }
    startEl.addEventListener('change', validate);
    endEl.addEventListener('change', validate);
    setInterval(function(){ startEl.min = nowLocal(); }, 60000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
