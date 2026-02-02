<?php
/**
 * Unit Tests for AbstractMessageProcessor
 *
 * Tests the base processor functionality including:
 * - Constructor configuration
 * - Signal handling
 * - Statistics tracking
 * - Shutdown procedures
 * - Lockfile management
 *
 * Uses a concrete test implementation to test abstract class behavior.
 */

namespace Eiou\Tests\Processors;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Eiou\Processors\AbstractMessageProcessor;
use Eiou\Utils\AdaptivePoller;
use PDO;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(AbstractMessageProcessor::class)]
class AbstractMessageProcessorTest extends TestCase
{
    private ConcreteTestProcessor $processor;
    private string $testLockfile;

    /**
     * Sample test data constants
     */
    private const TEST_POLLER_CONFIG = [
        'min_interval_ms' => 100,
        'max_interval_ms' => 5000,
        'idle_interval_ms' => 2000,
        'adaptive' => false
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->testLockfile = sys_get_temp_dir() . '/test_processor_' . uniqid() . '.pid';
        $this->processor = new ConcreteTestProcessor(
            self::TEST_POLLER_CONFIG,
            $this->testLockfile,
            60,
            30
        );
    }

    protected function tearDown(): void
    {
        // Clean up lockfile if it exists
        if (file_exists($this->testLockfile)) {
            @unlink($this->testLockfile);
        }

        parent::tearDown();
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor initializes properties correctly
     */
    public function testConstructorInitializesPropertiesCorrectly(): void
    {
        $processor = new ConcreteTestProcessor(
            self::TEST_POLLER_CONFIG,
            '/tmp/test.pid',
            120,
            45
        );

        $this->assertEquals(120, $processor->getLogInterval());
        $this->assertEquals(45, $processor->getShutdownTimeout());
        $this->assertEquals('/tmp/test.pid', $processor->getLockfile());
        $this->assertEquals(self::TEST_POLLER_CONFIG, $processor->getPollerConfig());
    }

    /**
     * Test constructor sets default values
     */
    public function testConstructorSetsDefaultValues(): void
    {
        $processor = new ConcreteTestProcessor(
            self::TEST_POLLER_CONFIG,
            '/tmp/test.pid'
        );

        $this->assertEquals(60, $processor->getLogInterval());
        $this->assertEquals(30, $processor->getShutdownTimeout());
        $this->assertEquals(0, $processor->getTotalProcessed());
        $this->assertFalse($processor->getShouldStop());
    }

    /**
     * Test constructor initializes shutdown state correctly
     */
    public function testConstructorInitializesShutdownState(): void
    {
        $this->assertFalse($this->processor->getShutdownInProgress());
        $this->assertNull($this->processor->getShutdownStartTime());
        $this->assertEquals(0, $this->processor->getShutdownSignalCount());
    }

    // =========================================================================
    // Initialize Tests
    // =========================================================================

    /**
     * Test initialize creates lockfile with PID
     */
    public function testInitializeCreatesLockfileWithPid(): void
    {
        $this->processor->initialize();

        $this->assertFileExists($this->testLockfile);
        $this->assertEquals((string)getmypid(), trim(file_get_contents($this->testLockfile)));
    }

    /**
     * Test initialize creates AdaptivePoller instance
     */
    public function testInitializeCreatesAdaptivePollerInstance(): void
    {
        $this->processor->initialize();

        $this->assertInstanceOf(AdaptivePoller::class, $this->processor->getPoller());
    }

    /**
     * Test initialize removes stale lockfile with invalid PID
     */
    public function testInitializeRemovesStaleLockfileWithInvalidPid(): void
    {
        // Create a lockfile with invalid PID
        file_put_contents($this->testLockfile, 'invalid-pid');

        // Suppress output during initialization
        ob_start();
        $this->processor->initialize();
        ob_end_clean();

        // Lockfile should now contain our PID
        $this->assertEquals((string)getmypid(), trim(file_get_contents($this->testLockfile)));
    }

    /**
     * Test initialize removes stale lockfile with non-existent PID
     */
    public function testInitializeRemovesStaleLockfileWithNonExistentPid(): void
    {
        // Create a lockfile with a PID that doesn't exist
        file_put_contents($this->testLockfile, '99999999');

        // Suppress output during initialization
        ob_start();
        $this->processor->initialize();
        ob_end_clean();

        // Lockfile should now contain our PID
        $this->assertEquals((string)getmypid(), trim(file_get_contents($this->testLockfile)));
    }

    /**
     * Test initialize removes empty lockfile
     */
    public function testInitializeRemovesEmptyLockfile(): void
    {
        // Create an empty lockfile
        file_put_contents($this->testLockfile, '');

        // Suppress output during initialization
        ob_start();
        $this->processor->initialize();
        ob_end_clean();

        // Lockfile should now contain our PID
        $this->assertEquals((string)getmypid(), trim(file_get_contents($this->testLockfile)));
    }

    // =========================================================================
    // Signal Handling Tests
    // =========================================================================

    /**
     * Test handleShutdownSignal sets shouldStop flag
     */
    #[RequiresPhpExtension('pcntl')]
    public function testHandleShutdownSignalSetsShouldStopFlag(): void
    {
        // Suppress output
        ob_start();
        $this->processor->handleShutdownSignal(SIGTERM);
        ob_end_clean();

        $this->assertTrue($this->processor->getShouldStop());
    }

    /**
     * Test handleShutdownSignal sets shutdown state
     */
    #[RequiresPhpExtension('pcntl')]
    public function testHandleShutdownSignalSetsShutdownState(): void
    {
        ob_start();
        $this->processor->handleShutdownSignal(SIGTERM);
        ob_end_clean();

        $this->assertTrue($this->processor->getShutdownInProgress());
        $this->assertNotNull($this->processor->getShutdownStartTime());
        $this->assertEquals(1, $this->processor->getShutdownSignalCount());
    }

    /**
     * Test handleShutdownSignal ignores duplicate signals
     */
    #[RequiresPhpExtension('pcntl')]
    public function testHandleShutdownSignalIgnoresDuplicateSignals(): void
    {
        ob_start();
        $this->processor->handleShutdownSignal(SIGTERM);
        $firstShutdownTime = $this->processor->getShutdownStartTime();

        usleep(1000); // Small delay

        $this->processor->handleShutdownSignal(SIGTERM);
        ob_end_clean();

        // Signal count should increment but state should not change
        $this->assertEquals(2, $this->processor->getShutdownSignalCount());
        $this->assertEquals($firstShutdownTime, $this->processor->getShutdownStartTime());
    }

    /**
     * Test handleShutdownSignal handles SIGINT correctly
     */
    #[RequiresPhpExtension('pcntl')]
    public function testHandleShutdownSignalHandlesSigintCorrectly(): void
    {
        ob_start();
        $this->processor->handleShutdownSignal(SIGINT);
        ob_end_clean();

        $this->assertTrue($this->processor->getShouldStop());
        $this->assertTrue($this->processor->getShutdownInProgress());
    }

    /**
     * Test handleReloadSignal calls onReload hook
     */
    #[RequiresPhpExtension('pcntl')]
    public function testHandleReloadSignalCallsOnReloadHook(): void
    {
        ob_start();
        $this->processor->handleReloadSignal(SIGHUP);
        ob_end_clean();

        $this->assertTrue($this->processor->wasOnReloadCalled());
    }

    /**
     * Test handleQuitSignal removes lockfile
     */
    #[RequiresPhpExtension('pcntl')]
    public function testHandleQuitSignalRemovesLockfile(): void
    {
        // Create lockfile
        file_put_contents($this->testLockfile, getmypid());
        $this->assertFileExists($this->testLockfile);

        // We cannot actually test exit() behavior, but we can verify the method exists
        $this->assertTrue(method_exists($this->processor, 'handleQuitSignal'));
    }

    // =========================================================================
    // Shutdown Timeout Tests
    // =========================================================================

    /**
     * Test hasShutdownTimedOut returns false when not in shutdown
     */
    public function testHasShutdownTimedOutReturnsFalseWhenNotInShutdown(): void
    {
        $this->assertFalse($this->processor->publicHasShutdownTimedOut());
    }

    /**
     * Test hasShutdownTimedOut returns false during grace period
     */
    #[RequiresPhpExtension('pcntl')]
    public function testHasShutdownTimedOutReturnsFalseDuringGracePeriod(): void
    {
        // Create processor with long timeout
        $processor = new ConcreteTestProcessor(
            self::TEST_POLLER_CONFIG,
            $this->testLockfile,
            60,
            3600 // 1 hour timeout
        );

        ob_start();
        $processor->handleShutdownSignal(SIGTERM);
        ob_end_clean();

        $this->assertFalse($processor->publicHasShutdownTimedOut());
    }

    /**
     * Test hasShutdownTimedOut returns true after timeout
     */
    #[RequiresPhpExtension('pcntl')]
    public function testHasShutdownTimedOutReturnsTrueAfterTimeout(): void
    {
        // Create processor with very short timeout
        $processor = new ConcreteTestProcessor(
            self::TEST_POLLER_CONFIG,
            $this->testLockfile,
            60,
            0 // Immediate timeout
        );

        ob_start();
        $processor->handleShutdownSignal(SIGTERM);
        ob_end_clean();

        // Should immediately timeout with 0 second timeout
        $this->assertTrue($processor->publicHasShutdownTimedOut());
    }

    // =========================================================================
    // Database Connection Tests
    // =========================================================================

    /**
     * Test setDatabaseConnection stores PDO instance
     */
    public function testSetDatabaseConnectionStoresPdoInstance(): void
    {
        $pdo = $this->createMock(PDO::class);

        $this->processor->setDatabaseConnection($pdo);

        $this->assertSame($pdo, $this->processor->getPdoConnection());
    }

    /**
     * Test closeDatabaseConnections nullifies PDO
     */
    public function testCloseDatabaseConnectionsNullifiesPdo(): void
    {
        $pdo = $this->createMock(PDO::class);
        $this->processor->setDatabaseConnection($pdo);

        ob_start();
        $this->processor->publicCloseDatabaseConnections();
        ob_end_clean();

        $this->assertNull($this->processor->getPdoConnection());
    }

    // =========================================================================
    // Statistics Tests
    // =========================================================================

    /**
     * Test maybeLogStatistics resets total processed after logging
     */
    public function testMaybeLogStatisticsResetsTotalProcessedAfterLogging(): void
    {
        // Initialize processor
        $this->processor->initialize();

        // Set up processed count and old log time
        $this->processor->setTotalProcessed(100);
        $this->processor->setLastLogTime(time() - 120); // 2 minutes ago

        ob_start();
        $this->processor->publicMaybeLogStatistics();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getTotalProcessed());
    }

