<?php
namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\PaybackMethodReceivedRepository;
use Eiou\Services\PaybackMethodService;
use Eiou\Services\ReceivedPaybackMethodService;

/**
 * Subclass that makes the rate-limit hook controllable so individual
 * tests can drive responder decisions without needing to populate the
 * mock repo with expires_at fixtures.
 */
class ReceivedPaybackMethodServiceTestDouble extends ReceivedPaybackMethodService
{
    public bool $rateLimitAnswer = false;

    protected function wasRecentlyAnswered(string $senderPubkeyHash): bool
    {
        return $this->rateLimitAnswer;
    }
}

#[CoversClass(ReceivedPaybackMethodService::class)]
class ReceivedPaybackMethodServiceTest extends TestCase
{
    private PaybackMethodReceivedRepository $repo;
    private PaybackMethodService $localSvc;
    private ReceivedPaybackMethodServiceTestDouble $svc;

    /** @var list<array{0: string, 1: string, 2: array}> */
    public array $sentMessages = [];

    protected function setUp(): void
    {
        $this->repo = $this->createMock(PaybackMethodReceivedRepository::class);
        $this->localSvc = $this->createMock(PaybackMethodService::class);

        $this->svc = new ReceivedPaybackMethodServiceTestDouble(
            $this->repo,
            $this->localSvc,
            /* logger */ null,
            /* deliveryCallback */ function ($contactAddress, $messageType, $payload) {
                $this->sentMessages[] = [$contactAddress, $messageType, $payload];
            }
        );
    }

    // =========================================================================
    // requestFromContact
    // =========================================================================

