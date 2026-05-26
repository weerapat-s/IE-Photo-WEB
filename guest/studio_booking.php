<?php
// guest/studio_booking.php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../includes/email_templates.php';

$error = '';
$success = '';

// Fetch available studios — ใช้ 'available' ตรงกับ DB schema
$stmt = $pdo->query("SELECT id, name FROM studios WHERE status = 'available' ORDER BY name ASC");
$studios = $stmt->fetchAll();

// Pre-fill from session if logged in
$prefill_name = '';
$prefill_email = '';
if (isset($_SESSION['user_id'])) {
    $userStmt = $pdo->prepare("SELECT student_id, email FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userData = $userStmt->fetch();
    if ($userData) {
        $prefill_name = $userData['student_id'];
        $prefill_email = $userData['email'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── CSRF ─────────────────────────────────────────────────────────────────
    if (!csrf_verify()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $studio_id    = intval($_POST['studio_id'] ?? 0);
        $guest_name   = trim($_POST['guest_name'] ?? '');
        $guest_email  = trim($_POST['guest_email'] ?? '');
        $usage_reason = trim($_POST['usage_reason'] ?? '');
        $usage_type   = trim($_POST['usage_type'] ?? '');
        $start_datetime = $_POST['start_datetime'] ?? '';
        $end_datetime   = $_POST['end_datetime'] ?? '';

        // ── Input validation ──────────────────────────────────────────────
        if (empty($guest_name) || empty($guest_email) || $studio_id <= 0
            || empty($start_datetime) || empty($end_datetime) || empty($usage_reason)) {
            $error = 'กรุณากรอกข้อมูลให้ครบถ้วนทุกช่อง';
        } elseif (!filter_var($guest_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'รูปแบบอีเมลไม่ถูกต้อง';
        } elseif (strtotime($start_datetime) < time() - 3600) {
            $error = 'ไม่สามารถจองวันเวลาในอดีตได้';
        } elseif (strtotime($start_datetime) >= strtotime($end_datetime)) {
            $error = 'เวลาสิ้นสุดต้องอยู่หลังเวลาเริ่มต้น';
        } else {
            // ── Conflict check ────────────────────────────────────────────
            $cStmt = $pdo->prepare("
                SELECT id FROM bookings
                WHERE item_id = ? AND booking_type = 'studio'
                  AND status IN ('pending','approved')
                  AND start_datetime < ? AND end_datetime > ?
            ");
            $cStmt->execute([$studio_id, $end_datetime, $start_datetime]);
            if ($cStmt->fetch()) {
                $error = 'สตูดิโอนี้ถูกจองในช่วงเวลาดังกล่าวแล้ว กรุณาเลือกเวลาอื่น';
            }
        }

        // ── Insert ────────────────────────────────────────────────────────
        if (!$error && $studio_id > 0) {
            $user_id = $_SESSION['user_id'] ?? null;
            try {
                $insert = $pdo->prepare("
                    INSERT INTO bookings
                        (booking_type, item_id, user_id, guest_name, guest_email,
                         usage_reason, usage_type, start_datetime, end_datetime, status)
                    VALUES ('studio', ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                $insert->execute([$studio_id, $user_id, $guest_name, $guest_email,
                                  $usage_reason, $usage_type, $start_datetime, $end_datetime]);
                $success = 'ส่งคำขอจองสตูดิโอเรียบร้อยแล้ว! รอการอนุมัติจากผู้ดูแลระบบ';

                // Notify admins (non-critical — wrap separately)
                try {
                    $studioStmt = $pdo->prepare("SELECT name FROM studios WHERE id = ?");
                    $studioStmt->execute([$studio_id]);
                    $studioName = $studioStmt->fetchColumn();
                    $emailBody  = getBookingPendingEmailTemplate($guest_name, $studioName, 'studio');
                    sendEmailToAllAdmins($pdo, "IE-Photo: คำขอจองสตูดิโอใหม่จาก {$guest_name}", $emailBody);
                } catch (Exception $eEmail) {
                    error_log("Studio booking email error: " . $eEmail->getMessage());
                }
            } catch (Exception $e) {
                error_log("Studio booking insert error: " . $e->getMessage());
                $error = 'เกิดข้อผิดพลาดในการส่งคำขอ โปรดลองอีกครั้ง';
            }
        }
    } // end csrf/input block
}

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-container">
    <div class="page-header">
        <h2>จองการใช้งานห้องสตูดิโอ</h2>
        <p>เลือกสตูดิโอและวันเวลาที่ต้องการด้านล่าง</p>
    </div>

    <div class="studio-booking-grid">
        <!-- Studio Previews -->
        <div class="glass-card animate-in">
            <h3 style="font-size:1.1rem;margin-bottom:1.2rem;"><i class="ph-bold ph-images"></i> ตัวอย่างห้องสตูดิโอ</h3>
            <div style="display:flex;flex-direction:column;gap:1rem;">
                <div style="border-radius:var(--radius-sm);overflow:hidden;border:1px solid var(--border);">
                    <div style="height:140px;background:linear-gradient(135deg,#1a1a2e,#16213e);display:flex;align-items:center;justify-content:center;">
                        <i class="ph-bold ph-lamp" style="font-size:3rem;color:rgba(255,255,255,.3)"></i>
                    </div>
                    <div style="padding:.8rem;">
                        <h4 style="margin:0;font-size:.95rem;">Studio 1: Professional Lighting</h4>
                        <p style="font-size:.82rem;color:var(--text-secondary);margin:.3rem 0 0;">อุปกรณ์ไฟครบครัน เหมาะสำหรับงาน Portrait และ Fashion</p>
                    </div>
                </div>
                <div style="border-radius:var(--radius-sm);overflow:hidden;border:1px solid var(--border);">
                    <div style="height:140px;background:linear-gradient(135deg,#f5f0e8,#e8dcc8);display:flex;align-items:center;justify-content:center;">
                        <i class="ph-bold ph-plant" style="font-size:3rem;color:rgba(0,0,0,.15)"></i>
                    </div>
                    <div style="padding:.8rem;">
                        <h4 style="margin:0;font-size:.95rem;">Studio 2: Creative Minimal</h4>
                        <p style="font-size:.82rem;color:var(--text-secondary);margin:.3rem 0 0;">เน้นแสงธรรมชาติ เหมาะสำหรับงาน Product และ MV</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Booking Form -->
        <div class="glass-card animate-in">
            <h3 style="font-size:1.1rem;margin-bottom:1.2rem;"><i class="ph-bold ph-notepad"></i> ข้อมูลการจอง</h3>

            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="ph-bold ph-warning-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="ph-bold ph-check-circle"></i> <?php echo $success; ?></div>
            <?php endif; ?>

            <form method="POST" action="studio_booking.php">
                <?php echo csrf_input(); ?>
                <div class="form-group">
                    <label for="guest_name"><i class="ph ph-user"></i> ชื่อ-นามสกุล</label>
                    <input type="text" id="guest_name" name="guest_name" class="form-control" placeholder="ระบุชื่อจริง" required value="<?php echo htmlspecialchars($prefill_name); ?>">
                </div>
                <div class="form-group">
                    <label for="guest_email"><i class="ph ph-envelope"></i> อีเมลติดต่อกลับ</label>
                    <input type="email" id="guest_email" name="guest_email" class="form-control" placeholder="example@email.com" required value="<?php echo htmlspecialchars($prefill_email); ?>">
                </div>
                <div class="form-group">
                    <label for="studio_id"><i class="ph ph-video-camera"></i> เลือกห้องสตูดิโอ</label>
                    <select id="studio_id" name="studio_id" class="form-control" required>
                        <option value="">-- กรุณาเลือกสตูดิโอ --</option>
                        <?php foreach ($studios as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="usage_type"><i class="ph ph-tag"></i> ประเภทการใช้งาน</label>
                    <select id="usage_type" name="usage_type" class="form-control" required>
                        <option value="Project เรียน">📚 Project เรียน</option>
                        <option value="งานส่วนตัว">🎨 งานส่วนตัว</option>
                        <option value="กิจกรรมชุมนุม">🎭 กิจกรรมชุมนุม</option>
                        <option value="อื่นๆ">📋 อื่นๆ</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="usage_reason"><i class="ph ph-text-align-left"></i> เหตุผลการเข้าใช้งาน</label>
                    <textarea id="usage_reason" name="usage_reason" class="form-control" rows="3" placeholder="ระบุวัตถุประสงค์ในการใช้งาน" required></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="start_datetime"><i class="ph ph-calendar"></i> วันเวลาเริ่มต้น</label>
                        <input type="datetime-local" id="start_datetime" name="start_datetime" class="form-control" required min="<?php echo date('Y-m-d\TH:i'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="end_datetime"><i class="ph ph-calendar-check"></i> วันเวลาสิ้นสุด</label>
                        <input type="datetime-local" id="end_datetime" name="end_datetime" class="form-control" required min="<?php echo date('Y-m-d\TH:i'); ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-2">
                    <i class="ph-bold ph-calendar-check"></i> ส่งคำขอจอง
                </button>
                <p class="text-center mt-3">
                    <?php
                        if (isset($_SESSION['user_id'])) {
                            if ($_SESSION['role'] === 'admin') {
                                $home = '../admin/dashboard.php';
                            } else {
                                $home = '../member/feed.php';
                            }
                        } else {
                            $home = '../auth/login.php';
                        }
                    ?>
                    <a href="<?php echo $home; ?>" style="font-size:.88rem;color:var(--text-muted);"><i class="ph ph-arrow-left"></i> กลับไปหน้าหลัก</a>
                </p>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>