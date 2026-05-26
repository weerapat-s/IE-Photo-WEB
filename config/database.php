<?php
// config/database.php
// แสดง error เฉพาะบน localhost เท่านั้น
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1', '']);
ini_set('display_errors', $isLocalhost ? 1 : 0);
ini_set('display_startup_errors', $isLocalhost ? 1 : 0);
error_reporting(E_ALL);

// ─── CSRF Protection ────────────────────────────────────────────────────────
if (!function_exists('csrf_token')) {
    /** คืนค่า CSRF token สำหรับ session ปัจจุบัน (สร้างใหม่ถ้ายังไม่มี) */
    function csrf_token(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) return '';
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    /** คืน HTML hidden input พร้อม token — ใส่ใน <form> ทุกฟอร์ม */
    function csrf_input(): string {
        return '<input type="hidden" name="_csrf" value="'
            . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
    /** ตรวจสอบ token จาก POST — คืน true ถ้าถูกต้อง */
    function csrf_verify(): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) return false;
        $submitted = $_POST['_csrf'] ?? '';
        $stored    = $_SESSION['csrf_token'] ?? '';
        return $submitted !== '' && $stored !== '' && hash_equals($stored, $submitted);
    }
}

// ─── Login Rate-Limiting helpers ────────────────────────────────────────────
// const declarations cannot be inside if blocks in PHP — define at global scope
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECS', 300); // 5 นาที

if (!function_exists('login_attempts')) {
    function login_attempts(): int {
        return (int)($_SESSION['login_attempts'] ?? 0);
    }
    function login_locked(): bool {
        if (login_attempts() < LOGIN_MAX_ATTEMPTS) return false;
        $elapsed = time() - (int)($_SESSION['login_last_attempt'] ?? 0);
        if ($elapsed >= LOGIN_LOCKOUT_SECS) {
            // Reset หลังหมดเวลา
            unset($_SESSION['login_attempts'], $_SESSION['login_last_attempt']);
            return false;
        }
        return true;
    }
    function login_record_fail(): void {
        $_SESSION['login_attempts'] = login_attempts() + 1;
        $_SESSION['login_last_attempt'] = time();
    }
    function login_reset(): void {
        unset($_SESSION['login_attempts'], $_SESSION['login_last_attempt']);
    }
    function login_wait_secs(): int {
        return max(0, LOGIN_LOCKOUT_SECS - (time() - (int)($_SESSION['login_last_attempt'] ?? 0)));
    }
}

// ─── IP Rate-Limiting & Login Logging ──────────────────────────────────────
define('IP_MAX_ATTEMPTS', 20);   // ครั้งสูงสุดจาก IP เดียวใน window
define('IP_WINDOW_SECS',  900);  // 15 นาที
define('IP_LOCKOUT_SECS', 1800); // บล็อก 30 นาที

