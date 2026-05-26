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
    // Log error แต่ไม่แสดง DB details ต่อผู้ใช้
    error_log("DB connection failed: " . $e->getMessage());
    $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1', '']);
    die($isLocalhost
        ? "Database connection failed: " . $e->getMessage()
        : "ระบบขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง");
}
