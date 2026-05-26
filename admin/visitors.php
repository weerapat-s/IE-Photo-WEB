<?php
// admin/visitors.php — Real-time visitor monitoring
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || !is_super_admin()) {
    header("Location: ../auth/login.php");
    exit;
}

visitor_ensure_table($pdo);

// ── Stats ────────────────────────────────────────────────────────────────────
$now   = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$dbError = false;

$onlineNow = $uniqueToday = $totalToday = $memberToday = $guestToday = 0;
$onlineList = $topPages = $topIps = $recentVisits = [];
$hourlyHits = array_fill(0, 24, 0);
$hourlyUniq = array_fill(0, 24, 0);

try {
    $onlineNow   = (int) $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visitor_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();
    $uniqueToday = (int) $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visitor_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $totalToday  = (int) $pdo->query("SELECT COUNT(*) FROM visitor_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $memberToday = (int) $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visitor_logs WHERE DATE(created_at) = CURDATE() AND user_id IS NOT NULL")->fetchColumn();
    $guestToday  = $uniqueToday - $memberToday;

    // ── Online now (last 5 min) ───────────────────────────────────────────────
    $onlineList = $pdo->query("
        SELECT ip_address, MAX(user_id) as user_id, MAX(role) as role,
               MAX(page_url) as last_page, MAX(created_at) as last_seen, COUNT(*) as hits
        FROM visitor_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        GROUP BY ip_address ORDER BY last_seen DESC LIMIT 50
    ")->fetchAll();

    // ── Top pages today ───────────────────────────────────────────────────────
    $topPages = $pdo->query("
        SELECT page_url, COUNT(*) as hits, COUNT(DISTINCT ip_address) as unique_ips
        FROM visitor_logs WHERE DATE(created_at) = CURDATE()
        GROUP BY page_url ORDER BY hits DESC LIMIT 15
    ")->fetchAll();

    // ── Top IPs today ─────────────────────────────────────────────────────────
    $topIps = $pdo->query("
        SELECT ip_address, COUNT(*) as hits, MAX(user_id) as user_id,
               MAX(role) as role, MAX(created_at) as last_seen
        FROM visitor_logs WHERE DATE(created_at) = CURDATE()
        GROUP BY ip_address ORDER BY hits DESC LIMIT 20
    ")->fetchAll();

    // ── Recent visits (live log) ──────────────────────────────────────────────
    $recentVisits = $pdo->query("
        SELECT v.*, u.student_id FROM visitor_logs v
        LEFT JOIN users u ON v.user_id = u.id
        ORDER BY v.created_at DESC LIMIT 100
    ")->fetchAll();

    // ── Hourly chart (today) ──────────────────────────────────────────────────
    $hourlyData = $pdo->query("
        SELECT HOUR(created_at) as hr, COUNT(*) as hits, COUNT(DISTINCT ip_address) as uniq
        FROM visitor_logs WHERE DATE(created_at) = CURDATE()
        GROUP BY HOUR(created_at) ORDER BY hr
    ")->fetchAll();
    foreach ($hourlyData as $h) {
        $hourlyHits[(int)$h['hr']] = (int)$h['hits'];
        $hourlyUniq[(int)$h['hr']] = (int)$h['uniq'];
    }
} catch (Exception $e) {
    $dbError = 'ไม่สามารถโหลดข้อมูลได้: ตารางยังไม่พร้อม กรุณารอสักครู่แล้วรีเฟรช (' . $e->getMessage() . ')';
    error_log('visitors.php DB error: ' . $e->getMessage());
}

// ── Geolocation batch lookup ─────────────────────────────────────────────────
// รวบรวม IP ทั้งหมดที่แสดงในหน้านี้ แล้วดึง geo พร้อมกัน (cached 24h)
$allIps = array_unique(array_filter(array_merge(
    array_column($onlineList,  'ip_address'),
    array_column($topIps,      'ip_address'),
    array_column($recentVisits,'ip_address')
)));
$geos = !empty($allIps) ? get_ip_geos($pdo, array_values($allIps)) : [];

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2><i class="ph-bold ph-eye"></i> ผู้เข้าชมเว็บไซต์</h2>
    <p>ติดตาม IP และพฤติกรรมผู้เข้าใช้งานแบบ real-time</p>
</div>

<?php if($dbError):?>
<div class="alert alert-danger"><i class="ph-bold ph-warning-circle"></i> <?php echo htmlspecialchars($dbError);?></div>
<?php endif;?>

<!-- Stats -->
<div class="stats-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card animate-in" style="border-left:4px solid var(--success);">
        <div class="stat-value" style="color:var(--success);" id="online-count"><?php echo $onlineNow;?></div>
        <div class="stat-label">🟢 Online ตอนนี้ (5 นาที)</div>
    </div>
    <div class="stat-card animate-in" style="border-left:4px solid var(--primary);">
        <div class="stat-value" style="color:var(--primary);"><?php echo $uniqueToday;?></div>
        <div class="stat-label">IP ต่างกันวันนี้</div>
    </div>
    <div class="stat-card animate-in" style="border-left:4px solid var(--info);">
        <div class="stat-value" style="color:var(--info);"><?php echo $totalToday;?></div>
        <div class="stat-label">Page views วันนี้</div>
    </div>
    <div class="stat-card animate-in" style="border-left:4px solid var(--warning);">
        <div class="stat-value" style="color:var(--warning);"><?php echo $memberToday;?> / <?php echo $guestToday;?></div>
        <div class="stat-label">สมาชิก / Guest วันนี้</div>
    </div>
</div>

<!-- Online Now -->
<div class="glass-card animate-in" style="margin-bottom:1.5rem;">
    <div class="flex-between" style="margin-bottom:1rem;">
        <h3 style="font-size:1.05rem;margin:0;"><i class="ph-bold ph-broadcast"></i> Online ตอนนี้
            <span id="online-badge" style="background:var(--success);color:#fff;border-radius:99px;padding:.15rem .6rem;font-size:.78rem;margin-left:.5rem;"><?php echo count($onlineList);?></span>
        </h3>
        <button onclick="location.reload()" class="btn btn-outline btn-sm"><i class="ph ph-arrows-clockwise"></i> รีเฟรช</button>
    </div>

    <?php if(empty($onlineList)):?>
        <div class="empty-state" style="padding:2rem;"><i class="ph ph-wifi-x"></i><p class="text-muted">ยังไม่มีผู้เข้าชมใน 5 นาทีที่ผ่านมา</p></div>
    <?php else:?>
    <div style="display:flex;flex-direction:column;gap:.4rem;">
    <?php foreach($onlineList as $ol):
        $isAdmin  = in_array($ol['role'], ['admin','super_admin']);
        $isMember = $ol['user_id'] !== null;
        $pageShort = preg_replace('/\?.*$/', '', basename($ol['last_page']));
        $geo = $geos[$ol['ip_address']] ?? [];
        $flag = country_flag($geo['country_code'] ?? '');
        $loc  = trim(($geo['city'] ?? '') . ($geo['city'] && $geo['country'] ? ', ' : '') . ($geo['country'] ?? ''));
        $isp  = $geo['isp'] ?? '';
    ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.65rem .9rem;background:rgba(34,197,94,.05);border-radius:var(--radius-xs);border:1px solid rgba(34,197,94,.15);">
            <div style="display:flex;align-items:center;gap:.8rem;">
                <span style="width:8px;height:8px;border-radius:50%;background:var(--success);box-shadow:0 0 6px var(--success);flex-shrink:0;"></span>
                <div>
                    <code style="font-size:.85rem;font-weight:600;"><?php echo htmlspecialchars($ol['ip_address']);?></code>
                    <?php if($isAdmin):?>
                        <span class="badge" style="background:rgba(99,102,241,.12);color:#6366f1;margin-left:.4rem;">👤 Admin</span>
                    <?php elseif($isMember):?>
                        <span class="badge badge-approved" style="margin-left:.4rem;">✓ สมาชิก</span>
                    <?php else:?>
                        <span class="badge" style="background:rgba(0,0,0,.06);margin-left:.4rem;">Guest</span>
                    <?php endif;?>
                    <div style="font-size:.75rem;color:var(--text-muted);margin-top:.15rem;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;">
                        <?php if($loc):?><span><?php echo $flag;?> <?php echo htmlspecialchars($loc);?></span><?php endif;?>
                        <?php if($isp):?><span style="opacity:.7;">· <?php echo htmlspecialchars($isp);?></span><?php endif;?>
                        <span>· <i class="ph ph-file-text"></i> <?php echo htmlspecialchars($pageShort ?: '/');?> · <?php echo $ol['hits'];?> hits</span>
                    </div>
                </div>
            </div>
            <div style="font-size:.75rem;color:var(--text-muted);text-align:right;">
                <?php
                    $diff = time() - strtotime($ol['last_seen']);
                    echo $diff < 60 ? $diff . ' วิที่แล้ว' : round($diff/60) . ' นาทีที่แล้ว';
                ?>
            </div>
        </div>
    <?php endforeach;?>
    </div>
    <?php endif;?>
</div>

<div class="grid-2" style="margin-bottom:1.5rem;">

    <!-- Top Pages -->
    <div class="glass-card animate-in">
        <h3 style="font-size:1.05rem;margin-bottom:1rem;"><i class="ph-bold ph-file-text"></i> หน้ายอดนิยมวันนี้</h3>
        <?php if(empty($topPages)):?>
            <div class="empty-state" style="padding:2rem;"><p class="text-muted">ยังไม่มีข้อมูล</p></div>
        <?php else:?>
        <?php
            $maxHits = max(array_column($topPages, 'hits')) ?: 1;
        ?>
        <div style="display:flex;flex-direction:column;gap:.5rem;">
        <?php foreach($topPages as $p):
            $pageShort = preg_replace('/\?.*$/', '', basename($p['page_url']));
            $barWidth  = round(($p['hits'] / $maxHits) * 100);
        ?>
            <div>
                <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:.2rem;">
                    <span title="<?php echo htmlspecialchars($p['page_url']);?>"><?php echo htmlspecialchars($pageShort ?: '/');?></span>
                    <span style="color:var(--text-muted);"><?php echo $p['hits'];?> hits · <?php echo $p['unique_ips'];?> IPs</span>
                </div>
                <div style="height:6px;background:rgba(0,0,0,.07);border-radius:3px;">
                    <div style="width:<?php echo $barWidth;?>%;height:100%;background:var(--primary);border-radius:3px;"></div>
                </div>
            </div>
        <?php endforeach;?>
        </div>
        <?php endif;?>
    </div>

    <!-- Top IPs -->
    <div class="glass-card animate-in">
        <h3 style="font-size:1.05rem;margin-bottom:1rem;"><i class="ph-bold ph-globe"></i> Top IP วันนี้</h3>
        <?php if(empty($topIps)):?>
            <div class="empty-state" style="padding:2rem;"><p class="text-muted">ยังไม่มีข้อมูล</p></div>
        <?php else:?>
        <div style="display:flex;flex-direction:column;gap:.35rem;">
        <?php foreach($topIps as $ti):
            $isMember = $ti['user_id'] !== null;
            $geo = $geos[$ti['ip_address']] ?? [];
            $flag = country_flag($geo['country_code'] ?? '');
            $loc  = trim(($geo['city'] ?? '') . ($geo['city'] && $geo['country'] ? ', ' : '') . ($geo['country'] ?? ''));
            $isp  = $geo['isp'] ?? '';
        ?>
            <div style="padding:.55rem .7rem;background:rgba(0,0,0,.025);border-radius:var(--radius-xs);">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <code style="font-size:.83rem;"><?php echo htmlspecialchars($ti['ip_address']);?></code>
                        <?php if($isMember):?>
                            <span style="font-size:.72rem;color:var(--success);margin-left:.3rem;"><?php echo in_array($ti['role'],['admin','super_admin'])?'admin':'สมาชิก';?></span>
                        <?php endif;?>
                    </div>
                    <span style="font-size:.8rem;font-weight:600;color:var(--primary);"><?php echo $ti['hits'];?> hits</span>
                </div>
                <?php if($loc || $isp):?>
                <div style="font-size:.72rem;color:var(--text-muted);margin-top:.15rem;">
                    <?php if($loc):?><?php echo $flag;?> <?php echo htmlspecialchars($loc);?><?php endif;?>
                    <?php if($isp):?><span style="opacity:.7;"> · <?php echo htmlspecialchars($isp);?></span><?php endif;?>
                </div>
                <?php endif;?>
            </div>
        <?php endforeach;?>
        </div>
        <?php endif;?>
    </div>
</div>

<!-- Hourly Chart (Today) -->
<div class="glass-card animate-in" style="margin-bottom:1.5rem;">
    <h3 style="font-size:1.05rem;margin-bottom:1rem;"><i class="ph-bold ph-chart-bar"></i> Page views รายชั่วโมง (วันนี้)</h3>
    <div style="display:flex;align-items:flex-end;gap:3px;height:80px;padding:0 .25rem;">
    <?php
        $maxH = max($hourlyHits) ?: 1;
        $currentHour = (int) date('H');
        for ($h = 0; $h < 24; $h++):
            $barH   = round(($hourlyHits[$h] / $maxH) * 100);
            $isCur  = $h === $currentHour;
            $label  = sprintf('%02d', $h);
    ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;" title="<?php echo "{$label}:00 — {$hourlyHits[$h]} hits, {$hourlyUniq[$h]} IPs";?>">
            <div style="width:100%;background:<?php echo $isCur?'var(--primary)':'rgba(242,83,28,.3)';?>;border-radius:2px 2px 0 0;height:<?php echo max($barH, $hourlyHits[$h]>0?4:0);?>%;min-height:<?php echo $hourlyHits[$h]>0?'4px':'0';?>;transition:height .3s;"></div>
        </div>
    <?php endfor;?>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:.68rem;color:var(--text-muted);padding:.3rem .25rem 0;">
        <span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>23:00</span>
    </div>
</div>

<!-- Live Log -->
<div class="glass-card animate-in">
    <div class="flex-between" style="margin-bottom:1rem;">
        <h3 style="font-size:1.05rem;margin:0;"><i class="ph-bold ph-list-bullets"></i> Visitor Log ล่าสุด (100 รายการ)</h3>
        <a href="visitors.php" class="btn btn-outline btn-sm"><i class="ph ph-arrows-clockwise"></i> รีเฟรช</a>
    </div>
    <p class="table-scroll-hint"><i class="ph ph-arrow-left"></i> เลื่อนดูข้อมูลเพิ่มเติม <i class="ph ph-arrow-right"></i></p>
    <div class="table-responsive">
        <table class="glass-table">
            <thead>
                <tr><th>เวลา</th><th>IP</th><th>ตำแหน่ง</th><th>ผู้ใช้</th><th>หน้า</th><th>Method</th><th>User-Agent</th></tr>
            </thead>
            <tbody>
            <?php foreach($recentVisits as $v):
                $pageShort  = htmlspecialchars(preg_replace('/\?.*$/', '', basename($v['page_url'])) ?: '/');
                $isPost     = $v['http_method'] === 'POST';
                $geo  = $geos[$v['ip_address']] ?? [];
                $flag = country_flag($geo['country_code'] ?? '');
                $city = $geo['city'] ?? '';
                $ctry = $geo['country'] ?? '';
                $isp  = $geo['isp'] ?? '';
                $loc  = trim($city . ($city && $ctry ? ', ' : '') . $ctry);
            ?>
                <tr>
                    <td style="font-size:.75rem;white-space:nowrap;"><?php echo date('d M, H:i:s', strtotime($v['created_at']));?></td>
                    <td><code style="font-size:.8rem;"><?php echo htmlspecialchars($v['ip_address']);?></code></td>
                    <td style="font-size:.78rem;min-width:130px;">
                        <?php if($loc):?>
                            <div><?php echo $flag;?> <?php echo htmlspecialchars($loc);?></div>
                        <?php endif;?>
                        <?php if($isp):?>
                            <div style="color:var(--text-muted);font-size:.7rem;margin-top:.1rem;"><?php echo htmlspecialchars($isp);?></div>
                        <?php endif;?>
                    </td>
                    <td style="font-size:.82rem;">
                        <?php if($v['user_id']):?>
                            <span style="color:<?php echo in_array($v['role'],['admin','super_admin'])?'#6366f1':'var(--success)';?>;">
                                <?php echo in_array($v['role'],['admin','super_admin'])?'👤 admin':'✓ '.htmlspecialchars($v['student_id']??'');?>
                            </span>
                        <?php else:?>
                            <span class="text-muted">Guest</span>
                        <?php endif;?>
                    </td>
                    <td style="font-size:.8rem;" title="<?php echo htmlspecialchars($v['page_url']);?>"><?php echo $pageShort;?></td>
                    <td>
                        <span style="font-size:.72rem;padding:.15rem .4rem;border-radius:4px;background:<?php echo $isPost?'rgba(99,102,241,.1)':'rgba(34,197,94,.08)';?>;color:<?php echo $isPost?'#6366f1':'var(--success)';?>;"><?php echo htmlspecialchars($v['http_method']);?></span>
                    </td>
                    <td style="font-size:.72rem;color:var(--text-muted);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($v['user_agent']??'');?>">
                        <?php echo htmlspecialchars(substr($v['user_agent'] ?? '—', 0, 55));?>
                    </td>
                </tr>
            <?php endforeach;?>
            <?php if(empty($recentVisits)):?><tr><td colspan="6" class="text-center text-muted" style="padding:2rem;">ยังไม่มีข้อมูล</td></tr><?php endif;?>
            </tbody>
        </table>
    </div>
</div>

<!-- Auto-refresh every 30s -->
<script>
(function(){
    var countdown = 30;
    var el = document.createElement('div');
    el.style.cssText = 'position:fixed;bottom:80px;right:16px;background:rgba(0,0,0,.65);color:#fff;padding:.35rem .7rem;border-radius:99px;font-size:.72rem;z-index:500;backdrop-filter:blur(8px);';
    document.body.appendChild(el);
    var t = setInterval(function(){
        countdown--;
        el.textContent = 'รีเฟรชใน ' + countdown + ' วิ';
        if(countdown <= 0){ clearInterval(t); location.reload(); }
    }, 1000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
