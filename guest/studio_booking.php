<?php
// guest/studio_booking.php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../includes/email_templates.php';

$error   = '';
$success = '';
$is_admin_user = isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['admin','super_admin']);

// ─── Auto-migrate: เพิ่ม columns ข้อมูลสตูดิโอถ้ายังไม่มี ───────────────────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM studios")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('subtitle',      $cols)) $pdo->exec("ALTER TABLE studios ADD COLUMN subtitle      VARCHAR(120)  DEFAULT ''");
    if (!in_array('tags',          $cols)) $pdo->exec("ALTER TABLE studios ADD COLUMN tags          VARCHAR(300)  DEFAULT ''");
    if (!in_array('features',      $cols)) $pdo->exec("ALTER TABLE studios ADD COLUMN features      TEXT");
    if (!in_array('open_hours',    $cols)) $pdo->exec("ALTER TABLE studios ADD COLUMN open_hours    VARCHAR(160)  DEFAULT ''");
    if (!in_array('contact_phone', $cols)) $pdo->exec("ALTER TABLE studios ADD COLUMN contact_phone VARCHAR(30)   DEFAULT ''");
    if (!in_array('theme',         $cols)) $pdo->exec("ALTER TABLE studios ADD COLUMN theme         VARCHAR(10)   DEFAULT 'dark'");
} catch (Exception $e) { /* ไม่ critical */ }

// ─── Seed ค่า default ถ้า Studio 1/2 ยังว่างอยู่ ─────────────────────────────
$defaultData = [
    1 => [
        'subtitle'      => 'Professional Lighting',
        'tags'          => '📸 Portrait,👗 Fashion,🎬 MV',
        'features'      => "ชุดไฟ Strobe 4 จุด + Softbox\nพื้นหลัง Seamless ขาว / ดำ / เทา\nขนาดพื้นที่ ~6×8 เมตร\nมีอุปกรณ์เสริม: Reflector, Stand",
        'open_hours'    => 'จ–ศ 08:00–20:00 น. / ส–อา 09:00–17:00 น.',
        'contact_phone' => '096-954-5290',
        'theme'         => 'dark',
    ],
    2 => [
        'subtitle'      => 'Natural Light / Minimal',
        'tags'          => '📦 Product,🌿 Lifestyle,🎥 Vlog',
        'features'      => "หน้าต่างแสงธรรมชาติขนาดใหญ่\nพื้นหลัง White Infinity Cyc Wall\nขนาดพื้นที่ ~5×6 เมตร\nเหมาะงาน minimal, clean-look",
        'open_hours'    => 'จ–ศ 08:00–20:00 น. / ส–อา 09:00–17:00 น.',
        'contact_phone' => '096-954-5290',
        'theme'         => 'light',
    ],
];
foreach ($defaultData as $sid => $d) {
    try {
        $chk = $pdo->prepare("SELECT subtitle FROM studios WHERE id = ?");
        $chk->execute([$sid]);
        $row = $chk->fetch();
        if ($row !== false && ($row['subtitle'] ?? '') === '') {
            $upd = $pdo->prepare("UPDATE studios SET subtitle=?,tags=?,features=?,open_hours=?,contact_phone=?,theme=? WHERE id=?");
            $upd->execute([$d['subtitle'],$d['tags'],$d['features'],$d['open_hours'],$d['contact_phone'],$d['theme'],$sid]);
        }
    } catch (Exception $e) { /* ignore */ }
}

// ─── Fetch studios (booking dropdown = open/available) ───────────────────────
$stmtAll = $pdo->query("SELECT * FROM studios WHERE id IN (1,2) ORDER BY id ASC");
$studioInfo = $stmtAll->fetchAll();

$stmtOpen = $pdo->query("SELECT id, name FROM studios WHERE status IN ('open','available') ORDER BY name ASC");
$studios = $stmtOpen->fetchAll();

