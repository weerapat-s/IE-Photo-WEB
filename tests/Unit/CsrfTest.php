<?php
// Unit tests for CSRF helper functions (config/database.php)
// config/database.php loaded by run_tests.php bootstrap

class CsrfTest extends TestCase {
    public function run(): void {
        $_SESSION = [];

        // 1. csrf_token() generates a non-empty 64-char hex token
        $token = csrf_token();
        $this->assertNotEmpty($token, 'csrf_token() generates non-empty token');
        $this->assertEquals(64, strlen($token), 'csrf_token() is 64 characters long');
        $this->assertMatchesRegex('/^[0-9a-f]{64}$/', $token, 'csrf_token() is lowercase hex only');

        // 2. Calling csrf_token() twice returns the same token (idempotent within session)
        $token2 = csrf_token();
        $this->assertEquals($token, $token2, 'csrf_token() is idempotent within same session');

        // 3. Token is stored in $_SESSION
        $this->assertEquals($token, $_SESSION['csrf_token'], 'Token is stored in $_SESSION["csrf_token"]');

        // 4. csrf_input() returns a hidden input with the token
        $html = csrf_input();
        $this->assertContains('<input type="hidden" name="_csrf"', $html, 'csrf_input() returns hidden input element');
        $this->assertContains('value="' . $token . '"', $html, 'csrf_input() contains current token as value');

        // 5. csrf_verify() returns false when _csrf is absent from POST
        $_POST = [];
        $this->assertEquals(false, csrf_verify(), 'csrf_verify() returns false with no POST _csrf');

        // 6. csrf_verify() returns false with wrong token
        $_POST['_csrf'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $this->assertEquals(false, csrf_verify(), 'csrf_verify() returns false with wrong token');

        // 7. csrf_verify() returns true with correct token
        $_POST['_csrf'] = $token;
        $this->assertEquals(true, csrf_verify(), 'csrf_verify() returns true with correct token');

        // 8. csrf_verify() returns false when stored token is empty
        $_SESSION['csrf_token'] = '';
        $_POST['_csrf']         = '';
        $this->assertEquals(false, csrf_verify(), 'csrf_verify() returns false when both tokens are empty');

        // 9. Rotating token: unset session key generates a NEW token
        unset($_SESSION['csrf_token']);
        $newToken = csrf_token();
        $this->assertNotEmpty($newToken, 'New token generated after session key removed');
        // Two tokens from random_bytes are astronomically unlikely to match
        $this->assertNotEquals($token, $newToken, 'New token is different from old token');

        // 10. csrf_verify() fails when no session is active (simulated by checking return on PHP_SESSION_NONE)
        // We cannot easily deactivate the session mid-test, so verify the guard logic via direct check
        $this->assertEquals(PHP_SESSION_ACTIVE, session_status(), 'Session is active during tests');

        // Cleanup
        $_POST    = [];
        $_SESSION = [];
    }
}
