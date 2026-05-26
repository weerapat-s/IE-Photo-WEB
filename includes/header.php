<?php
// includes/header.php — Mobile-First with Bottom Nav
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
if (!isset($base_url)) $base_url = '../';

// ── Visitor tracking — บันทึกทุก page visit ─────────────────────────────────
if (isset($pdo)) log_visit($pdo);

// Detect current page for active state
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
function isActive($page) {
    global $currentPage;
    return $currentPage === $page ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="theme-color" content="#ffffff">
    <title>IE-Photo Booking System</title>
    <meta name="description" content="ระบบจองอุปกรณ์ถ่ายภาพและสตูดิโอ IE-Photo KMITL">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $base_url; ?>assets/css/glassmorphism.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<?php
    $__role = $_SESSION['role'] ?? '';
    $__bodyClass = match($__role) {
        'super_admin' => ' class="is-admin is-super-admin"',
        'admin'       => ' class="is-admin"',
        default       => '',
    };
?>
<body<?php echo $__bodyClass; ?>>
    <div class="bg-orbs"></div>

    <!-- Top Navbar -->
    <nav class="glass-navbar">
        <div class="nav-container">
            <a href="<?php echo $base_url; ?>auth/login.php" class="nav-brand">
                <i class="ph-bold ph-camera" style="margin-right:3px"></i> IE-PHOTO
            </a>

            <?php if($__role === 'admin' || $__role === 'super_admin'): ?>
            <button class="mobile-toggle" id="mobile-toggle-btn" aria-label="Menu" style="margin-left:auto;">
                <i class="ph-bold ph-list"></i>
            </button>
            <?php endif; ?>

            <div class="nav-links" id="nav-links">

                <?php if($__role === 'admin' || $__role === 'super_admin'): ?>
                <!-- ปุ่ม ✕ ปิด panel -->
                <button id="nav-close-btn" aria-label="ปิดเมนู"
                    style="position:absolute;top:14px;right:14px;background:none;border:none;
                           font-size:1.4rem;cursor:pointer;color:var(--text-muted);
                           min-width:44px;min-height:44px;display:flex;
                           align-items:center;justify-content:center;z-index:1076;">
                    <i class="ph-bold ph-x"></i>
                </button>
                <?php endif; ?>

                <?php if(isset($_SESSION['user_id'])): ?>

                    <?php if($__role === 'super_admin'): ?>
                    <!-- ══ SUPER ADMIN nav ══════════════════════════════════════ -->
                    <div class="nav-role-badge" style="padding:.5rem 1rem .25rem;font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#a78bfa;border-bottom:1px solid rgba(167,139,250,.2);margin-bottom:.25rem;">⭐ Super Admin</div>
                        <a href="<?php echo $base_url; ?>admin/dashboard.php" class="<?php echo isActive('dashboard');?>"><i class="ph ph-squares-four"></i> แดชบอร์ด</a>
                        <a href="<?php echo $base_url; ?>admin/bookings.php" class="<?php echo isActive('bookings');?>"><i class="ph ph-list-checks"></i> รายการจอง</a>
                        <a href="<?php echo $base_url; ?>member/borrow_form.php" class="<?php echo isActive('borrow_form');?>"><i class="ph ph-hand-grabbing"></i> ยืมอุปกรณ์</a>
                        <a href="<?php echo $base_url; ?>guest/studio_booking.php" class="<?php echo isActive('studio_booking');?>"><i class="ph ph-video-camera"></i> จองสตูดิโอ</a>
                        <a href="<?php echo $base_url; ?>admin/inventory.php" class="<?php echo isActive('inventory');?>"><i class="ph ph-package"></i> คลังอุปกรณ์</a>
                        <a href="<?php echo $base_url; ?>admin/tasks.php" class="<?php echo isActive('tasks');?>"><i class="ph ph-kanban"></i> จัดการงาน</a>
                        <a href="<?php echo $base_url; ?>member/calendar.php" class="<?php echo isActive('calendar');?>"><i class="ph ph-calendar"></i> ปฏิทิน</a>
                        <a href="<?php echo $base_url; ?>admin/users.php" class="<?php echo isActive('users');?>"><i class="ph ph-users"></i> จัดการสมาชิก</a>
                        <a href="<?php echo $base_url; ?>admin/visitors.php" class="<?php echo isActive('visitors');?>"><i class="ph ph-eye"></i> ผู้เข้าชม</a>
                        <a href="<?php echo $base_url; ?>admin/ip_manager.php" class="<?php echo isActive('ip_manager');?>"><i class="ph ph-shield-warning"></i> จัดการ IP</a>
                        <a href="tel:0969545290" style="color:#06c755 !important;"><i class="ph ph-phone-call"></i> 096-954-5290</a>

                    <?php elseif($__role === 'admin'): ?>
                    <!-- ══ ADMIN nav ════════════════════════════════════════════ -->
                    <div class="nav-role-badge" style="padding:.5rem 1rem .25rem;font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--success);border-bottom:1px solid rgba(16,185,129,.2);margin-bottom:.25rem;">🛡 Admin</div>
                        <a href="<?php echo $base_url; ?>admin/dashboard.php" class="<?php echo isActive('dashboard');?>"><i class="ph ph-squares-four"></i> แดชบอร์ด</a>
                        <a href="<?php echo $base_url; ?>admin/bookings.php" class="<?php echo isActive('bookings');?>"><i class="ph ph-list-checks"></i> รายการจอง</a>
                        <a href="<?php echo $base_url; ?>member/borrow_form.php" class="<?php echo isActive('borrow_form');?>"><i class="ph ph-hand-grabbing"></i> ยืมอุปกรณ์</a>
                        <a href="<?php echo $base_url; ?>guest/studio_booking.php" class="<?php echo isActive('studio_booking');?>"><i class="ph ph-video-camera"></i> จองสตูดิโอ</a>
                        <a href="<?php echo $base_url; ?>admin/inventory.php" class="<?php echo isActive('inventory');?>"><i class="ph ph-package"></i> คลังอุปกรณ์</a>
                        <a href="<?php echo $base_url; ?>admin/tasks.php" class="<?php echo isActive('tasks');?>"><i class="ph ph-kanban"></i> จัดการงาน</a>
                        <a href="<?php echo $base_url; ?>member/calendar.php" class="<?php echo isActive('calendar');?>"><i class="ph ph-calendar"></i> ปฏิทิน</a>
                        <a href="<?php echo $base_url; ?>admin/users.php" class="<?php echo isActive('users');?>"><i class="ph ph-users"></i> จัดการสมาชิก</a>
                        <a href="tel:0969545290" style="color:#06c755 !important;"><i class="ph ph-phone-call"></i> 096-954-5290</a>

                    <?php else: ?>
                    <!-- ══ MEMBER nav ═══════════════════════════════════════════ -->
                        <a href="<?php echo $base_url; ?>member/feed.php" class="<?php echo isActive('feed');?>"><i class="ph ph-house"></i> ฟีด</a>
                        <a href="<?php echo $base_url; ?>member/borrow_form.php" class="<?php echo isActive('borrow_form');?>"><i class="ph ph-hand-grabbing"></i> ยืมอุปกรณ์</a>
                        <a href="<?php echo $base_url; ?>guest/studio_booking.php" class="<?php echo isActive('studio_booking');?>"><i class="ph ph-video-camera"></i> จองสตูดิโอ</a>
                        <a href="<?php echo $base_url; ?>member/my_tasks.php" class="<?php echo isActive('my_tasks');?>"><i class="ph ph-kanban"></i> งานของฉัน</a>
                        <a href="<?php echo $base_url; ?>member/calendar.php" class="<?php echo isActive('calendar');?>"><i class="ph ph-calendar"></i> ปฏิทิน</a>
                        <a href="tel:0969545290" style="color:#06c755 !important;"><i class="ph ph-phone-call"></i> 096-954-5290</a>
                    <?php endif; ?>

                    <div class="nav-profile">
                        <a href="<?php echo $base_url; ?>member/my_bookings.php"><i class="ph-bold ph-list-dashes"></i> การจองของฉัน</a>
                        <a href="<?php echo $base_url; ?>member/profile.php"><i class="ph-bold ph-user-circle"></i> โปรไฟล์</a>
                        <a href="<?php echo $base_url; ?>auth/logout.php" class="btn btn-outline btn-sm">ออกจากระบบ</a>
                    </div>

                <?php else: ?>
                    <a href="<?php echo $base_url; ?>guest/studio_booking.php"><i class="ph ph-calendar-plus"></i> จองสตูดิโอ</a>
                    <a href="<?php echo $base_url; ?>auth/login.php"><i class="ph ph-sign-in"></i> เข้าสู่ระบบ</a>
                    <a href="<?php echo $base_url; ?>auth/register.php" class="btn btn-primary btn-sm">สมัครสมาชิก</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Nav Overlay (Admin / Super Admin slide panel) -->
    <?php if($__role === 'admin' || $__role === 'super_admin'): ?>
    <div class="nav-overlay" id="nav-overlay"></div>
    <?php endif; ?>

    <!-- Main Content -->
    <main class="main-content">

    <?php if($__role === 'admin' || $__role === 'super_admin'): ?>
    <script>
    (function(){
        var btn    = document.getElementById('mobile-toggle-btn');
        var nav    = document.getElementById('nav-links');
        var ov     = document.getElementById('nav-overlay');
        var closeX = document.getElementById('nav-close-btn');
        if(!btn||!nav) return;

        function closeMenu(){
            nav.classList.remove('active');
            if(ov) ov.classList.remove('active');
            var ic=btn.querySelector('i');
            if(ic){ic.classList.remove('ph-x');ic.classList.add('ph-list');}
            document.documentElement.classList.remove('menu-open');
        }
        function openMenu(){
            nav.classList.add('active');
            if(ov) ov.classList.add('active');
            var ic=btn.querySelector('i');
            if(ic){ic.classList.remove('ph-list');ic.classList.add('ph-x');}
            document.documentElement.classList.add('menu-open');
        }

        btn.addEventListener('click',function(e){
            e.stopPropagation();
            nav.classList.contains('active') ? closeMenu() : openMenu();
        });

        /* ปุ่ม ✕ ใน panel */
        if(closeX) closeX.addEventListener('click', closeMenu);

        /* คลิก overlay ปิด */
        if(ov){
            ov.addEventListener('click', closeMenu);
            ov.addEventListener('touchend',function(e){e.preventDefault();closeMenu();});
        }

        /* คลิก link ใน panel ปิด */
        nav.querySelectorAll('a').forEach(function(a){
            a.addEventListener('click', closeMenu);
        });

        /* Escape key */
        document.addEventListener('keydown',function(e){if(e.key==='Escape')closeMenu();});

        /* หมุนจอ */
        window.addEventListener('orientationchange',function(){setTimeout(closeMenu,300);});
    })();
    </script>
    <?php endif; ?>
