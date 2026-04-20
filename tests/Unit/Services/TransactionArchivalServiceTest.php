<?php
/**
 * Unit Tests for TransactionArchivalService
 *
 * Key invariant under test: archival is gated per bilateral pair on
 * verifyChainIntegrityByHashes() returning valid=true. Pairs with a
 * detected gap must be skipped entirely — no move, no checkpoint.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Contracts\BackupServiceInterface;
use Eiou\Core\UserContext;
use Eiou\Database\TransactionArchiveRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Services\TransactionArchivalService;

#[CoversClass(TransactionArchivalService::class)]
class TransactionArchivalServiceTest extends TestCase
{
    private TransactionArchiveRepository $archiveRepo;
    private TransactionChainRepository $chainRepo;
    private UserContext $currentUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archiveRepo = $this->createMock(TransactionArchiveRepository::class);
        $this->chainRepo   = $this->createMock(TransactionChainRepository::class);
        $this->currentUser = $this->createMock(UserContext::class);
        $this->currentUser->method('getTransactionsArchiveRetentionDays')->willReturn(180);
        $this->currentUser->method('getTransactionsArchiveBatchSize')->willReturn(500);
    }

    // Default helper result shape for verifyChainIntegrityByHashes()
    private static function verifyOk(int $txCount = 10): array
    {
        return [
            'valid'            => true,
            'has_transactions' => $txCount > 0,
            'transaction_count' => $txCount,
            'gaps'             => [],
            'broken_txids'     => [],
            'gap_context'      => [],
        ];
    }

    private static function verifyGap(): array
    {
        return [
            'valid'            => false,
            'has_transactions' => true,
            'transaction_count' => 5,
            'gaps'             => ['missing-tx-1'],
            'broken_txids'     => ['broken-tx-1'],
            'gap_context'      => [
                ['missing_txid' => 'missing-tx-1', 'before_txid' => null, 'after_txid' => 'broken-tx-1']
            ],
        ];
    }

    // ---- no-op / dry-run ----------------------------------------------------

    public function testDryRunDoesNotMoveAnything(): void
    {
        $this->archiveRepo->method('findEligibleBilateralPairs')->willReturn([
            ['hash_a' => 'aaaa', 'hash_b' => 'bbbb'],
        ]);
        $this->archiveRepo->method('findEligibleLiveIdsForPair')->willReturn([1, 2, 3]);
        $this->archiveRepo->method('getLatestArchivedAt')->willReturn(null);
        $this->archiveRepo->expects($this->never())->method('moveRows');
        $this->archiveRepo->expects($this->never())->method('upsertCheckpoint');
        $this->chainRepo->expects($this->never())->method('verifyChainIntegrityByHashes');

        $svc = new TransactionArchivalService(
            $this->archiveRepo, $this->chainRepo, $this->currentUser
        );
        $result = $svc->run(true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['pairs_processed']);
        $this->assertSame(3, $result['eligible']);
        $this->assertSame(0, $result['moved']);
    }

    public function testNoEligiblePairsIsNoop(): void
    {
        $this->archiveRepo->method('findEligibleBilateralPairs')->willReturn([]);
        $this->archiveRepo->method('getLatestArchivedAt')->willReturn(null);
        $this->archiveRepo->expects($this->never())->method('moveRows');
        $this->chainRepo->expects($this->never())->method('verifyChainIntegrityByHashes');

        $svc = new TransactionArchivalService(
            $this->archiveRepo, $this->chainRepo, $this->currentUser
        );
        $result = $svc->run(false);

        $this->assertSame(0, $result['moved']);
        $this->assertSame(0, $result['pairs_processed']);
    }

    // ---- happy path: gap-free pair gets archived + checkpointed -------------

    public function testGapFreePairGetsArchivedAndCheckpointed(): void
    {
        $this->archiveRepo->method('findEligibleBilateralPairs')->willReturn([
            ['hash_a' => 'aaaa', 'hash_b' => 'bbbb'],
        ]);
        $this->archiveRepo->method('findEligibleLiveIdsForPair')
            ->willReturnOnConsecutiveCalls([10, 11, 12], []);
        $this->archiveRepo->method('getLatestArchivedAt')->willReturn('2026-04-20 01:30:00');

        $this->chainRepo->expects($this->once())
            ->method('verifyChainIntegrityByHashes')
            ->with('aaaa', 'bbbb', null)
            ->willReturn(self::verifyOk(15));

        $this->archiveRepo->expects($this->once())
            ->method('moveRows')
            ->with([10, 11, 12])
            ->willReturn(3);

        $this->archiveRepo->method('countArchivedForPair')->willReturn(3);
        $this->archiveRepo->method('computeArchivedTxidHash')->willReturn('deadbeef');
        $this->archiveRepo->method('getArchiveHeadForPair')->willReturn([
            'highest_archived_timestamp' => '2026-04-20 01:30:00.000000',
            'highest_archived_time'      => 1711929600,
        ]);
        $this->archiveRepo->expects($this->once())
            ->method('upsertCheckpoint')
            ->with('aaaa', 'bbbb', '2026-04-20 01:30:00.000000', 1711929600, 3, 'deadbeef');

        $svc = new TransactionArchivalService(
            $this->archiveRepo, $this->chainRepo, $this->currentUser
        );
        $result = $svc->run(false);

        $this->assertSame(3, $result['moved']);
        $this->assertSame(1, $result['pairs_archived']);
        $this->assertSame(0, $result['pairs_skipped_gap']);
    }

    // ---- INVARIANT: pair with a gap MUST be skipped -------------------------

    public function testPairWithChainGapIsSkippedAndNotArchived(): void
    {
        $this->archiveRepo->method('findEligibleBilateralPairs')->willReturn([
            ['hash_a' => 'aaaa', 'hash_b' => 'bbbb'],
        ]);
        $this->archiveRepo->method('getLatestArchivedAt')->willReturn(null);

        $this->chainRepo->expects($this->once())
            ->method('verifyChainIntegrityByHashes')
            ->willReturn(self::verifyGap());

        // Critical: NO moves, NO checkpoint write when a gap is detected.
        $this->archiveRepo->expects($this->never())->method('findEligibleLiveIdsForPair');
        $this->archiveRepo->expects($this->never())->method('moveRows');
        $this->archiveRepo->expects($this->never())->method('upsertCheckpoint');

        $svc = new TransactionArchivalService(
            $this->archiveRepo, $this->chainRepo, $this->currentUser
        );
        $result = $svc->run(false);

        $this->assertSame(0, $result['moved']);
        $this->assertSame(0, $result['pairs_archived']);
        $this->assertSame(1, $result['pairs_skipped_gap']);
    }

    public function testMixedPairsOnlyArchivesGapFreeOnes(): void
    {
        $this->archiveRepo->method('findEligibleBilateralPairs')->willReturn([
            ['hash_a' => 'aaaa', 'hash_b' => 'bbbb'],  // gap-free
            ['hash_a' => 'cccc', 'hash_b' => 'dddd'],  // gap
            ['hash_a' => 'eeee', 'hash_b' => 'ffff'],  // gap-free
        ]);
        $this->archiveRepo->method('getLatestArchivedAt')->willReturn(null);

        $this->chainRepo->method('verifyChainIntegrityByHashes')
            ->willReturnOnConsecutiveCalls(
                self::verifyOk(10),   // aaaa/bbbb
                self::verifyGap(),    // cccc/dddd
                self::verifyOk(5)     // eeee/ffff
            );

        $this->archiveRepo->method('findEligibleLiveIdsForPair')
            ->willReturnOnConsecutiveCalls(
                [1, 2],  // aaaa/bbbb first batch
                [],      // aaaa/bbbb exhausted
                // cccc/dddd is skipped — no calls
                [3],     // eeee/ffff first batch
                []       // eeee/ffff exhausted
            );

        $this->archiveRepo->method('moveRows')
            ->willReturnOnConsecutiveCalls(2, 1);

        $this->archiveRepo->method('countArchivedForPair')->willReturn(3);
        $this->archiveRepo->method('computeArchivedTxidHash')->willReturn('hash');
        $this->archiveRepo->method('getArchiveHeadForPair')->willReturn([
            'highest_archived_timestamp' => '2026-04-20 01:30:00.000000',
            'highest_archived_time'      => 1711929600,
        ]);

        // Checkpoint upserted exactly twice — once for each gap-free pair
        $this->archiveRepo->expects($this->exactly(2))->method('upsertCheckpoint');

        $svc = new TransactionArchivalService(
            $this->archiveRepo, $this->chainRepo, $this->currentUser
        );
        $result = $svc->run(false);

        $this->assertSame(3, $result['moved']);
        $this->assertSame(2, $result['pairs_archived']);
        $this->assertSame(1, $result['pairs_skipped_gap']);
        $this->assertSame(3, $result['pairs_processed']);
    }

    // ---- batch loop semantics -----------------------------------------------

    public function testHotLoopProtectionStopsPairOnZeroMove(): void
    {
        $this->archiveRepo->method('findEligibleBilateralPairs')->willReturn([
            ['hash_a' => 'aaaa', 'hash_b' => 'bbbb'],
        ]);
        $this->archiveRepo->method('findEligibleLiveIdsForPair')
            ->willReturnOnConsecutiveCalls([1, 2, 3], [1, 2, 3]);
        $this->archiveRepo->method('getLatestArchivedAt')->willReturn(null);

        $this->chainRepo->method('verifyChainIntegrityByHashes')
            ->willReturn(self::verifyOk());

        // moveRows returns 0 (insert/delete mismatch guard fired). Must NOT
        // loop again on the same IDs.
        $this->archiveRepo->expects($this->once())
            ->method('moveRows')
            ->willReturn(0);

        // No checkpoint upsert when 0 moved.
        $this->archiveRepo->expects($this->never())->method('upsertCheckpoint');

        $svc = new TransactionArchivalService(
            $this->archiveRepo, $this->chainRepo, $this->currentUser
        );
        $result = $svc->run(false);

        $this->assertSame(0, $result['moved']);
        $this->assertSame(0, $result['pairs_archived']);
    }

    public function testPropagatesExceptionFromMoveRows(): void
    {
        $this->archiveRepo->method('findEligibleBilateralPairs')->willReturn([
            ['hash_a' => 'aaaa', 'hash_b' => 'bbbb'],
        ]);
        $this->archiveRepo->method('findEligibleLiveIdsForPair')->willReturn([1, 2, 3]);
        $this->archiveRepo->method('getLatestArchivedAt')->willReturn(null);
        $this->chainRepo->method('verifyChainIntegrityByHashes')->willReturn(self::verifyOk());
        $this->archiveRepo->method('moveRows')->willThrowException(new \RuntimeException('db down'));

        $svc = new TransactionArchivalService(
            $this->archiveRepo, $this->chainRepo, $this->currentUser
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db down');
        $svc->run(false);
    }

    // ---- backup side-effects ------------------------------------------------

    public function testTriggersArchiveBackupAfterSuccessfulMove(): void
    {
        $this->archiveRepo->method('findEligibleBilateralPairs')->willReturn([
            ['hash_a' => 'aaaa', 'hash_b' => 'bbbb'],
        ]);
        $this->archiveRepo->method('findEligibleLiveIdsForPair')
            ->willReturnOnConsecutiveCalls([10], []);
        $this->archiveRepo->method('getLatestArchivedAt')->willReturn('2026-04-20 01:30:00');
        $this->archiveRepo->method('moveRows')->willReturn(1);
        $this->archiveRepo->method('countArchivedForPair')->willReturn(1);
        $this->archiveRepo->method('computeArchivedTxidHash')->willReturn('hash');
        $this->archiveRepo->method('getArchiveHeadForPair')->willReturn([
            'highest_archived_timestamp' => '2026-04-20 01:30:00.000000',
            'highest_archived_time'      => 1711929600,
        ]);
        $this->chainRepo->method('verifyChainIntegrityByHashes')->willReturn(self::verifyOk());

        $backup = $this->createMock(BackupServiceInterface::class);
        $backup->expects($this->once())
            ->method('createArchiveBackup')
            ->willReturn(['success' => true, 'filename' => 'archive_backup_20260420_013000.json']);
        $backup->expects($this->once())->method('cleanupOldBackups')
            ->willReturn(['success' => true, 'deleted_count' => 0, 'deleted_files' => []]);

        $svc = new TransactionArchivalService(
            $this->archiveRepo, $this->chainRepo, $this->currentUser, $backup
        );
        $result = $svc->run(false);

        $this->assertSame(1, $result['moved']);
        $this->assertSame('archive_backup_20260420_013000.json', $result['archive_backup_file']);
    }

    public function testDoesNotTriggerBackupWhenAllPairsSkipped(): void
    {
        $this->archiveRepo->method('findEligibleBilateralPairs')->willReturn([
            ['hash_a' => 'aaaa', 'hash_b' => 'bbbb'],
        ]);
        $this->archiveRepo->method('getLatestArchivedAt')->willReturn(null);
        $this->chainRepo->method('verifyChainIntegrityByHashes')->willReturn(self::verifyGap());

        $backup = $this->createMock(BackupServiceInterface::class);
        $backup->expects($this->never())->method('createArchiveBackup');

        $svc = new TransactionArchivalService(
            $this->archiveRepo, $this->chainRepo, $this->currentUser, $backup
        );
        $result = $svc->run(false);

        $this->assertSame(0, $result['moved']);
        $this->assertSame(1, $result['pairs_skipped_gap']);
        $this->assertNull($result['archive_backup_file']);
    }

    public function testBackupFailureDoesNotFailArchival(): void
    {
        $this->archiveRepo->method('findEligibleBilateralPairs')->willReturn([
            ['hash_a' => 'aaaa', 'hash_b' => 'bbbb'],
        ]);
        $this->archiveRepo->method('findEligibleLiveIdsForPair')
            ->willReturnOnConsecutiveCalls([10], []);
        $this->archiveRepo->method('getLatestArchivedAt')->willReturn(null);
        $this->archiveRepo->method('moveRows')->willReturn(1);
        $this->archiveRepo->method('countArchivedForPair')->willReturn(1);
        $this->archiveRepo->method('computeArchivedTxidHash')->willReturn('hash');
        $this->archiveRepo->method('getArchiveHeadForPair')->willReturn([
            'highest_archived_timestamp' => '2026-04-20 01:30:00.000000',
            'highest_archived_time'      => 1711929600,
        ]);
        $this->chainRepo->method('verifyChainIntegrityByHashes')->willReturn(self::verifyOk());

        $backup = $this->createMock(BackupServiceInterface::class);
        $backup->method('createArchiveBackup')
            ->willThrowException(new \RuntimeException('disk full'));

        $svc = new TransactionArchivalService(
            $this->archiveRepo, $this->chainRepo, $this->currentUser, $backup
        );
        $result = $svc->run(false);

        $this->assertSame(1, $result['moved']);
        $this->assertNull($result['archive_backup_file']);
    }
}
