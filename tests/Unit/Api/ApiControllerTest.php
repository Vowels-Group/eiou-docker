<?php
/**
 * Unit Tests for ApiController
 *
 * Tests the REST API controller for handling external application integration.
 * Uses mocked dependencies to test routing, authentication, and response handling.
 */

namespace Eiou\Tests\Api;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Eiou\Api\ApiController;
use Eiou\Services\ApiAuthService;
use Eiou\Database\ApiKeyRepository;
use Eiou\Services\ServiceContainer;
use Eiou\Utils\Logger;
use Eiou\Core\Constants;
use Eiou\Exceptions\FatalServiceException;
use Eiou\Contracts\TransactionServiceInterface;
use Eiou\Database\RepositoryFactory;

#[CoversClass(ApiController::class)]
class ApiControllerTest extends TestCase
{
    private ApiAuthService $mockAuthService;
    private ApiKeyRepository $mockApiKeyRepository;
    private ServiceContainer $mockServices;
    private ?Logger $mockLogger;
    private ApiController $controller;

    /** @var array<class-string, object> Repository mocks keyed by class name */
    private array $repoMocks = [];

    protected function setUp(): void
    {
        $this->repoMocks = [];
        $this->mockAuthService = $this->createMock(ApiAuthService::class);
        $this->mockApiKeyRepository = $this->createMock(ApiKeyRepository::class);
        $this->mockServices = $this->createMock(ServiceContainer::class);
        $this->mockLogger = $this->createMock(Logger::class);

        // Wire RepositoryFactory mock to return per-test repo mocks
        $mockRepoFactory = $this->createMock(RepositoryFactory::class);
        $mockRepoFactory->method('get')
            ->willReturnCallback(function (string $class) {
                return $this->repoMocks[$class] ?? $this->createMock($class);
            });
        $this->mockServices->method('getRepositoryFactory')
            ->willReturn($mockRepoFactory);

        $this->controller = new ApiController(
            $this->mockAuthService,
            $this->mockApiKeyRepository,
            $this->mockServices,
            $this->mockLogger
        );
    }

    /**
     * Test constructor sets dependencies
     */
    public function testConstructorSetsDependencies(): void
    {
        $controller = new ApiController(
            $this->mockAuthService,
            $this->mockApiKeyRepository,
            $this->mockServices,
            $this->mockLogger
        );

        $this->assertInstanceOf(ApiController::class, $controller);
    }

    /**
     * Test constructor works without optional logger
     */
    public function testConstructorWorksWithoutLogger(): void
    {
        $controller = new ApiController(
            $this->mockAuthService,
            $this->mockApiKeyRepository,
            $this->mockServices,
            null
        );

        $this->assertInstanceOf(ApiController::class, $controller);
    }

