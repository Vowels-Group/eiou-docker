<?php
namespace Eiou\Tests\Services\Lookup;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Contracts\PluginCallable;
use Eiou\Core\SplitAmount;
use Eiou\Database\TransactionStatisticsRepository;
use Eiou\Services\Lookup\TransactionStatisticsLookupService;
use ReflectionMethod;

#[CoversClass(TransactionStatisticsLookupService::class)]
class TransactionStatisticsLookupServiceTest extends TestCase
{
    private $repo;
    private TransactionStatisticsLookupService $svc;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(TransactionStatisticsRepository::class);
        $this->svc = new TransactionStatisticsLookupService($this->repo);
    }

    // =========================================================================
    // getStatsForPeriod — happy path + projection
    // =========================================================================

    public function testReturnsCountAndProjectedTotal(): void
    {
        $this->repo->expects($this->once())
            ->method('getStatisticsForPeriod')
            ->with(1000, 2000, 'USD')
            ->willReturn([
                'count' => 5,
                'total_amount' => new SplitAmount(123, 45000000),
            ]);

        $result = $this->svc->getStatsForPeriod(1000, 2000, 'USD');
        $this->assertSame(5, $result['count']);
        $this->assertSame(123, $result['total_amount']['whole']);
        $this->assertSame(45000000, $result['total_amount']['frac']);
        $this->assertArrayHasKey('minor_units', $result['total_amount']);
        $this->assertArrayHasKey('display', $result['total_amount']);
    }

    public function testCurrencyParamIsOptional(): void
    {
        $this->repo->expects($this->once())
            ->method('getStatisticsForPeriod')
            ->with(0, 1, null)
            ->willReturn([
                'count' => 0,
                'total_amount' => SplitAmount::zero(),
            ]);

        $this->svc->getStatsForPeriod(0, 1);
    }

    // =========================================================================
    // Bounds and short-circuits
    // =========================================================================

    public function testZeroWindowShortCircuitsBeforeRepo(): void
    {
        // $endTs <= $startTs is a no-op; the service returns the zero
        // result without touching the repository.
        $this->repo->expects($this->never())->method('getStatisticsForPeriod');
        $r = $this->svc->getStatsForPeriod(100, 100);
        $this->assertSame(0, $r['count']);
        $this->assertSame(0, $r['total_amount']['minor_units']);
    }

    public function testReversedWindowShortCircuitsBeforeRepo(): void
    {
        $this->repo->expects($this->never())->method('getStatisticsForPeriod');
        $r = $this->svc->getStatsForPeriod(2000, 1000);
        $this->assertSame(0, $r['count']);
    }

    public function testOversizedWindowClampsToMaxPeriod(): void
    {
        // Window beyond MAX_PERIOD_SECONDS gets clamped at the
        // service boundary so a hostile plugin can't trigger an
        // unbounded scan in one call.
        $start = 0;
        $end = $start + TransactionStatisticsLookupService::MAX_PERIOD_SECONDS + 99999;
        $expectedEnd = $start + TransactionStatisticsLookupService::MAX_PERIOD_SECONDS;
        $this->repo->expects($this->once())
            ->method('getStatisticsForPeriod')
            ->with($start, $expectedEnd, null)
            ->willReturn(['count' => 0, 'total_amount' => SplitAmount::zero()]);

        $this->svc->getStatsForPeriod($start, $end);
    }

    public function testEmptyCurrencyStringShortCircuits(): void
    {
        // An empty currency is nonsense, but a plugin passing one
        // shouldn't trigger a wide query — short-circuit to zero.
        $this->repo->expects($this->never())->method('getStatisticsForPeriod');
        $r = $this->svc->getStatsForPeriod(0, 100, '   ');
        $this->assertSame(0, $r['count']);
    }

    // =========================================================================
    // Defensive — drift in repository contract
    // =========================================================================

    public function testMalformedRepositoryResultProjectsToZero(): void
    {
        // Defence against the repository drifting away from the
        // {count, total_amount: SplitAmount} shape — fall back to
        // zero rather than crashing the call.
        $this->repo->method('getStatisticsForPeriod')->willReturn([
            'count' => 7,
            'total_amount' => 'not a SplitAmount',
        ]);

        $r = $this->svc->getStatsForPeriod(0, 100);
        $this->assertSame(0, $r['count']);
        $this->assertSame(0, $r['total_amount']['minor_units']);
    }

    // =========================================================================
    // Permission-gate annotation
    // =========================================================================

    public function testGetStatsForPeriodRequiresAggregatePermission(): void
    {
        $reflection = new ReflectionMethod(TransactionStatisticsLookupService::class, 'getStatsForPeriod');
        $instance = $reflection->getAttributes(PluginCallable::class)[0]->newInstance();
        $this->assertSame(
            'transaction_history_aggregate',
            $instance->permission,
            'aggregates leak volume but not counterparties — distinct permission key from enumerate'
        );
    }
}
