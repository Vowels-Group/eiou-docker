<?php
/**
 * Unit Tests for ApiController P2P Approval Endpoints
 *
 * Tests the REST API P2P approval endpoints:
 * - GET /api/v1/p2p (list pending)
 * - GET /api/v1/p2p/candidates/{hash}
 * - POST /api/v1/p2p/approve
 * - POST /api/v1/p2p/reject
 */

namespace Eiou\Tests\Api;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Api\ApiController;
use Eiou\Core\AppConfig;
use Eiou\Services\ApiAuthService;
use Eiou\Database\ApiKeyRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Services\P2pApprovalService;
use Eiou\Services\ServiceContainer;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Contracts\SendOperationServiceInterface;
use Eiou\Database\RepositoryFactory;
use Eiou\Utils\Logger;
use Eiou\Core\Constants;

#[CoversClass(ApiController::class)]
class ApiControllerP2pTest extends TestCase
{
    private ApiAuthService $mockAuthService;
    private ApiKeyRepository $mockApiKeyRepository;
    private ServiceContainer $mockServices;
    private ?Logger $mockLogger;
    private P2pRepository $mockP2pRepo;
    private Rp2pRepository $mockRp2pRepo;
    private Rp2pCandidateRepository $mockRp2pCandidateRepo;
    private SendOperationServiceInterface $mockSendService;
    private P2pServiceInterface $mockP2pService;
    private P2pApprovalService $mockP2pApprovalService;
    private ApiController $controller;

    protected function setUp(): void
    {
        $this->mockAuthService = $this->createMock(ApiAuthService::class);
        $this->mockApiKeyRepository = $this->createMock(ApiKeyRepository::class);
        $this->mockServices = $this->createMock(ServiceContainer::class);
        $this->mockLogger = $this->createMock(Logger::class);
        $this->mockP2pRepo = $this->createMock(P2pRepository::class);
        $this->mockRp2pRepo = $this->createMock(Rp2pRepository::class);
        $this->mockRp2pCandidateRepo = $this->createMock(Rp2pCandidateRepository::class);
        $this->mockSendService = $this->createMock(SendOperationServiceInterface::class);
        $this->mockP2pService = $this->createMock(P2pServiceInterface::class);
        $this->mockP2pApprovalService = $this->createMock(P2pApprovalService::class);

        $this->mockApiKeyRepository->method('logRequest');

        // Wire service container via RepositoryFactory
        $mockRepoFactory = $this->createMock(RepositoryFactory::class);
        $mockRepoFactory->method('get')
            ->willReturnCallback(function (string $class) {
                return match ($class) {
                    P2pRepository::class => $this->mockP2pRepo,
                    Rp2pRepository::class => $this->mockRp2pRepo,
                    Rp2pCandidateRepository::class => $this->mockRp2pCandidateRepo,
                    default => $this->createMock($class),
                };
            });
        $this->mockServices->method('getRepositoryFactory')
            ->willReturn($mockRepoFactory);
        $this->mockServices->method('getSendOperationService')
            ->willReturn($this->mockSendService);
        $this->mockServices->method('getP2pService')
            ->willReturn($this->mockP2pService);
        $this->mockServices->method('getP2pApprovalService')
            ->willReturn($this->mockP2pApprovalService);

        // ServiceContainer::getAppConfig() returns a final value object;
        // PHPUnit can't auto-double a final class, so the mock would
        // throw "Class AppConfig is declared final and cannot be doubled"
        // on the first call from any handler that touches it.
        $this->mockServices->method('getAppConfig')->willReturn(AppConfig::fromEnvironment());

        $this->controller = new ApiController(
            $this->mockAuthService,
            $this->mockApiKeyRepository,
            $this->mockServices,
            $this->mockLogger
        );
    }

