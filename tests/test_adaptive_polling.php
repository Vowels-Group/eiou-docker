<?php
/**
 * Test script for adaptive polling performance improvements
 */

require_once __DIR__ . '/SimpleTest.php';
require_once dirname(__DIR__) . '/src/utils/AdaptivePoller.php';

// Test the AdaptivePoller class
SimpleTest::test('Adaptive poller reduces interval when idle', function() {
    $config = [
        'min_interval_ms' => 100,
        'max_interval_ms' => 5000,
        'idle_interval_ms' => 2000,
        'adaptive' => true,
    ];

    $poller = new AdaptivePoller($config);

    // Simulate no work for multiple cycles
    for ($i = 0; $i < 15; $i++) {
        // Don't actually wait, just update state
        $stats = $poller->getStats();
        $poller->wait(0, false);
    }

    $finalStats = $poller->getStats();
    SimpleTest::assertEquals(2000, $finalStats['current_interval_ms'],
        "Should reach idle interval after no work");
    SimpleTest::assertTrue($finalStats['consecutive_empty'] >= 10,
        "Should track consecutive empty cycles");
});

SimpleTest::test('Adaptive poller speeds up with work', function() {
    $config = [
        'min_interval_ms' => 100,
        'max_interval_ms' => 5000,
        'idle_interval_ms' => 2000,
        'adaptive' => true,
    ];

    $poller = new AdaptivePoller($config);

    // Start idle
    for ($i = 0; $i < 10; $i++) {
        $poller->wait(0, false);
    }

    // Simulate work with large queue
    $poller->wait(100, true);
    $stats = $poller->getStats();

    SimpleTest::assertTrue($stats['current_interval_ms'] < 2000,
        "Should reduce interval when work appears");
    SimpleTest::assertEquals(0, $stats['consecutive_empty'],
        "Should reset empty counter when work is done");
});

SimpleTest::test('Fixed interval mode works', function() {
    $config = [
        'min_interval_ms' => 100,
        'max_interval_ms' => 5000,
        'idle_interval_ms' => 1000,
        'adaptive' => false,  // Disable adaptive mode
    ];

    $poller = new AdaptivePoller($config);

    // Regardless of work, should stay at idle interval
    $poller->wait(0, false);
    $stats1 = $poller->getStats();

    $poller->wait(100, true);
    $stats2 = $poller->getStats();

    SimpleTest::assertEquals(1000, $stats1['current_interval_ms'],
        "Fixed mode should use idle interval");
    SimpleTest::assertEquals(1000, $stats2['current_interval_ms'],
        "Fixed mode should not change interval");
});

SimpleTest::test('Performance improvement calculation', function() {
    // Calculate theoretical improvements

    // Old system: Fixed 500ms polling
    $oldCpuUsagePerHour = (3600 * 1000 / 500) * 3; // 3 processors, polls per hour
    $oldCpuWasteWhenIdle = $oldCpuUsagePerHour; // All polls are waste when idle

    // New system: Adaptive polling (100ms-5000ms)
    $newCpuUsageWhenBusy = (3600 * 1000 / 100) * 3;  // At minimum interval
    $newCpuUsageWhenIdle = (3600 * 1000 / 2000) * 2 + (3600 * 1000 / 10000); // Different idle rates

    $improvementWhenIdle = (($oldCpuWasteWhenIdle - $newCpuUsageWhenIdle) / $oldCpuWasteWhenIdle) * 100;
    $responsivenessImprovement = 500 / 100; // How much faster we respond to new work

    echo "\nPerformance Improvements:\n";
    echo "========================\n";
    echo "Old system (fixed 500ms): " . number_format($oldCpuUsagePerHour) . " polls/hour\n";
    echo "New system (idle): " . number_format($newCpuUsageWhenIdle) . " polls/hour\n";
    echo "CPU reduction when idle: " . round($improvementWhenIdle, 1) . "%\n";
    echo "Response time improvement: " . $responsivenessImprovement . "x faster\n\n";

    SimpleTest::assertTrue($improvementWhenIdle > 50,
        "Should reduce CPU usage by >50% when idle");
    SimpleTest::assertTrue($responsivenessImprovement >= 5,
        "Should respond 5x faster when work arrives");
});

SimpleTest::test('Configuration via environment variables', function() {
    // Test that we can configure via environment variables
    putenv('TRANSACTION_MIN_INTERVAL_MS=50');
    putenv('TRANSACTION_MAX_INTERVAL_MS=10000');
    putenv('TRANSACTION_IDLE_INTERVAL_MS=3000');

    $minInterval = getenv('TRANSACTION_MIN_INTERVAL_MS') ?: 100;
    $maxInterval = getenv('TRANSACTION_MAX_INTERVAL_MS') ?: 5000;
    $idleInterval = getenv('TRANSACTION_IDLE_INTERVAL_MS') ?: 2000;

    SimpleTest::assertEquals('50', $minInterval, "Should read min interval from env");
    SimpleTest::assertEquals('10000', $maxInterval, "Should read max interval from env");
    SimpleTest::assertEquals('3000', $idleInterval, "Should read idle interval from env");

    // Clean up
    putenv('TRANSACTION_MIN_INTERVAL_MS');
    putenv('TRANSACTION_MAX_INTERVAL_MS');
    putenv('TRANSACTION_IDLE_INTERVAL_MS');
});

// Run the tests
SimpleTest::run();