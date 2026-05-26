<?php
/**
 * IE-Photo-WEB — Simple Test Framework
 * Run: php tests/run_tests.php
 *      php tests/run_tests.php --integration   (รวม DB tests)
 */
abstract class TestCase {
    private int   $passed   = 0;
    private int   $failed   = 0;
    private array $failures = [];

    abstract public function run(): void;

    // ── Assertions ────────────────────────────────────────────────────────────

    protected function assert(bool $condition, string $description): void {
        if ($condition) {
            $this->passed++;
            echo "    \033[32m✓\033[0m {$description}\n";
        } else {
            $this->failed++;
            $this->failures[] = $description;
            echo "    \033[31m✗\033[0m {$description}\n";
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $description): void {
        if ($expected === $actual) {
            $this->passed++;
            echo "    \033[32m✓\033[0m {$description}\n";
        } else {
            $this->failed++;
            $msg = "{$description} — expected " . var_export($expected, true) . ", got " . var_export($actual, true);
            $this->failures[] = $msg;
            echo "    \033[31m✗\033[0m {$msg}\n";
        }
    }

    protected function assertNotEquals(mixed $unexpected, mixed $actual, string $description): void {
        $this->assert($unexpected !== $actual, $description);
    }

    protected function assertContains(string $needle, string $haystack, string $description): void {
        $this->assert(str_contains($haystack, $needle), "{$description}");
    }

    protected function assertNotEmpty(mixed $value, string $description): void {
        $this->assert(!empty($value), $description);
    }

    protected function assertEmpty(mixed $value, string $description): void {
        $this->assert(empty($value), $description);
    }

    protected function assertNull(mixed $value, string $description): void {
        $this->assert($value === null, $description);
    }

    protected function assertNotNull(mixed $value, string $description): void {
        $this->assert($value !== null, $description);
    }

    protected function assertGreaterThan(int $min, int $actual, string $description): void {
        $this->assert($actual > $min, "{$description} ({$actual} > {$min})");
    }

    protected function assertMatchesRegex(string $pattern, string $value, string $description): void {
        $this->assert((bool) preg_match($pattern, $value), $description);
    }

    protected function assertCount(int $expected, array $array, string $description): void {
        $this->assertEquals($expected, count($array), $description);
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public function stats(): array {
        return [
            'passed'   => $this->passed,
            'failed'   => $this->failed,
            'failures' => $this->failures,
        ];
    }
}
