<?php
/**
 * Unit Tests for ChainDropService
 *
 * Tests the chain drop agreement service functionality including:
 * - Proposing chain drops to contacts
 * - Handling incoming proposals
 * - Accepting and rejecting proposals
 * - Expiring stale proposals
 * - Delegating to repository for queries
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\ChainDropService;
use Eiou\Database\ChainDropProposalRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\ContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;

#[CoversClass(ChainDropService::class)]
class ChainDropServiceTest extends TestCase
{
    private MockObject|ChainDropProposalRepository $proposalRepository;
    private MockObject|TransactionChainRepository $transactionChainRepository;
    private MockObject|TransactionRepository $transactionRepository;
    private MockObject|ContactRepository $contactRepository;
    private MockObject|BalanceRepository $balanceRepository;
    private MockObject|UtilityServiceContainer $utilityContainer;
    private MockObject|TransportUtilityService $transportUtility;
    private MockObject|UserContext $userContext;
    private ChainDropService $service;

    /**
     * Sample test data
     */
    private const TEST_USER_PUBKEY = 'test-user-public-key-123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890';
    private const TEST_CONTACT_PUBKEY = 'test-contact-public-key-987654321098765432109876543210987654321098765432109876543210987654321098765432109876543210987654321098765432109876543210';
    private const TEST_CONTACT_ADDRESS = 'http://contact.example.com';
    private const TEST_MISSING_TXID = 'missing_txid_abc123def456789012345678901234567890123456789012345678';
    private const TEST_BROKEN_TXID = 'broken_txid_xyz789abc123456789012345678901234567890123456789012345';
    private const TEST_PREVIOUS_TXID = 'prev_txid_000111222333444555666777888999aaabbbcccdddeeefffggg000111222';
    private const TEST_PROPOSAL_ID = 'cdp-test-proposal-id-123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        $this->proposalRepository = $this->createMock(ChainDropProposalRepository::class);
        $this->transactionChainRepository = $this->createMock(TransactionChainRepository::class);
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->contactRepository = $this->createMock(ContactRepository::class);
        $this->balanceRepository = $this->createMock(BalanceRepository::class);
        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->transportUtility = $this->createMock(TransportUtilityService::class);
        $this->userContext = $this->createMock(UserContext::class);

        // Configure UtilityServiceContainer to return transport mock
        $this->utilityContainer->method('getTransportUtility')
            ->willReturn($this->transportUtility);

        // Configure UtilityServiceContainer to return other required utility mocks
        $currencyUtility = $this->createMock(\Eiou\Services\Utilities\CurrencyUtilityService::class);
        $timeUtility = $this->createMock(\Eiou\Services\Utilities\TimeUtilityService::class);
        $validationUtility = $this->createMock(\Eiou\Services\Utilities\ValidationUtilityService::class);

        $this->utilityContainer->method('getCurrencyUtility')
            ->willReturn($currencyUtility);
        $this->utilityContainer->method('getTimeUtility')
            ->willReturn($timeUtility);
        $this->utilityContainer->method('getValidationUtility')
            ->willReturn($validationUtility);

        // Default UserContext mock behavior
        $this->userContext->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);
        $this->userContext->method('getPublicKeyHash')
            ->willReturn(hash('sha256', self::TEST_USER_PUBKEY));
        $this->userContext->method('getUserAddresses')
            ->willReturn(['http://myaddress.example.com']);

        // Default transport utility behavior
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://myaddress.example.com');
        $this->transportUtility->method('send')
            ->willReturn('OK');

        $this->service = new ChainDropService(
            $this->proposalRepository,
            $this->transactionChainRepository,
            $this->transactionRepository,
            $this->contactRepository,
            $this->utilityContainer,
            $this->userContext
        );
    }

    // =========================================================================
    // proposeChainDrop() Tests
    // =========================================================================

    /**
     * Test proposeChainDrop succeeds when a gap is detected
     */
    public function testProposeChainDropSuccess(): void
    {
        $contactPubkeyHash = hash('sha256', self::TEST_CONTACT_PUBKEY);

        // Contact lookup returns contact with pubkey
        $this->contactRepository->expects($this->atLeastOnce())
            ->method('lookupByPubkeyHash')
            ->with($contactPubkeyHash)
            ->willReturn([
                'pubkey' => self::TEST_CONTACT_PUBKEY,
                'http' => self::TEST_CONTACT_ADDRESS
            ]);

        // Chain integrity shows a gap
        $this->transactionChainRepository->expects($this->once())
            ->method('verifyChainIntegrity')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn([
                'valid' => false,
                'gaps' => [self::TEST_MISSING_TXID],
                'broken_txids' => [self::TEST_BROKEN_TXID],
                'transaction_count' => 10
            ]);

        // No existing active proposal
        $this->proposalRepository->expects($this->once())
            ->method('getActiveProposalForGap')
            ->with($contactPubkeyHash, self::TEST_MISSING_TXID)
            ->willReturn(null);

        // Chain lookup for findPreviousTxidBeforeGap
        $this->transactionChainRepository->expects($this->once())
            ->method('getTransactionChain')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn([
                ['txid' => self::TEST_PREVIOUS_TXID],
                ['txid' => self::TEST_BROKEN_TXID]
            ]);

        // Proposal creation succeeds
        $this->proposalRepository->expects($this->once())
            ->method('createProposal')
            ->willReturn(true);

        $result = $this->service->proposeChainDrop($contactPubkeyHash);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['proposal_id']);
        $this->assertNull($result['error']);
        $this->assertEquals(self::TEST_MISSING_TXID, $result['missing_txid']);
        $this->assertEquals(self::TEST_BROKEN_TXID, $result['broken_txid']);
    }

    /**
     * Test proposeChainDrop returns error when chain is valid (no gap)
     */
    public function testProposeChainDropNoGapFound(): void
    {
        $contactPubkeyHash = hash('sha256', self::TEST_CONTACT_PUBKEY);

        // Contact lookup returns contact with pubkey
        $this->contactRepository->expects($this->once())
            ->method('lookupByPubkeyHash')
            ->with($contactPubkeyHash)
            ->willReturn([
                'pubkey' => self::TEST_CONTACT_PUBKEY,
                'http' => self::TEST_CONTACT_ADDRESS
            ]);

        // Chain integrity reports valid chain
        $this->transactionChainRepository->expects($this->once())
            ->method('verifyChainIntegrity')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn([
                'valid' => true,
                'gaps' => [],
                'broken_txids' => [],
                'transaction_count' => 10
            ]);

        // Proposal should NOT be created
        $this->proposalRepository->expects($this->never())
            ->method('createProposal');

        $result = $this->service->proposeChainDrop($contactPubkeyHash);

        $this->assertFalse($result['success']);
        $this->assertNull($result['proposal_id']);
        $this->assertEquals('Chain is valid, no gap to resolve', $result['error']);
    }

    // =========================================================================
    // handleIncomingProposal() Tests
    // =========================================================================

    /**
     * Test handleIncomingProposal creates a proposal record when gap exists locally
     */
    public function testHandleIncomingProposalSuccess(): void
    {
        $senderPubkeyHash = hash('sha256', self::TEST_CONTACT_PUBKEY);

        // No existing active proposal
        $this->proposalRepository->expects($this->once())
            ->method('getActiveProposalForGap')
            ->with($senderPubkeyHash, self::TEST_MISSING_TXID)
            ->willReturn(null);

        // Local chain also has the gap
        $this->transactionChainRepository->expects($this->once())
            ->method('verifyChainIntegrity')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn([
                'valid' => false,
                'gaps' => [self::TEST_MISSING_TXID],
                'broken_txids' => [self::TEST_BROKEN_TXID],
                'transaction_count' => 8
            ]);

        // Chain lookup for findPreviousTxidBeforeGap
        $this->transactionChainRepository->expects($this->once())
            ->method('getTransactionChain')
            ->willReturn([
                ['txid' => self::TEST_PREVIOUS_TXID],
                ['txid' => self::TEST_BROKEN_TXID]
            ]);

        // Expect proposal to be created with direction 'incoming'
        $this->proposalRepository->expects($this->once())
            ->method('createProposal')
            ->with($this->callback(function (array $data) {
                return $data['proposal_id'] === self::TEST_PROPOSAL_ID
                    && $data['direction'] === 'incoming'
                    && $data['missing_txid'] === self::TEST_MISSING_TXID
                    && $data['broken_txid'] === self::TEST_BROKEN_TXID;
            }))
            ->willReturn(true);

        $this->service->handleIncomingProposal([
            'proposalId' => self::TEST_PROPOSAL_ID,
            'missingTxid' => self::TEST_MISSING_TXID,
            'brokenTxid' => self::TEST_BROKEN_TXID,
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS
        ]);
    }

    /**
     * Test handleIncomingProposal rejects when local chain has no gap
     */
    public function testHandleIncomingProposalRejectsIfNoLocalGap(): void
    {
        $senderPubkeyHash = hash('sha256', self::TEST_CONTACT_PUBKEY);

        // No existing active proposal
        $this->proposalRepository->expects($this->once())
            ->method('getActiveProposalForGap')
            ->with($senderPubkeyHash, self::TEST_MISSING_TXID)
            ->willReturn(null);

        // Local chain is valid (no gap)
        $this->transactionChainRepository->expects($this->once())
            ->method('verifyChainIntegrity')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn([
                'valid' => true,
                'gaps' => [],
                'broken_txids' => [],
                'transaction_count' => 10
            ]);

        // Proposal should NOT be stored
        $this->proposalRepository->expects($this->never())
            ->method('createProposal');

        // Rejection should be sent back
        $this->transportUtility->expects($this->once())
            ->method('send')
            ->with(
                self::TEST_CONTACT_ADDRESS,
                $this->callback(function (array $payload) {
                    return isset($payload['action']) && $payload['action'] === 'reject';
                })
            );

        $this->service->handleIncomingProposal([
            'proposalId' => self::TEST_PROPOSAL_ID,
            'missingTxid' => self::TEST_MISSING_TXID,
            'brokenTxid' => self::TEST_BROKEN_TXID,
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS
        ]);
    }

    // =========================================================================
    // handleIncomingProposal() Simultaneous Proposal Tiebreak Tests
    // =========================================================================

    /**
     * Create a ChainDropService with a custom user pubkey hash for tiebreak tests
     */
    private function createServiceWithPubkeyHash(string $pubkeyHash): ChainDropService
    {
        $userContext = $this->createMock(UserContext::class);
        $userContext->method('getPublicKey')->willReturn(self::TEST_USER_PUBKEY);
        $userContext->method('getPublicKeyHash')->willReturn($pubkeyHash);
        $userContext->method('getUserAddresses')->willReturn(['http://myaddress.example.com']);

        return new ChainDropService(
            $this->proposalRepository,
            $this->transactionChainRepository,
            $this->transactionRepository,
            $this->contactRepository,
            $this->utilityContainer,
            $userContext
        );
    }

    /**
     * Test: when both sides propose simultaneously and we win the tiebreak (lower hash),
     * auto-accept by executing the drop and sending acceptance for their proposal
     */
    public function testHandleIncomingProposalAutoAcceptsOnMutualProposalWhenTiebreakWins(): void
    {
        $contactPubkeyHash = hash('sha256', self::TEST_CONTACT_PUBKEY);

        // Our hash is lower → we win the tiebreak
        $service = $this->createServiceWithPubkeyHash(str_repeat('0', 64));

        $existingOutgoing = [
            'proposal_id' => 'cdp-our-outgoing',
            'contact_pubkey_hash' => $contactPubkeyHash,
            'missing_txid' => self::TEST_MISSING_TXID,
            'broken_txid' => self::TEST_BROKEN_TXID,
            'previous_txid_before_gap' => self::TEST_PREVIOUS_TXID,
            'direction' => 'outgoing',
            'status' => 'pending'
        ];

        $this->proposalRepository->expects($this->once())
            ->method('getActiveProposalForGap')
            ->with($contactPubkeyHash, self::TEST_MISSING_TXID)
            ->willReturn($existingOutgoing);

        // executeChainDrop: update previous_txid succeeds
        $this->transactionChainRepository->expects($this->once())
            ->method('updatePreviousTxid')
            ->with(self::TEST_BROKEN_TXID, self::TEST_PREVIOUS_TXID)
            ->willReturn(true);

        // executeChainDrop: transaction not sent by us (no re-sign needed)
        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with(self::TEST_BROKEN_TXID)
            ->willReturn(['sender_public_key_hash' => 'other-sender-hash', 'memo' => 'standard']);

        // For updateChainStatusAfterDrop
        $this->transactionChainRepository->method('verifyChainIntegrity')
            ->willReturn(['valid' => true, 'gaps' => [], 'broken_txids' => [], 'transaction_count' => 7]);

        // Contact lookups for balance sync, chain status, and address resolution
        $this->contactRepository->method('lookupByPubkeyHash')
            ->willReturn([
                'pubkey' => self::TEST_CONTACT_PUBKEY,
                'http' => self::TEST_CONTACT_ADDRESS
            ]);

        // Verify markExecuted called on our outgoing proposal
        $this->proposalRepository->expects($this->once())
            ->method('markExecuted')
            ->with('cdp-our-outgoing');

        // Verify createProposal is NOT called (no incoming proposal stored)
        $this->proposalRepository->expects($this->never())
            ->method('createProposal');

        $service->handleIncomingProposal([
            'proposalId' => self::TEST_PROPOSAL_ID,
            'missingTxid' => self::TEST_MISSING_TXID,
            'brokenTxid' => self::TEST_BROKEN_TXID,
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS
        ]);
    }

    /**
     * Test: when both sides propose simultaneously and we lose the tiebreak (higher hash),
     * silently return and let the other side handle it
     */
    public function testHandleIncomingProposalDefersOnMutualProposalWhenTiebreakLoses(): void
    {
        $contactPubkeyHash = hash('sha256', self::TEST_CONTACT_PUBKEY);

        // Our hash is higher → we lose the tiebreak
        $service = $this->createServiceWithPubkeyHash(str_repeat('f', 64));

        $existingOutgoing = [
            'proposal_id' => 'cdp-our-outgoing',
            'contact_pubkey_hash' => $contactPubkeyHash,
            'missing_txid' => self::TEST_MISSING_TXID,
            'broken_txid' => self::TEST_BROKEN_TXID,
            'previous_txid_before_gap' => self::TEST_PREVIOUS_TXID,
            'direction' => 'outgoing',
            'status' => 'pending'
        ];

        $this->proposalRepository->expects($this->once())
            ->method('getActiveProposalForGap')
            ->with($contactPubkeyHash, self::TEST_MISSING_TXID)
            ->willReturn($existingOutgoing);

        // Verify no chain drop execution
        $this->transactionChainRepository->expects($this->never())
            ->method('updatePreviousTxid');

        // Verify markExecuted is NOT called
        $this->proposalRepository->expects($this->never())
            ->method('markExecuted');

        // Verify createProposal is NOT called
        $this->proposalRepository->expects($this->never())
            ->method('createProposal');

        $service->handleIncomingProposal([
            'proposalId' => self::TEST_PROPOSAL_ID,
            'missingTxid' => self::TEST_MISSING_TXID,
            'brokenTxid' => self::TEST_BROKEN_TXID,
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS
        ]);
    }

    /**
     * Test: when an incoming proposal arrives for a gap that already has an incoming proposal,
     * skip it (true duplicate — not a simultaneous proposal)
     */
    public function testHandleIncomingProposalSkipsDuplicateIncomingProposal(): void
    {
        $contactPubkeyHash = hash('sha256', self::TEST_CONTACT_PUBKEY);

        $existingIncoming = [
            'proposal_id' => 'cdp-existing-incoming',
            'contact_pubkey_hash' => $contactPubkeyHash,
            'missing_txid' => self::TEST_MISSING_TXID,
            'broken_txid' => self::TEST_BROKEN_TXID,
            'previous_txid_before_gap' => self::TEST_PREVIOUS_TXID,
            'direction' => 'incoming',
            'status' => 'pending'
        ];

        $this->proposalRepository->expects($this->once())
            ->method('getActiveProposalForGap')
            ->with($contactPubkeyHash, self::TEST_MISSING_TXID)
            ->willReturn($existingIncoming);

        // Verify no chain drop execution
        $this->transactionChainRepository->expects($this->never())
            ->method('updatePreviousTxid');

        // Verify createProposal is NOT called
        $this->proposalRepository->expects($this->never())
            ->method('createProposal');

        // Verify markExecuted is NOT called
        $this->proposalRepository->expects($this->never())
            ->method('markExecuted');

        $this->service->handleIncomingProposal([
            'proposalId' => self::TEST_PROPOSAL_ID,
            'missingTxid' => self::TEST_MISSING_TXID,
            'brokenTxid' => self::TEST_BROKEN_TXID,
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS
        ]);
    }

    // =========================================================================
    // acceptProposal() Tests
    // =========================================================================

    /**
     * Test acceptProposal succeeds for a pending incoming proposal
     */
    public function testAcceptProposalSuccess(): void
    {
        $contactPubkeyHash = hash('sha256', self::TEST_CONTACT_PUBKEY);

        $proposal = [
            'proposal_id' => self::TEST_PROPOSAL_ID,
            'contact_pubkey_hash' => $contactPubkeyHash,
            'missing_txid' => self::TEST_MISSING_TXID,
            'broken_txid' => self::TEST_BROKEN_TXID,
            'previous_txid_before_gap' => self::TEST_PREVIOUS_TXID,
            'direction' => 'incoming',
            'status' => 'pending'
        ];

        $this->proposalRepository->expects($this->once())
            ->method('getByProposalId')
            ->with(self::TEST_PROPOSAL_ID)
            ->willReturn($proposal);

        // executeChainDrop: update previous_txid succeeds
        $this->transactionChainRepository->expects($this->once())
            ->method('updatePreviousTxid')
            ->with(self::TEST_BROKEN_TXID, self::TEST_PREVIOUS_TXID)
            ->willReturn(true);

        // executeChainDrop: getByTxid returns transaction not sent by us
        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with(self::TEST_BROKEN_TXID)
            ->willReturn([
                'sender_public_key_hash' => 'other-sender-hash',
                'memo' => 'standard'
            ]);

        // Status should be updated to accepted
        $this->proposalRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_PROPOSAL_ID, 'accepted');

        // Contact address lookup (called for balance sync and sending acceptance)
        $this->contactRepository->expects($this->exactly(2))
            ->method('lookupByPubkeyHash')
            ->with($contactPubkeyHash)
            ->willReturn(['http' => self::TEST_CONTACT_ADDRESS]);

        $result = $this->service->acceptProposal(self::TEST_PROPOSAL_ID);

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
    }

    /**
     * Test acceptProposal rejects when proposal is not in pending status
     */
    public function testAcceptProposalRejectsNonPending(): void
    {
        $proposal = [
            'proposal_id' => self::TEST_PROPOSAL_ID,
            'contact_pubkey_hash' => hash('sha256', self::TEST_CONTACT_PUBKEY),
            'direction' => 'incoming',
            'status' => 'accepted'
        ];

        $this->proposalRepository->expects($this->once())
            ->method('getByProposalId')
            ->with(self::TEST_PROPOSAL_ID)
            ->willReturn($proposal);

        // Should not attempt to execute the drop
        $this->transactionChainRepository->expects($this->never())
            ->method('updatePreviousTxid');

        $result = $this->service->acceptProposal(self::TEST_PROPOSAL_ID);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no longer pending', $result['error']);
    }

    // =========================================================================
    // rejectProposal() Tests
    // =========================================================================

    /**
     * Test rejectProposal succeeds for a pending incoming proposal
     */
    public function testRejectProposalSuccess(): void
    {
        $contactPubkeyHash = hash('sha256', self::TEST_CONTACT_PUBKEY);

        $proposal = [
            'proposal_id' => self::TEST_PROPOSAL_ID,
            'contact_pubkey_hash' => $contactPubkeyHash,
            'direction' => 'incoming',
            'status' => 'pending'
        ];

        $this->proposalRepository->expects($this->once())
            ->method('getByProposalId')
            ->with(self::TEST_PROPOSAL_ID)
            ->willReturn($proposal);

        // Status should be updated to rejected
        $this->proposalRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_PROPOSAL_ID, 'rejected');

        // Contact address lookup for sending rejection notification
        $this->contactRepository->expects($this->once())
            ->method('lookupByPubkeyHash')
            ->with($contactPubkeyHash)
            ->willReturn(['http' => self::TEST_CONTACT_ADDRESS]);

        // Rejection message sent to contact
        $this->transportUtility->expects($this->once())
            ->method('send')
            ->with(
                self::TEST_CONTACT_ADDRESS,
                $this->callback(function (array $payload) {
                    return isset($payload['action']) && $payload['action'] === 'reject'
                        && isset($payload['proposalId']) && $payload['proposalId'] === self::TEST_PROPOSAL_ID;
                })
            );

        $result = $this->service->rejectProposal(self::TEST_PROPOSAL_ID);

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
    }

    // =========================================================================
    // expireStaleProposals() Tests
    // =========================================================================

    /**
     * Test expireStaleProposals delegates to repository
     */
    public function testExpireStaleProposals(): void
    {
        $this->proposalRepository->expects($this->once())
            ->method('expireOldProposals')
            ->willReturn(3);

        $result = $this->service->expireStaleProposals();

        $this->assertEquals(3, $result);
    }

    // =========================================================================
    // getIncomingPendingProposals() Tests
    // =========================================================================

    /**
     * Test getIncomingPendingProposals delegates to repository
     */
    public function testGetIncomingPendingProposals(): void
    {
        $expected = [
            ['proposal_id' => 'cdp-1', 'direction' => 'incoming', 'status' => 'pending'],
            ['proposal_id' => 'cdp-2', 'direction' => 'incoming', 'status' => 'pending']
        ];

        $this->proposalRepository->expects($this->once())
            ->method('getIncomingPending')
            ->willReturn($expected);

        $result = $this->service->getIncomingPendingProposals();

        $this->assertCount(2, $result);
        $this->assertEquals($expected, $result);
    }

    // =========================================================================
    // getProposalsForContact() Tests
    // =========================================================================

    /**
     * Test getProposalsForContact delegates to repository
     */
    public function testGetProposalsForContact(): void
    {
        $contactPubkeyHash = hash('sha256', self::TEST_CONTACT_PUBKEY);
        $expected = [
            ['proposal_id' => 'cdp-1', 'contact_pubkey_hash' => $contactPubkeyHash, 'status' => 'pending'],
            ['proposal_id' => 'cdp-2', 'contact_pubkey_hash' => $contactPubkeyHash, 'status' => 'accepted']
        ];

        $this->proposalRepository->expects($this->once())
            ->method('getPendingForContact')
            ->with($contactPubkeyHash)
            ->willReturn($expected);

        $result = $this->service->getProposalsForContact($contactPubkeyHash);

        $this->assertCount(2, $result);
        $this->assertEquals($expected, $result);
    }

    // =========================================================================
    // Auto-Accept Toggle and Balance Guard Tests
    // =========================================================================

    /**
     * Test auto-accept triggers when toggle is ON and balance guard is safe
     */
    public function testAutoAcceptTriggersWhenBalanceGuardSafe(): void
    {
        // Enable auto-accept on the mock UserContext
        $this->userContext->method('getAutoChainDropAccept')
            ->willReturn(true);
        $this->userContext->method('getAutoChainDropAcceptGuard')
            ->willReturn(true);

        $senderPubkeyHash = hash('sha256', self::TEST_CONTACT_PUBKEY);

        // Wire BalanceRepository for balance guard
        $this->service->setBalanceRepository($this->balanceRepository);

        // No existing active proposal
        $this->proposalRepository->method('getActiveProposalForGap')
            ->willReturn(null);

        // Chain integrity: gap on first call, valid on second (after drop)
        $this->transactionChainRepository->method('verifyChainIntegrity')
            ->willReturnOnConsecutiveCalls(
                [
                    'valid' => false,
                    'gaps' => [self::TEST_MISSING_TXID],
                    'broken_txids' => [self::TEST_BROKEN_TXID],
                    'transaction_count' => 8
                ],
                ['valid' => true, 'gaps' => [], 'broken_txids' => [], 'transaction_count' => 7]
            );

        // Chain lookup for findPreviousTxidBeforeGap
        $this->transactionChainRepository->method('getTransactionChain')
            ->willReturn([
                ['txid' => self::TEST_PREVIOUS_TXID],
                ['txid' => self::TEST_BROKEN_TXID]
            ]);

        // Proposal creation succeeds
        $this->proposalRepository->method('createProposal')
            ->willReturn(true);

        // Balance guard: no transactions between us = no debt risk
        $this->transactionRepository->method('getTransactionsBetweenPubkeys')
            ->willReturn([]);

        // acceptProposal: return the stored proposal
        $this->proposalRepository->method('getByProposalId')
            ->with(self::TEST_PROPOSAL_ID)
            ->willReturn([
                'proposal_id' => self::TEST_PROPOSAL_ID,
                'contact_pubkey_hash' => $senderPubkeyHash,
                'missing_txid' => self::TEST_MISSING_TXID,
                'broken_txid' => self::TEST_BROKEN_TXID,
                'previous_txid_before_gap' => self::TEST_PREVIOUS_TXID,
                'direction' => 'incoming',
                'status' => 'pending'
            ]);

        // executeChainDrop
        $this->transactionChainRepository->method('updatePreviousTxid')
            ->willReturn(true);
        $this->transactionRepository->method('getByTxid')
            ->willReturn(['sender_public_key_hash' => 'other-sender-hash', 'memo' => 'standard']);

        // Contact lookups (for updateChainStatusAfterDrop and resolveContactAddress)
        $this->contactRepository->method('lookupByPubkeyHash')
            ->willReturn([
                'pubkey' => self::TEST_CONTACT_PUBKEY,
                'http' => self::TEST_CONTACT_ADDRESS
            ]);

        // KEY ASSERTION: updateStatus called with 'accepted' proves auto-accept executed
        $this->proposalRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_PROPOSAL_ID, 'accepted');

        $this->service->handleIncomingProposal([
            'proposalId' => self::TEST_PROPOSAL_ID,
            'missingTxid' => self::TEST_MISSING_TXID,
            'brokenTxid' => self::TEST_BROKEN_TXID,
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS
        ]);
    }

    /**
     * Test auto-accept blocked when balance guard detects debt erasure risk
     */
    public function testAutoAcceptBlockedByBalanceGuard(): void
    {
        // Enable auto-accept with guard on the mock UserContext
        $this->userContext->method('getAutoChainDropAccept')
            ->willReturn(true);
        $this->userContext->method('getAutoChainDropAcceptGuard')
            ->willReturn(true);

        // Wire BalanceRepository for balance guard
        $this->service->setBalanceRepository($this->balanceRepository);

        // No existing active proposal
        $this->proposalRepository->method('getActiveProposalForGap')
            ->willReturn(null);

        // Local chain has the gap
        $this->transactionChainRepository->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'gaps' => [self::TEST_MISSING_TXID],
                'broken_txids' => [self::TEST_BROKEN_TXID],
                'transaction_count' => 8
            ]);

        $this->transactionChainRepository->method('getTransactionChain')
            ->willReturn([
                ['txid' => self::TEST_PREVIOUS_TXID],
                ['txid' => self::TEST_BROKEN_TXID]
            ]);

        $this->proposalRepository->method('createProposal')
            ->willReturn(true);

        // Balance guard: transaction shows we received 1000 from contact
        $this->transactionRepository->method('getTransactionsBetweenPubkeys')
            ->willReturn([
                [
                    'status' => 'completed',
                    'currency' => 'USD',
                    'sender_address' => self::TEST_CONTACT_ADDRESS,
                    'receiver_address' => 'http://myaddress.example.com',
                    'amount' => 1000
                ]
            ]);

        // Stored balance shows higher received than transaction sum
        // missing_received = 2000 - 1000 = 1000, missing_sent = 0, net_missing = 1000 > 0 → BLOCKED
        $this->balanceRepository->method('getContactReceivedBalance')
            ->willReturn(2000);
        $this->balanceRepository->method('getContactSentBalance')
            ->willReturn(0);

        // KEY ASSERTION: acceptProposal should NOT be called (guard blocks)
        $this->proposalRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->handleIncomingProposal([
            'proposalId' => self::TEST_PROPOSAL_ID,
            'missingTxid' => self::TEST_MISSING_TXID,
            'brokenTxid' => self::TEST_BROKEN_TXID,
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS
        ]);
    }

    /**
     * Test auto-accept skipped when toggle is OFF (default)
     */
    public function testAutoAcceptSkippedWhenToggleOff(): void
    {
        // Default is OFF — no env var needed
        // Wire BalanceRepository (would be safe, but toggle is OFF)
        $this->service->setBalanceRepository($this->balanceRepository);

        $this->proposalRepository->method('getActiveProposalForGap')
            ->willReturn(null);

        $this->transactionChainRepository->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'gaps' => [self::TEST_MISSING_TXID],
                'broken_txids' => [self::TEST_BROKEN_TXID],
                'transaction_count' => 8
            ]);

        $this->transactionChainRepository->method('getTransactionChain')
            ->willReturn([
                ['txid' => self::TEST_PREVIOUS_TXID],
                ['txid' => self::TEST_BROKEN_TXID]
            ]);

        $this->proposalRepository->method('createProposal')
            ->willReturn(true);

        // KEY ASSERTIONS: neither balance guard nor acceptProposal should be called
        $this->transactionRepository->expects($this->never())
            ->method('getTransactionsBetweenPubkeys');
        $this->proposalRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->handleIncomingProposal([
            'proposalId' => self::TEST_PROPOSAL_ID,
            'missingTxid' => self::TEST_MISSING_TXID,
            'brokenTxid' => self::TEST_BROKEN_TXID,
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS
        ]);
    }

    /**
     * Test auto-accept blocked when BalanceRepository is not wired
     */
    public function testAutoAcceptBlockedWithoutBalanceRepository(): void
    {
        // Enable auto-accept with guard on the mock UserContext
        $this->userContext->method('getAutoChainDropAccept')
            ->willReturn(true);
        $this->userContext->method('getAutoChainDropAcceptGuard')
            ->willReturn(true);

        // Do NOT wire BalanceRepository — isAutoAcceptSafe returns false

        $this->proposalRepository->method('getActiveProposalForGap')
            ->willReturn(null);

        $this->transactionChainRepository->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'gaps' => [self::TEST_MISSING_TXID],
                'broken_txids' => [self::TEST_BROKEN_TXID],
                'transaction_count' => 8
            ]);

        $this->transactionChainRepository->method('getTransactionChain')
            ->willReturn([
                ['txid' => self::TEST_PREVIOUS_TXID],
                ['txid' => self::TEST_BROKEN_TXID]
            ]);

        $this->proposalRepository->method('createProposal')
            ->willReturn(true);

        // KEY ASSERTION: acceptProposal should NOT be called (guard fails without BalanceRepository)
        $this->proposalRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->handleIncomingProposal([
            'proposalId' => self::TEST_PROPOSAL_ID,
            'missingTxid' => self::TEST_MISSING_TXID,
            'brokenTxid' => self::TEST_BROKEN_TXID,
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS
        ]);
    }

    /**
     * Test auto-propose skipped in Constants when toggle is OFF via env var
     */
    public function testAutoChainDropProposeToggle(): void
    {
        // Default should be enabled
        $this->assertTrue(Constants::isAutoChainDropProposeEnabled());

        // Disable via env var
        putenv('EIOU_AUTO_CHAIN_DROP_PROPOSE=false');
        try {
            $this->assertFalse(Constants::isAutoChainDropProposeEnabled());
        } finally {
            putenv('EIOU_AUTO_CHAIN_DROP_PROPOSE');
        }

        // Should be back to default
        $this->assertTrue(Constants::isAutoChainDropProposeEnabled());
    }

    /**
     * Test auto-accept toggle in Constants with env var override
     */
    public function testAutoChainDropAcceptToggle(): void
    {
        // Default should be disabled (safety)
        $this->assertFalse(Constants::isAutoChainDropAcceptEnabled());

        // Enable via env var
        putenv('EIOU_AUTO_CHAIN_DROP_ACCEPT=true');
        try {
            $this->assertTrue(Constants::isAutoChainDropAcceptEnabled());
        } finally {
            putenv('EIOU_AUTO_CHAIN_DROP_ACCEPT');
        }

        // Should be back to default (disabled)
        $this->assertFalse(Constants::isAutoChainDropAcceptEnabled());
    }

    /**
     * Test auto-accept guard toggle in Constants with env var override
     */
    public function testAutoChainDropAcceptGuardToggle(): void
    {
        // Default should be enabled (safety)
        $this->assertTrue(Constants::isAutoChainDropAcceptGuardEnabled());

        // Disable via env var
        putenv('EIOU_AUTO_CHAIN_DROP_ACCEPT_GUARD=false');
        try {
            $this->assertFalse(Constants::isAutoChainDropAcceptGuardEnabled());
        } finally {
            putenv('EIOU_AUTO_CHAIN_DROP_ACCEPT_GUARD');
        }

        // Should be back to default (enabled)
        $this->assertTrue(Constants::isAutoChainDropAcceptGuardEnabled());
    }

    /**
     * Test auto-accept bypasses balance guard when guard is disabled
     */
    public function testAutoAcceptBypassesGuardWhenGuardDisabled(): void
    {
        // Enable auto-accept AND disable the guard on the mock UserContext
        $this->userContext->method('getAutoChainDropAccept')
            ->willReturn(true);
        $this->userContext->method('getAutoChainDropAcceptGuard')
            ->willReturn(false);

        $senderPubkeyHash = hash('sha256', self::TEST_CONTACT_PUBKEY);

        // Wire BalanceRepository — would block if guard were enabled
        $this->service->setBalanceRepository($this->balanceRepository);

        $this->proposalRepository->method('getActiveProposalForGap')
            ->willReturn(null);

        $this->transactionChainRepository->method('verifyChainIntegrity')
            ->willReturnOnConsecutiveCalls(
                [
                    'valid' => false,
                    'gaps' => [self::TEST_MISSING_TXID],
                    'broken_txids' => [self::TEST_BROKEN_TXID],
                    'transaction_count' => 8
                ],
                ['valid' => true, 'gaps' => [], 'broken_txids' => [], 'transaction_count' => 7]
            );

        $this->transactionChainRepository->method('getTransactionChain')
            ->willReturn([
                ['txid' => self::TEST_PREVIOUS_TXID],
                ['txid' => self::TEST_BROKEN_TXID]
            ]);

        $this->proposalRepository->method('createProposal')
            ->willReturn(true);

        // Balance guard would block this (debt erasure risk), but guard is disabled
        $this->transactionRepository->method('getTransactionsBetweenPubkeys')
            ->willReturn([
                [
                    'status' => 'completed',
                    'currency' => 'USD',
                    'sender_address' => self::TEST_CONTACT_ADDRESS,
                    'receiver_address' => 'http://myaddress.example.com',
                    'amount' => 1000
                ]
            ]);

        $this->balanceRepository->method('getContactReceivedBalance')
            ->willReturn(2000);
        $this->balanceRepository->method('getContactSentBalance')
            ->willReturn(0);

        $this->proposalRepository->method('getByProposalId')
            ->with(self::TEST_PROPOSAL_ID)
            ->willReturn([
                'proposal_id' => self::TEST_PROPOSAL_ID,
                'contact_pubkey_hash' => $senderPubkeyHash,
                'missing_txid' => self::TEST_MISSING_TXID,
                'broken_txid' => self::TEST_BROKEN_TXID,
                'previous_txid_before_gap' => self::TEST_PREVIOUS_TXID,
                'direction' => 'incoming',
                'status' => 'pending'
            ]);

        $this->transactionChainRepository->method('updatePreviousTxid')
            ->willReturn(true);
        $this->transactionRepository->method('getByTxid')
            ->willReturn(['sender_public_key_hash' => 'other-sender-hash', 'memo' => 'standard']);

        $this->contactRepository->method('lookupByPubkeyHash')
            ->willReturn([
                'pubkey' => self::TEST_CONTACT_PUBKEY,
                'http' => self::TEST_CONTACT_ADDRESS
            ]);

        // KEY ASSERTION: guard is disabled, so auto-accept proceeds despite risky balances
        $this->proposalRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_PROPOSAL_ID, 'accepted');

        // isAutoAcceptSafe should NOT be called when guard is disabled
        $this->balanceRepository->expects($this->never())
            ->method('getContactReceivedBalance');

        $this->service->handleIncomingProposal([
            'proposalId' => self::TEST_PROPOSAL_ID,
            'missingTxid' => self::TEST_MISSING_TXID,
            'brokenTxid' => self::TEST_BROKEN_TXID,
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS
        ]);
    }
}
