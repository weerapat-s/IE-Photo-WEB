<?php
// Unit tests for session-based login rate limiting (config/database.php)
// config/database.php already loaded by CsrfTest bootstrap

class SessionRateLimitTest extends TestCase {
    public function run(): void {
        $_SESSION = [];

        // 1. login_attempts() returns 0 on fresh session
        $this->assertEquals(0, login_attempts(), 'login_attempts() returns 0 on fresh session');

        // 2. login_locked() returns false at 0 attempts
        $this->assertEquals(false, login_locked(), 'login_locked() is false at 0 attempts');

        // 3. Recording failures below threshold does NOT lock
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS - 1; $i++) {
            login_record_fail();
        }
        $this->assertEquals(LOGIN_MAX_ATTEMPTS - 1, login_attempts(), 'login_attempts() reflects recorded failures');
        $this->assertEquals(false, login_locked(), 'login_locked() is false below threshold');

        // 4. Hitting the threshold locks the session
        login_record_fail(); // now at LOGIN_MAX_ATTEMPTS
        $this->assertEquals(true, login_locked(), 'login_locked() is true at threshold');

        // 5. login_wait_secs() returns a positive value when locked
        $wait = login_wait_secs();
        $this->assertGreaterThan(0, $wait, 'login_wait_secs() returns > 0 when locked');
        $this->assert($wait <= LOGIN_LOCKOUT_SECS, 'login_wait_secs() is <= LOGIN_LOCKOUT_SECS');

        // 6. login_reset() clears the counter and unlocks
        login_reset();
        $this->assertEquals(0, login_attempts(), 'login_reset() clears attempt count');
        $this->assertEquals(false, login_locked(), 'login_locked() returns false after reset');

        // 7. Auto-reset: lock expires after LOGIN_LOCKOUT_SECS
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS; $i++) login_record_fail();
        $this->assertEquals(true, login_locked(), 'Locked before fake-expiry');
        // Fake the timestamp to be past the lockout window
        $_SESSION['login_last_attempt'] = time() - LOGIN_LOCKOUT_SECS - 1;
        $this->assertEquals(false, login_locked(), 'login_locked() auto-resets after lockout expires');
        $this->assertEquals(0, login_attempts(), 'login_attempts() is 0 after auto-reset');

        // 8. LOGIN_MAX_ATTEMPTS and LOGIN_LOCKOUT_SECS are defined with expected values
        $this->assert(defined('LOGIN_MAX_ATTEMPTS'),  'LOGIN_MAX_ATTEMPTS is defined');
        $this->assert(defined('LOGIN_LOCKOUT_SECS'),  'LOGIN_LOCKOUT_SECS is defined');
        $this->assertGreaterThan(0, LOGIN_MAX_ATTEMPTS, 'LOGIN_MAX_ATTEMPTS > 0');
        $this->assertGreaterThan(0, LOGIN_LOCKOUT_SECS, 'LOGIN_LOCKOUT_SECS > 0');

        $_SESSION = [];
    }
}