if (!function_exists('get_client_ip')) {
    /** คืน IP จริงของ client — รองรับ Cloudflare, nginx reverse proxy */
    function get_client_ip(): string {
        // CF-Connecting-IP: ตั้งโดย Cloudflare เท่านั้น — ปลอดภัยที่สุด
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        // X-Forwarded-For: เอา IP แรกที่เป็น public IP
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        // X-Real-IP: nginx proxy
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = trim($_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /** สร้างตาราง ip_blocks และ login_logs ถ้ายังไม่มี (เรียกครั้งแรกครั้งเดียว) */
    function ip_ensure_tables(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ip_blocks (
                ip_address    VARCHAR(45)  PRIMARY KEY,
                blocked_until DATETIME     DEFAULT NULL,
                reason        VARCHAR(255) DEFAULT 'Too many failed attempts',
                blocked_by    ENUM('auto','admin') DEFAULT 'auto',
                created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS login_logs (
                id          BIGINT AUTO_INCREMENT PRIMARY KEY,
                ip_address  VARCHAR(45)  NOT NULL,
                identifier  VARCHAR(100) DEFAULT NULL,
                success     TINYINT(1)   NOT NULL DEFAULT 0,
                user_agent  VARCHAR(500) DEFAULT NULL,
                created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip_time (ip_address, created_at),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) { error_log('ip_ensure_tables: ' . $e->getMessage()); }
    }

    /** ตรวจว่า IP ปัจจุบันถูกบล็อกอยู่หรือเปล่า */
    function ip_is_blocked(PDO $pdo): bool {
        try {
            ip_ensure_tables($pdo);
            $stmt = $pdo->prepare("SELECT 1 FROM ip_blocks WHERE ip_address = ? AND (blocked_until IS NULL OR blocked_until > NOW()) LIMIT 1");
            $stmt->execute([get_client_ip()]);
            return (bool) $stmt->fetch();
        } catch (Exception $e) { return false; }
    }

    /** คืนเวลาที่ block หมดอายุ (null = permanent block) */
    function ip_blocked_until(PDO $pdo): ?string {
        try {
            $stmt = $pdo->prepare("SELECT blocked_until FROM ip_blocks WHERE ip_address = ? AND (blocked_until IS NULL OR blocked_until > NOW()) LIMIT 1");
            $stmt->execute([get_client_ip()]);
            $row = $stmt->fetch();
            return $row ? $row['blocked_until'] : null;
        } catch (Exception $e) { return null; }
    }

    /** บันทึก login ล้มเหลว + auto-block เมื่อเกิน threshold */
    function ip_record_fail(PDO $pdo, string $identifier = ''): void {
        try {
            $ip = get_client_ip();
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
            $pdo->prepare("INSERT INTO login_logs (ip_address, identifier, success, user_agent) VALUES (?, ?, 0, ?)")
                ->execute([$ip, $identifier, $ua]);
            // นับ failed ใน window
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_logs WHERE ip_address = ? AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $stmt->execute([$ip, IP_WINDOW_SECS]);
            $count = (int) $stmt->fetchColumn();
            // auto-block เมื่อเกิน threshold
            if ($count >= IP_MAX_ATTEMPTS) {
                $reason = "Auto-blocked: {$count} failed attempts in " . (IP_WINDOW_SECS / 60) . " min";
                $until  = date('Y-m-d H:i:s', time() + IP_LOCKOUT_SECS);
                $pdo->prepare("INSERT INTO ip_blocks (ip_address, blocked_until, reason, blocked_by)
                    VALUES (?, ?, ?, 'auto')
                    ON DUPLICATE KEY UPDATE blocked_until=VALUES(blocked_until), reason=VALUES(reason), blocked_by='auto', updated_at=NOW()")
                    ->execute([$ip, $until, $reason]);
            }
        } catch (Exception $e) { /* non-critical */ }
    }

    /** บันทึก login สำเร็จ */
    function ip_record_success(PDO $pdo, string $identifier = ''): void {
        try {
            $ip = get_client_ip();
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
            $pdo->prepare("INSERT INTO login_logs (ip_address, identifier, success, user_agent) VALUES (?, ?, 1, ?)")
                ->execute([$ip, $identifier, $ua]);
        } catch (Exception $e) { }
    }

    /** Admin: บล็อก IP ด้วยตนเอง ($duration_secs=null = permanent) */
    function ip_block_manual(PDO $pdo, string $ip, ?int $duration_secs = null, string $reason = 'Blocked by admin'): void {
        $until = $duration_secs ? date('Y-m-d H:i:s', time() + $duration_secs) : null;
        $pdo->prepare("INSERT INTO ip_blocks (ip_address, blocked_until, reason, blocked_by)
            VALUES (?, ?, ?, 'admin')
            ON DUPLICATE KEY UPDATE blocked_until=VALUES(blocked_until), reason=VALUES(reason), blocked_by='admin', updated_at=NOW()")
            ->execute([$ip, $until, $reason]);
    }

    /** Admin: ปลดบล็อก IP */
    function ip_unblock(PDO $pdo, string $ip): void {
        $pdo->prepare("DELETE FROM ip_blocks WHERE ip_address = ?")->execute([$ip]);
    }

    /** สร้างตาราง visitor_logs ถ้ายังไม่มี */
    function visitor_ensure_table(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS visitor_logs (
                id          BIGINT AUTO_INCREMENT PRIMARY KEY,
                ip_address  VARCHAR(45)  NOT NULL,
                user_id     INT          DEFAULT NULL,
                role        VARCHAR(20)  DEFAULT NULL,
                page_url    VARCHAR(500) NOT NULL,
                http_method VARCHAR(10)  DEFAULT 'GET',
                user_agent  VARCHAR(500) DEFAULT NULL,
                referrer    VARCHAR(500) DEFAULT NULL,
                created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip      (ip_address),
                INDEX idx_created (created_at),
                INDEX idx_user    (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) { error_log('visitor_ensure_table: ' . $e->getMessage()); }
    }

    /** บันทึก page visit — เรียกจาก header.php ทุกหน้า */
    function log_visit(PDO $pdo): void {
        try {
            visitor_ensure_table($pdo);
            $ip     = get_client_ip();
            $uid    = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $role   = $_SESSION['role'] ?? null;
            $url    = substr(($_SERVER['REQUEST_URI'] ?? '/'), 0, 500);
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
            $ref    = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500);
            $pdo->prepare("INSERT INTO visitor_logs (ip_address, user_id, role, page_url, http_method, user_agent, referrer) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$ip, $uid, $role, $url, $method, $ua, $ref]);
        } catch (Exception $e) { /* non-critical — never block page load */ }
    }

    /** ลบ log เก่าเกิน 7 วัน + expired auto-blocks */
    function ip_cleanup(PDO $pdo): void {
        try {
            $pdo->exec("DELETE FROM login_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $pdo->exec("DELETE FROM ip_blocks WHERE blocked_by='auto' AND blocked_until IS NOT NULL AND blocked_until < NOW()");
            $pdo->exec("DELETE FROM visitor_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        } catch (Exception $e) { }
    }
}

// Railway MySQL env vars (fallback to localhost for development)
$host     = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';
$dbname   = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'iephotoo_booking';
$username = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';
$password = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '';
$port     = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';
$charset  = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (\PDOException $e) {
    // ถ้ารันจาก test runner (TESTING=true) — ไม่ die() เพื่อให้ unit tests โหลดได้
    if (defined('TESTING') && TESTING) {
        $pdo = null;
        return;
    }
    // Log error แต่ไม่แสดง DB details ต่อผู้ใช้
    error_log("DB connection failed: " . $e->getMessage());
    $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1', '']);
    die($isLocalhost
        ? "Database connection failed: " . $e->getMessage()
        : "ระบบขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง");
}
