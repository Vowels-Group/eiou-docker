<?php
namespace Eiou\Tests\Services\Lookup;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Contracts\PluginCallable;
use Eiou\Core\SplitAmount;
use Eiou\Database\BalanceRepository;
use Eiou\Services\Lookup\BalanceLookupService;
use ReflectionMethod;

#[CoversClass(BalanceLookupService::class)]
class BalanceLookupServiceTest extends TestCase
{
    private $repo;
    private BalanceLookupService $svc;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(BalanceRepository::class);
        $this->svc = new BalanceLookupService($this->repo);
    }

    // =========================================================================
    // getUserBalance — currency-specific path
    // =========================================================================

    public function testGetUserBalanceWithCurrencyReturnsSingleProjection(): void
    {
        $this->repo->expects($this->once())
            ->method('getUserBalanceCurrency')
            ->with('USD')
            ->willReturn(new SplitAmount(100, 50000000));

        $result = $this->svc->getUserBalance('USD');
        $this->assertNotNull($result);
        $this->assertSame('USD', $result['currency']);
        $this->assertSame(
            ['whole', 'frac', 'minor_units', 'display'],
            array_keys($result['balance'])
        );
        $this->assertSame(100, $result['balance']['whole']);
        $this->assertSame(50000000, $result['balance']['frac']);
    }

    public function testGetUserBalanceWithCurrencyShortCircuitsEmptyString(): void
    {
        $this->repo->expects($this->never())->method('getUserBalanceCurrency');
        $this->assertNull($this->svc->getUserBalance(''));
        $this->assertNull($this->svc->getUserBalance('   '));
    }

    public function testGetUserBalanceWithCurrencyReturnsZeroRowForUnknownCurrency(): void
    {
        // Repository's getUserBalanceCurrency returns SplitAmount::zero()
        // for currencies with no rows. The service surfaces that as a
        // {currency, balance: {0,0,0,'0.00...'}} row rather than null
        // because the plugin can't distinguish "no rows" from "real
        // zero balance" otherwise; the no-arg list form is the right
        // path for existence checks.
        $this->repo->expects($this->once())
            ->method('getUserBalanceCurrency')
            ->with('XXX')
            ->willReturn(SplitAmount::zero());

        $result = $this->svc->getUserBalance('XXX');
        $this->assertNotNull($result);
        $this->assertSame(0, $result['balance']['whole']);
        $this->assertSame(0, $result['balance']['frac']);
        $this->assertSame(0, $result['balance']['minor_units']);
    }

    // =========================================================================
    // getUserBalance — currency-list path
    // =========================================================================

    public function testGetUserBalanceWithoutCurrencyProjectsEachRow(): void
    {
        $this->repo->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => new SplitAmount(100, 0)],
                ['currency' => 'EUR', 'total_balance' => new SplitAmount(50, 25000000)],
            ]);

        $result = $this->svc->getUserBalance();
        $this->assertCount(2, $result);
        $this->assertSame('USD', $result[0]['currency']);
        $this->assertSame(100, $result[0]['balance']['whole']);
        $this->assertSame('EUR', $result[1]['currency']);
        $this->assertSame(50, $result[1]['balance']['whole']);
        $this->assertSame(25000000, $result[1]['balance']['frac']);
    }

    public function testGetUserBalanceWithoutCurrencyReturnsEmptyOnNoRows(): void
    {
        $this->repo->expects($this->once())
            ->method('getUserBalance')
            ->willReturn(null);

        $this->assertSame([], $this->svc->getUserBalance());
    }

    public function testGetUserBalanceWithoutCurrencyDropsMalformedRows(): void
    {
        // A row missing the SplitAmount, or missing the currency, or
        // carrying the wrong type for total_balance — drop rather than
        // crash. Defence in depth against drift in the repository
        // contract.
        $this->repo->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => new SplitAmount(10, 0)],
                ['currency' => 'BAD', 'total_balance' => 'not a SplitAmount'],
                ['total_balance' => new SplitAmount(99, 0)], // missing currency
            ]);

        $result = $this->svc->getUserBalance();
        $this->assertCount(1, $result);
        $this->assertSame('USD', $result[0]['currency']);
    }

    // =========================================================================
    // Negative-balance encoding — whole < 0 with non-negative frac
    // =========================================================================

    public function testNegativeBalanceProjectsWithNegativeWholeAndPositiveFrac(): void
    {
        // -1.50 stored as whole=-2, frac=50000000 (see SplitAmount
        // docblock). minor_units must be the lossless signed integer
        // form so plugins doing arithmetic don't have to mirror the
        // whole/frac sign convention.
        $this->repo->expects($this->once())
            ->method('getUserBalanceCurrency')
            ->with('USD')
            ->willReturn(new SplitAmount(-2, 50000000));

        $result = $this->svc->getUserBalance('USD');
        $this->assertSame(-2, $result['balance']['whole']);
        $this->assertSame(50000000, $result['balance']['frac']);
        $this->assertLessThan(0, $result['balance']['minor_units']);
    }

    // =========================================================================
    // getUserBalanceContact — per-contact balance, by pubkey_hash
    // =========================================================================

    public function testGetUserBalanceContactProjectsEachCurrency(): void
    {
        $this->repo->expects($this->once())
            ->method('getUserBalanceContactByPubkeyHash')
            ->with('hash-x')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => new SplitAmount(25, 0)],
                ['currency' => 'EUR', 'total_balance' => new SplitAmount(-10, 50000000)],
            ]);

        $result = $this->svc->getUserBalanceContact('hash-x');
        $this->assertCount(2, $result);
        $this->assertSame('USD', $result[0]['currency']);
        $this->assertSame(25, $result[0]['balance']['whole']);
        $this->assertSame('EUR', $result[1]['currency']);
        // Negative balance encoding from SplitAmount: -10.50 stored as
        // whole=-11, frac=50000000 — see SplitAmount docblock. The
        // test passes -10 explicitly to avoid coupling to that
        // encoding here; what matters is the projection round-trips.
        $this->assertSame(-10, $result[1]['balance']['whole']);
        $this->assertSame(50000000, $result[1]['balance']['frac']);
    }

    public function testGetUserBalanceContactReturnsEmptyOnUnknownHash(): void
    {
        $this->repo->expects($this->once())
            ->method('getUserBalanceContactByPubkeyHash')
            ->with('unknown')
            ->willReturn(null);
        $this->assertSame([], $this->svc->getUserBalanceContact('unknown'));
    }

    public function testGetUserBalanceContactShortCircuitsEmptyInput(): void
    {
        $this->repo->expects($this->never())->method('getUserBalanceContactByPubkeyHash');
        $this->assertSame([], $this->svc->getUserBalanceContact(''));
        $this->assertSame([], $this->svc->getUserBalanceContact('   '));
    }

    public function testGetUserBalanceContactNormalisesCase(): void
    {
        $this->repo->expects($this->once())
            ->method('getUserBalanceContactByPubkeyHash')
            ->with('abc123')
            ->willReturn([['currency' => 'USD', 'total_balance' => new SplitAmount(1, 0)]]);

        $result = $this->svc->getUserBalanceContact(' ABC123 ');
        $this->assertCount(1, $result);
    }

    public function testGetUserBalanceContactDropsMalformedRows(): void
    {
        $this->repo->expects($this->once())
            ->method('getUserBalanceContactByPubkeyHash')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => new SplitAmount(1, 0)],
                ['currency' => 'BAD', 'total_balance' => 'not a SplitAmount'],
                ['total_balance' => new SplitAmount(2, 0)], // missing currency
            ]);

        $result = $this->svc->getUserBalanceContact('hash');
        $this->assertCount(1, $result);
        $this->assertSame('USD', $result[0]['currency']);
    }

    // =========================================================================
    // Permission-gate annotation
    // =========================================================================

    public function testGetUserBalanceRequiresWalletBalanceReadPermission(): void
    {
        $reflection = new ReflectionMethod(BalanceLookupService::class, 'getUserBalance');
        $instance = $reflection->getAttributes(PluginCallable::class)[0]->newInstance();

        $this->assertSame(
            'wallet_balance_read',
            $instance->permission,
            'getUserBalance discloses the operator\'s net financial position; '
            . 'must gate on wallet_balance_read'
        );
    }

    public function testGetUserBalanceContactRequiresWalletBalanceReadPermission(): void
    {
        $reflection = new ReflectionMethod(BalanceLookupService::class, 'getUserBalanceContact');
        $instance = $reflection->getAttributes(PluginCallable::class)[0]->newInstance();

        $this->assertSame(
            'wallet_balance_read',
            $instance->permission,
            'getUserBalanceContact discloses per-contact balance; same disclosure shape as the total — same permission'
        );
    }
}
