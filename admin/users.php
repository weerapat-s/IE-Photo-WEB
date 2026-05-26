<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$current_user_id = $_SESSION['user_id'];
$success = '';
$error   = '';

// สร้าง / ดึง CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ─── Handle POST actions ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง (CSRF token ผิดพลาด) กรุณาลองใหม่';
    } else {

        $target_id = (int)($_POST['user_id'] ?? 0);
        $action    = $_POST['action'];

        // ── เปลี่ยนบทบาท ──────────────────────────────────────────────────
        if ($action === 'change_role') {
            $new_role = $_POST['new_role'] ?? '';
            if ($target_id === $current_user_id) {
                $error = 'ไม่สามารถเปลี่ยนบทบาทของตัวเองได้';
            } elseif (!in_array($new_role, ['admin', 'member'])) {
                $error = 'บทบาทไม่ถูกต้อง';
            } else {
                $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")
                    ->execute([$new_role, $target_id]);
                $success = 'เปลี่ยนบทบาทเรียบร้อยแล้ว';
            }

        // ── รีเซ็ตรหัสผ่าน ────────────────────────────────────────────────
        } elseif ($action === 'reset_password') {
            $new_pass  = $_POST['new_password']     ?? '';
            $conf_pass = $_POST['confirm_password'] ?? '';

            if ($target_id === $current_user_id) {
                $error = 'ใช้หน้าโปรไฟล์เพื่อเปลี่ยนรหัสผ่านของตัวเอง';
            } elseif (strlen($new_pass) < 6) {
                $error = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
            } elseif ($new_pass !== $conf_pass) {
                $error = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
            } else {
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                    ->execute([password_hash($new_pass, PASSWORD_DEFAULT), $target_id]);
                $success = 'รีเซ็ตรหัสผ่านเรียบร้อยแล้ว';
            }

        // ── ลบบัญชี ───────────────────────────────────────────────────────
        } elseif ($action === 'delete_user') {
            if ($target_id === $current_user_id) {
                $error = 'ไม่สามารถลบบัญชีของตัวเองได้';
            } elseif ($target_id === 0) {
                $error = 'ไม่พบผู้ใช้';
            } else {
                $pdo->prepare("DELETE FROM users WHERE id = ?")
                    ->execute([$target_id]);
                $success = 'ลบบัญชีผู้ใช้เรียบร้อยแล้ว';
            }
        }

        // Regenerate CSRF token หลังทุก action
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $csrf_token = $_SESSION['csrf_token'];
    }
}

$users = $pdo->query("SELECT id, student_id, first_name, last_name, email, role, created_at FROM users ORDER BY role DESC, student_id ASC")->fetchAll();

