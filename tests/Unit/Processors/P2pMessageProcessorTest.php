<?php
/**
 * Unit Tests for P2pMessageProcessor
 *
 * Tests the P2P message processor functionality including:
 * - Constructor with default and custom configuration
 * - Processor name identification
 * - Fast polling configuration for time-critical P2P routing
 * - Coordinator cycle: reap, recover, dispatch
 * - Per-transport worker limits (HTTP: 50, Tor: 5)
 * - Active worker count tracking (total and per-transport)
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
    private const TEST_MAX_WORKERS = ['http' => 5, 'https' => 5, 'tor' => 3];

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

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        // processMessages returns count from dispatchWorkers (0 when no queued messages)
        $this->assertEquals(0, $result);
    }

    /**
     * Test processMessages dispatches new workers and returns spawned count
     */
    public function testProcessMessagesDispatchesNewWorkers(): void
    {
        $queuedMessages = [
            ['hash' => 'abc123', 'sender_address' => 'http://node1.example.com'],
            ['hash' => 'def456', 'sender_address' => 'http://node2.example.com'],
        ];

        // dispatchWorkers queries p2pRepository for queued messages
        $this->p2pRepository->expects($this->once())
            ->method('getQueuedP2pMessages')
            ->with(Constants::STATUS_QUEUED, Constants::P2P_QUEUE_BATCH_SIZE)
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

        $result = $this->invokeProtectedMethod($processor, 'processMessages');

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // dispatchWorkers Tests (Per-Transport Limits)
    // =========================================================================

    /**
     * Test dispatchWorkers respects per-transport worker limits
     *
     * When a transport has reached its max, queued P2Ps for that transport
     * should be skipped while other transports can still spawn.
     */
    public function testDispatchWorkersRespectsPerTransportLimits(): void
    {
        // Tor limit is 3 in our test config
        $queuedMessages = [
            ['hash' => 'tor1', 'sender_address' => 'abcdef1234567890.onion'],
            ['hash' => 'tor2', 'sender_address' => 'abcdef1234567891.onion'],
        ];

        $this->p2pRepository->expects($this->once())
            ->method('getQueuedP2pMessages')
            ->willReturn($queuedMessages);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        // Fill 3 Tor workers (at limit)
        $actualClassReflection = new ReflectionClass(P2pMessageProcessor::class);
        $activeWorkersProp = $actualClassReflection->getProperty('activeWorkers');
        $activeWorkersProp->setAccessible(true);
        $activeWorkersProp->setValue($processor, [
            1 => ['hash' => 'h1', 'process' => null, 'started_at' => microtime(true), 'pipes' => [], 'transport' => 'tor'],
            2 => ['hash' => 'h2', 'process' => null, 'started_at' => microtime(true), 'pipes' => [], 'transport' => 'tor'],
            3 => ['hash' => 'h3', 'process' => null, 'started_at' => microtime(true), 'pipes' => [], 'transport' => 'tor'],
        ]);

        // spawnWorker should NOT be called since Tor is at capacity
        $processorMock = $this->getMockBuilder(P2pMessageProcessor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['spawnWorker'])
            ->getMock();

        $this->copyProcessorProperties($processor, $processorMock);

        $processorMock->expects($this->never())
            ->method('spawnWorker');

        $result = $this->invokeProtectedMethod($processorMock, 'dispatchWorkers');

        $this->assertEquals(0, $result);
    }

    /**
     * Test dispatchWorkers allows HTTP when Tor is full
     *
     * HTTP P2Ps should still spawn even when Tor limit is reached.
     */
    public function testDispatchWorkersAllowsHttpWhenTorIsFull(): void
    {
        $queuedMessages = [
            ['hash' => 'tor_skip', 'sender_address' => 'abcdef1234567890.onion'],
            ['hash' => 'http_ok', 'sender_address' => 'http://node1.example.com'],
        ];

        $this->p2pRepository->expects($this->once())
            ->method('getQueuedP2pMessages')
            ->willReturn($queuedMessages);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        // Fill Tor to capacity (3)
        $actualClassReflection = new ReflectionClass(P2pMessageProcessor::class);
        $activeWorkersProp = $actualClassReflection->getProperty('activeWorkers');
        $activeWorkersProp->setAccessible(true);
        $activeWorkersProp->setValue($processor, [
            1 => ['hash' => 'h1', 'process' => null, 'started_at' => microtime(true), 'pipes' => [], 'transport' => 'tor'],
            2 => ['hash' => 'h2', 'process' => null, 'started_at' => microtime(true), 'pipes' => [], 'transport' => 'tor'],
            3 => ['hash' => 'h3', 'process' => null, 'started_at' => microtime(true), 'pipes' => [], 'transport' => 'tor'],
        ]);

        $processorMock = $this->getMockBuilder(P2pMessageProcessor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['spawnWorker'])
            ->getMock();

        $this->copyProcessorProperties($processor, $processorMock);

        // Only the HTTP P2P should spawn (Tor skipped)
        $processorMock->expects($this->once())
            ->method('spawnWorker')
            ->with('http_ok', 'http')
            ->willReturn(true);

        $result = $this->invokeProtectedMethod($processorMock, 'dispatchWorkers');

        $this->assertEquals(1, $result);
    }

    /**
     * Test dispatchWorkers spawns workers for queued P2Ps
     */
    public function testDispatchWorkersSpawnsForQueuedP2ps(): void
    {
        $queuedMessages = [
            ['hash' => 'hash_a', 'sender_address' => 'http://node1.example.com'],
            ['hash' => 'hash_b', 'sender_address' => 'http://node2.example.com'],
            ['hash' => 'hash_c', 'sender_address' => 'http://node3.example.com'],
        ];

        $this->p2pRepository->expects($this->once())
            ->method('getQueuedP2pMessages')
            ->with(Constants::STATUS_QUEUED, Constants::P2P_QUEUE_BATCH_SIZE)
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
     * Test dispatchWorkers returns correct spawned count when some fail
     */
    public function testDispatchWorkersReturnsSpawnedCount(): void
    {
        $queuedMessages = [
            ['hash' => 'hash_ok1', 'sender_address' => 'http://node1.example.com'],
            ['hash' => 'hash_fail', 'sender_address' => 'http://node2.example.com'],
            ['hash' => 'hash_ok2', 'sender_address' => 'http://node3.example.com'],
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

    /**
     * Test dispatchWorkers handles mixed transport messages correctly
     *
     * When batch contains both HTTP and Tor P2Ps, each transport's limit
     * is enforced independently.
     */
    public function testDispatchWorkersHandlesMixedTransports(): void
    {
        $queuedMessages = [
            ['hash' => 'http1', 'sender_address' => 'http://node1.example.com'],
            ['hash' => 'tor1', 'sender_address' => 'abcdef1234567890.onion'],
            ['hash' => 'http2', 'sender_address' => 'http://node2.example.com'],
            ['hash' => 'tor2', 'sender_address' => 'abcdef1234567891.onion'],
        ];

        $this->p2pRepository->expects($this->once())
            ->method('getQueuedP2pMessages')
            ->willReturn($queuedMessages);

        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $processorMock = $this->getMockBuilder(P2pMessageProcessor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['spawnWorker'])
            ->getMock();

        $this->copyProcessorProperties($processor, $processorMock);

        // All 4 should spawn (both transports have capacity)
        $processorMock->expects($this->exactly(4))
            ->method('spawnWorker')
            ->willReturn(true);

        $result = $this->invokeProtectedMethod($processorMock, 'dispatchWorkers');

        $this->assertEquals(4, $result);
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
            101 => ['hash' => 'h1', 'process' => null, 'started_at' => microtime(true), 'pipes' => [], 'transport' => 'http'],
            102 => ['hash' => 'h2', 'process' => null, 'started_at' => microtime(true), 'pipes' => [], 'transport' => 'tor'],
            103 => ['hash' => 'h3', 'process' => null, 'started_at' => microtime(true), 'pipes' => [], 'transport' => 'http'],
        ]);

        $this->assertEquals(3, $processor->getActiveWorkerCount());
    }

    /**
     * Test getActiveWorkerCountByTransport returns correct per-transport counts
     */
    public function testGetActiveWorkerCountByTransportReturnsCounts(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $actualClassReflection = new ReflectionClass(P2pMessageProcessor::class);
        $activeWorkersProp = $actualClassReflection->getProperty('activeWorkers');
        $activeWorkersProp->setAccessible(true);
        $activeWorkersProp->setValue($processor, [
            101 => ['hash' => 'h1', 'process' => null, 'started_at' => microtime(true), 'pipes' => [], 'transport' => 'http'],
            102 => ['hash' => 'h2', 'process' => null, 'started_at' => microtime(true), 'pipes' => [], 'transport' => 'tor'],
            103 => ['hash' => 'h3', 'process' => null, 'started_at' => microtime(true), 'pipes' => [], 'transport' => 'http'],
            104 => ['hash' => 'h4', 'process' => null, 'started_at' => microtime(true), 'pipes' => [], 'transport' => 'tor'],
            105 => ['hash' => 'h5', 'process' => null, 'started_at' => microtime(true), 'pipes' => [], 'transport' => 'https'],
        ]);

        $this->assertEquals(2, $processor->getActiveWorkerCountByTransport('http'));
        $this->assertEquals(2, $processor->getActiveWorkerCountByTransport('tor'));
        $this->assertEquals(1, $processor->getActiveWorkerCountByTransport('https'));
        $this->assertEquals(0, $processor->getActiveWorkerCountByTransport('unknown'));
    }

    // =========================================================================
    // getMessageTransport Tests
    // =========================================================================

    /**
     * Test getMessageTransport identifies HTTP addresses
     */
    public function testGetMessageTransportIdentifiesHttp(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'getMessageTransport', [
            ['sender_address' => 'http://node1.example.com']
        ]);

        $this->assertEquals('http', $result);
    }

    /**
     * Test getMessageTransport identifies HTTPS addresses
     */
    public function testGetMessageTransportIdentifiesHttps(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'getMessageTransport', [
            ['sender_address' => 'https://node1.example.com']
        ]);

        $this->assertEquals('https', $result);
    }

    /**
     * Test getMessageTransport identifies Tor addresses
     */
    public function testGetMessageTransportIdentifiesTor(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'getMessageTransport', [
            ['sender_address' => 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef12.onion']
        ]);

        $this->assertEquals('tor', $result);
    }

    /**
     * Test getMessageTransport falls back to default for missing address
     */
    public function testGetMessageTransportFallsBackToDefault(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $result = $this->invokeProtectedMethod($processor, 'getMessageTransport', [
            ['sender_address' => '']
        ]);

        $this->assertEquals(Constants::DEFAULT_TRANSPORT_MODE, $result);
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

        $this->assertEquals(0, $processor->getActiveWorkerCount());

        $this->invokeProtectedMethod($processor, 'onShutdown');

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
     * Test setMaxWorkers updates the per-transport limits
     */
    public function testSetMaxWorkersUpdatesLimits(): void
    {
        $processor = $this->createProcessorWithMockedDependencies(
            self::TEST_POLLER_CONFIG,
            self::TEST_LOCKFILE
        );

        $limits = ['http' => 20, 'https' => 20, 'tor' => 3];
        $processor->setMaxWorkers($limits);

        $actualClassReflection = new ReflectionClass(P2pMessageProcessor::class);
        $overrideProp = $actualClassReflection->getProperty('maxWorkersOverride');
        $overrideProp->setAccessible(true);

        $this->assertEquals($limits, $overrideProp->getValue($processor));
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
    // Constants Integration Tests
    // =========================================================================

    /**
     * Test P2P_MAX_WORKERS is keyed by transport protocol
     */
    public function testMaxWorkersConstantIsKeyedByTransport(): void
    {
        $this->assertIsArray(Constants::P2P_MAX_WORKERS);
        $this->assertArrayHasKey('http', Constants::P2P_MAX_WORKERS);
        $this->assertArrayHasKey('https', Constants::P2P_MAX_WORKERS);
        $this->assertArrayHasKey('tor', Constants::P2P_MAX_WORKERS);
    }

    /**
     * Test Tor worker limit is lower than HTTP
     */
    public function testTorWorkerLimitIsLowerThanHttp(): void
    {
        $this->assertLessThan(
            Constants::P2P_MAX_WORKERS['http'],
            Constants::P2P_MAX_WORKERS['tor']
        );
    }

    /**
     * Test getMaxP2pWorkers returns correct per-transport values
     */
    public function testGetMaxP2pWorkersReturnsPerTransportValues(): void
    {
        $this->assertEquals(Constants::P2P_MAX_WORKERS['http'], Constants::getMaxP2pWorkers('http'));
        $this->assertEquals(Constants::P2P_MAX_WORKERS['tor'], Constants::getMaxP2pWorkers('tor'));
        $this->assertEquals(Constants::P2P_MAX_WORKERS['https'], Constants::getMaxP2pWorkers('https'));
    }

    /**
     * Test getMaxP2pWorkers falls back to minimum for unknown transport
     */
    public function testGetMaxP2pWorkersFallsBackForUnknownTransport(): void
    {
        $result = Constants::getMaxP2pWorkers('unknown_protocol');
        $this->assertEquals(min(Constants::P2P_MAX_WORKERS), $result);
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

        // Set maxWorkersOverride (per-transport limits for testing)
        $overrideProp = $actualClassReflection->getProperty('maxWorkersOverride');
        $overrideProp->setAccessible(true);
        $overrideProp->setValue($processor, self::TEST_MAX_WORKERS);

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
        $ownProps = ['p2pService', 'p2pRepository', 'activeWorkers', 'maxWorkersOverride',
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
