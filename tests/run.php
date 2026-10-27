<?php
declare(strict_types=1);

if (file_exists(__DIR__ . '/../src/autoload.php')) {
    require_once __DIR__ . '/../src/autoload.php';
} elseif (file_exists(__DIR__ . '/../autoload.php')) {
    require_once __DIR__ . '/../autoload.php';
} else {
    require_once __DIR__ . '/autoload.php';
}
require_once __DIR__ . '/Unit/Stage1DataQualityTest.php';
require_once __DIR__ . '/Unit/Stage2LifecycleTest.php';
require_once __DIR__ . '/Unit/Stage3PolicyClassifierTest.php';
require_once __DIR__ . '/Unit/Stage4RecoveryModelTest.php';
require_once __DIR__ . '/Unit/Stage5RetryEngineTest.php';

use RetryEngine\Tests\Unit\Stage1DataQualityTest;
use RetryEngine\Tests\Unit\Stage2LifecycleTest;
use RetryEngine\Tests\Unit\Stage3PolicyClassifierTest;
use RetryEngine\Tests\Unit\Stage4RecoveryModelTest;
use RetryEngine\Tests\Unit\Stage5RetryEngineTest;

echo "================================================================================\n";
echo "            RUNNING AUTOMATED TEST SUITE (STAGES 1 TO 5)                       \n";
echo "================================================================================\n\n";

$passCount = 0;
$failCount = 0;

$runSuite = function (string $suiteName, callable $callback) use (&$passCount, &$failCount): void {
    echo "\n>>> Running {$suiteName}:\n";
    try {
        $callback();
        $passCount++;
    } catch (\Throwable $e) {
        $failCount++;
        echo "  [FAIL] {$suiteName}: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
};

$runSuite("Stage 1: Data Contract & Quality Control", function () {
    (new Stage1DataQualityTest())->runAll();
});

$runSuite("Stage 2: Charge Lifecycle & Financial Baseline", function () {
    (new Stage2LifecycleTest())->runAll();
});

$runSuite("Stage 3: Policy Classification & Scheme Rules", function () {
    (new Stage3PolicyClassifierTest())->runAll();
});

$runSuite("Stage 4: Recovery Model & Economic Filtering", function () {
    (new Stage4RecoveryModelTest())->runAll();
});

$runSuite("Stage 5: Retry Engine, Idempotency & PCI Masking", function () {
    (new Stage5RetryEngineTest())->runAll();
});

echo "\n================================================================================\n";
if ($failCount === 0) {
    echo "  ALL TEST SUITES PASSED! ({$passCount}/{$passCount} Suites OK)\n";
    echo "================================================================================\n";
    exit(0);
} else {
    echo "  TEST FAILURES DETECTED! ({$failCount} Suites Failed)\n";
    echo "================================================================================\n";
    exit(1);
}
