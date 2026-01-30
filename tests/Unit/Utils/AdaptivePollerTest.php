<?php
/**
 * Unit Tests for AdaptivePoller
 *
 * Tests adaptive polling interval calculation and state management.
 */

namespace Eiou\Tests\Utils;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Utils\AdaptivePoller;

#[CoversClass(AdaptivePoller::class)]
class AdaptivePollerTest extends TestCase
{
    /**
     * Test constructor with default config
     */
    public function testConstructorWithDefaultConfig(): void
    {
        $poller = new AdaptivePoller([]);

        $stats = $poller->getStats();
        $this->assertEquals(2000, $stats['current_interval_ms']); // Default idle
        $this->assertEquals(0, $stats['consecutive_empty']);
        $this->assertEquals(0, $stats['consecutive_success']);
        $this->assertEquals(0, $stats['last_queue_size']);
        $this->assertTrue($stats['adaptive_enabled']);
    }

    /**
     * Test constructor with custom config
     */
    public function testConstructorWithCustomConfig(): void
    {
        $config = [
            'min_interval_ms' => 50,
            'max_interval_ms' => 10000,
            'idle_interval_ms' => 3000,
            'adaptive' => false
        ];

        $poller = new AdaptivePoller($config);
        $stats = $poller->getStats();

        $this->assertEquals(3000, $stats['current_interval_ms']);
        $this->assertFalse($stats['adaptive_enabled']);
    }

    /**
     * Test getStats returns expected structure
     */
    public function testGetStatsReturnsExpectedStructure(): void
    {
        $poller = new AdaptivePoller([]);
        $stats = $poller->getStats();

        $this->assertArrayHasKey('current_interval_ms', $stats);
        $this->assertArrayHasKey('consecutive_empty', $stats);
        $this->assertArrayHasKey('consecutive_success', $stats);
        $this->assertArrayHasKey('last_queue_size', $stats);
        $this->assertArrayHasKey('runtime_seconds', $stats);
        $this->assertArrayHasKey('adaptive_enabled', $stats);
    }

    /**
     * Test runtime seconds increases
     */
    public function testRuntimeSecondsIncreases(): void
    {
        $poller = new AdaptivePoller([]);
        $stats1 = $poller->getStats();

        usleep(10000); // 10ms

        $stats2 = $poller->getStats();
        $this->assertGreaterThan($stats1['runtime_seconds'], $stats2['runtime_seconds']);
    }

    /**
     * Test reset clears all state
     */
    public function testResetClearsAllState(): void
    {
        $poller = new AdaptivePoller([
            'idle_interval_ms' => 1000,
            'adaptive' => false // Avoid usleep delays
        ]);

        // Simulate some activity by setting up internal state via forceInterval
        $poller->forceInterval(500);

        $poller->reset();
        $stats = $poller->getStats();

        $this->assertEquals(1000, $stats['current_interval_ms']); // Back to idle
        $this->assertEquals(0, $stats['consecutive_empty']);
        $this->assertEquals(0, $stats['consecutive_success']);
        $this->assertEquals(0, $stats['last_queue_size']);
    }

    /**
     * Test forceInterval sets interval within bounds
     */
    public function testForceIntervalWithinBounds(): void
    {
        $poller = new AdaptivePoller([
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000
        ]);

        $poller->forceInterval(1000);
        $stats = $poller->getStats();
        $this->assertEquals(1000, $stats['current_interval_ms']);
    }

    /**
     * Test forceInterval clamps to minimum
     */
    public function testForceIntervalClampsToMinimum(): void
    {
        $poller = new AdaptivePoller([
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000
        ]);

        $poller->forceInterval(10); // Below minimum
        $stats = $poller->getStats();
        $this->assertEquals(100, $stats['current_interval_ms']);
    }

    /**
     * Test forceInterval clamps to maximum
     */
    public function testForceIntervalClampsToMaximum(): void
    {
        $poller = new AdaptivePoller([
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000
        ]);

        $poller->forceInterval(10000); // Above maximum
        $stats = $poller->getStats();
        $this->assertEquals(5000, $stats['current_interval_ms']);
    }

    /**
     * Test forceInterval with exact minimum
     */
    public function testForceIntervalAtMinimum(): void
    {
        $poller = new AdaptivePoller([
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000
        ]);

        $poller->forceInterval(100);
        $stats = $poller->getStats();
        $this->assertEquals(100, $stats['current_interval_ms']);
    }

    /**
     * Test forceInterval with exact maximum
     */
    public function testForceIntervalAtMaximum(): void
    {
        $poller = new AdaptivePoller([
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000
        ]);

        $poller->forceInterval(5000);
        $stats = $poller->getStats();
        $this->assertEquals(5000, $stats['current_interval_ms']);
    }

    /**
     * Test non-adaptive mode uses idle interval
     */
    public function testNonAdaptiveModeUsesIdleInterval(): void
    {
        $poller = new AdaptivePoller([
            'idle_interval_ms' => 500,
            'adaptive' => false
        ]);

        $stats = $poller->getStats();
        $this->assertFalse($stats['adaptive_enabled']);
        $this->assertEquals(500, $stats['current_interval_ms']);
    }

    /**
     * Test initial state is at idle interval
     */
    public function testInitialStateIsIdleInterval(): void
    {
        $poller = new AdaptivePoller([
            'idle_interval_ms' => 2500
        ]);

        $stats = $poller->getStats();
        $this->assertEquals(2500, $stats['current_interval_ms']);
    }

    /**
     * Test multiple forceInterval calls
     */
    public function testMultipleForceIntervalCalls(): void
    {
        $poller = new AdaptivePoller([
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000
        ]);

        $poller->forceInterval(1000);
        $this->assertEquals(1000, $poller->getStats()['current_interval_ms']);

        $poller->forceInterval(2000);
        $this->assertEquals(2000, $poller->getStats()['current_interval_ms']);

        $poller->forceInterval(500);
        $this->assertEquals(500, $poller->getStats()['current_interval_ms']);
    }

    /**
     * Test reset restores runtime to near zero
     */
    public function testResetRestoresRuntimeNearZero(): void
    {
        $poller = new AdaptivePoller([]);

        usleep(50000); // 50ms
        $statsBefore = $poller->getStats();
        $this->assertGreaterThan(0.04, $statsBefore['runtime_seconds']);

        $poller->reset();
        $statsAfter = $poller->getStats();

        // Runtime should be very small after reset
        $this->assertLessThan(0.01, $statsAfter['runtime_seconds']);
    }
}
