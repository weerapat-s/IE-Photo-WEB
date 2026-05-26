<?php
// admin/ip_manager.php — IP block management & login log viewer
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || !is_super_admin()) {
    header("Location: ../auth/login.php");
    exit;
}

ip_ensure_tables($pdo);

$success = '';
$error   = '';

// ── Handle POST actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'block') {
            $ip       = trim($_POST['ip'] ?? '');
            $duration = intval($_POST['duration'] ?? 0); // 0 = permanent
            $reason   = trim($_POST['reason'] ?? 'Blocked by admin');
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                ip_block_manual($pdo, $ip, $duration > 0 ? $duration * 3600 : null, htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'));
                $success = "บล็อก {$ip} เรียบร้อยแล้ว";
            } else {
                $error = 'IP address ไม่ถูกต้อง';
            }

        } elseif ($action === 'unblock') {
            $ip = trim($_POST['ip'] ?? '');
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                ip_unblock($pdo, $ip);
                $success = "ปลดบล็อก {$ip} เรียบร้อยแล้ว";
            } else {
                $error = 'IP address ไม่ถูกต้อง';
            }

        } elseif ($action === 'cleanup') {
            ip_cleanup($pdo);
            $success = 'ลบ log เก่าและ expired blocks เรียบร้อยแล้ว';
        }
    }
}

// ── Statistics & data (all wrapped — tables may not exist yet) ───────────────
$stats      = ['active_blocks'=>0,'failed_today'=>0,'success_today'=>0,'unique_ips_today'=>0];
$blocks     = [];
$topFails   = [];
$recentLogs = [];
$dbError    = false;

