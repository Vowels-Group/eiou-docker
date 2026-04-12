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

    public function __construct(CurrencyUtilityService $currencyUtility)
    {
        $this->currencyUtility = $currencyUtility;
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

        if ($this->p2pRepository === null || $this->rp2pCandidateRepository === null
            || $this->p2pTransactionSender === null) {
            $output->error('P2P approval dependencies not configured', ErrorCodes::GENERAL_ERROR);
            return;
        }

        $hash = $argv[3] ?? null;
        if (empty($hash)) {
            $output->error('Transaction hash is required. Usage: eiou p2p approve <hash> [index]', ErrorCodes::MISSING_ARGUMENT);
            return;
        }

        $p2p = $this->p2pRepository->getAwaitingApproval($hash);
        if (!$p2p) {
            $output->error('Transaction not found or not awaiting approval', ErrorCodes::NOT_FOUND, 404);
            return;
        }

        if (empty($p2p['destination_address'])) {
            $output->error('Only the transaction originator can approve', ErrorCodes::PERMISSION_DENIED, 403);
            return;
        }

        $candidateIndex = isset($argv[4]) ? (int) $argv[4] : 0;

        if ($candidateIndex > 0) {
            // User selected a specific candidate by 1-based index
            $candidates = $this->rp2pCandidateRepository->getCandidatesByHash($hash);
            if (empty($candidates)) {
                $output->error('No candidates available for this transaction', ErrorCodes::NOT_FOUND, 404);
                return;
            }

            if ($candidateIndex < 1 || $candidateIndex > count($candidates)) {
                $output->error("Invalid candidate index. Choose between 1 and " . count($candidates), ErrorCodes::VALIDATION_ERROR, 400);
                return;
            }

            $candidate = $candidates[$candidateIndex - 1];

            $request = [
                'hash' => $candidate['hash'],
                'time' => $candidate['time'],
                'amount' => $candidate['amount'],
                'currency' => $candidate['currency'],
                'senderPublicKey' => $candidate['sender_public_key'],
                'senderAddress' => $candidate['sender_address'],
                'signature' => $candidate['sender_signature'],
            ];

            // Candidate amount already includes the originator's fee from handleRp2pCandidate.
            // Insert rp2p record (required by daemon's processOutgoingP2p for the 'time' field).

            try {
                $this->rp2pRepository->insertRp2pRequest($request);
                $this->p2pRepository->updateStatus($hash, 'found');
                $this->p2pTransactionSender->sendP2pEiou($request);
                $this->rp2pCandidateRepository->deleteCandidatesByHash($hash);

                $output->success('P2P transaction approved and sent', [
                    'hash' => $hash,
                    'candidate_index' => $candidateIndex,
                    'sender_address' => $candidate['sender_address'],
                ], "P2P transaction {$hash} approved (candidate #{$candidateIndex})");
            } catch (\Throwable $e) {
                $output->error('Failed to send transaction: ' . $e->getMessage(), ErrorCodes::GENERAL_ERROR, 500);
            }
            return;
        }

        // No index provided - check for single rp2p (fast mode)
        $candidates = $this->rp2pCandidateRepository->getCandidatesByHash($hash);

        if (!empty($candidates) && count($candidates) > 1) {
            $output->error(
                'Multiple route candidates available. Specify an index: eiou p2p approve ' . $hash . ' <1-' . count($candidates) . '>',
                ErrorCodes::VALIDATION_ERROR,
                400
            );
            return;
        }

        if (!empty($candidates) && count($candidates) === 1) {
            // Single candidate in best-fee mode - use it
            $candidate = $candidates[0];

            $request = [
                'hash' => $candidate['hash'],
                'time' => $candidate['time'],
                'amount' => $candidate['amount'],
                'currency' => $candidate['currency'],
                'senderPublicKey' => $candidate['sender_public_key'],
                'senderAddress' => $candidate['sender_address'],
                'signature' => $candidate['sender_signature'],
            ];

            try {
                $this->rp2pRepository->insertRp2pRequest($request);
                $this->p2pRepository->updateStatus($hash, 'found');
                $this->p2pTransactionSender->sendP2pEiou($request);
                $this->rp2pCandidateRepository->deleteCandidatesByHash($hash);

                $output->success('P2P transaction approved and sent', [
                    'hash' => $hash,
                    'sender_address' => $candidate['sender_address'],
                ], "P2P transaction {$hash} approved");
            } catch (\Throwable $e) {
                $output->error('Failed to send transaction: ' . $e->getMessage(), ErrorCodes::GENERAL_ERROR, 500);
            }
            return;
        }

        // No candidates - check for single rp2p (fast mode)
        if ($this->rp2pRepository !== null) {
            $rp2p = $this->rp2pRepository->getByHash($hash);
            if ($rp2p) {
                $request = [
                    'hash' => $rp2p['hash'],
                    'time' => $rp2p['time'],
                    'amount' => $rp2p['amount'],
                    'currency' => $rp2p['currency'],
                    'senderPublicKey' => $rp2p['sender_public_key'],
                    'senderAddress' => $rp2p['sender_address'],
                    'signature' => $rp2p['sender_signature'],
                ];

                try {
                    $this->p2pRepository->updateStatus($hash, 'found');
                    $this->p2pTransactionSender->sendP2pEiou($request);

                    $output->success('P2P transaction approved and sent', [
                        'hash' => $hash,
                        'sender_address' => $rp2p['sender_address'],
                    ], "P2P transaction {$hash} approved (fast mode)");
                } catch (\Throwable $e) {
                    $output->error('Failed to send transaction: ' . $e->getMessage(), ErrorCodes::GENERAL_ERROR, 500);
                }
                return;
            }
        }

        $output->error('No route available for this transaction. Routes may still be arriving.', ErrorCodes::NOT_FOUND, 404);
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

        if ($this->p2pRepository === null) {
            $output->error('P2P repository not available', ErrorCodes::GENERAL_ERROR);
            return;
        }

        $hash = $argv[3] ?? null;
        if (empty($hash)) {
            $output->error('Transaction hash is required. Usage: eiou p2p reject <hash>', ErrorCodes::MISSING_ARGUMENT);
            return;
        }

        $p2p = $this->p2pRepository->getAwaitingApproval($hash);
        if (!$p2p) {
            $output->error('Transaction not found or not awaiting approval', ErrorCodes::NOT_FOUND, 404);
            return;
        }

        if (empty($p2p['destination_address'])) {
            $output->error('Only the transaction originator can reject', ErrorCodes::PERMISSION_DENIED, 403);
            return;
        }

        try {
            $this->p2pRepository->updateStatus($hash, Constants::STATUS_CANCELLED);

            // Propagate full cancel downstream to all contacts
            // (originator has destination_address set, so upstream cancel is not applicable)
            if ($this->p2pService !== null) {
                $this->p2pService->broadcastFullCancelForHash($hash);
            }

            // Clean up any remaining candidates
            if ($this->rp2pCandidateRepository !== null) {
                $this->rp2pCandidateRepository->deleteCandidatesByHash($hash);
            }

            $output->success('P2P transaction rejected', [
                'hash' => $hash,
            ], "P2P transaction {$hash} rejected and cancelled");
        } catch (\Throwable $e) {
            $output->error('Failed to reject transaction: ' . $e->getMessage(), ErrorCodes::GENERAL_ERROR, 500);
        }
    }
}
