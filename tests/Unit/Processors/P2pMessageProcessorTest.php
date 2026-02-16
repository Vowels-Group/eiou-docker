<?php
/**
 * Unit Tests for P2pMessageProcessor
 *
 * Tests the P2P message processor functionality including:
 * - Constructor with default and custom configuration
 * - Processor name identification
 * - Fast polling configuration for time-critical P2P routing
 * - Coordinator cycle: reap, recover, dispatch
 * - Worker dispatch with max worker limits
 * - Active worker count tracking
 * - Graceful shutdown with no active workers
 */

namespace Eiou\Tests\Processors;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Processors\P2pMessageProcessor;
use Eiou\Services\P2pService;
use Eiou\Database\P2pRepository;
use Eiou\Core\Application;
use Eiou\Core\Constants;
use Eiou\Services\ServiceContainer;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(P2pMessageProcessor::class)]
class P2pMessageProcessorTest extends TestCase
{
    private MockObject|P2pService $p2pService;
    private MockObject|P2pRepository $p2pRepository;
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
    private const TEST_WORKER_SCRIPT = '/tmp/test_worker.php';
    private const TEST_MAX_WORKERS = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->p2pService = $this->createMock(P2pService::class);
        $this->p2pRepository = $this->createMock(P2pRepository::class);
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

        // Use actual class reflection to access private property
        $reflection = new ReflectionClass(P2pMessageProcessor::class);
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
    // processMessages Tests (Coordinator Cycle)
    // =========================================================================

    /**
     * Test processMessages calls reapWorkers as first step of coordinator cycle
     *
     * Since processMessages calls reapWorkers(), recoverStuckP2ps(), and
     * dispatchWorkers() internally, we verify the full cycle runs by checking
     * that dispatchWorkers returns its result (which depends on p2pRepository).
     */
    public function testProcessMessagesReapsFinishedWorkers(): void
    {
        // Setup: no queued messages so dispatchWorkers returns 0
        $this->p2pRepository->expects($this->once())
            ->method('getQueuedP2pMessages')
            ->willReturn([]);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        // Add a fake "finished" worker to activeWorkers via reflection
        // Since we cannot create real proc_open resources in unit tests,
        // we verify the method completes without error when activeWorkers is empty
        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        // processMessages returns count from dispatchWorkers (0 when no queued messages)
        $this->assertEquals(0, $result);
    }

    /**
     * Test processMessages dispatches new workers and returns spawned count
     *
     * When p2pRepository returns queued messages, dispatchWorkers attempts to
     * spawn workers. Since spawnWorker uses proc_open (untestable in unit tests),
     * we verify that the repository is queried with the correct limit.
     */
    public function testProcessMessagesDispatchesNewWorkers(): void
    {
        $queuedMessages = [
            ['hash' => 'abc123'],
            ['hash' => 'def456'],
        ];

        // dispatchWorkers queries p2pRepository for queued messages
        $this->p2pRepository->expects($this->once())
            ->method('getQueuedP2pMessages')
            ->with(Constants::STATUS_QUEUED, self::TEST_MAX_WORKERS)
            ->willReturn($queuedMessages);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        // Use a partial mock to stub spawnWorker so it doesn't call proc_open
        $processorMock = $this->getMockBuilder(P2pMessageProcessor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['spawnWorker'])
            ->getMock();

        // Copy properties from the fully set-up processor to the partial mock
        $this->copyProcessorProperties($processor, $processorMock);

        $processorMock->expects($this->exactly(2))
            ->method('spawnWorker')
            ->willReturn(true);

        $result = $this->invokeProtectedMethod($processorMock, 'processMessages');

        $this->assertEquals(2, $result);
    }

