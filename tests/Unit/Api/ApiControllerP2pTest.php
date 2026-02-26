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
use Eiou\Services\ApiAuthService;
use Eiou\Database\ApiKeyRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Services\ServiceContainer;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Contracts\SendOperationServiceInterface;
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

        $this->mockApiKeyRepository->method('logRequest');

        // Wire service container
        $this->mockServices->method('getP2pRepository')
            ->willReturn($this->mockP2pRepo);
        $this->mockServices->method('getRp2pRepository')
            ->willReturn($this->mockRp2pRepo);
        $this->mockServices->method('getRp2pCandidateRepository')
            ->willReturn($this->mockRp2pCandidateRepo);
        $this->mockServices->method('getSendOperationService')
            ->willReturn($this->mockSendService);
        $this->mockServices->method('getP2pService')
            ->willReturn($this->mockP2pService);

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
                    'hash' => 'abc123',
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
        $this->assertEquals('abc123', $response['data']['transactions'][0]['hash']);
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
            ->with('abc123')
            ->willReturn([
                'hash' => 'abc123',
                'amount' => 1000,
                'currency' => 'USD',
                'fast' => 0,
                'destination_address' => 'http://bob:8080',
            ]);

        $candidates = [
            [
                'id' => 1,
                'hash' => 'abc123',
                'sender_address' => 'http://relay1:8080',
                'amount' => 1020,
                'currency' => 'USD',
                'fee_amount' => 20,
            ],
        ];

        $this->mockRp2pCandidateRepo->method('getCandidatesByHash')
            ->with('abc123')
            ->willReturn($candidates);

        $this->mockRp2pRepo->method('getByHash')
            ->willReturn(null);

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/p2p/candidates/abc123',
            [],
            '',
            []
        );

        $this->assertTrue($response['success']);
        $this->assertEquals('abc123', $response['data']['hash']);
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
            '/api/v1/p2p/candidates/invalid',
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
     * Test approving P2P with candidate ID
     */
    public function testApproveP2pWithCandidateId(): void
    {
        $this->authenticateWith(['wallet:send']);

        $this->mockP2pRepo->method('getAwaitingApproval')
            ->with('abc123')
            ->willReturn([
                'hash' => 'abc123',
                'amount' => 1000,
                'currency' => 'USD',
                'destination_address' => 'http://bob:8080',
                'my_fee_amount' => 10,
                'fast' => 0,
            ]);

        $this->mockRp2pCandidateRepo->method('getCandidateById')
            ->with(5)
            ->willReturn([
                'id' => 5,
                'hash' => 'abc123',
                'sender_address' => 'http://relay1:8080',
                'amount' => 1020,
                'currency' => 'USD',
                'fee_amount' => 20,
                'time' => 123456,
                'sender_public_key' => 'pk1',
                'sender_signature' => 'sig1',
            ]);

        $this->mockP2pRepo->expects($this->once())
            ->method('updateStatus')
            ->with('abc123', 'found');

        $this->mockSendService->expects($this->once())
            ->method('sendP2pEiou')
            ->with($this->callback(function ($request) {
                return $request['hash'] === 'abc123'
                    && $request['amount'] === 1010;  // 1020 - 10
            }));

        $this->mockRp2pCandidateRepo->expects($this->once())
            ->method('deleteCandidatesByHash')
            ->with('abc123');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/p2p/approve',
            [],
            json_encode(['hash' => 'abc123', 'candidate_id' => 5]),
            []
        );

        $this->assertTrue($response['success']);
        $this->assertEquals('abc123', $response['data']['hash']);
    }

    /**
     * Test approving P2P without candidate_id in fast mode
     */
    public function testApproveP2pWithoutCandidateIdFastMode(): void
    {
        $this->authenticateWith(['wallet:send']);

        $this->mockP2pRepo->method('getAwaitingApproval')
            ->with('abc123')
            ->willReturn([
                'hash' => 'abc123',
                'amount' => 1000,
                'currency' => 'USD',
                'destination_address' => 'http://bob:8080',
                'my_fee_amount' => 10,
                'fast' => 1,
            ]);

        $this->mockRp2pCandidateRepo->method('getCandidatesByHash')
            ->willReturn([]);

        $this->mockRp2pRepo->method('getByHash')
            ->with('abc123')
            ->willReturn([
                'hash' => 'abc123',
                'sender_address' => 'http://relay1:8080',
                'amount' => 1010,
                'currency' => 'USD',
                'time' => 123456,
                'sender_public_key' => 'pk1',
                'sender_signature' => 'sig1',
            ]);

        $this->mockP2pRepo->expects($this->once())
            ->method('updateStatus')
            ->with('abc123', 'found');

        $this->mockSendService->expects($this->once())
            ->method('sendP2pEiou');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/p2p/approve',
            [],
            json_encode(['hash' => 'abc123']),
            []
        );

        $this->assertTrue($response['success']);
        $this->assertStringContainsString('fast mode', $response['data']['message']);
    }

    /**
     * Test approving P2P with multiple candidates but no candidate_id returns error
     */
    public function testApproveP2pMultipleCandidatesNoCandidateId(): void
    {
        $this->authenticateWith(['wallet:send']);

        $this->mockP2pRepo->method('getAwaitingApproval')
            ->with('abc123')
            ->willReturn([
                'hash' => 'abc123',
                'amount' => 1000,
                'currency' => 'USD',
                'destination_address' => 'http://bob:8080',
                'my_fee_amount' => 10,
                'fast' => 0,
            ]);

        $this->mockRp2pCandidateRepo->method('getCandidatesByHash')
            ->willReturn([
                ['id' => 1, 'hash' => 'abc123'],
                ['id' => 2, 'hash' => 'abc123'],
            ]);

        $this->mockSendService->expects($this->never())
            ->method('sendP2pEiou');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/p2p/approve',
            [],
            json_encode(['hash' => 'abc123']),
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
            json_encode(['hash' => 'abc123']),
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
     * Test rejecting P2P cancels and propagates
     */
    public function testRejectP2pCancelsAndPropagates(): void
    {
        $this->authenticateWith(['wallet:send']);

        $this->mockP2pRepo->method('getAwaitingApproval')
            ->with('abc123')
            ->willReturn([
                'hash' => 'abc123',
                'amount' => 1000,
                'currency' => 'USD',
                'destination_address' => 'http://bob:8080',
            ]);

        $this->mockP2pRepo->expects($this->once())
            ->method('updateStatus')
            ->with('abc123', Constants::STATUS_CANCELLED);

        $this->mockP2pService->expects($this->once())
            ->method('sendCancelNotificationForHash')
            ->with('abc123');

        $this->mockRp2pCandidateRepo->expects($this->once())
            ->method('deleteCandidatesByHash')
            ->with('abc123');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/p2p/reject',
            [],
            json_encode(['hash' => 'abc123']),
            []
        );

        $this->assertTrue($response['success']);
        $this->assertEquals('abc123', $response['data']['hash']);
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
            json_encode(['hash' => 'abc123']),
            []
        );

        $this->assertFalse($response['success']);
        $this->assertEquals(403, $response['status_code']);
        $this->assertEquals('permission_denied', $response['error']['code']);
    }
}
