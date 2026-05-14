<?php
namespace Eiou\Tests\Services\Lookup;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Contracts\PluginCallable;
use Eiou\Core\SplitAmount;
use Eiou\Database\PaymentRequestRepository;
use Eiou\Services\Lookup\PaymentRequestLookupService;
use ReflectionMethod;

#[CoversClass(PaymentRequestLookupService::class)]
class PaymentRequestLookupServiceTest extends TestCase
{
    private $repo;
    private PaymentRequestLookupService $svc;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(PaymentRequestRepository::class);
        $this->svc = new PaymentRequestLookupService($this->repo);
    }

    private function sampleRow(array $overrides = []): array
    {
        return array_merge([
            'request_id'             => 'req-1',
            'direction'              => 'incoming',
            'status'                 => 'pending',
            'requester_pubkey_hash'  => 'r-hash',
            'recipient_pubkey_hash'  => 'p-hash',
            'contact_name'           => 'Alice',
            'amount'                 => new SplitAmount(50, 25000000),
            'currency'               => 'USD',
            'description'            => 'demo',
            'created_at'             => '2026-05-14 10:00:00',
            'responded_at'           => null,
            'resulting_txid'         => null,
            // Sensitive / internal fields that MUST NOT leak
            'requester_address'      => 'SECRET',
            'signed_message_content' => 'CRYPTO',
            'id'                     => 42,
        ], $overrides);
    }

    // =========================================================================
    // getByRequestId — projection + null path
    // =========================================================================

    public function testGetByRequestIdProjectsRow(): void
    {
        $this->repo->expects($this->once())
            ->method('getByRequestId')
            ->with('req-1')
            ->willReturn($this->sampleRow());

        $r = $this->svc->getByRequestId('req-1');
        $this->assertNotNull($r);
        $this->assertSame('req-1', $r['request_id']);
        $this->assertSame('incoming', $r['direction']);
        $this->assertSame(50, $r['amount']['whole']);
        $this->assertSame('USD', $r['currency']);
        $this->assertIsInt($r['created_at']);

        // Sensitive fields must not leak
        $this->assertArrayNotHasKey('requester_address', $r);
        $this->assertArrayNotHasKey('signed_message_content', $r);
        $this->assertArrayNotHasKey('id', $r);
    }

    public function testGetByRequestIdReturnsNullOnMissing(): void
    {
        $this->repo->method('getByRequestId')->willReturn(null);
        $this->assertNull($this->svc->getByRequestId('nope'));
    }

    public function testGetByRequestIdShortCircuitsEmpty(): void
    {
        $this->repo->expects($this->never())->method('getByRequestId');
        $this->assertNull($this->svc->getByRequestId(''));
        $this->assertNull($this->svc->getByRequestId('   '));
    }

    // =========================================================================
    // listPendingIncoming — enumerate
    // =========================================================================

    public function testListPendingIncomingProjectsEachRow(): void
    {
        $this->repo->expects($this->once())
            ->method('getPendingIncoming')
            ->willReturn([
                $this->sampleRow(['request_id' => 'a']),
                $this->sampleRow(['request_id' => 'b']),
            ]);

        $r = $this->svc->listPendingIncoming();
        $this->assertCount(2, $r);
        $this->assertSame('a', $r[0]['request_id']);
        $this->assertSame('b', $r[1]['request_id']);
    }

    // =========================================================================
    // listOutgoing — limit clamp
    // =========================================================================

    public function testListOutgoingClampsOversizedLimit(): void
    {
        $this->repo->expects($this->once())
            ->method('getAllOutgoing')
            ->with(PaymentRequestLookupService::MAX_PAGE_LIMIT)
            ->willReturn([]);
        $this->svc->listOutgoing(1_000_000);
    }

    public function testListOutgoingDefaultsLimitTo50(): void
    {
        $this->repo->expects($this->once())
            ->method('getAllOutgoing')
            ->with(50)
            ->willReturn([]);
        $this->svc->listOutgoing();
    }

    public function testListOutgoingClampsNegativeToZero(): void
    {
        $this->repo->expects($this->once())
            ->method('getAllOutgoing')
            ->with(0)
            ->willReturn([]);
        $this->svc->listOutgoing(-5);
    }

    // =========================================================================
    // Permission-gate annotation
    // =========================================================================

    public function testGetByRequestIdHasNoPermission(): void
    {
        $reflection = new ReflectionMethod(PaymentRequestLookupService::class, 'getByRequestId');
        $instance = $reflection->getAttributes(PluginCallable::class)[0]->newInstance();
        $this->assertNull(
            $instance->permission,
            'per-id lookups are demand-driven (plugin already has the id from create)'
        );
    }

    public function testListMethodsRequireEnumeratePermission(): void
    {
        foreach (['listPendingIncoming', 'listOutgoing'] as $method) {
            $reflection = new ReflectionMethod(PaymentRequestLookupService::class, $method);
            $instance = $reflection->getAttributes(PluginCallable::class)[0]->newInstance();
            $this->assertSame(
                'payment_request_enumerate',
                $instance->permission,
                "{$method} reveals the operator's pending-debts / receivables lists; must gate"
            );
        }
    }
}
