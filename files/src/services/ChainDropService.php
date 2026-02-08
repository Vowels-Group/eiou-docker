<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\ChainDropServiceInterface;
use Eiou\Contracts\BackupServiceInterface;
use Eiou\Database\ChainDropProposalRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\ContactRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Schemas\Payloads\MessagePayload;
use Eiou\Schemas\Payloads\TransactionPayload;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Events\ChainDropEvents;
use Eiou\Events\EventDispatcher;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Cli\CliOutputManager;
use Eiou\Core\ErrorCodes;
use PDOException;
use Exception;

/**
 * Chain Drop Service
 *
 * Manages the mutual agreement protocol for dropping missing transactions
 * from the chain between two contacts.
 *
 * When both contacts are missing the same transaction, the chain cannot be
 * repaired via sync. This service coordinates:
 * 1. Proposing a chain drop to the contact
 * 2. Handling incoming proposals
 * 3. Accepting/rejecting proposals
 * 4. Executing the chain modification (updating previous_txid, re-signing)
 * 5. Exchanging re-signed transaction copies between both parties
 */
class ChainDropService implements ChainDropServiceInterface
{
    private ChainDropProposalRepository $proposalRepository;
    private TransactionChainRepository $transactionChainRepository;
    private TransactionRepository $transactionRepository;
    private ContactRepository $contactRepository;
    private UtilityServiceContainer $utilityContainer;
    private TransportUtilityService $transportUtility;
    private UserContext $currentUser;
    private MessagePayload $messagePayload;
    private TransactionPayload $transactionPayload;
    private ?BackupServiceInterface $backupService = null;
    private ?SyncTriggerInterface $syncTrigger = null;

    /**
     * Set the backup service for transaction recovery from backups
     *
     * @param BackupServiceInterface $backupService Backup service
     */
    public function setBackupService(BackupServiceInterface $backupService): void
    {
        $this->backupService = $backupService;
    }

    /**
     * Set the sync trigger for balance recalculation after chain drops
     *
     * @param SyncTriggerInterface $syncTrigger Sync trigger
     */
    public function setSyncTrigger(SyncTriggerInterface $syncTrigger): void
    {
        $this->syncTrigger = $syncTrigger;
    }

    /**
     * Constructor
     *
     * @param ChainDropProposalRepository $proposalRepository Proposal storage
     * @param TransactionChainRepository $transactionChainRepository Chain operations
     * @param TransactionRepository $transactionRepository Transaction CRUD
     * @param ContactRepository $contactRepository Contact lookups
     * @param UtilityServiceContainer $utilityContainer Utility services
     * @param UserContext $currentUser Current user context
     */
    public function __construct(
        ChainDropProposalRepository $proposalRepository,
        TransactionChainRepository $transactionChainRepository,
        TransactionRepository $transactionRepository,
        ContactRepository $contactRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser
    ) {
        $this->proposalRepository = $proposalRepository;
        $this->transactionChainRepository = $transactionChainRepository;
        $this->transactionRepository = $transactionRepository;
        $this->contactRepository = $contactRepository;
        $this->utilityContainer = $utilityContainer;
        $this->transportUtility = $utilityContainer->getTransportUtility();
        $this->currentUser = $currentUser;
        $this->messagePayload = new MessagePayload($currentUser, $utilityContainer);
        $this->transactionPayload = new TransactionPayload($currentUser, $utilityContainer);
    }

