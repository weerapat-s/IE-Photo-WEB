<?php
// admin/dashboard.php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$stats = [];
$stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role='member'")->fetchColumn();
$stats['pending_bookings'] = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='pending'")->fetchColumn();
$stats['active_borrowed'] = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='approved' AND start_datetime <= NOW() AND end_datetime >= NOW()")->fetchColumn();
$stats['total_equipments'] = $pdo->query("SELECT COUNT(*) FROM equipments")->fetchColumn();

$recentStmt = $pdo->query("
    SELECT b.id, b.booking_type, b.start_datetime, b.status,
           COALESCE(u.student_id, b.guest_name) as borrower
    FROM bookings b LEFT JOIN users u ON b.user_id = u.id
    ORDER BY b.created_at DESC LIMIT 5
");
$recent_bookings = $recentStmt->fetchAll();

$typeStmt = $pdo->query("SELECT booking_type, COUNT(*) as count FROM bookings GROUP BY booking_type");
$typeData = $typeStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$equipment_count = $typeData['equipment'] ?? 0;
$studio_count = $typeData['studio'] ?? 0;

$trendStmt = $pdo->query("
    SELECT DATE(created_at) as date, COUNT(*) as count
    FROM bookings WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at) ORDER BY DATE(created_at) ASC
");
$trendRows = $trendStmt->fetchAll();
$trendDates = []; $trendCounts = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $trendDates[] = date('d M', strtotime($date));
    $count = 0;
    foreach ($trendRows as $row) { if ($row['date'] === $date) { $count = $row['count']; break; } }
    $trendCounts[] = $count;
}

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2>แผงควบคุมผู้ดูแลระบบ</h2>
    <p>ภาพรวมระบบและการจัดการคำขอที่ค้างอยู่</p>
</div>

<div class="stats-grid" style="margin-bottom:2rem;">
    <div class="stat-card animate-in">
        <div class="stat-icon" style="background:rgba(242,83,28,.1);color:var(--primary);"><i class="ph-bold ph-users"></i></div>
        <div class="stat-value"><?php echo $stats['total_users']; ?></div>
        <div class="stat-label">สมาชิกทั้งหมด</div>
    </div>
    <div class="stat-card animate-in" style="border-left:4px solid var(--danger);">
        <div class="stat-icon" style="background:var(--danger-bg);color:var(--danger);"><i class="ph-bold ph-hourglass"></i></div>
        <div class="stat-value" style="color:var(--danger);"><?php echo $stats['pending_bookings']; ?></div>
        <div class="stat-label">คำขอรอตรวจสอบ</div>
    </div>
    <div class="stat-card animate-in" style="border-left:4px solid var(--success);">
        <div class="stat-icon" style="background:var(--success-bg);color:var(--success);"><i class="ph-bold ph-camera"></i></div>
        <div class="stat-value" style="color:var(--success);"><?php echo $stats['active_borrowed']; ?></div>
        <div class="stat-label">รายการที่กำลังยืม</div>
    </div>
    <div class="stat-card animate-in">
        <div class="stat-icon" style="background:rgba(59,130,246,.1);color:var(--info);"><i class="ph-bold ph-package"></i></div>
        <div class="stat-value"><?php echo $stats['total_equipments']; ?></div>
        <div class="stat-label">จำนวนอุปกรณ์</div>
    </div>
</div>

<div class="charts-grid">
    <div class="glass-card animate-in">
        <h3 style="font-size:1rem;margin-bottom:1rem;"><i class="ph-bold ph-chart-pie-slice"></i> สัดส่วนการจอง</h3>
        <div style="height:clamp(160px,30vw,220px);"><canvas id="typeChart"></canvas></div>
    </div>
    <div class="glass-card animate-in">
        <h3 style="font-size:1rem;margin-bottom:1rem;"><i class="ph-bold ph-chart-bar"></i> แนวโน้มการจอง (7 วัน)</h3>
        <div style="height:clamp(160px,30vw,220px);"><canvas id="trendChart"></canvas></div>
    </div>
</div>