    /**
     * Helper to set up authenticated request with specific permissions
     */
    private function authenticateWith(array $permissions): void
    {
        $this->mockAuthService->method('authenticate')
            ->willReturn([
                'success' => true,
                'key' => ['key_id' => 'test_key', 'permissions' => $permissions]
            ]);

        $this->mockAuthService->method('hasPermission')
            ->willReturnCallback(function ($key, $permission) use ($permissions) {
                return in_array($permission, $permissions);
            });
    }

    // =========================================================================
    // GET /api/v1/p2p (list pending)
    // =========================================================================

    /**
     * Test listing pending P2P returns awaiting transactions
     */
    public function testListPendingP2pReturnsAwaitingTransactions(): void
    {
        $this->authenticateWith(['wallet:read']);

        $this->mockP2pRepo->method('getAwaitingApprovalList')
            ->willReturn([
                [
                    'hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                    'amount' => 1000,
                    'currency' => 'USD',
                    'destination_address' => 'http://bob:8080',
                    'my_fee_amount' => 10,
                    'rp2p_amount' => 1010,
                    'fast' => 1,
                    'created_at' => '2026-02-26 10:00:00',
                ],
            ]);

        $this->mockRp2pCandidateRepo->method('getCandidateCount')
            ->willReturn(0);

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/p2p',
            [],
            '',
            []
        );

        $this->assertTrue($response['success']);
        $this->assertCount(1, $response['data']['transactions']);
        $this->assertEquals('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $response['data']['transactions'][0]['hash']);
        $this->assertEquals(1, $response['data']['count']);
    }

    /**
     * Test listing pending P2P requires read permission
     */
    public function testListPendingP2pRequiresReadPermission(): void
    {
        $this->authenticateWith([]);

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/p2p',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(403, $response['status_code']);
        $this->assertEquals('permission_denied', $response['error']['code']);
    }

    // =========================================================================
    // GET /api/v1/p2p/candidates/{hash}
    // =========================================================================

    /**
     * Test getting P2P candidates returns routes
     */
    public function testGetP2pCandidatesReturnsRoutes(): void
    {
        $this->authenticateWith(['wallet:read']);

        $this->mockP2pRepo->method('getAwaitingApproval')
            ->with('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
            ->willReturn([
                'hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'amount' => 1000,
                'currency' => 'USD',
                'fast' => 0,
                'destination_address' => 'http://bob:8080',
            ]);

        $candidates = [
            [
                'id' => 1,
                'hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'sender_address' => 'http://relay1:8080',
                'amount' => 1020,
                'currency' => 'USD',
                'fee_amount' => 20,
            ],
        ];

        $this->mockRp2pCandidateRepo->method('getCandidatesByHash')
            ->with('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
            ->willReturn($candidates);

        $this->mockRp2pRepo->method('getByHash')
            ->willReturn(null);

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/p2p/candidates/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            [],
            '',
            []
        );

        $this->assertTrue($response['success']);
        $this->assertEquals('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $response['data']['hash']);
        $this->assertCount(1, $response['data']['candidates']);
    }

    /**
     * Test getting P2P candidates returns 404 for invalid hash
     */
    public function testGetP2pCandidatesNotFound(): void
    {
        $this->authenticateWith(['wallet:read']);

        $this->mockP2pRepo->method('getAwaitingApproval')
            ->willReturn(null);

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/p2p/candidates/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            [],
            '',
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(404, $response['status_code']);
        $this->assertEquals('not_found', $response['error']['code']);
    }

    // =========================================================================
    // POST /api/v1/p2p/approve
    // =========================================================================

    /**
     * Approve with explicit candidate_id delegates to P2pApprovalService::approve()
     * with the candidate id (and a null candidate index — that's CLI vocabulary).
     */
    public function testApproveP2pWithCandidateId(): void
    {
        $this->authenticateWith(['wallet:send']);

        $hash = str_repeat('a', 64);
        $this->mockP2pApprovalService->expects($this->once())
            ->method('approve')
            ->with($hash, null, 5)
            ->willReturn([
                'success' => true,
                'hash' => $hash,
                'mode' => 'candidate',
                'candidate_index' => null,
                'sender_address' => 'http://relay1:8080',
            ]);

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/p2p/approve',
            [],
            json_encode(['hash' => $hash, 'candidate_id' => 5]),
            []
        );

        $this->assertTrue($response['success']);
        $this->assertEquals($hash, $response['data']['hash']);
        $this->assertEquals(5, $response['data']['candidate_id']);
        $this->assertSame('P2P transaction approved and sent', $response['data']['message']);
    }

    /**
     * Approve without candidate_id delegates with both selectors null;
     * when the service reports `mode=fast` the response message gets the
     * "(fast mode)" suffix and no candidate_id field is added.
     */
    public function testApproveP2pWithoutCandidateIdFastMode(): void
    {
        $this->authenticateWith(['wallet:send']);

        $hash = str_repeat('a', 64);
        $this->mockP2pApprovalService->expects($this->once())
            ->method('approve')
            ->with($hash, null, null)
            ->willReturn([
                'success' => true,
                'hash' => $hash,
                'mode' => 'fast',
                'candidate_index' => null,
                'sender_address' => 'http://relay1:8080',
            ]);

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/p2p/approve',
            [],
            json_encode(['hash' => $hash]),
            []
        );

        $this->assertTrue($response['success']);
        $this->assertStringContainsString('fast mode', $response['data']['message']);
        $this->assertArrayNotHasKey('candidate_id', $response['data']);
    }