// Pre-fill from session
$prefill_name = '';
$prefill_email = '';
if (isset($_SESSION['user_id'])) {
    $userStmt = $pdo->prepare("SELECT student_id, email FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userData = $userStmt->fetch();
    if ($userData) { $prefill_name = $userData['student_id']; $prefill_email = $userData['email']; }
}

// ─── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'save_studio') {
        // ── บันทึกข้อมูลสตูดิโอ (admin เท่านั้น) ──────────────────────────────
        if (!$is_admin_user) {
            $error = 'ไม่มีสิทธิ์แก้ไขข้อมูลสตูดิโอ';
        } else {
            $sid     = intval($_POST['studio_id'] ?? 0);
            $sub     = trim($_POST['subtitle']      ?? '');
            $tags    = trim($_POST['tags']           ?? '');
            $feats   = trim($_POST['features']       ?? '');
            $hours   = trim($_POST['open_hours']     ?? '');
            $phone   = trim($_POST['contact_phone']  ?? '');
            $theme   = in_array($_POST['theme'] ?? '', ['dark','light']) ? $_POST['theme'] : 'dark';
            $status  = in_array($_POST['studio_status'] ?? '', ['open','closed']) ? $_POST['studio_status'] : 'open';

            if ($sid > 0) {
                $upd = $pdo->prepare("UPDATE studios SET subtitle=?,tags=?,features=?,open_hours=?,contact_phone=?,theme=?,status=? WHERE id=?");
                $upd->execute([$sub,$tags,$feats,$hours,$phone,$theme,$status,$sid]);
                $success = 'บันทึกข้อมูลสตูดิโอเรียบร้อย';
                // Reload studio info
                $stmtAll = $pdo->query("SELECT * FROM studios WHERE id IN (1,2) ORDER BY id ASC");
                $studioInfo = $stmtAll->fetchAll();
                $stmtOpen = $pdo->query("SELECT id, name FROM studios WHERE status IN ('open','available') ORDER BY name ASC");
                $studios = $stmtOpen->fetchAll();
            }
        }
    } else {
        // ── จองสตูดิโอ ─────────────────────────────────────────────────────────
        $studio_id    = intval($_POST['studio_id'] ?? 0);
        $guest_name   = trim($_POST['guest_name']   ?? '');
        $guest_email  = trim($_POST['guest_email']  ?? '');
        $usage_reason = trim($_POST['usage_reason'] ?? '');
        $usage_type   = trim($_POST['usage_type']   ?? '');
        $start_datetime = $_POST['start_datetime']  ?? '';
        $end_datetime   = $_POST['end_datetime']    ?? '';

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
            $cStmt = $pdo->prepare("SELECT id FROM bookings WHERE item_id=? AND booking_type='studio' AND status IN ('pending','approved') AND start_datetime<? AND end_datetime>?");
            $cStmt->execute([$studio_id, $end_datetime, $start_datetime]);
            if ($cStmt->fetch()) { $error = 'สตูดิโอนี้ถูกจองในช่วงเวลาดังกล่าวแล้ว กรุณาเลือกเวลาอื่น'; }
        }

        if (!$error && $studio_id > 0) {
            $user_id = $_SESSION['user_id'] ?? null;
            try {
                $insert = $pdo->prepare("INSERT INTO bookings (booking_type,item_id,user_id,guest_name,guest_email,usage_reason,usage_type,start_datetime,end_datetime,status) VALUES ('studio',?,?,?,?,?,?,?,?,'pending')");
                $insert->execute([$studio_id,$user_id,$guest_name,$guest_email,$usage_reason,$usage_type,$start_datetime,$end_datetime]);
                $success = 'ส่งคำขอจองสตูดิโอเรียบร้อยแล้ว! รอการอนุมัติจากผู้ดูแลระบบ';
                try {
                    $studioStmt = $pdo->prepare("SELECT name FROM studios WHERE id=?");
                    $studioStmt->execute([$studio_id]);
                    $studioName = $studioStmt->fetchColumn();
                    sendEmailToAllAdmins($pdo, "IE-Photo: คำขอจองสตูดิโอใหม่จาก {$guest_name}", getBookingPendingEmailTemplate($guest_name, $studioName, 'studio'));
                } catch (Exception $eEmail) { error_log("Studio email: " . $eEmail->getMessage()); }
            } catch (Exception $e) { $error = 'เกิดข้อผิดพลาด โปรดลองอีกครั้ง'; }
        }
    }
}

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';

