<?php
// Unit tests for get_client_ip() — rightmost-untrusted IP detection

class IpHelperTest extends TestCase {
    private array $serverBackup = [];

    public function run(): void {
        $this->serverBackup = $_SERVER;

        // ── Rightmost-untrusted (CleverCloud style) ──────────────────────────

        // 1. No proxy headers — fallback to REMOTE_ADDR
        $_SERVER = ['REMOTE_ADDR' => '192.168.1.10'];
        $this->assertEquals('192.168.1.10', get_client_ip(), 'Direct: returns REMOTE_ADDR');

        // 2. CleverCloud: REMOTE_ADDR=proxy, XFF has real client at left
        //    proxy=(REMOTE_ADDR=10.1.1.1) appended real client, result = rightmost non-proxy
        $_SERVER = [
            'REMOTE_ADDR'           => '10.1.1.1',
            'HTTP_X_FORWARDED_FOR'  => '203.0.113.5, 10.1.1.1',
        ];
        $this->assertEquals('203.0.113.5', get_client_ip(),
            'CleverCloud: rightmost-untrusted returns real client IP');

        // 3. Spoof attempt: client sends fake IP, proxy appends real IP at right
        //    X-Forwarded-For: spoof, real_client  (proxy adds real_client at end)
        //    Should return real_client (rightmost non-proxy), not spoof
        $_SERVER = [
            'REMOTE_ADDR'           => '10.1.1.1',
            'HTTP_X_FORWARDED_FOR'  => '1.2.3.4, 203.0.113.99, 10.1.1.1',
        ];
        $this->assertEquals('203.0.113.99', get_client_ip(),
            'Anti-spoof: rightmost non-proxy IP returned, not leftmost fake');

        // 4. All XFF entries are private/proxy — fallback to REMOTE_ADDR
        $_SERVER = [
            'REMOTE_ADDR'           => '192.168.1.5',
            'HTTP_X_FORWARDED_FOR'  => '10.0.0.1, 172.16.0.2',
        ];
        $this->assertEquals('192.168.1.5', get_client_ip(),
            'XFF all-proxy: falls through to REMOTE_ADDR');

        // 5. Single real IP in XFF
        $_SERVER = [
            'REMOTE_ADDR'           => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR'  => '198.51.100.7',
        ];
        $this->assertEquals('198.51.100.7', get_client_ip(),
            'XFF single public IP: returned correctly');

        // 6. Cloudflare: CF-Connecting-IP trusted when REMOTE_ADDR is proxy
        $_SERVER = [
            'REMOTE_ADDR'           => '10.0.0.1',       // trusted proxy
            'HTTP_CF_CONNECTING_IP' => '203.0.113.5',
            'HTTP_X_FORWARDED_FOR'  => '198.51.100.9',
        ];
        $this->assertEquals('203.0.113.5', get_client_ip(),
            'Cloudflare: CF-Connecting-IP used when REMOTE_ADDR is trusted proxy');

        // 7. Localhost — no proxy headers
        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
        $this->assertEquals('127.0.0.1', get_client_ip(),
            'Localhost REMOTE_ADDR returned as-is');

        // 8. No SERVER vars at all — safe fallback
        $_SERVER = [];
        $ip = get_client_ip();
        $this->assert(filter_var($ip, FILTER_VALIDATE_IP) !== false,
            'Returns valid IP even with no SERVER vars');

        // 9. Invalid IP in XFF skipped — moves to next
        $_SERVER = [
            'REMOTE_ADDR'           => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR'  => 'not-an-ip, 203.0.113.42, 10.0.0.1',
        ];
        $this->assertEquals('203.0.113.42', get_client_ip(),
            'Invalid IP in XFF skipped, next valid non-proxy IP returned');

        // 10. ip_in_cidr: exact IP match (no CIDR)
        $this->assert(ip_in_cidr('10.0.0.5', '10.0.0.5'),
            'ip_in_cidr: exact IP match');

        // 11. ip_in_cidr: IP inside /8 range
        $this->assert(ip_in_cidr('10.99.88.77', '10.0.0.0/8'),
            'ip_in_cidr: IP inside /8 range');

        // 12. ip_in_cidr: IP outside range
        $this->assert(!ip_in_cidr('11.0.0.1', '10.0.0.0/8'),
            'ip_in_cidr: IP outside range returns false');

        // 13. ip_in_cidr: /32 single host
        $this->assert(ip_in_cidr('192.168.1.100', '192.168.1.100/32'),
            'ip_in_cidr: /32 matches exact host');

        // 14. ip_in_cidr: invalid CIDR subnet → false
        $this->assert(!ip_in_cidr('10.0.0.1', 'not-a-cidr/8'),
            'ip_in_cidr: invalid CIDR subnet returns false');

        $_SERVER = $this->serverBackup;
    }
}