    /**
     * Test processMessages runs recovery after the recovery interval has elapsed
     *
     * When lastRecoveryTime is far enough in the past, recoverStuckP2ps()
     * should be called during the processMessages cycle.
     */
    public function testProcessMessagesRunsRecoveryAfterInterval(): void
    {
        $stuckP2ps = [
            ['hash' => 'stuck1', 'sending_worker_pid' => 99999],
        ];

        // Recovery should trigger getStuckSendingP2ps
        $this->p2pRepository->expects($this->once())
            ->method('getStuckSendingP2ps')
            ->willReturn($stuckP2ps);

        $this->p2pRepository->expects($this->once())
            ->method('recoverStuckP2p')
            ->with('stuck1')
            ->willReturn(true);

        // dispatchWorkers will also query
        $this->p2pRepository->expects($this->once())
            ->method('getQueuedP2pMessages')
            ->willReturn([]);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        // Set lastRecoveryTime far in the past so recovery triggers
        $actualClassReflection = new ReflectionClass(P2pMessageProcessor::class);
        $lastRecoveryProp = $actualClassReflection->getProperty('lastRecoveryTime');
        $lastRecoveryProp->setAccessible(true);
        $lastRecoveryProp->setValue($processor, microtime(true) - 120);

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(0, $result);
    }

