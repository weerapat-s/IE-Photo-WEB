<?php
// admin/tasks.php — Task Assignment & Management
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../includes/email_templates.php';

if (!isset($_SESSION['user_id']) || !is_admin()) {
    header("Location: ../auth/login.php");
    exit;
}

$success = '';
$error = '';

// Check if tasks table exists
$tableExists = true;
try {
    $pdo->query("SELECT 1 FROM tasks LIMIT 1");
} catch (Exception $e) {
    $tableExists = false;
}

if (!$tableExists) {
    // Auto-create tasks table
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        assigned_by INT NOT NULL,
        assigned_to INT NOT NULL,
        booking_id INT DEFAULT NULL,
        status ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
        due_date DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif (isset($_POST['create_task'])) {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $assigned_to = intval($_POST['assigned_to'] ?? 0);
        $due_date = $_POST['due_date'] ?? null;
        $booking_id = !empty($_POST['booking_id']) ? intval($_POST['booking_id']) : null;

        if (!empty($title) && $assigned_to > 0) {
            $stmt = $pdo->prepare("INSERT INTO tasks (title, description, assigned_by, assigned_to, booking_id, due_date) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$title, $description, $_SESSION['user_id'], $assigned_to, $booking_id, $due_date ?: null])) {
                $success = "สร้างงานสำเร็จ: {$title}";

                // Notify assigned user
                $userStmt = $pdo->prepare("SELECT email, student_id FROM users WHERE id = ?");
                $userStmt->execute([$assigned_to]);
                $assignee = $userStmt->fetch();
                if ($assignee && !empty($assignee['email'])) {
                    $taskBody = "
                    <div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;padding:24px;border:1px solid #e0e0e0;border-radius:12px;'>
                        <div style='background:linear-gradient(135deg,#F2531C,#ff6b35);padding:20px;border-radius:8px;text-align:center;margin-bottom:20px;'>
                            <h2 style='color:#fff;margin:0;font-size:20px;'>📋 คุณได้รับมอบหมายงานใหม่</h2>
                        </div>
                        <p>สวัสดี <strong>" . htmlspecialchars($assignee['student_id']) . "</strong>,</p>
                        <p>แอดมินได้มอบหมายงานให้คุณ:</p>
                        <div style='background:#f8f9fa;border-radius:8px;padding:16px;border-left:4px solid #F2531C;margin:16px 0;'>
                            <strong>" . htmlspecialchars($title) . "</strong>
                            " . ($description ? "<p style='margin:8px 0 0;color:#666;font-size:14px;'>" . htmlspecialchars($description) . "</p>" : "") . "
                        </div>
                        <p style='font-size:13px;color:#666;'>กรุณาเข้าสู่ระบบเพื่อดูรายละเอียดและอัปเดตสถานะงาน</p>
                    </div>";
                    sendEmail($assignee['email'], "IE-Photo: คุณได้รับมอบหมายงานใหม่ — {$title}", $taskBody);
                }
            } else { $error = 'สร้างงานไม่สำเร็จ'; }
        } else { $error = 'กรุณากรอกชื่องานและเลือกผู้รับผิดชอบ'; }
    } elseif (isset($_POST['update_status'])) {
        $task_id = intval($_POST['task_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($task_id > 0 && in_array($status, ['pending', 'in_progress', 'completed', 'cancelled'])) {
            $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
            $success = $stmt->execute([$status, $task_id]) ? 'อัปเดตสถานะสำเร็จ' : 'อัปเดตไม่สำเร็จ';
        }
    } elseif (isset($_POST['delete_task'])) {
        $task_id = intval($_POST['task_id'] ?? 0);
        if ($task_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
            $success = $stmt->execute([$task_id]) ? 'ลบงานสำเร็จ' : 'ลบไม่สำเร็จ';
        }
    }
}

// Fetch all tasks
$filter = $_GET['filter'] ?? 'all';
$where = '';
if ($filter === 'pending') $where = "WHERE t.status = 'pending'";
elseif ($filter === 'in_progress') $where = "WHERE t.status = 'in_progress'";
elseif ($filter === 'completed') $where = "WHERE t.status = 'completed'";

$tasks = $pdo->query("
    SELECT t.*, a.student_id as assignee_name, c.student_id as creator_name
    FROM tasks t
    LEFT JOIN users a ON t.assigned_to = a.id
    LEFT JOIN users c ON t.assigned_by = c.id
    {$where}
    ORDER BY t.created_at DESC
")->fetchAll();

// Get members for dropdown
$members = $pdo->query("SELECT id, student_id, role FROM users ORDER BY role DESC, student_id ASC")->fetchAll();

// Get pending bookings for linking
$pendingBookings = $pdo->query("
    SELECT b.id, COALESCE(e.name, s.name) as item_name, COALESCE(u.student_id, b.guest_name) as borrower
    FROM bookings b
    LEFT JOIN equipments e ON b.item_id = e.id AND b.booking_type = 'equipment'
    LEFT JOIN studios s ON b.item_id = s.id AND b.booking_type = 'studio'
    LEFT JOIN users u ON b.user_id = u.id
    WHERE b.status IN ('approved','pending')
    ORDER BY b.created_at DESC LIMIT 20
")->fetchAll();

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2>จัดการงาน & มอบหมาย</h2>
    <p>สร้าง มอบหมาย และติดตามงานของสมาชิกในทีม</p>
</div>

<div class="flex-between" style="margin-bottom:1rem;">
    <div class="filter-bar">
        <a href="tasks.php" class="btn <?php echo $filter==='all'?'btn-primary':'btn-outline'; ?> btn-sm">ทั้งหมด</a>
        <a href="tasks.php?filter=pending" class="btn <?php echo $filter==='pending'?'btn-primary':'btn-outline'; ?> btn-sm">รอดำเนินการ</a>
        <a href="tasks.php?filter=in_progress" class="btn <?php echo $filter==='in_progress'?'btn-primary':'btn-outline'; ?> btn-sm">กำลังทำ</a>
        <a href="tasks.php?filter=completed" class="btn <?php echo $filter==='completed'?'btn-primary':'btn-outline'; ?> btn-sm">เสร็จแล้ว</a>
    </div>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('createForm').style.display=document.getElementById('createForm').style.display==='none'?'block':'none'">
        <i class="ph-bold ph-plus"></i> สร้างงานใหม่
    </button>
</div>

<?php if($success):?><div class="alert alert-success"><i class="ph-bold ph-check-circle"></i> <?php echo htmlspecialchars($success);?></div><?php endif;?>
<?php if($error):?><div class="alert alert-danger"><i class="ph-bold ph-warning-circle"></i> <?php echo htmlspecialchars($error);?></div><?php endif;?>

<!-- Create Task Form -->
<div id="createForm" class="glass-card" style="display:none;margin-bottom:1.5rem;border:2px solid var(--primary);">
    <h3 style="font-size:1rem;margin-bottom:1rem;"><i class="ph-bold ph-plus-circle"></i> สร้างงานใหม่</h3>
    <form method="POST">
        <?php echo csrf_input(); ?>
        <div class="form-row">
            <div class="form-group" style="flex:2"><label>ชื่องาน</label><input type="text" name="title" class="form-control" required placeholder="เช่น จัดเตรียมอุปกรณ์ถ่ายภาพ"></div>
            <div class="form-group" style="flex:1"><label>มอบหมายให้</label>
                <select name="assigned_to" class="form-control" required>
                    <option value="">-- เลือกสมาชิก --</option>
                    <?php foreach($members as $m):?><option value="<?php echo $m['id'];?>"><?php echo htmlspecialchars($m['student_id']);?> <?php echo $m['role']==='admin'?'(Admin)':'';?></option><?php endforeach;?>
                </select>
            </div>
        </div>
        <div class="form-group"><label>รายละเอียด</label><textarea name="description" class="form-control" rows="2" placeholder="รายละเอียดเพิ่มเติม (ไม่บังคับ)"></textarea></div>
        <div class="form-row">
            <div class="form-group"><label>กำหนดส่ง</label><input type="datetime-local" name="due_date" class="form-control"></div>
            <div class="form-group"><label>เชื่อมกับการจอง (ไม่บังคับ)</label>
                <select name="booking_id" class="form-control">
                    <option value="">— ไม่เชื่อม —</option>
                    <?php foreach($pendingBookings as $pb):?><option value="<?php echo $pb['id'];?>">#<?php echo $pb['id'];?> <?php echo htmlspecialchars($pb['item_name']);?> (<?php echo htmlspecialchars($pb['borrower']);?>)</option><?php endforeach;?>
                </select>
            </div>
        </div>
        <div class="add-form-actions">
            <button type="submit" name="create_task" class="btn btn-success btn-sm">สร้างงาน</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('createForm').style.display='none'">ยกเลิก</button>
        </div>
    </form>
</div>

<!-- Task Cards -->
<?php if(empty($tasks)):?>
    <div class="glass-card empty-state"><i class="ph ph-kanban"></i><p class="text-muted">ยังไม่มีงานในระบบ</p></div>
<?php else:?>
    <div class="tasks-grid">
    <?php foreach($tasks as $t):?>
        <?php
            $sLabel='รอดำเนินการ';$sBadge='badge-pending';$sIcon='⏳';
            if($t['status']==='in_progress'){$sLabel='กำลังทำ';$sBadge='badge-approved';$sIcon='🔄';}
            if($t['status']==='completed'){$sLabel='เสร็จแล้ว';$sBadge='badge-returned';$sIcon='✅';}
            if($t['status']==='cancelled'){$sLabel='ยกเลิก';$sBadge='badge-rejected';$sIcon='❌';}
            $isOverdue = $t['due_date'] && strtotime($t['due_date']) < time() && $t['status'] !== 'completed';
        ?>
        <div class="glass-card animate-in" style="padding:1.3rem;<?php echo $isOverdue?'border-left:4px solid var(--danger);':'';?>">
            <div class="flex-between" style="margin-bottom:.8rem;">
                <span class="badge <?php echo $sBadge;?>"><?php echo $sIcon.' '.$sLabel;?></span>
                <?php if($isOverdue):?><span class="badge badge-rejected">⚠️ เลยกำหนด</span><?php endif;?>
            </div>
            <h4 style="font-size:1rem;margin-bottom:.4rem;"><?php echo htmlspecialchars($t['title']);?></h4>
            <?php if($t['description']):?><p style="font-size:.85rem;color:var(--text-secondary);margin-bottom:.8rem;"><?php echo htmlspecialchars($t['description']);?></p><?php endif;?>
            <div style="font-size:.82rem;color:var(--text-muted);margin-bottom:.8rem;">
                <div><i class="ph ph-user-focus"></i> มอบหมายให้: <strong><?php echo htmlspecialchars($t['assignee_name']);?></strong></div>
                <div><i class="ph ph-user"></i> สร้างโดย: <?php echo htmlspecialchars($t['creator_name']);?></div>
                <?php if($t['due_date']):?><div><i class="ph ph-calendar"></i> กำหนด: <?php echo date('d M Y, H:i',strtotime($t['due_date']));?></div><?php endif;?>
                <?php if($t['booking_id']):?><div><i class="ph ph-link"></i> เชื่อมกับการจอง #<?php echo $t['booking_id'];?></div><?php endif;?>
            </div>
            <div style="display:flex;gap:.3rem;flex-wrap:wrap;">
                <form method="POST" style="display:flex;gap:.3rem;align-items:center;flex:1;">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="task_id" value="<?php echo $t['id'];?>">
                    <select name="status" class="form-control" style="font-size:.8rem;padding:.35rem .6rem;" onchange="this.form.submit()">
                        <option value="pending" <?php echo $t['status']==='pending'?'selected':'';?>>⏳ รอ</option>
                        <option value="in_progress" <?php echo $t['status']==='in_progress'?'selected':'';?>>🔄 กำลังทำ</option>
                        <option value="completed" <?php echo $t['status']==='completed'?'selected':'';?>>✅ เสร็จ</option>
                        <option value="cancelled" <?php echo $t['status']==='cancelled'?'selected':'';?>>❌ ยกเลิก</option>
                    </select>
                    <input type="hidden" name="update_status" value="1">
                </form>
                <form method="POST" onsubmit="return confirm('ลบงานนี้?')"><?php echo csrf_input();?><input type="hidden" name="task_id" value="<?php echo $t['id'];?>">
                    <button name="delete_task" value="1" class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger);padding:.35rem .5rem;"><i class="ph-bold ph-trash"></i></button></form>
            </div>
        </div>
    <?php endforeach;?>
    </div>
<?php endif;?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
