<?php
/**
 * Unit Tests for ChainDropProposalRepository
 *
 * Tests chain drop proposal repository database operations with mocked PDO.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\ChainDropProposalRepository;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(ChainDropProposalRepository::class)]
class ChainDropProposalRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private ChainDropProposalRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new ChainDropProposalRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor sets table name correctly
     */
    public function testConstructorSetsTableName(): void
    {
        $this->assertEquals('chain_drop_proposals', $this->repository->getTableName());
    }

    /**
     * Test constructor accepts PDO dependency injection
     */
    public function testConstructorAcceptsPdoDependencyInjection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repository = new ChainDropProposalRepository($pdo);

        $this->assertSame($pdo, $repository->getPdo());
    }

    // =========================================================================
    // createProposal() Tests
    // =========================================================================

    /**
     * Test createProposal inserts record with correct parameters
     */
    public function testCreateProposal(): void
    {
        $data = [
            'proposal_id' => 'cdp-test-123',
            'contact_pubkey_hash' => 'hash_abc123',
            'missing_txid' => 'missing_tx_456',
            'broken_txid' => 'broken_tx_789',
            'previous_txid_before_gap' => 'prev_tx_000',
            'direction' => 'outgoing',
            'gap_context' => ['chain_transaction_count' => 10]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO chain_drop_proposals'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->createProposal($data);

        $this->assertTrue($result);
    }

    /**
     * Test createProposal returns false on PDOException
     */
    public function testCreateProposalReturnsFalseOnFailure(): void
    {
        $data = [
            'proposal_id' => 'cdp-test-fail',
            'contact_pubkey_hash' => 'hash_fail',
            'missing_txid' => 'missing_fail',
            'broken_txid' => 'broken_fail',
            'previous_txid_before_gap' => null,
            'direction' => 'outgoing',
            'gap_context' => []
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Insert failed'));

        $result = $this->repository->createProposal($data);

        $this->assertFalse($result);
    }

    // =========================================================================
    // getByProposalId() Tests
    // =========================================================================

    /**
     * Test getByProposalId returns proposal data when found
     */
    public function testGetByProposalId(): void
    {
        $proposalId = 'cdp-lookup-123';
        $expectedRow = [
            'id' => 1,
            'proposal_id' => $proposalId,
            'contact_pubkey_hash' => 'hash_abc',
            'missing_txid' => 'missing_abc',
            'broken_txid' => 'broken_abc',
            'direction' => 'incoming',
            'status' => 'pending'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE proposal_id = :proposal_id'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':proposal_id', $proposalId)
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRow);

        $result = $this->repository->getByProposalId($proposalId);

        $this->assertIsArray($result);
        $this->assertEquals($proposalId, $result['proposal_id']);
        $this->assertEquals('pending', $result['status']);
    }

    /**
     * Test getByProposalId returns null when not found
     */
    public function testGetByProposalIdReturnsNullWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->getByProposalId('cdp-nonexistent');

        $this->assertNull($result);
    }

    // =========================================================================
    // getPendingForContact() Tests
    // =========================================================================

    /**
     * Test getPendingForContact returns pending proposals filtered by contact
     */
    public function testGetPendingForContact(): void
    {
        $contactHash = 'contact_pubkey_hash_abc123';
        $expectedRows = [
            ['proposal_id' => 'cdp-1', 'status' => 'pending', 'contact_pubkey_hash' => $contactHash],
            ['proposal_id' => 'cdp-2', 'status' => 'pending', 'contact_pubkey_hash' => $contactHash]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('contact_pubkey_hash = :contact_hash'),
                $this->stringContains("status = 'pending'")
            ))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':contact_hash', $contactHash)
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRows);

        $result = $this->repository->getPendingForContact($contactHash);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('cdp-1', $result[0]['proposal_id']);
    }

    /**
     * Test getPendingForContact returns empty array when none found
     */
    public function testGetPendingForContactReturnsEmptyWhenNone(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $result = $this->repository->getPendingForContact('nonexistent_hash');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // updateStatus() Tests
    // =========================================================================

    /**
     * Test updateStatus updates the proposal status
     */
    public function testUpdateStatus(): void
    {
        $proposalId = 'cdp-update-status';

        // The updateStatus method calls AbstractRepository::update() which calls execute()
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updateStatus($proposalId, 'accepted');

        $this->assertTrue($result);
    }

    /**
     * Test updateStatus returns false when no rows affected
     */
    public function testUpdateStatusReturnsFalseWhenNoRowsAffected(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->updateStatus('cdp-nonexistent', 'accepted');

        $this->assertFalse($result);
    }

    // =========================================================================
    // markExecuted() Tests
    // =========================================================================

    /**
     * Test markExecuted sets status to 'executed' and resolved_at
     */
    public function testMarkExecuted(): void
    {
        $proposalId = 'cdp-mark-executed';

        // markExecuted calls updateStatus which calls update()
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('UPDATE chain_drop_proposals SET'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->markExecuted($proposalId);

        $this->assertTrue($result);
    }

    // =========================================================================
    // expireOldProposals() Tests
    // =========================================================================

    /**
     * Test expireOldProposals updates pending proposals past expiry
     */
    public function testExpireOldProposals(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains("SET status = 'expired'"),
                $this->stringContains("WHERE status = 'pending'"),
                $this->stringContains('expires_at <= NOW(6)')
            ))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(5);

        $result = $this->repository->expireOldProposals();

        $this->assertEquals(5, $result);
    }

    /**
     * Test expireOldProposals returns zero on failure
     */
    public function testExpireOldProposalsReturnsZeroOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Update failed'));

        $result = $this->repository->expireOldProposals();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // getActiveProposalForGap() Tests
    // =========================================================================

    /**
     * Test getActiveProposalForGap returns active proposal when found
     */
    public function testGetActiveProposalForGap(): void
    {
        $contactHash = 'contact_hash_123';
        $missingTxid = 'missing_tx_456';
        $expectedRow = [
            'proposal_id' => 'cdp-active',
            'contact_pubkey_hash' => $contactHash,
            'missing_txid' => $missingTxid,
            'status' => 'pending'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('contact_pubkey_hash = :contact_hash'),
                $this->stringContains('missing_txid = :missing_txid'),
                $this->stringContains("status IN ('pending', 'accepted')")
            ))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRow);

        $result = $this->repository->getActiveProposalForGap($contactHash, $missingTxid);

        $this->assertIsArray($result);
        $this->assertEquals('cdp-active', $result['proposal_id']);
        $this->assertEquals($contactHash, $result['contact_pubkey_hash']);
    }

    /**
     * Test getActiveProposalForGap returns null when no active proposal
     */
    public function testGetActiveProposalForGapReturnsNullWhenNone(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->getActiveProposalForGap('hash_abc', 'missing_xyz');

        $this->assertNull($result);
    }
}
