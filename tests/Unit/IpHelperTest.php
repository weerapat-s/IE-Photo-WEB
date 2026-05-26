<?php
// Unit tests for get_client_ip() — IP detection across proxy setups

class IpHelperTest extends TestCase {
    private array $serverBackup = [];

    public function run(): void {
        $this->serverBackup = $_SERVER;

        // 1. Direct connection — uses REMOTE_ADDR
        $_SERVER = ['REMOTE_ADDR' => '192.168.1.10'];
        $this->assertEquals('192.168.1.10', get_client_ip(), 'Direct: returns REMOTE_ADDR');

        // 2. Cloudflare header takes highest priority
        $_SERVER = [
            'REMOTE_ADDR'           => '10.0.0.1',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.5',
            'HTTP_X_FORWARDED_FOR'  => '198.51.100.9',
        ];
        $this->assertEquals('203.0.113.5', get_client_ip(), 'Cloudflare: CF-Connecting-IP is highest priority');

        // 3. X-Forwarded-For: first public IP wins
        $_SERVER = [
            'REMOTE_ADDR'             => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR'    => '198.51.100.7, 10.0.0.2',
        ];
        $this->assertEquals('198.51.100.7', get_client_ip(), 'XFF: first public IP returned');

        // 4. X-Forwarded-For: all private — fall through to REMOTE_ADDR
        $_SERVER = [
            'REMOTE_ADDR'             => '192.168.1.5',
            'HTTP_X_FORWARDED_FOR'    => '10.0.0.1, 172.16.0.2',
        ];
        $this->assertEquals('192.168.1.5', get_client_ip(), 'XFF private-only: falls through to REMOTE_ADDR');

        // 5. X-Real-IP used as fallback when XFF absent
        $_SERVER = [
            'REMOTE_ADDR'     => '10.0.0.1',
            'HTTP_X_REAL_IP'  => '203.0.113.42',
        ];
        $this->assertEquals('203.0.113.42', get_client_ip(), 'X-Real-IP fallback works');

        // 6. Localhost REMOTE_ADDR returned when no proxy headers
        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
        $this->assertEquals('127.0.0.1', get_client_ip(), 'Localhost REMOTE_ADDR returned as-is');

        // 7. Missing REMOTE_ADDR entirely — returns '127.0.0.1' default
        $_SERVER = [];
        $ip = get_client_ip();
        $this->assert(filter_var($ip, FILTER_VALIDATE_IP) !== false, 'Returns valid IP even with no SERVER vars');

        // 8. CF header with invalid IP — falls through to REMOTE_ADDR
        $_SERVER = [
            'REMOTE_ADDR'           => '203.0.113.99',
            'HTTP_CF_CONNECTING_IP' => 'not-an-ip',
        ];
        // Should fall through past the invalid CF header
        $ip = get_client_ip();
        $this->assert(filter_var($ip, FILTER_VALIDATE_IP) !== false, 'Invalid CF header skipped, still returns valid IP');

        $_SERVER = $this->serverBackup;
    }
}
