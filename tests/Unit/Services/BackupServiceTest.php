<?php
/**
 * Unit Tests for BackupService
 *
 * Tests the pure utility methods of BackupService using reflection
 * to access private methods. Does not test filesystem/shell operations.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\BackupService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use PDO;
use ReflectionClass;
use ReflectionMethod;
use DateTime;

#[CoversClass(BackupService::class)]
class BackupServiceTest extends TestCase
{
    private UserContext $userContext;
    private PDO $pdo;
    private ReflectionMethod $formatBytesMethod;
    private ReflectionMethod $getNextScheduledBackupMethod;

    protected function setUp(): void
    {
        // Create mock objects for dependencies
        $this->userContext = $this->createMock(UserContext::class);
        $this->pdo = $this->createMock(PDO::class);

        // Set up reflection for private methods
        $reflection = new ReflectionClass(BackupService::class);

        $this->formatBytesMethod = $reflection->getMethod('formatBytes');
        $this->formatBytesMethod->setAccessible(true);

        $this->getNextScheduledBackupMethod = $reflection->getMethod('getNextScheduledBackup');
        $this->getNextScheduledBackupMethod->setAccessible(true);
    }

    /**
     * Create a BackupService instance with mocked directory creation
     * Uses reflection to bypass the constructor's ensureBackupDirectory call
     */
    private function createBackupServiceWithoutFilesystem(): BackupService
    {
        $reflection = new ReflectionClass(BackupService::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        // Set the required private properties
        $currentUserProp = $reflection->getProperty('currentUser');
        $currentUserProp->setAccessible(true);
        $currentUserProp->setValue($instance, $this->userContext);

        $pdoProp = $reflection->getProperty('pdo');
        $pdoProp->setAccessible(true);
        $pdoProp->setValue($instance, $this->pdo);

        $backupDirProp = $reflection->getProperty('backupDirectory');
        $backupDirProp->setAccessible(true);
        $backupDirProp->setValue($instance, '/tmp/test-backups');

        return $instance;
    }

    // =========================================================================
    // formatBytes() Tests
    // =========================================================================

    /**
     * Test formatBytes with zero bytes
     */
    public function testFormatBytesWithZeroBytes(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        $result = $this->formatBytesMethod->invoke($service, 0);

        $this->assertEquals('0 B', $result);
    }

    /**
     * Test formatBytes with bytes (< 1024)
     */
    public function testFormatBytesWithBytesOnly(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        $result = $this->formatBytesMethod->invoke($service, 512);

        $this->assertEquals('512 B', $result);
    }

    /**
     * Test formatBytes with exactly 1023 bytes (edge case before KB)
     */
    public function testFormatBytesWithMaxBytes(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        $result = $this->formatBytesMethod->invoke($service, 1023);

        $this->assertEquals('1023 B', $result);
    }

    /**
     * Test formatBytes with exactly 1 KB (1024 bytes)
     */
    public function testFormatBytesWithExactlyOneKilobyte(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        $result = $this->formatBytesMethod->invoke($service, 1024);

        $this->assertEquals('1 KB', $result);
    }

    /**
     * Test formatBytes with kilobytes
     */
    public function testFormatBytesWithKilobytes(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        // 5.5 KB = 5632 bytes
        $result = $this->formatBytesMethod->invoke($service, 5632);

        $this->assertEquals('5.5 KB', $result);
    }

    /**
     * Test formatBytes with megabytes
     */
    public function testFormatBytesWithMegabytes(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        // 2.5 MB = 2621440 bytes
        $result = $this->formatBytesMethod->invoke($service, 2621440);

        $this->assertEquals('2.5 MB', $result);
    }

    /**
     * Test formatBytes with exactly 1 MB
     */
    public function testFormatBytesWithExactlyOneMegabyte(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        // 1 MB = 1048576 bytes
        $result = $this->formatBytesMethod->invoke($service, 1048576);

        $this->assertEquals('1 MB', $result);
    }

    /**
     * Test formatBytes with gigabytes
     */
    public function testFormatBytesWithGigabytes(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        // 1.5 GB = 1610612736 bytes
        $result = $this->formatBytesMethod->invoke($service, 1610612736);

        $this->assertEquals('1.5 GB', $result);
    }

    /**
     * Test formatBytes with exactly 1 GB
     */
    public function testFormatBytesWithExactlyOneGigabyte(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        // 1 GB = 1073741824 bytes
        $result = $this->formatBytesMethod->invoke($service, 1073741824);

        $this->assertEquals('1 GB', $result);
    }

    /**
     * Test formatBytes with large values (multiple GB)
     */
    public function testFormatBytesWithLargeValues(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        // 10 GB = 10737418240 bytes
        $result = $this->formatBytesMethod->invoke($service, 10737418240);

        $this->assertEquals('10 GB', $result);
    }

    /**
     * Test formatBytes with very large value (stays in GB)
     */
    public function testFormatBytesWithVeryLargeValue(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        // 100 GB = 107374182400 bytes - should stay in GB since no TB unit
        $result = $this->formatBytesMethod->invoke($service, 107374182400);

        $this->assertEquals('100 GB', $result);
    }

    /**
     * Test formatBytes rounds to 2 decimal places
     */
    public function testFormatBytesRoundsToTwoDecimals(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        // 1536 bytes = 1.5 KB exactly
        $result = $this->formatBytesMethod->invoke($service, 1536);
        $this->assertEquals('1.5 KB', $result);

        // 1638 bytes = 1.599609... KB, should round to 1.6
        $result = $this->formatBytesMethod->invoke($service, 1638);
        $this->assertEquals('1.6 KB', $result);
    }

    /**
     * Test formatBytes with 1 byte
     */
    public function testFormatBytesWithOneByte(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        $result = $this->formatBytesMethod->invoke($service, 1);

        $this->assertEquals('1 B', $result);
    }

    // =========================================================================
    // getNextScheduledBackup() Tests
    // =========================================================================

    /**
     * Test getNextScheduledBackup returns ISO 8601 format
     */
    public function testGetNextScheduledBackupReturnsIsoFormat(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        $result = $this->getNextScheduledBackupMethod->invoke($service);

        // Verify it's a valid ISO 8601 date format
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $result
        );
    }

    /**
     * Test getNextScheduledBackup returns tomorrow if current time is past scheduled time
     */
    public function testGetNextScheduledBackupReturnsTomorrowIfPastScheduledTime(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        // Get the scheduled hour/minute from Constants
        $hour = Constants::BACKUP_CRON_HOUR;
        $minute = Constants::BACKUP_CRON_MINUTE;

        // Create a DateTime for today at the scheduled time
        $scheduledToday = new DateTime();
        $scheduledToday->setTime($hour, $minute, 0);

        // If current time is past the scheduled time, result should be tomorrow
        $now = new DateTime();
        $result = $this->getNextScheduledBackupMethod->invoke($service);
        $resultDate = new DateTime($result);

        if ($now > $scheduledToday) {
            // Should be tomorrow
            $expectedDate = clone $scheduledToday;
            $expectedDate->modify('+1 day');

            $this->assertEquals(
                $expectedDate->format('Y-m-d'),
                $resultDate->format('Y-m-d'),
                'When current time is past scheduled time, next backup should be tomorrow'
            );
        } else {
            // Should be today
            $this->assertEquals(
                $scheduledToday->format('Y-m-d'),
                $resultDate->format('Y-m-d'),
                'When current time is before scheduled time, next backup should be today'
            );
        }
    }

    /**
     * Test getNextScheduledBackup uses correct hour and minute from Constants
     */
    public function testGetNextScheduledBackupUsesConstantsHourAndMinute(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        $result = $this->getNextScheduledBackupMethod->invoke($service);
        $resultDate = new DateTime($result);

        $expectedHour = Constants::BACKUP_CRON_HOUR;
        $expectedMinute = Constants::BACKUP_CRON_MINUTE;

        $this->assertEquals(
            $expectedHour,
            (int) $resultDate->format('H'),
            'Scheduled backup should use BACKUP_CRON_HOUR from Constants'
        );

        $this->assertEquals(
            $expectedMinute,
            (int) $resultDate->format('i'),
            'Scheduled backup should use BACKUP_CRON_MINUTE from Constants'
        );
    }

    /**
     * Test getNextScheduledBackup sets seconds to zero
     */
    public function testGetNextScheduledBackupSetsSecondsToZero(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        $result = $this->getNextScheduledBackupMethod->invoke($service);
        $resultDate = new DateTime($result);

        $this->assertEquals(
            0,
            (int) $resultDate->format('s'),
            'Scheduled backup seconds should be zero'
        );
    }

    /**
     * Test getNextScheduledBackup result is always in the future
     */
    public function testGetNextScheduledBackupIsAlwaysInFuture(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        $result = $this->getNextScheduledBackupMethod->invoke($service);
        $resultDate = new DateTime($result);
        $now = new DateTime();

        $this->assertGreaterThan(
            $now,
            $resultDate,
            'Next scheduled backup should always be in the future'
        );
    }

    /**
     * Test getNextScheduledBackup result is within 24 hours
     */
    public function testGetNextScheduledBackupIsWithin24Hours(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        $result = $this->getNextScheduledBackupMethod->invoke($service);
        $resultDate = new DateTime($result);
        $now = new DateTime();

        $diffSeconds = $resultDate->getTimestamp() - $now->getTimestamp();

        // Should be within 24 hours (86400 seconds)
        $this->assertLessThanOrEqual(
            86400,
            $diffSeconds,
            'Next scheduled backup should be within 24 hours'
        );

        $this->assertGreaterThan(
            0,
            $diffSeconds,
            'Next scheduled backup should be in the future'
        );
    }

    /**
     * Test getNextScheduledBackup date calculation is correct for midnight (default)
     */
    public function testGetNextScheduledBackupForMidnightSchedule(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        // Since Constants::BACKUP_CRON_HOUR = 0 and BACKUP_CRON_MINUTE = 0 (midnight)
        $result = $this->getNextScheduledBackupMethod->invoke($service);
        $resultDate = new DateTime($result);

        // Verify time is midnight
        $this->assertEquals('00:00:00', $resultDate->format('H:i:s'));
    }

    // =========================================================================
    // Integration-style Tests for Utility Logic
    // =========================================================================

    /**
     * Test that formatBytes handles typical backup sizes correctly
     */
    public function testFormatBytesWithTypicalBackupSizes(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        // Small backup: 50 KB
        $result = $this->formatBytesMethod->invoke($service, 51200);
        $this->assertEquals('50 KB', $result);

        // Medium backup: 5 MB
        $result = $this->formatBytesMethod->invoke($service, 5242880);
        $this->assertEquals('5 MB', $result);

        // Large backup: 500 MB
        $result = $this->formatBytesMethod->invoke($service, 524288000);
        $this->assertEquals('500 MB', $result);
    }

    /**
     * Test formatBytes boundary conditions at unit transitions
     */
    public function testFormatBytesBoundaryConditions(): void
    {
        $service = $this->createBackupServiceWithoutFilesystem();

        // Just under 1 KB (1023 bytes)
        $result = $this->formatBytesMethod->invoke($service, 1023);
        $this->assertEquals('1023 B', $result);

        // Exactly 1 KB (1024 bytes)
        $result = $this->formatBytesMethod->invoke($service, 1024);
        $this->assertEquals('1 KB', $result);

        // Just under 1 MB (1048575 bytes)
        $result = $this->formatBytesMethod->invoke($service, 1048575);
        $this->assertEquals('1024 KB', $result);

        // Exactly 1 MB (1048576 bytes)
        $result = $this->formatBytesMethod->invoke($service, 1048576);
        $this->assertEquals('1 MB', $result);

        // Just under 1 GB (1073741823 bytes)
        $result = $this->formatBytesMethod->invoke($service, 1073741823);
        $this->assertEquals('1024 MB', $result);

        // Exactly 1 GB (1073741824 bytes)
        $result = $this->formatBytesMethod->invoke($service, 1073741824);
        $this->assertEquals('1 GB', $result);
    }
}
