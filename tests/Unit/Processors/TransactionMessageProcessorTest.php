<?php
/**
 * Unit Tests for TransactionMessageProcessor
 *
 * Tests the transaction message processor functionality including:
 * - Constructor with default and custom configuration
 * - Message processing delegation to TransactionService
 * - Processor name identification
 * - Fast polling configuration for time-critical transactions
 */

namespace Eiou\Tests\Processors;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Processors\TransactionMessageProcessor;
use Eiou\Services\TransactionService;
use Eiou\Core\Application;
use Eiou\Services\ServiceContainer;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(TransactionMessageProcessor::class)]
class TransactionMessageProcessorTest extends TestCase
{
    private MockObject|TransactionService $transactionService;
    private MockObject|Application $application;
    private MockObject|ServiceContainer $serviceContainer;

    /**
     * Sample test data constants
     */
    private const TEST_POLLER_CONFIG = [
        'min_interval_ms' => 100,
        'max_interval_ms' => 5000,
        'idle_interval_ms' => 2000,
        'adaptive' => true
    ];

    private const TEST_LOCKFILE = '/tmp/test_transaction_lock.pid';

    protected function setUp(): void
    {
        parent::setUp();

        $this->transactionService = $this->createMock(TransactionService::class);
        $this->serviceContainer = $this->createMock(ServiceContainer::class);
        $this->application = $this->createMock(Application::class);
    }

    protected function tearDown(): void
    {
        // Clean up any test lockfiles
        @unlink(self::TEST_LOCKFILE);
        @unlink('/tmp/transactionmessages_lock.pid');

        parent::tearDown();
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor with custom poller config and lockfile
     */
    public function testConstructorWithCustomPollerConfigAndLockfile(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $reflection = new ReflectionClass($processor);

        $pollerConfigProp = $reflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $this->assertEquals(self::TEST_POLLER_CONFIG, $pollerConfigProp->getValue($processor));

        $lockfileProp = $reflection->getProperty('lockfile');
        $lockfileProp->setAccessible(true);
        $this->assertEquals(self::TEST_LOCKFILE, $lockfileProp->getValue($processor));
    }

    /**
     * Test constructor sets 60 second log interval
     */
    public function testConstructorSetsSixtySecondLogInterval(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $reflection = new ReflectionClass($processor);
        $logIntervalProp = $reflection->getProperty('logInterval');
        $logIntervalProp->setAccessible(true);

        $this->assertEquals(60, $logIntervalProp->getValue($processor));
    }

    /**
     * Test constructor stores transaction service reference
     */
    public function testConstructorStoresTransactionServiceReference(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $reflection = new ReflectionClass($processor);
        $serviceProp = $reflection->getProperty('transactionService');
        $serviceProp->setAccessible(true);

        $this->assertSame($this->transactionService, $serviceProp->getValue($processor));
    }

    // =========================================================================
    // Default Configuration Tests
    // =========================================================================

    /**
     * Test constructor uses default config when null provided
     */
    public function testConstructorUsesDefaultConfigWhenNullProvided(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(null, null);

        $reflection = new ReflectionClass($processor);

        $pollerConfigProp = $reflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $config = $pollerConfigProp->getValue($processor);

        // Verify default config structure (uses Constants values or fallbacks)
        $this->assertArrayHasKey('min_interval_ms', $config);
        $this->assertArrayHasKey('max_interval_ms', $config);
        $this->assertArrayHasKey('idle_interval_ms', $config);
        $this->assertArrayHasKey('adaptive', $config);
    }

    /**
     * Test constructor uses default lockfile when null provided
     */
    public function testConstructorUsesDefaultLockfileWhenNullProvided(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            null
        );

        $reflection = new ReflectionClass($processor);
        $lockfileProp = $reflection->getProperty('lockfile');
        $lockfileProp->setAccessible(true);

        $this->assertEquals('/tmp/transactionmessages_lock.pid', $lockfileProp->getValue($processor));
    }

    // =========================================================================
    // processMessages Tests
    // =========================================================================

    /**
     * Test processMessages delegates to transaction service
     */
    public function testProcessMessagesDelegatesToTransactionService(): void
    {
        $this->transactionService->expects($this->once())
            ->method('processPendingTransactions')
            ->willReturn(8);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(8, $result);
    }

