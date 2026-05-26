<?php
// Integration: database connection + schema validation
// Requires a live DB — run with: php tests/run_tests.php --integration

class DatabaseTest extends TestCase {
    private PDO $pdo;

    public function __construct() {
        global $pdo;
        $this->pdo = $pdo;
    }

    public function run(): void {
        // 1. PDO connection is alive
        $this->assertNotNull($this->pdo, 'PDO object is not null');

        // 2. Basic query works
        $result = $this->pdo->query("SELECT 1 AS val")->fetch();
        $this->assertEquals('1', (string) $result['val'], 'SELECT 1 returns 1');

        // 3. Core tables exist
        foreach (['users', 'bookings', 'equipments', 'studios', 'feeds'] as $table) {
            $exists = $this->pdo->query("SHOW TABLES LIKE '{$table}'")->fetch();
            $this->assertNotEmpty($exists, "Table '{$table}' exists");
        }

        // 4. users table has required columns
        $cols = $this->pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['id', 'student_id', 'email', 'password', 'role', 'profile_completed', 'email_verified'] as $col) {
            $this->assert(in_array($col, $cols), "users.{$col} exists");
        }

        // 5. bookings table has required columns
        $cols = $this->pdo->query("SHOW COLUMNS FROM bookings")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['id', 'user_id', 'item_id', 'booking_type', 'status', 'start_datetime', 'end_datetime'] as $col) {
            $this->assert(in_array($col, $cols), "bookings.{$col} exists");
        }

        // 6. bookings.status ENUM includes all expected values
        $row = $this->pdo->query("SHOW COLUMNS FROM bookings LIKE 'status'")->fetch();
        $enumDef = $row['Type'] ?? '';
        foreach (['pending', 'approved', 'rejected', 'returned', 'pending_return', 'cancelled'] as $s) {
            $this->assertContains($s, $enumDef, "bookings.status includes '{$s}'");
        }

        // 7. IP tables created on demand
        ip_ensure_tables($this->pdo);
        foreach (['ip_blocks', 'login_logs'] as $t) {
            $exists = $this->pdo->query("SHOW TABLES LIKE '{$t}'")->fetch();
            $this->assertNotEmpty($exists, "Table '{$t}' auto-created by ip_ensure_tables()");
        }

        // 8. ip_blocks schema
        $cols = $this->pdo->query("SHOW COLUMNS FROM ip_blocks")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['ip_address', 'blocked_until', 'reason', 'blocked_by', 'created_at', 'updated_at'] as $col) {
            $this->assert(in_array($col, $cols), "ip_blocks.{$col} exists");
        }

        // 9. login_logs schema
        $cols = $this->pdo->query("SHOW COLUMNS FROM login_logs")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['id', 'ip_address', 'identifier', 'success', 'user_agent', 'created_at'] as $col) {
            $this->assert(in_array($col, $cols), "login_logs.{$col} exists");
        }

        // 10. PDO error mode is EXCEPTION
        $attrs = $this->pdo->getAttribute(PDO::ATTR_ERRMODE);
        $this->assertEquals(PDO::ERRMODE_EXCEPTION, $attrs, 'PDO error mode is ERRMODE_EXCEPTION');
    }
}
