<?php

declare(strict_types=1);

namespace Eiou\Tests\Api;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Api\ApiController;
use Eiou\Database\ApiKeyRepository;
use Eiou\Database\ContactCurrencyRepository;
use Eiou\Database\ContactRepository;
use Eiou\Database\RepositoryFactory;
use Eiou\Services\ApiAuthService;
use Eiou\Services\ContactDecisionService;
use Eiou\Services\ServiceContainer;
use Eiou\Utils\Logger;

/**
 * Tests the four contact-decisions / per-currency API endpoints introduced
 * with the contact-CLI rework. These endpoints share their service layer
 * (ContactDecisionService + ContactCurrencyRepository) with the GUI batched
 * apply flow and the CLI handler — the assertions here pin the routing and
 * the request/response shapes so the three surfaces stay in lockstep.
 */
#[CoversClass(ApiController::class)]
class ApiControllerContactDecisionsTest extends TestCase
{
    /** @var ApiAuthService&\PHPUnit\Framework\MockObject\MockObject */
    private $auth;
    /** @var ApiKeyRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $apiKeyRepo;
    /** @var ServiceContainer&\PHPUnit\Framework\MockObject\MockObject */
    private $services;
    /** @var ContactDecisionService&\PHPUnit\Framework\MockObject\MockObject */
    private $decisionService;
    /** @var ContactRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $contactRepo;
    /** @var ContactCurrencyRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $contactCurrencyRepo;

    private ApiController $controller;

    protected function setUp(): void
    {
        $this->auth = $this->createMock(ApiAuthService::class);
        $this->apiKeyRepo = $this->createMock(ApiKeyRepository::class);
        $this->services = $this->createMock(ServiceContainer::class);
        $this->decisionService = $this->createMock(ContactDecisionService::class);
        $this->contactRepo = $this->createMock(ContactRepository::class);
        $this->contactCurrencyRepo = $this->createMock(ContactCurrencyRepository::class);

        $this->auth->method('authenticate')->willReturn([
            'success' => true,
            'key' => ['key_id' => 'test', 'permissions' => ['contacts:read', 'contacts:write']],
        ]);
        $this->auth->method('hasPermission')->willReturn(true);
        $this->apiKeyRepo->method('logRequest');

        $this->services->method('getContactDecisionService')
            ->willReturn($this->decisionService);

        $repoFactory = $this->createMock(RepositoryFactory::class);
        $repoFactory->method('get')->willReturnCallback(function (string $class) {
            if ($class === ContactRepository::class) return $this->contactRepo;
            if ($class === ContactCurrencyRepository::class) return $this->contactCurrencyRepo;
            return $this->createMock($class);
        });
        $this->services->method('getRepositoryFactory')->willReturn($repoFactory);

        $this->controller = new ApiController(
            $this->auth,
            $this->apiKeyRepo,
            $this->services,
            $this->createMock(Logger::class),
        );
    }

    // =========================================================================
    // POST /api/v1/contacts/:hash/decisions
    // =========================================================================

    public function testApplyDecisionsForwardsToServiceAndReturnsResult(): void
    {
        $this->decisionService->expects($this->once())
            ->method('apply')
            ->with(
                'abc123',
                $this->callback(fn (array $d) =>
                    count($d) === 2
                    && $d[0]['currency'] === 'USD' && $d[0]['action'] === 'accept'
                    && $d[1]['currency'] === 'EUR' && $d[1]['action'] === 'decline'
                ),
                true,
                'http://bob:8080',
                'Bob',
            )
            ->willReturn(['accepted' => ['USD'], 'declined' => ['EUR'], 'errors' => []]);

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts/abc123/decisions',
            [],
            json_encode([
                'decisions' => [
                    ['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000'],
                    ['currency' => 'EUR', 'action' => 'decline'],
                ],
                'is_new_contact' => true,
                'contact_address' => 'http://bob:8080',
                'contact_name' => 'Bob',
            ]),
            ['Authorization' => 'Bearer test']
        );

        $this->assertTrue($response['success']);
        $this->assertSame(['USD'], $response['data']['accepted']);
        $this->assertSame(['EUR'], $response['data']['declined']);
    }

    public function testApplyDecisionsRejectsEmptyArray(): void
    {
        $this->decisionService->expects($this->never())->method('apply');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts/abc123/decisions',
            [],
            json_encode(['decisions' => []]),
            ['Authorization' => 'Bearer test']
        );

        $this->assertFalse($response['success']);
        $this->assertSame(400, $response['status_code']);
        $this->assertSame('missing_decisions', $response['error']['code']);
    }

    public function testApplyDecisionsRejectsMalformedJson(): void
    {
        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts/abc123/decisions',
            [],
            'not-valid-json',
            ['Authorization' => 'Bearer test']
        );

        $this->assertFalse($response['success']);
        $this->assertSame(400, $response['status_code']);
    }

    // =========================================================================
    // POST /api/v1/contacts/:hash/currency-accept
    // =========================================================================

    public function testCurrencyAcceptDelegatesToDecisionService(): void
    {
        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn('pubkey-bob');
        $this->contactRepo->method('getContactByPubkey')->willReturn([
            'pubkey' => 'pubkey-bob',
            'status' => 'pending',
            'name' => 'Bob',
            'http' => 'http://bob:8080',
        ]);

        $this->decisionService->expects($this->once())
            ->method('apply')
            ->with(
                'abc123',
                $this->callback(fn (array $d) =>
                    count($d) === 1
                    && $d[0]['currency'] === 'EUR'
                    && $d[0]['action'] === 'accept'
                    && $d[0]['fee'] === '0.02'
                    && $d[0]['credit'] === '500'
                ),
                true,                          // isNewContact (status pending)
                'http://bob:8080',
                'Bob',
            )
            ->willReturn(['accepted' => ['EUR'], 'declined' => [], 'errors' => []]);

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts/abc123/currency-accept',
            [],
            json_encode(['currency' => 'eur', 'fee' => '0.02', 'credit' => '500']),
            ['Authorization' => 'Bearer test']
        );

        $this->assertTrue($response['success']);
        $this->assertSame(['EUR'], $response['data']['accepted']);
    }

    public function testCurrencyAcceptReturns404ForUnknownContact(): void
    {
        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn(null);
        $this->decisionService->expects($this->never())->method('apply');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts/abc123/currency-accept',
            [],
            json_encode(['currency' => 'EUR', 'fee' => '0.02', 'credit' => '500']),
            ['Authorization' => 'Bearer test']
        );

        $this->assertFalse($response['success']);
        $this->assertSame(404, $response['status_code']);
    }

    public function testCurrencyAcceptRequiresAllFields(): void
    {
        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts/abc123/currency-accept',
            [],
            json_encode(['currency' => 'EUR']), // missing fee + credit
            ['Authorization' => 'Bearer test']
        );

        $this->assertFalse($response['success']);
        $this->assertSame(400, $response['status_code']);
        $this->assertSame('missing_field', $response['error']['code']);
    }

    // =========================================================================
    // POST /api/v1/contacts/:hash/currency-decline
    // =========================================================================

    public function testCurrencyDeclineForwardsToRepository(): void
    {
        $this->contactCurrencyRepo->expects($this->once())
            ->method('declineIncomingCurrency')
            ->with('abc123', 'EUR')
            ->willReturn(true);

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts/abc123/currency-decline',
            [],
            json_encode(['currency' => 'eur']),
            ['Authorization' => 'Bearer test']
        );

        $this->assertTrue($response['success']);
        $this->assertSame('EUR', $response['data']['currency']);
    }

    public function testCurrencyDeclineRequiresCurrency(): void
    {
        $this->contactCurrencyRepo->expects($this->never())->method('declineIncomingCurrency');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts/abc123/currency-decline',
            [],
            json_encode([]),
            ['Authorization' => 'Bearer test']
        );

        $this->assertFalse($response['success']);
        $this->assertSame(400, $response['status_code']);
    }

    // =========================================================================
    // POST /api/v1/contacts/:hash/decline (bulk decline)
    // =========================================================================

    public function testBulkDeclineEmptyPendingReturnsSuccessWithEmptyList(): void
    {
        // No pending currencies — endpoint stays idempotent and returns 200
        // with an empty declined[] so callers don't need to special-case
        // "already declined / never had any".
        $this->contactCurrencyRepo->method('getPendingCurrencies')
            ->with('abc123', 'incoming')
            ->willReturn([]);
        $this->contactCurrencyRepo->expects($this->never())->method('declineIncomingCurrency');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts/abc123/decline',
            [],
            '',
            ['Authorization' => 'Bearer test']
        );

        $this->assertTrue($response['success']);
        $this->assertSame([], $response['data']['declined']);
    }

    public function testBulkDeclineLoopsOverEveryPendingCurrency(): void
    {
        $this->contactCurrencyRepo->method('getPendingCurrencies')
            ->with('abc123', 'incoming')
            ->willReturn([
                ['currency' => 'usd'],   // mixed-case to verify uppercase normalization
                ['currency' => 'EUR'],
            ]);

        $declined = [];
        $this->contactCurrencyRepo->expects($this->exactly(2))
            ->method('declineIncomingCurrency')
            ->willReturnCallback(function ($_h, $ccy) use (&$declined) {
                $declined[] = $ccy;
                return true;
            });

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts/abc123/decline',
            [],
            '',
            ['Authorization' => 'Bearer test']
        );

        $this->assertTrue($response['success']);
        $this->assertSame(['USD', 'EUR'], $declined);
        $this->assertSame(['USD', 'EUR'], $response['data']['declined']);
    }

    public function testBulkDeclineSurfacesPartialFailure(): void
    {
        // If any per-currency decline throws, the endpoint reports a 500 with
        // the partial state in the error context — callers shouldn't have to
        // re-query to see what landed.
        $this->contactCurrencyRepo->method('getPendingCurrencies')->willReturn([
            ['currency' => 'USD'],
            ['currency' => 'EUR'],
        ]);
        $this->contactCurrencyRepo->method('declineIncomingCurrency')
            ->willReturnCallback(function ($_h, $ccy) {
                if ($ccy === 'EUR') {
                    throw new \RuntimeException('locked');
                }
                return true;
            });

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts/abc123/decline',
            [],
            '',
            ['Authorization' => 'Bearer test']
        );

        $this->assertFalse($response['success']);
        $this->assertSame(500, $response['status_code']);
        $this->assertSame('partial_decline_failure', $response['error']['code']);
    }

    // =========================================================================
    // GET /api/v1/contacts/:hash/currencies
    // =========================================================================

    public function testListCurrenciesReturnsRows(): void
    {
        $rows = [
            ['currency' => 'USD', 'status' => 'accepted', 'direction' => 'incoming'],
            ['currency' => 'EUR', 'status' => 'pending',  'direction' => 'incoming'],
        ];
        $this->contactCurrencyRepo->method('getContactCurrencies')
            ->with('abc123')
            ->willReturn($rows);

        $response = $this->controller->handleRequest(
            'GET',
            '/api/v1/contacts/abc123/currencies',
            [],
            '',
            ['Authorization' => 'Bearer test']
        );

        $this->assertTrue($response['success']);
        $this->assertSame('abc123', $response['data']['pubkey_hash']);
        $this->assertSame($rows, $response['data']['currencies']);
    }

    // =========================================================================
    // POST /api/v1/contacts/:hash/currency-remove
    // =========================================================================

    public function testCurrencyRemoveDeletesConfig(): void
    {
        $this->contactCurrencyRepo->expects($this->once())
            ->method('deleteCurrencyConfig')
            ->with('abc123', 'EUR')
            ->willReturn(true);

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts/abc123/currency-remove',
            [],
            json_encode(['currency' => 'eur']),
            ['Authorization' => 'Bearer test']
        );

        $this->assertTrue($response['success']);
        $this->assertSame('EUR', $response['data']['currency']);
    }

    public function testCurrencyRemoveRequiresCurrency(): void
    {
        $this->contactCurrencyRepo->expects($this->never())->method('deleteCurrencyConfig');

        $response = $this->controller->handleRequest(
            'POST',
            '/api/v1/contacts/abc123/currency-remove',
            [],
            json_encode([]),
            ['Authorization' => 'Bearer test']
        );

        $this->assertFalse($response['success']);
        $this->assertSame(400, $response['status_code']);
    }

    // =========================================================================
    // Permission gate
    // =========================================================================

    public function testDecisionsEndpointRequiresContactsWritePermission(): void
    {
        // Override the permission gate to deny.
        $auth = $this->createMock(ApiAuthService::class);
        $auth->method('authenticate')->willReturn([
            'success' => true,
            'key' => ['key_id' => 'test', 'permissions' => []],
        ]);
        $auth->method('hasPermission')->willReturn(false);

        $controller = new ApiController(
            $auth,
            $this->apiKeyRepo,
            $this->services,
            $this->createMock(Logger::class),
        );

        $response = $controller->handleRequest(
            'POST',
            '/api/v1/contacts/abc123/decisions',
            [],
            json_encode(['decisions' => [['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000']]]),
            ['Authorization' => 'Bearer test']
        );

        $this->assertFalse($response['success']);
        $this->assertSame(403, $response['status_code']);
    }
}
