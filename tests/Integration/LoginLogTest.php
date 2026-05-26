<?php
// Integration: login_logs write + read + ip_cleanup

class LoginLogTest extends TestCase {
    private PDO    $pdo;
    private string $testIp = '203.0.113.20'; // RFC 5737 TEST-NET

    public function __construct() {
        global $pdo;
        $this->pdo = $pdo;
    }

    private function cleanup(): void {
        $this->pdo->prepare("DELETE FROM login_logs WHERE ip_address = ?")->execute([$this->testIp]);
        $this->pdo->prepare("DELETE FROM ip_blocks WHERE ip_address = ?")->execute([$this->testIp]);
    }

    public function run(): void {
        ip_ensure_tables($this->pdo);
        $this->cleanup();
        $_SERVER['REMOTE_ADDR']      = $this->testIp;
        $_SERVER['HTTP_USER_AGENT']  = 'TestSuite/1.0';

        // 1. ip_record_fail() inserts a row with success=0
        ip_record_fail($this->pdo, 's66000001');
        $stmt = $this->pdo->prepare("SELECT identifier, success, user_agent FROM login_logs WHERE ip_address = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$this->testIp]);
        $row = $stmt->fetch();
        $this->assertNotEmpty($row,                              'ip_record_fail() inserts a log row');
        $this->assertEquals('s66000001', $row['identifier'],    'Log row stores identifier');
        $this->assertEquals('0', (string) $row['success'],      'Log row has success=0');
        $this->assertContains('TestSuite', $row['user_agent'],  'Log row stores user_agent');

        // 2. ip_record_success() inserts with success=1
        ip_record_success($this->pdo, 's66000001');
        $stmt->execute([$this->testIp]);
        $row2 = $stmt->fetch();
        $this->assertEquals('1', (string) $row2['success'], 'ip_record_success() has success=1');

        // 3. Multiple records accumulate correctly
        ip_record_fail($this->pdo, 's66000002');
        ip_record_fail($this->pdo, 's66000003');
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM login_logs WHERE ip_address = ?");
        $count->execute([$this->testIp]);
        $total = (int) $count->fetchColumn();
        $this->assertEquals(4, $total, 'Total of 4 log rows (1 fail + 1 success + 2 more fails)');

        // 4. ip_cleanup() removes entries older than 7 days
        $this->pdo->prepare("INSERT INTO login_logs (ip_address, identifier, success, created_at) VALUES (?, ?, 0, DATE_SUB(NOW(), INTERVAL 8 DAY))")
            ->execute([$this->testIp, 'old_test']);
        $old = $this->pdo->prepare("SELECT COUNT(*) FROM login_logs WHERE ip_address = ? AND identifier = 'old_test'");
        $old->execute([$this->testIp]);
        $this->assertEquals(1, (int) $old->fetchColumn(), 'Old log entry inserted successfully');

        ip_cleanup($this->pdo);

        $old->execute([$this->testIp]);
        $this->assertEquals(0, (int) $old->fetchColumn(), 'ip_cleanup() deletes entries older than 7 days');

        // 5. ip_cleanup() removes expired auto-blocks but not admin blocks
        ip_block_manual($this->pdo, $this->testIp, null, 'Admin permanent block');
        ip_cleanup($this->pdo);
        $blockCheck = $this->pdo->prepare("SELECT 1 FROM ip_blocks WHERE ip_address = ?");
        $blockCheck->execute([$this->testIp]);
        $this->assertNotEmpty($blockCheck->fetch(), 'ip_cleanup() does NOT remove admin/permanent blocks');

        // 6. IP defines are sane
        $this->assert(defined('IP_MAX_ATTEMPTS'),  'IP_MAX_ATTEMPTS defined');
        $this->assert(defined('IP_WINDOW_SECS'),   'IP_WINDOW_SECS defined');
        $this->assert(defined('IP_LOCKOUT_SECS'),  'IP_LOCKOUT_SECS defined');
        $this->assertGreaterThan(0, IP_MAX_ATTEMPTS,  'IP_MAX_ATTEMPTS > 0');
        $this->assertGreaterThan(0, IP_WINDOW_SECS,   'IP_WINDOW_SECS > 0');
        $this->assertGreaterThan(0, IP_LOCKOUT_SECS,  'IP_LOCKOUT_SECS > 0');

        $this->cleanup();
    }
}
