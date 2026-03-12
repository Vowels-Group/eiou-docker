<?php
/**
 * Unit Tests for RateLimiterRepository
 *
 * Tests rate limiter repository database operations with mocked PDO.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\RateLimiterRepository;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(RateLimiterRepository::class)]
class RateLimiterRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private RateLimiterRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new RateLimiterRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor accepts PDO dependency injection
     */
    public function testConstructorAcceptsPdoDependencyInjection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repository = new RateLimiterRepository($pdo);

        $this->assertInstanceOf(RateLimiterRepository::class, $repository);
    }

    // =========================================================================
    // getBlockedRecord() Tests
    // =========================================================================

    /**
     * Test getBlockedRecord returns blocked record when found
     */
    public function testGetBlockedRecordReturnsRecordWhenBlocked(): void
    {
        $expectedRecord = [
            'id' => 1,
            'identifier' => '192.168.1.1',
            'action' => 'login',
            'attempts' => 5,
            'blocked_until' => '2025-12-31 23:59:59'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('blocked_until IS NOT NULL AND blocked_until > NOW()'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with(['192.168.1.1', 'login'])
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn($expectedRecord);

        $result = $this->repository->getBlockedRecord('192.168.1.1', 'login');

        $this->assertEquals($expectedRecord, $result);
    }

    /**
     * Test getBlockedRecord returns false when not blocked
     */
    public function testGetBlockedRecordReturnsFalseWhenNotBlocked(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with(['192.168.1.1', 'login'])
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = $this->repository->getBlockedRecord('192.168.1.1', 'login');

        $this->assertFalse($result);
    }

    /**
     * Test getBlockedRecord with different identifiers
     */
    public function testGetBlockedRecordWithDifferentIdentifiers(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with(['user_id_12345', 'password_reset'])
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = $this->repository->getBlockedRecord('user_id_12345', 'password_reset');

        $this->assertFalse($result);
    }

    // =========================================================================
    // getAttemptsInWindow() Tests
    // =========================================================================

    /**
     * Test getAttemptsInWindow returns record with attempts
     */
    public function testGetAttemptsInWindowReturnsRecordWithAttempts(): void
    {
        $expectedRecord = [
            'id' => 1,
            'identifier' => '192.168.1.1',
            'action' => 'login',
            'attempts' => 3,
            'first_attempt' => '2025-01-01 12:00:00',
            'last_attempt' => '2025-01-01 12:05:00'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('last_attempt > DATE_SUB(NOW(), INTERVAL ? SECOND)'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with(['192.168.1.1', 'login', 300])
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn($expectedRecord);

        $result = $this->repository->getAttemptsInWindow('192.168.1.1', 'login', 300);

        $this->assertEquals($expectedRecord, $result);
    }

    /**
     * Test getAttemptsInWindow returns false when no attempts in window
     */
    public function testGetAttemptsInWindowReturnsFalseWhenNoAttempts(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with(['192.168.1.1', 'login', 60])
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = $this->repository->getAttemptsInWindow('192.168.1.1', 'login', 60);

        $this->assertFalse($result);
    }

    /**
     * Test getAttemptsInWindow with various window sizes
     */
    public function testGetAttemptsInWindowWithVariousWindowSizes(): void
    {
        $windowSizes = [30, 60, 300, 3600, 86400];

        foreach ($windowSizes as $windowSize) {
            $pdo = $this->createMock(PDO::class);
            $stmt = $this->createMock(PDOStatement::class);
            $repository = new RateLimiterRepository($pdo);

            $pdo->expects($this->once())
                ->method('prepare')
                ->willReturn($stmt);

            $stmt->expects($this->once())
                ->method('execute')
                ->with(['test_id', 'test_action', $windowSize])
                ->willReturn(true);

            $stmt->expects($this->once())
                ->method('fetch')
                ->willReturn(false);

            $result = $repository->getAttemptsInWindow('test_id', 'test_action', $windowSize);

            $this->assertFalse($result);
        }
    }

    // =========================================================================
    // insertFirstAttempt() Tests
    // =========================================================================

    /**
     * Test insertFirstAttempt inserts record successfully
     */
    public function testInsertFirstAttemptInsertsSuccessfully(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO rate_limits'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with(['192.168.1.1', 'login'])
            ->willReturn(true);

        $result = $this->repository->insertFirstAttempt('192.168.1.1', 'login');

        $this->assertTrue($result);
    }

    /**
     * Test insertFirstAttempt uses ON DUPLICATE KEY UPDATE
     */
    public function testInsertFirstAttemptUsesOnDuplicateKeyUpdate(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('ON DUPLICATE KEY UPDATE'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->insertFirstAttempt('user_123', 'api_request');

        $this->assertTrue($result);
    }

    /**
     * Test insertFirstAttempt returns false on failure
     */
    public function testInsertFirstAttemptReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(false);

        $result = $this->repository->insertFirstAttempt('192.168.1.1', 'login');

        $this->assertFalse($result);
    }

    // =========================================================================
    // updateAttempts() Tests
    // =========================================================================

    /**
     * Test updateAttempts updates count successfully
     */
    public function testUpdateAttemptsUpdatesSuccessfully(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SET attempts = ?, last_attempt = NOW()'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([5, '192.168.1.1', 'login'])
            ->willReturn(true);

        $result = $this->repository->updateAttempts('192.168.1.1', 'login', 5);

        $this->assertTrue($result);
    }

    /**
     * Test updateAttempts with various attempt counts
     */
    public function testUpdateAttemptsWithVariousCounts(): void
    {
        $attemptCounts = [1, 2, 5, 10, 100];

        foreach ($attemptCounts as $count) {
            $pdo = $this->createMock(PDO::class);
            $stmt = $this->createMock(PDOStatement::class);
            $repository = new RateLimiterRepository($pdo);

            $pdo->expects($this->once())
                ->method('prepare')
                ->willReturn($stmt);

            $stmt->expects($this->once())
                ->method('execute')
                ->with([$count, 'test_id', 'test_action'])
                ->willReturn(true);

            $result = $repository->updateAttempts('test_id', 'test_action', $count);

            $this->assertTrue($result);
        }
    }

    /**
     * Test updateAttempts returns false on failure
     */
    public function testUpdateAttemptsReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(false);

        $result = $this->repository->updateAttempts('192.168.1.1', 'login', 3);

        $this->assertFalse($result);
    }

    // =========================================================================
    // blockIdentifier() Tests
    // =========================================================================

    /**
     * Test blockIdentifier blocks identifier successfully
     */
    public function testBlockIdentifierBlocksSuccessfully(): void
    {
        $blockedUntil = '2025-12-31 23:59:59';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('blocked_until = ?'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([5, $blockedUntil, '192.168.1.1', 'login'])
            ->willReturn(true);

        $result = $this->repository->blockIdentifier('192.168.1.1', 'login', 5, $blockedUntil);

        $this->assertTrue($result);
    }

    /**
     * Test blockIdentifier with different block durations
     */
    public function testBlockIdentifierWithDifferentDurations(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([10, '2025-06-15 14:30:00', 'user_456', 'password_change'])
            ->willReturn(true);

        $result = $this->repository->blockIdentifier('user_456', 'password_change', 10, '2025-06-15 14:30:00');

        $this->assertTrue($result);
    }

    /**
     * Test blockIdentifier returns false on failure
     */
    public function testBlockIdentifierReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(false);

        $result = $this->repository->blockIdentifier('192.168.1.1', 'login', 5, '2025-12-31 23:59:59');

        $this->assertFalse($result);
    }

    // =========================================================================
    // reset() Tests
    // =========================================================================

    /**
     * Test reset deletes rate limit record
     */
    public function testResetDeletesRecord(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DELETE FROM rate_limits'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with(['192.168.1.1', 'login'])
            ->willReturn(true);

        $result = $this->repository->reset('192.168.1.1', 'login');

        $this->assertTrue($result);
    }

    /**
     * Test reset returns false on failure
     */
    public function testResetReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(false);

        $result = $this->repository->reset('192.168.1.1', 'login');

        $this->assertFalse($result);
    }

    /**
     * Test reset with various identifiers and actions
     */
    public function testResetWithVariousIdentifiersAndActions(): void
    {
        $testCases = [
            ['192.168.1.1', 'login'],
            ['user_12345', 'api_request'],
            ['email@example.com', 'password_reset'],
            ['session_abc123', 'form_submit']
        ];

        foreach ($testCases as [$identifier, $action]) {
            $pdo = $this->createMock(PDO::class);
            $stmt = $this->createMock(PDOStatement::class);
            $repository = new RateLimiterRepository($pdo);

            $pdo->expects($this->once())
                ->method('prepare')
                ->willReturn($stmt);

            $stmt->expects($this->once())
                ->method('execute')
                ->with([$identifier, $action])
                ->willReturn(true);

            $result = $repository->reset($identifier, $action);

            $this->assertTrue($result);
        }
    }

    // =========================================================================
    // cleanup() Tests
    // =========================================================================

    /**
     * Test cleanup removes old records
     */
    public function testCleanupRemovesOldRecords(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('last_attempt < DATE_SUB(NOW(), INTERVAL ? SECOND)'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([3600])
            ->willReturn(true);

        $result = $this->repository->cleanup(3600);

        $this->assertTrue($result);
    }

    /**
     * Test cleanup with default parameter
     */
    public function testCleanupWithDefaultParameter(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([3600])
            ->willReturn(true);

        $result = $this->repository->cleanup();

        $this->assertTrue($result);
    }

    /**
     * Test cleanup only removes records where blocked_until has passed
     */
    public function testCleanupOnlyRemovesExpiredBlocks(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('(blocked_until IS NULL OR blocked_until < NOW())'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->cleanup(7200);

        $this->assertTrue($result);
    }

    /**
     * Test cleanup returns false on PDOException
     */
    public function testCleanupReturnsFalseOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Cleanup failed'));

        $result = $this->repository->cleanup();

        $this->assertFalse($result);
    }

    /**
     * Test cleanup with various time windows
     */
    public function testCleanupWithVariousTimeWindows(): void
    {
        $timeWindows = [60, 300, 3600, 86400, 604800];

        foreach ($timeWindows as $window) {
            $pdo = $this->createMock(PDO::class);
            $stmt = $this->createMock(PDOStatement::class);
            $repository = new RateLimiterRepository($pdo);

            $pdo->expects($this->once())
                ->method('prepare')
                ->willReturn($stmt);

            $stmt->expects($this->once())
                ->method('execute')
                ->with([$window])
                ->willReturn(true);

            $result = $repository->cleanup($window);

            $this->assertTrue($result);
        }
    }

    // =========================================================================
    // Edge Cases Tests
    // =========================================================================

    /**
     * Test with empty identifier
     */
    public function testWithEmptyIdentifier(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with(['', 'login'])
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = $this->repository->getBlockedRecord('', 'login');

        $this->assertFalse($result);
    }

    /**
     * Test with special characters in identifier
     */
    public function testWithSpecialCharactersInIdentifier(): void
    {
        $specialIdentifier = "user'name\"test<script>";

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([$specialIdentifier, 'login'])
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = $this->repository->getBlockedRecord($specialIdentifier, 'login');

        $this->assertFalse($result);
    }

    /**
     * Test with IPv6 address as identifier
     */
    public function testWithIpv6AddressIdentifier(): void
    {
        $ipv6 = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([$ipv6, 'api_request'])
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = $this->repository->getBlockedRecord($ipv6, 'api_request');

        $this->assertFalse($result);
    }

    /**
     * Test with zero attempts
     */
    public function testUpdateAttemptsWithZero(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([0, 'test_id', 'test_action'])
            ->willReturn(true);

        $result = $this->repository->updateAttempts('test_id', 'test_action', 0);

        $this->assertTrue($result);
    }

    /**
     * Test with very long identifier
     */
    public function testWithVeryLongIdentifier(): void
    {
        $longIdentifier = str_repeat('a', 255);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([$longIdentifier, 'test'])
            ->willReturn(true);

        $result = $this->repository->insertFirstAttempt($longIdentifier, 'test');

        $this->assertTrue($result);
    }

    // =========================================================================
    // Action Types Tests
    // =========================================================================

    /**
     * Test common action types
     */
    public function testCommonActionTypes(): void
    {
        $commonActions = [
            'login',
            'api_request',
            'password_reset',
            'form_submit',
            'file_upload',
            'transaction',
            'contact_request'
        ];

        foreach ($commonActions as $action) {
            $pdo = $this->createMock(PDO::class);
            $stmt = $this->createMock(PDOStatement::class);
            $repository = new RateLimiterRepository($pdo);

            $pdo->expects($this->once())
                ->method('prepare')
                ->willReturn($stmt);

            $stmt->expects($this->once())
                ->method('execute')
                ->willReturn(true);

            $result = $repository->insertFirstAttempt('test_identifier', $action);

            $this->assertTrue($result, "Failed for action: {$action}");
        }
    }
}
