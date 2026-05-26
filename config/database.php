<?php
// config/database.php
// แสดง error เฉพาะบน localhost เท่านั้น
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1', '']);
ini_set('display_errors', $isLocalhost ? 1 : 0);
ini_set('display_startup_errors', $isLocalhost ? 1 : 0);
error_reporting(E_ALL);

// ─── Session hardening (PHP-FPM / mod_php compat) ───────────────────────────
// These MUST be set before session_start().  We use ini_set() here because
// CleverCloud runs PHP-FPM where php_value in .htaccess is NOT supported.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly',  '1');
    ini_set('session.cookie_samesite',  'Strict');
    ini_set('session.use_strict_mode',  '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid',    '0');
    // Enable secure flag only on HTTPS (production)
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
        ini_set('session.cookie_secure', '1');
    }
}

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
    /**
     * ตรวจว่า $ip อยู่ใน CIDR range หรือเปล่า (เช่น 10.0.0.0/8)
     * ใช้สำหรับกรอง CleverCloud's CC_REVERSE_PROXY_IPS ออกจาก X-Forwarded-For
     */
    function ip_in_cidr(string $ip, string $cidr): bool {
        if (!str_contains($cidr, '/')) return $ip === $cidr;
        [$subnet, $bits] = explode('/', $cidr, 2);
        if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        if (!filter_var($ip,     FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $mask = ~((1 << (32 - (int)$bits)) - 1);
        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }

    /**
     * คืน IP จริงของ client แบบแม่นยำ (Rightmost-Untrusted approach)
     *
     * วิธีการ:
     *   X-Forwarded-For: <client>, <proxy1>, <clevercloud-proxy>
     *   เดินจากขวาไปซ้าย — ข้าม IP ที่เป็น trusted proxy (CC_REVERSE_PROXY_IPS)
     *   หรือ private/reserved range จนเจอ IP สาธารณะที่ไม่ใช่ proxy = IP จริง
     *
     * ทำไมถึงแม่นยำกว่า:
     *   - แบบเก่า (leftmost) ถูกปลอมได้ด้วยการส่ง X-Forwarded-For: fake, real
     *   - แบบใหม่ (rightmost-untrusted) ปลอมไม่ได้ เพราะ proxy append จากขวา
     */
    function get_client_ip(): string {
        // ── 1. สร้างรายการ trusted proxy CIDR จาก CC_REVERSE_PROXY_IPS ────────
        //    CleverCloud inject env var นี้ให้อัตโนมัติ
        //    เช่น "185.42.117.0/24,10.0.0.0/8"
        $trustedCIDRs = [];
        if (($env = getenv('CC_REVERSE_PROXY_IPS')) !== false && $env !== '') {
            foreach (explode(',', $env) as $entry) {
                $entry = trim($entry);
                if ($entry !== '') $trustedCIDRs[] = $entry;
            }
        }

        // ── 2. ฟังก์ชันตรวจว่า IP นี้เป็น proxy/internal หรือเปล่า ──────────
        //    เงื่อนไข: private/reserved range เสมอ (10.x, 172.16-31.x, 192.168.x)
        //             หรือ ตรงกับ CC_REVERSE_PROXY_IPS ที่ CleverCloud กำหนด
        $isProxy = static function (string $ip) use ($trustedCIDRs): bool {
            // private/loopback/reserved → เป็น internal network เสมอ
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
            // Public IP ที่ CleverCloud ระบุว่าเป็น proxy ของพวกเขา
            foreach ($trustedCIDRs as $cidr) {
                if (ip_in_cidr($ip, $cidr)) return true;
            }
            return false;
        };

        // ── 3. CF-Connecting-IP (เฉพาะเมื่อ REMOTE_ADDR เป็น proxy จริง) ──────
        //    ป้องกันการปลอม: ใช้ CF header ก็ต่อเมื่อ connecting peer เป็น proxy
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ra    = $_SERVER['REMOTE_ADDR'] ?? '';
            $cfIp  = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
            if ($isProxy($ra) && filter_var($cfIp, FILTER_VALIDATE_IP)) {
                return $cfIp;
            }
        }

        // ── 4. X-Forwarded-For: rightmost-untrusted ───────────────────────────
        //    X-Forwarded-For: <client>, <proxy1>, <clevercloud-proxy>
        //    เดินจากขวา→ซ้าย ข้าม proxy/private IPs จนเจอ IP จริงของผู้ใช้
        //    วิธีนี้ปลอมไม่ได้ เพราะ proxy append จากขวาเสมอ
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $chain = array_reverse(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));
            foreach ($chain as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP)) continue; // รูปแบบผิด → ข้าม
                if ($isProxy($ip)) continue;                         // proxy/private → ข้าม
                return $ip;                                          // ✓ IP จริงของผู้ใช้
            }
        }

        // ── 5. Fallback: REMOTE_ADDR ───────────────────────────────────────────
        //    ใช้เมื่อไม่มี proxy headers (localhost / direct connection)
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

    /** ลบ log เก่าเกิน 7 วัน + expired auto-blocks + geo cache เก่า 7 วัน */
    function ip_cleanup(PDO $pdo): void {
        try {
            $pdo->exec("DELETE FROM login_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $pdo->exec("DELETE FROM ip_blocks WHERE blocked_by='auto' AND blocked_until IS NOT NULL AND blocked_until < NOW()");
            $pdo->exec("DELETE FROM visitor_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $pdo->exec("DELETE FROM ip_geo_cache WHERE cached_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        } catch (Exception $e) { }
    }
}

// ─── IP Geolocation helpers ──────────────────────────────────────────────────
if (!function_exists('ip_geo_ensure_table')) {

    /** สร้างตาราง cache geolocation ถ้ายังไม่มี */
    function ip_geo_ensure_table(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ip_geo_cache (
                ip_address   VARCHAR(45)   PRIMARY KEY,
                country      VARCHAR(100)  DEFAULT NULL,
                country_code VARCHAR(5)    DEFAULT NULL,
                region       VARCHAR(100)  DEFAULT NULL,
                city         VARCHAR(100)  DEFAULT NULL,
                isp          VARCHAR(200)  DEFAULT NULL,
                lat          DECIMAL(10,6) DEFAULT NULL,
                lon          DECIMAL(10,6) DEFAULT NULL,
                cached_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_cached (cached_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) { error_log('ip_geo_ensure_table: ' . $e->getMessage()); }
    }

    /**
     * ดึง geolocation ของหลาย IP พร้อมกัน (batch)
     * - เช็ค cache ก่อน (24h TTL)
     * - IP ที่ไม่มีใน cache → ยิง ip-api.com/batch (POST, สูงสุด 100 IPs ต่อครั้ง)
     * - บันทึกผลลง cache
     * คืน array keyed by IP: ['country'=>..., 'country_code'=>..., 'city'=>..., 'isp'=>...]
     */
    function get_ip_geos(PDO $pdo, array $ips): array {
        if (empty($ips)) return [];
        ip_geo_ensure_table($pdo);

        $result   = [];
        $toFetch  = [];

        // แยก private/loopback ออกก่อน
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $result[$ip] = ['country'=>'Local','country_code'=>'','region'=>'','city'=>'Private','isp'=>'—'];
            } else {
                $toFetch[] = $ip;
            }
        }

        if (empty($toFetch)) return $result;

        // ดึงจาก cache ในครั้งเดียว
        $in   = implode(',', array_fill(0, count($toFetch), '?'));
        $stmt = $pdo->prepare("SELECT * FROM ip_geo_cache WHERE ip_address IN ($in) AND cached_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt->execute($toFetch);
        $cached = [];
        foreach ($stmt->fetchAll() as $row) {
            $cached[$row['ip_address']] = $row;
        }

        // IPs ที่ยังไม่มีใน cache
        $missing = array_values(array_diff($toFetch, array_keys($cached)));

        // Merge cached ลง result
        foreach ($cached as $ip => $row) {
            $result[$ip] = $row;
        }

        // Batch fetch จาก ip-api.com (สูงสุด 100 IP ต่อครั้ง)
        if (!empty($missing)) {
            foreach (array_chunk($missing, 100) as $chunk) {
                $payload = json_encode(array_map(fn($ip) => [
                    'query'  => $ip,
                    'fields' => 'status,query,country,countryCode,regionName,city,isp,lat,lon',
                ], $chunk));
                $ctx  = stream_context_create(['http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 5,
                ]]);
                $json = @file_get_contents('http://ip-api.com/batch?fields=status,query,country,countryCode,regionName,city,isp,lat,lon', false, $ctx);
                if ($json === false) {
                    foreach ($chunk as $ip) $result[$ip] = ['country'=>'?','country_code'=>'','region'=>'','city'=>'ดึงข้อมูลไม่ได้','isp'=>'—'];
                    continue;
                }
                $rows = json_decode($json, true) ?? [];
                $inserts = [];
                foreach ($rows as $r) {
                    $ip = $r['query'] ?? '';
                    if (!$ip) continue;
                    if (($r['status'] ?? '') === 'success') {
                        $geo = [
                            'ip_address'   => $ip,
                            'country'      => $r['country']     ?? '',
                            'country_code' => $r['countryCode'] ?? '',
                            'region'       => $r['regionName']  ?? '',
                            'city'         => $r['city']        ?? '',
                            'isp'          => $r['isp']         ?? '',
                            'lat'          => $r['lat']         ?? null,
                            'lon'          => $r['lon']         ?? null,
                        ];
                    } else {
                        $geo = ['ip_address'=>$ip,'country'=>'?','country_code'=>'','region'=>'','city'=>'ไม่พบข้อมูล','isp'=>'—','lat'=>null,'lon'=>null];
                    }
                    $result[$ip]  = $geo;
                    $inserts[]    = $geo;
                }
                // บันทึก cache
                foreach ($inserts as $g) {
                    try {
                        $pdo->prepare("INSERT INTO ip_geo_cache (ip_address,country,country_code,region,city,isp,lat,lon)
                            VALUES (?,?,?,?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE country=VALUES(country),country_code=VALUES(country_code),
                            region=VALUES(region),city=VALUES(city),isp=VALUES(isp),lat=VALUES(lat),lon=VALUES(lon),cached_at=NOW()")
                            ->execute([$g['ip_address'],$g['country'],$g['country_code'],$g['region'],$g['city'],$g['isp'],$g['lat'],$g['lon']]);
                    } catch (Exception $e) { /* non-critical */ }
                }
            }
        }
        return $result;
    }

    /** แปลง country_code เป็น flag emoji (TH → 🇹🇭) */
    function country_flag(string $cc): string {
        $cc = strtoupper(trim($cc));
        if (strlen($cc) !== 2) return '🌐';
        return mb_chr(ord($cc[0]) + 127397, 'UTF-8') . mb_chr(ord($cc[1]) + 127397, 'UTF-8');
    }
}

// ─── Role helpers ────────────────────────────────────────────────────────────
if (!function_exists('is_admin')) {
    /** คืน true ถ้า user ปัจจุบันเป็น admin หรือ super_admin */
    function is_admin(): bool {
        return in_array($_SESSION['role'] ?? '', ['admin', 'super_admin']);
    }
    /** คืน true ถ้า user ปัจจุบันเป็น super_admin เท่านั้น */
    function is_super_admin(): bool {
        return ($_SESSION['role'] ?? '') === 'super_admin';
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
