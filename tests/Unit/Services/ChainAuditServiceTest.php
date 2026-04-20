<?php
/**
 * Unit Tests for ChainAuditService
 *
 * The audit / --full-verify path for archived chains. Three scenarios matter:
 *   1. Clean state: live + archive + checkpoint all agree. No findings.
 *   2. Chain gap in live that the fast-path verify (with checkpoint
 *      trust) would miss — audit with useCheckpoint=false must catch it.
 *   3. Archive tampered with post-archival: stored `archived_txid_hash`
 *      no longer matches what recomputing from the archive produces —
 *      audit must flag the mismatch.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\TransactionArchiveRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Services\ChainAuditService;

#[CoversClass(ChainAuditService::class)]
class ChainAuditServiceTest extends TestCase
{
    private TransactionChainRepository $chainRepo;
    private TransactionArchiveRepository $archiveRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chainRepo   = $this->createMock(TransactionChainRepository::class);
        $this->archiveRepo = $this->createMock(TransactionArchiveRepository::class);
    }

    public function testNoPairsReportsPairsScannedZero(): void
    {
        $this->archiveRepo->method('findAllBilateralPairs')->willReturn([]);

        $svc = new ChainAuditService($this->chainRepo, $this->archiveRepo);
        $result = $svc->verifyAll();

        $this->assertSame(0, $result['pairs_scanned']);
        $this->assertSame([], $result['pairs']);
    }

    public function testVerifyPairCleanChainCleanHash(): void
    {
        $this->chainRepo->method('verifyChainIntegrityByHashes')
            ->with('aaaa', 'bbbb', null, false)  // useCheckpoint must be FALSE in audit
            ->willReturn([
                'valid' => true, 'has_transactions' => true, 'transaction_count' => 5,
                'gaps' => [], 'broken_txids' => [], 'gap_context' => [],
            ]);
        $this->archiveRepo->method('getCheckpoint')->willReturn([
            'archived_count'      => 3,
            'archived_txid_hash'  => 'matching-hash',
        ]);
        $this->archiveRepo->method('computeArchivedTxidHash')->willReturn('matching-hash');

        $svc = new ChainAuditService($this->chainRepo, $this->archiveRepo);
        $r = $svc->verifyPair('aaaa', 'bbbb');

        $this->assertTrue($r['chain']['valid']);
        $this->assertTrue($r['archive_hash_match']);
    }

    public function testVerifyPairDetectsChainGapThatFastPathWouldMiss(): void
    {
        // The regression target: a real gap in the chain that
        // verifyChainIntegrityByHashes(useCheckpoint=true) would hide by
        // trusting the checkpoint. Audit uses useCheckpoint=false, walking
        // live + archive, and catches it.
        $this->chainRepo->expects($this->once())
            ->method('verifyChainIntegrityByHashes')
            ->with('aaaa', 'bbbb', null, false)
            ->willReturn([
                'valid' => false, 'has_transactions' => true, 'transaction_count' => 4,
                'gaps' => ['missing-tx'], 'broken_txids' => ['broken-tx'],
                'gap_context' => [
                    ['missing_txid' => 'missing-tx', 'before_txid' => 'prev-tx', 'after_txid' => 'broken-tx']
                ],
            ]);
        $this->archiveRepo->method('getCheckpoint')->willReturn([
            'archived_count'     => 3,
            'archived_txid_hash' => 'h',
        ]);
        $this->archiveRepo->method('computeArchivedTxidHash')->willReturn('h');

        $svc = new ChainAuditService($this->chainRepo, $this->archiveRepo);
        $r = $svc->verifyPair('aaaa', 'bbbb');

        $this->assertFalse($r['chain']['valid']);
        $this->assertContains('missing-tx', $r['chain']['gaps']);
        $this->assertTrue($r['archive_hash_match']);
    }

    public function testVerifyPairDetectsTamperedArchive(): void
    {
        // Archive rows were modified outside the archival service — the
        // stored checkpoint hash is stale. Audit catches this because it
        // recomputes the hash from the current archive state.
        $this->chainRepo->method('verifyChainIntegrityByHashes')->willReturn([
            'valid' => true, 'has_transactions' => true, 'transaction_count' => 5,
            'gaps' => [], 'broken_txids' => [], 'gap_context' => [],
        ]);
        $this->archiveRepo->method('getCheckpoint')->willReturn([
            'archived_count'     => 3,
            'archived_txid_hash' => 'stored-original-hash',
        ]);
        $this->archiveRepo->method('computeArchivedTxidHash')
            ->willReturn('recomputed-after-tamper-hash');

        $svc = new ChainAuditService($this->chainRepo, $this->archiveRepo);
        $r = $svc->verifyPair('aaaa', 'bbbb');

        $this->assertTrue($r['chain']['valid']);
        $this->assertFalse($r['archive_hash_match']);
        $this->assertSame('stored-original-hash', $r['checkpoint']['archived_txid_hash']);
        $this->assertSame('recomputed-after-tamper-hash', $r['recomputed_archive_hash']);
    }

    public function testVerifyPairWithNoCheckpointReportsNullMatchStatus(): void
    {
        // A pair that has no archive yet — checkpoint doesn't exist.
        // archive_hash_match is explicitly null (not false) so callers
        // can distinguish "no checkpoint, no comparison possible" from
        // "checkpoint exists and hash mismatches".
        $this->chainRepo->method('verifyChainIntegrityByHashes')->willReturn([
            'valid' => true, 'has_transactions' => true, 'transaction_count' => 2,
            'gaps' => [], 'broken_txids' => [], 'gap_context' => [],
        ]);
        $this->archiveRepo->method('getCheckpoint')->willReturn(null);
        $this->archiveRepo->method('computeArchivedTxidHash')->willReturn(hash('sha256', ''));

        $svc = new ChainAuditService($this->chainRepo, $this->archiveRepo);
        $r = $svc->verifyPair('aaaa', 'bbbb');

        $this->assertNull($r['checkpoint']);
        $this->assertNull($r['archive_hash_match']);
    }

    public function testVerifyAllIteratesEveryPairFromFindAll(): void
    {
        $this->archiveRepo->method('findAllBilateralPairs')->willReturn([
            ['hash_a' => 'a1', 'hash_b' => 'b1'],
            ['hash_a' => 'a2', 'hash_b' => 'b2'],
            ['hash_a' => 'a3', 'hash_b' => 'b3'],
        ]);
        $this->chainRepo->expects($this->exactly(3))
            ->method('verifyChainIntegrityByHashes')
            ->willReturn([
                'valid' => true, 'has_transactions' => false, 'transaction_count' => 0,
                'gaps' => [], 'broken_txids' => [], 'gap_context' => [],
            ]);
        $this->archiveRepo->method('getCheckpoint')->willReturn(null);
        $this->archiveRepo->method('computeArchivedTxidHash')->willReturn('h');

        $svc = new ChainAuditService($this->chainRepo, $this->archiveRepo);
        $result = $svc->verifyAll();

        $this->assertSame(3, $result['pairs_scanned']);
        $this->assertCount(3, $result['pairs']);
    }
}