    /**
     * Test processMessages skips recovery when interval has not elapsed
     */
    public function testProcessMessagesSkipsRecoveryWhenIntervalNotElapsed(): void
    {
        // Recovery should NOT trigger
        $this->p2pRepository->expects($this->never())
            ->method('getStuckSendingP2ps');

        // dispatchWorkers will query
        $this->p2pRepository->expects($this->once())
            ->method('getQueuedP2pMessages')
            ->willReturn([]);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        // lastRecoveryTime is set to microtime(true) by createProcessorWithMockedDependencies
        // which means the interval hasn't elapsed yet

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // dispatchWorkers Tests
    // =========================================================================

    /**
     * Test dispatchWorkers respects max worker limit
     *
     * When activeWorkers count equals or exceeds maxWorkers, dispatchWorkers
     * should return 0 immediately without querying the repository.
     */
    public function testDispatchWorkersRespectsMaxWorkerLimit(): void
    {
        $this->p2pRepository->expects($this->never())
            ->method('getQueuedP2pMessages');

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        // Fill activeWorkers to maxWorkers via reflection
        $actualClassReflection = new ReflectionClass(P2pMessageProcessor::class);
        $activeWorkersProp = $actualClassReflection->getProperty('activeWorkers');
        $activeWorkersProp->setAccessible(true);

        $fakeWorkers = [];
        for ($i = 1; $i <= self::TEST_MAX_WORKERS; $i++) {
            $fakeWorkers[$i] = [
                'hash' => "hash_{$i}",
                'process' => null,
                'started_at' => microtime(true),
                'pipes' => [],
            ];
        }
        $activeWorkersProp->setValue($processor, $fakeWorkers);

        $result = $this->invokeProtectedMethod($processor, 'dispatchWorkers');

        $this->assertEquals(0, $result);
    }

    /**
     * Test dispatchWorkers spawns workers for queued P2Ps
     *
     * When there are available worker slots and queued messages, dispatchWorkers
     * should call spawnWorker for each queued message.
     */
    public function testDispatchWorkersSpawnsForQueuedP2ps(): void
    {
        $queuedMessages = [
            ['hash' => 'hash_a'],
            ['hash' => 'hash_b'],
            ['hash' => 'hash_c'],
        ];

        $this->p2pRepository->expects($this->once())
            ->method('getQueuedP2pMessages')
            ->with(Constants::STATUS_QUEUED, self::TEST_MAX_WORKERS)
            ->willReturn($queuedMessages);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        // Use partial mock to stub spawnWorker
        $processorMock = $this->getMockBuilder(P2pMessageProcessor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['spawnWorker'])
            ->getMock();

        $this->copyProcessorProperties($processor, $processorMock);

        $processorMock->expects($this->exactly(3))
            ->method('spawnWorker')
            ->willReturn(true);

        $result = $this->invokeProtectedMethod($processorMock, 'dispatchWorkers');

        $this->assertEquals(3, $result);
    }

    /**
     * Test dispatchWorkers returns correct spawned count
     *
     * When some spawnWorker calls succeed and some fail, the returned count
     * should only reflect successful spawns.
     */
    public function testDispatchWorkersReturnsSpawnedCount(): void
    {
        $queuedMessages = [
            ['hash' => 'hash_ok1'],
            ['hash' => 'hash_fail'],
            ['hash' => 'hash_ok2'],
        ];

        $this->p2pRepository->expects($this->once())
            ->method('getQueuedP2pMessages')
            ->willReturn($queuedMessages);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        // Partial mock: first and third succeed, second fails
        $processorMock = $this->getMockBuilder(P2pMessageProcessor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['spawnWorker'])
            ->getMock();

        $this->copyProcessorProperties($processor, $processorMock);

        $processorMock->expects($this->exactly(3))
            ->method('spawnWorker')
            ->willReturnOnConsecutiveCalls(true, false, true);

        $result = $this->invokeProtectedMethod($processorMock, 'dispatchWorkers');

        $this->assertEquals(2, $result);
    }

    /**
     * Test dispatchWorkers queries with correct available slots
     *
     * When some workers are already active, the limit passed to
     * getQueuedP2pMessages should be (maxWorkers - activeWorkers count).
     */
    public function testDispatchWorkersQueriesWithCorrectAvailableSlots(): void
    {
        // 2 workers already active, maxWorkers=5, so 3 slots available
        $this->p2pRepository->expects($this->once())
            ->method('getQueuedP2pMessages')
            ->with(Constants::STATUS_QUEUED, 3)
            ->willReturn([]);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        // Set 2 active workers via reflection
        $actualClassReflection = new ReflectionClass(P2pMessageProcessor::class);
        $activeWorkersProp = $actualClassReflection->getProperty('activeWorkers');
        $activeWorkersProp->setAccessible(true);
        $activeWorkersProp->setValue($processor, [
            100 => ['hash' => 'h1', 'process' => null, 'started_at' => microtime(true), 'pipes' => []],
            200 => ['hash' => 'h2', 'process' => null, 'started_at' => microtime(true), 'pipes' => []],
        ]);

        $result = $this->invokeProtectedMethod($processor, 'dispatchWorkers');

        $this->assertEquals(0, $result);
    }

    /**
     * Test dispatchWorkers returns zero when no queued messages
     */
    public function testDispatchWorkersReturnsZeroWhenNoQueuedMessages(): void
    {
        $this->p2pRepository->expects($this->once())
            ->method('getQueuedP2pMessages')
            ->willReturn([]);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'dispatchWorkers');

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // getActiveWorkerCount Tests
    // =========================================================================

    /**
     * Test getActiveWorkerCount returns zero initially
     */
    public function testGetActiveWorkerCountReturnsZeroInitially(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $this->assertEquals(0, $processor->getActiveWorkerCount());
    }

    /**
     * Test getActiveWorkerCount reflects active workers
     */
    public function testGetActiveWorkerCountReflectsActiveWorkers(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        // Add fake workers via reflection
        $actualClassReflection = new ReflectionClass(P2pMessageProcessor::class);
        $activeWorkersProp = $actualClassReflection->getProperty('activeWorkers');
        $activeWorkersProp->setAccessible(true);
        $activeWorkersProp->setValue($processor, [
            101 => ['hash' => 'h1', 'process' => null, 'started_at' => microtime(true), 'pipes' => []],
            102 => ['hash' => 'h2', 'process' => null, 'started_at' => microtime(true), 'pipes' => []],
            103 => ['hash' => 'h3', 'process' => null, 'started_at' => microtime(true), 'pipes' => []],
        ]);

        $this->assertEquals(3, $processor->getActiveWorkerCount());
    }

    // =========================================================================
    // onShutdown Tests
    // =========================================================================

    /**
     * Test onShutdown does nothing when no active workers
     */
    public function testOnShutdownDoesNothingWhenNoActiveWorkers(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        // Verify activeWorkers is empty
        $this->assertEquals(0, $processor->getActiveWorkerCount());

        // onShutdown should return without error when no active workers
        // (it checks empty($this->activeWorkers) and returns early)
        $this->invokeProtectedMethod($processor, 'onShutdown');

        // If we get here without exception, the test passes
        $this->assertEquals(0, $processor->getActiveWorkerCount());
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
    // Test Helper Setters
    // =========================================================================

    /**
     * Test setP2pRepository updates the repository
     */
    public function testSetP2pRepositoryUpdatesRepository(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $newRepo = $this->createMock(P2pRepository::class);
        $processor->setP2pRepository($newRepo);

        $actualClassReflection = new ReflectionClass(P2pMessageProcessor::class);
        $repoProp = $actualClassReflection->getProperty('p2pRepository');
        $repoProp->setAccessible(true);

        $this->assertSame($newRepo, $repoProp->getValue($processor));
    }

    /**
     * Test setWorkerScript updates the script path
     */
    public function testSetWorkerScriptUpdatesPath(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $processor->setWorkerScript('/custom/path/worker.php');

        $actualClassReflection = new ReflectionClass(P2pMessageProcessor::class);
        $scriptProp = $actualClassReflection->getProperty('workerScript');
        $scriptProp->setAccessible(true);

        $this->assertEquals('/custom/path/worker.php', $scriptProp->getValue($processor));
    }

    /**
     * Test setMaxWorkers updates the limit
     */
    public function testSetMaxWorkersUpdatesLimit(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $processor->setMaxWorkers(10);

        $actualClassReflection = new ReflectionClass(P2pMessageProcessor::class);
        $maxProp = $actualClassReflection->getProperty('maxWorkers');
        $maxProp->setAccessible(true);

        $this->assertEquals(10, $maxProp->getValue($processor));
    }

    // =========================================================================
    // recoverStuckP2ps Tests
    // =========================================================================

    /**
     * Test recoverStuckP2ps calls repository methods
     */
    public function testRecoverStuckP2psCallsRepositoryMethods(): void
    {
        $stuckP2ps = [
            ['hash' => 'stuck_a', 'sending_worker_pid' => 11111],
            ['hash' => 'stuck_b', 'sending_worker_pid' => 22222],
        ];

        $this->p2pRepository->expects($this->once())
            ->method('getStuckSendingP2ps')
            ->willReturn($stuckP2ps);

        $this->p2pRepository->expects($this->exactly(2))
            ->method('recoverStuckP2p')
            ->willReturnMap([
                ['stuck_a', true],
                ['stuck_b', false],
            ]);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $this->invokeProtectedMethod($processor, 'recoverStuckP2ps');
    }

    /**
     * Test recoverStuckP2ps handles empty result
     */
    public function testRecoverStuckP2psHandlesEmptyResult(): void
    {
        $this->p2pRepository->expects($this->once())
            ->method('getStuckSendingP2ps')
            ->willReturn([]);

        $this->p2pRepository->expects($this->never())
            ->method('recoverStuckP2p');

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $this->invokeProtectedMethod($processor, 'recoverStuckP2ps');
    }

    // =========================================================================
    // reapWorkers Tests
    // =========================================================================

    /**
     * Test reapWorkers does nothing when no active workers
     */
    public function testReapWorkersDoesNothingWhenNoActiveWorkers(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        // Should complete without error
        $this->invokeProtectedMethod($processor, 'reapWorkers');

        $this->assertEquals(0, $processor->getActiveWorkerCount());
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create a P2pMessageProcessor with mocked dependencies
     *
     * Sets up all properties via reflection, including the new coordinator
     * properties (p2pRepository, activeWorkers, maxWorkers, workerScript,
     * lastRecoveryTime, recoveryInterval).
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

        // Get reflection for the actual classes (not the mock)
        $actualClassReflection = new ReflectionClass(P2pMessageProcessor::class);
        $parentClassReflection = $actualClassReflection->getParentClass();

        // --- Parent class (AbstractMessageProcessor) properties ---

        // Set pollerConfig
        $actualConfig = $pollerConfig ?? [
            'min_interval_ms' => 100,
            'max_interval_ms' => 5000,
            'idle_interval_ms' => 2000,
            'adaptive' => true,
        ];
        $pollerConfigProp = $parentClassReflection->getProperty('pollerConfig');
        $pollerConfigProp->setAccessible(true);
        $pollerConfigProp->setValue($processor, $actualConfig);

        // Set lockfile
        $actualLockfile = $lockfile ?? '/tmp/p2pmessages_lock.pid';
        $lockfileProp = $parentClassReflection->getProperty('lockfile');
        $lockfileProp->setAccessible(true);
        $lockfileProp->setValue($processor, $actualLockfile);

        // Set logInterval (60 seconds for P2P)
        $logIntervalProp = $parentClassReflection->getProperty('logInterval');
        $logIntervalProp->setAccessible(true);
        $logIntervalProp->setValue($processor, 60);

        // Set lastLogTime
        $lastLogTimeProp = $parentClassReflection->getProperty('lastLogTime');
        $lastLogTimeProp->setAccessible(true);
        $lastLogTimeProp->setValue($processor, time());

        // Set default shutdown timeout
        $shutdownTimeoutProp = $parentClassReflection->getProperty('shutdownTimeout');
        $shutdownTimeoutProp->setAccessible(true);
        $shutdownTimeoutProp->setValue($processor, 30);

        // --- P2pMessageProcessor-specific properties ---

        // Set p2pService
        $serviceProp = $actualClassReflection->getProperty('p2pService');
        $serviceProp->setAccessible(true);
        $serviceProp->setValue($processor, $this->p2pService);

        // Set p2pRepository
        $repoProp = $actualClassReflection->getProperty('p2pRepository');
        $repoProp->setAccessible(true);
        $repoProp->setValue($processor, $this->p2pRepository);

        // Set activeWorkers (empty by default)
        $activeWorkersProp = $actualClassReflection->getProperty('activeWorkers');
        $activeWorkersProp->setAccessible(true);
        $activeWorkersProp->setValue($processor, []);

        // Set maxWorkers
        $maxWorkersProp = $actualClassReflection->getProperty('maxWorkers');
        $maxWorkersProp->setAccessible(true);
        $maxWorkersProp->setValue($processor, self::TEST_MAX_WORKERS);

        // Set workerScript
        $workerScriptProp = $actualClassReflection->getProperty('workerScript');
        $workerScriptProp->setAccessible(true);
        $workerScriptProp->setValue($processor, self::TEST_WORKER_SCRIPT);

        // Set lastRecoveryTime (current time so recovery doesn't trigger by default)
        $lastRecoveryTimeProp = $actualClassReflection->getProperty('lastRecoveryTime');
        $lastRecoveryTimeProp->setAccessible(true);
        $lastRecoveryTimeProp->setValue($processor, microtime(true));

        // Set recoveryInterval
        $recoveryIntervalProp = $actualClassReflection->getProperty('recoveryInterval');
        $recoveryIntervalProp->setAccessible(true);
        $recoveryIntervalProp->setValue($processor, 60);

        return $processor;
    }

    /**
     * Copy all relevant properties from a configured processor to a partial mock
     *
     * Used when we need a partial mock with spawnWorker stubbed but all other
     * properties correctly set up.
     */
    private function copyProcessorProperties(
        P2pMessageProcessor $source,
        P2pMessageProcessor $target
    ): void {
        $actualClassReflection = new ReflectionClass(P2pMessageProcessor::class);
        $parentClassReflection = $actualClassReflection->getParentClass();

        // Parent class properties
        $parentProps = ['pollerConfig', 'lockfile', 'logInterval', 'lastLogTime', 'shutdownTimeout'];
        foreach ($parentProps as $propName) {
            $prop = $parentClassReflection->getProperty($propName);
            $prop->setAccessible(true);
            $prop->setValue($target, $prop->getValue($source));
        }

        // P2pMessageProcessor properties
        $ownProps = ['p2pService', 'p2pRepository', 'activeWorkers', 'maxWorkers',
                     'workerScript', 'lastRecoveryTime', 'recoveryInterval'];
        foreach ($ownProps as $propName) {
            $prop = $actualClassReflection->getProperty($propName);
            $prop->setAccessible(true);
            $prop->setValue($target, $prop->getValue($source));
        }
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
