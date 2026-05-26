<?php
// member/profile.php
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
    $phone      = trim($_POST['phone'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $profile_image = $_POST['current_image'] ?? 'default.png';

    if (mb_strlen($first_name) > 100) { $error = 'ชื่อจริงยาวเกินไป'; }
    if (mb_strlen($last_name) > 100)  { $error = 'นามสกุลยาวเกินไป'; }

    if (!$error && isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['profile_image']['size'] > 4 * 1024 * 1024) {
            $error = 'รูปโปรไฟล์ต้องไม่เกิน 4 MB';
        } else {
            $tmp_name = $_FILES['profile_image']['tmp_name'];
            $ext = strtolower(pathinfo(basename($_FILES['profile_image']['name']), PATHINFO_EXTENSION));
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $tmp_name);
            finfo_close($finfo);
            $allowedMimes = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
            if (in_array($ext, ['jpg','jpeg','png','gif','webp']) && isset($allowedMimes[$mime])) {
                $new_name = $user_id . '_' . time() . '.' . $allowedMimes[$mime];
                $upload_dir = __DIR__ . '/../uploads/profiles/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                if (move_uploaded_file($tmp_name, $upload_dir . $new_name)) { $profile_image = $new_name; }
                else { $error = 'อัปโหลดรูปไม่สำเร็จ'; }
            } else { $error = 'รูปแบบไฟล์ไม่รองรับ (รองรับ JPG, PNG, GIF, WebP)'; }
        }
    }

    if (!$error) {
        $stmt = $pdo->prepare("UPDATE users SET phone = ?, first_name = ?, last_name = ?, profile_image = ?, profile_completed = 1 WHERE id = ?");
        if ($stmt->execute([$phone, $first_name, $last_name, $profile_image, $user_id])) {
            $success = 'อัปเดตโปรไฟล์สำเร็จแล้ว!';
            if (isset($_GET['first_login'])) { header("Location: feed.php?welcome=1"); exit; }
        } else { $error = 'เกิดข้อผิดพลาด'; }
    }
}

$stmt = $pdo->prepare("SELECT student_id, email, phone, first_name, last_name, profile_image, profile_completed, created_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-container-sm">
    <div class="page-header">
        <h2>ข้อมูลส่วนตัว</h2>
        <p>จัดการข้อมูลและรูปโปรไฟล์ของคุณ</p>
    </div>

    <?php if(isset($_GET['first_login'])):?>
        <div class="alert alert-success" style="text-align:center;">
            <i class="ph-bold ph-confetti" style="font-size:1.3rem;"></i>
            <strong>ยินดีต้อนรับสมาชิกใหม่!</strong> กรุณาตั้งค่าโปรไฟล์ก่อนเริ่มใช้งาน
        </div>
    <?php endif;?>

    <div class="glass-card animate-in">
        <?php if($success):?><div class="alert alert-success"><i class="ph-bold ph-check-circle"></i> <?php echo $success;?></div><?php endif;?>
        <?php if($error):?><div class="alert alert-danger"><i class="ph-bold ph-warning-circle"></i> <?php echo htmlspecialchars($error);?></div><?php endif;?>

        <form method="POST" action="profile.php<?php echo isset($_GET['first_login'])?'?first_login=1':'';?>" enctype="multipart/form-data">
            <?php echo csrf_input(); ?>
            <div style="text-align:center;margin-bottom:2rem;">
                <?php
                $imgPath = $user['profile_image'] !== 'default.png' ? "../uploads/profiles/".$user['profile_image'] : "https://ui-avatars.com/api/?name=".urlencode($user['student_id'])."&background=F2531C&color=fff&size=512";
                ?>
                <div style="position:relative;display:inline-block;">
                    <div style="width:120px;height:120px;margin:0 auto;border-radius:32px;overflow:hidden;border:4px solid #fff;box-shadow:0 8px 24px rgba(0,0,0,.08);">
                        <img id="avatar-preview" src="<?php echo htmlspecialchars($imgPath);?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;">
                    </div>
                    <label for="profile_image" style="position:absolute;bottom:-8px;right:-8px;background:linear-gradient(135deg,var(--primary),#ff6b35);width:40px;height:40px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff;cursor:pointer;border:3px solid #fff;box-shadow:0 4px 12px var(--primary-glow);">
                        <i class="ph-bold ph-camera" style="font-size:1.1rem;"></i>
                    </label>
                    <input type="file" id="profile_image" name="profile_image" style="display:none;" accept="image/*">
                </div>
            </div>

            <div class="form-group">
                <label><i class="ph ph-lock"></i> รหัสนักศึกษา / อีเมล</label>
                <div class="form-control" style="background:#f8f9fa;border-color:#e8ecf0;color:var(--text-secondary);">
                    <?php echo htmlspecialchars($user['student_id'] . ' | ' . $user['email']); ?>
                </div>
            </div>

            <!-- ชื่อ-นามสกุล (แก้ไขได้) -->
            <div class="form-row">
                <div class="form-group">
                    <label for="first_name"><i class="ph ph-user"></i> ชื่อจริง</label>
                    <input type="text" id="first_name" name="first_name" class="form-control"
                           placeholder="สมชาย"
                           value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>"
                           maxlength="100" autocomplete="given-name">
                </div>
                <div class="form-group">
                    <label for="last_name">นามสกุล</label>
                    <input type="text" id="last_name" name="last_name" class="form-control"
                           placeholder="ใจดี"
                           value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>"
                           maxlength="100" autocomplete="family-name">
                </div>
            </div>

            <div class="form-group">
                <label for="phone"><i class="ph ph-phone"></i> เบอร์โทรศัพท์ติดต่อ</label>
                <input type="text" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? '');?>" placeholder="0XXXXXXXXX">
            </div>

            <input type="hidden" name="current_image" value="<?php echo htmlspecialchars($user['profile_image']);?>">
            <button type="submit" class="btn btn-primary w-100 mt-2"><i class="ph-bold ph-floppy-disk"></i> บันทึกข้อมูล</button>

            <?php if($user['profile_completed']):?>
                <a href="feed.php" class="btn btn-outline w-100 mt-3">กลับไปหน้าฟีด</a>
            <?php endif;?>
        </form>
    </div>
</div>

<script>
document.getElementById('profile_image').addEventListener('change',function(){
    if(this.files&&this.files[0]){
        const r=new FileReader();
        r.onload=function(e){document.getElementById('avatar-preview').src=e.target.result;};
        r.readAsDataURL(this.files[0]);
        if(typeof showToast==='function')showToast('เลือกรูปภาพเรียบร้อย','success');
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
