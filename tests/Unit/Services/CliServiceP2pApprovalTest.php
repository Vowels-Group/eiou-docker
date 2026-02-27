<?php
/**
 * Unit Tests for CliService P2P Approval Commands
 *
 * Tests CLI P2P approval functionality including:
 * - Listing pending P2P transactions
 * - Viewing route candidates
 * - Approving P2P transactions (fast mode and candidate selection)
 * - Rejecting P2P transactions
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\CliService;
use Eiou\Database\ContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Contracts\P2pTransactionSenderInterface;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\GeneralUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Cli\CliOutputManager;

#[CoversClass(CliService::class)]
class CliServiceP2pApprovalTest extends TestCase
{
    private MockObject|ContactRepository $contactRepository;
    private MockObject|BalanceRepository $balanceRepository;
    private MockObject|TransactionRepository $transactionRepository;
    private MockObject|UtilityServiceContainer $utilityContainer;
    private MockObject|CurrencyUtilityService $currencyUtility;
    private MockObject|TransportUtilityService $transportUtility;
    private MockObject|GeneralUtilityService $generalUtility;
    private MockObject|UserContext $userContext;
    private MockObject|CliOutputManager $outputManager;
    private MockObject|P2pRepository $p2pRepository;
    private MockObject|Rp2pRepository $rp2pRepository;
    private MockObject|Rp2pCandidateRepository $rp2pCandidateRepository;
    private MockObject|P2pTransactionSenderInterface $p2pTransactionSender;
    private MockObject|P2pServiceInterface $p2pService;
    private CliService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contactRepository = $this->createMock(ContactRepository::class);
        $this->balanceRepository = $this->createMock(BalanceRepository::class);
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->currencyUtility = $this->createMock(CurrencyUtilityService::class);
        $this->transportUtility = $this->createMock(TransportUtilityService::class);
        $this->generalUtility = $this->createMock(GeneralUtilityService::class);
        $this->userContext = $this->createMock(UserContext::class);
        $this->outputManager = $this->createMock(CliOutputManager::class);
        $this->p2pRepository = $this->createMock(P2pRepository::class);
        $this->rp2pRepository = $this->createMock(Rp2pRepository::class);
        $this->rp2pCandidateRepository = $this->createMock(Rp2pCandidateRepository::class);
        $this->p2pTransactionSender = $this->createMock(P2pTransactionSenderInterface::class);
        $this->p2pService = $this->createMock(P2pServiceInterface::class);

        $this->utilityContainer->method('getCurrencyUtility')
            ->willReturn($this->currencyUtility);
        $this->utilityContainer->method('getTransportUtility')
            ->willReturn($this->transportUtility);
        $this->utilityContainer->method('getGeneralUtility')
            ->willReturn($this->generalUtility);

        $this->service = new CliService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->transactionRepository,
            $this->utilityContainer,
            $this->userContext
        );

        $this->service->setP2pRepository($this->p2pRepository);
        $this->service->setP2pApprovalDependencies(
            $this->rp2pRepository,
            $this->rp2pCandidateRepository,
            $this->p2pTransactionSender,
            $this->p2pService
        );
    }

    // =========================================================================
    // displayPendingP2p() Tests
    // =========================================================================

    /**
     * Test displayPendingP2p shows awaiting transactions
     */
    public function testDisplayPendingP2pShowsAwaitingTransactions(): void
    {
        $this->outputManager->method('isJsonMode')->willReturn(false);

        $this->p2pRepository->method('getAwaitingApprovalList')
            ->willReturn([
                [
                    'hash' => 'abc123',
                    'amount' => 1000,
                    'currency' => 'USD',
                    'destination_address' => 'http://bob:8080',
                    'my_fee_amount' => 10,
                    'rp2p_amount' => 1010,
                    'fast' => 1,
                    'created_at' => '2026-02-26 10:00:00',
                ],
                [
                    'hash' => 'def456',
                    'amount' => 2000,
                    'currency' => 'USD',
                    'destination_address' => 'http://carol:8080',
                    'my_fee_amount' => 20,
                    'rp2p_amount' => null,
                    'fast' => 0,
                    'created_at' => '2026-02-26 10:05:00',
                ],
            ]);

        $this->rp2pCandidateRepository->method('getCandidateCount')
            ->willReturnMap([
                ['abc123', 0],
                ['def456', 3],
            ]);

        $this->currencyUtility->method('formatCurrency')
            ->willReturnCallback(function ($amount, $currency) {
                return number_format($amount / 100, 2);
            });

        ob_start();
        $this->service->displayPendingP2p(['eiou', 'p2p'], $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('abc123', $output);
        $this->assertStringContainsString('def456', $output);
        $this->assertStringContainsString('2 transaction(s)', $output);
    }

    /**
     * Test displayPendingP2p shows empty message when no transactions
     */
    public function testDisplayPendingP2pEmptyList(): void
    {
        $this->outputManager->method('isJsonMode')->willReturn(false);

        $this->p2pRepository->method('getAwaitingApprovalList')
            ->willReturn([]);

        ob_start();
        $this->service->displayPendingP2p(['eiou', 'p2p'], $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('No pending P2P transactions', $output);
    }

    /**
     * Test displayPendingP2p in JSON mode
     */
    public function testDisplayPendingP2pJsonMode(): void
    {
        $this->outputManager->method('isJsonMode')->willReturn(true);

        $this->p2pRepository->method('getAwaitingApprovalList')
            ->willReturn([
                [
                    'hash' => 'abc123',
                    'amount' => 1000,
                    'currency' => 'USD',
                    'destination_address' => 'http://bob:8080',
                    'my_fee_amount' => 10,
                    'rp2p_amount' => 1010,
                    'fast' => 1,
                    'created_at' => '2026-02-26 10:00:00',
                ],
            ]);

        $this->rp2pCandidateRepository->method('getCandidateCount')
            ->willReturn(0);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Pending P2P transactions retrieved',
                $this->callback(function ($data) {
                    return $data['count'] === 1
                        && $data['transactions'][0]['hash'] === 'abc123'
                        && $data['transactions'][0]['candidate_count'] === 0;
                }),
                $this->anything()
            );

        $this->service->displayPendingP2p(['eiou', 'p2p'], $this->outputManager);
    }

    // =========================================================================
    // displayP2pCandidates() Tests
    // =========================================================================

    /**
     * Test displayP2pCandidates shows candidate list
     */
    public function testDisplayP2pCandidatesShowsCandidateList(): void
    {
        $this->outputManager->method('isJsonMode')->willReturn(false);

        $this->p2pRepository->method('getAwaitingApproval')
            ->with('abc123')
            ->willReturn([
                'hash' => 'abc123',
                'amount' => 1000,
                'currency' => 'USD',
                'fast' => 0,
                'destination_address' => 'http://bob:8080',
            ]);

        $this->rp2pCandidateRepository->method('getCandidatesByHash')
            ->with('abc123')
            ->willReturn([
                [
                    'id' => 1,
                    'hash' => 'abc123',
                    'sender_address' => 'http://relay1:8080',
                    'amount' => 1020,
                    'currency' => 'USD',
                    'fee_amount' => 20,
                    'time' => 123456,
                    'sender_public_key' => 'pk1',
                    'sender_signature' => 'sig1',
                ],
                [
                    'id' => 2,
                    'hash' => 'abc123',
                    'sender_address' => 'http://relay2:8080',
                    'amount' => 1050,
                    'currency' => 'USD',
                    'fee_amount' => 50,
                    'time' => 123457,
                    'sender_public_key' => 'pk2',
                    'sender_signature' => 'sig2',
                ],
            ]);

        $this->rp2pRepository->method('getByHash')
            ->willReturn(null);

        $this->currencyUtility->method('formatCurrency')
            ->willReturnCallback(function ($amount, $currency) {
                return number_format($amount / 100, 2);
            });

        ob_start();
        $this->service->displayP2pCandidates(['eiou', 'p2p', 'candidates', 'abc123'], $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('[1]', $output);
        $this->assertStringContainsString('[2]', $output);
        $this->assertStringContainsString('relay1', $output);
        $this->assertStringContainsString('relay2', $output);
    }

    /**
     * Test displayP2pCandidates shows single rp2p (fast mode)
     */
    public function testDisplayP2pCandidatesShowsSingleRp2p(): void
    {
        $this->outputManager->method('isJsonMode')->willReturn(false);

        $this->p2pRepository->method('getAwaitingApproval')
            ->with('abc123')
            ->willReturn([
                'hash' => 'abc123',
                'amount' => 1000,
                'currency' => 'USD',
                'fast' => 1,
                'destination_address' => 'http://bob:8080',
            ]);

        $this->rp2pCandidateRepository->method('getCandidatesByHash')
            ->willReturn([]);

        $this->rp2pRepository->method('getByHash')
            ->with('abc123')
            ->willReturn([
                'hash' => 'abc123',
                'sender_address' => 'http://relay1:8080',
                'amount' => 1010,
                'currency' => 'USD',
                'time' => 123456,
                'sender_public_key' => 'pk1',
                'sender_signature' => 'sig1',
            ]);

        $this->currencyUtility->method('formatCurrency')
            ->willReturnCallback(function ($amount, $currency) {
                return number_format($amount / 100, 2);
            });

        ob_start();
        $this->service->displayP2pCandidates(['eiou', 'p2p', 'candidates', 'abc123'], $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('Single route (fast mode)', $output);
        $this->assertStringContainsString('relay1', $output);
    }

    /**
     * Test displayP2pCandidates errors when hash is missing
     */
    public function testDisplayP2pCandidatesMissingHash(): void
    {
        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('hash is required'),
                $this->anything()
            );

        $this->service->displayP2pCandidates(['eiou', 'p2p', 'candidates'], $this->outputManager);
    }

    // =========================================================================
    // approveP2p() Tests
    // =========================================================================

    /**
     * Test approveP2p with candidate index
     */
    public function testApproveP2pWithCandidateIndex(): void
    {
        $this->p2pRepository->method('getAwaitingApproval')
            ->with('abc123')
            ->willReturn([
                'hash' => 'abc123',
                'amount' => 1000,
                'currency' => 'USD',
                'destination_address' => 'http://bob:8080',
                'my_fee_amount' => 10,
                'fast' => 0,
            ]);

        $this->rp2pCandidateRepository->method('getCandidatesByHash')
            ->with('abc123')
            ->willReturn([
                [
                    'id' => 1,
                    'hash' => 'abc123',
                    'sender_address' => 'http://relay1:8080',
                    'amount' => 1020,
                    'currency' => 'USD',
                    'fee_amount' => 20,
                    'time' => 123456,
                    'sender_public_key' => 'pk1',
                    'sender_signature' => 'sig1',
                ],
            ]);

        $this->rp2pRepository->expects($this->once())
            ->method('insertRp2pRequest')
            ->with($this->callback(function ($request) {
                return $request['hash'] === 'abc123'
                    && $request['amount'] === 1020;
            }));

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with('abc123', 'found');

        $this->p2pTransactionSender->expects($this->once())
            ->method('sendP2pEiou')
            ->with($this->callback(function ($request) {
                return $request['hash'] === 'abc123'
                    && $request['amount'] === 1020  // candidate amount (fee already included)
                    && $request['senderAddress'] === 'http://relay1:8080';
            }));

        $this->rp2pCandidateRepository->expects($this->once())
            ->method('deleteCandidatesByHash')
            ->with('abc123');

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('approved'),
                $this->callback(function ($data) {
                    return $data['hash'] === 'abc123' && $data['candidate_index'] === 1;
                }),
                $this->anything()
            );

        $this->service->approveP2p(['eiou', 'p2p', 'approve', 'abc123', '1'], $this->outputManager);
    }

    /**
     * Test approveP2p fast mode (no candidates, single rp2p)
     */
    public function testApproveP2pFastMode(): void
    {
        $this->p2pRepository->method('getAwaitingApproval')
            ->with('abc123')
            ->willReturn([
                'hash' => 'abc123',
                'amount' => 1000,
                'currency' => 'USD',
                'destination_address' => 'http://bob:8080',
                'my_fee_amount' => 10,
                'fast' => 1,
            ]);

        $this->rp2pCandidateRepository->method('getCandidatesByHash')
            ->willReturn([]);

        $this->rp2pRepository->method('getByHash')
            ->with('abc123')
            ->willReturn([
                'hash' => 'abc123',
                'sender_address' => 'http://relay1:8080',
                'amount' => 1010,
                'currency' => 'USD',
                'time' => 123456,
                'sender_public_key' => 'pk1',
                'sender_signature' => 'sig1',
            ]);

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with('abc123', 'found');

        $this->p2pTransactionSender->expects($this->once())
            ->method('sendP2pEiou');

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('approved'),
                $this->anything(),
                $this->stringContains('fast mode')
            );

        $this->service->approveP2p(['eiou', 'p2p', 'approve', 'abc123'], $this->outputManager);
    }

    /**
     * Test approveP2p errors when multiple candidates but no index
     */
    public function testApproveP2pMultipleCandidatesNoIndex(): void
    {
        $this->p2pRepository->method('getAwaitingApproval')
            ->with('abc123')
            ->willReturn([
                'hash' => 'abc123',
                'amount' => 1000,
                'currency' => 'USD',
                'destination_address' => 'http://bob:8080',
                'my_fee_amount' => 10,
                'fast' => 0,
            ]);

        $this->rp2pCandidateRepository->method('getCandidatesByHash')
            ->willReturn([
                ['id' => 1, 'hash' => 'abc123'],
                ['id' => 2, 'hash' => 'abc123'],
            ]);

        $this->p2pTransactionSender->expects($this->never())
            ->method('sendP2pEiou');

        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Multiple route candidates'),
                $this->anything(),
                $this->anything()
            );

        $this->service->approveP2p(['eiou', 'p2p', 'approve', 'abc123'], $this->outputManager);
    }

    /**
     * Test approveP2p errors when hash not found
     */
    public function testApproveP2pInvalidHash(): void
    {
        $this->p2pRepository->method('getAwaitingApproval')
            ->with('invalid')
            ->willReturn(null);

        $this->p2pTransactionSender->expects($this->never())
            ->method('sendP2pEiou');

        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('not found'),
                $this->anything(),
                $this->anything()
            );

        $this->service->approveP2p(['eiou', 'p2p', 'approve', 'invalid'], $this->outputManager);
    }

    // =========================================================================
    // rejectP2p() Tests
    // =========================================================================

    /**
     * Test rejectP2p cancels and propagates
     */
    public function testRejectP2p(): void
    {
        $this->p2pRepository->method('getAwaitingApproval')
            ->with('abc123')
            ->willReturn([
                'hash' => 'abc123',
                'amount' => 1000,
                'currency' => 'USD',
                'destination_address' => 'http://bob:8080',
            ]);

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with('abc123', Constants::STATUS_CANCELLED);

        $this->p2pService->expects($this->once())
            ->method('sendCancelNotificationForHash')
            ->with('abc123');

        $this->rp2pCandidateRepository->expects($this->once())
            ->method('deleteCandidatesByHash')
            ->with('abc123');

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('rejected'),
                $this->callback(function ($data) {
                    return $data['hash'] === 'abc123';
                }),
                $this->anything()
            );

        $this->service->rejectP2p(['eiou', 'p2p', 'reject', 'abc123'], $this->outputManager);
    }

    /**
     * Test rejectP2p errors when dependencies not set
     */
    public function testRejectP2pMissingDependencies(): void
    {
        // Create service without P2P dependencies
        $service = new CliService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->transactionRepository,
            $this->utilityContainer,
            $this->userContext
        );

        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('not available'),
                $this->anything()
            );

        $service->rejectP2p(['eiou', 'p2p', 'reject', 'abc123'], $this->outputManager);
    }
}
