<?php
namespace Eiou\Tests\Services;

use Eiou\Cli\CliOutputManager;
use Eiou\Contracts\PluginCallable;
use Eiou\Contracts\PluginCallerAware;
use Eiou\Contracts\TransactionServiceInterface;
use Eiou\Services\WalletOutboundService;
use Eiou\Utils\Logger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(WalletOutboundService::class)]
class WalletOutboundServiceTest extends TestCase
{
    /** @var TransactionServiceInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $tx;
    private WalletOutboundService $svc;

    protected function setUp(): void
    {
        $this->tx = $this->createMock(TransactionServiceInterface::class);
        $this->svc = new WalletOutboundService($this->tx, $this->createMock(Logger::class));
    }

    private function withCaller(string $pluginId): WalletOutboundService
    {
        $this->svc->setCallingPluginId($pluginId);
        return $this->svc;
    }

    // ===================================================================
    // Caller-id requirement
    // ===================================================================

    #[Test]
    public function refusesSendWithoutCallerId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gateway-injected caller id');
        $this->svc->send('alice', '1.00', 'EIOU');
    }

    #[Test]
    public function refusesSendAfterCallerIdCleared(): void
    {
        $this->svc->setCallingPluginId('p1');
        $this->svc->setCallingPluginId(null);
        $this->expectException(RuntimeException::class);
        $this->svc->send('alice', '1.00', 'EIOU');
    }

    // ===================================================================
    // Argument validation
    // ===================================================================

    #[Test]
    public function rejectsBadCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('p1')->send('alice', '1', 'eu');
    }

    #[Test]
    public function rejectsZeroAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('p1')->send('alice', '0', 'EIOU');
    }

    #[Test]
    public function rejectsNegativeAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('p1')->send('alice', '-1', 'EIOU');
    }

    #[Test]
    public function rejectsScientificAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('p1')->send('alice', '1e2', 'EIOU');
    }

    #[Test]
    public function rejectsRecipientWithSpaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('p1')->send('has spaces', '1', 'EIOU');
    }

    #[Test]
    public function rejectsOversizeDescription(): void
    {
        // `description` is the operator-facing free-form text from
        // `eiou send <recipient> <amount> <currency> [description]`.
        // Not to be confused with the internal `transactions.memo`
        // routing-hash field, which is set by sendEiou itself and is
        // not a plugin-controllable input.
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('p1')->send('alice', '1', 'EIOU', str_repeat('x', 300));
    }

    // ===================================================================
    // Happy path — calls sendEiou and returns txid
    // ===================================================================

    #[Test]
    public function happyPathCallsSendEiouAndReturnsTxid(): void
    {
        $captured = null;
        $this->tx->expects($this->once())
            ->method('sendEiou')
            ->willReturnCallback(function (array $req, ?CliOutputManager $out) use (&$captured): void {
                $captured = $req;
                $out->success('sent', ['txid' => 'deadbeef']);
            });

        $result = $this->withCaller('p1')->send('alice', '50.00', 'EIOU', 'refund-123');
        $this->assertTrue($result['ok']);
        $this->assertSame('deadbeef', $result['txid']);

        $this->assertSame('alice', $captured[2]);
        $this->assertSame('50.00', $captured[3]);
        $this->assertSame('EIOU', $captured[4]);
        $this->assertSame('refund-123', $captured[5]);
    }

    #[Test]
    public function happyPathWithoutMemoSendsEmptyDescription(): void
    {
        $captured = null;
        $this->tx->method('sendEiou')
            ->willReturnCallback(function (array $req, ?CliOutputManager $out) use (&$captured): void {
                $captured = $req;
                $out->success('sent', ['txid' => 'tx-1']);
            });

        $this->withCaller('p1')->send('alice', '1', 'EIOU');
        $this->assertSame('', $captured[5]);
    }

    #[Test]
    public function returnsNullTxidWhenUnderlyingSendDidntProduceOne(): void
    {
        $this->tx->method('sendEiou')
            ->willReturnCallback(function (array $req, ?CliOutputManager $out): void {
                // P2P queued route — sendEiou's success call may not include txid yet.
                $out->success('queued for P2P route discovery', ['status' => 'pending']);
            });

        $result = $this->withCaller('p1')->send('alice', '1', 'EIOU');
        $this->assertTrue($result['ok']);
        $this->assertNull($result['txid']);
    }

    // ===================================================================
    // Downstream refusal
    // ===================================================================

    #[Test]
    public function downstreamRefusalThrows(): void
    {
        $this->tx->method('sendEiou')
            ->willReturnCallback(function (array $req, ?CliOutputManager $out): void {
                $out->error('No contact for direct + no P2P route', 'NO_ROUTE', 503);
            });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outbound send refused');
        $this->withCaller('p1')->send('alice', '50', 'EIOU');
    }

    #[Test]
    public function thrownByUnderlyingSendIsRewrapped(): void
    {
        $this->tx->method('sendEiou')->willThrowException(new \LogicException('boom'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outbound send failed: boom');
        $this->withCaller('p1')->send('alice', '50', 'EIOU');
    }

    // ===================================================================
    // Contract
    // ===================================================================

    #[Test]
    public function implementsPluginCallerAware(): void
    {
        $this->assertInstanceOf(PluginCallerAware::class, $this->svc);
    }

    #[Test]
    public function sendCarriesPluginCallableAttribute(): void
    {
        $reflection = new ReflectionMethod(WalletOutboundService::class, 'send');
        $attributes = $reflection->getAttributes(PluginCallable::class);
        $this->assertCount(1, $attributes);
        $instance = $attributes[0]->newInstance();
        $this->assertNotSame('', $instance->description ?? '');
    }

    #[Test]
    public function sendRequiresWalletOutboundSendPermission(): void
    {
        // Spending operator funds is the most consequential surface in
        // the callable catalog. The permission must be on the attribute
        // so that gateway gate 3b refuses any plugin whose manifest
        // doesn't declare it — operators see "may send payments on your
        // behalf" as a distinct line in the plugin modal before enable.
        $reflection = new ReflectionMethod(WalletOutboundService::class, 'send');
        $instance = $reflection->getAttributes(PluginCallable::class)[0]->newInstance();
        $this->assertSame('wallet_outbound_send', $instance->permission);
    }

    #[Test]
    public function callerIdIsClearableBetweenCalls(): void
    {
        $this->tx->method('sendEiou')->willReturnCallback(
            function (array $req, ?CliOutputManager $out): void {
                $out->success('sent', ['txid' => 'tx1']);
            }
        );

        $this->withCaller('p1')->send('alice', '1', 'EIOU');
        $this->svc->setCallingPluginId(null);

        $this->expectException(RuntimeException::class);
        $this->svc->send('alice', '1', 'EIOU');
    }
}
