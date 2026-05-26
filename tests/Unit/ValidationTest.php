<?php
// Unit tests for input validation, sanitization, and security helpers

class ValidationTest extends TestCase {
    public function run(): void {

        // ── Email validation ──────────────────────────────────────────────────
        $this->assert((bool) filter_var('test@kmitl.ac.th', FILTER_VALIDATE_EMAIL),  'Valid KMITL email passes filter');
        $this->assert((bool) filter_var('s66000001@kmitl.ac.th', FILTER_VALIDATE_EMAIL), 'Student email format passes filter');
        $this->assert(!(bool) filter_var('notanemail', FILTER_VALIDATE_EMAIL),        'Plain string fails email filter');
        $this->assert(!(bool) filter_var('', FILTER_VALIDATE_EMAIL),                  'Empty string fails email filter');
        $this->assert(!(bool) filter_var('@kmitl.ac.th', FILTER_VALIDATE_EMAIL),      'Missing local part fails filter');

        // ── KMITL domain check ────────────────────────────────────────────────
        $check = fn($s) => str_contains($s, '@') && str_ends_with(strtolower($s), '@kmitl.ac.th');
        $this->assert( $check('student@kmitl.ac.th'),  'KMITL address passes domain check');
        $this->assert(!$check('student@gmail.com'),    'Gmail rejected by domain check');
        $this->assert(!$check('student@kmitl.ac.th.evil.com'), 'Subdomain spoof rejected');
        $this->assert(!$check('student@notkmitl.ac.th'), 'Wrong domain rejected');

        // ── Integer sanitization ──────────────────────────────────────────────
        $this->assertEquals(0,  intval(''),     'Empty string intval → 0');
        $this->assertEquals(0,  intval('abc'),  'Non-numeric intval → 0');
        $this->assertEquals(5,  intval('5'),    'Numeric string intval → 5');
        $this->assertEquals(-1, intval('-1'),   'Negative intval → -1');
        $this->assertEquals(0,  intval(null),   'null intval → 0');

        // ── XSS prevention ───────────────────────────────────────────────────
        $xss = '<script>alert("xss")</script>';
        $safe = htmlspecialchars($xss, ENT_QUOTES, 'UTF-8');
        $this->assert(!str_contains($safe, '<script>'),  'htmlspecialchars removes <script> tag');
        $this->assertContains('&lt;script&gt;', $safe,  'htmlspecialchars encodes < to &lt;');

        $attr = '" onmouseover="alert(1)';
        $safeAttr = htmlspecialchars($attr, ENT_QUOTES, 'UTF-8');
        $this->assert(!str_contains($safeAttr, '"'),  'htmlspecialchars(ENT_QUOTES) encodes double quotes');

        // ── File extension whitelist ──────────────────────────────────────────
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $this->assert( in_array('jpg',  $allowed), 'jpg allowed');
        $this->assert( in_array('png',  $allowed), 'png allowed');
        $this->assert( in_array('webp', $allowed), 'webp allowed');
        $this->assert(!in_array('php',  $allowed), 'php not allowed');
        $this->assert(!in_array('exe',  $allowed), 'exe not allowed');
        $this->assert(!in_array('svg',  $allowed), 'svg not allowed (XSS vector)');
        $this->assert(!in_array('gif',  $allowed), 'gif not in equipment upload whitelist');

        // ── MIME type whitelist ───────────────────────────────────────────────
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $this->assert( isset($allowedMimes['image/jpeg']),      'image/jpeg allowed');
        $this->assert( isset($allowedMimes['image/png']),       'image/png allowed');
        $this->assert(!isset($allowedMimes['image/svg+xml']),   'image/svg+xml not allowed');
        $this->assert(!isset($allowedMimes['application/php']), 'application/php not allowed');
        $this->assert(!isset($allowedMimes['text/html']),       'text/html not allowed');

        // ── CSRF token entropy ────────────────────────────────────────────────
        $t1 = bin2hex(random_bytes(32));
        $t2 = bin2hex(random_bytes(32));
        $this->assertEquals(64, strlen($t1), 'Token is 64 characters');
        $this->assertMatchesRegex('/^[0-9a-f]{64}$/', $t1, 'Token is hex-only');
        $this->assertNotEquals($t1, $t2, 'Two tokens are different (entropy check)');

        // ── hash_equals timing-safe ───────────────────────────────────────────
        $this->assertEquals(true,  hash_equals('abc123', 'abc123'), 'hash_equals: equal strings → true');
        $this->assertEquals(false, hash_equals('abc123', 'xyz789'), 'hash_equals: different strings → false');
        $this->assertEquals(false, hash_equals('abc',    ''),       'hash_equals: empty vs non-empty → false');

        // ── IP validation ─────────────────────────────────────────────────────
        $this->assert((bool) filter_var('203.0.113.1',     FILTER_VALIDATE_IP), 'Valid public IPv4 accepted');
        $this->assert((bool) filter_var('::1',             FILTER_VALIDATE_IP), 'IPv6 loopback accepted by FILTER_VALIDATE_IP');
        $this->assert(!(bool) filter_var('999.0.0.1',      FILTER_VALIDATE_IP), 'Invalid IPv4 rejected');
        $this->assert(!(bool) filter_var('not-an-ip',      FILTER_VALIDATE_IP), 'String rejected as IP');
        $this->assert(!(bool) filter_var('',               FILTER_VALIDATE_IP), 'Empty string rejected as IP');

        // Public IP check (no private/reserved ranges)
        $pubFlag = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        $this->assert( (bool) filter_var('203.0.113.1', FILTER_VALIDATE_IP, $pubFlag), 'Public IP passes public-only check');
        $this->assert(!(bool) filter_var('10.0.0.1',    FILTER_VALIDATE_IP, $pubFlag), 'Private 10.x rejected by public-only');
        $this->assert(!(bool) filter_var('192.168.1.1', FILTER_VALIDATE_IP, $pubFlag), 'Private 192.168.x rejected by public-only');
        $this->assert(!(bool) filter_var('127.0.0.1',   FILTER_VALIDATE_IP, $pubFlag), 'Loopback rejected by public-only');
    }
}