// ─── Helper: theme config ─────────────────────────────────────────────────────
function studioTheme(string $theme, int $idx): array {
    if ($theme === 'light') return [
        'bg'     => 'linear-gradient(135deg,#f5f0e8 0%,#fde8cc 50%,#f5f0e8 100%)',
        'icon'   => 'ph-bold ph-sun',
        'iconcol'=> '#f59e0b',
        'glow'   => 'rgba(245,158,11,.4)',
        'txtcol' => '#333',
        'border' => 'rgba(59,130,246,.2)',
        'tagbg'  => 'rgba(59,130,246,.08)',
        'tagcol' => 'var(--info)',
    ];
    return [
        'bg'     => 'linear-gradient(135deg,#1a1a2e 0%,#2d1f5e 50%,#1a1a2e 100%)',
        'icon'   => 'ph-bold ph-lamp',
        'iconcol'=> '#f2a93b',
        'glow'   => 'rgba(242,169,59,.5)',
        'txtcol' => '#fff',
        'border' => 'rgba(242,83,28,.2)',
        'tagbg'  => 'rgba(242,83,28,.08)',
        'tagcol' => 'var(--primary)',
    ];
}
?>

<div class="page-container">
    <div class="page-header">
        <h2>จองการใช้งานห้องสตูดิโอ</h2>
        <p>เลือกสตูดิโอและวันเวลาที่ต้องการด้านล่าง</p>
    </div>

    <?php if($success && isset($_POST['action']) && $_POST['action']==='save_studio'): ?>
        <div class="alert alert-success" style="margin-bottom:1rem;"><i class="ph-bold ph-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="studio-booking-grid">

        <!-- ═══ Studio Info Cards ═══════════════════════════════════════════════ -->
        <div class="glass-card animate-in">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;">
                <h3 style="font-size:1.1rem;margin:0;"><i class="ph-bold ph-images"></i> ห้องสตูดิโอของเรา</h3>
                <?php if($is_admin_user): ?>
                <span style="font-size:.75rem;color:var(--text-muted);"><i class="ph ph-pencil"></i> กดไอคอน ✏️ เพื่อแก้ไข</span>
                <?php endif; ?>
            </div>

            <div style="display:flex;flex-direction:column;gap:1.1rem;">
            <?php
            // ข้อมูลสำรองถ้า DB ไม่มีข้อมูล
            $defaultContact = ['open_hours'=>'จ–ศ 08:00–20:00 น.','contact_phone'=>'096-954-5290'];
            $shownHours = $defaultContact;
            foreach ($studioInfo as $idx => $s):
                $th = studioTheme($s['theme'] ?? 'dark', $idx);
                $tagList = array_filter(array_map('trim', explode(',', $s['tags'] ?? '')));
                $featList = array_filter(array_map('trim', explode("\n", $s['features'] ?? '')));
                if (!empty($s['open_hours'])) $shownHours = $s;
            ?>
            <div style="border-radius:var(--radius-sm);overflow:hidden;border:1.5px solid <?php echo $th['border'];?>;">

                <!-- Header gradient -->
                <div style="height:120px;background:<?php echo $th['bg'];?>;display:flex;align-items:center;justify-content:space-between;padding:0 1rem;">
                    <div style="display:flex;align-items:center;gap:.8rem;">
                        <i class="<?php echo $th['icon'];?>" style="font-size:2.4rem;color:<?php echo $th['iconcol'];?>;filter:drop-shadow(0 0 10px <?php echo $th['glow'];?>)"></i>
                        <div style="color:<?php echo $th['txtcol'];?>;">
                            <div style="font-size:1rem;font-weight:700;"><?php echo htmlspecialchars($s['name']); ?></div>
                            <div style="font-size:.75rem;opacity:.7;"><?php echo htmlspecialchars($s['subtitle'] ?? ''); ?></div>
                        </div>
                    </div>
                    <?php if($is_admin_user): ?>
                    <button onclick="openStudioEdit(<?php echo $s['id'];?>)"
                        style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:10px;padding:.35rem .55rem;cursor:pointer;color:#fff;font-size:.85rem;display:flex;align-items:center;gap:.3rem;"
                        title="แก้ไขข้อมูลสตูดิโอนี้">
                        <i class="ph-bold ph-pencil-simple"></i> แก้ไข
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Body -->
                <div style="padding:.9rem;">
                    <?php if(!empty($tagList)): ?>
                    <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.6rem;">
                        <?php foreach($tagList as $tag): ?>
                        <span style="font-size:.72rem;padding:.2rem .55rem;border-radius:20px;background:<?php echo $th['tagbg'];?>;color:<?php echo $th['tagcol'];?>;font-weight:600;"><?php echo htmlspecialchars($tag);?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if(!empty($featList)): ?>
                    <ul style="font-size:.82rem;color:var(--text-secondary);margin:0;padding-left:1.1rem;line-height:1.85;">
                        <?php foreach($featList as $f): ?><li><?php echo htmlspecialchars($f);?></li><?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <!-- สถานะ -->
                    <div style="margin-top:.6rem;">
                        <?php if(in_array($s['status'],['open','available'])): ?>
                        <span style="font-size:.72rem;padding:.2rem .6rem;border-radius:20px;background:rgba(34,197,94,.1);color:var(--success);font-weight:600;"><i class="ph ph-circle-wavy-check"></i> พร้อมใช้งาน</span>
                        <?php else: ?>
                        <span style="font-size:.72rem;padding:.2rem .6rem;border-radius:20px;background:rgba(239,68,68,.08);color:var(--danger);font-weight:600;"><i class="ph ph-prohibit"></i> ปิดให้บริการ</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

                <!-- Contact bar -->
                <div style="background:rgba(242,83,28,.04);border-radius:var(--radius-sm);padding:.8rem;font-size:.82rem;color:var(--text-secondary);">
                    <div><i class="ph ph-clock" style="color:var(--primary);"></i>
                        <strong style="color:var(--text);">เวลาเปิด-ปิด:</strong>
                        <?php echo htmlspecialchars($shownHours['open_hours'] ?? 'จ–ศ 08:00–20:00 น.'); ?>
                    </div>
                    <?php if(!empty($shownHours['contact_phone'])): ?>
                    <div style="margin-top:.3rem;"><i class="ph ph-phone" style="color:var(--primary);"></i>
                        <strong style="color:var(--text);">โทร:</strong>
                        <a href="tel:<?php echo preg_replace('/[^0-9]/','',$shownHours['contact_phone']); ?>" style="color:var(--primary);font-weight:600;">
                            <?php echo htmlspecialchars($shownHours['contact_phone']); ?>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ═══ Booking Form ══════════════════════════════════════════════════════ -->
        <div class="glass-card animate-in">
            <h3 style="font-size:1.1rem;margin-bottom:1.2rem;"><i class="ph-bold ph-notepad"></i> ข้อมูลการจอง</h3>

            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="ph-bold ph-warning-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success && !isset($_POST['action'])): ?>
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
                <div class="form-group">
                    <label for="start_datetime"><i class="ph ph-calendar"></i> วันเวลาเริ่มต้น</label>
                    <input type="datetime-local" id="start_datetime" name="start_datetime" class="form-control" required min="<?php echo date('Y-m-d\TH:i'); ?>">
                </div>
                <div class="form-group">
                    <label for="end_datetime"><i class="ph ph-calendar-check"></i> วันเวลาสิ้นสุด</label>
                    <input type="datetime-local" id="end_datetime" name="end_datetime" class="form-control" required min="<?php echo date('Y-m-d\TH:i'); ?>">
                    <div id="dt-error" style="display:none;color:var(--danger);font-size:.82rem;margin-top:.3rem;">
                        <i class="ph ph-warning-circle"></i> เวลาสิ้นสุดต้องอยู่หลังเวลาเริ่มต้น
                    </div>
                </div>
                <button type="submit" id="booking-submit-btn" class="btn btn-primary w-100 mt-2">
                    <i class="ph-bold ph-calendar-check"></i> ส่งคำขอจอง
                </button>
                <?php
                    if (isset($_SESSION['user_id'])) {
                        $home = in_array($_SESSION['role'] ?? '', ['admin','super_admin']) ? '../admin/dashboard.php' : '../member/feed.php';
                    } else { $home = '../auth/login.php'; }
                ?>
                <a href="<?php echo $home; ?>" class="btn btn-outline w-100 mt-2" style="justify-content:center;">
                    <i class="ph ph-arrow-left"></i> กลับไปหน้าหลัก
                </a>
            </form>
        </div>
    </div>
