<?php
/**
 * Unit Tests for TorCircuitHealth
 *
 * Tests per-.onion address failure tracking, cooldown activation,
 * success recovery, and bulk operations.
 *
 * @see https://github.com/eiou-org/eiou-docker/issues/699
 */

namespace Eiou\Tests\Utils;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Utils\TorCircuitHealth;
use Eiou\Core\Constants;
use ReflectionClass;

#[CoversClass(TorCircuitHealth::class)]
class TorCircuitHealthTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        // Override HEALTH_DIR to an isolated temp directory per test
        $this->testDir = sys_get_temp_dir() . '/tor-circuit-health-test-' . uniqid();
        mkdir($this->testDir, 0755, true);

        $reflection = new ReflectionClass(TorCircuitHealth::class);
        $constant = $reflection->getReflectionConstant('HEALTH_DIR');
        // Can't override constants, so we'll use the real /tmp dir.
        // Instead, clear state before and after each test.
        TorCircuitHealth::clearAll();
    }

    protected function tearDown(): void
    {
        TorCircuitHealth::clearAll();

        // Clean up our temp dir
        if (is_dir($this->testDir)) {
            $files = glob($this->testDir . '/*');
            if ($files) {
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
            @rmdir($this->testDir);
        }
    }

    // =========================================================================
    // isAvailable() Tests
    // =========================================================================

    public function testIsAvailableReturnsTrueForUnknownAddress(): void
    {
        $this->assertTrue(TorCircuitHealth::isAvailable('unknown123456.onion'));
    }

    public function testIsAvailableReturnsTrueAfterSingleFailure(): void
    {
        $address = 'singlefailure' . uniqid() . '.onion';
        TorCircuitHealth::recordFailure($address, 'Connection timed out');

        // Default max failures is 2, so 1 failure should still be available
        $this->assertTrue(TorCircuitHealth::isAvailable($address));
    }

    public function testIsAvailableReturnsFalseAfterMaxFailures(): void
    {
        $address = 'maxfailure' . uniqid() . '.onion';

        // Record failures up to the threshold (default: 2)
        for ($i = 0; $i < Constants::TOR_CIRCUIT_MAX_FAILURES; $i++) {
            TorCircuitHealth::recordFailure($address, 'Connection timed out');
        }

        $this->assertFalse(TorCircuitHealth::isAvailable($address));
    }

    public function testIsAvailableReturnsTrueAfterCooldownExpires(): void
    {
        $address = 'expired' . uniqid() . '.onion';

        // Record enough failures to trigger cooldown
        for ($i = 0; $i < Constants::TOR_CIRCUIT_MAX_FAILURES; $i++) {
            TorCircuitHealth::recordFailure($address, 'Connection timed out');
        }

        // Manually set cooldown_until to the past
        $reflection = new ReflectionClass(TorCircuitHealth::class);
        $method = $reflection->getMethod('getFilePath');
        $method->setAccessible(true);
        $filePath = $method->invoke(null, $address);

        $data = json_decode(file_get_contents($filePath), true);
        $data['cooldown_until'] = time() - 1; // Already expired
        file_put_contents($filePath, json_encode($data), LOCK_EX);

        $this->assertTrue(TorCircuitHealth::isAvailable($address));
    }

    // =========================================================================
    // recordFailure() Tests
    // =========================================================================

    public function testRecordFailureReturnsFalseBeforeThreshold(): void
    {
        $address = 'beforethresh' . uniqid() . '.onion';

        $result = TorCircuitHealth::recordFailure($address, 'Timeout');
        $this->assertFalse($result);
    }

    public function testRecordFailureReturnsTrueAtThreshold(): void
    {
        $address = 'atthresh' . uniqid() . '.onion';

        // Record one less than threshold
        for ($i = 0; $i < Constants::TOR_CIRCUIT_MAX_FAILURES - 1; $i++) {
            $result = TorCircuitHealth::recordFailure($address, 'Timeout');
            $this->assertFalse($result);
        }

        // This one should trigger cooldown
        $result = TorCircuitHealth::recordFailure($address, 'Timeout');
        $this->assertTrue($result);
    }

    public function testRecordFailureIncrementsConsecutiveFailures(): void
    {
        $address = 'increment' . uniqid() . '.onion';

        TorCircuitHealth::recordFailure($address, 'Error 1');
        $status = TorCircuitHealth::getStatus($address);
        $this->assertSame(1, $status['consecutive_failures']);

        TorCircuitHealth::recordFailure($address, 'Error 2');
        $status = TorCircuitHealth::getStatus($address);
        $this->assertSame(2, $status['consecutive_failures']);
    }

    public function testRecordFailureStoresLastError(): void
    {
        $address = 'lasterror' . uniqid() . '.onion';

        TorCircuitHealth::recordFailure($address, 'First error');
        $status = TorCircuitHealth::getStatus($address);
        $this->assertSame('First error', $status['last_error']);

        TorCircuitHealth::recordFailure($address, 'Second error');
        $status = TorCircuitHealth::getStatus($address);
        $this->assertSame('Second error', $status['last_error']);
    }

    public function testRecordFailureSetsLastFailureAt(): void
    {
        $address = 'timestamp' . uniqid() . '.onion';
        $before = time();

        TorCircuitHealth::recordFailure($address, 'Error');

        $status = TorCircuitHealth::getStatus($address);
        $this->assertGreaterThanOrEqual($before, $status['last_failure_at']);
        $this->assertLessThanOrEqual(time(), $status['last_failure_at']);
    }

    public function testRecordFailureSetsCooldownUntilOnThreshold(): void
    {
        $address = 'cooldowntime' . uniqid() . '.onion';

        for ($i = 0; $i < Constants::TOR_CIRCUIT_MAX_FAILURES; $i++) {
            TorCircuitHealth::recordFailure($address, 'Timeout');
        }

        $status = TorCircuitHealth::getStatus($address);
        $expectedMin = time() + Constants::TOR_CIRCUIT_COOLDOWN_SECONDS - 2;
        $expectedMax = time() + Constants::TOR_CIRCUIT_COOLDOWN_SECONDS + 2;

        $this->assertGreaterThanOrEqual($expectedMin, $status['cooldown_until']);
        $this->assertLessThanOrEqual($expectedMax, $status['cooldown_until']);
    }

    // =========================================================================
    // recordSuccess() Tests
    // =========================================================================

    public function testRecordSuccessRemovesHealthFile(): void
    {
        $address = 'successclear' . uniqid() . '.onion';

        TorCircuitHealth::recordFailure($address, 'Error');
        $this->assertNotNull(TorCircuitHealth::getStatus($address));

        TorCircuitHealth::recordSuccess($address);
        $this->assertNull(TorCircuitHealth::getStatus($address));
    }

    public function testRecordSuccessResetsAfterCooldown(): void
    {
        $address = 'resetcooldown' . uniqid() . '.onion';

        // Trigger cooldown
        for ($i = 0; $i < Constants::TOR_CIRCUIT_MAX_FAILURES; $i++) {
            TorCircuitHealth::recordFailure($address, 'Timeout');
        }
        $this->assertFalse(TorCircuitHealth::isAvailable($address));

        // Success should clear everything
        TorCircuitHealth::recordSuccess($address);
        $this->assertTrue(TorCircuitHealth::isAvailable($address));
        $this->assertNull(TorCircuitHealth::getStatus($address));
    }

    public function testRecordSuccessNoOpForUnknownAddress(): void
    {
        // Should not throw
        TorCircuitHealth::recordSuccess('nonexistent' . uniqid() . '.onion');
        $this->assertTrue(true); // No exception
    }

    // =========================================================================
    // getStatus() Tests
    // =========================================================================

    public function testGetStatusReturnsNullForUnknownAddress(): void
    {
        $this->assertNull(TorCircuitHealth::getStatus('unknown' . uniqid() . '.onion'));
    }

    public function testGetStatusReturnsDataAfterFailure(): void
    {
        $address = 'statusdata' . uniqid() . '.onion';
        TorCircuitHealth::recordFailure($address, 'Test error');

        $status = TorCircuitHealth::getStatus($address);

        $this->assertIsArray($status);
        $this->assertSame($address, $status['address']);
        $this->assertSame(1, $status['consecutive_failures']);
        $this->assertSame('Test error', $status['last_error']);
        $this->assertArrayHasKey('last_failure_at', $status);
        $this->assertArrayHasKey('in_cooldown', $status);
    }

    public function testGetStatusShowsInCooldownWhenActive(): void
    {
        $address = 'incooldown' . uniqid() . '.onion';

        for ($i = 0; $i < Constants::TOR_CIRCUIT_MAX_FAILURES; $i++) {
            TorCircuitHealth::recordFailure($address, 'Timeout');
        }

        $status = TorCircuitHealth::getStatus($address);
        $this->assertTrue($status['in_cooldown']);
        $this->assertArrayHasKey('cooldown_remaining_seconds', $status);
        $this->assertGreaterThan(0, $status['cooldown_remaining_seconds']);
    }

    public function testGetStatusShowsNotInCooldownWhenBelowThreshold(): void
    {
        $address = 'notincooldown' . uniqid() . '.onion';
        TorCircuitHealth::recordFailure($address, 'Error');

        $status = TorCircuitHealth::getStatus($address);
        $this->assertFalse($status['in_cooldown']);
    }

    // =========================================================================
    // getAllUnhealthy() Tests
    // =========================================================================

    public function testGetAllUnhealthyReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], TorCircuitHealth::getAllUnhealthy());
    }

    public function testGetAllUnhealthyReturnsOnlyCooldownAddresses(): void
    {
        $address1 = 'unhealthy1' . uniqid() . '.onion';
        $address2 = 'healthy2' . uniqid() . '.onion';
        $address3 = 'unhealthy3' . uniqid() . '.onion';

        // Put address1 in cooldown
        for ($i = 0; $i < Constants::TOR_CIRCUIT_MAX_FAILURES; $i++) {
            TorCircuitHealth::recordFailure($address1, 'Timeout');
        }

        // address2 has only 1 failure (below threshold)
        TorCircuitHealth::recordFailure($address2, 'Timeout');

        // Put address3 in cooldown
        for ($i = 0; $i < Constants::TOR_CIRCUIT_MAX_FAILURES; $i++) {
            TorCircuitHealth::recordFailure($address3, 'Timeout');
        }

        $unhealthy = TorCircuitHealth::getAllUnhealthy();
        $this->assertCount(2, $unhealthy);

        $addresses = array_column($unhealthy, 'address');
        $this->assertContains($address1, $addresses);
        $this->assertContains($address3, $addresses);
        $this->assertNotContains($address2, $addresses);
    }

    // =========================================================================
    // clearCooldown() Tests
    // =========================================================================

    public function testClearCooldownRemovesSpecificAddress(): void
    {
        $address1 = 'clear1' . uniqid() . '.onion';
        $address2 = 'clear2' . uniqid() . '.onion';

        // Put both in cooldown
        for ($i = 0; $i < Constants::TOR_CIRCUIT_MAX_FAILURES; $i++) {
            TorCircuitHealth::recordFailure($address1, 'Timeout');
            TorCircuitHealth::recordFailure($address2, 'Timeout');
        }

        $this->assertFalse(TorCircuitHealth::isAvailable($address1));
        $this->assertFalse(TorCircuitHealth::isAvailable($address2));

        // Clear only address1
        TorCircuitHealth::clearCooldown($address1);

        $this->assertTrue(TorCircuitHealth::isAvailable($address1));
        $this->assertFalse(TorCircuitHealth::isAvailable($address2));
    }

    // =========================================================================
    // clearAll() Tests
    // =========================================================================

    public function testClearAllRemovesAllHealthFiles(): void
    {
        $address1 = 'clearall1' . uniqid() . '.onion';
        $address2 = 'clearall2' . uniqid() . '.onion';

        for ($i = 0; $i < Constants::TOR_CIRCUIT_MAX_FAILURES; $i++) {
            TorCircuitHealth::recordFailure($address1, 'Timeout');
            TorCircuitHealth::recordFailure($address2, 'Timeout');
        }

        $this->assertFalse(TorCircuitHealth::isAvailable($address1));
        $this->assertFalse(TorCircuitHealth::isAvailable($address2));

        TorCircuitHealth::clearAll();

        $this->assertTrue(TorCircuitHealth::isAvailable($address1));
        $this->assertTrue(TorCircuitHealth::isAvailable($address2));
        $this->assertSame([], TorCircuitHealth::getAllUnhealthy());
    }

    public function testClearAllNoOpWhenDirectoryDoesNotExist(): void
    {
        // Should not throw even if health dir doesn't exist
        TorCircuitHealth::clearAll();
        $this->assertTrue(true);
    }

    // =========================================================================
    // File Path Hashing Tests
    // =========================================================================

    public function testDifferentAddressesGetDifferentFiles(): void
    {
        $address1 = 'addr1' . uniqid() . '.onion';
        $address2 = 'addr2' . uniqid() . '.onion';

        TorCircuitHealth::recordFailure($address1, 'Error 1');
        TorCircuitHealth::recordFailure($address2, 'Error 2');

        $status1 = TorCircuitHealth::getStatus($address1);
        $status2 = TorCircuitHealth::getStatus($address2);

        $this->assertSame($address1, $status1['address']);
        $this->assertSame($address2, $status2['address']);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testFailureCycleResetBySuccess(): void
    {
        $address = 'cycle' . uniqid() . '.onion';

        // Record one failure
        TorCircuitHealth::recordFailure($address, 'Error');
        $this->assertTrue(TorCircuitHealth::isAvailable($address));

        // Success resets
        TorCircuitHealth::recordSuccess($address);

        // Need full threshold again to trigger cooldown
        TorCircuitHealth::recordFailure($address, 'Error');
        $this->assertTrue(TorCircuitHealth::isAvailable($address));
    }

    public function testMultipleAddressesTrackIndependently(): void
    {
        $address1 = 'indep1' . uniqid() . '.onion';
        $address2 = 'indep2' . uniqid() . '.onion';

        // Put address1 in cooldown
        for ($i = 0; $i < Constants::TOR_CIRCUIT_MAX_FAILURES; $i++) {
            TorCircuitHealth::recordFailure($address1, 'Timeout');
        }

        // address2 still available
        $this->assertFalse(TorCircuitHealth::isAvailable($address1));
        $this->assertTrue(TorCircuitHealth::isAvailable($address2));

        // Success on address1 doesn't affect address2's tracking
        TorCircuitHealth::recordSuccess($address1);
        $this->assertTrue(TorCircuitHealth::isAvailable($address1));
    }

    public function testContinuedFailuresAfterCooldownExtendCooldown(): void
    {
        $address = 'extend' . uniqid() . '.onion';

        // Trigger initial cooldown
        for ($i = 0; $i < Constants::TOR_CIRCUIT_MAX_FAILURES; $i++) {
            TorCircuitHealth::recordFailure($address, 'Timeout');
        }

        $status1 = TorCircuitHealth::getStatus($address);

        // Additional failure should update cooldown
        TorCircuitHealth::recordFailure($address, 'Still down');

        $status2 = TorCircuitHealth::getStatus($address);
        $this->assertSame(
            Constants::TOR_CIRCUIT_MAX_FAILURES + 1,
            $status2['consecutive_failures']
        );
        // Cooldown should be refreshed (>= original)
        $this->assertGreaterThanOrEqual($status1['cooldown_until'], $status2['cooldown_until']);
    }
}