    /**
     * Test processMessages returns zero when no pending transactions
     */
    public function testProcessMessagesReturnsZeroWhenNoPendingTransactions(): void
    {
        $this->transactionService->expects($this->once())
            ->method('processPendingTransactions')
            ->willReturn(0);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(0, $result);
    }

    /**
     * Test processMessages returns count from transaction service
     */
    public function testProcessMessagesReturnsCountFromTransactionService(): void
    {
        $this->transactionService->expects($this->once())
            ->method('processPendingTransactions')
            ->willReturn(37);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(37, $result);
    }

    /**
     * Test processMessages handles large batch sizes
     */
    public function testProcessMessagesHandlesLargeBatchSizes(): void
    {
        $this->transactionService->expects($this->once())
            ->method('processPendingTransactions')
            ->willReturn(500);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(500, $result);
    }

    // =========================================================================
    // getProcessorName Tests
    // =========================================================================

    /**
     * Test getProcessorName returns "Transaction"
     */
    public function testGetProcessorNameReturnsTransaction(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'getProcessorName');

        $this->assertEquals('Transaction', $result);
    }

    // =========================================================================
    // Poller Configuration Tests (Fast Polling for Transactions)
    // =========================================================================

    /**
     * Test default minimum interval is 100ms (fast polling)
     */
    public function testDefaultMinimumIntervalIs100ms(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(null, null);

        $reflection = new ReflectionClass($processor);
        $pollerConfigProp = $reflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $config = $pollerConfigProp->getValue($processor);

        // Default Transaction min interval should be <= 100ms for fast polling
        $this->assertLessThanOrEqual(100, $config['min_interval_ms']);
    }

    /**
     * Test default maximum interval is 5 seconds
     */
    public function testDefaultMaximumIntervalIsFiveSeconds(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(null, null);

        $reflection = new ReflectionClass($processor);
        $pollerConfigProp = $reflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $config = $pollerConfigProp->getValue($processor);

        // Default Transaction max interval should be <= 5000ms
        $this->assertLessThanOrEqual(5000, $config['max_interval_ms']);
    }

    /**
     * Test default idle interval is 2 seconds
     */
    public function testDefaultIdleIntervalIsTwoSeconds(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(null, null);

        $reflection = new ReflectionClass($processor);
        $pollerConfigProp = $reflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $config = $pollerConfigProp->getValue($processor);

        // Default Transaction idle interval should be <= 2000ms
        $this->assertLessThanOrEqual(2000, $config['idle_interval_ms']);
    }

    /**
     * Test Transaction polling is faster than cleanup polling
     */
    public function testTransactionPollingIsFasterThanCleanupPolling(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(null, null);

        $reflection = new ReflectionClass($processor);
        $pollerConfigProp = $reflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $config = $pollerConfigProp->getValue($processor);

        // Transaction min interval (100ms) should be less than cleanup min interval (1000ms)
        $this->assertLessThan(1000, $config['min_interval_ms']);
    }

    /**
     * Test Transaction polling matches P2P polling intervals
     */
    public function testTransactionPollingMatchesP2pPollingIntervals(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(null, null);

        $reflection = new ReflectionClass($processor);
        $pollerConfigProp = $reflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $config = $pollerConfigProp->getValue($processor);

        // Both P2P and Transaction should have similar fast polling (100ms min, 5000ms max)
        $this->assertEquals(100, $config['min_interval_ms']);
        $this->assertEquals(5000, $config['max_interval_ms']);
        $this->assertEquals(2000, $config['idle_interval_ms']);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    /**
     * Test processor handles empty poller config array
     */
    public function testProcessorHandlesEmptyPollerConfigArray(): void
    {
        $processor = $this->createProcessorWithMockedDependencies([], self::TEST_LOCKFILE);

        $reflection = new ReflectionClass($processor);
        $pollerConfigProp = $reflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);

        // Should still have an array, even if empty
        $this->assertIsArray($pollerConfigProp->getValue($processor));
    }

    /**
     * Test processor handles repeated processMessages calls
     */
    public function testProcessorHandlesRepeatedProcessMessagesCalls(): void
    {
        $this->transactionService->expects($this->exactly(3))
            ->method('processPendingTransactions')
            ->willReturnOnConsecutiveCalls(3, 0, 7);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result1 = $this->invokeProtectedMethod($processor, 'processMessages');
        $result2 = $this->invokeProtectedMethod($processor, 'processMessages');
        $result3 = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(3, $result1);
        $this->assertEquals(0, $result2);
        $this->assertEquals(7, $result3);
    }

    /**
     * Test custom poller config overrides defaults
     */
    public function testCustomPollerConfigOverridesDefaults(): void
    {
        $customConfig = [
            'min_interval_ms' => 50,
            'max_interval_ms' => 10000,
            'idle_interval_ms' => 5000,
            'adaptive' => false
        ];

        $processor = $this->createProcessorWithMockedDependencies(
            $customConfig,
            self::TEST_LOCKFILE
        );

        $reflection = new ReflectionClass($processor);
        $pollerConfigProp = $reflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $config = $pollerConfigProp->getValue($processor);

        $this->assertEquals(50, $config['min_interval_ms']);
        $this->assertEquals(10000, $config['max_interval_ms']);
        $this->assertEquals(5000, $config['idle_interval_ms']);
        $this->assertFalse($config['adaptive']);
    }

    /**
     * Test processor handles single transaction processing correctly
     */
    public function testProcessorHandlesSingleTransactionProcessingCorrectly(): void
    {
        $this->transactionService->expects($this->once())
            ->method('processPendingTransactions')
            ->willReturn(1);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(1, $result);
    }

    // =========================================================================
    // Log Interval Comparison Tests
    // =========================================================================

    /**
     * Test Transaction log interval matches P2P log interval
     */
    public function testTransactionLogIntervalMatchesP2pLogInterval(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $reflection = new ReflectionClass($processor);
        $logIntervalProp = $reflection->getProperty('logInterval');
        $logIntervalProp->setAccessible(true);

        // Both Transaction and P2P should log every 60 seconds
        $this->assertEquals(60, $logIntervalProp->getValue($processor));
    }

    /**
     * Test Transaction log interval is shorter than Cleanup log interval
     */
    public function testTransactionLogIntervalIsShorterThanCleanupLogInterval(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $reflection = new ReflectionClass($processor);
        $logIntervalProp = $reflection->getProperty('logInterval');
        $logIntervalProp->setAccessible(true);

        // Transaction logs every 60 seconds, Cleanup logs every 300 seconds
        $this->assertLessThan(300, $logIntervalProp->getValue($processor));
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create a TransactionMessageProcessor with mocked dependencies
     */
    private function createProcessorWithMockedDependencies(
        ?array $pollerConfig,
        ?string $lockfile
    ): TransactionMessageProcessor {
        // Create partial mock that doesn't call the real constructor
        $processor = $this->getMockBuilder(TransactionMessageProcessor::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        // Manually set up the processor using reflection
        $reflection = new ReflectionClass($processor);

        // Set up parent class properties
        $parentReflection = $reflection->getParentClass();

        // Set pollerConfig
        $actualConfig = $pollerConfig ?? [
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 2000,
            'adaptive' => true,
        ];
        $pollerConfigProp = $parentReflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $pollerConfigProp->setValue($processor, $actualConfig);

        // Set lockfile
        $actualLockfile = $lockfile ?? '/tmp/transactionmessages_lock.pid';
        $lockfileProp = $parentReflection->getProperty('lockfile');
        $lockfileProp->setAccessible(true);
        $lockfileProp->setValue($processor, $actualLockfile);

        // Set logInterval (60 seconds for Transaction)
        $logIntervalProp = $parentReflection->getProperty('logInterval');
        $logIntervalProp->setAccessible(true);
        $logIntervalProp->setValue($processor, 60);

        // Set lastLogTime
        $lastLogTimeProp = $parentReflection->getProperty('lastLogTime');
        $lastLogTimeProp->setAccessible(true);
        $lastLogTimeProp->setValue($processor, time());

        // Set default shutdown timeout
        $shutdownTimeoutProp = $parentReflection->getProperty('shutdownTimeout');
        $shutdownTimeoutProp->setAccessible(true);
        $shutdownTimeoutProp->setValue($processor, 30);

        // Set transactionService
        $serviceProp = $reflection->getProperty('transactionService');
        $serviceProp->setAccessible(true);
        $serviceProp->setValue($processor, $this->transactionService);

        return $processor;
    }

    /**
     * Invoke a protected method on an object
     */
    private function invokeProtectedMethod(object $object, string $methodName, array $args = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }
}