    /**
     * {@inheritdoc}
     */
    public function proposeChainDrop(string $contactPubkeyHash): array
    {
        $result = ['success' => false, 'proposal_id' => null, 'missing_txid' => null, 'broken_txid' => null, 'error' => null];

        try {
            // Look up contact to get pubkey and address
            $contact = $this->contactRepository->lookupByPubkeyHash($contactPubkeyHash);
            if (!$contact) {
                $result['error'] = 'Contact not found';
                return $result;
            }

            $contactPubkey = $contact['pubkey'] ?? null;
            if (!$contactPubkey) {
                $result['error'] = 'Contact public key not available';
                return $result;
            }

            // Verify the chain and auto-detect the first gap
            $chainStatus = $this->transactionChainRepository->verifyChainIntegrity(
                $this->currentUser->getPublicKey(),
                $contactPubkey
            );

            if ($chainStatus['valid']) {
                $result['error'] = 'Chain is valid, no gap to resolve';
                return $result;
            }

            $gaps = $chainStatus['gaps'] ?? [];
            $brokenTxids = $chainStatus['broken_txids'] ?? [];

            if (empty($gaps) || empty($brokenTxids)) {
                $result['error'] = 'No gap detected in chain';
                return $result;
            }

            // Pick the first gap
            $missingTxid = $gaps[0];
            $brokenTxid = $brokenTxids[0];

            // Fallback: attempt backup recovery at propose level
            // This is a safety net — sync-level recovery should have caught this already
            if ($this->backupService !== null) {
                Logger::getInstance()->info("Backup recovery fallback triggered at propose level (sync-level recovery should have handled this)", [
                    'missing_txid' => substr($missingTxid, 0, 16) . '...',
                    'contact_address' => $contactAddress ?? 'unknown'
                ]);
                $recoveryResult = $this->attemptBackupRecovery($missingTxid, $contactPubkeyHash, $contactPubkey);
                if ($recoveryResult['success']) {
                    $result['success'] = true;
                    $result['recovered_from_backup'] = true;
                    $result['missing_txid'] = $missingTxid;
                    $result['backup_filename'] = $recoveryResult['filename'] ?? null;
                    return $result;
                }
            }

            // Check for existing active proposal for this gap
            $existing = $this->proposalRepository->getActiveProposalForGap($contactPubkeyHash, $missingTxid);
            if ($existing) {
                $result['error'] = 'An active proposal already exists for this gap';
                $result['proposal_id'] = $existing['proposal_id'];
                return $result;
            }

            // Determine previous_txid_before_gap
            $previousTxidBeforeGap = $this->findPreviousTxidBeforeGap(
                $missingTxid, $brokenTxid, $contactPubkey
            );

            // Generate proposal ID
            $proposalId = 'cdp-' . hash('sha256', $missingTxid . $brokenTxid . microtime(true));

            // Create proposal record
            $created = $this->proposalRepository->createProposal([
                'proposal_id' => $proposalId,
                'contact_pubkey_hash' => $contactPubkeyHash,
                'missing_txid' => $missingTxid,
                'broken_txid' => $brokenTxid,
                'previous_txid_before_gap' => $previousTxidBeforeGap,
                'direction' => 'outgoing',
                'gap_context' => [
                    'chain_transaction_count' => $chainStatus['transaction_count'],
                    'total_gaps' => count($gaps),
                    'proposed_at' => date('c')
                ]
            ]);

            if (!$created) {
                $result['error'] = 'Failed to create proposal record';
                return $result;
            }

            // Resolve contact address for sending proposal
            $contactAddress = $this->resolveContactAddress($contactPubkeyHash);
            if (!$contactAddress) {
                $result['error'] = 'Could not resolve contact address';
                return $result;
            }

            // Send proposal to contact
            $payload = $this->messagePayload->buildChainDropProposal(
                $contactAddress,
                $proposalId,
                $missingTxid,
                $brokenTxid,
                $previousTxidBeforeGap,
                ['chain_transaction_count' => $chainStatus['transaction_count']]
            );

            $response = $this->transportUtility->send($contactAddress, $payload);

            Logger::getInstance()->info("Chain drop proposed", [
                'proposal_id' => $proposalId,
                'missing_txid' => substr($missingTxid, 0, 16) . '...',
                'broken_txid' => substr($brokenTxid, 0, 16) . '...',
                'contact_address' => $contactAddress
            ]);

            EventDispatcher::getInstance()->dispatch(ChainDropEvents::CHAIN_DROP_PROPOSED, [
                'proposal_id' => $proposalId,
                'contact_pubkey_hash' => $contactPubkeyHash,
                'missing_txid' => $missingTxid,
                'broken_txid' => $brokenTxid
            ]);

            $result['success'] = true;
            $result['proposal_id'] = $proposalId;
            $result['missing_txid'] = $missingTxid;
            $result['broken_txid'] = $brokenTxid;

        } catch (Exception $e) {
            $result['error'] = 'Failed to propose chain drop: ' . $e->getMessage();
            Logger::getInstance()->logException($e, ['method' => 'proposeChainDrop']);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function handleIncomingProposal(array $request): void
    {
        try {
            $proposalId = $request['proposalId'] ?? null;
            $missingTxid = $request['missingTxid'] ?? null;
            $brokenTxid = $request['brokenTxid'] ?? null;
            $senderPubkey = $request['senderPublicKey'] ?? null;
            $senderAddress = $request['senderAddress'] ?? null;

            if (!$proposalId || !$missingTxid || !$brokenTxid || !$senderPubkey) {
                Logger::getInstance()->warning("Invalid chain drop proposal: missing fields");
                return;
            }

            $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $senderPubkey);

            // Check for existing active proposal for this gap
            $existing = $this->proposalRepository->getActiveProposalForGap($contactPubkeyHash, $missingTxid);
            if ($existing) {
                Logger::getInstance()->info("Chain drop proposal already exists for this gap", [
                    'existing_proposal_id' => $existing['proposal_id'],
                    'incoming_proposal_id' => $proposalId
                ]);
                return;
            }

            // Verify the gap exists locally
            $chainStatus = $this->transactionChainRepository->verifyChainIntegrity(
                $this->currentUser->getPublicKey(),
                $senderPubkey
            );

            if ($chainStatus['valid'] || !in_array($missingTxid, $chainStatus['gaps'] ?? [])) {
                // We don't have this gap — reject automatically
                Logger::getInstance()->info("Rejecting chain drop proposal: gap not found locally", [
                    'proposal_id' => $proposalId,
                    'missing_txid' => substr($missingTxid, 0, 16) . '...'
                ]);

                if ($senderAddress) {
                    $rejectPayload = $this->messagePayload->buildChainDropRejection(
                        $senderAddress,
                        $proposalId,
                        'transaction_exists_locally'
                    );
                    $this->transportUtility->send($senderAddress, $rejectPayload);
                }
                return;
            }

            // Fallback: attempt backup recovery at incoming-proposal level
            // This is a safety net — sync-level recovery should have caught this already
            if ($this->backupService !== null) {
                Logger::getInstance()->info("Backup recovery fallback triggered at incoming-proposal level (sync-level recovery should have handled this)", [
                    'proposal_id' => $proposalId,
                    'missing_txid' => substr($missingTxid, 0, 16) . '...'
                ]);
                $recoveryResult = $this->attemptBackupRecovery($missingTxid, $contactPubkeyHash, $senderPubkey);
                if ($recoveryResult['success']) {
                    // Chain repaired from backup — reject the proposal
                    Logger::getInstance()->info("Transaction recovered from backup, rejecting chain drop proposal", [
                        'proposal_id' => $proposalId,
                        'missing_txid' => substr($missingTxid, 0, 16) . '...',
                        'backup' => $recoveryResult['filename'] ?? 'unknown'
                    ]);
                    if ($senderAddress) {
                        $rejectPayload = $this->messagePayload->buildChainDropRejection(
                            $senderAddress,
                            $proposalId,
                            'recovered_from_backup'
                        );
                        $this->transportUtility->send($senderAddress, $rejectPayload);
                    }
                    return;
                }
            }

            // Determine our view of the previous_txid_before_gap
            $previousTxidBeforeGap = $this->findPreviousTxidBeforeGap(
                $missingTxid, $brokenTxid, $senderPubkey
            );

            // Store incoming proposal
            $created = $this->proposalRepository->createProposal([
                'proposal_id' => $proposalId,
                'contact_pubkey_hash' => $contactPubkeyHash,
                'missing_txid' => $missingTxid,
                'broken_txid' => $brokenTxid,
                'previous_txid_before_gap' => $previousTxidBeforeGap,
                'direction' => 'incoming',
                'gap_context' => [
                    'remote_context' => $request['gapContext'] ?? [],
                    'local_gap_count' => count($chainStatus['gaps']),
                    'received_at' => date('c')
                ]
            ]);

            if ($created) {
                Logger::getInstance()->info("Chain drop proposal received and stored", [
                    'proposal_id' => $proposalId,
                    'contact_pubkey_hash' => substr($contactPubkeyHash, 0, 16) . '...'
                ]);
            }

        } catch (Exception $e) {
            Logger::getInstance()->logException($e, ['method' => 'handleIncomingProposal']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function acceptProposal(string $proposalId): array
    {
        $result = ['success' => false, 'error' => null];

        try {
            $proposal = $this->proposalRepository->getByProposalId($proposalId);
            if (!$proposal) {
                $result['error'] = 'Proposal not found';
                return $result;
            }

            if ($proposal['direction'] !== 'incoming') {
                $result['error'] = 'Can only accept incoming proposals';
                return $result;
            }

            if ($proposal['status'] !== 'pending') {
                $result['error'] = 'Proposal is no longer pending (status: ' . $proposal['status'] . ')';
                return $result;
            }

            // Execute the chain drop locally
            $dropResult = $this->executeChainDrop($proposal);
            if (!$dropResult['success']) {
                $result['error'] = 'Failed to execute chain drop: ' . ($dropResult['error'] ?? 'unknown');
                return $result;
            }

            // Recalculate balance now that chain has been modified
            $this->syncContactBalanceAfterDrop($proposal['contact_pubkey_hash']);

            // Update proposal status
            $this->proposalRepository->updateStatus($proposalId, 'accepted');

            // Send acceptance with our re-signed transaction data
            $contactAddress = $this->resolveContactAddress($proposal['contact_pubkey_hash']);
            if ($contactAddress) {
                $acceptPayload = $this->messagePayload->buildChainDropAcceptance(
                    $contactAddress,
                    $proposalId,
                    $dropResult['resigned_transactions'] ?? []
                );
                $this->transportUtility->send($contactAddress, $acceptPayload);
            }

            Logger::getInstance()->info("Chain drop proposal accepted", [
                'proposal_id' => $proposalId,
                'resigned_count' => count($dropResult['resigned_transactions'] ?? [])
            ]);

            EventDispatcher::getInstance()->dispatch(ChainDropEvents::CHAIN_DROP_ACCEPTED, [
                'proposal_id' => $proposalId,
                'contact_pubkey_hash' => $proposal['contact_pubkey_hash'],
                'direction' => 'incoming'
            ]);

            $result['success'] = true;

        } catch (Exception $e) {
            $result['error'] = 'Exception accepting proposal: ' . $e->getMessage();
            Logger::getInstance()->logException($e, ['method' => 'acceptProposal']);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function handleIncomingAcceptance(array $request): void
    {
        try {
            $proposalId = $request['proposalId'] ?? null;
            $resignedTransactions = $request['resignedTransactions'] ?? [];

            if (!$proposalId) {
                Logger::getInstance()->warning("Invalid chain drop acceptance: missing proposalId");
                return;
            }

            $proposal = $this->proposalRepository->getByProposalId($proposalId);
            if (!$proposal || $proposal['direction'] !== 'outgoing' || $proposal['status'] !== 'pending') {
                Logger::getInstance()->warning("Chain drop acceptance for invalid proposal", [
                    'proposal_id' => $proposalId,
                    'found' => $proposal !== null,
                    'direction' => $proposal['direction'] ?? 'unknown',
                    'status' => $proposal['status'] ?? 'unknown'
                ]);
                return;
            }

            // Execute the chain drop locally
            $dropResult = $this->executeChainDrop($proposal);
            if (!$dropResult['success']) {
                Logger::getInstance()->error("Failed to execute chain drop on acceptance", [
                    'proposal_id' => $proposalId,
                    'error' => $dropResult['error'] ?? 'unknown'
                ]);
                $this->proposalRepository->updateStatus($proposalId, 'failed');
                return;
            }

            // Process the contact's re-signed transactions
            $this->processResignedTransactions($resignedTransactions);

            // Recalculate balance now that chain has been modified
            $this->syncContactBalanceAfterDrop($proposal['contact_pubkey_hash']);

            // Update proposal status
            $this->proposalRepository->updateStatus($proposalId, 'accepted');

            // Send acknowledgment with our re-signed transaction data
            $contactAddress = $this->resolveContactAddress($proposal['contact_pubkey_hash']);
            if ($contactAddress) {
                $ackPayload = $this->messagePayload->buildChainDropAcknowledgment(
                    $contactAddress,
                    $proposalId,
                    $dropResult['resigned_transactions'] ?? []
                );
                $this->transportUtility->send($contactAddress, $ackPayload);
            }

            // Mark as executed
            $this->proposalRepository->markExecuted($proposalId);

            Logger::getInstance()->info("Chain drop executed after acceptance", [
                'proposal_id' => $proposalId,
                'local_resigned' => count($dropResult['resigned_transactions'] ?? []),
                'remote_resigned' => count($resignedTransactions)
            ]);

            EventDispatcher::getInstance()->dispatch(ChainDropEvents::CHAIN_DROP_EXECUTED, [
                'proposal_id' => $proposalId,
                'contact_pubkey_hash' => $proposal['contact_pubkey_hash'],
                'missing_txid' => $proposal['missing_txid'],
                'broken_txid' => $proposal['broken_txid'],
                'new_previous_txid' => $proposal['previous_txid_before_gap']
            ]);

        } catch (Exception $e) {
            Logger::getInstance()->logException($e, ['method' => 'handleIncomingAcceptance']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function handleIncomingAcknowledgment(array $request): void
    {
        try {
            $proposalId = $request['proposalId'] ?? null;
            $resignedTransactions = $request['resignedTransactions'] ?? [];

            if (!$proposalId) {
                return;
            }

            $proposal = $this->proposalRepository->getByProposalId($proposalId);
            if (!$proposal || $proposal['direction'] !== 'incoming') {
                return;
            }

            // Process the proposer's re-signed transactions
            $this->processResignedTransactions($resignedTransactions);

            // Mark as fully executed
            $this->proposalRepository->markExecuted($proposalId);

            Logger::getInstance()->info("Chain drop fully completed", [
                'proposal_id' => $proposalId,
                'remote_resigned' => count($resignedTransactions)
            ]);

            EventDispatcher::getInstance()->dispatch(ChainDropEvents::CHAIN_DROP_EXECUTED, [
                'proposal_id' => $proposalId,
                'contact_pubkey_hash' => $proposal['contact_pubkey_hash'],
                'missing_txid' => $proposal['missing_txid'],
                'broken_txid' => $proposal['broken_txid'],
                'new_previous_txid' => $proposal['previous_txid_before_gap']
            ]);

        } catch (Exception $e) {
            Logger::getInstance()->logException($e, ['method' => 'handleIncomingAcknowledgment']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rejectProposal(string $proposalId): array
    {
        $result = ['success' => false, 'error' => null];

        try {
            $proposal = $this->proposalRepository->getByProposalId($proposalId);
            if (!$proposal) {
                $result['error'] = 'Proposal not found';
                return $result;
            }

            if ($proposal['direction'] !== 'incoming') {
                $result['error'] = 'Can only reject incoming proposals';
                return $result;
            }

            if ($proposal['status'] !== 'pending') {
                $result['error'] = 'Proposal is no longer pending';
                return $result;
            }

            $this->proposalRepository->updateStatus($proposalId, 'rejected');

            // Notify the proposer
            $contactAddress = $this->resolveContactAddress($proposal['contact_pubkey_hash']);
            if ($contactAddress) {
                $rejectPayload = $this->messagePayload->buildChainDropRejection(
                    $contactAddress,
                    $proposalId,
                    'user_rejected'
                );
                $this->transportUtility->send($contactAddress, $rejectPayload);
            }

            Logger::getInstance()->info("Chain drop proposal rejected", [
                'proposal_id' => $proposalId
            ]);

            EventDispatcher::getInstance()->dispatch(ChainDropEvents::CHAIN_DROP_REJECTED, [
                'proposal_id' => $proposalId,
                'contact_pubkey_hash' => $proposal['contact_pubkey_hash'],
                'reason' => 'user_rejected'
            ]);

            $result['success'] = true;

        } catch (Exception $e) {
            $result['error'] = 'Exception rejecting proposal: ' . $e->getMessage();
            Logger::getInstance()->logException($e, ['method' => 'rejectProposal']);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function handleIncomingRejection(array $request): void
    {
        try {
            $proposalId = $request['proposalId'] ?? null;
            $reason = $request['reason'] ?? 'unknown';

            if (!$proposalId) {
                return;
            }

            $proposal = $this->proposalRepository->getByProposalId($proposalId);
            if (!$proposal || $proposal['direction'] !== 'outgoing') {
                return;
            }

            $this->proposalRepository->updateStatus($proposalId, 'rejected');

            Logger::getInstance()->info("Chain drop proposal was rejected by contact", [
                'proposal_id' => $proposalId,
                'reason' => $reason
            ]);

            EventDispatcher::getInstance()->dispatch(ChainDropEvents::CHAIN_DROP_REJECTED, [
                'proposal_id' => $proposalId,
                'contact_pubkey_hash' => $proposal['contact_pubkey_hash'],
                'reason' => $reason
            ]);

        } catch (Exception $e) {
            Logger::getInstance()->logException($e, ['method' => 'handleIncomingRejection']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getProposalsForContact(string $contactPubkeyHash): array
    {
        return $this->proposalRepository->getPendingForContact($contactPubkeyHash);
    }

    /**
     * {@inheritdoc}
     */
    public function getIncomingPendingProposals(): array
    {
        return $this->proposalRepository->getIncomingPending();
    }

    /**
     * {@inheritdoc}
     */
    public function expireStaleProposals(): int
    {
        return $this->proposalRepository->expireOldProposals();
    }

    // =========================================================================
    // CLI COMMAND HANDLING
    // =========================================================================

    /**
     * Handle CLI chain drop subcommands
     *
     * @param array $argv Command line arguments
     * @param CliOutputManager $output CLI output manager
     * @return void
     */
    public function handleCommand(array $argv, CliOutputManager $output): void
    {
        $subcommand = $argv[2] ?? 'help';

        switch ($subcommand) {
            case 'propose':
                $this->handleProposeCommand($argv, $output);
                break;
            case 'accept':
                $this->handleAcceptCommand($argv, $output);
                break;
            case 'reject':
                $this->handleRejectCommand($argv, $output);
                break;
            case 'list':
                $this->handleListCommand($argv, $output);
                break;
            case 'help':
            default:
                $this->showHelp($output);
                break;
        }
    }

    /**
     * Handle 'chaindrop propose' subcommand
     */
    private function handleProposeCommand(array $argv, CliOutputManager $output): void
    {
        $contactIdentifier = $argv[3] ?? null;
        if (!$contactIdentifier) {
            $output->error("Contact address required. Usage: eiou chaindrop propose <contact_address>", ErrorCodes::MISSING_ARGUMENT);
            exit(1);
        }

        $transportIndex = $this->transportUtility->determineTransportType($contactIdentifier);
        $contact = $transportIndex ? $this->contactRepository->lookupByAddress($transportIndex, $contactIdentifier) : null;
        if (!$contact) {
            $output->error("Contact not found: {$contactIdentifier}", ErrorCodes::CONTACT_NOT_FOUND);
            exit(1);
        }

        $result = $this->proposeChainDrop($contact['pubkey_hash']);
        if ($result['success']) {
            $output->success("Chain drop proposal sent", [
                'proposal_id' => $result['proposal_id'],
                'missing_txid' => $result['missing_txid'] ?? null
            ]);
        } else {
            $output->error($result['error'] ?? 'Proposal failed', ErrorCodes::GENERAL_ERROR);
            exit(1);
        }
    }

    /**
     * Handle 'chaindrop accept' subcommand
     */
    private function handleAcceptCommand(array $argv, CliOutputManager $output): void
    {
        $proposalId = $argv[3] ?? null;
        if (!$proposalId) {
            $output->error("Proposal ID required. Usage: eiou chaindrop accept <proposal_id>", ErrorCodes::MISSING_ARGUMENT);
            exit(1);
        }

        $result = $this->acceptProposal($proposalId);
        if ($result['success']) {
            $output->success("Chain drop proposal accepted and executed", [
                'proposal_id' => $proposalId
            ]);
        } else {
            $output->error($result['error'] ?? 'Accept failed', ErrorCodes::GENERAL_ERROR);
            exit(1);
        }
    }

    /**
     * Handle 'chaindrop reject' subcommand
     */
    private function handleRejectCommand(array $argv, CliOutputManager $output): void
    {
        $proposalId = $argv[3] ?? null;
        if (!$proposalId) {
            $output->error("Proposal ID required. Usage: eiou chaindrop reject <proposal_id>", ErrorCodes::MISSING_ARGUMENT);
            exit(1);
        }

        $result = $this->rejectProposal($proposalId);
        if ($result['success']) {
            $output->success("Chain drop proposal rejected", [
                'proposal_id' => $proposalId
            ]);
            $output->info("Note: The chain gap remains unresolved. Transactions with this contact are blocked until a new chain drop proposal is accepted.");
        } else {
            $output->error($result['error'] ?? 'Reject failed', ErrorCodes::GENERAL_ERROR);
            exit(1);
        }
    }

    /**
     * Handle 'chaindrop list' subcommand
     */
    private function handleListCommand(array $argv, CliOutputManager $output): void
    {
        $contactIdentifier = $argv[3] ?? null;

        if ($contactIdentifier) {
            $transportIndex = $this->transportUtility->determineTransportType($contactIdentifier);
            $contact = $transportIndex ? $this->contactRepository->lookupByAddress($transportIndex, $contactIdentifier) : null;
            if (!$contact) {
                $output->error("Contact not found: {$contactIdentifier}", ErrorCodes::CONTACT_NOT_FOUND);
                exit(1);
            }
            $proposals = $this->getProposalsForContact($contact['pubkey_hash']);
        } else {
            $proposals = $this->getIncomingPendingProposals();
        }

        $output->success("Chain drop proposals", [
            'count' => count($proposals),
            'proposals' => $proposals
        ]);
    }

    /**
     * Show chain drop help for CLI
     */
    private function showHelp(CliOutputManager $output): void
    {
        $output->info("Chain Drop Agreement Commands:");
        $output->info("  eiou chaindrop propose <contact_address>  - Propose dropping a missing transaction");
        $output->info("  eiou chaindrop accept <proposal_id>       - Accept an incoming proposal");
        $output->info("  eiou chaindrop reject <proposal_id>       - Reject an incoming proposal");
        $output->info("  eiou chaindrop list [contact_address]     - List pending proposals");
        $output->info("  eiou chaindrop help                       - Show this help");
        $output->info("");
        $output->info("When a chain gap exists, transactions with that contact are blocked.");
        $output->info("Rejecting a proposal leaves the gap unresolved. A new proposal must");
        $output->info("be accepted to restore the ability to transact.");
    }

    // =========================================================================
    // BACKUP RECOVERY
    // =========================================================================

    /**
     * Attempt to recover a missing transaction from database backups
     *
     * Searches all available backups (newest first) for the missing transaction.
     * If found, restores just that single transaction and verifies the chain is repaired.
     *
     * @param string $missingTxid The txid of the missing transaction
     * @param string $contactPubkeyHash The contact's public key hash
     * @param string $contactPubkey The contact's public key
     * @return array Result with keys: success (bool), filename (string|null)
     */
    private function attemptBackupRecovery(string $missingTxid, string $contactPubkeyHash, string $contactPubkey): array
    {
        $result = ['success' => false, 'filename' => null];

        try {
            Logger::getInstance()->info("Attempting to recover missing transaction from backups", [
                'missing_txid' => substr($missingTxid, 0, 16) . '...'
            ]);

            $restoreResult = $this->backupService->restoreTransactionFromBackup($missingTxid);

            if (!$restoreResult['success']) {
                Logger::getInstance()->info("Transaction not found in any backup", [
                    'missing_txid' => substr($missingTxid, 0, 16) . '...',
                    'error' => $restoreResult['error'] ?? 'unknown'
                ]);
                return $result;
            }

            // Verify the chain is now valid after restoring the transaction
            $recheckStatus = $this->transactionChainRepository->verifyChainIntegrity(
                $this->currentUser->getPublicKey(),
                $contactPubkey
            );

            if ($recheckStatus['valid']) {
                Logger::getInstance()->info("Chain repaired by restoring transaction from backup", [
                    'missing_txid' => substr($missingTxid, 0, 16) . '...',
                    'backup' => $restoreResult['filename']
                ]);

                EventDispatcher::getInstance()->dispatch(ChainDropEvents::TRANSACTION_RECOVERED_FROM_BACKUP, [
                    'missing_txid' => $missingTxid,
                    'contact_pubkey_hash' => $contactPubkeyHash,
                    'backup_filename' => $restoreResult['filename']
                ]);

                $result['success'] = true;
                $result['filename'] = $restoreResult['filename'];
            } else {
                Logger::getInstance()->info("Transaction restored from backup but chain still has gaps", [
                    'missing_txid' => substr($missingTxid, 0, 16) . '...',
                    'remaining_gaps' => count($recheckStatus['gaps'] ?? [])
                ]);
            }
        } catch (Exception $e) {
            Logger::getInstance()->warning("Backup recovery attempt failed", [
                'missing_txid' => substr($missingTxid, 0, 16) . '...',
                'error' => $e->getMessage()
            ]);
        }

        return $result;
    }

    // =========================================================================
    // CHAIN DROP EXECUTION
    // =========================================================================

    /**
     * Execute the chain drop: update previous_txid and re-sign affected transactions
     *
     * @param array $proposal The proposal data
     * @return array Result with keys: success (bool), resigned_transactions (array), error (string|null)
     */
    private function executeChainDrop(array $proposal): array
    {
        $result = ['success' => false, 'resigned_transactions' => [], 'error' => null];

        try {
            $brokenTxid = $proposal['broken_txid'];
            $newPreviousTxid = $proposal['previous_txid_before_gap'];

            // Update previous_txid of the broken transaction
            $updated = $this->transactionChainRepository->updatePreviousTxid($brokenTxid, $newPreviousTxid);
            if (!$updated) {
                $result['error'] = 'Failed to update previous_txid for broken transaction';
                return $result;
            }

            Logger::getInstance()->info("Chain drop: updated previous_txid", [
                'broken_txid' => substr($brokenTxid, 0, 16) . '...',
                'new_previous_txid' => $newPreviousTxid ? substr($newPreviousTxid, 0, 16) . '...' : 'NULL'
            ]);

            // Check if the broken transaction was sent by us — if so, re-sign it
            $transactionData = $this->transactionRepository->getByTxid($brokenTxid);
            if ($transactionData) {
                $transaction = is_array($transactionData) && isset($transactionData[0])
                    ? $transactionData[0] : $transactionData;

                $userPubkeyHash = hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey());

                if (($transaction['sender_public_key_hash'] ?? '') === $userPubkeyHash) {
                    // We sent this transaction — re-sign it
                    $resignResult = $this->resignTransaction($brokenTxid);
                    if ($resignResult) {
                        // Get updated transaction data for exchange
                        $updatedTx = $this->transactionRepository->getByTxid($brokenTxid);
                        $updatedTx = is_array($updatedTx) && isset($updatedTx[0]) ? $updatedTx[0] : $updatedTx;
                        $result['resigned_transactions'][] = [
                            'txid' => $brokenTxid,
                            'previous_txid' => $newPreviousTxid,
                            'sender_signature' => $updatedTx['sender_signature'] ?? null,
                            'signature_nonce' => $updatedTx['signature_nonce'] ?? null
                        ];
                    } else {
                        $result['error'] = 'Failed to re-sign broken transaction';
                        // Attempt rollback
                        $this->transactionChainRepository->updatePreviousTxid(
                            $brokenTxid, $proposal['missing_txid']
                        );
                        return $result;
                    }
                }
            }

            $result['success'] = true;

        } catch (Exception $e) {
            $result['error'] = 'Exception during chain drop execution: ' . $e->getMessage();
            Logger::getInstance()->logException($e, ['method' => 'executeChainDrop']);
        }

        return $result;
    }

    /**
     * Recalculate the contact balance after a chain drop
     *
     * The dropped transaction no longer exists in the transactions table, so
     * syncContactBalance will recompute the balance from the remaining
     * completed transactions, producing the correct total.
     *
     * @param string $contactPubkeyHash The contact's public key hash
     */
    private function syncContactBalanceAfterDrop(string $contactPubkeyHash): void
    {
        if (!$this->syncTrigger) {
            Logger::getInstance()->warning("Cannot sync balance after chain drop: no sync trigger configured");
            return;
        }

        try {
            $contact = $this->contactRepository->lookupByPubkeyHash($contactPubkeyHash);
            if ($contact && !empty($contact['pubkey'])) {
                $syncResult = $this->syncTrigger->syncContactBalance($contact['pubkey']);
                Logger::getInstance()->info("Balance recalculated after chain drop", [
                    'contact_pubkey_hash' => substr($contactPubkeyHash, 0, 16) . '...',
                    'success' => $syncResult['success'] ?? false,
                    'currencies' => $syncResult['currencies'] ?? []
                ]);
            }
        } catch (Exception $e) {
            Logger::getInstance()->warning("Failed to sync balance after chain drop", [
                'contact_pubkey_hash' => substr($contactPubkeyHash, 0, 16) . '...',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Re-sign a transaction after its previous_txid has been updated
     *
     * Reuses the same pattern as HeldTransactionService::resignTransaction().
     *
     * @param string $txid The transaction ID to re-sign
     * @return bool True on success
     */
    private function resignTransaction(string $txid): bool
    {
        try {
            $transactionData = $this->transactionRepository->getByTxid($txid);
            if (!$transactionData) {
                Logger::getInstance()->warning("Cannot resign for chain drop: transaction not found", [
                    'txid' => $txid
                ]);
                return false;
            }

            $transaction = is_array($transactionData) && isset($transactionData[0])
                ? $transactionData[0] : $transactionData;

            // Build payload for signing based on memo type
            $memo = $transaction['memo'] ?? 'standard';
            if ($memo === 'standard') {
                $payload = $this->transactionPayload->buildStandardFromDatabase($transaction);
            } else {
                $payload = $this->transactionPayload->buildFromDatabase($transaction);
            }

            // Re-sign
            $signResult = $this->transportUtility->signWithCapture($payload);
            if (!$signResult || !isset($signResult['signature']) || !isset($signResult['nonce'])) {
                Logger::getInstance()->warning("Chain drop re-sign failed: invalid signWithCapture result", [
                    'txid' => $txid
                ]);
                return false;
            }

            // Update signature in database
            $updated = $this->transactionRepository->updateSignatureData(
                $txid,
                $signResult['signature'],
                $signResult['nonce']
            );

            if (!$updated) {
                Logger::getInstance()->warning("Chain drop: failed to update signature in database", [
                    'txid' => $txid
                ]);
                return false;
            }

            Logger::getInstance()->info("Chain drop: re-signed transaction", [
                'txid' => substr($txid, 0, 16) . '...',
                'new_nonce' => $signResult['nonce']
            ]);

            return true;

        } catch (Exception $e) {
            Logger::getInstance()->logException($e, [
                'method' => 'resignTransaction (chain drop)',
                'txid' => $txid
            ]);
            return false;
        }
    }

    /**
     * Process re-signed transactions received from the other party
     *
     * Updates local signature data for transactions that were re-signed by the contact.
     *
     * @param array $resignedTransactions Array of re-signed transaction data
     * @return void
     */
    private function processResignedTransactions(array $resignedTransactions): void
    {
        foreach ($resignedTransactions as $txData) {
            $txid = $txData['txid'] ?? null;
            $signature = $txData['sender_signature'] ?? null;
            $nonce = $txData['signature_nonce'] ?? null;

            if (!$txid || !$signature || $nonce === null) {
                Logger::getInstance()->warning("Skipping invalid re-signed transaction data", [
                    'txid' => $txid
                ]);
                continue;
            }

            $updated = $this->transactionRepository->updateSignatureData($txid, $signature, (int)$nonce);
            if ($updated) {
                Logger::getInstance()->info("Chain drop: stored contact's re-signed transaction", [
                    'txid' => substr($txid, 0, 16) . '...'
                ]);
            }
        }
    }

    /**
     * Find the transaction that should become the new previous_txid after dropping the gap
     *
     * Given the chain ...→ A → [B missing] → C → ...
     * This finds A (the transaction before the missing B).
     *
     * Strategy: Get the broken transaction (C), look at the full chain, and find
     * the transaction that comes before the missing one in chronological order.
     *
     * @param string $missingTxid The txid that is missing (B)
     * @param string $brokenTxid The txid that points to the missing one (C)
     * @param string $contactPubkey The contact's public key
     * @return string|null The txid before the gap (A), or null if it's the chain start
     */
    private function findPreviousTxidBeforeGap(string $missingTxid, string $brokenTxid, string $contactPubkey): ?string
    {
        // Get the full chain between the two parties
        $chain = $this->transactionChainRepository->getTransactionChain(
            $this->currentUser->getPublicKey(),
            $contactPubkey
        );

        if (empty($chain)) {
            return null;
        }

        // Find the broken transaction in the chain and get the transaction before the gap
        // The gap means: broken_txid->previous_txid = missing_txid, but missing_txid doesn't exist
        // We need to find what missing_txid's previous_txid WOULD have been
        // Since we don't have the missing tx, we look for the tx whose next-in-chain is the broken tx
        // In a properly ordered chain: ... → prev_before_gap → [missing] → broken → ...
        // We want prev_before_gap

        // Simple approach: walk the chain and find the tx right before the broken one
        // that isn't the missing one
        $previousTx = null;
        foreach ($chain as $tx) {
            if ($tx['txid'] === $brokenTxid) {
                break;
            }
            $previousTx = $tx;
        }

        return $previousTx ? $previousTx['txid'] : null;
    }

    /**
     * Resolve a contact's network address from their pubkey hash
     *
     * @param string $contactPubkeyHash The contact's public key hash
     * @return string|null The contact's address or null
     */
    private function resolveContactAddress(string $contactPubkeyHash): ?string
    {
        try {
            $contact = $this->contactRepository->lookupByPubkeyHash($contactPubkeyHash);
            if (!$contact) {
                return null;
            }

            // Priority: tor > https > http (most secure first)
            return $contact['tor'] ?? $contact['https'] ?? $contact['http'] ?? null;
        } catch (Exception $e) {
            Logger::getInstance()->warning("Failed to resolve contact address", [
                'pubkey_hash' => substr($contactPubkeyHash, 0, 16) . '...',
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
