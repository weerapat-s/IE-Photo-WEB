<?php
// member/my_tasks.php — View and update assigned tasks
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Check if tasks table exists — and ensure utf8mb4 charset (ป้องกัน Thai ???)
$tasksExist = true;
try { $pdo->query("SELECT 1 FROM tasks LIMIT 1"); } catch (Exception $e) { $tasksExist = false; }
if (!$tasksExist) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, description TEXT,
        assigned_by INT NOT NULL, assigned_to INT NOT NULL, booking_id INT DEFAULT NULL,
        status ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
        due_date DATETIME DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else {
    try {
        $cs = $pdo->query("SELECT CCSA.character_set_name
            FROM information_schema.TABLES T
            JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
                ON CCSA.collation_name = T.TABLE_COLLATION
            WHERE T.TABLE_SCHEMA = DATABASE() AND T.TABLE_NAME = 'tasks'")->fetchColumn();
        if ($cs && strtolower($cs) !== 'utf8mb4') {
            $pdo->exec("ALTER TABLE tasks CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    } catch (Exception $e) { }
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (!csrf_verify()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
    $task_id = intval($_POST['task_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($task_id > 0 && in_array($status, ['pending', 'in_progress', 'completed'])) {
        $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ? AND assigned_to = ?");
        if ($stmt->execute([$status, $task_id, $user_id])) {
            $success = 'อัปเดตสถานะงานเรียบร้อย';
        } else { $error = 'อัปเดตไม่สำเร็จ'; }
    }
    } // end csrf else
}

// Fetch my tasks
$stmt = $pdo->prepare("
    SELECT t.*, c.student_id as creator_name
    FROM tasks t
    LEFT JOIN users c ON t.assigned_by = c.id
    WHERE t.assigned_to = ?
    ORDER BY FIELD(t.status, 'in_progress', 'pending', 'completed', 'cancelled'), t.created_at DESC
");
$stmt->execute([$user_id]);
$tasks = $stmt->fetchAll();

$pendingCount = 0; $inProgressCount = 0; $completedCount = 0;
foreach ($tasks as $t) {
    if ($t['status'] === 'pending') $pendingCount++;
    elseif ($t['status'] === 'in_progress') $inProgressCount++;
    elseif ($t['status'] === 'completed') $completedCount++;
}

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2>งานของฉัน</h2>
    <p>รายการงานที่ได้รับมอบหมาย — อัปเดตสถานะเมื่อดำเนินการ</p>
</div>

<!-- Stats -->
<div class="stats-grid stats-grid-3" style="margin-bottom:1.5rem;">
    <div class="stat-card animate-in" style="border-left:4px solid var(--warning);">
        <div class="stat-value" style="color:var(--warning);font-size:1.5rem;"><?php echo $pendingCount; ?></div>
        <div class="stat-label">รอดำเนินการ</div>
    </div>
    <div class="stat-card animate-in" style="border-left:4px solid var(--info);">
        <div class="stat-value" style="color:var(--info);font-size:1.5rem;"><?php echo $inProgressCount; ?></div>
        <div class="stat-label">กำลังทำ</div>
    </div>
    <div class="stat-card animate-in" style="border-left:4px solid var(--success);">
        <div class="stat-value" style="color:var(--success);font-size:1.5rem;"><?php echo $completedCount; ?></div>
        <div class="stat-label">เสร็จแล้ว</div>
    </div>
</div>

<?php if($success):?><div class="alert alert-success"><i class="ph-bold ph-check-circle"></i> <?php echo $success;?></div><?php endif;?>
<?php if($error):?><div class="alert alert-danger"><i class="ph-bold ph-warning-circle"></i> <?php echo htmlspecialchars($error);?></div><?php endif;?>

<?php if(empty($tasks)):?>
    <div class="glass-card empty-state"><i class="ph ph-kanban"></i><p class="text-muted">คุณยังไม่มีงานที่ได้รับมอบหมาย</p></div>
<?php else:?>
    <div style="display:flex;flex-direction:column;gap:1rem;">
    <?php foreach($tasks as $t):?>
        <?php
            $sLabel='รอดำเนินการ';$sBadge='badge-pending';$sIcon='⏳';
            if($t['status']==='in_progress'){$sLabel='กำลังทำ';$sBadge='badge-approved';$sIcon='🔄';}
            if($t['status']==='completed'){$sLabel='เสร็จแล้ว';$sBadge='badge-returned';$sIcon='✅';}
            if($t['status']==='cancelled'){$sLabel='ยกเลิก';$sBadge='badge-rejected';$sIcon='❌';}
            $isOverdue = $t['due_date'] && strtotime($t['due_date']) < time() && !in_array($t['status'], ['completed','cancelled']);
        ?>
        <div class="glass-card animate-in" style="padding:1.3rem;<?php echo $isOverdue?'border-left:4px solid var(--danger);':'';?><?php echo $t['status']==='completed'?'opacity:.7;':'';?>">
            <div class="flex-between" style="margin-bottom:.6rem;">
                <span class="badge <?php echo $sBadge;?>"><?php echo $sIcon.' '.$sLabel;?></span>
                <span style="font-size:.78rem;color:var(--text-muted);"><i class="ph ph-clock"></i> <?php echo date('d M Y',strtotime($t['created_at']));?></span>
            </div>
            <h4 style="font-size:1.05rem;margin-bottom:.3rem;"><?php echo htmlspecialchars($t['title']);?></h4>
            <?php if($t['description']):?><p style="font-size:.88rem;color:var(--text-secondary);margin-bottom:.6rem;"><?php echo nl2br(htmlspecialchars($t['description']));?></p><?php endif;?>
            <div class="task-meta" style="font-size:.82rem;color:var(--text-muted);margin-bottom:.8rem;">
                <span><i class="ph ph-user"></i> จาก: <?php echo htmlspecialchars($t['creator_name']);?></span>
                <?php if($t['due_date']):?>
                    <span style="<?php echo $isOverdue?'color:var(--danger);font-weight:600;':'';?>">
                        <i class="ph ph-calendar"></i> กำหนด: <?php echo date('d M Y, H:i',strtotime($t['due_date']));?>
                        <?php echo $isOverdue?' ⚠️ เลยกำหนด!':'';?>
                    </span>
                <?php endif;?>
            </div>
            <?php if(!in_array($t['status'],['completed','cancelled'])):?>
            <form method="POST" class="task-actions" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <?php echo csrf_input();?>
                <input type="hidden" name="task_id" value="<?php echo $t['id'];?>">
                <input type="hidden" name="update_status" value="1">
                <?php if($t['status']==='pending'):?>
                    <button name="status" value="in_progress" class="btn btn-primary btn-sm"><i class="ph-bold ph-play"></i> เริ่มทำ</button>
                <?php endif;?>
                <?php if($t['status']==='in_progress'):?>
                    <button name="status" value="completed" class="btn btn-success btn-sm"><i class="ph-bold ph-check"></i> เสร็จแล้ว</button>
                <?php endif;?>
                <button name="status" value="pending" class="btn btn-outline btn-sm" style="<?php echo $t['status']==='pending'?'display:none;':'';?>"><i class="ph ph-arrow-counter-clockwise"></i> รีเซ็ต</button>
            </form>
            <?php endif;?>
        </div>
    <?php endforeach;?>
    </div>
<?php endif;?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