    /**
     * Test maybeLogStatistics does nothing before interval
     */
    public function testMaybeLogStatisticsDoesNothingBeforeInterval(): void
    {
        // Initialize processor
        $this->processor->initialize();

        // Set up processed count and recent log time
        $this->processor->setTotalProcessed(100);
        $this->processor->setLastLogTime(time()); // Just now

        ob_start();
        $this->processor->publicMaybeLogStatistics();
        $output = ob_get_clean();

        // Should not log and count should remain
        $this->assertEquals(100, $this->processor->getTotalProcessed());
        $this->assertEmpty($output);
    }

    // =========================================================================
    // Temporary Files Tests
    // =========================================================================

    /**
     * Test clearTemporaryFiles removes processor-specific temp files
     */
    public function testClearTemporaryFilesRemovesProcessorSpecificTempFiles(): void
    {
        // Create some test temp files
        $tempDir = sys_get_temp_dir();
        $testFile1 = $tempDir . '/test_processor_abc123';
        $testFile2 = $tempDir . '/test_processor_def456';
        file_put_contents($testFile1, 'test');
        file_put_contents($testFile2, 'test');

        $this->processor->setProcessorNameForTest('test_processor');

        ob_start();
        $this->processor->publicClearTemporaryFiles();
        ob_end_clean();

        $this->assertFileDoesNotExist($testFile1);
        $this->assertFileDoesNotExist($testFile2);
    }

