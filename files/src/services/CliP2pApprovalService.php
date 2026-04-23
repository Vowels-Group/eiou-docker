<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\ErrorCodes;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Contracts\P2pTransactionSenderInterface;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Cli\CliOutputManager;

/**
 * CLI P2P Approval Service
 *
 * Handles CLI commands for P2P transaction approval workflow:
 * - Listing pending P2P transactions
 * - Viewing route candidates
 * - Approving P2P transactions
 * - Rejecting P2P transactions
 *
 * Extracted from CliService (ARCH-04) to reduce God Class complexity.
 */
class CliP2pApprovalService
{
    private CurrencyUtilityService $currencyUtility;
    private ?P2pRepository $p2pRepository = null;
    private ?Rp2pRepository $rp2pRepository = null;
    private ?Rp2pCandidateRepository $rp2pCandidateRepository = null;
    private ?P2pTransactionSenderInterface $p2pTransactionSender = null;
    private ?P2pServiceInterface $p2pService = null;
    private ?P2pApprovalService $approvalService = null;

    public function __construct(CurrencyUtilityService $currencyUtility)
    {
        $this->currencyUtility = $currencyUtility;
    }

    /**
     * Inject the shared approve/reject commit-point service. When set,
     * approveP2p()/rejectP2p() delegate to it so event dispatch and the
     * side-effect sequence stay in one place across CLI/API/GUI.
     */
    public function setApprovalService(P2pApprovalService $service): void
    {
        $this->approvalService = $service;
    }

    /**
     * Set the P2P repository (optional dependency)
     */
    public function setP2pRepository(P2pRepository $p2pRepository): void
    {
        $this->p2pRepository = $p2pRepository;
    }

    /**
     * Set P2P approval dependencies (optional, for CLI approve/reject commands)
     */
    public function setP2pApprovalDependencies(
        Rp2pRepository $rp2pRepository,
        Rp2pCandidateRepository $rp2pCandidateRepository,
        P2pTransactionSenderInterface $p2pTransactionSender,
        P2pServiceInterface $p2pService
    ): void {
        $this->rp2pRepository = $rp2pRepository;
        $this->rp2pCandidateRepository = $rp2pCandidateRepository;
        $this->p2pTransactionSender = $p2pTransactionSender;
        $this->p2pService = $p2pService;
    }