try {
    $stats['active_blocks']    = (int) $pdo->query("SELECT COUNT(*) FROM ip_blocks WHERE blocked_until IS NULL OR blocked_until > NOW()")->fetchColumn();
    $stats['failed_today']     = (int) $pdo->query("SELECT COUNT(*) FROM login_logs WHERE success=0 AND created_at >= CURDATE()")->fetchColumn();
    $stats['success_today']    = (int) $pdo->query("SELECT COUNT(*) FROM login_logs WHERE success=1 AND created_at >= CURDATE()")->fetchColumn();
    $stats['unique_ips_today'] = (int) $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM login_logs WHERE created_at >= CURDATE()")->fetchColumn();

    $blocks = $pdo->query("
        SELECT *, CASE WHEN blocked_until IS NULL THEN 'permanent'
                       ELSE IF(blocked_until > NOW(), 'active', 'expired') END AS block_status
        FROM ip_blocks ORDER BY updated_at DESC LIMIT 100
    ")->fetchAll();

    $topFails = $pdo->query("
        SELECT ip_address, COUNT(*) as attempts,
               MAX(created_at) as last_attempt, SUM(success) as successes
        FROM login_logs
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY ip_address ORDER BY attempts DESC LIMIT 20
    ")->fetchAll();

    $recentLogs = $pdo->query("
        SELECT l.*, CASE WHEN b.ip_address IS NOT NULL THEN 1 ELSE 0 END as is_blocked
        FROM login_logs l
        LEFT JOIN ip_blocks b ON l.ip_address = b.ip_address
            AND (b.blocked_until IS NULL OR b.blocked_until > NOW())
        ORDER BY l.created_at DESC LIMIT 100
    ")->fetchAll();
} catch (Exception $e) {
    $dbError = 'ไม่สามารถโหลดข้อมูลได้: ตารางยังไม่พร้อม กรุณารอสักครู่แล้วรีเฟรช (' . $e->getMessage() . ')';
    error_log('ip_manager.php DB error: ' . $e->getMessage());
}

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2><i class="ph-bold ph-shield-warning"></i> จัดการ IP & Log การเข้าสู่ระบบ</h2>
    <p>ตรวจสอบการพยายาม login, บล็อก/ปลดบล็อก IP</p>
</div>

<?php if($success):?><div class="alert alert-success"><i class="ph-bold ph-check-circle"></i> <?php echo htmlspecialchars($success);?></div><?php endif;?>
<?php if($error):?><div class="alert alert-danger"><i class="ph-bold ph-warning-circle"></i> <?php echo htmlspecialchars($error);?></div><?php endif;?>
<?php if($dbError):?><div class="alert alert-danger"><i class="ph-bold ph-warning-circle"></i> <?php echo htmlspecialchars($dbError);?></div><?php endif;?>

<!-- Stats -->
<div class="stats-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card animate-in" style="border-left:4px solid var(--danger);">
        <div class="stat-value" style="color:var(--danger);"><?php echo $stats['active_blocks'];?></div>
        <div class="stat-label">IP ที่ถูกบล็อก</div>
    </div>
    <div class="stat-card animate-in" style="border-left:4px solid var(--warning);">
        <div class="stat-value" style="color:var(--warning);"><?php echo $stats['failed_today'];?></div>
        <div class="stat-label">ล้มเหลววันนี้</div>
    </div>
    <div class="stat-card animate-in" style="border-left:4px solid var(--success);">
        <div class="stat-value" style="color:var(--success);"><?php echo $stats['success_today'];?></div>
        <div class="stat-label">สำเร็จวันนี้</div>
    </div>
    <div class="stat-card animate-in" style="border-left:4px solid var(--info);">
        <div class="stat-value" style="color:var(--info);"><?php echo $stats['unique_ips_today'];?></div>
        <div class="stat-label">IP ต่างกันวันนี้</div>
    </div>
</div>

<div class="grid-2" style="margin-bottom:1.5rem;">

    <!-- Manual Block Form -->
    <div class="glass-card animate-in">
        <h3 style="font-size:1.05rem;margin-bottom:1rem;"><i class="ph-bold ph-prohibit"></i> บล็อก IP ด้วยตนเอง</h3>
        <form method="POST">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="block">
            <div class="form-group">
                <label>IP Address</label>
                <input type="text" name="ip" class="form-control" placeholder="203.0.113.1" required pattern="^[\d\.\:a-fA-F]+$">
            </div>
            <div class="form-group">
                <label>ระยะเวลา (ชั่วโมง) — 0 = permanent</label>
                <input type="number" name="duration" class="form-control" value="24" min="0" max="8760">
            </div>
            <div class="form-group">
                <label>เหตุผล</label>
                <input type="text" name="reason" class="form-control" placeholder="Blocked by admin" value="Blocked by admin" maxlength="255">
            </div>
            <button type="submit" class="btn btn-danger w-100"><i class="ph-bold ph-prohibit"></i> บล็อก IP นี้</button>
        </form>

        <hr style="margin:1rem 0;border-color:var(--border);">
        <form method="POST" onsubmit="return confirm('ลบ log เก่าและ expired blocks?')">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="cleanup">
            <button type="submit" class="btn btn-outline w-100" style="color:var(--text-muted);"><i class="ph ph-broom"></i> ล้าง log เก่า / expired blocks</button>
        </form>
    </div>

    <!-- Top Failing IPs -->
    <div class="glass-card animate-in">
        <h3 style="font-size:1.05rem;margin-bottom:1rem;"><i class="ph-bold ph-chart-bar"></i> IP พยายาม login มากสุด (24 ชม.)</h3>
        <?php if(empty($topFails)):?>
            <div class="empty-state" style="padding:2rem;"><i class="ph ph-check-circle" style="color:var(--success);"></i><p class="text-muted">ไม่มี activity ผิดปกติ</p></div>
        <?php else:?>
            <div style="display:flex;flex-direction:column;gap:.4rem;">
            <?php foreach($topFails as $tf):
                $failRate = $tf['attempts'] > 0 ? round((($tf['attempts'] - $tf['successes']) / $tf['attempts']) * 100) : 0;
                $isHigh = ($tf['attempts'] - $tf['successes']) >= 10;
            ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem .8rem;background:<?php echo $isHigh?'rgba(239,68,68,.06)':'rgba(0,0,0,.03)';?>;border-radius:var(--radius-xs);border:1px solid <?php echo $isHigh?'rgba(239,68,68,.15)':'var(--border)';?>;">
                    <div>
                        <code style="font-size:.85rem;font-weight:600;"><?php echo htmlspecialchars($tf['ip_address']);?></code>
                        <div style="font-size:.75rem;color:var(--text-muted);">ล่าสุด: <?php echo date('d M, H:i', strtotime($tf['last_attempt']));?></div>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <span class="badge" style="background:<?php echo $isHigh?'rgba(239,68,68,.1)':'rgba(0,0,0,.05)';?>;color:<?php echo $isHigh?'var(--danger)':'var(--text)';?>;">
                            <?php echo ($tf['attempts'] - $tf['successes']);?> ล้มเหลว
                        </span>
                        <form method="POST" style="display:inline;">
                            <?php echo csrf_input();?>
                            <input type="hidden" name="action" value="block">
                            <input type="hidden" name="ip" value="<?php echo htmlspecialchars($tf['ip_address']);?>">
                            <input type="hidden" name="duration" value="24">
                            <input type="hidden" name="reason" value="Auto-block by admin: suspicious activity">
                            <button class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger);padding:.25rem .45rem;" title="บล็อก IP นี้"><i class="ph-bold ph-prohibit"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach;?>
            </div>
        <?php endif;?>
    </div>
</div>

<!-- Active Blocks Table -->
<div class="glass-card animate-in" style="margin-bottom:1.5rem;">
    <h3 style="font-size:1.05rem;margin-bottom:1rem;"><i class="ph-bold ph-shield-slash"></i> IP ที่ถูกบล็อกอยู่</h3>
    <?php if(empty($blocks)):?>
        <div class="empty-state" style="padding:2rem;"><i class="ph ph-shield-check" style="color:var(--success);"></i><p class="text-muted">ไม่มี IP ที่ถูกบล็อก</p></div>
    <?php else:?>
    <p class="table-scroll-hint"><i class="ph ph-arrow-left"></i> เลื่อนดูข้อมูลเพิ่มเติม <i class="ph ph-arrow-right"></i></p>
    <div class="table-responsive">
        <table class="glass-table">
            <thead><tr><th>IP Address</th><th>ประเภท</th><th>เหตุผล</th><th>หมดอายุ</th><th>อัปเดต</th><th>จัดการ</th></tr></thead>
            <tbody>
            <?php foreach($blocks as $b):
                $isPermanent = $b['blocked_until'] === null;
                $isActive    = $b['block_status'] === 'active' || $b['block_status'] === 'permanent';
            ?>
                <tr style="<?php echo !$isActive?'opacity:.5;':'';?>">
                    <td><code style="font-weight:600;"><?php echo htmlspecialchars($b['ip_address']);?></code></td>
                    <td>
                        <span class="badge" style="background:<?php echo $b['blocked_by']==='admin'?'rgba(99,102,241,.1)':'rgba(239,68,68,.1)';?>;color:<?php echo $b['blocked_by']==='admin'?'#6366f1':'var(--danger)';?>;">
                            <?php echo $b['blocked_by']==='admin'?'👤 Manual':'🤖 Auto';?>
                        </span>
                    </td>
                    <td style="font-size:.82rem;max-width:200px;"><?php echo htmlspecialchars($b['reason']);?></td>
                    <td style="font-size:.82rem;">
                        <?php if($isPermanent):?>
                            <span style="color:var(--danger);font-weight:600;">Permanent</span>
                        <?php elseif($isActive):?>
                            <?php echo date('d M Y, H:i', strtotime($b['blocked_until']));?>
                        <?php else:?>
                            <span class="text-muted">หมดอายุแล้ว</span>
                        <?php endif;?>
                    </td>
                    <td style="font-size:.78rem;color:var(--text-muted);"><?php echo date('d M, H:i', strtotime($b['updated_at']));?></td>
                    <td>
                        <?php if($isActive):?>
                        <form method="POST" onsubmit="return confirm('ปลดบล็อก <?php echo htmlspecialchars($b['ip_address']);?>?')">
                            <?php echo csrf_input();?>
                            <input type="hidden" name="action" value="unblock">
                            <input type="hidden" name="ip" value="<?php echo htmlspecialchars($b['ip_address']);?>">
                            <button class="btn btn-outline btn-sm" style="color:var(--success);border-color:var(--success);padding:.3rem .5rem;"><i class="ph-bold ph-lock-open"></i></button>
                        </form>
                        <?php else:?><span class="text-muted">—</span><?php endif;?>
                    </td>
                </tr>
            <?php endforeach;?>
            </tbody>
        </table>
    </div>
    <?php endif;?>
</div>

<!-- Recent Login Log -->
<div class="glass-card animate-in">
    <h3 style="font-size:1.05rem;margin-bottom:1rem;"><i class="ph-bold ph-list-bullets"></i> Login Log ล่าสุด (100 รายการ)</h3>
    <p class="table-scroll-hint"><i class="ph ph-arrow-left"></i> เลื่อนดูข้อมูลเพิ่มเติม <i class="ph ph-arrow-right"></i></p>
    <div class="table-responsive">
        <table class="glass-table">
            <thead><tr><th>เวลา</th><th>IP Address</th><th>Identifier</th><th>ผล</th><th>User-Agent</th></tr></thead>
            <tbody>
            <?php foreach($recentLogs as $log):?>
                <tr style="<?php echo $log['is_blocked']?'background:rgba(239,68,68,.03);':'';?>">
                    <td style="font-size:.78rem;white-space:nowrap;"><?php echo date('d M, H:i:s', strtotime($log['created_at']));?></td>
                    <td>
                        <code style="font-size:.82rem;"><?php echo htmlspecialchars($log['ip_address']);?></code>
                        <?php if($log['is_blocked']):?><span title="ถูกบล็อก" style="color:var(--danger);margin-left:.3rem;"><i class="ph-bold ph-prohibit"></i></span><?php endif;?>
                    </td>
                    <td style="font-size:.82rem;"><?php echo $log['identifier'] ? htmlspecialchars($log['identifier']) : '<span class="text-muted">—</span>';?></td>
                    <td>
                        <?php if($log['success']):?>
                            <span class="badge badge-approved">✓ สำเร็จ</span>
                        <?php else:?>
                            <span class="badge badge-rejected">✗ ล้มเหลว</span>
                        <?php endif;?>
                    </td>
                    <td style="font-size:.75rem;color:var(--text-muted);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($log['user_agent'] ?? '');?>">
                        <?php echo htmlspecialchars(substr($log['user_agent'] ?? '—', 0, 60));?>
                    </td>
                </tr>
            <?php endforeach;?>
            <?php if(empty($recentLogs)):?><tr><td colspan="5" class="text-center text-muted" style="padding:2rem;">ยังไม่มี log</td></tr><?php endif;?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