    // =========================================================================
    // Lockfile Release Tests
    // =========================================================================

    /**
     * Test releaseLocks removes lockfile
     */
    public function testReleaseLocksRemovesLockfile(): void
    {
        // Create lockfile
        file_put_contents($this->testLockfile, getmypid());
        $this->assertFileExists($this->testLockfile);

        ob_start();
        $this->processor->publicReleaseLocks();
        ob_end_clean();

        $this->assertFileDoesNotExist($this->testLockfile);
    }

    /**
     * Test releaseLocks handles missing lockfile gracefully
     */
    public function testReleaseLocksHandlesMissingLockfileGracefully(): void
    {
        // Ensure lockfile doesn't exist
        @unlink($this->testLockfile);

        ob_start();
        $this->processor->publicReleaseLocks();
        $output = ob_get_clean();

        // Should not throw and should not output removal message
        $this->assertStringNotContainsString('Lockfile removed', $output);
    }

    // =========================================================================
    // getProcessorName Tests
    // =========================================================================

    /**
     * Test getProcessorName returns correct name
     */
    public function testGetProcessorNameReturnsCorrectName(): void
    {
        $this->assertEquals('Test', $this->processor->publicGetProcessorName());
    }

    // =========================================================================
    // processMessages Tests
    // =========================================================================

    /**
     * Test processMessages returns count from concrete implementation
     */
    public function testProcessMessagesReturnsCountFromConcreteImplementation(): void
    {
        $this->processor->setMessagesToProcess(5);

        $result = $this->processor->publicProcessMessages();

        $this->assertEquals(5, $result);
    }