    /**
     * Display pending P2P transactions awaiting approval
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function displayPendingP2p(array $argv, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->p2pRepository === null) {
            $output->error('P2P repository not available', ErrorCodes::GENERAL_ERROR);
            return;
        }

        $awaitingList = $this->p2pRepository->getAwaitingApprovalList();

        $p2pData = [];
        foreach ($awaitingList as $p2p) {
            $candidateCount = 0;
            if ($this->rp2pCandidateRepository !== null) {
                $candidateCount = $this->rp2pCandidateRepository->getCandidateCount($p2p['hash']);
            }

            $p2pData[] = [
                'hash' => $p2p['hash'],
                'amount' => $p2p['amount'],
                'currency' => $p2p['currency'],
                'destination_address' => $p2p['destination_address'],
                'my_fee_amount' => (int) ($p2p['my_fee_amount'] ?? 0),
                'rp2p_amount' => $p2p['rp2p_amount'] !== null ? (int) $p2p['rp2p_amount'] : null,
                'fast' => (int) $p2p['fast'],
                'candidate_count' => $candidateCount,
                'created_at' => $p2p['created_at'],
            ];
        }

        if ($output->isJsonMode()) {
            $output->success('Pending P2P transactions retrieved', [
                'transactions' => $p2pData,
                'count' => count($p2pData),
            ], 'Pending P2P transactions');
        } else {
            if (empty($p2pData)) {
                echo "No pending P2P transactions awaiting approval.\n";
                return;
            }

            echo "P2P Transactions Awaiting Approval\n";
            echo "===================================\n\n";

            foreach ($p2pData as $i => $p2p) {
                $mode = $p2p['fast'] ? 'fast' : 'best-fee';
                $totalCost = $p2p['rp2p_amount'] !== null
                    ? $this->currencyUtility->formatCurrency(SplitAmount::from($p2p['rp2p_amount']), $p2p['currency'])
                    : 'pending';
                echo ($i + 1) . ". Hash: " . $p2p['hash'] . "\n";
                echo "   Amount: " . $this->currencyUtility->formatCurrency(SplitAmount::from($p2p['amount']), $p2p['currency']) . " " . $p2p['currency'] . "\n";
                echo "   Total cost: " . $totalCost . " | Mode: " . $mode . "\n";
                echo "   Candidates: " . $p2p['candidate_count'] . " | Created: " . $p2p['created_at'] . "\n";
                echo "\n";
            }

            echo "-------------------------------------------\n";
            echo "Total: " . count($p2pData) . " transaction(s) awaiting approval\n";
            echo "\nUse: eiou p2p candidates <hash>  to view route options\n";
            echo "     eiou p2p approve <hash>     to approve\n";
            echo "     eiou p2p reject <hash>      to reject\n";
        }
    }

    /**
     * Display route candidates for a P2P transaction
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function displayP2pCandidates(array $argv, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->p2pRepository === null || $this->rp2pCandidateRepository === null) {
            $output->error('P2P approval dependencies not available', ErrorCodes::GENERAL_ERROR);
            return;
        }

        $hash = $argv[3] ?? null;
        if (empty($hash)) {
            $output->error('Transaction hash is required. Usage: eiou p2p candidates <hash>', ErrorCodes::MISSING_ARGUMENT);
            return;
        }

        $p2p = $this->p2pRepository->getAwaitingApproval($hash);
        if (!$p2p) {
            $output->error('Transaction not found or not awaiting approval', ErrorCodes::NOT_FOUND, 404);
            return;
        }

        $candidates = $this->rp2pCandidateRepository->getCandidatesByHash($hash);

        // Also check for single rp2p (fast mode)
        $rp2p = null;
        if ($this->rp2pRepository !== null) {
            $rp2p = $this->rp2pRepository->getByHash($hash);
        }

        if ($output->isJsonMode()) {
            $output->success('P2P candidates retrieved', [
                'hash' => $hash,
                'amount' => $p2p['amount'],
                'currency' => $p2p['currency'],
                'fast' => (int) $p2p['fast'],
                'candidates' => $candidates,
                'rp2p' => $rp2p,
            ], 'P2P candidates');
        } else {
            echo "Route Candidates for P2P: " . $hash . "\n";
            echo "==========================================\n";
            echo "Amount: " . $this->currencyUtility->formatCurrency(SplitAmount::from($p2p['amount']), $p2p['currency']) . " " . $p2p['currency'] . "\n\n";

            if (!empty($candidates)) {
                echo "Available routes (ordered by fee, lowest first):\n";
                echo "-------------------------------------------\n";
                foreach ($candidates as $i => $candidate) {
                    $num = $i + 1;
                    echo "  [{$num}] Via: " . $candidate['sender_address'] . "\n";
                    echo "      Total amount: " . $this->currencyUtility->formatCurrency(SplitAmount::from($candidate['amount']), $candidate['currency']) . " " . $candidate['currency'] . "\n";
                    echo "      Fee: " . $this->currencyUtility->formatCurrency(SplitAmount::from($candidate['fee_amount']), $candidate['currency']) . "\n";
                    echo "\n";
                }
                echo "Use: eiou p2p approve {$hash} <number>  to approve a route\n";
            } elseif ($rp2p) {
                echo "Single route (fast mode):\n";
                echo "-------------------------------------------\n";
                echo "  Via: " . $rp2p['sender_address'] . "\n";
                echo "  Total amount: " . $this->currencyUtility->formatCurrency(SplitAmount::from($rp2p['amount']), $rp2p['currency']) . " " . $rp2p['currency'] . "\n";
                echo "\nUse: eiou p2p approve {$hash}  to approve\n";
            } else {
                echo "No route candidates available yet. Routes may still be arriving.\n";
            }
        }
    }

    /**
     * Approve a P2P transaction and send it
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function approveP2p(array $argv, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->approvalService === null) {
            $output->error('P2P approval service not configured', ErrorCodes::GENERAL_ERROR);
            return;
        }

        $hash = $argv[3] ?? null;
        if (empty($hash)) {
            $output->error('Transaction hash is required. Usage: eiou p2p approve <hash> [index]', ErrorCodes::MISSING_ARGUMENT);
            return;
        }

        $candidateIndex = isset($argv[4]) ? (int) $argv[4] : null;
        $result = $this->approvalService->approve($hash, $candidateIndex);

        if (!$result['success']) {
            $output->error($result['message'], $this->mapErrorCode($result['code']), $result['status'] ?? 500);
            return;
        }

        $summary = [
            'hash' => $result['hash'],
            'sender_address' => $result['sender_address'] ?? null,
        ];
        if (isset($result['candidate_index']) && $result['candidate_index'] !== null) {
            $summary['candidate_index'] = $result['candidate_index'];
        }
        $modeSuffix = $result['mode'] === 'fast' ? ' (fast mode)' : '';
        $output->success('P2P transaction approved and sent', $summary,
            "P2P transaction {$hash} approved{$modeSuffix}");
    }

    /**
     * Reject a P2P transaction
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function rejectP2p(array $argv, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->approvalService === null) {
            $output->error('P2P approval service not configured', ErrorCodes::GENERAL_ERROR);
            return;
        }

        $hash = $argv[3] ?? null;
        if (empty($hash)) {
            $output->error('Transaction hash is required. Usage: eiou p2p reject <hash>', ErrorCodes::MISSING_ARGUMENT);
            return;
        }

        $result = $this->approvalService->reject($hash);
        if (!$result['success']) {
            $output->error($result['message'], $this->mapErrorCode($result['code']), $result['status'] ?? 500);
            return;
        }

        $output->success('P2P transaction rejected', ['hash' => $hash],
            "P2P transaction {$hash} rejected and cancelled");
    }

    /**
     * Map the shared service's error code strings to the CLI's ErrorCodes
     * constants. Kept here because the shared service stays agnostic of
     * caller-specific error-code vocabularies.
     */
    private function mapErrorCode(string $serviceCode): string
    {
        return match ($serviceCode) {
            'not_found', 'no_candidates', 'no_route'    => ErrorCodes::NOT_FOUND,
            'not_originator'                            => ErrorCodes::PERMISSION_DENIED,
            'invalid_candidate_index',
            'candidate_selection_required'              => ErrorCodes::VALIDATION_ERROR,
            default                                     => ErrorCodes::GENERAL_ERROR,
        };
    }
}