    /**
     * When the service reports candidate_selection_required the controller
     * surfaces the structured error (status + code + message) without
     * inventing its own response shape.
     */
    public function testApproveP2pMultipleCandidatesNoCandidateId(): void
    {
        $this->authenticateWith(['wallet:send']);

        $hash = str_repeat('a', 64);
        $this->mockP2pApprovalService->expects($this->once())
            ->method('approve')
            ->with($hash, null, null)
            ->willReturn([
                'success' => false,
                'code' => 'candidate_selection_required',
                'message' => 'Multiple candidates available; please pick one with candidate_id.',
                'status' => 400,
            ]);

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/p2p/approve',
            [],
            json_encode(['hash' => $hash]),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('candidate_selection_required', $response['error']['code']);
    }

    /**
     * Test approving P2P requires send permission
     */
    public function testApproveP2pRequiresSendPermission(): void
    {
        $this->authenticateWith(['wallet:read']);

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/p2p/approve',
            [],
            json_encode(['hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(403, $response['status_code']);
        $this->assertEquals('permission_denied', $response['error']['code']);
    }

    // =========================================================================
    // POST /api/v1/p2p/reject
    // =========================================================================

    /**
     * Reject delegates to P2pApprovalService::reject() and surfaces the
     * canonical "rejected and cancelled" message + hash on success.
     */
    public function testRejectP2pCancelsAndPropagates(): void
    {
        $this->authenticateWith(['wallet:send']);

        $hash = str_repeat('a', 64);
        $this->mockP2pApprovalService->expects($this->once())
            ->method('reject')
            ->with($hash)
            ->willReturn([
                'success' => true,
                'hash' => $hash,
            ]);

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/p2p/reject',
            [],
            json_encode(['hash' => $hash]),
            []
        );

        $this->assertTrue($response['success']);
        $this->assertEquals($hash, $response['data']['hash']);
        $this->assertSame('P2P transaction rejected and cancelled', $response['data']['message']);
    }

    /**
     * Test rejecting P2P requires send permission
     */
    public function testRejectP2pRequiresSendPermission(): void
    {
        $this->authenticateWith(['wallet:read']);

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/p2p/reject',
            [],
            json_encode(['hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(403, $response['status_code']);
        $this->assertEquals('permission_denied', $response['error']['code']);
    }
}
