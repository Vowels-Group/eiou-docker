<?php
/**
 * Unit tests for AdaptivePoller class
 * Tests adaptive polling behavior, interval calculations, and statistics
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/utils/AdaptivePoller.php';

class AdaptivePollerTest extends TestCase {

    public function testPollerInitialization() {
        $config = [
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 2000,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);
        $stats = $poller->getStats();

        $this->assertEquals(2000, $stats['current_interval_ms'], "Should start with idle interval");
        $this->assertTrue($stats['adaptive_enabled'], "Adaptive should be enabled");
        $this->assertEquals(0, $stats['consecutive_empty'], "Should start with 0 consecutive empty");
    }

    public function testAdaptiveIntervalIncreaseOnIdle() {
        $config = [
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 2000,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);

        // Simulate multiple empty cycles
        for ($i = 0; $i < 15; $i++) {
            $poller->wait(0, false);
        }

        $stats = $poller->getStats();
        $this->assertEquals(2000, $stats['current_interval_ms'], "Should use idle interval after many empty cycles");
        $this->assertTrue($stats['consecutive_empty'] > 10, "Should track consecutive empty cycles");
    }

    public function testAdaptiveIntervalDecreaseOnLoad() {
        $config = [
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 2000,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);

        // Simulate high queue size
        $poller->wait(100, true);

        $stats = $poller->getStats();
        $this->assertEquals(100, $stats['current_interval_ms'], "Should use minimum interval with large queue");
        $this->assertEquals(100, $stats['last_queue_size'], "Should track queue size");
    }

    public function testFixedIntervalMode() {
        $config = [
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 2000,
            'adaptive' => false
        ];

        $poller = new AdaptivePoller($config);

        // Wait multiple times with different conditions
        $poller->wait(100, true);
        $poller->wait(0, false);

        $stats = $poller->getStats();
        $this->assertFalse($stats['adaptive_enabled'], "Adaptive should be disabled");
        // In fixed mode, interval should remain constant
    }

    public function testResetFunctionality() {
        $config = [
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 2000,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);

        // Generate some activity
        $poller->wait(50, true);
        $poller->wait(30, true);

        $statsBefore = $poller->getStats();
        $this->assertTrue($statsBefore['consecutive_success'] > 0, "Should have success count");

        // Reset
        $poller->reset();

        $statsAfter = $poller->getStats();
        $this->assertEquals(0, $statsAfter['consecutive_empty'], "Should reset empty count");
        $this->assertEquals(0, $statsAfter['consecutive_success'], "Should reset success count");
        $this->assertEquals(2000, $statsAfter['current_interval_ms'], "Should reset to idle interval");
    }

    public function testForceInterval() {
        $config = [
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 2000,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);
        $poller->forceInterval(1000);

        $stats = $poller->getStats();
        $this->assertEquals(1000, $stats['current_interval_ms'], "Should force specific interval");

        // Test boundary conditions
        $poller->forceInterval(50); // Below minimum
        $stats = $poller->getStats();
        $this->assertEquals(100, $stats['current_interval_ms'], "Should enforce minimum interval");

        $poller->forceInterval(10000); // Above maximum
        $stats = $poller->getStats();
        $this->assertEquals(5000, $stats['current_interval_ms'], "Should enforce maximum interval");
    }

    public function testProportionalScaling() {
        $config = [
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 2000,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);

        // Test with moderate queue (should scale proportionally)
        $poller->wait(25, true); // 25% of the 100 threshold

        $stats = $poller->getStats();
        // With 25 items, should be somewhere between min and idle
        $this->assertTrue($stats['current_interval_ms'] >= 100, "Should be at or above minimum");
        $this->assertTrue($stats['current_interval_ms'] <= 2000, "Should be at or below idle");
    }

    public function testStatisticsTracking() {
        $config = [
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 2000,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);

        // Simulate various scenarios
        $poller->wait(10, true);  // Success with queue
        $poller->wait(5, true);   // Success with smaller queue
        $poller->wait(0, false);  // Empty
        $poller->wait(0, false);  // Empty again

        $stats = $poller->getStats();

        $this->assertArrayHasKey('current_interval_ms', $stats, "Should track current interval");
        $this->assertArrayHasKey('consecutive_empty', $stats, "Should track consecutive empty");
        $this->assertArrayHasKey('consecutive_success', $stats, "Should track consecutive success");
        $this->assertArrayHasKey('last_queue_size', $stats, "Should track last queue size");
        $this->assertArrayHasKey('runtime_seconds', $stats, "Should track runtime");
        $this->assertArrayHasKey('adaptive_enabled', $stats, "Should track adaptive status");

        $this->assertEquals(2, $stats['consecutive_empty'], "Should count 2 consecutive empty");
        $this->assertEquals(0, $stats['last_queue_size'], "Should track last queue size as 0");
    }

    public function testBurstLoadHandling() {
        $config = [
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 2000,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);

        // Simulate burst load scenario
        $poller->wait(0, false);    // Start idle
        $poller->wait(0, false);    // Still idle
        $poller->wait(100, true);   // Sudden burst

        $stats = $poller->getStats();
        $this->assertEquals(100, $stats['current_interval_ms'], "Should immediately respond to burst");
        $this->assertEquals(1, $stats['consecutive_success'], "Should track success");
    }

    public function testGradualBackoff() {
        $config = [
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 2000,
            'adaptive' => true
        ];

        $poller = new AdaptivePoller($config);

        // Simulate gradual decrease in activity
        $intervals = [];
        for ($i = 10; $i >= 0; $i--) {
            $poller->wait($i, $i > 0);
            $stats = $poller->getStats();
            $intervals[] = $stats['current_interval_ms'];
        }

        // Verify that intervals generally increase as load decreases
        // (allowing for some variance in the algorithm)
        $lastInterval = $intervals[count($intervals) - 1];
        $this->assertTrue($lastInterval >= 1000, "Should increase interval as load decreases");
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new AdaptivePollerTest();

    SimpleTest::test('Poller initialization', function() use ($test) {
        $test->testPollerInitialization();
    });

    SimpleTest::test('Adaptive interval increase on idle', function() use ($test) {
        $test->testAdaptiveIntervalIncreaseOnIdle();
    });

    SimpleTest::test('Adaptive interval decrease on load', function() use ($test) {
        $test->testAdaptiveIntervalDecreaseOnLoad();
    });

    SimpleTest::test('Fixed interval mode', function() use ($test) {
        $test->testFixedIntervalMode();
    });

    SimpleTest::test('Reset functionality', function() use ($test) {
        $test->testResetFunctionality();
    });

    SimpleTest::test('Force interval', function() use ($test) {
        $test->testForceInterval();
    });

    SimpleTest::test('Proportional scaling', function() use ($test) {
        $test->testProportionalScaling();
    });

    SimpleTest::test('Statistics tracking', function() use ($test) {
        $test->testStatisticsTracking();
    });

    SimpleTest::test('Burst load handling', function() use ($test) {
        $test->testBurstLoadHandling();
    });

    SimpleTest::test('Gradual backoff', function() use ($test) {
        $test->testGradualBackoff();
    });

    SimpleTest::run();
}
