<?php
// auth/login.php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: ../admin/dashboard.php");
    } else {
        header("Location: ../member/feed.php");
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── CSRF check ──────────────────────────────────────────────────────────
    if (!csrf_verify()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    // ── Session rate limiting ────────────────────────────────────────────────
    } elseif (login_locked()) {
        $error = 'พยายามเข้าสู่ระบบมากเกินไป กรุณารอ ' . login_wait_secs() . ' วินาที แล้วลองใหม่';
    // ── IP block check ───────────────────────────────────────────────────────
    } elseif (ip_is_blocked($pdo)) {
        $until = ip_blocked_until($pdo);
        $error = $until
            ? 'IP ของคุณถูกบล็อกชั่วคราว กรุณารอจนถึง ' . date('H:i น.', strtotime($until)) . ' แล้วลองใหม่'
            : 'IP ของคุณถูกบล็อก กรุณาติดต่อผู้ดูแลระบบ';
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password   = $_POST['password'] ?? '';

        if (empty($identifier) || empty($password)) {
            $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
        } elseif (str_contains($identifier, '@') && !str_ends_with(strtolower($identifier), '@kmitl.ac.th')) {
            $error = 'อนุญาตเฉพาะอีเมล @kmitl.ac.th เท่านั้น';
        } else {
            $stmt = $pdo->prepare("SELECT id, email, password, role, profile_completed, email_verified FROM users WHERE student_id = ? OR email = ?");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // บล็อก login ถ้ายังไม่ยืนยันอีเมล (ยกเว้น admin)
                if ($user['role'] !== 'admin' && !$user['email_verified']) {
                    $error = 'unverified';
                    $unverifiedEmail = $user['email'];
                } else {
                    login_reset();
                    ip_record_success($pdo, $identifier); // ── log success
                    session_regenerate_id(true);
                    unset($_SESSION['csrf_token']); // rotate CSRF token
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role']    = $user['role'];
                    $_SESSION['email']   = $user['email'];

                    if ($user['role'] !== 'admin' && !$user['profile_completed']) {
                        header("Location: ../member/profile.php?first_login=1");
                        exit;
                    }

                    header("Location: " . ($user['role'] === 'admin' ? '../admin/dashboard.php' : '../member/feed.php'));
                    exit;
                }
            } else {
                login_record_fail();
                ip_record_fail($pdo, $identifier); // ── log + maybe auto-block
                usleep(300000); // 0.3 วินาที — ป้องกัน timing attack
                $error = 'อีเมล/รหัสนักศึกษา หรือ รหัสผ่านไม่ถูกต้อง';
            }
        }
    }
}

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrapper">
    <div class="glass-card" style="max-width:420px; width:100%;">
        <div class="text-center mb-4">
            <div style="width:64px;height:64px;border-radius:20px;background:linear-gradient(135deg,var(--primary),var(--secondary));display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;box-shadow:0 8px 24px var(--primary-glow);">
                <i class="ph-bold ph-sign-in" style="font-size:1.8rem;color:#fff"></i>
            </div>
            <h2 style="font-size:1.5rem;margin-bottom:.3rem;">เข้าสู่ระบบ</h2>
            <p class="text-muted" style="font-size:.9rem;">ยินดีต้อนรับกลับสู่ IE-Photo Maker</p>
        </div>

        <?php if(isset($_GET['verify_sent'])): ?>
            <div class="alert alert-success">
                <i class="ph-bold ph-envelope"></i>
                ส่งอีเมลยืนยันไปที่ <strong><?php echo htmlspecialchars($_GET['email'] ?? ''); ?></strong> แล้ว กรุณาตรวจสอบกล่องข้อความ
            </div>
        <?php elseif(isset($_GET['registered'])): ?>
            <div class="alert alert-success"><i class="ph-bold ph-check-circle"></i> สมัครสมาชิกสำเร็จ! เข้าสู่ระบบเพื่อเริ่มใช้งาน</div>
        <?php endif; ?>

        <?php if($error === 'unverified'): ?>
            <div class="alert alert-danger" style="line-height:1.7;">
                <i class="ph-bold ph-envelope-simple-x"></i>
                <strong>ยังไม่ได้ยืนยันอีเมล</strong><br>
                <span style="font-size:.88rem;">กรุณาตรวจสอบอีเมลที่ <strong><?php echo htmlspecialchars($unverifiedEmail ?? ''); ?></strong></span><br>
                <a href="verify.php?resend=1&email=<?php echo urlencode($unverifiedEmail ?? ''); ?>"
                   style="font-size:.85rem;color:var(--primary);font-weight:600;">
                    <i class="ph ph-paper-plane-tilt"></i> ส่งอีเมลยืนยันใหม่
                </a>
            </div>
        <?php elseif($error): ?>
            <div class="alert alert-danger"><i class="ph-bold ph-warning-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php" id="login-form">
            <?php echo csrf_input(); ?>
            <div class="form-group">
                <label for="identifier"><i class="ph ph-identification-card"></i> รหัสนักศึกษา หรือ อีเมล</label>
                <div class="input-icon-wrap">
                    <input type="text" id="identifier" name="identifier" class="form-control"
                           placeholder="6XXXXXXX หรือ xxxxxx@kmitl.ac.th" required autocomplete="username"
                           value="<?php echo htmlspecialchars($_POST['identifier'] ?? ''); ?>">
                    <i class="ph ph-user input-icon"></i>
                </div>
                <div id="email-hint" class="field-error" style="display:none;">
                    <i class="ph ph-warning-circle"></i> อนุญาตเฉพาะอีเมล @kmitl.ac.th เท่านั้น
                </div>
            </div>
            <div class="form-group">
                <label for="password"><i class="ph ph-lock"></i> รหัสผ่าน</label>
                <div style="position:relative;">
                    <input type="password" id="password" name="password" class="form-control"
                           placeholder="ระบุรหัสผ่านของคุณ" required autocomplete="current-password"
                           style="padding-right:3rem;">
                    <button type="button" onclick="togglePwd('password','pwd-eye-login')" tabindex="-1"
                            style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;font-size:1.1rem;">
                        <i class="ph ph-eye" id="pwd-eye-login"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 mt-2" id="login-btn" style="font-size:1rem;padding:.85rem;">
                <i class="ph-bold ph-sign-in"></i> เข้าสู่ระบบ
            </button>
            <div class="divider">หรือ</div>
            <div class="text-center">
                <p class="text-muted" style="font-size:.9rem;">
                    ยังไม่มีบัญชี? <a href="register.php" style="font-weight:700;color:var(--primary);">สมัครสมาชิกใหม่ →</a>
                </p>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var input = document.getElementById('identifier');
    var hint  = document.getElementById('email-hint');
    var btn   = document.getElementById('login-btn');

    function validate() {
        var val = input.value.trim();
        if (val.includes('@') && !val.toLowerCase().endsWith('@kmitl.ac.th')) {
            hint.style.display = 'flex';
            input.classList.add('is-invalid');
            input.classList.remove('is-valid');
            btn.disabled = true;
        } else {
            hint.style.display = 'none';
            input.classList.remove('is-invalid');
            if (val) input.classList.add('is-valid');
            btn.disabled = false;
        }
    }

    function togglePwd(id, eyeId) {
        var inp = document.getElementById(id);
        var eye = document.getElementById(eyeId);
        inp.type = inp.type === 'password' ? 'text' : 'password';
        eye.className = inp.type === 'password' ? 'ph ph-eye' : 'ph ph-eye-slash';
    }
    window.togglePwd = togglePwd;

    input.addEventListener('input', validate);
    input.addEventListener('blur',  validate);
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
