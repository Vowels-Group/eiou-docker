<?php
/**
 * Unit Tests for PaymentRequestArchivalService
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Core\UserContext;
use Eiou\Database\PaymentRequestArchiveRepository;
use Eiou\Services\PaymentRequestArchivalService;

#[CoversClass(PaymentRequestArchivalService::class)]
class PaymentRequestArchivalServiceTest extends TestCase
{
    private PaymentRequestArchiveRepository $repo;
    private UserContext $currentUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->createMock(PaymentRequestArchiveRepository::class);
        $this->currentUser = $this->createMock(UserContext::class);
        $this->currentUser->method('getPaymentRequestsArchiveRetentionDays')->willReturn(180);
        $this->currentUser->method('getPaymentRequestsArchiveBatchSize')->willReturn(500);
    }

    public function testDryRunDoesNotMoveAnything(): void
    {
        $this->repo->method('findEligibleLiveIds')->willReturn([1, 2, 3, 4, 5]);
        $this->repo->method('getLatestArchivedAt')->willReturn(null);
        $this->repo->expects($this->never())->method('moveRows');

        $svc = new PaymentRequestArchivalService($this->repo, $this->currentUser);
        $result = $svc->run(true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(5, $result['eligible']);
        $this->assertSame(0, $result['moved']);
        $this->assertSame(0, $result['batches']);
    }

    public function testNoEligibleRowsIsNoop(): void
    {
        $this->repo->method('findEligibleLiveIds')->willReturn([]);
        $this->repo->method('getLatestArchivedAt')->willReturn(null);
        $this->repo->expects($this->never())->method('moveRows');

        $svc = new PaymentRequestArchivalService($this->repo, $this->currentUser);
        $result = $svc->run(false);

        $this->assertSame(0, $result['moved']);
        $this->assertSame(0, $result['batches']);
    }

    public function testMovesSingleBatchWhenUnderBatchSize(): void
    {
        $this->repo->method('findEligibleLiveIds')
            ->willReturnOnConsecutiveCalls([10, 11, 12], []);
        $this->repo->method('getLatestArchivedAt')->willReturn('2026-04-20 01:00:00');
        $this->repo->expects($this->once())
            ->method('moveRows')
            ->with([10, 11, 12])
            ->willReturn(3);

        $svc = new PaymentRequestArchivalService($this->repo, $this->currentUser);
        $result = $svc->run(false);

        $this->assertSame(3, $result['moved']);
        $this->assertSame(1, $result['batches']);
        $this->assertSame('2026-04-20 01:00:00', $result['latest_archived_at']);
    }

    public function testLoopsUntilNoMoreEligibleRows(): void
    {
        // Simulate 3 batches worth: 500, 500, 50, then empty
        $this->repo->method('findEligibleLiveIds')
            ->willReturnOnConsecutiveCalls(
                range(1, 500),
                range(501, 1000),
                range(1001, 1050),
                []
            );
        $this->repo->method('getLatestArchivedAt')->willReturn(null);
        $this->repo->expects($this->exactly(3))
            ->method('moveRows')
            ->willReturnOnConsecutiveCalls(500, 500, 50);

        $svc = new PaymentRequestArchivalService($this->repo, $this->currentUser);
        $result = $svc->run(false);

        $this->assertSame(1050, $result['moved']);
        $this->assertSame(3, $result['batches']);
    }

    public function testStopsWhenBatchReturnsZeroMovedToAvoidHotLoop(): void
    {
        $this->repo->method('findEligibleLiveIds')
            ->willReturnOnConsecutiveCalls([1, 2, 3], [1, 2, 3]);
        $this->repo->method('getLatestArchivedAt')->willReturn(null);
        $this->repo->expects($this->once())
            ->method('moveRows')
            ->willReturn(0);

        $svc = new PaymentRequestArchivalService($this->repo, $this->currentUser);
        $result = $svc->run(false);

        $this->assertSame(0, $result['moved']);
        $this->assertSame(1, $result['batches']);
    }

    public function testPropagatesExceptionFromMoveRows(): void
    {
        $this->repo->method('findEligibleLiveIds')->willReturn([1, 2, 3]);
        $this->repo->method('getLatestArchivedAt')->willReturn(null);
        $this->repo->method('moveRows')->willThrowException(new \RuntimeException('db down'));

        $svc = new PaymentRequestArchivalService($this->repo, $this->currentUser);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db down');
        $svc->run(false);
    }
}
