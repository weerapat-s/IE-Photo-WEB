<?php
// member/feed.php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Show only studio feeds (booking_id IS NULL) and feeds related to the current user's own bookings
$query = "
    SELECT f.id as feed_id, f.message, f.created_at,
           (SELECT COUNT(*) FROM feed_likes WHERE feed_id = f.id) as like_count,
           (SELECT COUNT(*) FROM feed_likes WHERE feed_id = f.id AND user_id = :user_id) as user_liked,
           b.status as booking_status,
           b.form_image_path
    FROM feeds f
    LEFT JOIN bookings b ON f.booking_id = b.id
    WHERE f.booking_id IS NULL
       OR b.user_id = :user_id2
    ORDER BY f.created_at DESC
    LIMIT 50
";
$stmt = $pdo->prepare($query);
$stmt->execute(['user_id' => $user_id, 'user_id2' => $user_id]);
$feeds = $stmt->fetchAll();

$base_url = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h2>กิจกรรมล่าสุด</h2>
    <p>อัปเดตการใช้งานอุปกรณ์และสตูดิโอในชุมนุม</p>
</div>

<?php if(isset($_GET['welcome'])): ?>
    <div class="alert alert-success" style="text-align:center;">
        <i class="ph-bold ph-hands-clapping" style="font-size:1.3rem;"></i>
        <strong>ตั้งค่าโปรไฟล์เรียบร้อย!</strong> ยินดีต้อนรับเข้าใช้งานระบบ IE-Photo Maker
    </div>
<?php endif; ?>

<div class="page-container-md">
    <?php if(empty($feeds)): ?>
        <div class="glass-card empty-state">
            <i class="ph ph-calendar-blank"></i>
            <p class="text-muted">ยังไม่มีกิจกรรมในขณะนี้</p>
        </div>
    <?php else: ?>
        <?php foreach($feeds as $feed): ?>
            <?php
            $isLiked = $feed['user_liked'] > 0;
            $statusLabel = 'รอดำเนินการ'; $badgeClass = 'badge-pending';
            if($feed['booking_status'] === 'approved') { $statusLabel = 'อนุมัติแล้ว'; $badgeClass = 'badge-approved'; }
            if($feed['booking_status'] === 'rejected') { $statusLabel = 'ปฏิเสธแล้ว'; $badgeClass = 'badge-rejected'; }
            if($feed['booking_status'] === 'returned') { $statusLabel = 'คืนแล้ว'; $badgeClass = 'badge-returned'; }
            ?>
            <div class="feed-card animate-in">
                <?php if($feed['form_image_path']): ?>
                    <!-- FIX: Correct path from uploads/forms/ to uploads/booking_forms/ -->
                    <img src="<?php echo htmlspecialchars($base_url . 'uploads/booking_forms/' . $feed['form_image_path']); ?>" alt="Activity" class="feed-image" onerror="this.style.display='none'">
                <?php endif; ?>
                <div class="feed-body">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.4rem;margin-bottom:.8rem;">
                        <span class="badge <?php echo $badgeClass; ?>"><?php echo $statusLabel; ?></span>
                        <span style="font-size:.78rem;color:var(--text-muted);white-space:nowrap;"><i class="ph ph-clock"></i> <?php echo date('d M Y, H:i', strtotime($feed['created_at'])); ?></span>
                    </div>
                    <p style="font-size:1rem;line-height:1.6;margin-bottom:1.2rem;"><?php echo htmlspecialchars($feed['message']); ?></p>
                    <div style="border-top:1px solid var(--border);padding-top:.8rem;display:flex;align-items:center;">
                        <button class="btn-like <?php echo $isLiked?'liked':''; ?>" data-feed-id="<?php echo $feed['feed_id']; ?>" style="border:none;background:none;font-size:1rem;display:flex;align-items:center;gap:6px;cursor:pointer;color:<?php echo $isLiked?'var(--danger)':'var(--text-muted)'; ?>;">
                            <i class="ph-fill ph-heart" style="font-size:1.3rem;"></i>
                            <span class="like-count" style="font-weight:700;"><?php echo $feed['like_count']; ?></span>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