$base_url   = '../';
$page_title = 'จัดการสมาชิก';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
        <h2><i class="ph-bold ph-users"></i> จัดการสมาชิก</h2>
        <p>เปลี่ยนบทบาท · รีเซ็ตรหัสผ่าน · ลบบัญชี</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="ph ph-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="ph ph-warning"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- ═══ Desktop Table ═══════════════════════════════════════════════════ -->
    <div class="glass-card desktop-table">
        <p class="table-scroll-hint"><i class="ph ph-arrow-left"></i> เลื่อนดูข้อมูลเพิ่มเติม <i class="ph ph-arrow-right"></i></p>
        <div class="table-responsive">
            <table class="glass-table">
                <thead>
                    <tr>
                        <th>รหัสนักศึกษา</th>
                        <th>ชื่อ-นามสกุล</th>
                        <th>อีเมล</th>
                        <th>บทบาท</th>
                        <th style="min-width:320px">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($u['student_id'] ?? '-'); ?></strong></td>
                        <td>
                            <?php
                                $name = trim($u['first_name'] . ' ' . $u['last_name']);
                                echo $name ? htmlspecialchars($name) : '<span class="text-muted">ยังไม่ได้กรอก</span>';
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td>
                            <span class="badge <?php echo $u['role'] === 'admin' ? 'badge-approved' : 'badge-pending'; ?>">
                                <?php echo $u['role'] === 'admin' ? 'Admin' : 'Member'; ?>
                                <?php echo $u['id'] == $current_user_id ? ' (คุณ)' : ''; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($u['id'] != $current_user_id): ?>
                            <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;">

                                <!-- เปลี่ยนบทบาท -->
                                <form method="POST" style="display:flex;gap:.4rem;align-items:center">
                                    <input type="hidden" name="action"     value="change_role">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="user_id"    value="<?php echo $u['id']; ?>">
                                    <select name="new_role" class="form-control" style="width:auto;min-width:110px;padding:.3rem .6rem;font-size:.8rem">
                                        <option value="member" <?php echo $u['role']==='member'?'selected':''; ?>>Member</option>
                                        <option value="admin"  <?php echo $u['role']==='admin' ?'selected':''; ?>>Admin</option>
                                    </select>
                                    <button type="submit" class="btn btn-primary btn-sm">บันทึก</button>
                                </form>

                                <!-- ปุ่มรีเซ็ตรหัสผ่าน -->
                                <button class="btn btn-sm"
                                        style="background:var(--warning);color:#000;"
                                        onclick="openResetModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['student_id'] ?? $u['email'], ENT_QUOTES); ?>')">
                                    <i class="ph ph-key"></i> รีเซ็ต
                                </button>

                                <!-- ปุ่มลบ -->
                                <button class="btn btn-sm"
                                        style="background:var(--danger);color:#fff;"
                                        onclick="openDeleteModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['student_id'] ?? $u['email'], ENT_QUOTES); ?>')">
                                    <i class="ph ph-trash"></i> ลบ
                                </button>

                            </div>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:.82rem">— บัญชีของคุณ —</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══ Mobile Cards ════════════════════════════════════════════════════ -->
    <div class="mobile-cards">
        <?php foreach ($users as $u): ?>
        <div class="mobile-card animate-in">
            <div class="mc-header">
                <strong><?php echo htmlspecialchars($u['student_id'] ?? $u['email']); ?></strong>
                <span class="badge <?php echo $u['role']==='admin'?'badge-approved':'badge-pending'; ?>">
                    <?php echo $u['role']==='admin'?'Admin':'Member'; ?>
                    <?php echo $u['id']==$current_user_id?' (คุณ)':''; ?>
                </span>
            </div>
            <div class="mc-row">
                <span class="mc-label">ชื่อ</span>
                <span><?php
                    $name = trim($u['first_name'].' '.$u['last_name']);
                    echo $name ? htmlspecialchars($name) : '—';
                ?></span>
            </div>
            <div class="mc-row">
                <span class="mc-label">อีเมล</span>
                <span><?php echo htmlspecialchars($u['email']); ?></span>
            </div>

            <?php if ($u['id'] != $current_user_id): ?>
            <div class="mc-actions" style="flex-direction:column;gap:.5rem;">
                <!-- เปลี่ยนบทบาท -->
                <form method="POST" style="display:flex;gap:.5rem;align-items:center;width:100%">
                    <input type="hidden" name="action"     value="change_role">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="user_id"    value="<?php echo $u['id']; ?>">
                    <select name="new_role" class="form-control" style="flex:1">
                        <option value="member" <?php echo $u['role']==='member'?'selected':''; ?>>Member</option>
                        <option value="admin"  <?php echo $u['role']==='admin' ?'selected':''; ?>>Admin</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm">บันทึก</button>
                </form>
                <!-- รีเซ็ต + ลบ -->
                <div style="display:flex;gap:.5rem;width:100%">
                    <button class="btn btn-sm" style="flex:1;background:var(--warning);color:#000;"
                            onclick="openResetModal(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars($u['student_id']??$u['email'],ENT_QUOTES); ?>')">
                        <i class="ph ph-key"></i> รีเซ็ตรหัสผ่าน
                    </button>
                    <button class="btn btn-sm" style="flex:1;background:var(--danger);color:#fff;"
                            onclick="openDeleteModal(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars($u['student_id']??$u['email'],ENT_QUOTES); ?>')">
                        <i class="ph ph-trash"></i> ลบบัญชี
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

