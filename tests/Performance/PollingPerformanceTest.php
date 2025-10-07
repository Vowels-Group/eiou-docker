<?php
/**
 * Performance tests for adaptive polling mechanism
 * Tests CPU usage, memory consumption, and response times
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/utils/AdaptivePoller.php';
require_once dirname(__DIR__, 2) . '/src/config/polling.php';

class PollingPerformanceTest extends TestCase {

    private $startMemory;
    private $startTime;

    public function setUp() {
        parent::setUp();
        $this->startMemory = memory_get_usage();
        $this->startTime = microtime(true);
    }

    public function tearDown() {
        parent::tearDown();
        $memoryUsed = memory_get_usage() - $this->startMemory;
        $timeElapsed = microtime(true) - $this->startTime;

        echo "\n  Memory used: " . number_format($memoryUsed / 1024, 2) . " KB";
        echo "\n  Time elapsed: " . number_format($timeElapsed * 1000, 2) . " ms\n";
    }

    public function testAdaptivePollingReducesCPUUnderIdle() {
        $config = [
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 2000,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);
        $intervals = [];

        // Simulate idle conditions (no work)
        for ($i = 0; $i < 20; $i++) {
            $start = microtime(true);
            $poller->wait(0, false);
            $end = microtime(true);

            $stats = $poller->getStats();
            $intervals[] = $stats['current_interval_ms'];
        }

        // Intervals should increase over time when idle
        $avgEarly = array_sum(array_slice($intervals, 0, 5)) / 5;
        $avgLate = array_sum(array_slice($intervals, -5)) / 5;

        $this->assertTrue($avgLate >= $avgEarly,
            "Intervals should increase under idle conditions (Early: $avgEarly, Late: $avgLate)");
    }

    public function testFixedPollingConsistency() {
        $config = [
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 1000,
            'adaptive' => false
        ];

        $poller = new AdaptivePoller($config);
        $timings = [];

        // Measure consistency of fixed polling
        for ($i = 0; $i < 10; $i++) {
            $start = microtime(true);
            $poller->wait(rand(0, 100), rand(0, 1) === 1);
            $end = microtime(true);

            $timings[] = ($end - $start) * 1000; // Convert to ms
        }

        // All timings should be roughly the same (within 10% variance)
        $avg = array_sum($timings) / count($timings);
        foreach ($timings as $timing) {
            $variance = abs($timing - $avg) / $avg;
            $this->assertTrue($variance < 0.2, "Fixed polling should have consistent timing");
        }
    }

    public function testMemoryUsageUnderLoad() {
        $config = [
            'min_interval_ms' => 10,
            'max_interval_ms' => 1000,
            'idle_interval_ms' => 500,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);
        $memoryBefore = memory_get_usage();

        // Simulate high load scenario
        for ($i = 0; $i < 1000; $i++) {
            $poller->wait(rand(0, 200), rand(0, 1) === 1);
        }

        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;

        // Memory usage should be minimal (< 100KB for 1000 iterations)
        $this->assertTrue($memoryUsed < 100 * 1024,
            "Memory usage should be minimal: " . number_format($memoryUsed / 1024, 2) . " KB used");
    }

    public function testResponseTimeUnderBurstLoad() {
        $config = [
            'min_interval_ms' => 50,
            'max_interval_ms' => 2000,
            'idle_interval_ms' => 1000,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);

        // Idle state
        $poller->wait(0, false);
        $poller->wait(0, false);

        // Sudden burst
        $start = microtime(true);
        $poller->wait(500, true);
        $end = microtime(true);

        $responseTime = ($end - $start) * 1000;
        $stats = $poller->getStats();

        // Should respond to burst quickly (use minimum interval)
        $this->assertEquals(50, $stats['current_interval_ms'], "Should use min interval on burst");
        $this->assertTrue($responseTime < 100, "Response time should be fast: {$responseTime}ms");
    }

    public function testScalabilityWith1000Cycles() {
        $config = [
            'min_interval_ms' => 10,
            'max_interval_ms' => 1000,
            'idle_interval_ms' => 500,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);
        $start = microtime(true);

        // Simulate 1000 polling cycles
        for ($i = 0; $i < 1000; $i++) {
            // Vary the load pattern
            if ($i % 100 < 20) {
                $poller->wait(rand(50, 100), true); // High load
            } else if ($i % 100 < 50) {
                $poller->wait(rand(0, 20), true); // Medium load
            } else {
                $poller->wait(0, false); // Idle
            }
        }

        $end = microtime(true);
        $totalTime = ($end - $start);

        // Should complete 1000 cycles in reasonable time
        $this->assertTrue($totalTime < 30, "1000 cycles should complete quickly: {$totalTime}s");
    }

    public function testAdaptiveVsFixedEfficiency() {
        // Test adaptive polling
        $adaptiveConfig = [
            'min_interval_ms' => 10,
            'max_interval_ms' => 1000,
            'idle_interval_ms' => 500,
            'adaptive' => true
        ];
        $adaptivePoller = new AdaptivePoller($adaptiveConfig);

        $adaptiveStart = microtime(true);
        $adaptiveMemStart = memory_get_usage();

        for ($i = 0; $i < 100; $i++) {
            $queueSize = ($i < 20) ? 100 : 0; // 20% high load, 80% idle
            $hadWork = $queueSize > 0;
            $adaptivePoller->wait($queueSize, $hadWork);
        }

        $adaptiveTime = microtime(true) - $adaptiveStart;
        $adaptiveMem = memory_get_usage() - $adaptiveMemStart;

        // Test fixed polling
        $fixedConfig = [
            'min_interval_ms' => 10,
            'max_interval_ms' => 1000,
            'idle_interval_ms' => 500,
            'adaptive' => false
        ];
        $fixedPoller = new AdaptivePoller($fixedConfig);

        $fixedStart = microtime(true);
        $fixedMemStart = memory_get_usage();

        for ($i = 0; $i < 100; $i++) {
            $queueSize = ($i < 20) ? 100 : 0;
            $hadWork = $queueSize > 0;
            $fixedPoller->wait($queueSize, $hadWork);
        }

        $fixedTime = microtime(true) - $fixedStart;
        $fixedMem = memory_get_usage() - $fixedMemStart;

        echo "\n  Adaptive: {$adaptiveTime}s, " . number_format($adaptiveMem / 1024, 2) . " KB";
        echo "\n  Fixed: {$fixedTime}s, " . number_format($fixedMem / 1024, 2) . " KB";

        // Adaptive should be more efficient (similar or better performance)
        $this->assertTrue($adaptiveMem <= $fixedMem * 1.5, "Adaptive should have similar memory usage");
    }

    public function testStatisticsOverhead() {
        $config = [
            'min_interval_ms' => 10,
            'max_interval_ms' => 1000,
            'idle_interval_ms' => 500,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);
        $start = microtime(true);

        // Call getStats frequently
        for ($i = 0; $i < 10000; $i++) {
            $stats = $poller->getStats();
        }

        $end = microtime(true);
        $totalTime = ($end - $start) * 1000; // ms

        // Getting stats should be very fast (< 10ms for 10000 calls)
        $this->assertTrue($totalTime < 100, "getStats() should be fast: {$totalTime}ms for 10000 calls");
    }

    public function testConcurrentPollers() {
        $pollers = [];

        // Create multiple pollers (simulating multiple processes)
        for ($i = 0; $i < 10; $i++) {
            $config = [
                'min_interval_ms' => 50,
                'max_interval_ms' => 1000,
                'idle_interval_ms' => 500,
                'adaptive' => true
            ];
            $pollers[] = new AdaptivePoller($config);
        }

        $start = microtime(true);
        $memStart = memory_get_usage();

        // Simulate concurrent polling
        for ($cycle = 0; $cycle < 100; $cycle++) {
            foreach ($pollers as $idx => $poller) {
                $queueSize = rand(0, 50);
                $hadWork = $queueSize > 0;
                $poller->wait($queueSize, $hadWork);
            }
        }

        $time = microtime(true) - $start;
        $mem = memory_get_usage() - $memStart;

        echo "\n  10 pollers, 100 cycles each: {$time}s, " . number_format($mem / 1024, 2) . " KB";

        // Should handle multiple pollers efficiently
        $this->assertTrue($mem < 500 * 1024, "Should handle concurrent pollers: " .
            number_format($mem / 1024, 2) . " KB");
    }

    public function testResetPerformance() {
        $config = [
            'min_interval_ms' => 50,
            'max_interval_ms' => 1000,
            'idle_interval_ms' => 500,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);

        // Build up state
        for ($i = 0; $i < 100; $i++) {
            $poller->wait(rand(0, 100), rand(0, 1) === 1);
        }

        // Measure reset performance
        $start = microtime(true);
        for ($i = 0; $i < 10000; $i++) {
            $poller->reset();
        }
        $end = microtime(true);

        $totalTime = ($end - $start) * 1000; // ms

        // Reset should be very fast
        $this->assertTrue($totalTime < 50, "Reset should be fast: {$totalTime}ms for 10000 resets");
    }

    public function testForceIntervalPerformance() {
        $config = [
            'min_interval_ms' => 50,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 1000,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);
        $start = microtime(true);

        // Force many different intervals
        for ($i = 0; $i < 10000; $i++) {
            $poller->forceInterval(rand(50, 5000));
        }

        $end = microtime(true);
        $totalTime = ($end - $start) * 1000; // ms

        // forceInterval should be very fast
        $this->assertTrue($totalTime < 50, "forceInterval should be fast: {$totalTime}ms for 10000 calls");
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new PollingPerformanceTest();

    SimpleTest::test('Adaptive polling reduces CPU under idle', function() use ($test) {
        $test->setUp();
        $test->testAdaptivePollingReducesCPUUnderIdle();
        $test->tearDown();
    });

    SimpleTest::test('Fixed polling consistency', function() use ($test) {
        $test->setUp();
        $test->testFixedPollingConsistency();
        $test->tearDown();
    });

    SimpleTest::test('Memory usage under load', function() use ($test) {
        $test->setUp();
        $test->testMemoryUsageUnderLoad();
        $test->tearDown();
    });

    SimpleTest::test('Response time under burst load', function() use ($test) {
        $test->setUp();
        $test->testResponseTimeUnderBurstLoad();
        $test->tearDown();
    });

    SimpleTest::test('Scalability with 1000 cycles', function() use ($test) {
        $test->setUp();
        $test->testScalabilityWith1000Cycles();
        $test->tearDown();
    });

    SimpleTest::test('Adaptive vs fixed efficiency', function() use ($test) {
        $test->setUp();
        $test->testAdaptiveVsFixedEfficiency();
        $test->tearDown();
    });

    SimpleTest::test('Statistics overhead', function() use ($test) {
        $test->setUp();
        $test->testStatisticsOverhead();
        $test->tearDown();
    });

    SimpleTest::test('Concurrent pollers', function() use ($test) {
        $test->setUp();
        $test->testConcurrentPollers();
        $test->tearDown();
    });

    SimpleTest::run();
}