    /**
     * Test handleRequest returns 401 on authentication failure
     */
    public function testHandleRequestReturns401OnAuthFailure(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => false,
                'error' => 'Authentication failed',
                'code' => 'auth_failed'
            ]);

        $this->mockApiKeyRepository->expects($this->once())
            ->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/wallet/balance',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(401, $response['status_code']);
        $this->assertEquals('Authentication failed', $response['error']['message']);
    }

    /**
     * Test handleRequest returns 404 for invalid API path
     */
    public function testHandleRequestReturns404ForInvalidPath(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/invalid/path',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(404, $response['status_code']);
        $this->assertEquals('invalid_path', $response['error']['code']);
    }

    /**
     * Test handleRequest returns 404 for missing v1 prefix
     */
    public function testHandleRequestReturns404ForMissingV1Prefix(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:read']]
            ]);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v2/wallet/balance',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(404, $response['status_code']);
    }

    /**
     * Test handleRequest returns 404 for unknown resource
     */
    public function testHandleRequestReturns404ForUnknownResource(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:read']]
            ]);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/unknown/action',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(404, $response['status_code']);
        $this->assertEquals('unknown_resource', $response['error']['code']);
    }

    /**
     * Test handleRequest returns 403 for permission denied
     */
    public function testHandleRequestReturns403ForPermissionDenied(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/wallet/balance',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(403, $response['status_code']);
        $this->assertEquals('permission_denied', $response['error']['code']);
    }

    /**
     * Test handleRequest handles ServiceException properly
     */
    public function testHandleRequestHandlesServiceException(): void
    {
        // Create a fresh mock auth service for this test
        $mockAuth = $this->createMock(ApiAuthService::class);
        $mockAuth->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:read']]
            ]);
        $mockAuth->method('hasPermission')
            ->willReturn(true);

        // Create mock services with a balance repo that throws
        $mockServices = $this->createMock(ServiceContainer::class);
        $mockBalanceRepo = $this->createMock(\Eiou\Database\BalanceRepository::class);
        $mockBalanceRepo->method('getAllBalances')
            ->willThrowException(new FatalServiceException('Database error', 'DB_ERROR', [], 500));

        $mockRepoFactory = $this->createMock(RepositoryFactory::class);
        $mockRepoFactory->method('get')
            ->willReturnCallback(function (string $class) use ($mockBalanceRepo) {
                if ($class === \Eiou\Database\BalanceRepository::class) {
                    return $mockBalanceRepo;
                }
                return $this->createMock($class);
            });
        $mockServices->method('getRepositoryFactory')
            ->willReturn($mockRepoFactory);

        $mockApiKeyRepo = $this->createMock(ApiKeyRepository::class);
        $mockApiKeyRepo->method('logRequest');

        // Create controller WITHOUT logger
        $controller = new ApiController(
            $mockAuth,
            $mockApiKeyRepo,
            $mockServices,
            null  // No logger provided
        );

        $response = $controller->handleRequest(
            'GET',
            '/api/v1/wallet/balance',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(500, $response['status_code']);
    }

    /**
     * Test handleRequest includes request_id in response
     */
    public function testHandleRequestIncludesRequestId(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => false,
                'error' => 'Authentication failed',
                'code' => 'auth_failed'
            ]);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/wallet/balance',
            [],
            '',
            []
        );

        $this->assertArrayHasKey('request_id', $response);
        $this->assertStringStartsWith('req_', $response['request_id']);
    }

    /**
     * Test handleRequest includes timestamp in response
     */
    public function testHandleRequestIncludesTimestamp(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => false,
                'error' => 'Authentication failed',
                'code' => 'auth_failed'
            ]);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/wallet/balance',
            [],
            '',
            []
        );

        $this->assertArrayHasKey('timestamp', $response);
    }

    /**
     * Test wallet balance endpoint requires wallet:read permission
     */
    public function testWalletBalanceRequiresPermission(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->with($this->anything(), 'wallet:read')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/wallet/balance',
            [],
            '',
            []
        );

        $this->assertEquals(403, $response['status_code']);
        $this->assertStringContainsString('wallet:read', $response['error']['message']);
    }

    /**
     * Test contacts list endpoint requires contacts:read permission
     */
    public function testContactsListRequiresPermission(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/contacts',
            [],
            '',
            []
        );

        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Test system status endpoint requires system:read permission
     */
    public function testSystemStatusRequiresPermission(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/system/status',
            [],
            '',
            []
        );

        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Test keys management requires admin permission
     */
    public function testKeysManagementRequiresAdminPermission(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturnCallback(function ($key, $permission) {
                return $permission !== 'admin';
            });

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/keys',
            [],
            '',
            []
        );

        $this->assertEquals(403, $response['status_code']);
        $this->assertStringContainsString('Admin permission required', $response['error']['message']);
    }

    /**
     * Test send transaction returns 400 for invalid JSON
     */
    public function testSendTransactionReturns400ForInvalidJson(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:send']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/wallet/send',
            [],
            'invalid json',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('invalid_json', $response['error']['code']);
    }

    /**
     * Test send transaction returns 400 for missing required fields
     */
    public function testSendTransactionReturns400ForMissingFields(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:send']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/wallet/send',
            [],
            json_encode(['address' => 'test']),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('missing_field', $response['error']['code']);
    }

    /**
     * Test send transaction returns 400 for invalid amount
     */
    public function testSendTransactionReturns400ForInvalidAmount(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:send']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/wallet/send',
            [],
            json_encode(['address' => 'test', 'amount' => -10, 'currency' => 'USD']),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('invalid_amount', $response['error']['code']);
    }

    /**
     * Test send transaction passes --best flag when best_fee is true
     */
    public function testSendTransactionPassesBestFlagWhenBestFeeTrue(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:send']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $mockTransactionService = $this->createMock(TransactionServiceInterface::class);
        $mockTransactionService->expects($this->once())
            ->method('sendEiou')
            ->with($this->callback(function (array $argv) {
                return in_array('--best', $argv, true)
                    && in_array('--json', $argv, true);
            }));

        $this->mockServices->method('getTransactionService')
            ->willReturn($mockTransactionService);

        $this->controller->handleRequest(
            'POST',
            '/api/v1/wallet/send',
            [],
            json_encode([
                'address' => 'test_address',
                'amount' => 10,
                'currency' => 'USD',
                'best_fee' => true
            ]),
            []
        );
    }

    /**
     * Test send transaction does not pass --best flag when best_fee is absent
     */
    public function testSendTransactionOmitsBestFlagByDefault(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:send']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $mockTransactionService = $this->createMock(TransactionServiceInterface::class);
        $mockTransactionService->expects($this->once())
            ->method('sendEiou')
            ->with($this->callback(function (array $argv) {
                return !in_array('--best', $argv, true)
                    && in_array('--json', $argv, true);
            }));

        $this->mockServices->method('getTransactionService')
            ->willReturn($mockTransactionService);

        $this->controller->handleRequest(
            'POST',
            '/api/v1/wallet/send',
            [],
            json_encode([
                'address' => 'test_address',
                'amount' => 10,
                'currency' => 'USD'
            ]),
            []
        );
    }

    /**
     * Test add contact returns 400 for invalid JSON
     */
    public function testAddContactReturns400ForInvalidJson(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['contacts:write']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts',
            [],
            'invalid json',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('invalid_json', $response['error']['code']);
    }

    /**
     * Test add contact returns 400 for missing fields
     */
    public function testAddContactReturns400ForMissingFields(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['contacts:write']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts',
            [],
            json_encode(['address' => 'test']),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('missing_field', $response['error']['code']);
    }

    /**
     * Test add contact returns 400 for invalid requested_credit_limit
     */
    public function testAddContactReturns400ForInvalidRequestedCreditLimit(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['contacts:write']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts',
            [],
            json_encode([
                'address' => 'http://bob:8080',
                'name' => 'Bob',
                'fee_percent' => 1,
                'credit_limit' => 100,
                'currency' => 'USD',
                'requested_credit_limit' => -50  // Invalid: negative
            ]),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('invalid_requested_credit', $response['error']['code']);
    }

    /**
     * Test backup restore requires confirmation
     */
    public function testBackupRestoreRequiresConfirmation(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['backup:write']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/backup/restore',
            [],
            json_encode(['filename' => 'backup.sql']),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('confirmation_required', $response['error']['code']);
    }

    /**
     * Test backup verify returns 400 for missing filename
     */
    public function testBackupVerifyReturns400ForMissingFilename(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['backup:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/backup/verify',
            [],
            json_encode([]),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('missing_field', $response['error']['code']);
    }

    /**
     * Test create API key returns 400 for missing name
     */
    public function testCreateApiKeyReturns400ForMissingName(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['admin']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/keys',
            [],
            json_encode([]),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('missing_field', $response['error']['code']);
    }

    /**
     * Data provider for valid wallet endpoints
     */
    public static function walletEndpointsProvider(): array
    {
        return [
            'balance GET' => ['GET', 'balance', 'wallet:read'],
            'balances GET' => ['GET', 'balances', 'wallet:read'],
            'transactions GET' => ['GET', 'transactions', 'wallet:read'],
            'info GET' => ['GET', 'info', 'wallet:read'],
            'overview GET' => ['GET', 'overview', 'wallet:read'],
            'send POST' => ['POST', 'send', 'wallet:send'],
        ];
    }

    /**
     * Test wallet endpoints routing
     */
    #[DataProvider('walletEndpointsProvider')]
    public function testWalletEndpointsRouting(string $method, string $action, string $permission): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            $method,
            '/api/v1/wallet/' . $action,
            [],
            $method === 'POST' ? '{}' : '',
            []
        );

        // Should get permission denied, meaning routing worked correctly
        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Data provider for contacts endpoints
     */
    public static function contactsEndpointsProvider(): array
    {
        return [
            'list GET' => ['GET', '', 'contacts:read'],
            'pending GET' => ['GET', 'pending', 'contacts:read'],
            'search GET' => ['GET', 'search', 'contacts:read'],
            'add POST' => ['POST', '', 'contacts:write'],
        ];
    }

    /**
     * Test contacts endpoints routing
     */
    #[DataProvider('contactsEndpointsProvider')]
    public function testContactsEndpointsRouting(string $method, string $action, string $permission): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $path = '/api/v1/contacts' . ($action ? '/' . $action : '');
        $response = $this->controller->handleRequest(
            $method,
            $path,
            [],
            $method === 'POST' ? '{}' : '',
            []
        );

        // Should get permission denied, meaning routing worked correctly
        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Data provider for system endpoints
     */
    public static function systemEndpointsProvider(): array
    {
        return [
            'status GET' => ['GET', 'status'],
            'metrics GET' => ['GET', 'metrics'],
            'settings GET' => ['GET', 'settings'],
        ];
    }

    /**
     * Test system endpoints routing
     */
    #[DataProvider('systemEndpointsProvider')]
    public function testSystemEndpointsRouting(string $method, string $action): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            $method,
            '/api/v1/system/' . $action,
            [],
            '',
            []
        );

        // Should get permission denied, meaning routing worked correctly
        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Data provider for backup endpoints
     */
    public static function backupEndpointsProvider(): array
    {
        return [
            'status GET' => ['GET', 'status'],
            'list GET' => ['GET', 'list'],
            'create POST' => ['POST', 'create'],
            'restore POST' => ['POST', 'restore'],
            'verify POST' => ['POST', 'verify'],
            'enable POST' => ['POST', 'enable'],
            'disable POST' => ['POST', 'disable'],
            'cleanup POST' => ['POST', 'cleanup'],
        ];
    }

    /**
     * Test backup endpoints routing
     */
    #[DataProvider('backupEndpointsProvider')]
    public function testBackupEndpointsRouting(string $method, string $action): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            $method,
            '/api/v1/backup/' . $action,
            [],
            $method === 'POST' ? '{}' : '',
            []
        );

        // Should get permission denied, meaning routing worked correctly
        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Test unknown wallet action returns 404
     */
    public function testUnknownWalletActionReturns404(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/wallet/unknownaction',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(404, $response['status_code']);
        $this->assertEquals('unknown_action', $response['error']['code']);
    }

    /**
     * Test unknown system action returns 404
     */
    public function testUnknownSystemActionReturns404(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['system:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/system/unknownaction',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(404, $response['status_code']);
        $this->assertEquals('unknown_action', $response['error']['code']);
    }

    /**
     * Test unknown backup action returns 404
     */
    public function testUnknownBackupActionReturns404(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['backup:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/backup/unknownaction',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(404, $response['status_code']);
        $this->assertEquals('unknown_action', $response['error']['code']);
    }

    /**
     * Test response structure for success
     */
    public function testResponseStructureForSuccess(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        // Mock repositories
        $mockBalanceRepo = $this->createMock(\Eiou\Database\BalanceRepository::class);
        $mockBalanceRepo->method('getAllBalances')->willReturn([]);

        $mockContactRepo = $this->createMock(\Eiou\Database\ContactRepository::class);

        $this->repoMocks[\Eiou\Database\BalanceRepository::class] = $mockBalanceRepo;
        $this->repoMocks[\Eiou\Database\ContactRepository::class] = $mockContactRepo;

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/wallet/balance',
            [],
            '',
            []
        );

        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('error', $response);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertArrayHasKey('request_id', $response);
        $this->assertArrayHasKey('status_code', $response);
    }

    /**
     * Test response structure for error
     */
    public function testResponseStructureForError(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => false,
                'error' => 'Test error',
                'code' => 'test_error'
            ]);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/wallet/balance',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertNull($response['data']);
        $this->assertIsArray($response['error']);
        $this->assertArrayHasKey('message', $response['error']);
        $this->assertArrayHasKey('code', $response['error']);
    }

    /**
     * Test request ID format
     */
    public function testRequestIdFormat(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => false,
                'error' => 'Test error',
                'code' => 'test_error'
            ]);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/wallet/balance',
            [],
            '',
            []
        );

        // Request ID should start with 'req_' and be 20 characters (req_ + 16 hex chars)
        $this->assertStringStartsWith('req_', $response['request_id']);
        $this->assertEquals(20, strlen($response['request_id']));
    }

    // ==================== API Key Enable/Disable Tests ====================

    /**
     * Test enable API key succeeds
     */
    public function testEnableApiKeySucceeds(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['admin']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('enableKey')
            ->with('key123')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/keys/enable/key123',
            [],
            '',
            []
        );

        $this->assertTrue($response['success']);
        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('key123', $response['data']['key_id']);
    }

    /**
     * Test enable API key returns 404 for unknown key
     */
    public function testEnableApiKeyReturns404ForUnknownKey(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['admin']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('enableKey')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/keys/enable/unknown_key',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(404, $response['status_code']);
        $this->assertEquals('key_not_found', $response['error']['code']);
    }

    /**
     * Test disable API key succeeds
     */
    public function testDisableApiKeySucceeds(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['admin']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('disableKey')
            ->with('key123')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/keys/disable/key123',
            [],
            '',
            []
        );

        $this->assertTrue($response['success']);
        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('key123', $response['data']['key_id']);
    }

    /**
     * Test disable API key returns 404 for unknown key
     */
    public function testDisableApiKeyReturns404ForUnknownKey(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['admin']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('disableKey')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/keys/disable/unknown_key',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(404, $response['status_code']);
        $this->assertEquals('key_not_found', $response['error']['code']);
    }

    /**
     * Test enable/disable API key requires admin permission
     */
    public function testEnableDisableApiKeyRequiresAdmin(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturnCallback(function ($key, $permission) {
                return $permission !== 'admin';
            });

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/keys/enable/key123',
            [],
            '',
            []
        );

        $this->assertEquals(403, $response['status_code']);
    }

    // ==================== Wallet Info Enhancement Tests ====================

    /**
     * Test wallet info includes fee earnings and available credit
     */
    public function testWalletInfoIncludesFeeEarningsAndCredit(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        // Mock CurrentUser
        $mockCurrentUser = $this->createMock(\Eiou\Core\UserContext::class);
        $mockCurrentUser->method('getPublicKeyHash')->willReturn('abc123');
        $mockCurrentUser->method('getUserLocaters')->willReturn(['http' => 'http://alice']);

        // Mock AddressRepository
        $mockAddressRepo = $this->createMock(\Eiou\Database\AddressRepository::class);
        $mockAddressRepo->method('getAllAddressTypes')->willReturn(['http', 'https', 'tor']);

        // Mock P2pRepository
        $mockP2pRepo = $this->createMock(\Eiou\Database\P2pRepository::class);
        $mockP2pRepo->method('getUserTotalEarningsByCurrency')->willReturn([
            ['currency' => 'USD', 'total_amount' => 500]
        ]);

        // Mock ContactCreditRepository
        $mockCreditRepo = $this->createMock(\Eiou\Database\ContactCreditRepository::class);
        $mockCreditRepo->method('getTotalAvailableCreditByCurrency')->willReturn([
            ['currency' => 'USD', 'total_available_credit' => 10000]
        ]);

        $this->mockServices->method('getCurrentUser')->willReturn($mockCurrentUser);
        $this->repoMocks[\Eiou\Database\AddressRepository::class] = $mockAddressRepo;
        $this->repoMocks[\Eiou\Database\P2pRepository::class] = $mockP2pRepo;
        $this->repoMocks[\Eiou\Database\ContactCreditRepository::class] = $mockCreditRepo;

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/wallet/info',
            [],
            '',
            []
        );

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('fee_earnings', $response['data']);
        $this->assertArrayHasKey('available_credit', $response['data']);
        $this->assertNotEmpty($response['data']['fee_earnings']);
        $this->assertNotEmpty($response['data']['available_credit']);
    }

    // ==================== Transaction Contact Filter Tests ====================

    /**
     * Test transactions with contact filter returns 404 for unknown contact
     */
    public function testTransactionsContactFilterReturns404ForUnknownContact(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $mockContactRepo = $this->createMock(\Eiou\Database\ContactRepository::class);
        $mockContactRepo->method('lookupByName')->willReturn(null);
        $mockContactRepo->method('lookupByAddress')->willReturn(null);

        $mockAddressRepo = $this->createMock(\Eiou\Database\AddressRepository::class);
        $mockAddressRepo->method('getAllAddressTypes')->willReturn(['http', 'https', 'tor']);

        $this->repoMocks[\Eiou\Database\ContactRepository::class] = $mockContactRepo;
        $this->repoMocks[\Eiou\Database\AddressRepository::class] = $mockAddressRepo;
        // TransactionRepository and TransactionStatisticsRepository not needed due to early return

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/wallet/transactions',
            ['contact' => 'nonexistent'],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(404, $response['status_code']);
        $this->assertEquals('contact_not_found', $response['error']['code']);
    }

    // ==================== System Shutdown/Start Tests ====================

    /**
     * Test shutdown requires admin permission
     */
    public function testShutdownRequiresAdminPermission(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/system/shutdown',
            [],
            '',
            []
        );

        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Test start requires admin permission
     */
    public function testStartRequiresAdminPermission(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/system/start',
            [],
            '',
            []
        );

        $this->assertEquals(403, $response['status_code']);
    }

    // ==================== Sync Tests ====================

    /**
     * Test sync requires admin permission
     */
    public function testSyncRequiresAdminPermission(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/system/sync',
            [],
            '',
            []
        );

        $this->assertEquals(403, $response['status_code']);
    }

    // ==================== Settings Update Tests ====================

    /**
     * Test settings update requires admin permission
     */
    public function testSettingsUpdateRequiresAdminPermission(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'PUT',
            '/api/v1/system/settings',
            [],
            json_encode(['default_fee' => 2.5]),
            []
        );

        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Test settings update returns 400 for invalid JSON
     */
    public function testSettingsUpdateReturns400ForInvalidJson(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['admin']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'PUT',
            '/api/v1/system/settings',
            [],
            'invalid json',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('invalid_json', $response['error']['code']);
    }

    /**
     * Test settings update returns error for unknown setting
     */
    public function testSettingsUpdateReturnsErrorForUnknownSetting(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['admin']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'PUT',
            '/api/v1/system/settings',
            [],
            json_encode(['nonexistent_setting' => 'value']),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('validation_error', $response['error']['code']);
    }

    // ==================== Chain Drop Tests ====================

    /**
     * Test chain drop list requires wallet:read permission
     */
    public function testChainDropListRequiresPermission(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/chaindrop',
            [],
            '',
            []
        );

        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Test chain drop propose requires wallet:send permission
     */
    public function testChainDropProposeRequiresPermission(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/chaindrop/propose',
            [],
            json_encode(['contact' => 'bob']),
            []
        );

        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Test chain drop accept requires wallet:send permission
     */
    public function testChainDropAcceptRequiresPermission(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/chaindrop/accept',
            [],
            json_encode(['proposal_id' => 'prop123']),
            []
        );

        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Test chain drop reject requires wallet:send permission
     */
    public function testChainDropRejectRequiresPermission(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/chaindrop/reject',
            [],
            json_encode(['proposal_id' => 'prop123']),
            []
        );

        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Test chain drop propose returns 400 for invalid JSON
     */
    public function testChainDropProposeReturns400ForInvalidJson(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:send']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/chaindrop/propose',
            [],
            'invalid json',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('invalid_json', $response['error']['code']);
    }

    /**
     * Test chain drop propose returns 400 for missing contact field
     */
    public function testChainDropProposeReturns400ForMissingContact(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:send']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/chaindrop/propose',
            [],
            json_encode(['some_other_field' => 'value']),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('missing_field', $response['error']['code']);
    }

    /**
     * Test chain drop accept returns 400 for missing proposal_id
     */
    public function testChainDropAcceptReturns400ForMissingProposalId(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:send']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/chaindrop/accept',
            [],
            json_encode(['some_field' => 'value']),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('missing_field', $response['error']['code']);
    }

    /**
     * Test chain drop reject returns 400 for missing proposal_id
     */
    public function testChainDropRejectReturns400ForMissingProposalId(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:send']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/chaindrop/reject',
            [],
            json_encode(['some_field' => 'value']),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('missing_field', $response['error']['code']);
    }

    /**
     * Test unknown chaindrop action returns 404
     */
    public function testUnknownChainDropActionReturns404(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/chaindrop/unknownaction',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(404, $response['status_code']);
        $this->assertEquals('unknown_action', $response['error']['code']);
    }

    /**
     * Test chain drop list succeeds
     */
    public function testChainDropListSucceeds(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['wallet:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $mockChainDropService = $this->createMock(\Eiou\Services\ChainDropService::class);
        $mockChainDropService->method('getIncomingPendingProposals')->willReturn([]);

        $this->mockServices->method('getChainDropService')->willReturn($mockChainDropService);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/chaindrop',
            [],
            '',
            []
        );

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('proposals', $response['data']);
        $this->assertArrayHasKey('count', $response['data']);
    }

    // ==================== New Routing Data Providers ====================

    /**
     * Data provider for new system endpoints
     */
    public static function newSystemEndpointsProvider(): array
    {
        return [
            'settings PUT' => ['PUT', 'settings'],
            'sync POST' => ['POST', 'sync'],
            'shutdown POST' => ['POST', 'shutdown'],
            'start POST' => ['POST', 'start'],
        ];
    }

    /**
     * Test new system endpoints routing (permission denied = route found)
     */
    #[DataProvider('newSystemEndpointsProvider')]
    public function testNewSystemEndpointsRouting(string $method, string $action): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            $method,
            '/api/v1/system/' . $action,
            [],
            $method === 'PUT' || $method === 'POST' ? '{}' : '',
            []
        );

        // Should get permission denied, meaning routing worked correctly
        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Data provider for chaindrop endpoints
     */
    public static function chainDropEndpointsProvider(): array
    {
        return [
            'list GET' => ['GET', ''],
            'propose POST' => ['POST', 'propose'],
            'accept POST' => ['POST', 'accept'],
            'reject POST' => ['POST', 'reject'],
        ];
    }

    /**
     * Test chaindrop endpoints routing
     */
    #[DataProvider('chainDropEndpointsProvider')]
    public function testChainDropEndpointsRouting(string $method, string $action): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $path = '/api/v1/chaindrop' . ($action ? '/' . $action : '');
        $response = $this->controller->handleRequest(
            $method,
            $path,
            [],
            $method === 'POST' ? '{}' : '',
            []
        );

        // Should get permission denied, meaning routing worked correctly
        $this->assertEquals(403, $response['status_code']);
    }

    // ==================== Debug Report Endpoint Tests ====================

    /**
     * Test GET debug-report requires system:read permission
     */
    public function testGetDebugReportRequiresPermission(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/system/debug-report',
            [],
            '',
            []
        );

        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Test POST debug-report requires system:read permission
     */
    public function testSubmitDebugReportRequiresPermission(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(false);

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/system/debug-report',
            [],
            json_encode(['description' => 'test']),
            []
        );

        $this->assertEquals(403, $response['status_code']);
    }

    /**
     * Test GET debug-report returns report data on success
     */
    public function testGetDebugReportReturnsReportData(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['system:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $mockReport = [
            'description' => 'test issue',
            'system_info' => ['php_version' => '8.1'],
            'debug_entries' => [],
            'debug_entries_count' => 0,
            'php_errors' => '',
            'nginx_errors' => '',
            'eiou_app_log' => '',
            'report_type' => 'limited',
        ];

        $mockReportService = $this->createMock(\Eiou\Services\DebugReportService::class);
        $mockReportService->method('generateReport')
            ->willReturn($mockReport);

        $this->mockServices->method('getDebugReportService')
            ->willReturn($mockReportService);

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/system/debug-report',
            ['description' => 'test issue'],
            '',
            []
        );

        $this->assertTrue($response['success']);
        $this->assertEquals(200, $response['status_code']);
        $this->assertArrayHasKey('report', $response['data']);
        $this->assertEquals('limited', $response['data']['report_type']);
        $this->assertEquals(0, $response['data']['debug_entries_count']);
    }

    /**
     * Test GET debug-report passes full parameter
     */
    public function testGetDebugReportPassesFullParam(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['system:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $mockReportService = $this->createMock(\Eiou\Services\DebugReportService::class);
        $mockReportService->expects($this->once())
            ->method('generateReport')
            ->with('', true)
            ->willReturn([
                'description' => '',
                'system_info' => [],
                'debug_entries' => [],
                'debug_entries_count' => 0,
                'php_errors' => '',
                'nginx_errors' => '',
                'eiou_app_log' => '',
                'report_type' => 'full',
            ]);

        $this->mockServices->method('getDebugReportService')
            ->willReturn($mockReportService);

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/system/debug-report',
            ['full' => '1'],
            '',
            []
        );

        $this->assertTrue($response['success']);
        $this->assertEquals('full', $response['data']['report_type']);
    }

    /**
     * Test GET debug-report handles service exception
     */
    public function testGetDebugReportHandlesException(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['system:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $mockReportService = $this->createMock(\Eiou\Services\DebugReportService::class);
        $mockReportService->method('generateReport')
            ->willThrowException(new \Exception('Database error'));

        $this->mockServices->method('getDebugReportService')
            ->willReturn($mockReportService);

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/system/debug-report',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(500, $response['status_code']);
        $this->assertEquals('debug_report_error', $response['error']['code']);
    }

    /**
     * Test POST debug-report routes correctly with valid body
     */
    public function testSubmitDebugReportRoutesCorrectly(): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => ['system:read']]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturn(true);

        $this->mockApiKeyRepository->method('logRequest');

        $mockReportService = $this->createMock(\Eiou\Services\DebugReportService::class);
        $mockReportService->expects($this->once())
            ->method('generateReport')
            ->with('test issue', false)
            ->willReturn([
                'description' => 'test issue',
                'system_info' => [],
                'debug_entries' => [],
                'debug_entries_count' => 0,
                'php_errors' => '',
                'nginx_errors' => '',
                'eiou_app_log' => '',
                'report_type' => 'limited',
            ]);

        $this->mockServices->method('getDebugReportService')
            ->willReturn($mockReportService);

        // submit() is static and will fail (no Tor), but we verify routing
        // reaches generateReport() — the static call is an integration concern
        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/system/debug-report',
            [],
            json_encode(['description' => 'test issue']),
            []
        );

        // Will return 502 because submit() can't reach Tor in unit tests,
        // but the key assertion is that generateReport was called (expects once)
        $this->assertIsArray($response);
    }

    /**
     * Data provider for key enable/disable endpoints
     */
    public static function keyEnableDisableEndpointsProvider(): array
    {
        return [
            'enable POST' => ['POST', 'enable/testkey'],
            'disable POST' => ['POST', 'disable/testkey'],
        ];
    }

    /**
     * Test key enable/disable endpoints routing
     */
    #[DataProvider('keyEnableDisableEndpointsProvider')]
    public function testKeyEnableDisableEndpointsRouting(string $method, string $action): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => []]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturnCallback(function ($key, $permission) {
                return $permission !== 'admin';
            });

        $this->mockApiKeyRepository->method('logRequest');

        $response = $this->controller->handleRequest(
            $method,
            '/api/v1/keys/' . $action,
            [],
            '',
            []
        );

        // Should get permission denied for admin, meaning routing worked correctly
        $this->assertEquals(403, $response['status_code']);
    }
}
