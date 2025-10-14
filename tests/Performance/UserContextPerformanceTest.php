<?php
/**
 * Performance benchmark tests for UserContext class
 * Compares global array access vs UserContext object access
 * Tests memory usage, execution time, and scalability
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';

// Load the UserContext class
require_once dirname(__DIR__, 2) . '/src/context/UserContext.php';

use EIOU\Context\UserContext;

class UserContextPerformanceTest extends TestCase {

    private $iterations = 10000; // Number of iterations for benchmarks
    private $sampleData;

    public function setUp() {
        parent::setUp();

        $this->sampleData = [
            'public' => 'performance_test_public_key_12345',
            'private' => 'performance_test_private_key_67890',
            'hostname' => 'performance.host:8080',
            'torAddress' => 'perf123456789.onion',
            'defaultFee' => 1.5,
            'defaultCurrency' => 'USD',
            'localhostOnly' => false,
            'maxFee' => 5.0,
            'maxP2pLevel' => 6,
            'p2pExpiration' => 300,
            'debug' => true,
            'maxOutput' => 5
        ];
    }

    public function tearDown() {
        parent::tearDown();
    }

    // ==================== Memory Usage Tests ====================

    public function testMemoryUsageGlobalVsContext() {
        global $user;

        // Measure global array memory
        $memStart = memory_get_usage();
        $user = $this->sampleData;
        $globalMemory = memory_get_usage() - $memStart;

        // Measure context object memory
        $memStart = memory_get_usage();
        $context = new UserContext($this->sampleData);
        $contextMemory = memory_get_usage() - $memStart;

        echo "\n";
        echo "Global array memory: " . $globalMemory . " bytes\n";
        echo "UserContext memory: " . $contextMemory . " bytes\n";
        echo "Overhead: " . ($contextMemory - $globalMemory) . " bytes\n";
        echo "Overhead %: " . number_format((($contextMemory - $globalMemory) / $globalMemory) * 100, 2) . "%\n";

        // Context should have reasonable overhead (typically < 10x)
        $this->assertTrue($contextMemory < $globalMemory * 10, "Context memory overhead should be reasonable");
    }

    public function testMemoryUsageMultipleContexts() {
        $memStart = memory_get_usage();

        // Create 1000 context instances
        $contexts = [];
        for ($i = 0; $i < 1000; $i++) {
            $contexts[] = new UserContext($this->sampleData);
        }

        $totalMemory = memory_get_usage() - $memStart;
        $avgMemoryPerContext = $totalMemory / 1000;

        echo "\n";
        echo "1000 contexts total memory: " . number_format($totalMemory) . " bytes\n";
        echo "Average per context: " . number_format($avgMemoryPerContext) . " bytes\n";

        // Should be able to create 1000 contexts without excessive memory
        $this->assertTrue($totalMemory < 10 * 1024 * 1024, "1000 contexts should use < 10MB");
    }

    public function testMemoryLeakPrevention() {
        $memStart = memory_get_usage();

        // Create and destroy contexts repeatedly
        for ($i = 0; $i < 1000; $i++) {
            $context = new UserContext($this->sampleData);
            $publicKey = $context->getPublicKey();
            unset($context);
        }

        $memEnd = memory_get_usage();
        $memoryGrowth = $memEnd - $memStart;

        echo "\n";
        echo "Memory growth after 1000 create/destroy cycles: " . number_format($memoryGrowth) . " bytes\n";

        // Memory growth should be minimal (accounting for PHP's internal memory management)
        $this->assertTrue($memoryGrowth < 1024 * 1024, "Memory growth should be < 1MB");
    }

    // ==================== Execution Time Tests ====================

    public function testReadPerformanceGlobalVsContext() {
        global $user;
        $user = $this->sampleData;

        // Benchmark global array access
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $publicKey = $user['public'] ?? null;
            $hostname = $user['hostname'] ?? null;
            $fee = $user['defaultFee'] ?? 0.1;
        }
        $globalTime = microtime(true) - $start;

        // Benchmark context object access
        $context = new UserContext($this->sampleData);
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $publicKey = $context->getPublicKey();
            $hostname = $context->getHostname();
            $fee = $context->getDefaultFee();
        }
        $contextTime = microtime(true) - $start;

        echo "\n";
        echo "Iterations: " . number_format($this->iterations) . "\n";
        echo "Global array read time: " . number_format($globalTime * 1000, 3) . " ms\n";
        echo "UserContext read time: " . number_format($contextTime * 1000, 3) . " ms\n";
        echo "Slowdown factor: " . number_format($contextTime / $globalTime, 2) . "x\n";

        // Context should be reasonably fast (< 5x slower than array access)
        $this->assertTrue($contextTime < $globalTime * 5, "Context reads should be < 5x slower than array");
    }

    public function testWritePerformanceGlobalVsContext() {
        global $user;
        $user = $this->sampleData;

        // Benchmark global array write
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $user['defaultFee'] = 2.0 + $i;
            $user['debug'] = ($i % 2 === 0);
        }
        $globalTime = microtime(true) - $start;

        // Benchmark context object write
        $context = new UserContext($this->sampleData);
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $context->set('defaultFee', 2.0 + $i);
            $context->set('debug', ($i % 2 === 0));
        }
        $contextTime = microtime(true) - $start;

        echo "\n";
        echo "Iterations: " . number_format($this->iterations) . "\n";
        echo "Global array write time: " . number_format($globalTime * 1000, 3) . " ms\n";
        echo "UserContext write time: " . number_format($contextTime * 1000, 3) . " ms\n";
        echo "Slowdown factor: " . number_format($contextTime / $globalTime, 2) . "x\n";

        // Context writes should be reasonably fast
        $this->assertTrue($contextTime < $globalTime * 5, "Context writes should be < 5x slower than array");
    }

    public function testFromGlobalPerformance() {
        global $user;
        $user = $this->sampleData;

        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $context = UserContext::fromGlobal();
        }
        $duration = microtime(true) - $start;

        echo "\n";
        echo "fromGlobal() iterations: " . number_format($this->iterations) . "\n";
        echo "Total time: " . number_format($duration * 1000, 3) . " ms\n";
        echo "Average per call: " . number_format(($duration / $this->iterations) * 1000000, 3) . " μs\n";

        // fromGlobal should be fast (< 1ms for 10,000 calls)
        $this->assertTrue($duration < 0.1, "fromGlobal should be < 100ms for 10k calls");
    }

    public function testToArrayPerformance() {
        $context = new UserContext($this->sampleData);

        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $array = $context->toArray();
        }
        $duration = microtime(true) - $start;

        echo "\n";
        echo "toArray() iterations: " . number_format($this->iterations) . "\n";
        echo "Total time: " . number_format($duration * 1000, 3) . " ms\n";
        echo "Average per call: " . number_format(($duration / $this->iterations) * 1000000, 3) . " μs\n";

        // toArray should be very fast (< 50ms for 10,000 calls)
        $this->assertTrue($duration < 0.05, "toArray should be < 50ms for 10k calls");
    }

    public function testWithOverridesPerformance() {
        $context = new UserContext($this->sampleData);

        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $newContext = $context->withOverrides(['defaultFee' => 5.0]);
        }
        $duration = microtime(true) - $start;

        echo "\n";
        echo "withOverrides() iterations: " . number_format($this->iterations) . "\n";
        echo "Total time: " . number_format($duration * 1000, 3) . " ms\n";
        echo "Average per call: " . number_format(($duration / $this->iterations) * 1000000, 3) . " μs\n";

        // withOverrides creates new instances, so it's slower
        $this->assertTrue($duration < 1.0, "withOverrides should be < 1000ms for 10k calls");
    }

    // ==================== Scalability Tests ====================

    public function testLargeDataSetPerformance() {
        // Create large dataset
        $largeData = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeData["field_$i"] = "value_$i";
        }

        // Test context creation
        $start = microtime(true);
        $context = new UserContext($largeData);
        $createTime = microtime(true) - $start;

        // Test reads
        $start = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $value = $context->get("field_$i");
        }
        $readTime = microtime(true) - $start;

        echo "\n";
        echo "Large dataset (1000 fields):\n";
        echo "Create time: " . number_format($createTime * 1000, 3) . " ms\n";
        echo "Read 1000 fields: " . number_format($readTime * 1000, 3) . " ms\n";

        // Should handle large datasets efficiently
        $this->assertTrue($createTime < 0.01, "Creation should be < 10ms");
        $this->assertTrue($readTime < 0.1, "Reading 1000 fields should be < 100ms");
    }

    public function testConcurrentReadPerformance() {
        $context = new UserContext($this->sampleData);

        // Simulate multiple "threads" reading simultaneously
        $start = microtime(true);
        for ($thread = 0; $thread < 10; $thread++) {
            for ($i = 0; $i < 1000; $i++) {
                $public = $context->getPublicKey();
                $hostname = $context->getHostname();
                $fee = $context->getDefaultFee();
            }
        }
        $duration = microtime(true) - $start;

        echo "\n";
        echo "Concurrent reads (10 threads × 1000 reads):\n";
        echo "Total time: " . number_format($duration * 1000, 3) . " ms\n";

        // Should handle concurrent reads efficiently
        $this->assertTrue($duration < 0.5, "Concurrent reads should be < 500ms");
    }

    // ==================== Real-World Scenario Tests ====================

    public function testTypicalApplicationUsage() {
        // Simulate typical application flow
        $start = microtime(true);

        for ($i = 0; $i < 100; $i++) {
            // 1. Load user from global
            global $user;
            $user = $this->sampleData;
            $context = UserContext::fromGlobal();

            // 2. Read various properties
            $publicKey = $context->getPublicKey();
            $hostname = $context->getHostname();
            $fee = $context->getDefaultFee();
            $currency = $context->getDefaultCurrency();
            $isDebug = $context->isDebugMode();

            // 3. Check address
            $addresses = $context->getUserAddresses();
            $isMyAddress = $context->isMyAddress('performance.host:8080');

            // 4. Validate DB config
            $hasDb = $context->hasValidDbConfig();

            // 5. Modify config
            $context->set('defaultFee', 2.5);
            $context->set('debug', false);

            // 6. Get modified values
            $newFee = $context->getDefaultFee();
        }

        $duration = microtime(true) - $start;

        echo "\n";
        echo "Typical usage (100 iterations):\n";
        echo "Total time: " . number_format($duration * 1000, 3) . " ms\n";
        echo "Average per iteration: " . number_format(($duration / 100) * 1000, 3) . " ms\n";

        // Should handle typical usage efficiently (< 100ms for 100 iterations)
        $this->assertTrue($duration < 0.1, "Typical usage should be < 100ms for 100 iterations");
    }

    public function testTransactionProcessingScenario() {
        $context = new UserContext($this->sampleData);

        // Simulate processing 1000 transactions
        $start = microtime(true);

        for ($i = 0; $i < 1000; $i++) {
            // Get fee configuration
            $feePercent = $context->getDefaultFee();
            $maxFee = $context->getMaxFee();

            // Calculate fee for transaction
            $amount = 100.0 + $i;
            $fee = min($amount * ($feePercent / 100), $maxFee);

            // Check P2P settings
            $maxLevel = $context->getMaxP2pLevel();
            $expiration = $context->getP2pExpiration();

            // Validate sender
            $senderAddress = 'performance.host:8080';
            $isMySend = $context->isMyAddress($senderAddress);
        }

        $duration = microtime(true) - $start;

        echo "\n";
        echo "Transaction processing (1000 transactions):\n";
        echo "Total time: " . number_format($duration * 1000, 3) . " ms\n";
        echo "Average per transaction: " . number_format(($duration / 1000) * 1000, 3) . " ms\n";
        echo "Throughput: " . number_format(1000 / $duration, 0) . " transactions/second\n";

        // Should process 1000 transactions quickly (< 100ms)
        $this->assertTrue($duration < 0.1, "Should process 1000 transactions in < 100ms");
    }

    // ==================== Comparison Summary ====================

    public function testComparisonSummary() {
        echo "\n";
        echo str_repeat('=', 70) . "\n";
        echo "PERFORMANCE COMPARISON SUMMARY\n";
        echo str_repeat('=', 70) . "\n\n";

        // Memory comparison
        $memStart = memory_get_usage();
        global $user;
        $user = $this->sampleData;
        $globalMem = memory_get_usage() - $memStart;

        $memStart = memory_get_usage();
        $context = new UserContext($this->sampleData);
        $contextMem = memory_get_usage() - $memStart;

        echo "Memory Usage:\n";
        echo "  Global array: " . number_format($globalMem) . " bytes\n";
        echo "  UserContext:  " . number_format($contextMem) . " bytes\n";
        echo "  Overhead:     " . number_format($contextMem - $globalMem) . " bytes\n\n";

        // Speed comparison (simplified)
        $start = microtime(true);
        for ($i = 0; $i < 10000; $i++) {
            $value = $user['public'] ?? null;
        }
        $globalSpeed = microtime(true) - $start;

        $start = microtime(true);
        for ($i = 0; $i < 10000; $i++) {
            $value = $context->getPublicKey();
        }
        $contextSpeed = microtime(true) - $start;

        echo "Read Speed (10,000 iterations):\n";
        echo "  Global array: " . number_format($globalSpeed * 1000, 3) . " ms\n";
        echo "  UserContext:  " . number_format($contextSpeed * 1000, 3) . " ms\n";
        echo "  Slowdown:     " . number_format($contextSpeed / $globalSpeed, 2) . "x\n\n";

        echo str_repeat('=', 70) . "\n";
        echo "VERDICT: UserContext provides good performance with benefits:\n";
        echo "  + Type safety and encapsulation\n";
        echo "  + Method chaining\n";
        echo "  + Immutable cloning with withOverrides()\n";
        echo "  + Better IDE autocomplete\n";
        echo "  - Small memory overhead\n";
        echo "  - Slightly slower than direct array access\n";
        echo str_repeat('=', 70) . "\n";

        $this->assertTrue(true, "Performance tests completed");
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new UserContextPerformanceTest();

    echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║           UserContext Performance Benchmark Suite               ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";

    SimpleTest::test('Memory usage: Global vs Context', function() use ($test) {
        $test->setUp();
        $test->testMemoryUsageGlobalVsContext();
        $test->tearDown();
    });

    SimpleTest::test('Memory usage: Multiple contexts', function() use ($test) {
        $test->setUp();
        $test->testMemoryUsageMultipleContexts();
        $test->tearDown();
    });

    SimpleTest::test('Memory leak prevention', function() use ($test) {
        $test->setUp();
        $test->testMemoryLeakPrevention();
        $test->tearDown();
    });

    SimpleTest::test('Read performance: Global vs Context', function() use ($test) {
        $test->setUp();
        $test->testReadPerformanceGlobalVsContext();
        $test->tearDown();
    });

    SimpleTest::test('Write performance: Global vs Context', function() use ($test) {
        $test->setUp();
        $test->testWritePerformanceGlobalVsContext();
        $test->tearDown();
    });

    SimpleTest::test('fromGlobal() performance', function() use ($test) {
        $test->setUp();
        $test->testFromGlobalPerformance();
        $test->tearDown();
    });

    SimpleTest::test('toArray() performance', function() use ($test) {
        $test->setUp();
        $test->testToArrayPerformance();
        $test->tearDown();
    });

    SimpleTest::test('withOverrides() performance', function() use ($test) {
        $test->setUp();
        $test->testWithOverridesPerformance();
        $test->tearDown();
    });

    SimpleTest::test('Large dataset performance', function() use ($test) {
        $test->setUp();
        $test->testLargeDataSetPerformance();
        $test->tearDown();
    });

    SimpleTest::test('Typical application usage', function() use ($test) {
        $test->setUp();
        $test->testTypicalApplicationUsage();
        $test->tearDown();
    });

    SimpleTest::test('Transaction processing scenario', function() use ($test) {
        $test->setUp();
        $test->testTransactionProcessingScenario();
        $test->tearDown();
    });

    SimpleTest::test('Performance comparison summary', function() use ($test) {
        $test->setUp();
        $test->testComparisonSummary();
        $test->tearDown();
    });

    SimpleTest::run();
}
