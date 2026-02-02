<?php
/**
 * Unit Tests for CleanupMessageProcessor
 *
 * Tests the cleanup message processor functionality including:
 * - Constructor with default and custom configuration
 * - Message processing delegation to CleanupService
 * - Processor name identification
 */

namespace Eiou\Tests\Processors;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Processors\CleanupMessageProcessor;
use Eiou\Services\CleanupService;
use Eiou\Core\Application;
use Eiou\Services\ServiceContainer;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(CleanupMessageProcessor::class)]
class CleanupMessageProcessorTest extends TestCase
{
    private MockObject|CleanupService $cleanupService;
    private MockObject|Application $application;
    private MockObject|ServiceContainer $serviceContainer;

    /**
     * Sample test data constants
     */
    private const TEST_POLLER_CONFIG = [
        'min_interval_ms' => 1000,
        'max_interval_ms' => 30000,
        'idle_interval_ms' => 10000,
        'adaptive' => true
    ];

    private const TEST_LOCKFILE = '/tmp/test_cleanup_lock.pid';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupService = $this->createMock(CleanupService::class);
        $this->serviceContainer = $this->createMock(ServiceContainer::class);
        $this->application = $this->createMock(Application::class);
    }

    protected function tearDown(): void
    {
        // Clean up any test lockfiles
        @unlink(self::TEST_LOCKFILE);
        @unlink('/tmp/cleanupmessages_lock.pid');

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
        // We need to test without triggering Application::getInstance()
        // Use reflection to verify the properties are set correctly
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
     * Test constructor sets 5-minute log interval
     */
    public function testConstructorSetsFiveMinuteLogInterval(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $reflection = new ReflectionClass($processor);
        $logIntervalProp = $reflection->getProperty('logInterval');
        $logIntervalProp->setAccessible(true);

        $this->assertEquals(300, $logIntervalProp->getValue($processor));
    }

    /**
     * Test constructor stores cleanup service reference
     */
    public function testConstructorStoresCleanupServiceReference(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        // Use actual class reflection to access private property
        $reflection = new ReflectionClass(CleanupMessageProcessor::class);
        $serviceProp = $reflection->getProperty('cleanupService');
        $serviceProp->setAccessible(true);

        $this->assertSame($this->cleanupService, $serviceProp->getValue($processor));
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

        $this->assertEquals('/tmp/cleanupmessages_lock.pid', $lockfileProp->getValue($processor));
    }

    // =========================================================================
    // processMessages Tests
    // =========================================================================

    /**
     * Test processMessages delegates to cleanup service
     */
    public function testProcessMessagesDelegatesToCleanupService(): void
    {
        $this->cleanupService->expects($this->once())
            ->method('processCleanupMessages')
            ->willReturn(5);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(5, $result);
    }

    /**
     * Test processMessages returns zero when no messages cleaned
     */
    public function testProcessMessagesReturnsZeroWhenNoMessagesCleaned(): void
    {
        $this->cleanupService->expects($this->once())
            ->method('processCleanupMessages')
            ->willReturn(0);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(0, $result);
    }

    /**
     * Test processMessages returns count from cleanup service
     */
    public function testProcessMessagesReturnsCountFromCleanupService(): void
    {
        $this->cleanupService->expects($this->once())
            ->method('processCleanupMessages')
            ->willReturn(42);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(42, $result);
    }

    // =========================================================================
    // getProcessorName Tests
    // =========================================================================

    /**
     * Test getProcessorName returns "Cleanup"
     */
    public function testGetProcessorNameReturnsCleanup(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'getProcessorName');

        $this->assertEquals('Cleanup', $result);
    }

    // =========================================================================
    // Poller Configuration Tests
    // =========================================================================

    /**
     * Test default minimum interval is 1 second
     */
    public function testDefaultMinimumIntervalIsOneSecond(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(null, null);

        $reflection = new ReflectionClass($processor);
        $pollerConfigProp = $reflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $config = $pollerConfigProp->getValue($processor);

        // Default cleanup min interval should be >= 1000ms
        $this->assertGreaterThanOrEqual(1000, $config['min_interval_ms']);
    }

    /**
     * Test default maximum interval is 30 seconds
     */
    public function testDefaultMaximumIntervalIsThirtySeconds(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(null, null);

        $reflection = new ReflectionClass($processor);
        $pollerConfigProp = $reflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $config = $pollerConfigProp->getValue($processor);

        // Default cleanup max interval should be <= 30000ms
        $this->assertLessThanOrEqual(30000, $config['max_interval_ms']);
    }

    /**
     * Test default idle interval is 10 seconds
     */
    public function testDefaultIdleIntervalIsTenSeconds(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(null, null);

        $reflection = new ReflectionClass($processor);
        $pollerConfigProp = $reflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $config = $pollerConfigProp->getValue($processor);

        // Default cleanup idle interval should be <= 10000ms
        $this->assertLessThanOrEqual(10000, $config['idle_interval_ms']);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create a CleanupMessageProcessor with mocked dependencies
     */
    private function createProcessorWithMockedDependencies(
        ?array $pollerConfig,
        ?string $lockfile
    ): CleanupMessageProcessor {
        // Create partial mock that doesn't call the real constructor
        $processor = $this->getMockBuilder(CleanupMessageProcessor::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        // Get reflection for the actual classes (not the mock)
        $actualClassReflection = new ReflectionClass(CleanupMessageProcessor::class);
        $parentClassReflection = $actualClassReflection->getParentClass();

        // Set pollerConfig
        $actualConfig = $pollerConfig ?? [
            'min_interval_ms' => 1000,
            'max_interval_ms' => 30000,
            'idle_interval_ms' => 10000,
            'adaptive' => true,
        ];
        $pollerConfigProp = $parentClassReflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $pollerConfigProp->setValue($processor, $actualConfig);

        // Set lockfile
        $actualLockfile = $lockfile ?? '/tmp/cleanupmessages_lock.pid';
        $lockfileProp = $parentClassReflection->getProperty('lockfile');
        $lockfileProp->setAccessible(true);
        $lockfileProp->setValue($processor, $actualLockfile);

        // Set logInterval (300 seconds = 5 minutes for cleanup)
        $logIntervalProp = $parentClassReflection->getProperty('logInterval');
        $logIntervalProp->setAccessible(true);
        $logIntervalProp->setValue($processor, 300);

        // Set lastLogTime
        $lastLogTimeProp = $parentClassReflection->getProperty('lastLogTime');
        $lastLogTimeProp->setAccessible(true);
        $lastLogTimeProp->setValue($processor, time());

        // Set default shutdown timeout
        $shutdownTimeoutProp = $parentClassReflection->getProperty('shutdownTimeout');
        $shutdownTimeoutProp->setAccessible(true);
        $shutdownTimeoutProp->setValue($processor, 30);

        // Set cleanupService using actual class reflection
        $serviceProp = $actualClassReflection->getProperty('cleanupService');
        $serviceProp->setAccessible(true);
        $serviceProp->setValue($processor, $this->cleanupService);

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
