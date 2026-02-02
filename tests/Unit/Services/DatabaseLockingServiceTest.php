<?php
/**
 * Unit Tests for DatabaseLockingService
 *
 * Tests MySQL advisory lock operations (GET_LOCK, RELEASE_LOCK, IS_FREE_LOCK).
 * Uses mocked PDO and PDOStatement to simulate database responses.
 *
 * Note: The DatabaseLockingService has a destructor that calls releaseAll().
 * Tests must account for this by ensuring mocks handle cleanup appropriately.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\DatabaseLockingService;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(DatabaseLockingService::class)]
class DatabaseLockingServiceTest extends TestCase
{
    private PDO $mockPdo;
    private PDOStatement $mockStmt;

    protected function setUp(): void
    {
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
    }

    /**
     * Helper to create a service instance with mocked PDO
     */
    private function createService(): DatabaseLockingService
    {
        return new DatabaseLockingService($this->mockPdo);
    }

    /**
     * Helper to setup mock for queries that handles both acquire and release.
     * This is needed because the destructor calls releaseAll() which triggers
     * additional queries when locks are held.
     *
     * @param array $returnValues Array of return values for sequential fetch calls
     */
    private function setupMockQuerySequence(array $returnValues): void
    {
        $callIndex = 0;
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturnCallback(function () use (&$callIndex, $returnValues) {
                $result = $returnValues[$callIndex] ?? ['released' => 1];
                $callIndex++;
                return $result;
            });
    }

    // =========================================================================
    // acquireLock() Tests
    // =========================================================================

    /**
     * Test acquireLock success when GET_LOCK returns 1
     */
    public function testAcquireLockSuccessReturnsTrue(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => 1],
            ['released' => 1],  // For destructor cleanup
        ]);

        $service = $this->createService();
        $result = $service->acquireLock('test_lock');

        $this->assertTrue($result);
    }

    /**
     * Test acquireLock success when GET_LOCK returns '1' (string)
     */
    public function testAcquireLockSuccessWithStringOne(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => '1'],
            ['released' => 1],
        ]);

        $service = $this->createService();
        $result = $service->acquireLock('test_lock');

        $this->assertTrue($result);
    }

    /**
     * Test acquireLock timeout when GET_LOCK returns 0
     */
    public function testAcquireLockTimeoutReturnsFalse(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => 0],
        ]);

        $service = $this->createService();
        $result = $service->acquireLock('test_lock', 5);

        $this->assertFalse($result);
    }

    /**
     * Test acquireLock timeout when GET_LOCK returns '0' (string)
     */
    public function testAcquireLockTimeoutWithStringZero(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => '0'],
        ]);

        $service = $this->createService();
        $result = $service->acquireLock('test_lock');

        $this->assertFalse($result);
    }

    /**
     * Test acquireLock error when GET_LOCK returns NULL
     */
    public function testAcquireLockErrorReturnsFalse(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => null],
        ]);

        $service = $this->createService();
        $result = $service->acquireLock('test_lock');

        $this->assertFalse($result);
    }

    /**
     * Test acquireLock when already holding the lock returns true without query
     */
    public function testAcquireLockWhenAlreadyHeldReturnsTrue(): void
    {
        $callCount = 0;
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                // First call: acquire lock
                // Second call: destructor releases lock
                return $callCount === 1
                    ? ['acquired' => 1]
                    : ['released' => 1];
            });

        $service = $this->createService();

        // First acquisition
        $result1 = $service->acquireLock('test_lock');
        $this->assertTrue($result1);

        // Second call should return true without another query (uses cached state)
        $result2 = $service->acquireLock('test_lock');
        $this->assertTrue($result2);

        // Verify the lock is still held (only one acquisition happened)
        $this->assertTrue($service->holdsLock('test_lock'));
    }

    /**
     * Test acquireLock handles PDOException gracefully
     */
    public function testAcquireLockHandlesPdoException(): void
    {
        $this->mockPdo->method('prepare')
            ->willThrowException(new PDOException('Connection lost'));

        $service = $this->createService();
        $result = $service->acquireLock('test_lock');

        $this->assertFalse($result);
    }

    /**
     * Test acquireLock uses correct timeout parameter
     */
    public function testAcquireLockUsesCorrectTimeout(): void
    {
        $capturedParams = [];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                if (isset($params['timeout'])) {
                    $capturedParams = $params;
                }
                return true;
            });
        $this->mockStmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['acquired' => 1],
                ['released' => 1]
            );

        $service = $this->createService();
        $service->acquireLock('test_lock', 60);

        $this->assertEquals(60, $capturedParams['timeout']);
    }

    /**
     * Test acquireLock handles negative timeout (converts to 0)
     */
    public function testAcquireLockHandlesNegativeTimeout(): void
    {
        $capturedParams = [];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                if (isset($params['timeout'])) {
                    $capturedParams = $params;
                }
                return true;
            });
        $this->mockStmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['acquired' => 1],
                ['released' => 1]
            );

        $service = $this->createService();
        $service->acquireLock('test_lock', -10);

        $this->assertEquals(0, $capturedParams['timeout']);
    }

    // =========================================================================
    // releaseLock() Tests
    // =========================================================================

    /**
     * Test releaseLock success when RELEASE_LOCK returns 1
     */
    public function testReleaseLockSuccessReturnsTrue(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => 1],
            ['released' => 1],
        ]);

        $service = $this->createService();
        $service->acquireLock('test_lock');
        $result = $service->releaseLock('test_lock');

        $this->assertTrue($result);
    }

    /**
     * Test releaseLock success with string '1'
     */
    public function testReleaseLockSuccessWithStringOne(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => 1],
            ['released' => '1'],
        ]);

        $service = $this->createService();
        $service->acquireLock('test_lock');
        $result = $service->releaseLock('test_lock');

        $this->assertTrue($result);
    }

    /**
     * Test releaseLock when not held returns false
     */
    public function testReleaseLockNotHeldReturnsFalse(): void
    {
        $service = $this->createService();

        // Try to release a lock we never acquired
        $result = $service->releaseLock('test_lock');

        $this->assertFalse($result);
    }

    /**
     * Test releaseLock when RELEASE_LOCK returns 0 (not held by connection)
     */
    public function testReleaseLockNotHeldByConnectionReturnsFalse(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => 1],
            ['released' => 0],
        ]);

        $service = $this->createService();
        $service->acquireLock('test_lock');
        $result = $service->releaseLock('test_lock');

        $this->assertFalse($result);
    }

    /**
     * Test releaseLock when RELEASE_LOCK returns NULL (lock doesn't exist)
     */
    public function testReleaseLockDoesNotExistReturnsFalse(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => 1],
            ['released' => null],
        ]);

        $service = $this->createService();
        $service->acquireLock('test_lock');
        $result = $service->releaseLock('test_lock');

        $this->assertFalse($result);
    }

    /**
     * Test releaseLock handles PDOException gracefully
     */
    public function testReleaseLockHandlesPdoException(): void
    {
        $callCount = 0;
        $this->mockPdo->method('prepare')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return $this->mockStmt;
                }
                throw new PDOException('Connection lost');
            });
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturn(['acquired' => 1]);

        $service = $this->createService();
        $service->acquireLock('test_lock');
        $result = $service->releaseLock('test_lock');

        $this->assertFalse($result);
    }

    /**
     * Test releaseLock removes lock from held list even on error
     */
    public function testReleaseLockRemovesFromHeldListOnError(): void
    {
        $callCount = 0;
        $this->mockPdo->method('prepare')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return $this->mockStmt;
                }
                throw new PDOException('Connection lost');
            });
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturn(['acquired' => 1]);

        $service = $this->createService();
        $service->acquireLock('test_lock');

        $this->assertTrue($service->holdsLock('test_lock'));

        $service->releaseLock('test_lock');

        $this->assertFalse($service->holdsLock('test_lock'));
    }

    // =========================================================================
    // isLocked() Tests
    // =========================================================================

    /**
     * Test isLocked returns true when lock is in use (IS_FREE_LOCK returns 0)
     */
    public function testIsLockedReturnsTrueWhenInUse(): void
    {
        $this->setupMockQuerySequence([
            ['is_free' => 0],
        ]);

        $service = $this->createService();
        $result = $service->isLocked('test_lock');

        $this->assertTrue($result);
    }

    /**
     * Test isLocked returns true when lock is in use (IS_FREE_LOCK returns '0')
     */
    public function testIsLockedReturnsTrueWhenInUseStringZero(): void
    {
        $this->setupMockQuerySequence([
            ['is_free' => '0'],
        ]);

        $service = $this->createService();
        $result = $service->isLocked('test_lock');

        $this->assertTrue($result);
    }

    /**
     * Test isLocked returns false when lock is free (IS_FREE_LOCK returns 1)
     */
    public function testIsLockedReturnsFalseWhenFree(): void
    {
        $this->setupMockQuerySequence([
            ['is_free' => 1],
        ]);

        $service = $this->createService();
        $result = $service->isLocked('test_lock');

        $this->assertFalse($result);
    }

    /**
     * Test isLocked returns false when lock is free (IS_FREE_LOCK returns '1')
     */
    public function testIsLockedReturnsFalseWhenFreeStringOne(): void
    {
        $this->setupMockQuerySequence([
            ['is_free' => '1'],
        ]);

        $service = $this->createService();
        $result = $service->isLocked('test_lock');

        $this->assertFalse($result);
    }

    /**
     * Test isLocked returns true on error (IS_FREE_LOCK returns NULL) - safe default
     */
    public function testIsLockedReturnsTrueOnError(): void
    {
        $this->setupMockQuerySequence([
            ['is_free' => null],
        ]);

        $service = $this->createService();
        $result = $service->isLocked('test_lock');

        $this->assertTrue($result);
    }

    /**
     * Test isLocked returns true on PDOException - safe default
     */
    public function testIsLockedReturnsTrueOnPdoException(): void
    {
        $this->mockPdo->method('prepare')
            ->willThrowException(new PDOException('Connection lost'));

        $service = $this->createService();
        $result = $service->isLocked('test_lock');

        $this->assertTrue($result);
    }

    // =========================================================================
    // holdsLock() Tests
    // =========================================================================

    /**
     * Test holdsLock returns true for held locks
     */
    public function testHoldsLockReturnsTrueForHeldLock(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => 1],
            ['released' => 1],
        ]);

        $service = $this->createService();
        $service->acquireLock('test_lock');

        $this->assertTrue($service->holdsLock('test_lock'));
    }

    /**
     * Test holdsLock returns false for locks not held
     */
    public function testHoldsLockReturnsFalseForNotHeld(): void
    {
        $service = $this->createService();

        $this->assertFalse($service->holdsLock('test_lock'));
    }

    /**
     * Test holdsLock returns false after lock released
     */
    public function testHoldsLockReturnsFalseAfterRelease(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => 1],
            ['released' => 1],
        ]);

        $service = $this->createService();
        $service->acquireLock('test_lock');

        $this->assertTrue($service->holdsLock('test_lock'));

        $service->releaseLock('test_lock');

        $this->assertFalse($service->holdsLock('test_lock'));
    }

    // =========================================================================
    // releaseAll() Tests
    // =========================================================================

    /**
     * Test releaseAll releases all held locks
     */
    public function testReleaseAllReleasesAllLocks(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => 1],
            ['acquired' => 1],
            ['acquired' => 1],
            ['released' => 1],
            ['released' => 1],
            ['released' => 1],
        ]);

        $service = $this->createService();

        // Acquire multiple locks
        $service->acquireLock('lock1');
        $service->acquireLock('lock2');
        $service->acquireLock('lock3');

        $this->assertCount(3, $service->getHeldLocks());

        // Release all
        $released = $service->releaseAll();

        $this->assertEquals(3, $released);
        $this->assertCount(0, $service->getHeldLocks());
    }

    /**
     * Test releaseAll returns 0 when no locks held
     */
    public function testReleaseAllReturnsZeroWhenNoLocks(): void
    {
        $service = $this->createService();
        $released = $service->releaseAll();

        $this->assertEquals(0, $released);
    }

    /**
     * Test releaseAll continues on individual lock release failure
     */
    public function testReleaseAllContinuesOnFailure(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => 1],
            ['acquired' => 1],
            ['released' => 1],
            ['released' => 0],
        ]);

        $service = $this->createService();
        $service->acquireLock('lock1');
        $service->acquireLock('lock2');

        $released = $service->releaseAll();

        // Only 1 was successfully released according to RELEASE_LOCK
        $this->assertEquals(1, $released);
        // But the internal list is cleared
        $this->assertCount(0, $service->getHeldLocks());
    }

    /**
     * Test releaseAll handles PDOException and continues
     */
    public function testReleaseAllHandlesPdoException(): void
    {
        $callCount = 0;
        $this->mockPdo->method('prepare')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                // First 2 are acquisitions (return statement)
                // Third throws exception, fourth succeeds
                if ($callCount <= 2 || $callCount === 4) {
                    return $this->mockStmt;
                }
                throw new PDOException('Connection error');
            });
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturnCallback(function () use (&$callCount) {
                if ($callCount <= 2) {
                    return ['acquired' => 1];
                }
                return ['released' => 1];
            });

        $service = $this->createService();
        $service->acquireLock('lock1');
        $service->acquireLock('lock2');

        $released = $service->releaseAll();

        // One succeeded, one threw exception
        $this->assertEquals(1, $released);
        $this->assertCount(0, $service->getHeldLocks());
    }

    // =========================================================================
    // getHeldLocks() Tests
    // =========================================================================

    /**
     * Test getHeldLocks returns empty array initially
     */
    public function testGetHeldLocksReturnsEmptyArrayInitially(): void
    {
        $service = $this->createService();

        $this->assertEquals([], $service->getHeldLocks());
    }

    /**
     * Test getHeldLocks returns correct list of held locks
     */
    public function testGetHeldLocksReturnsCorrectList(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => 1],
            ['acquired' => 1],
            ['released' => 1],
            ['released' => 1],
        ]);

        $service = $this->createService();
        $service->acquireLock('lock_alpha');
        $service->acquireLock('lock_beta');

        $heldLocks = $service->getHeldLocks();

        $this->assertCount(2, $heldLocks);
        $this->assertContains('eiou_lock_alpha', $heldLocks);
        $this->assertContains('eiou_lock_beta', $heldLocks);
    }

    /**
     * Test getHeldLocks updates after release
     */
    public function testGetHeldLocksUpdatesAfterRelease(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => 1],
            ['acquired' => 1],
            ['released' => 1],
            ['released' => 1],
        ]);

        $service = $this->createService();
        $service->acquireLock('lock1');
        $service->acquireLock('lock2');

        $this->assertCount(2, $service->getHeldLocks());

        $service->releaseLock('lock1');

        $heldLocks = $service->getHeldLocks();
        $this->assertCount(1, $heldLocks);
        $this->assertContains('eiou_lock2', $heldLocks);
        $this->assertNotContains('eiou_lock1', $heldLocks);
    }

    // =========================================================================
    // Lock Name Sanitization Tests
    // =========================================================================

    /**
     * Test lock name has prefix added
     */
    public function testLockNameHasPrefixAdded(): void
    {
        $capturedParams = [];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                if (isset($params['timeout'])) {
                    $capturedParams = $params;
                }
                return true;
            });
        $this->mockStmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['acquired' => 1],
                ['released' => 1]
            );

        $service = $this->createService();
        $service->acquireLock('mylock');

        $this->assertEquals('eiou_mylock', $capturedParams['name']);
    }

    /**
     * Test double prefix is avoided
     */
    public function testDoublePrefixIsAvoided(): void
    {
        $capturedParams = [];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                if (isset($params['timeout'])) {
                    $capturedParams = $params;
                }
                return true;
            });
        $this->mockStmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['acquired' => 1],
                ['released' => 1]
            );

        $service = $this->createService();
        $service->acquireLock('eiou_already_prefixed');

        $this->assertEquals('eiou_already_prefixed', $capturedParams['name']);
    }

    /**
     * Test special characters are removed from lock name
     */
    public function testSpecialCharsRemovedFromLockName(): void
    {
        $capturedParams = [];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                if (isset($params['timeout'])) {
                    $capturedParams = $params;
                }
                return true;
            });
        $this->mockStmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['acquired' => 1],
                ['released' => 1]
            );

        $service = $this->createService();
        $service->acquireLock('my-lock.with@special#chars!');

        $this->assertEquals('eiou_my_lock_with_special_chars_', $capturedParams['name']);
    }

    /**
     * Test lock name is truncated to 64 characters
     */
    public function testLockNameTruncatedToMaxLength(): void
    {
        $capturedParams = [];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                if (isset($params['timeout'])) {
                    $capturedParams = $params;
                }
                return true;
            });
        $this->mockStmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['acquired' => 1],
                ['released' => 1]
            );

        $service = $this->createService();

        // Create a lock name that exceeds 64 chars when prefixed
        $longName = str_repeat('a', 100);
        $service->acquireLock($longName);

        $this->assertEquals(64, strlen($capturedParams['name']));
        $this->assertStringStartsWith('eiou_', $capturedParams['name']);
    }

    /**
     * Test underscores are preserved in lock name
     */
    public function testUnderscoresPreservedInLockName(): void
    {
        $capturedParams = [];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                if (isset($params['timeout'])) {
                    $capturedParams = $params;
                }
                return true;
            });
        $this->mockStmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['acquired' => 1],
                ['released' => 1]
            );

        $service = $this->createService();
        $service->acquireLock('my_lock_name');

        $this->assertEquals('eiou_my_lock_name', $capturedParams['name']);
    }

    /**
     * Test alphanumeric characters are preserved
     */
    public function testAlphanumericCharsPreserved(): void
    {
        $capturedParams = [];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                if (isset($params['timeout'])) {
                    $capturedParams = $params;
                }
                return true;
            });
        $this->mockStmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['acquired' => 1],
                ['released' => 1]
            );

        $service = $this->createService();
        $service->acquireLock('Lock123ABC');

        $this->assertEquals('eiou_Lock123ABC', $capturedParams['name']);
    }

    /**
     * Test holdsLock works with original name (not sanitized)
     */
    public function testHoldsLockWorksWithOriginalName(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => 1],
            ['released' => 1],
        ]);

        $service = $this->createService();
        $service->acquireLock('my-special-lock!');

        // Should be able to check using original name
        $this->assertTrue($service->holdsLock('my-special-lock!'));
    }

    /**
     * Test releaseLock works with original name (not sanitized)
     */
    public function testReleaseLockWorksWithOriginalName(): void
    {
        $this->setupMockQuerySequence([
            ['acquired' => 1],
            ['released' => 1],
        ]);

        $service = $this->createService();
        $service->acquireLock('my-special-lock!');

        // Should be able to release using original name
        $result = $service->releaseLock('my-special-lock!');
        $this->assertTrue($result);
    }
}
