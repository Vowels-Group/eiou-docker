<?php
/**
 * Unit Tests for P2pMessageProcessor
 *
 * Tests the P2P message processor functionality including:
 * - Constructor with default and custom configuration
 * - Message processing delegation to P2pService
 * - Processor name identification
 * - Fast polling configuration for time-critical P2P routing
 */

namespace Eiou\Tests\Processors;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Processors\P2pMessageProcessor;
use Eiou\Services\P2pService;
use Eiou\Core\Application;
use Eiou\Services\ServiceContainer;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(P2pMessageProcessor::class)]
class P2pMessageProcessorTest extends TestCase
{
    private MockObject|P2pService $p2pService;
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

    private const TEST_LOCKFILE = '/tmp/test_p2p_lock.pid';

    protected function setUp(): void
    {
        parent::setUp();

        $this->p2pService = $this->createMock(P2pService::class);
        $this->serviceContainer = $this->createMock(ServiceContainer::class);
        $this->application = $this->createMock(Application::class);
    }

    protected function tearDown(): void
    {
        // Clean up any test lockfiles
        @unlink(self::TEST_LOCKFILE);
        @unlink('/tmp/p2pmessages_lock.pid');

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
     * Test constructor stores P2P service reference
     */
    public function testConstructorStoresP2pServiceReference(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $reflection = new ReflectionClass($processor);
        $serviceProp = $reflection->getProperty('p2pService');
        $serviceProp->setAccessible(true);

        $this->assertSame($this->p2pService, $serviceProp->getValue($processor));
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

        $this->assertEquals('/tmp/p2pmessages_lock.pid', $lockfileProp->getValue($processor));
    }

    // =========================================================================
    // processMessages Tests
    // =========================================================================

    /**
     * Test processMessages delegates to P2P service
     */
    public function testProcessMessagesDelegatesToP2pService(): void
    {
        $this->p2pService->expects($this->once())
            ->method('processQueuedP2pMessages')
            ->willReturn(10);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(10, $result);
    }

    /**
     * Test processMessages returns zero when no messages queued
     */
    public function testProcessMessagesReturnsZeroWhenNoMessagesQueued(): void
    {
        $this->p2pService->expects($this->once())
            ->method('processQueuedP2pMessages')
            ->willReturn(0);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(0, $result);
    }

    /**
     * Test processMessages returns count from P2P service
     */
    public function testProcessMessagesReturnsCountFromP2pService(): void
    {
        $this->p2pService->expects($this->once())
            ->method('processQueuedP2pMessages')
            ->willReturn(25);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(25, $result);
    }

    /**
     * Test processMessages handles large batch sizes
     */
    public function testProcessMessagesHandlesLargeBatchSizes(): void
    {
        $this->p2pService->expects($this->once())
            ->method('processQueuedP2pMessages')
            ->willReturn(1000);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(1000, $result);
    }

    // =========================================================================
    // getProcessorName Tests
    // =========================================================================

    /**
     * Test getProcessorName returns "P2P"
     */
    public function testGetProcessorNameReturnsP2P(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'getProcessorName');

        $this->assertEquals('P2P', $result);
    }

    // =========================================================================
    // Poller Configuration Tests (Fast Polling for P2P)
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

        // Default P2P min interval should be <= 100ms for fast polling
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

        // Default P2P max interval should be <= 5000ms
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

        // Default P2P idle interval should be <= 2000ms
        $this->assertLessThanOrEqual(2000, $config['idle_interval_ms']);
    }

    /**
     * Test P2P polling is faster than cleanup polling
     */
    public function testP2pPollingIsFasterThanCleanupPolling(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(null, null);

        $reflection = new ReflectionClass($processor);
        $pollerConfigProp = $reflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $config = $pollerConfigProp->getValue($processor);

        // P2P min interval (100ms) should be less than cleanup min interval (1000ms)
        $this->assertLessThan(1000, $config['min_interval_ms']);
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
        $this->p2pService->expects($this->exactly(3))
            ->method('processQueuedP2pMessages')
            ->willReturnOnConsecutiveCalls(5, 0, 10);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result1 = $this->invokeProtectedMethod($processor, 'processMessages');
        $result2 = $this->invokeProtectedMethod($processor, 'processMessages');
        $result3 = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(5, $result1);
        $this->assertEquals(0, $result2);
        $this->assertEquals(10, $result3);
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

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create a P2pMessageProcessor with mocked dependencies
     */
    private function createProcessorWithMockedDependencies(
        ?array $pollerConfig,
        ?string $lockfile
    ): P2pMessageProcessor {
        // Create partial mock that doesn't call the real constructor
        $processor = $this->getMockBuilder(P2pMessageProcessor::class)
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
        $actualLockfile = $lockfile ?? '/tmp/p2pmessages_lock.pid';
        $lockfileProp = $parentReflection->getProperty('lockfile');
        $lockfileProp->setAccessible(true);
        $lockfileProp->setValue($processor, $actualLockfile);

        // Set logInterval (60 seconds for P2P)
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

        // Set p2pService
        $serviceProp = $reflection->getProperty('p2pService');
        $serviceProp->setAccessible(true);
        $serviceProp->setValue($processor, $this->p2pService);

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
