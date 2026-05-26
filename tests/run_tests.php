<?php
/**
 * IE-Photo-WEB — Test Runner
 *
 * Usage:
 *   php tests/run_tests.php               # Unit tests only
 *   php tests/run_tests.php --integration # Unit + Integration (requires live DB)
 *   php tests/run_tests.php --filter Csrf # Run only tests matching "Csrf"
 */

define('TESTS_ROOT',   __DIR__);
define('PROJECT_ROOT', dirname(__DIR__));

// Parse args first (needed before bootstrap)
$args           = array_slice($argv ?? [], 1);
$runIntegration = in_array('--integration', $args);
$filter         = '';
foreach ($args as $i => $arg) {
    if ($arg === '--filter' && isset($args[$i + 1])) {
        $filter = strtolower($args[$i + 1]);
    }
}

// Bootstrap
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
require_once TESTS_ROOT . '/TestCase.php';

// Unit tests: TESTING=true → config/database.php skips die() on DB failure
// Integration tests: load config normally (requires live DB)
if (!$runIntegration) define('TESTING', true);
require_once PROJECT_ROOT . '/config/database.php';

$testDirs = [TESTS_ROOT . '/Unit'];
if ($runIntegration) {
    $testDirs[] = TESTS_ROOT . '/Integration';
}

$totalPassed = 0;
$totalFailed = 0;
$allFailures = [];
$startTime   = microtime(true);

echo "\n\033[1mIE-Photo-WEB Test Suite\033[0m\n";
echo str_repeat('-', 52) . "\n";

foreach ($testDirs as $dir) {
    if (!is_dir($dir)) continue;

    $type  = basename($dir);
    $files = glob($dir . '/*Test.php') ?: [];
    if (empty($files)) continue;

    echo "\n\033[1;34m[{$type}]\033[0m\n";

    foreach ($files as $file) {
        $className = basename($file, '.php');
        if ($filter && !str_contains(strtolower($className), $filter)) continue;

        require_once $file;

        if (!class_exists($className)) {
            echo "  \033[33m? {$className} -- class not found\033[0m\n";
            continue;
        }

        echo "\n  \033[1m{$className}\033[0m\n";

        try {
            $test = new $className();
            $test->run();
        } catch (Throwable $e) {
            $msg = 'EXCEPTION: ' . get_class($e) . ': ' . $e->getMessage();
            echo "  \033[31m  {$msg}\033[0m\n";
            $allFailures[$className][] = $msg;
            $totalFailed++;
            continue;
        }

        $s            = $test->stats();
        $totalPassed += $s['passed'];
        $totalFailed += $s['failed'];
        if (!empty($s['failures'])) $allFailures[$className] = $s['failures'];
    }
}

$elapsed = round(microtime(true) - $startTime, 3);
$total   = $totalPassed + $totalFailed;

echo "\n" . str_repeat('-', 52) . "\n";
echo "\033[1mResults:\033[0m {$total} tests -- ";
if ($totalFailed === 0) {
    echo "\033[32m{$totalPassed} passed\033[0m";
} else {
    echo "\033[32m{$totalPassed} passed\033[0m, \033[31m{$totalFailed} failed\033[0m";
}
echo "  ({$elapsed}s)\n";

if (!empty($allFailures)) {
    echo "\n\033[31;1mFailures:\033[0m\n";
    foreach ($allFailures as $cls => $msgs) {
        echo "  \033[31m{$cls}\033[0m\n";
        foreach ($msgs as $m) echo "    - {$m}\n";
    }
}

if (!$runIntegration) {
    echo "\n\033[90mTip: --integration เพื่อรวม DB tests\033[0m\n";
}

echo "\n";
exit($totalFailed > 0 ? 1 : 0);
