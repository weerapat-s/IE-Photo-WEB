<?php
// admin/inventory.php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif (isset($_POST['add_equipment'])) {
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? '';
        if (!empty($name) && in_array($type, ['camera', 'lens', 'accessory'])) {
            $stmt = $pdo->prepare("INSERT INTO equipments (name, type, status) VALUES (?, ?, 'available')");
            $success = $stmt->execute([$name, $type]) ? 'เพิ่มอุปกรณ์สำเร็จ' : 'เพิ่มอุปกรณ์ไม่สำเร็จ';
        } else { $error = 'ข้อมูลไม่ถูกต้อง'; }
    } elseif (isset($_POST['update_status'])) {
        $eq_id = intval($_POST['eq_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($eq_id > 0 && in_array($status, ['available', 'borrowed', 'maintenance'])) {
            $stmt = $pdo->prepare("UPDATE equipments SET status = ? WHERE id = ?");
            $success = $stmt->execute([$status, $eq_id]) ? 'อัปเดตสถานะสำเร็จ' : 'อัปเดตไม่สำเร็จ';
        }
    } elseif (isset($_POST['delete_equipment'])) {
        $eq_id = intval($_POST['eq_id'] ?? 0);
        if ($eq_id > 0) {
            // Check if equipment is currently borrowed
            $checkStmt = $pdo->prepare("SELECT status FROM equipments WHERE id = ?");
            $checkStmt->execute([$eq_id]);
            $eq = $checkStmt->fetch();
            if ($eq && $eq['status'] === 'borrowed') {
                $error = 'ไม่สามารถลบอุปกรณ์ที่กำลังถูกยืมอยู่';
            } else {
                $stmt = $pdo->prepare("DELETE FROM equipments WHERE id = ?");
                $success = $stmt->execute([$eq_id]) ? 'ลบอุปกรณ์สำเร็จ' : 'ลบไม่สำเร็จ';
            }
        }
    } elseif (isset($_POST['edit_equipment'])) {
        $eq_id = intval($_POST['eq_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? '';
        if ($eq_id > 0 && !empty($name) && in_array($type, ['camera', 'lens', 'accessory'])) {
            $stmt = $pdo->prepare("UPDATE equipments SET name = ?, type = ? WHERE id = ?");
            $success = $stmt->execute([$name, $type, $eq_id]) ? 'แก้ไขอุปกรณ์สำเร็จ' : 'แก้ไขไม่สำเร็จ';
        } else { $error = 'ข้อมูลไม่ถูกต้อง'; }
    }
}

$stmt = $pdo->query("SELECT * FROM equipments ORDER BY type ASC, name ASC");
$equipments = $stmt->fetchAll();

// Stats
$available = 0; $borrowed = 0; $maintenance = 0;
foreach ($equipments as $eq) {
    if ($eq['status'] === 'available') $available++;
    elseif ($eq['status'] === 'borrowed') $borrowed++;
    else $maintenance++;
}

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2>คลังอุปกรณ์</h2>
    <p>จัดการข้อมูลกล้อง เลนส์ และอุปกรณ์ทั้งหมด</p>
</div>

<div class="stats-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card animate-in" style="border-left:4px solid var(--success);">
        <div class="stat-value" style="color:var(--success);font-size:1.5rem;"><?php echo $available; ?></div>
        <div class="stat-label">พร้อมใช้งาน</div>
    </div>
    <div class="stat-card animate-in" style="border-left:4px solid var(--primary);">
        <div class="stat-value" style="color:var(--primary);font-size:1.5rem;"><?php echo $borrowed; ?></div>
        <div class="stat-label">กำลังถูกยืม</div>
    </div>
    <div class="stat-card animate-in" style="border-left:4px solid var(--danger);">
        <div class="stat-value" style="color:var(--danger);font-size:1.5rem;"><?php echo $maintenance; ?></div>
        <div class="stat-label">ซ่อมบำรุง</div>
    </div>
</div>

<div class="flex-between" style="margin-bottom:1rem;">
    <h3 style="font-size:1.1rem;margin:0;">รายการอุปกรณ์ (<?php echo count($equipments); ?>)</h3>
    <button class="btn btn-primary btn-sm" onclick="toggleAddForm()"><i class="ph-bold ph-plus"></i> เพิ่มอุปกรณ์</button>
</div>

<?php if($success): ?><div class="alert alert-success"><i class="ph-bold ph-check-circle"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger"><i class="ph-bold ph-warning-circle"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<!-- Add Form -->
<div id="addForm" class="glass-card" style="display:none;margin-bottom:1.5rem;border:2px solid var(--primary);">
    <h3 style="font-size:1rem;margin-bottom:1rem;"><i class="ph-bold ph-plus-circle"></i> เพิ่มอุปกรณ์ใหม่</h3>
    <form method="POST" class="form-row" style="align-items:flex-end;">
        <?php echo csrf_input(); ?>
        <div class="form-group" style="flex:2;margin-bottom:0;"><label>ชื่ออุปกรณ์</label><input type="text" name="name" class="form-control" required placeholder="เช่น Sony A7IV"></div>
        <div class="form-group" style="flex:1;margin-bottom:0;"><label>ประเภท</label>
            <select name="type" class="form-control" required><option value="camera">📷 กล้อง</option><option value="lens">🔍 เลนส์</option><option value="accessory">📦 อุปกรณ์เสริม</option></select>
        </div>
        <div style="display:flex;gap:.5rem;"><button type="submit" name="add_equipment" class="btn btn-success btn-sm">บันทึก</button><button type="button" class="btn btn-outline btn-sm" onclick="toggleAddForm()">ยกเลิก</button></div>
    </form>
</div>

<!-- Desktop Table -->
<div class="glass-card desktop-table" style="padding:1.2rem;">
    <div class="table-responsive">
        <table class="glass-table">
            <thead><tr><th>ID</th><th>ประเภท</th><th>ชื่อ</th><th>สถานะ</th><th>เปลี่ยนสถานะ</th><th>จัดการ</th></tr></thead>
            <tbody>
            <?php foreach($equipments as $eq): ?>
                <?php
                    $label='พร้อมใช้งาน';$badgeClass='badge-approved';
                    if($eq['status']=='borrowed'){$label='กำลังถูกยืม';$badgeClass='badge-pending';}
                    if($eq['status']=='maintenance'){$label='ซ่อมบำรุง';$badgeClass='badge-rejected';}
                    $typeLabel = $eq['type']==='camera'?'📷 กล้อง':($eq['type']==='lens'?'🔍 เลนส์':'📦 อุปกรณ์เสริม');
                ?>
                <tr>
                    <td><strong>#<?php echo $eq['id'];?></strong></td>
                    <td><span class="badge" style="background:rgba(0,0,0,.04);"><?php echo $typeLabel;?></span></td>
                    <td style="font-weight:500;"><?php echo htmlspecialchars($eq['name']);?></td>
                    <td><span class="badge <?php echo $badgeClass;?>">● <?php echo $label;?></span></td>
                    <td>
                        <form method="POST" style="display:flex;gap:.3rem;align-items:center;">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="eq_id" value="<?php echo $eq['id'];?>">
                            <select name="status" class="form-control" style="width:auto;padding:.4rem .8rem;font-size:.82rem;" onchange="if(confirm('เปลี่ยนสถานะ?'))this.form.submit()">
                                <option value="available" <?php if($eq['status']=='available')echo'selected';?>>พร้อมใช้งาน</option>
                                <option value="borrowed" <?php if($eq['status']=='borrowed')echo'selected';?>>กำลังถูกยืม</option>
                                <option value="maintenance" <?php if($eq['status']=='maintenance')echo'selected';?>>ซ่อมบำรุง</option>
                            </select>
                            <input type="hidden" name="update_status" value="1">
                        </form>
                    </td>
                    <td>
                        <div style="display:flex;gap:.3rem;">
                            <form method="POST" onsubmit="return confirm('ลบอุปกรณ์นี้?');"><?php echo csrf_input();?><input type="hidden" name="eq_id" value="<?php echo $eq['id'];?>">
                                <button name="delete_equipment" value="1" class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger);padding:.35rem .6rem;"><i class="ph-bold ph-trash"></i></button></form>
                        </div>
                    </td>
                </tr>
            <?php endforeach;?>
            <?php if(empty($equipments)):?><tr><td colspan="6" class="text-center text-muted" style="padding:3rem;">ยังไม่มีอุปกรณ์ในคลัง</td></tr><?php endif;?>
            </tbody>
        </table>
    </div>
</div>

<!-- Mobile Cards -->
<div class="mobile-cards">
    <?php foreach($equipments as $eq):?>
        <?php $label='พร้อมใช้';$badgeClass='badge-approved';if($eq['status']=='borrowed'){$label='ถูกยืม';$badgeClass='badge-pending';}if($eq['status']=='maintenance'){$label='ซ่อม';$badgeClass='badge-rejected';}?>
        <div class="mobile-card animate-in">
            <div class="mc-header"><strong><?php echo htmlspecialchars($eq['name']);?></strong><span class="badge <?php echo $badgeClass;?>"><?php echo $label;?></span></div>
            <div class="mc-row"><span class="mc-label">ประเภท</span><span><?php echo $eq['type']==='camera'?'📷 กล้อง':($eq['type']==='lens'?'🔍 เลนส์':'📦 เสริม');?></span></div>
            <div class="mc-actions">
                <form method="POST" style="flex:1;display:flex;gap:.3rem;align-items:center;"><?php echo csrf_input();?><input type="hidden" name="eq_id" value="<?php echo $eq['id'];?>">
                    <select name="status" class="form-control" style="font-size:.82rem;padding:.4rem;" onchange="if(confirm('เปลี่ยน?'))this.form.submit()">
                        <option value="available" <?php if($eq['status']=='available')echo'selected';?>>พร้อม</option>
                        <option value="borrowed" <?php if($eq['status']=='borrowed')echo'selected';?>>ยืม</option>
                        <option value="maintenance" <?php if($eq['status']=='maintenance')echo'selected';?>>ซ่อม</option>
                    </select><input type="hidden" name="update_status" value="1">
                </form>
                <form method="POST" onsubmit="return confirm('ลบ?')"><?php echo csrf_input();?><input type="hidden" name="eq_id" value="<?php echo $eq['id'];?>">
                    <button name="delete_equipment" value="1" class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger);"><i class="ph-bold ph-trash"></i></button></form>
            </div>
        </div>
    <?php endforeach;?>
    <?php if(empty($equipments)):?><div class="empty-state"><i class="ph ph-package"></i><p class="text-muted">ยังไม่มีอุปกรณ์</p></div><?php endif;?>
</div>

<script>function toggleAddForm(){const f=document.getElementById('addForm');f.style.display=f.style.display==='none'?'block':'none';}</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
