<?php
/**
 * Unit Tests for ServiceContainer
 *
 * Tests the singleton dependency injection container including:
 * - Singleton pattern enforcement
 * - Repository lazy loading
 * - Service lazy loading
 * - Utility instance management
 * - Circular dependency wiring
 * - Service registration and retrieval
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\ServiceContainer;
use Eiou\Core\UserContext;
use Eiou\Utils\Logger;
use Eiou\Utils\InputValidator;
use Eiou\Utils\Security;
use PDO;
use ReflectionClass;
use RuntimeException;

#[CoversClass(ServiceContainer::class)]
class ServiceContainerTest extends TestCase
{
    private ?ServiceContainer $container = null;
    private PDO $mockPdo;
    private UserContext $mockUserContext;

    protected function setUp(): void
    {
        // Reset singleton for each test
        $this->resetSingleton();

        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockUserContext = $this->createMock(UserContext::class);
    }

    protected function tearDown(): void
    {
        // Reset singleton after each test
        $this->resetSingleton();
    }

    /**
     * Reset the singleton instance using reflection
     */
    private function resetSingleton(): void
    {
        $reflection = new ReflectionClass(ServiceContainer::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    // =========================================================================
    // Singleton Pattern Tests
    // =========================================================================

    /**
     * Test getInstance returns singleton instance
     */
    public function testGetInstanceReturnsSingletonInstance(): void
    {
        $instance1 = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);
        $instance2 = ServiceContainer::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    /**
     * Test getInstance creates new instance with provided dependencies
     */
    public function testGetInstanceCreatesNewInstanceWithProvidedDependencies(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $this->assertInstanceOf(ServiceContainer::class, $container);
        $this->assertSame($this->mockUserContext, $container->getCurrentUser());
    }

    /**
     * Test getInstance ignores subsequent dependencies after first call
     */
    public function testGetInstanceIgnoresSubsequentDependenciesAfterFirstCall(): void
    {
        $container1 = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $differentUserContext = $this->createMock(UserContext::class);
        $container2 = ServiceContainer::getInstance($differentUserContext, null);

        $this->assertSame($container1, $container2);
        $this->assertSame($this->mockUserContext, $container2->getCurrentUser());
    }

    // =========================================================================
    // Current User Tests
    // =========================================================================

    /**
     * Test setCurrentUser sets the user context
     */
    public function testSetCurrentUserSetsTheUserContext(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $newUserContext = $this->createMock(UserContext::class);
        $container->setCurrentUser($newUserContext);

        $this->assertSame($newUserContext, $container->getCurrentUser());
    }

    /**
     * Test getCurrentUser returns current user context
     */
    public function testGetCurrentUserReturnsCurrentUserContext(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $this->assertSame($this->mockUserContext, $container->getCurrentUser());
    }

    // =========================================================================
    // PDO Tests
    // =========================================================================

    /**
     * Test getPdo returns PDO instance
     */
    public function testGetPdoReturnsPdoInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $this->assertSame($this->mockPdo, $container->getPdo());
    }

    /**
     * Test getPdo throws RuntimeException when PDO is null
     */
    public function testGetPdoThrowsRuntimeExceptionWhenPdoIsNull(): void
    {
        // Create container and force PDO to be null
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        // Use reflection to set PDO to null
        $reflection = new ReflectionClass($container);
        $pdoProperty = $reflection->getProperty('pdo');
        $pdoProperty->setAccessible(true);
        $pdoProperty->setValue($container, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database connection is not available');

        $container->getPdo();
    }

    // =========================================================================
    // Repository Tests
    // =========================================================================

    /**
     * Test getAddressRepository returns repository instance
     */
    public function testGetAddressRepositoryReturnsRepositoryInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $repo = $container->getAddressRepository();
        $this->assertInstanceOf(\Eiou\Database\AddressRepository::class, $repo);
    }

    /**
     * Test getAddressRepository returns same instance on subsequent calls
     */
    public function testGetAddressRepositoryReturnsSameInstanceOnSubsequentCalls(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $repo1 = $container->getAddressRepository();
        $repo2 = $container->getAddressRepository();

        $this->assertSame($repo1, $repo2);
    }

    /**
     * Test getBalanceRepository returns repository instance
     */
    public function testGetBalanceRepositoryReturnsRepositoryInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $repo = $container->getBalanceRepository();
        $this->assertInstanceOf(\Eiou\Database\BalanceRepository::class, $repo);
    }

    /**
     * Test getContactRepository returns repository instance
     */
    public function testGetContactRepositoryReturnsRepositoryInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $repo = $container->getContactRepository();
        $this->assertInstanceOf(\Eiou\Database\ContactRepository::class, $repo);
    }

    /**
     * Test getP2pRepository returns repository instance
     */
    public function testGetP2pRepositoryReturnsRepositoryInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $repo = $container->getP2pRepository();
        $this->assertInstanceOf(\Eiou\Database\P2pRepository::class, $repo);
    }

    /**
     * Test getRp2pRepository returns repository instance
     */
    public function testGetRp2pRepositoryReturnsRepositoryInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $repo = $container->getRp2pRepository();
        $this->assertInstanceOf(\Eiou\Database\Rp2pRepository::class, $repo);
    }

    /**
     * Test getTransactionRepository returns repository instance
     */
    public function testGetTransactionRepositoryReturnsRepositoryInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $repo = $container->getTransactionRepository();
        $this->assertInstanceOf(\Eiou\Database\TransactionRepository::class, $repo);
    }

    /**
     * Test getDebugRepository returns repository instance
     */
    public function testGetDebugRepositoryReturnsRepositoryInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $repo = $container->getDebugRepository();
        $this->assertInstanceOf(\Eiou\Database\DebugRepository::class, $repo);
    }

    /**
     * Test getApiKeyRepository returns repository instance
     */
    public function testGetApiKeyRepositoryReturnsRepositoryInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $repo = $container->getApiKeyRepository();
        $this->assertInstanceOf(\Eiou\Database\ApiKeyRepository::class, $repo);
    }

    /**
     * Test getMessageDeliveryRepository returns repository instance
     */
    public function testGetMessageDeliveryRepositoryReturnsRepositoryInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $repo = $container->getMessageDeliveryRepository();
        $this->assertInstanceOf(\Eiou\Database\MessageDeliveryRepository::class, $repo);
    }

    /**
     * Test getDeadLetterQueueRepository returns repository instance
     */
    public function testGetDeadLetterQueueRepositoryReturnsRepositoryInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $repo = $container->getDeadLetterQueueRepository();
        $this->assertInstanceOf(\Eiou\Database\DeadLetterQueueRepository::class, $repo);
    }

    /**
     * Test getHeldTransactionRepository returns repository instance
     */
    public function testGetHeldTransactionRepositoryReturnsRepositoryInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $repo = $container->getHeldTransactionRepository();
        $this->assertInstanceOf(\Eiou\Database\HeldTransactionRepository::class, $repo);
    }

    /**
     * Test getTransactionChainRepository returns repository instance
     */
    public function testGetTransactionChainRepositoryReturnsRepositoryInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $repo = $container->getTransactionChainRepository();
        $this->assertInstanceOf(\Eiou\Database\TransactionChainRepository::class, $repo);
    }

    // =========================================================================
    // Utility Tests
    // =========================================================================

    /**
     * Test getInputValidator returns InputValidator instance
     */
    public function testGetInputValidatorReturnsInputValidatorInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $validator = $container->getInputValidator();
        $this->assertInstanceOf(InputValidator::class, $validator);
    }

    /**
     * Test getInputValidator returns same instance on subsequent calls
     */
    public function testGetInputValidatorReturnsSameInstanceOnSubsequentCalls(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $validator1 = $container->getInputValidator();
        $validator2 = $container->getInputValidator();

        $this->assertSame($validator1, $validator2);
    }

    /**
     * Test getLogger returns Logger instance
     */
    public function testGetLoggerReturnsLoggerInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $logger = $container->getLogger();
        $this->assertInstanceOf(Logger::class, $logger);
    }

    /**
     * Test getSecurity returns Security instance
     */
    public function testGetSecurityReturnsSecurityInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $security = $container->getSecurity();
        $this->assertInstanceOf(Security::class, $security);
    }

    // =========================================================================
    // Service Registration Tests
    // =========================================================================

    /**
     * Test registerService registers a custom service
     */
    public function testRegisterServiceRegistersACustomService(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $customService = new \stdClass();
        $customService->name = 'TestService';

        $container->registerService('TestService', $customService);

        $this->assertTrue($container->hasService('TestService'));
        $this->assertSame($customService, $container->getService('TestService'));
    }

    /**
     * Test registerUtil registers a custom utility
     */
    public function testRegisterUtilRegistersACustomUtility(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $customUtil = new \stdClass();
        $customUtil->name = 'TestUtil';

        $container->registerUtil('TestUtil', $customUtil);

        $this->assertTrue($container->hasUtil('TestUtil'));
        $this->assertSame($customUtil, $container->getUtil('TestUtil'));
    }

    /**
     * Test hasService returns false for unregistered service
     */
    public function testHasServiceReturnsFalseForUnregisteredService(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $this->assertFalse($container->hasService('NonExistentService'));
    }

    /**
     * Test hasUtil returns false for unregistered utility
     */
    public function testHasUtilReturnsFalseForUnregisteredUtility(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $this->assertFalse($container->hasUtil('NonExistentUtil'));
    }

    /**
     * Test getService returns null for unregistered service
     */
    public function testGetServiceReturnsNullForUnregisteredService(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $this->assertNull($container->getService('NonExistentService'));
    }

    /**
     * Test getUtil returns null for unregistered utility
     */
    public function testGetUtilReturnsNullForUnregisteredUtility(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $this->assertNull($container->getUtil('NonExistentUtil'));
    }

    // =========================================================================
    // Clear Services Tests
    // =========================================================================

    /**
     * Test clearServices clears all cached services
     */
    public function testClearServicesClearsAllCachedServices(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        // Register a service
        $customService = new \stdClass();
        $container->registerService('TestService', $customService);

        $this->assertTrue($container->hasService('TestService'));

        // Clear services
        $container->clearServices();

        $this->assertFalse($container->hasService('TestService'));
    }

    /**
     * Test clearUtils clears all cached utilities
     */
    public function testClearUtilsClearsAllCachedUtilities(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        // Get a utility to cache it
        $container->getInputValidator();

        $this->assertTrue($container->hasUtil('InputValidator'));

        // Clear utilities
        $container->clearUtils();

        $this->assertFalse($container->hasUtil('InputValidator'));
    }

    // =========================================================================
    // Service Getter Tests
    // =========================================================================

    /**
     * Test getUtilityContainer returns UtilityServiceContainer
     */
    public function testGetUtilityContainerReturnsUtilityServiceContainer(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $utilityContainer = $container->getUtilityContainer();
        $this->assertInstanceOf(\Eiou\Services\Utilities\UtilityServiceContainer::class, $utilityContainer);
    }

    /**
     * Test getTransactionPayload returns TransactionPayload instance
     */
    public function testGetTransactionPayloadReturnsTransactionPayloadInstance(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $payload = $container->getTransactionPayload();
        $this->assertInstanceOf(\Eiou\Schemas\Payloads\TransactionPayload::class, $payload);
    }

    /**
     * Test getEventDispatcher returns EventDispatcher singleton
     */
    public function testGetEventDispatcherReturnsEventDispatcherSingleton(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $dispatcher1 = $container->getEventDispatcher();
        $dispatcher2 = $container->getEventDispatcher();

        $this->assertInstanceOf(\Eiou\Contracts\EventDispatcherInterface::class, $dispatcher1);
        $this->assertSame($dispatcher1, $dispatcher2);
    }

    /**
     * Test getSyncServiceProxy returns SyncTriggerInterface
     */
    public function testGetSyncServiceProxyReturnsSyncTriggerInterface(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $proxy = $container->getSyncServiceProxy();

        $this->assertInstanceOf(\Eiou\Contracts\SyncTriggerInterface::class, $proxy);
    }

    /**
     * Test getSyncServiceProxy returns same instance on subsequent calls
     */
    public function testGetSyncServiceProxyReturnsSameInstanceOnSubsequentCalls(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $proxy1 = $container->getSyncServiceProxy();
        $proxy2 = $container->getSyncServiceProxy();

        $this->assertSame($proxy1, $proxy2);
    }

    // =========================================================================
    // Service Interface Tests
    // =========================================================================

    /**
     * Test getLockingService returns LockingServiceInterface
     */
    public function testGetLockingServiceReturnsLockingServiceInterface(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $service = $container->getLockingService();

        $this->assertInstanceOf(\Eiou\Contracts\LockingServiceInterface::class, $service);
    }

    /**
     * Test getRateLimiterService returns RateLimiterServiceInterface
     */
    public function testGetRateLimiterServiceReturnsRateLimiterServiceInterface(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        $service = $container->getRateLimiterService();

        $this->assertInstanceOf(\Eiou\Contracts\RateLimiterServiceInterface::class, $service);
    }

    // =========================================================================
    // Wiring Tests
    // =========================================================================

    /**
     * Test wireCircularDependencies does not throw on empty services
     */
    public function testWireCircularDependenciesDoesNotThrowOnEmptyServices(): void
    {
        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        // Should not throw even with no services initialized
        $container->wireCircularDependencies();

        $this->expectNotToPerformAssertions();
    }

    /**
     * Test wireAllServices initializes and wires all services
     *
     * Note: This is effectively an integration test since wireAllServices()
     * triggers service initialization that creates repositories requiring
     * actual database connections. Should be moved to integration tests.
     */
    public function testWireAllServicesInitializesAndWiresAllServices(): void
    {
        // Skip in unit test environment - this test requires a real database
        // because wireAllServices() initializes services that internally create
        // repositories with database connections (e.g., TransactionChainRepository
        // is created directly in TransactionValidationService constructor).
        $this->markTestSkipped(
            'This test requires a real database connection. ' .
            'TransactionValidationService creates TransactionChainRepository internally, ' .
            'bypassing the mock PDO. Move to integration tests or refactor services to ' .
            'use dependency injection for all repository dependencies.'
        );

        $container = ServiceContainer::getInstance($this->mockUserContext, $this->mockPdo);

        // This will initialize all services and wire dependencies
        $container->wireAllServices();

        // Verify key services are now available
        $this->assertTrue($container->hasService('SyncService'));
        $this->assertTrue($container->hasService('ContactService'));
        $this->assertTrue($container->hasService('TransactionService'));
    }
}
