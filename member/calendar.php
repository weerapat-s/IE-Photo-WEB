<?php
// member/calendar.php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$stmt = $pdo->query("
    SELECT b.id, b.booking_type, b.start_datetime, b.end_datetime, b.status,
           COALESCE(u.student_id, b.guest_name) as borrower,
           COALESCE(e.name, s.name) as item_name
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.id
    LEFT JOIN equipments e ON b.item_id = e.id AND b.booking_type = 'equipment'
    LEFT JOIN studios s ON b.item_id = s.id AND b.booking_type = 'studio'
    WHERE b.status IN ('approved', 'pending')
");
$events = [];
foreach($stmt->fetchAll() as $row) {
    $events[] = [
        'id' => $row['id'],
        'title' => ($row['booking_type'] === 'equipment' ? '📷 ' : '🎬 ') . $row['item_name'] . ' (' . $row['borrower'] . ')',
        'start' => $row['start_datetime'],
        'end' => $row['end_datetime'],
        'color' => $row['status'] === 'pending' ? '#f59e0b' : ($row['booking_type'] === 'equipment' ? '#F2531C' : '#EF3961'),
        'allDay' => false
    ];
}

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2>ปฏิทินการใช้งาน</h2>
    <p>ตรวจสอบคิวการใช้งานอุปกรณ์และห้องสตูดิโอ</p>
</div>

<div style="display:flex;gap:.5rem;justify-content:center;margin-bottom:1rem;flex-wrap:wrap;">
    <span class="badge" style="background:rgba(242,83,28,.1);color:#F2531C;">● อุปกรณ์</span>
    <span class="badge" style="background:rgba(239,57,97,.1);color:#EF3961;">● สตูดิโอ</span>
    <span class="badge" style="background:rgba(245,158,11,.1);color:#f59e0b;">● รอการอนุมัติ</span>
</div>

<div class="glass-card animate-in" style="padding:clamp(.8rem,3vw,1.5rem);">
    <div id="calendar"></div>
</div>

<link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/th.js'></script>

<style>
.fc{font-family:'Kanit',sans-serif;}
.fc-header-toolbar{margin-bottom:1.5rem!important;}
.fc-button-primary{background:var(--primary)!important;border-color:var(--primary)!important;border-radius:8px!important;padding:.4rem .8rem!important;font-size:.85rem!important;}
.fc-button-active{opacity:.8!important;}
.fc-event{border-radius:6px!important;padding:3px 6px!important;font-size:.8rem!important;border:none!important;box-shadow:0 2px 8px rgba(0,0,0,.08)!important;}
.fc-daygrid-day-number{font-weight:600;color:var(--text);}
.fc-col-header-cell-cushion{color:var(--text-secondary);font-weight:500;text-decoration:none!important;}
@media(max-width:600px){
    .fc-header-toolbar{flex-direction:column;gap:.5rem;}
    .fc-toolbar-chunk{display:flex;justify-content:center;}
}
</style>

<script>
document.addEventListener('DOMContentLoaded',function(){
    var cal=new FullCalendar.Calendar(document.getElementById('calendar'),{
        locale:'th',
        initialView:window.innerWidth<600?'listMonth':'dayGridMonth',
        headerToolbar:{left:'prev,next today',center:'title',right:'dayGridMonth,timeGridWeek,listMonth'},
        events:<?php echo json_encode($events);?>,
        eventClick:function(info){alert('รายการ: '+info.event.title+'\nเวลา: '+info.event.start.toLocaleString('th-TH'));},
        height:'auto'
    });
    cal.render();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
