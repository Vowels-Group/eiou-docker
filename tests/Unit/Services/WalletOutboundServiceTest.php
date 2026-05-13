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
        $this->svc->send('EIOU', '1.00', 'alice');
    }

    #[Test]
    public function refusesSendAfterCallerIdCleared(): void
    {
        $this->svc->setCallingPluginId('p1');
        $this->svc->setCallingPluginId(null);
        $this->expectException(RuntimeException::class);
        $this->svc->send('EIOU', '1.00', 'alice');
    }

    // ===================================================================
    // Argument validation
    // ===================================================================

    #[Test]
    public function rejectsBadCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('p1')->send('eu', '1', 'alice');
    }

    #[Test]
    public function rejectsZeroAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('p1')->send('EIOU', '0', 'alice');
    }

    #[Test]
    public function rejectsNegativeAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('p1')->send('EIOU', '-1', 'alice');
    }

    #[Test]
    public function rejectsScientificAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('p1')->send('EIOU', '1e2', 'alice');
    }

    #[Test]
    public function rejectsRecipientWithSpaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('p1')->send('EIOU', '1', 'has spaces');
    }

    #[Test]
    public function rejectsOversizeMemo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('p1')->send('EIOU', '1', 'alice', str_repeat('x', 300));
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

        $result = $this->withCaller('p1')->send('EIOU', '50.00', 'alice', 'refund-123');
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

        $this->withCaller('p1')->send('EIOU', '1', 'alice');
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

        $result = $this->withCaller('p1')->send('EIOU', '1', 'alice');
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
        $this->withCaller('p1')->send('EIOU', '50', 'alice');
    }

    #[Test]
    public function thrownByUnderlyingSendIsRewrapped(): void
    {
        $this->tx->method('sendEiou')->willThrowException(new \LogicException('boom'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outbound send failed: boom');
        $this->withCaller('p1')->send('EIOU', '50', 'alice');
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
    public function callerIdIsClearableBetweenCalls(): void
    {
        $this->tx->method('sendEiou')->willReturnCallback(
            function (array $req, ?CliOutputManager $out): void {
                $out->success('sent', ['txid' => 'tx1']);
            }
        );

        $this->withCaller('p1')->send('EIOU', '1', 'alice');
        $this->svc->setCallingPluginId(null);

        $this->expectException(RuntimeException::class);
        $this->svc->send('EIOU', '1', 'alice');
    }
}