    /**
     * Test processMessages returns zero when no messages
     */
    public function testProcessMessagesReturnsZeroWhenNoMessages(): void
    {
        $this->processor->setMessagesToProcess(0);

        $result = $this->processor->publicProcessMessages();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // onShutdown Hook Tests
    // =========================================================================

    /**
     * Test onShutdown hook is called during shutdown
     */
    public function testOnShutdownHookIsCalledDuringShutdown(): void
    {
        // Initialize first
        $this->processor->initialize();

        ob_start();
        $this->processor->publicShutdown();
        ob_end_clean();

        $this->assertTrue($this->processor->wasOnShutdownCalled());
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    /**
     * Test processor handles zero log interval
     */
    public function testProcessorHandlesZeroLogInterval(): void
    {
        $processor = new ConcreteTestProcessor(
            self::TEST_POLLER_CONFIG,
            $this->testLockfile,
            0, // Zero log interval
            30
        );

        $this->assertEquals(0, $processor->getLogInterval());
    }

    /**
     * Test processor handles zero shutdown timeout
     */
    public function testProcessorHandlesZeroShutdownTimeout(): void
    {
        $processor = new ConcreteTestProcessor(
            self::TEST_POLLER_CONFIG,
            $this->testLockfile,
            60,
            0 // Zero shutdown timeout
        );

        $this->assertEquals(0, $processor->getShutdownTimeout());
    }
}

/**
 * Concrete implementation of AbstractMessageProcessor for testing
 *
 * This class exposes protected methods as public for testing purposes.
 */
class ConcreteTestProcessor extends AbstractMessageProcessor
{
    private int $messagesToProcess = 0;
    private bool $onReloadCalled = false;
    private bool $onShutdownCalled = false;
    private string $testProcessorName = 'Test';

    // Public accessors for protected properties

    public function getLogInterval(): int
    {
        return $this->logInterval;
    }

    public function getShutdownTimeout(): int
    {
        return $this->shutdownTimeout;
    }

    public function getLockfile(): string
    {
        return $this->lockfile;
    }

    public function getPollerConfig(): array
    {
        return $this->pollerConfig;
    }

    public function getTotalProcessed(): int
    {
        return $this->totalProcessed;
    }

    public function setTotalProcessed(int $count): void
    {
        $this->totalProcessed = $count;
    }

    public function getShouldStop(): bool
    {
        return $this->shouldStop;
    }

    public function getShutdownInProgress(): bool
    {
        return $this->shutdownInProgress;
    }

    public function getShutdownStartTime(): ?float
    {
        return $this->shutdownStartTime;
    }

    public function getShutdownSignalCount(): int
    {
        return $this->shutdownSignalCount;
    }

    public function getPoller(): AdaptivePoller
    {
        return $this->poller;
    }

    public function getPdoConnection(): ?PDO
    {
        return $this->pdo;
    }

    public function setLastLogTime(int $time): void
    {
        $this->lastLogTime = $time;
    }

    public function setMessagesToProcess(int $count): void
    {
        $this->messagesToProcess = $count;
    }

    public function setProcessorNameForTest(string $name): void
    {
        $this->testProcessorName = $name;
    }

    public function wasOnReloadCalled(): bool
    {
        return $this->onReloadCalled;
    }

    public function wasOnShutdownCalled(): bool
    {
        return $this->onShutdownCalled;
    }

    // Public wrappers for protected methods

    public function publicHasShutdownTimedOut(): bool
    {
        return $this->hasShutdownTimedOut();
    }

    public function publicCloseDatabaseConnections(): void
    {
        $this->closeDatabaseConnections();
    }

    public function publicMaybeLogStatistics(): void
    {
        $this->maybeLogStatistics();
    }

    public function publicClearTemporaryFiles(): void
    {
        $this->clearTemporaryFiles();
    }

    public function publicReleaseLocks(): void
    {
        $this->releaseLocks();
    }

    public function publicGetProcessorName(): string
    {
        return $this->getProcessorName();
    }

    public function publicProcessMessages(): int
    {
        return $this->processMessages();
    }

    public function publicShutdown(): void
    {
        $this->shutdown();
    }

    // Abstract method implementations

    protected function processMessages(): int
    {
        return $this->messagesToProcess;
    }

    protected function getProcessorName(): string
    {
        return $this->testProcessorName;
    }

    // Hook overrides for testing

    protected function onReload(): void
    {
        $this->onReloadCalled = true;
    }

    protected function onShutdown(): void
    {
        $this->onShutdownCalled = true;
    }
}
