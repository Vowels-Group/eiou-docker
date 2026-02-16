<?php
/**
 * Unit Tests for Rp2pService Cascade Cancel Notification
 *
 * Tests cascade cancel notification handling in Rp2pService including:
 * - checkRp2pPossible with cancelled request in best-fee vs fast mode
 * - handleCancelNotification response counting and selection triggering
 * - selectAndForwardBestRp2p with no candidates (cancel + propagate)
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\Rp2pService;
use Eiou\Services\MessageDeliveryService;
use Eiou\Database\ContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\P2pSenderRepository;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Database\P2pRelayedContactRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Contracts\P2pTransactionSenderInterface;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;

#[CoversClass(Rp2pService::class)]
class Rp2pServiceCascadeCancelTest extends TestCase
{
    private MockObject|ContactRepository $contactRepository;
    private MockObject|BalanceRepository $balanceRepository;
    private MockObject|P2pRepository $p2pRepository;
    private MockObject|Rp2pRepository $rp2pRepository;
    private MockObject|UtilityServiceContainer $utilityContainer;
    private MockObject|ValidationUtilityService $validationUtility;
    private MockObject|TransportUtilityService $transportUtility;
    private MockObject|TimeUtilityService $timeUtility;
    private MockObject|UserContext $userContext;
    private MockObject|MessageDeliveryService $messageDeliveryService;
    private MockObject|P2pTransactionSenderInterface $p2pTransactionSender;
    private MockObject|P2pSenderRepository $p2pSenderRepository;
    private MockObject|P2pServiceInterface $p2pService;
    private Rp2pService $service;

    private const TEST_ADDRESS = 'http://test.example.com';
    private const TEST_PUBLIC_KEY = 'test-public-key-1234567890';
    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_AMOUNT = 10000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contactRepository = $this->createMock(ContactRepository::class);
        $this->balanceRepository = $this->createMock(BalanceRepository::class);
        $this->p2pRepository = $this->createMock(P2pRepository::class);
        $this->rp2pRepository = $this->createMock(Rp2pRepository::class);
        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->validationUtility = $this->createMock(ValidationUtilityService::class);
        $this->transportUtility = $this->createMock(TransportUtilityService::class);
        $this->timeUtility = $this->createMock(TimeUtilityService::class);
        $this->userContext = $this->createMock(UserContext::class);
        $this->messageDeliveryService = $this->createMock(MessageDeliveryService::class);
        $this->p2pTransactionSender = $this->createMock(P2pTransactionSenderInterface::class);
        $this->p2pSenderRepository = $this->createMock(P2pSenderRepository::class);
        $this->p2pService = $this->createMock(P2pServiceInterface::class);

        // Setup utility container
        $this->utilityContainer->method('getValidationUtility')
            ->willReturn($this->validationUtility);
        $this->utilityContainer->method('getTransportUtility')
            ->willReturn($this->transportUtility);
        $this->utilityContainer->method('getTimeUtility')
            ->willReturn($this->timeUtility);

        // Setup transport utility to return address as-is
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturnCallback(fn($address) => $address ?? self::TEST_ADDRESS);

        // Setup default user context
        $this->userContext->method('getMaxFee')
            ->willReturn(5.0);
        $this->userContext->method('getPublicKey')
            ->willReturn(self::TEST_PUBLIC_KEY);

        $this->service = new Rp2pService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->rp2pRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->messageDeliveryService
        );
    }

    /**
     * Helper to create a service with rp2pCandidateRepository and p2pService injected
     */
    private function createServiceWithCandidateRepoAndP2pService(
        ?MockObject $rp2pCandidateRepo = null,
        ?MockObject $p2pRelayedContactRepo = null
    ): Rp2pService {
        $rp2pCandidateRepo = $rp2pCandidateRepo ?? $this->createMock(Rp2pCandidateRepository::class);
        $service = new Rp2pService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->rp2pRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->messageDeliveryService,
            $rp2pCandidateRepo,
            $this->p2pSenderRepository
        );
        $service->setP2pService($this->p2pService);
        if ($p2pRelayedContactRepo) {
            $service->setP2pRelayedContactRepository($p2pRelayedContactRepo);
        }
        return $service;
    }

    // =========================================================================
    // checkRp2pPossible with cancelled request Tests
    // =========================================================================

    /**
     * Test checkRp2pPossible processes cancel notification in best-fee mode (fast=0)
     *
     * When request has cancelled => true and P2P is best-fee mode (fast=0),
     * should call handleCancelNotification and echo JSON response.
     */
    public function testCheckRp2pPossibleProcessesCancelInBestFeeMode(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'cancelled' => true,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'fast' => 0, // best-fee mode
            'sender_public_key' => self::TEST_PUBLIC_KEY,
        ];

        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn($p2p);

        // handleCancelNotification checks rp2pExists first
        $this->rp2pRepository->method('rp2pExists')
            ->with(self::TEST_HASH)
            ->willReturn(false);

        // handleCancelNotification increments responded count
        $this->p2pRepository->expects($this->once())
            ->method('incrementContactsRespondedCount')
            ->with(self::TEST_HASH);

        // After increment, getTrackingCounts is called
        $this->p2pRepository->method('getTrackingCounts')
            ->willReturn([
                'contacts_sent_count' => 3,
                'contacts_responded_count' => 1, // not all yet
                'contacts_relayed_count' => 0,
                'fast' => 0,
            ]);

        ob_start();
        $result = $this->service->checkRp2pPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('received', $decoded['status']);
    }

    /**
     * Test checkRp2pPossible processes cancel notification in fast mode (fast=1)
     *
     * Both fast and best-fee modes need cancel cascade so relay nodes can detect
     * when ALL contacts are dead ends and propagate cancel upstream.
     */
    public function testCheckRp2pPossibleProcessesCancelInFastMode(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'cancelled' => true,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'fast' => 1, // fast mode
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'status' => 'sent',
        ];

        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn($p2p);

        // handleCancelNotification checks rp2pExists first
        $this->rp2pRepository->method('rp2pExists')
            ->with(self::TEST_HASH)
            ->willReturn(false);

        // handleCancelNotification increments responded count
        $this->p2pRepository->expects($this->once())
            ->method('incrementContactsRespondedCount')
            ->with(self::TEST_HASH);

        // After increment, getTrackingCounts is called
        $this->p2pRepository->method('getTrackingCounts')
            ->willReturn([
                'contacts_sent_count' => 3,
                'contacts_responded_count' => 1, // not all yet
                'contacts_relayed_count' => 0,
                'fast' => 1,
            ]);

        ob_start();
        $result = $this->service->checkRp2pPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('received', $decoded['status']);
    }

    /**
     * Test checkRp2pPossible returns false and echoes JSON for cancel request
     */
    public function testCheckRp2pPossibleReturnsFalseForCancelRequest(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'cancelled' => true,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'fast' => 0,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        $this->p2pRepository->method('getTrackingCounts')
            ->willReturn([
                'contacts_sent_count' => 2,
                'contacts_responded_count' => 1,
                'contacts_relayed_count' => 0,
                'fast' => 0,
            ]);

        ob_start();
        $result = $this->service->checkRp2pPossible($request, true);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('received', $decoded['status']);
        $this->assertStringContainsString('cancel notification processed', $decoded['message']);
    }

    /**
     * Test checkRp2pPossible does not echo for cancel when echo=false
     */
    public function testCheckRp2pPossibleDoesNotEchoCancelWhenEchoDisabled(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'cancelled' => true,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'fast' => 0,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        $this->p2pRepository->method('getTrackingCounts')
            ->willReturn([
                'contacts_sent_count' => 2,
                'contacts_responded_count' => 1,
                'contacts_relayed_count' => 0,
                'fast' => 0,
            ]);

        ob_start();
        $result = $this->service->checkRp2pPossible($request, false);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertEmpty($output);
    }

    /**
     * Test checkRp2pPossible handles cancel when P2P not found
     *
     * If P2P record is null, the cancel path returns false without error.
     */
    public function testCheckRp2pPossibleHandlesCancelWhenP2pNotFound(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'cancelled' => true,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn(null);

        // handleCancelNotification should NOT be called
        $this->p2pRepository->expects($this->never())
            ->method('incrementContactsRespondedCount');

        ob_start();
        $result = $this->service->checkRp2pPossible($request);
        ob_get_clean();

        $this->assertFalse($result);
    }

    // =========================================================================
    // handleCancelNotification() Tests
    // =========================================================================

    /**
     * Test handleCancelNotification skips processing when P2P is already cancelled
     *
     * Prevents feedback loop: repeated cancel notifications should not increment
     * counters or re-trigger selection on an already-cancelled P2P.
     */
    public function testHandleCancelNotificationSkipsWhenAlreadyCancelled(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'status' => Constants::STATUS_CANCELLED,
        ];

        // Should NOT even check rp2pExists (returns before that)
        $this->rp2pRepository->expects($this->never())
            ->method('rp2pExists');

        // Should NOT increment counter
        $this->p2pRepository->expects($this->never())
            ->method('incrementContactsRespondedCount');

        // Should NOT trigger selection
        $this->p2pRepository->expects($this->never())
            ->method('getTrackingCounts');

        $this->service->handleCancelNotification($request, $p2p);
    }

    /**
     * Test handleCancelNotification skips processing when P2P is expired
     */
    public function testHandleCancelNotificationSkipsWhenExpired(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'status' => 'expired',
        ];

        $this->p2pRepository->expects($this->never())
            ->method('incrementContactsRespondedCount');

        $this->service->handleCancelNotification($request, $p2p);
    }

    /**
     * Test handleCancelNotification increments contacts_responded_count for inserted contact
     */
    public function testHandleCancelNotificationIncrementsRespondedCount(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'status' => 'sent',
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->with(self::TEST_HASH)
            ->willReturn(false);

        $this->p2pRepository->expects($this->once())
            ->method('incrementContactsRespondedCount')
            ->with(self::TEST_HASH);

        // Not all contacts responded yet
        $this->p2pRepository->method('getTrackingCounts')
            ->willReturn([
                'contacts_sent_count' => 3,
                'contacts_responded_count' => 1,
                'contacts_relayed_count' => 0,
                'fast' => 0,
            ]);

        $this->service->handleCancelNotification($request, $p2p);
    }

    /**
     * Test handleCancelNotification does nothing when rp2p already exists (already selected)
     */
    public function testHandleCancelNotificationSkipsWhenAlreadySelected(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'status' => 'sent',
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->with(self::TEST_HASH)
            ->willReturn(true);

        // Should NOT increment count if already selected
        $this->p2pRepository->expects($this->never())
            ->method('incrementContactsRespondedCount');

        $this->service->handleCancelNotification($request, $p2p);
    }

    /**
     * Test handleCancelNotification increments relayed responded count for relayed contact
     */
    public function testHandleCancelNotificationIncrementsRelayedRespondedCount(): void
    {
        $p2pRelayedContactRepo = $this->createMock(P2pRelayedContactRepository::class);
        $service = $this->createServiceWithCandidateRepoAndP2pService(null, $p2pRelayedContactRepo);

        $request = [
            'hash' => self::TEST_HASH,
            'senderAddress' => 'http://relayed-contact.test',
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        // The sender is a relayed contact
        $p2pRelayedContactRepo->method('isRelayedContact')
            ->with(self::TEST_HASH, 'http://relayed-contact.test')
            ->willReturn(true);

        $this->p2pRepository->expects($this->once())
            ->method('incrementContactsRelayedRespondedCount')
            ->with(self::TEST_HASH);

        // Should NOT increment the regular counter
        $this->p2pRepository->expects($this->never())
            ->method('incrementContactsRespondedCount');

        // Not all contacts responded yet
        $this->p2pRepository->method('getTrackingCounts')
            ->willReturn([
                'contacts_sent_count' => 2,
                'contacts_responded_count' => 2,
                'contacts_relayed_count' => 1,
                'contacts_relayed_responded_count' => 0,
                'fast' => 0,
            ]);

        $service->handleCancelNotification($request, $p2p);
    }

    /**
     * Test handleCancelNotification triggers selectAndForwardBestRp2p when all contacts responded
     * and candidates exist
     */
    public function testHandleCancelNotificationTriggersSelectionWhenAllResponded(): void
    {
        $rp2pCandidateRepo = $this->createMock(Rp2pCandidateRepository::class);
        $service = $this->createServiceWithCandidateRepoAndP2pService($rp2pCandidateRepo);

        $request = [
            'hash' => self::TEST_HASH,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        // All inserted contacts responded (no relayed)
        $this->p2pRepository->method('getTrackingCounts')
            ->willReturn([
                'contacts_sent_count' => 3,
                'contacts_responded_count' => 3,
                'contacts_relayed_count' => 0,
                'fast' => 0,
            ]);

        // selectAndForwardBestRp2p should be triggered: it calls getBestCandidate
        $rp2pCandidateRepo->expects($this->once())
            ->method('getBestCandidate')
            ->with(self::TEST_HASH)
            ->willReturn([
                'hash' => self::TEST_HASH,
                'time' => 1234567890,
                'amount' => 10050,
                'currency' => 'EIOU',
                'sender_public_key' => self::TEST_PUBLIC_KEY,
                'sender_address' => self::TEST_ADDRESS,
                'sender_signature' => 'test-sig',
                'fee_amount' => 50,
            ]);

        $rp2pCandidateRepo->method('getCandidateCount')
            ->willReturn(1);

        // P2P record for handleRp2pRequest
        $this->p2pRepository->method('getByHash')
            ->willReturn([
                'hash' => self::TEST_HASH,
                'amount' => self::TEST_AMOUNT,
                'my_fee_amount' => 50,
                'sender_address' => 'http://upstream.test',
                'sender_public_key' => self::TEST_PUBLIC_KEY,
            ]);

        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(100000);
        $this->contactRepository->method('getCreditLimit')
            ->willReturn(100000.0);
        $this->rp2pRepository->method('insertRp2pRequest')
            ->willReturn('test-rp2p-id');
        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1234567890);
        $this->messageDeliveryService->method('sendMessage')
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{}',
                'messageId' => 'test-msg',
            ]);

        $service->handleCancelNotification($request, $p2p);
    }

    /**
     * Test handleCancelNotification cancels P2P and propagates upstream when all
     * contacts responded and NO candidates exist
     */
    public function testHandleCancelNotificationCancelsAndPropagatesWhenNoCandidates(): void
    {
        $rp2pCandidateRepo = $this->createMock(Rp2pCandidateRepository::class);
        $service = $this->createServiceWithCandidateRepoAndP2pService($rp2pCandidateRepo);

        $request = [
            'hash' => self::TEST_HASH,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        // All inserted contacts responded (no relayed)
        $this->p2pRepository->method('getTrackingCounts')
            ->willReturn([
                'contacts_sent_count' => 2,
                'contacts_responded_count' => 2,
                'contacts_relayed_count' => 0,
                'fast' => 0,
            ]);

        // No best candidate found
        $rp2pCandidateRepo->expects($this->once())
            ->method('getBestCandidate')
            ->with(self::TEST_HASH)
            ->willReturn(null);

        // Should cancel the P2P
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_CANCELLED);

        // Should propagate cancel upstream via p2pService
        $this->p2pService->expects($this->once())
            ->method('sendCancelNotificationForHash')
            ->with(self::TEST_HASH);

        $service->handleCancelNotification($request, $p2p);
    }

    /**
     * Test handleCancelNotification triggers Phase 1 when inserted contacts all responded
     * but relayed contacts exist
     */
    public function testHandleCancelNotificationTriggersPhase1WhenRelayedPending(): void
    {
        $rp2pCandidateRepo = $this->createMock(Rp2pCandidateRepository::class);
        $p2pRelayedContactRepo = $this->createMock(P2pRelayedContactRepository::class);
        $service = $this->createServiceWithCandidateRepoAndP2pService($rp2pCandidateRepo, $p2pRelayedContactRepo);

        $request = [
            'hash' => self::TEST_HASH,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        // Phase 1: all inserted responded, relayed not yet
        $this->p2pRepository->method('getTrackingCounts')
            ->willReturn([
                'contacts_sent_count' => 2,
                'contacts_responded_count' => 2,
                'contacts_relayed_count' => 1,
                'contacts_relayed_responded_count' => 0,
                'phase1_sent' => 0,
                'fast' => 0,
            ]);

        // Phase 1 should mark as sent
        $this->p2pRepository->expects($this->once())
            ->method('markPhase1Sent')
            ->with(self::TEST_HASH);

        // Phase 1 looks up best candidate
        $rp2pCandidateRepo->method('getBestCandidate')
            ->willReturn([
                'hash' => self::TEST_HASH,
                'time' => 1234567890,
                'amount' => 10050,
                'currency' => 'EIOU',
                'sender_public_key' => self::TEST_PUBLIC_KEY,
                'sender_address' => self::TEST_ADDRESS,
                'sender_signature' => 'test-sig',
                'fee_amount' => 50,
            ]);

        // Phase 1 sends to relayed contacts
        $p2pRelayedContactRepo->expects($this->once())
            ->method('getRelayedContactsByHash')
            ->with(self::TEST_HASH)
            ->willReturn([
                ['contact_address' => 'http://relayed-contact.test'],
            ]);

        $service->handleCancelNotification($request, $p2p);
    }

    /**
     * Test Phase 1 sends cancel to relayed contacts when no candidates exist
     *
     * When all inserted contacts cancelled (getBestCandidate returns null),
     * sendBestCandidateToRelayedContacts should send cancel notifications to
     * relayed contacts so they can count the response and break mutual deadlocks.
     */
    public function testHandleCancelNotificationPhase1SendsCancelToRelayedWhenNoCandidates(): void
    {
        $rp2pCandidateRepo = $this->createMock(Rp2pCandidateRepository::class);
        $p2pRelayedContactRepo = $this->createMock(P2pRelayedContactRepository::class);
        $service = $this->createServiceWithCandidateRepoAndP2pService($rp2pCandidateRepo, $p2pRelayedContactRepo);

        $request = [
            'hash' => self::TEST_HASH,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        // Phase 1: all inserted responded, relayed not yet
        $this->p2pRepository->method('getTrackingCounts')
            ->willReturn([
                'contacts_sent_count' => 2,
                'contacts_responded_count' => 2,
                'contacts_relayed_count' => 1,
                'contacts_relayed_responded_count' => 0,
                'phase1_sent' => 0,
                'fast' => 0,
            ]);

        // Phase 1 should mark as sent
        $this->p2pRepository->expects($this->once())
            ->method('markPhase1Sent')
            ->with(self::TEST_HASH);

        // Phase 1 looks up best candidate — none found (all cancelled)
        $rp2pCandidateRepo->method('getBestCandidate')
            ->willReturn(null);

        // Should look up relayed contacts to send cancel
        $p2pRelayedContactRepo->expects($this->once())
            ->method('getRelayedContactsByHash')
            ->with(self::TEST_HASH)
            ->willReturn([
                ['contact_address' => 'http://relayed-contact.test'],
            ]);

        // Should send cancel message to relayed contact
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'rp2p',
                'http://relayed-contact.test',
                $this->callback(function ($payload) {
                    return $payload['type'] === 'rp2p'
                        && $payload['cancelled'] === true
                        && $payload['amount'] === 0
                        && $payload['hash'] === self::TEST_HASH;
                }),
                $this->anything(),
                false
            );

        $service->handleCancelNotification($request, $p2p);
    }

    /**
     * Test handleCancelNotification does not trigger selection when not all contacts responded
     */
    public function testHandleCancelNotificationDoesNotTriggerSelectionWhenNotAllResponded(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        // Not all contacts responded
        $this->p2pRepository->method('getTrackingCounts')
            ->willReturn([
                'contacts_sent_count' => 5,
                'contacts_responded_count' => 2,
                'contacts_relayed_count' => 0,
                'fast' => 0,
            ]);

        // selectAndForwardBestRp2p should NOT be triggered
        // (indicated by no getBestCandidate call or updateStatus with STATUS_CANCELLED)
        $this->p2pRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->handleCancelNotification($request, $p2p);
    }

    /**
     * Test handleCancelNotification does nothing when getTrackingCounts returns null
     */
    public function testHandleCancelNotificationHandlesNullTrackingCounts(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        $this->p2pRepository->method('getTrackingCounts')
            ->willReturn(null);

        // Should not crash or trigger selection
        $this->p2pRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->handleCancelNotification($request, $p2p);
    }

    // =========================================================================
    // selectAndForwardBestRp2p with no candidates Tests
    // =========================================================================

    /**
     * Test selectAndForwardBestRp2p cancels P2P when no candidates exist
     */
    public function testSelectAndForwardBestRp2pCancelsWhenNoCandidates(): void
    {
        $rp2pCandidateRepo = $this->createMock(Rp2pCandidateRepository::class);
        $service = $this->createServiceWithCandidateRepoAndP2pService($rp2pCandidateRepo);

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        // No best candidate found
        $rp2pCandidateRepo->expects($this->once())
            ->method('getBestCandidate')
            ->with(self::TEST_HASH)
            ->willReturn(null);

        // Should cancel the P2P
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_CANCELLED);

        // Should propagate cancel upstream
        $this->p2pService->expects($this->once())
            ->method('sendCancelNotificationForHash')
            ->with(self::TEST_HASH);

        $service->selectAndForwardBestRp2p(self::TEST_HASH);
    }

    /**
     * Test selectAndForwardBestRp2p does not call sendCancelNotificationForHash
     * when p2pService is not set
     */
    public function testSelectAndForwardBestRp2pDoesNotPropagateWithoutP2pService(): void
    {
        $rp2pCandidateRepo = $this->createMock(Rp2pCandidateRepository::class);
        // Create service WITHOUT p2pService
        $service = new Rp2pService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->rp2pRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->messageDeliveryService,
            $rp2pCandidateRepo,
            $this->p2pSenderRepository
        );
        // Note: NOT calling setP2pService

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        $rp2pCandidateRepo->method('getBestCandidate')
            ->willReturn(null);

        // Should cancel the P2P (still happens)
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_CANCELLED);

        // p2pService is null so sendCancelNotificationForHash should NOT be called
        $this->p2pService->expects($this->never())
            ->method('sendCancelNotificationForHash');

        $service->selectAndForwardBestRp2p(self::TEST_HASH);
    }

    /**
     * Test selectAndForwardBestRp2p skips cancel notification when P2P already cancelled
     *
     * Prevents feedback loop: if P2P is already cancelled (from a previous
     * cancel notification), don't send redundant cancel notifications upstream.
     */
    public function testSelectAndForwardBestRp2pSkipsCancelWhenAlreadyCancelled(): void
    {
        $rp2pCandidateRepo = $this->createMock(Rp2pCandidateRepository::class);
        $service = $this->createServiceWithCandidateRepoAndP2pService($rp2pCandidateRepo);

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        // No best candidate found
        $rp2pCandidateRepo->method('getBestCandidate')
            ->willReturn(null);

        // P2P is already cancelled
        $this->p2pRepository->method('getByHash')
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => Constants::STATUS_CANCELLED,
            ]);

        // Should NOT update status again or send cancel notification
        $this->p2pRepository->expects($this->never())
            ->method('updateStatus');
        $this->p2pService->expects($this->never())
            ->method('sendCancelNotificationForHash');

        $service->selectAndForwardBestRp2p(self::TEST_HASH);
    }

    /**
     * Test selectAndForwardBestRp2p exits early when rp2p already exists
     */
    public function testSelectAndForwardBestRp2pExitsWhenAlreadyExists(): void
    {
        $rp2pCandidateRepo = $this->createMock(Rp2pCandidateRepository::class);
        $service = $this->createServiceWithCandidateRepoAndP2pService($rp2pCandidateRepo);

        $this->rp2pRepository->method('rp2pExists')
            ->with(self::TEST_HASH)
            ->willReturn(true);

        // Should clean up candidates
        $rp2pCandidateRepo->expects($this->once())
            ->method('deleteCandidatesByHash')
            ->with(self::TEST_HASH);

        // Should NOT cancel or propagate
        $this->p2pRepository->expects($this->never())
            ->method('updateStatus');
        $this->p2pService->expects($this->never())
            ->method('sendCancelNotificationForHash');

        $service->selectAndForwardBestRp2p(self::TEST_HASH);
    }

    /**
     * Test selectAndForwardBestRp2p exits early when rp2pCandidateRepository is null
     */
    public function testSelectAndForwardBestRp2pExitsWhenNoCandidateRepo(): void
    {
        // Service without rp2pCandidateRepository (constructor default)
        $this->service->setP2pService($this->p2pService);

        // Should NOT cancel or propagate
        $this->p2pRepository->expects($this->never())
            ->method('updateStatus');
        $this->p2pService->expects($this->never())
            ->method('sendCancelNotificationForHash');

        $this->service->selectAndForwardBestRp2p(self::TEST_HASH);
    }

    /**
     * Test full cascade: cancel notification triggers selection which finds no candidates,
     * cancels P2P and propagates upstream
     */
    public function testFullCascadeCancelPropagation(): void
    {
        $rp2pCandidateRepo = $this->createMock(Rp2pCandidateRepository::class);
        $service = $this->createServiceWithCandidateRepoAndP2pService($rp2pCandidateRepo);

        $request = [
            'hash' => self::TEST_HASH,
            'senderAddress' => self::TEST_ADDRESS,
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
        ];

        // Not yet selected
        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        // This is the last contact responding (all responded)
        $this->p2pRepository->method('getTrackingCounts')
            ->willReturn([
                'contacts_sent_count' => 2,
                'contacts_responded_count' => 2, // all responded
                'contacts_relayed_count' => 0,
                'fast' => 0,
            ]);

        // No candidates at all (all contacts cancelled)
        $rp2pCandidateRepo->method('getBestCandidate')
            ->willReturn(null);

        // Should cancel P2P
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_CANCELLED);

        // Should propagate cancel notification upstream
        $this->p2pService->expects($this->once())
            ->method('sendCancelNotificationForHash')
            ->with(self::TEST_HASH);

        $service->handleCancelNotification($request, $p2p);
    }

    /**
     * Test handleCancelNotification with Phase 2 trigger: all inserted + relayed responded
     */
    public function testHandleCancelNotificationTriggersPhase2WhenAllResponded(): void
    {
        $rp2pCandidateRepo = $this->createMock(Rp2pCandidateRepository::class);
        $p2pRelayedContactRepo = $this->createMock(P2pRelayedContactRepository::class);
        $service = $this->createServiceWithCandidateRepoAndP2pService($rp2pCandidateRepo, $p2pRelayedContactRepo);

        $request = [
            'hash' => self::TEST_HASH,
            'senderAddress' => 'http://relayed-contact.test',
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        // Classify as relayed contact
        $p2pRelayedContactRepo->method('isRelayedContact')
            ->willReturn(true);

        // Phase 2: all inserted + relayed responded
        $this->p2pRepository->method('getTrackingCounts')
            ->willReturn([
                'contacts_sent_count' => 2,
                'contacts_responded_count' => 2,
                'contacts_relayed_count' => 1,
                'contacts_relayed_responded_count' => 1,
                'phase1_sent' => 1,
                'fast' => 0,
            ]);

        // selectAndForwardBestRp2p should be triggered (Phase 2)
        // No candidates -> cancel + propagate
        $rp2pCandidateRepo->expects($this->once())
            ->method('getBestCandidate')
            ->with(self::TEST_HASH)
            ->willReturn(null);

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_CANCELLED);

        $this->p2pService->expects($this->once())
            ->method('sendCancelNotificationForHash')
            ->with(self::TEST_HASH);

        $service->handleCancelNotification($request, $p2p);
    }
}
