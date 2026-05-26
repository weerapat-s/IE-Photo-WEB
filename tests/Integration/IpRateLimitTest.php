<?php
// Integration: IP rate limiting + auto-block + manual block/unblock
// Uses RFC 5737 TEST-NET addresses (203.0.113.x) — safe to use in tests

class IpRateLimitTest extends TestCase {
    private PDO    $pdo;
    private string $testIp = '203.0.113.10'; // TEST-NET (RFC 5737) — never routed

    public function __construct() {
        global $pdo;
        $this->pdo = $pdo;
    }

    private function cleanup(): void {
        $this->pdo->prepare("DELETE FROM ip_blocks WHERE ip_address = ?")->execute([$this->testIp]);
        $this->pdo->prepare("DELETE FROM login_logs WHERE ip_address = ?")->execute([$this->testIp]);
    }

    public function run(): void {
        ip_ensure_tables($this->pdo);
        $this->cleanup();
        $_SERVER['REMOTE_ADDR'] = $this->testIp;

        // 1. Fresh IP is not blocked
        $this->assertEquals(false, ip_is_blocked($this->pdo), 'Fresh IP is not blocked');

        // 2. ip_blocked_until() returns null when not blocked
        $this->assertNull(ip_blocked_until($this->pdo), 'ip_blocked_until() is null for unblocked IP');

        // 3. Failures below threshold do NOT trigger block
        for ($i = 0; $i < IP_MAX_ATTEMPTS - 1; $i++) {
            ip_record_fail($this->pdo, 'testuser');
        }
        $this->assertEquals(false, ip_is_blocked($this->pdo), 'IP not blocked below threshold (' . (IP_MAX_ATTEMPTS - 1) . ' failures)');

        // 4. Hitting the threshold triggers auto-block
        ip_record_fail($this->pdo, 'testuser'); // exactly IP_MAX_ATTEMPTS
        $this->assertEquals(true, ip_is_blocked($this->pdo), 'IP auto-blocked at threshold (' . IP_MAX_ATTEMPTS . ' failures)');

        // 5. ip_blocked_until() returns a future datetime
        $until = ip_blocked_until($this->pdo);
        $this->assertNotNull($until, 'ip_blocked_until() returns a datetime when blocked');
        $this->assert(strtotime($until) > time(), 'blocked_until is in the future');

        // 6. Block record has 'auto' as blocked_by
        $row = $this->pdo->prepare("SELECT blocked_by FROM ip_blocks WHERE ip_address = ?")->execute([$this->testIp])
            ?: null;
        $stmt = $this->pdo->prepare("SELECT blocked_by, reason FROM ip_blocks WHERE ip_address = ?");
        $stmt->execute([$this->testIp]);
        $blockRow = $stmt->fetch();
        $this->assertEquals('auto', $blockRow['blocked_by'], 'Auto-block sets blocked_by=auto');
        $this->assertContains('Auto-blocked', $blockRow['reason'], 'Auto-block reason contains "Auto-blocked"');

        // 7. ip_unblock() removes the block
        ip_unblock($this->pdo, $this->testIp);
        $this->assertEquals(false, ip_is_blocked($this->pdo), 'ip_unblock() removes the block');
        $this->assertNull(ip_blocked_until($this->pdo), 'ip_blocked_until() is null after unblock');

        // 8. ip_block_manual() with duration
        ip_block_manual($this->pdo, $this->testIp, 3600, 'Test manual block');
        $this->assertEquals(true, ip_is_blocked($this->pdo), 'Manual block (1h) blocks IP');
        $stmt->execute([$this->testIp]);
        $manualRow = $stmt->fetch();
        $this->assertEquals('admin', $manualRow['blocked_by'],  'Manual block sets blocked_by=admin');
        $this->assertEquals('Test manual block', $manualRow['reason'], 'Manual block stores custom reason');

        // 9. ip_block_manual() with null duration = permanent
        ip_unblock($this->pdo, $this->testIp);
        ip_block_manual($this->pdo, $this->testIp, null, 'Permanent block test');
        $stmt->execute([$this->testIp]);
        $permRow = $stmt->fetch();
        // ip_blocked_until should return null (permanent) but ip_is_blocked should be true
        $this->assertEquals(true, ip_is_blocked($this->pdo), 'Permanent block is active');
        $until2 = ip_blocked_until($this->pdo);
        $this->assertNull($until2, 'Permanent block has NULL blocked_until');

        // 10. ip_record_success() logs a success entry
        ip_unblock($this->pdo, $this->testIp);
        ip_record_success($this->pdo, 'testuser');
        $check = $this->pdo->prepare("SELECT success FROM login_logs WHERE ip_address = ? AND success = 1 ORDER BY id DESC LIMIT 1");
        $check->execute([$this->testIp]);
        $successRow = $check->fetch();
        $this->assertNotEmpty($successRow, 'ip_record_success() writes a log row');
        $this->assertEquals('1', (string) $successRow['success'], 'Success row has success=1');

        $this->cleanup();
    }
}