<div class="glass-card animate-in" style="padding:1.5rem;">
    <div class="flex-between" style="margin-bottom:1.2rem;gap:.6rem;">
        <h3 style="font-size:1.1rem;margin:0;flex:1;"><i class="ph-bold ph-calendar-check"></i> รายการจองล่าสุด</h3>
        <a href="bookings.php" class="btn btn-outline btn-sm" style="flex-shrink:0;">ดูทั้งหมด</a>
    </div>

    <!-- Desktop -->
    <p class="table-scroll-hint"><i class="ph ph-arrow-left"></i> เลื่อนดูข้อมูลเพิ่มเติม <i class="ph ph-arrow-right"></i></p>
    <div class="table-responsive desktop-table">
        <table class="glass-table">
            <thead><tr><th>ID</th><th>ประเภท</th><th>ผู้จอง</th><th>เริ่มวันที่</th><th>สถานะ</th></tr></thead>
            <tbody>
            <?php foreach($recent_bookings as $rb): ?>
                <?php
                    $label='รอตรวจสอบ';$badgeClass='badge-pending';
                    if($rb['status']=='approved'){$label='อนุมัติแล้ว';$badgeClass='badge-approved';}
                    if($rb['status']=='rejected'){$label='ปฏิเสธแล้ว';$badgeClass='badge-rejected';}
                    if($rb['status']=='pending_return'){$label='รอตรวจคืน';$badgeClass='badge-pending';}
                    if($rb['status']=='returned'){$label='คืนแล้ว';$badgeClass='badge-returned';}
                    if($rb['status']=='cancelled'){$label='ยกเลิก';$badgeClass='badge-cancelled';}
                ?>
                <tr>
                    <td><strong>#<?php echo $rb['id'];?></strong></td>
                    <td><span class="badge" style="background:rgba(0,0,0,.04);"><?php echo $rb['booking_type']==='equipment'?'📦 อุปกรณ์':'🎬 สตูดิโอ';?></span></td>
                    <td><?php echo htmlspecialchars($rb['borrower']);?></td>
                    <td class="text-muted" style="font-size:.85rem;"><?php echo date('d M Y, H:i',strtotime($rb['start_datetime']));?></td>
                    <td><span class="badge <?php echo $badgeClass;?>"><?php echo $label;?></span></td>
                </tr>
            <?php endforeach;?>
            <?php if(empty($recent_bookings)):?><tr><td colspan="5" class="text-center text-muted">ยังไม่มีรายการจอง</td></tr><?php endif;?>
            </tbody>
        </table>
    </div>

    <!-- Mobile -->
    <div class="mobile-cards">
        <?php foreach($recent_bookings as $rb):?>
            <?php $label='รอตรวจสอบ';$badgeClass='badge-pending';if($rb['status']=='approved'){$label='อนุมัติ';$badgeClass='badge-approved';}if($rb['status']=='rejected'){$label='ปฏิเสธ';$badgeClass='badge-rejected';}if($rb['status']=='pending_return'){$label='รอตรวจคืน';$badgeClass='badge-pending';}if($rb['status']=='returned'){$label='คืนแล้ว';$badgeClass='badge-returned';}if($rb['status']=='cancelled'){$label='ยกเลิก';$badgeClass='badge-cancelled';}?>
            <div class="mobile-card">
                <div class="mc-header"><strong>#<?php echo $rb['id'];?></strong><span class="badge <?php echo $badgeClass;?>"><?php echo $label;?></span></div>
                <div class="mc-row"><span class="mc-label">ประเภท</span><span><?php echo $rb['booking_type']==='equipment'?'📦':'🎬';?></span></div>
                <div class="mc-row"><span class="mc-label">ผู้จอง</span><span><?php echo htmlspecialchars($rb['borrower']);?></span></div>
                <div class="mc-row"><span class="mc-label">วันที่</span><span style="font-size:.82rem;"><?php echo date('d M, H:i',strtotime($rb['start_datetime']));?></span></div>
            </div>
        <?php endforeach;?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('typeChart').getContext('2d'),{type:'doughnut',data:{labels:['อุปกรณ์','สตูดิโอ'],datasets:[{data:[<?php echo $equipment_count;?>,<?php echo $studio_count;?>],backgroundColor:['#F2531C','#EF3961'],borderWidth:0,hoverOffset:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});
new Chart(document.getElementById('trendChart').getContext('2d'),{type:'bar',data:{labels:<?php echo json_encode($trendDates);?>,datasets:[{label:'จำนวน',data:<?php echo json_encode($trendCounts);?>,backgroundColor:'#F2531C',borderRadius:8}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,ticks:{precision:0}}},plugins:{legend:{display:false}}}});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
