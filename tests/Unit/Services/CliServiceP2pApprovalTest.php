<?php
/**
 * Unit Tests for CliService P2P/DLQ Delegation
 *
 * Tests that CliService correctly delegates P2P and DLQ commands
 * to the extracted sub-services (CliP2pApprovalService, CliDlqService).
 *
 * For full P2P/DLQ logic tests, see:
 * - CliP2pApprovalServiceTest.php
 * - CliDlqServiceTest.php
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\CliService;
use Eiou\Services\CliP2pApprovalService;
use Eiou\Services\CliDlqService;
use Eiou\Database\ContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\GeneralUtilityService;
use Eiou\Core\UserContext;
use Eiou\Cli\CliOutputManager;

#[CoversClass(CliService::class)]
class CliServiceP2pApprovalTest extends TestCase
{
    private CliService $service;
    private MockObject|CliP2pApprovalService $p2pApprovalService;
    private MockObject|CliDlqService $dlqService;
    private MockObject|CliOutputManager $outputManager;

    protected function setUp(): void
    {
        parent::setUp();

        $contactRepository = $this->createMock(ContactRepository::class);
        $balanceRepository = $this->createMock(BalanceRepository::class);
        $transactionRepository = $this->createMock(TransactionRepository::class);
        $utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $currencyUtility = $this->createMock(CurrencyUtilityService::class);
        $transportUtility = $this->createMock(TransportUtilityService::class);
        $generalUtility = $this->createMock(GeneralUtilityService::class);
        $userContext = $this->createMock(UserContext::class);

        $utilityContainer->method('getCurrencyUtility')->willReturn($currencyUtility);
        $utilityContainer->method('getTransportUtility')->willReturn($transportUtility);
        $utilityContainer->method('getGeneralUtility')->willReturn($generalUtility);

        $this->outputManager = $this->createMock(CliOutputManager::class);
        $this->p2pApprovalService = $this->createMock(CliP2pApprovalService::class);
        $this->dlqService = $this->createMock(CliDlqService::class);

        $this->service = new CliService(
            $contactRepository,
            $balanceRepository,
            $transactionRepository,
            $utilityContainer,
            $userContext
        );

        $this->service->setP2pApprovalService($this->p2pApprovalService);
        $this->service->setDlqService($this->dlqService);
    }

    // =========================================================================
    // P2P Delegation Tests
    // =========================================================================

    public function testDisplayPendingP2pDelegatesToSubService(): void
    {
        $argv = ['eiou', 'p2p'];
        $this->p2pApprovalService->expects($this->once())
            ->method('displayPendingP2p')
            ->with($argv, $this->outputManager);

        $this->service->displayPendingP2p($argv, $this->outputManager);
    }

    public function testDisplayP2pCandidatesDelegatesToSubService(): void
    {
        $argv = ['eiou', 'p2p', 'candidates', 'abc123'];
        $this->p2pApprovalService->expects($this->once())
            ->method('displayP2pCandidates')
            ->with($argv, $this->outputManager);

        $this->service->displayP2pCandidates($argv, $this->outputManager);
    }

    public function testApproveP2pDelegatesToSubService(): void
    {
        $argv = ['eiou', 'p2p', 'approve', 'abc123'];
        $this->p2pApprovalService->expects($this->once())
            ->method('approveP2p')
            ->with($argv, $this->outputManager);

        $this->service->approveP2p($argv, $this->outputManager);
    }

    public function testRejectP2pDelegatesToSubService(): void
    {
        $argv = ['eiou', 'p2p', 'reject', 'abc123'];
        $this->p2pApprovalService->expects($this->once())
            ->method('rejectP2p')
            ->with($argv, $this->outputManager);

        $this->service->rejectP2p($argv, $this->outputManager);
    }

    public function testP2pErrorsWhenSubServiceNotSet(): void
    {
        $utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $utilityContainer->method('getCurrencyUtility')
            ->willReturn($this->createMock(CurrencyUtilityService::class));
        $utilityContainer->method('getTransportUtility')
            ->willReturn($this->createMock(TransportUtilityService::class));
        $utilityContainer->method('getGeneralUtility')
            ->willReturn($this->createMock(GeneralUtilityService::class));

        $service = new CliService(
            $this->createMock(ContactRepository::class),
            $this->createMock(BalanceRepository::class),
            $this->createMock(TransactionRepository::class),
            $utilityContainer,
            $this->createMock(UserContext::class)
        );

        $this->outputManager->expects($this->once())
            ->method('error')
            ->with($this->stringContains('not available'), $this->anything());

        $service->displayPendingP2p(['eiou', 'p2p'], $this->outputManager);
    }

    // =========================================================================
    // DLQ Delegation Tests
    // =========================================================================

    public function testDisplayDlqItemsDelegatesToSubService(): void
    {
        $argv = ['eiou', 'dlq'];
        $this->dlqService->expects($this->once())
            ->method('displayDlqItems')
            ->with($argv, $this->outputManager);

        $this->service->displayDlqItems($argv, $this->outputManager);
    }

    public function testRetryDlqItemDelegatesToSubService(): void
    {
        $argv = ['eiou', 'dlq', 'retry', '42'];
        $this->dlqService->expects($this->once())
            ->method('retryDlqItem')
            ->with($argv, $this->outputManager);

        $this->service->retryDlqItem($argv, $this->outputManager);
    }

    public function testAbandonDlqItemDelegatesToSubService(): void
    {
        $argv = ['eiou', 'dlq', 'abandon', '42'];
        $this->dlqService->expects($this->once())
            ->method('abandonDlqItem')
            ->with($argv, $this->outputManager);

        $this->service->abandonDlqItem($argv, $this->outputManager);
    }

    public function testDlqErrorsWhenSubServiceNotSet(): void
    {
        $utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $utilityContainer->method('getCurrencyUtility')
            ->willReturn($this->createMock(CurrencyUtilityService::class));
        $utilityContainer->method('getTransportUtility')
            ->willReturn($this->createMock(TransportUtilityService::class));
        $utilityContainer->method('getGeneralUtility')
            ->willReturn($this->createMock(GeneralUtilityService::class));

        $service = new CliService(
            $this->createMock(ContactRepository::class),
            $this->createMock(BalanceRepository::class),
            $this->createMock(TransactionRepository::class),
            $utilityContainer,
            $this->createMock(UserContext::class)
        );

        $this->outputManager->expects($this->once())
            ->method('error')
            ->with($this->stringContains('not available'), $this->anything());

        $service->displayDlqItems(['eiou', 'dlq'], $this->outputManager);
    }
}