</div>

<?php if($is_admin_user): ?>
<!-- ═══ Edit Studio Modal (admin only) ══════════════════════════════════════════ -->
<div id="studioEditModal" class="modal-overlay" role="dialog" aria-modal="true" onclick="if(event.target===this)closeStudioEdit()">
    <div class="glass-card modal-inner" style="max-width:520px;max-height:90vh;overflow-y:auto;">
        <button onclick="closeStudioEdit()" aria-label="ปิด"
            style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--text-muted);min-width:44px;min-height:44px;display:flex;align-items:center;justify-content:center;">
            <i class="ph-bold ph-x"></i>
        </button>

        <h3 style="font-size:1.1rem;margin-bottom:1.2rem;">
            <i class="ph-bold ph-pencil-simple"></i> แก้ไขข้อมูลสตูดิโอ
            <span id="edit-studio-name" style="color:var(--primary);"></span>
        </h3>

        <form method="POST" action="studio_booking.php" id="studioEditForm">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="save_studio">
            <input type="hidden" name="studio_id" id="edit-studio-id" value="">

            <div class="form-group">
                <label><i class="ph ph-text-t"></i> ชื่อห้อง (แสดงใน dropdown)</label>
                <input type="text" name="name" id="edit-name" class="form-control" placeholder="Studio 1">
                <small class="text-muted" style="font-size:.78rem;">* แก้ไขผ่านหน้า Inventory → Studios</small>
            </div>

            <div class="form-group">
                <label><i class="ph ph-subtitles"></i> คำบรรยายย่อย (subtitle)</label>
                <input type="text" name="subtitle" id="edit-subtitle" class="form-control"
                       placeholder="เช่น Professional Lighting" maxlength="120">
            </div>

            <div class="form-group">
                <label><i class="ph ph-tag"></i> Tags
                    <span style="font-weight:400;color:var(--text-muted);font-size:.8rem;">(คั่นด้วย , ใส่ emoji ได้)</span>
                </label>
                <input type="text" name="tags" id="edit-tags" class="form-control"
                       placeholder="📸 Portrait,👗 Fashion,🎬 MV">
                <small class="text-muted" style="font-size:.78rem;">ตัวอย่าง: 📸 Portrait,👗 Fashion,🎬 MV</small>
            </div>

            <div class="form-group">
                <label><i class="ph ph-list-bullets"></i> รายละเอียดสิ่งอำนวยความสะดวก
                    <span style="font-weight:400;color:var(--text-muted);font-size:.8rem;">(แต่ละบรรทัด = 1 bullet)</span>
                </label>
                <textarea name="features" id="edit-features" class="form-control" rows="5"
                          placeholder="ชุดไฟ Strobe 4 จุด&#10;พื้นหลัง Seamless&#10;ขนาด ~6×8 เมตร"></textarea>
            </div>

            <div class="form-group">
                <label><i class="ph ph-clock"></i> เวลาเปิด-ปิด</label>
                <input type="text" name="open_hours" id="edit-open-hours" class="form-control"
                       placeholder="จ–ศ 08:00–20:00 น. / ส–อา 09:00–17:00 น." maxlength="160">
            </div>

            <div class="form-group">
                <label><i class="ph ph-phone"></i> เบอร์โทรติดต่อ</label>
                <input type="text" name="contact_phone" id="edit-contact-phone" class="form-control"
                       placeholder="096-954-5290" maxlength="30">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label><i class="ph ph-palette"></i> Theme สี</label>
                    <select name="theme" id="edit-theme" class="form-control">
                        <option value="dark">🌑 Dark (ไฟสตูดิโอ)</option>
                        <option value="light">☀️ Light (แสงธรรมชาติ)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="ph ph-toggle-left"></i> สถานะห้อง</label>
                    <select name="studio_status" id="edit-status" class="form-control">
                        <option value="open">✅ เปิดให้จอง</option>
                        <option value="closed">🚫 ปิดชั่วคราว</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 mt-2">
                <i class="ph-bold ph-floppy-disk"></i> บันทึกข้อมูล
            </button>
        </form>
    </div>
