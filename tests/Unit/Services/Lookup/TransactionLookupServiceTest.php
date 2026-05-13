<?php
namespace Eiou\Tests\Services\Lookup;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Contracts\PluginCallable;
use Eiou\Database\TransactionRepository;
use Eiou\Services\Lookup\TransactionLookupService;
use ReflectionMethod;

#[CoversClass(TransactionLookupService::class)]
class TransactionLookupServiceTest extends TestCase
{
    private $repo;
    private TransactionLookupService $svc;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(TransactionRepository::class);
        $this->svc = new TransactionLookupService($this->repo);
    }

    // =========================================================================
    // Delegation — every method passes its arguments through unchanged and
    // returns whatever the repository returns. These tests pin the contract
    // the plugin gateway depends on (a thin facade, no transformation).
    // =========================================================================

    public function testGetByTxidDelegatesAndReturnsRows(): void
    {
        $expected = [['txid' => 'abc', 'description' => 'mcp-topup:key-123', 'status' => 'completed']];
        $this->repo->expects($this->once())
            ->method('getByTxid')
            ->with('abc')
            ->willReturn($expected);

        $this->assertSame($expected, $this->svc->getByTxid('abc'));
    }

    public function testGetByTxidReturnsNullWhenRepositoryHasNoMatch(): void
    {
        $this->repo->method('getByTxid')->willReturn(null);
        $this->assertNull($this->svc->getByTxid('missing'));
    }

    public function testGetStatusByTxidDelegates(): void
    {
        $this->repo->expects($this->once())
            ->method('getStatusByTxid')
            ->with('abc')
            ->willReturn('completed');

        $this->assertSame('completed', $this->svc->getStatusByTxid('abc'));
    }

    public function testGetStatusByTxidReturnsNullWhenMissing(): void
    {
        $this->repo->method('getStatusByTxid')->willReturn(null);
        $this->assertNull($this->svc->getStatusByTxid('missing'));
    }

    public function testExistingTxidDelegates(): void
    {
        $this->repo->expects($this->once())
            ->method('existingTxid')
            ->with('abc')
            ->willReturn(true);

        $this->assertTrue($this->svc->existingTxid('abc'));
    }

    public function testIsCompletedByTxidDelegates(): void
    {
        $this->repo->expects($this->once())
            ->method('isCompletedByTxid')
            ->with('abc')
            ->willReturn(true);

        $this->assertTrue($this->svc->isCompletedByTxid('abc'));
    }

    public function testGetReceivedUserTransactionsPassesLimitAndCurrency(): void
    {
        $expected = [['amount' => '10.00', 'currency' => 'USD']];
        $this->repo->expects($this->once())
            ->method('getReceivedUserTransactions')
            ->with(25, 'USD')
            ->willReturn($expected);

        $this->assertSame($expected, $this->svc->getReceivedUserTransactions(25, 'USD'));
    }

    public function testGetReceivedUserTransactionsAppliesDefaultsWhenOmitted(): void
    {
        $this->repo->expects($this->once())
            ->method('getReceivedUserTransactions')
            ->with(10, null)
            ->willReturn([]);

        $this->assertSame([], $this->svc->getReceivedUserTransactions());
    }

    // =========================================================================
    // #[PluginCallable] attribute coverage — every method exposed to the
    // gateway MUST carry the attribute. Without this assertion, a refactor
    // that drops the attribute would silently break every plugin manifest
    // that allow-lists the method. The reflection check mirrors what
    // PluginGatewayController does at Gate 3.
    // =========================================================================
    //
    // @return array<string, array{0:string}> Test-name => [methodName]
    public static function pluginCallableMethodProvider(): array
    {
        return [
            'getByTxid'                   => ['getByTxid'],
            'getStatusByTxid'             => ['getStatusByTxid'],
            'existingTxid'                => ['existingTxid'],
            'isCompletedByTxid'           => ['isCompletedByTxid'],
            'getReceivedUserTransactions' => ['getReceivedUserTransactions'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pluginCallableMethodProvider')]
    public function testMethodCarriesPluginCallableAttribute(string $method): void
    {
        $reflection = new ReflectionMethod(TransactionLookupService::class, $method);
        $attributes = $reflection->getAttributes(PluginCallable::class);

        $this->assertCount(
            1,
            $attributes,
            "TransactionLookupService::{$method}() must carry exactly one #[PluginCallable] attribute"
        );

        $instance = $attributes[0]->newInstance();
        $this->assertNotSame(
            '',
            $instance->description ?? '',
            "TransactionLookupService::{$method}()'s #[PluginCallable] must have a non-empty description"
        );
    }
}