<!-- ═══ Modal: รีเซ็ตรหัสผ่าน ══════════════════════════════════════════════ -->
<div id="modal-reset" class="modal-legacy" role="dialog" aria-modal="true" aria-labelledby="resetModalTitle">
    <div class="glass-card" style="max-width:420px;width:100%;position:relative;">
        <button onclick="closeModal('modal-reset')"
                style="position:absolute;top:.75rem;right:.75rem;background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:1.3rem;">
            <i class="ph ph-x"></i>
        </button>
        <h3 style="margin-bottom:.25rem;"><i class="ph ph-key"></i> รีเซ็ตรหัสผ่าน</h3>
        <p class="text-muted" style="font-size:.88rem;margin-bottom:1.25rem;">
            รหัสผ่านใหม่สำหรับ: <strong id="reset-label"></strong>
        </p>
        <form method="POST">
            <input type="hidden" name="action"     value="reset_password">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="user_id"    id="reset-user-id">

            <div class="form-group">
                <label><i class="ph ph-lock"></i> รหัสผ่านใหม่</label>
                <div style="position:relative;">
                    <input type="password" name="new_password" id="new-pwd" class="form-control"
                           placeholder="อย่างน้อย 6 ตัวอักษร" minlength="6" required
                           style="padding-right:3rem;">
                    <button type="button" onclick="togglePwd('new-pwd','eye1')" tabindex="-1"
                            style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);">
                        <i class="ph ph-eye" id="eye1"></i>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label><i class="ph ph-lock-key"></i> ยืนยันรหัสผ่าน</label>
                <div style="position:relative;">
                    <input type="password" name="confirm_password" id="conf-pwd" class="form-control"
                           placeholder="กรอกรหัสผ่านอีกครั้ง" minlength="6" required
                           style="padding-right:3rem;">
                    <button type="button" onclick="togglePwd('conf-pwd','eye2')" tabindex="-1"
                            style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);">
                        <i class="ph ph-eye" id="eye2"></i>
                    </button>
                </div>
                <small id="pwd-match-hint" style="font-size:.8rem;display:none;"></small>
            </div>
            <div style="display:flex;gap:.75rem;margin-top:1rem;">
                <button type="button" onclick="closeModal('modal-reset')" class="btn btn-outline" style="flex:1">ยกเลิก</button>
                <button type="submit" class="btn btn-primary" style="flex:1;background:var(--warning);border-color:var(--warning);color:#000;" id="reset-submit-btn">
                    <i class="ph ph-check"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ Modal: ยืนยันลบบัญชี ═══════════════════════════════════════════════ -->
<div id="modal-delete" class="modal-legacy" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="glass-card" style="max-width:400px;width:100%;text-align:center;position:relative;">
        <button onclick="closeModal('modal-delete')"
                style="position:absolute;top:.75rem;right:.75rem;background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:1.3rem;">
            <i class="ph ph-x"></i>
        </button>
        <div style="font-size:3rem;margin-bottom:.5rem;">🗑️</div>
        <h3 style="color:var(--danger);margin-bottom:.5rem;">ลบบัญชีผู้ใช้</h3>
        <p class="text-muted" style="font-size:.9rem;margin-bottom:1.5rem;">
            คุณแน่ใจหรือไม่ว่าต้องการลบบัญชี<br>
            <strong id="delete-label"></strong><br>
            <span style="color:var(--danger);font-size:.82rem;">การลบไม่สามารถย้อนกลับได้</span>
        </p>
        <form method="POST">
            <input type="hidden" name="action"     value="delete_user">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="user_id"    id="delete-user-id">
            <div style="display:flex;gap:.75rem;">
                <button type="button" onclick="closeModal('modal-delete')" class="btn btn-outline" style="flex:1">ยกเลิก</button>
                <button type="submit" class="btn btn-primary" style="flex:1;background:var(--danger);border-color:var(--danger);">
                    <i class="ph ph-trash"></i> ยืนยันลบ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openResetModal(userId, label) {
    document.getElementById('reset-user-id').value = userId;
    document.getElementById('reset-label').textContent = label;
    document.getElementById('new-pwd').value = '';
    document.getElementById('conf-pwd').value = '';
    document.getElementById('pwd-match-hint').style.display = 'none';
    showModal('modal-reset');
}

function openDeleteModal(userId, label) {
    document.getElementById('delete-user-id').value = userId;
    document.getElementById('delete-label').textContent = label;
    showModal('modal-delete');
}

function showModal(id) {
    var m = document.getElementById(id);
    m.classList.add('open');
    document.body.style.overflow = 'hidden';
    // ปิดเมื่อกด backdrop
    m.addEventListener('click', function onBg(e) {
        if (e.target === m) { closeModal(id); m.removeEventListener('click', onBg); }
    });
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}

function togglePwd(inputId, eyeId) {
    var inp = document.getElementById(inputId);
    var eye = document.getElementById(eyeId);
    inp.type = inp.type === 'password' ? 'text' : 'password';
    eye.className = inp.type === 'password' ? 'ph ph-eye' : 'ph ph-eye-slash';
}

// Real-time ตรวจรหัสผ่านตรงกัน
document.getElementById('conf-pwd').addEventListener('input', function () {
    var hint = document.getElementById('pwd-match-hint');
    var btn  = document.getElementById('reset-submit-btn');
    if (this.value && this.value !== document.getElementById('new-pwd').value) {
        hint.textContent = '⚠️ รหัสผ่านไม่ตรงกัน';
        hint.style.display = 'block';
        hint.style.color = 'var(--danger)';
        btn.disabled = true;
    } else if (this.value) {
        hint.textContent = '✅ รหัสผ่านตรงกัน';
        hint.style.display = 'block';
        hint.style.color = 'var(--success)';
        btn.disabled = false;
    } else {
        hint.style.display = 'none';
        btn.disabled = false;
    }
});

// ปิด modal ด้วย Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal('modal-reset');
        closeModal('modal-delete');
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