    public function testRequestFromContactReturnsUuid(): void
    {
        $requestId = $this->svc->requestFromContact('alice.example.com', 'EUR');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $requestId
        );
    }

    public function testRequestFromContactInvokesDelivery(): void
    {
        $requestId = $this->svc->requestFromContact('alice.example.com', 'EUR');
        $this->assertCount(1, $this->sentMessages);
        [$addr, $type, $payload] = $this->sentMessages[0];
        $this->assertEquals('alice.example.com', $addr);
        $this->assertEquals(ReceivedPaybackMethodService::MSG_TYPE_REQUEST, $type);
        $this->assertEquals($requestId, $payload['request_id']);
        $this->assertEquals('EUR', $payload['currency']);
    }

    // =========================================================================
    // handleIncomingRequest (responder side)
    // =========================================================================

    public function testHandleIncomingRequestAutoApproves(): void
    {
        $this->localSvc->method('listShareable')->willReturn([[
            'method_id' => 'm-1', 'type' => 'btc', 'label' => 'Cold',
            'currency' => 'BTC', 'priority' => 100,
            'share_policy' => 'auto',
            'fields' => ['address' => 'bc1q...'],
            'settlement_min_unit' => 1, 'settlement_min_unit_exponent' => -8,
        ]]);

        $response = $this->svc->handleIncomingRequest('bobhash', [
            'request_id' => 'req-1', 'currency' => 'BTC',
        ]);

        $this->assertEquals('ok', $response['status']);
        $this->assertEquals('req-1', $response['request_id']);
        $this->assertCount(1, $response['methods']);
        $this->assertEquals('m-1', $response['methods'][0]['remote_id']);
    }

    public function testHandleIncomingRequestRateLimited(): void
    {
        $this->svc->rateLimitAnswer = true;
        $this->localSvc->expects($this->never())->method('listShareable');

        $response = $this->svc->handleIncomingRequest('bobhash', ['request_id' => 'req-2']);
        $this->assertEquals('rate_limited', $response['status']);
    }

    public function testHandleIncomingRequestDeniedWhenNoMatches(): void
    {
        $this->localSvc->method('listShareable')->willReturn([]);
        $response = $this->svc->handleIncomingRequest('bobhash', [
            'request_id' => 'req-3', 'currency' => 'USD',
        ]);
        $this->assertEquals('denied', $response['status']);
    }

    public function testHandleIncomingRequestReturnsAllShareableMethods(): void
    {
        // Repository filters share_policy='never' out before this service sees
        // the list (listShareableForCurrency WHERE share_policy != 'never'), so
        // everything the service receives is shareable and should be returned.
        $this->localSvc->method('listShareable')->willReturn([
            [
                'method_id' => 'm-a', 'type' => 'paypal', 'label' => 'a',
                'currency' => 'USD', 'share_policy' => 'auto',
                'fields' => ['email' => 'a@b.c'], 'priority' => 10,
                'settlement_min_unit' => 1, 'settlement_min_unit_exponent' => -2,
            ],
            [
                'method_id' => 'm-b', 'type' => 'bank_wire', 'label' => 'b',
                'currency' => 'USD', 'share_policy' => 'auto',
                'fields' => [], 'priority' => 20,
                'settlement_min_unit' => 1, 'settlement_min_unit_exponent' => -2,
            ],
        ]);
        $response = $this->svc->handleIncomingRequest('bobhash', [
            'request_id' => 'req-5', 'currency' => 'USD',
        ]);
        $this->assertEquals('ok', $response['status']);
        $this->assertCount(2, $response['methods']);
        $this->assertEquals('m-a', $response['methods'][0]['remote_id']);
        $this->assertEquals('m-b', $response['methods'][1]['remote_id']);
        $this->assertArrayNotHasKey('pending_count', $response);
    }

    // =========================================================================
    // handleIncomingResponse (requester side)
    // =========================================================================

    public function testHandleIncomingResponseCachesMethods(): void
    {
        $this->repo->expects($this->exactly(2))->method('upsertReceived');
        $count = $this->svc->handleIncomingResponse('alicehash', [
            'request_id' => 'req-6',
            'status' => 'ok',
            'ttl_seconds' => 3600,
            'methods' => [
                [
                    'remote_id' => 'remote-m1', 'type' => 'btc', 'label' => 'Cold',
                    'currency' => 'BTC', 'fields' => ['address' => 'bc1q...'],
                    'priority' => 10, 'settlement_min_unit' => 1,
                    'settlement_min_unit_exponent' => -8,
                ],
                [
                    'remote_id' => 'remote-m2', 'type' => 'paypal', 'label' => 'x',
                    'currency' => 'EUR', 'fields' => ['email' => 'a@b.c'],
                ],
            ],
        ]);
        $this->assertEquals(2, $count);
    }

    public function testHandleIncomingResponseIgnoresDenied(): void
    {
        $this->repo->expects($this->never())->method('upsertReceived');
        $count = $this->svc->handleIncomingResponse('alicehash', [
            'request_id' => 'req-7', 'status' => 'denied',
        ]);
        $this->assertEquals(0, $count);
    }

    public function testHandleIncomingResponseClampsTtl(): void
    {
        $this->repo->expects($this->once())
            ->method('upsertReceived')
            ->willReturnCallback(function (array $row) {
                // expires_at should be ≤ max cap (7 days from now) and ≥ 1 minute
                $expires = strtotime($row['expires_at']);
                $this->assertGreaterThan(time(), $expires);
                $this->assertLessThanOrEqual(time() + ReceivedPaybackMethodService::MAX_TTL_SECONDS, $expires);
                return '1';
            });
        $this->svc->handleIncomingResponse('alicehash', [
            'status' => 'ok',
            'ttl_seconds' => 999999999, // crazy large → clamp
            'methods' => [[
                'remote_id' => 'r', 'type' => 'paypal', 'label' => 'x',
                'currency' => 'USD', 'fields' => [],
            ]],
        ]);
    }

    public function testHandleIncomingResponseSkipsMalformedMethods(): void
    {
        $this->repo->expects($this->once())->method('upsertReceived');
        $count = $this->svc->handleIncomingResponse('alicehash', [
            'status' => 'ok',
            'methods' => [
                ['remote_id' => 'r1', 'type' => 'paypal', 'label' => 'x', 'currency' => 'USD', 'fields' => []],
                ['remote_id' => '', 'type' => 'paypal'],  // missing remote_id → skipped
                ['type' => 'btc'],                         // missing remote_id → skipped
                'not-an-array',                            // not an array → skipped
            ],
        ]);
        $this->assertEquals(1, $count);
    }

    // =========================================================================
    // handleIncomingRevoke
    // =========================================================================

    public function testHandleIncomingRevoke(): void
    {
        $this->repo->expects($this->once())
            ->method('markRevoked')
            ->with('alicehash', ['r1', 'r2'])
            ->willReturn(2);
        $count = $this->svc->handleIncomingRevoke('alicehash', ['remote_ids' => ['r1', 'r2']]);
        $this->assertEquals(2, $count);
    }

    public function testHandleIncomingRevokeNoIdsIsNoop(): void
    {
        $this->repo->expects($this->never())->method('markRevoked');
        $this->assertEquals(0, $this->svc->handleIncomingRevoke('a', []));
        $this->assertEquals(0, $this->svc->handleIncomingRevoke('a', ['remote_ids' => []]));
    }

    // =========================================================================
    // listForContact
    // =========================================================================

    public function testListForContactReturnsFields(): void
    {
        $this->repo->method('listFreshForContact')->willReturn([[
            'remote_method_id' => 'r',
            'type' => 'paypal', 'label' => 'x', 'currency' => 'USD',
            'fields_json' => '{"email":"alice@example.com"}',
            'priority' => 100,
            'settlement_min_unit' => 1, 'settlement_min_unit_exponent' => -2,
            'received_at' => '2026-01-01', 'expires_at' => '2026-01-02',
        ]]);
        $rows = $this->svc->listForContact('alicehash');
        $this->assertCount(1, $rows);
        $this->assertEquals('alice@example.com', $rows[0]['fields']['email']);
    }

    public function testHasFreshForContactDelegates(): void
    {
        $this->repo->expects($this->once())
            ->method('hasFresh')
            ->with('alicehash')
            ->willReturn(true);
        $this->assertTrue($this->svc->hasFreshForContact('alicehash'));
    }
}