</div>

<!-- ข้อมูลสตูดิโอ JSON สำหรับ JS populate form -->
<script>
var studioData = <?php
    $jsData = [];
    foreach ($studioInfo as $s) {
        $jsData[$s['id']] = [
            'id'            => $s['id'],
            'name'          => $s['name'],
            'subtitle'      => $s['subtitle'] ?? '',
            'tags'          => $s['tags'] ?? '',
            'features'      => $s['features'] ?? '',
            'open_hours'    => $s['open_hours'] ?? '',
            'contact_phone' => $s['contact_phone'] ?? '',
            'theme'         => $s['theme'] ?? 'dark',
            'status'        => $s['status'] ?? 'open',
        ];
    }
    echo json_encode($jsData, JSON_UNESCAPED_UNICODE);
?>;

var _bn = null;
document.addEventListener('DOMContentLoaded', function(){ _bn = document.getElementById('bottom-nav'); });

function openStudioEdit(studioId) {
    var d = studioData[studioId];
    if (!d) return;
    document.getElementById('edit-studio-id').value    = d.id;
    document.getElementById('edit-studio-name').textContent = ' — ' + d.name;
    document.getElementById('edit-name').value         = d.name;
    document.getElementById('edit-subtitle').value     = d.subtitle;
    document.getElementById('edit-tags').value         = d.tags;
    document.getElementById('edit-features').value     = d.features;
    document.getElementById('edit-open-hours').value   = d.open_hours;
    document.getElementById('edit-contact-phone').value= d.contact_phone;
    document.getElementById('edit-theme').value        = d.theme;
    document.getElementById('edit-status').value       = d.status;

    document.getElementById('studioEditModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    if (_bn) { _bn.style.pointerEvents='none'; _bn.style.visibility='hidden'; }
}
function closeStudioEdit() {
    document.getElementById('studioEditModal').classList.remove('open');
    document.body.style.overflow = '';
    if (_bn) { _bn.style.pointerEvents=''; _bn.style.visibility=''; }
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeStudioEdit(); });
</script>
<?php endif; ?>

<script>
(function(){
    var startEl = document.getElementById('start_datetime');
    var endEl   = document.getElementById('end_datetime');
    var errEl   = document.getElementById('dt-error');
    var btnEl   = document.getElementById('booking-submit-btn');
    function nowLocal(){ var d=new Date(); d.setSeconds(0,0); return d.toISOString().slice(0,16); }
    startEl.min = nowLocal();
    function validate(){
        var s=startEl.value, e=endEl.value, now=nowLocal();
        if(s && s<now){ startEl.value=now; s=now; }
        if(s) endEl.min=s;
        var invalid = s && e && e<=s;
        errEl.style.display = invalid?'flex':'none';
        endEl.classList.toggle('is-invalid',!!invalid);
        if(btnEl) btnEl.disabled=!!invalid;
    }
    startEl.addEventListener('change', validate);
    endEl.addEventListener('change', validate);
    setInterval(function(){ startEl.min=nowLocal(); }, 60000);
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
