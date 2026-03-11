<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\Constants;
use Eiou\Core\UserContext;
use Eiou\Utils\Logger;
use Eiou\Contracts\TransactionProcessingServiceInterface;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Contracts\HeldTransactionServiceInterface;
use Eiou\Database\TransactionRepository;
use Eiou\Database\TransactionRecoveryRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Schemas\Payloads\TransactionPayload;
use RuntimeException;
use PDOException;
use Exception;
use InvalidArgumentException;

/**
 * Transaction Processing Service
 *
 * Handles core transaction processing logic including incoming transactions,
 * pending transactions, and P2P transactions. Extracted from TransactionService
 * as part of the God Class refactoring.
 *
 * Atomic Claiming Pattern:
 * Uses TransactionRecoveryRepository->claimPendingTransaction() for atomic
 * status transition (PENDING -> SENDING) to prevent duplicate processing.
 */
class TransactionProcessingService implements TransactionProcessingServiceInterface
{
    private TransactionRepository $transactionRepository;
    private TransactionRecoveryRepository $transactionRecoveryRepository;
    private TransactionChainRepository $transactionChainRepository;
    private P2pRepository $p2pRepository;
    private Rp2pRepository $rp2pRepository;
    private BalanceRepository $balanceRepository;
    private TransactionPayload $transactionPayload;
    private TransportUtilityService $transportUtility;
    private TimeUtilityService $timeUtility;
    private UserContext $currentUser;
    private Logger $secureLogger;
    private ?MessageDeliveryService $messageDeliveryService;
    /**
     * @var SyncTriggerInterface|null Sync trigger for conflict resolution
     */
    private ?SyncTriggerInterface $syncTrigger = null;
    private ?P2pServiceInterface $p2pService = null;
    private ?HeldTransactionServiceInterface $heldTransactionService = null;

    public function __construct(
        TransactionRepository $transactionRepository,
        TransactionRecoveryRepository $transactionRecoveryRepository,
        TransactionChainRepository $transactionChainRepository,
        P2pRepository $p2pRepository,
        Rp2pRepository $rp2pRepository,
        BalanceRepository $balanceRepository,
        TransactionPayload $transactionPayload,
        TransportUtilityService $transportUtility,
        TimeUtilityService $timeUtility,
        UserContext $currentUser,
        Logger $secureLogger,
        ?MessageDeliveryService $messageDeliveryService = null
    ) {
        $this->transactionRepository = $transactionRepository;
        $this->transactionRecoveryRepository = $transactionRecoveryRepository;
        $this->transactionChainRepository = $transactionChainRepository;
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->balanceRepository = $balanceRepository;
        $this->transactionPayload = $transactionPayload;
        $this->transportUtility = $transportUtility;
        $this->timeUtility = $timeUtility;
        $this->currentUser = $currentUser;
        $this->secureLogger = $secureLogger;
        $this->messageDeliveryService = $messageDeliveryService;
    }

    /**
     * Set the sync trigger (accepts interface for loose coupling)
     *
     * @param SyncTriggerInterface $sync Sync trigger (can be proxy or actual service)
     */
    public function setSyncTrigger(SyncTriggerInterface $sync): void
    {
        $this->syncTrigger = $sync;
    }

    public function setP2pService(P2pServiceInterface $p2pService): void
    {
        $this->p2pService = $p2pService;
    }

    public function setHeldTransactionService(HeldTransactionServiceInterface $heldTransactionService): void
    {
        $this->heldTransactionService = $heldTransactionService;
    }

    public function setUtilityContainer(UtilityServiceContainer $utilityContainer): void
    {
        $this->utilityContainer = $utilityContainer;
        $this->timeUtility = $utilityContainer->getTimeUtility();
    }

    private function getSyncTrigger(): SyncTriggerInterface
    {
        if ($this->syncTrigger === null) {
            throw new RuntimeException('SyncTrigger not injected. Call setSyncTrigger() or ensure ServiceContainer properly injects the dependency.');
        }
        return $this->syncTrigger;
    }

    private function getP2pService(): P2pServiceInterface
    {
        if ($this->p2pService === null) {
            throw new RuntimeException('P2pService not injected.');
        }
        return $this->p2pService;
    }

    /**
     * Process incoming transaction request
     */
    public function processTransaction(array $request): void
    {
        try {
            if (!isset($request['memo'], $request['senderAddress'])) {
                $this->secureLogger->error("Missing required fields in transaction request", [
                    'request_keys' => array_keys($request)
                ]);
                throw new InvalidArgumentException("Invalid transaction request structure");
            }

            if ($request['memo'] === 'standard') {
                $this->processStandardIncoming($request);
            } else {
                $this->processP2pIncoming($request);
            }
        } catch (PDOException $e) {
            Logger::getInstance()->logException($e, ['method' => 'processTransaction', 'context' => 'transaction_processing']);
            throw $e;
        } catch (Exception $e) {
            Logger::getInstance()->logException($e, ['method' => 'processTransaction', 'context' => 'transaction_processing']);
            throw $e;
        }
    }

    private function processStandardIncoming(array $request): void
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);

        if (!isset($request['recipientSignature'])) {
            $request['recipientSignature'] = $this->transactionPayload->generateRecipientSignature($request);
        }

        $this->transactionRepository->insertTransaction($request, 'received');
        $this->transactionRepository->updateTrackingFields($request['txid'], $myAddress, $request['senderAddress']);
    }

    private function processP2pIncoming(array $request): void
    {
        $memo = $request['memo'];
        $rP2pResult = $this->rp2pRepository->getByHash($memo);

        if (isset($rP2pResult) && $memo === $rP2pResult['hash']) {
            // Relay transaction
            $insertTransactionResponse = json_decode(
                $this->transactionRepository->insertTransaction($request, 'relay'),
                true
            );
            output(outputTransactionInsertion($insertTransactionResponse));
        } elseif ($this->matchYourselfTransaction($request, $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']))) {
            // End-recipient
            $myAddress = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);

            if (!isset($request['recipientSignature'])) {
                $request['recipientSignature'] = $this->transactionPayload->generateRecipientSignature($request);
            }

            $insertTransactionResponse = json_decode(
                $this->transactionRepository->insertTransaction($request, 'received'),
                true
            );
            output(outputTransactionInsertion($insertTransactionResponse));
            $this->transactionRepository->updateTrackingFields($request['txid'], $myAddress, null);
        }
    }

    /**
     * Process pending transactions
     */
    public function processPendingTransactions(): int
    {
        $pendingMessages = $this->transactionRecoveryRepository->getPendingTransactions();
        $processedCount = 0;

        foreach ($pendingMessages as $message) {
            $memo = $message['memo'];
            $txid = $message['txid'];

            if ($memo === 'standard') {
                $result = $this->processDirectTransaction($message, $txid);
                if ($result === 'break') {
                    break;
                }
                $processedCount++;
            } else {
                if ($this->processP2pTransaction($message, $memo, $txid)) {
                    $processedCount++;
                }
            }
        }

        return $processedCount;
    }

    private function processDirectTransaction(array $message, string $txid): string
    {
        $isSender = $message['sender_address'] == $this->transportUtility->resolveUserAddressForTransport($message['sender_address']);

        if ($isSender) {
            return $this->processOutgoingDirect($message, $txid);
        } else {
            $this->processIncomingDirect($message, $txid);
            return 'continue';
        }
    }

    private function processOutgoingDirect(array $message, string $txid): string
    {
        if (!$this->transactionRecoveryRepository->claimPendingTransaction($txid)) {
            Logger::getInstance()->info("Transaction already claimed, skipping", ['txid' => $txid]);
            return 'continue';
        }

        // Proactive hold: if sync is in progress for this contact, hold the transaction
        // instead of sending it (would just get rejected with invalid_previous_txid)
        $receiverPubkey = $message['receiver_public_key'] ?? null;
        if ($receiverPubkey !== null && $this->heldTransactionService !== null
            && $this->heldTransactionService->shouldHoldTransactions($receiverPubkey)) {
            $holdResult = $this->heldTransactionService->holdTransactionForSync($message, $receiverPubkey);
            if ($holdResult['held']) {
                // Set back to pending so recovery doesn't interfere while we wait for sync
                $this->transactionRepository->updateStatus($txid, Constants::STATUS_PENDING, true);
                Logger::getInstance()->info("Proactively held direct transaction during sync", [
                    'txid' => $txid,
                    'receiver' => $message['receiver_address'] ?? 'unknown'
                ]);
                return 'continue';
            }
        }

        // Set expires_at if the user has configured a direct transaction delivery timeout.
        // Default is 0 (no expiry) — direct transactions stay retryable indefinitely unless
        // the user opts into a time-bounded delivery window via the directTxExpiration setting.
        $directExpiry = $this->currentUser->getDirectTxExpirationTime();
        if ($directExpiry > 0) {
            $expiresAt = date('Y-m-d H:i:s', time() + $directExpiry);
            $this->transactionRepository->setExpiresAt($txid, $expiresAt);
        }

        $payload = $this->transactionPayload->buildStandardFromDatabase($message);
        Logger::getInstance()->info("Sending standard transaction (claimed)", [
            'txid' => $txid,
            'previous_txid_in_db' => $message['previous_txid'] ?? 'NULL',
            'previous_txid_in_payload' => $payload['previousTxid'] ?? 'NULL',
            'receiver' => $message['receiver_address']
        ]);

        $sendResult = $this->sendTransactionMessage($message['receiver_address'], $payload, $txid);
        $this->transactionRecoveryRepository->markAsSent($txid);
        $response = $sendResult['response'];
        output(outputTransactionInquiryResponse($response), 'SILENT');

        if ($response && $response['status'] === Constants::STATUS_ACCEPTED) {
            $this->handleAcceptedTransaction($txid, $sendResult, $response);
        } elseif ($response && $response['status'] === Constants::STATUS_REJECTED) {
            if (isset($response['reason']) && $response['reason'] === 'invalid_previous_txid') {
                if ($this->handleInvalidPreviousTxidDirect($message, $response, $txid)) {
                    return 'break';
                }
            }
            $this->transactionRepository->updateStatus($txid, Constants::STATUS_REJECTED, true);
            output(outputIssueTransactionTryP2p($response), 'SILENT');
            $this->getP2pService()->sendP2pRequestFromFailedDirectTransaction($message);
        } elseif (!$sendResult['success']) {
            $this->logDeliveryFailure($txid, $sendResult, 'Transaction');

            // If delivery was exhausted and moved to the DLQ, cancel the transaction immediately
            // so it is removed from the In-Progress panel and stops triggering auto-refresh.
            // Without this it would stay in 'sent' status until expireStaleTransactions runs
            // (up to directTxExpiration seconds later). The DLQ retry path resets the status
            // back to 'sending' when the user retries, so cancelling here is safe.
            if (!empty($sendResult['tracking']['dlq'])) {
                $this->transactionRepository->updateStatus($txid, Constants::STATUS_CANCELLED, true);
            }
        }

        return 'continue';
    }

    private function processIncomingDirect(array $message, string $txid): void
    {
        $this->transactionRepository->beginTransaction();
        try {
            $this->transactionRepository->updateStatus($txid, Constants::STATUS_COMPLETED, true);
            $this->balanceRepository->updateBalance($message['sender_public_key'], 'received', $message['amount'], $message['currency']);
            $this->transactionRepository->commit();
        } catch (\Exception $e) {
            $this->transactionRepository->rollback();
            throw $e;
        }
        output(outputTransactionAmountReceived($message), 'SILENT');

        $this->ensureDescriptionFromP2p($message);
        $payloadTransactionCompleted = $this->transactionPayload->buildCompleted($message);
        output(outputSendTransactionCompletionMessageTxid($message), 'SILENT');
        $this->sendTransactionMessage($message['sender_address'], $payloadTransactionCompleted, 'completion-response-' . $txid);
    }

    private function processP2pTransaction(array $message, string $memo, string $txid): bool
    {
        $isSender = $message['sender_address'] == $this->transportUtility->resolveUserAddressForTransport($message['sender_address']);

        if ($isSender) {
            return $this->processOutgoingP2p($message, $memo, $txid);
        } else {
            return $this->processIncomingP2p($message, $memo, $txid);
        }
    }

    private function processOutgoingP2p(array $message, string $memo, string $txid): bool
    {
        if (!$this->transactionRecoveryRepository->claimPendingTransaction($txid)) {
            Logger::getInstance()->info("P2P transaction already claimed, skipping", ['txid' => $txid, 'memo' => $memo]);
            return false;
        }

        // Proactive hold: if sync is in progress for the receiver contact, hold the
        // transaction instead of sending it (would just get rejected with invalid_previous_txid).
        // For P2P transactions, check remaining P2P lifetime first — every relay node has its
        // own independent expiration timer, so holding a P2P transaction while sync runs will
        // likely result in a zombie (the P2P expires on all other hops in the meantime).
        $receiverPubkey = $message['receiver_public_key'] ?? null;
        if ($receiverPubkey !== null && $this->heldTransactionService !== null
            && $this->heldTransactionService->shouldHoldTransactions($receiverPubkey)) {
            // Check if the P2P has enough remaining lifetime for sync to complete
            $p2pForHold = $this->p2pRepository->getByHash($memo);
            $p2pExpiration = (int) ($p2pForHold['expiration'] ?? 0);
            $currentMicrotime = (int)(microtime(true) * Constants::TIME_MICROSECONDS_TO_INT);

            if ($p2pExpiration > 0 && $currentMicrotime >= $p2pExpiration) {
                // P2P already expired — don't hold, let it fail naturally
                Logger::getInstance()->info("Skipping proactive hold for P2P — already expired", [
                    'txid' => $txid,
                    'memo' => $memo
                ]);
            } else if ($p2pExpiration > 0
                && ($p2pExpiration - $currentMicrotime) < ($this->currentUser->getHeldTxSyncTimeoutSeconds() * Constants::TIME_MICROSECONDS_TO_INT)) {
                // P2P will expire before sync timeout — don't hold, the P2P would expire
                // on every other relay node before we could resume
                Logger::getInstance()->info("Skipping proactive hold for P2P — insufficient remaining lifetime for sync", [
                    'txid' => $txid,
                    'memo' => $memo,
                    'remaining_seconds' => ($p2pExpiration - $currentMicrotime) / Constants::TIME_MICROSECONDS_TO_INT
                ]);
            } else {
                $holdResult = $this->heldTransactionService->holdTransactionForSync($message, $receiverPubkey);
                if ($holdResult['held']) {
                    $this->transactionRepository->updateStatus($txid, Constants::STATUS_PENDING, true);
                    Logger::getInstance()->info("Proactively held P2P transaction during sync", [
                        'txid' => $txid,
                        'memo' => $memo,
                        'receiver' => $message['receiver_address'] ?? 'unknown'
                    ]);
                    return true;
                }
            }
        }

        $rp2p = $this->rp2pRepository->getByHash($memo);
        $message['time'] = $rp2p['time'];

        $p2p = $this->p2pRepository->getByHash($memo);
        $isRelay = !isset($p2p['destination_address']) || $p2p['destination_address'] === null;

        if (!$isRelay && isset($p2p['destination_address'])) {
            $message['end_recipient_address'] = $p2p['destination_address'];
            $message['initial_sender_address'] = $this->transportUtility->resolveUserAddressForTransport($message['sender_address']);
        }

        // Set expires_at = p2p_expiry + DIRECT_TX_DELIVERY_EXPIRATION_SECONDS so the
        // transaction gets a delivery window after the P2P routing request itself expires.
        // This decouples the two lifecycles: CleanupService::expireMessage() only cancels
        // 'pending' transactions on P2P expiry; in-flight ones are cleaned up here instead.
        if (!empty($p2p['expiration']) && (int)$p2p['expiration'] > 0) {
            $p2pExpirySeconds = intval((int)$p2p['expiration'] / Constants::TIME_MICROSECONDS_TO_INT);
            $expiresAt = date('Y-m-d H:i:s', $p2pExpirySeconds + Constants::DIRECT_TX_DELIVERY_EXPIRATION_SECONDS);
            $this->transactionRepository->setExpiresAt($txid, $expiresAt);
        }

        $payload = $this->transactionPayload->buildFromDatabase($message);
        $this->p2pRepository->updateStatus($memo, Constants::STATUS_PAID);
        output(outputSendTransactionOnwards($message), 'SILENT');

        $sendResult = $this->sendTransactionMessage($message['receiver_address'], $payload, $txid, $isRelay);
        $this->transactionRecoveryRepository->markAsSent($txid);
        $response = $sendResult['response'];

        if ($response && $response['status'] === Constants::STATUS_ACCEPTED) {
            $this->handleAcceptedTransaction($txid, $sendResult, $response);
        } elseif ($response && $response['status'] === Constants::STATUS_REJECTED) {
            if (isset($response['reason']) && $response['reason'] === 'invalid_previous_txid') {
                $expectedTxid = $response['expected_txid'] ?? null;
                if ($this->attemptP2pRetryAndSync($message, $txid, $expectedTxid, $isRelay)) {
                    return true;
                }
            }
            $this->p2pRepository->updateStatus($memo, Constants::STATUS_CANCELLED);
            $this->transactionRepository->updateStatus($memo, Constants::STATUS_REJECTED);
        } elseif (!$sendResult['success']) {
            $this->logDeliveryFailure($txid, $sendResult, 'P2P transaction', $memo);
        }

        output(outputTransactionResponse($response), 'SILENT');
        return true;
    }

    private function processIncomingP2p(array $message, string $memo, string $txid): bool
    {
        $isEndRecipient = $this->matchYourselfTransaction(
            $message,
            $this->transportUtility->resolveUserAddressForTransport($message['sender_address'])
        );

        if (!$isEndRecipient) {
            // Relay forward
            $this->transactionRepository->updateStatus($memo, Constants::STATUS_ACCEPTED);
            $this->p2pRepository->updateIncomingTxid($message['memo'], $message['txid']);

            // Update sender_address if the actual transaction sender differs from stored P2P sender
            // This happens in multi-path routing when the chosen route uses a different upstream node
            $p2p = $this->p2pRepository->getByHash($memo);
            if ($p2p && $p2p['sender_address'] !== $message['sender_address']) {
                $this->p2pRepository->updateSenderAddress($memo, $message['sender_address']);
            }

            $rp2p = $this->rp2pRepository->getByHash($message['memo']);
            $data = $this->transactionPayload->buildForwarding($message, $rp2p);
            $payload = $this->transactionPayload->buildFromDatabase($data);

            $insertTransactionResponse = json_decode($this->transactionRepository->insertTransaction($payload, 'relay'), true);
            $this->p2pRepository->updateOutgoingTxid($data['memo'], $data['txid']);
            output(outputTransactionInsertion($insertTransactionResponse));
        } else {
            // End recipient — wrap status + balance updates in a DB transaction
            $this->transactionRepository->beginTransaction();
            try {
                $this->p2pRepository->updateStatus($memo, Constants::STATUS_COMPLETED, true);
                $this->transactionRepository->updateStatus($memo, Constants::STATUS_COMPLETED);
                $this->balanceRepository->updateBalance($message['sender_public_key'], 'received', $message['amount'], $message['currency']);
                $this->p2pRepository->updateIncomingTxid($message['memo'], $message['txid']);
                $this->transactionRepository->commit();
            } catch (\Exception $e) {
                $this->transactionRepository->rollback();
                throw $e;
            }

            // Update sender_address if the actual transaction sender differs from stored P2P sender
            // This happens in multi-path routing when the chosen route uses a different upstream node
            $p2p = $this->p2pRepository->getByHash($memo);
            if ($p2p && $p2p['sender_address'] !== $message['sender_address']) {
                $this->p2pRepository->updateSenderAddress($memo, $message['sender_address']);
            }
            output(outputTransactionAmountReceived($message), 'SILENT');

            $this->ensureDescriptionFromP2p($message, $memo);
            $payloadTransactionCompleted = $this->transactionPayload->buildCompleted($message);
            output(outputSendTransactionCompletionMessageMemo($message), 'SILENT');

            if ($this->messageDeliveryService !== null) {
                $this->messageDeliveryService->markCompletedByHash('p2p', $memo);
            }

            $this->sendTransactionMessage($message['sender_address'], $payloadTransactionCompleted, 'completion-response-' . $txid);
        }

        return true;
    }

    private function handleAcceptedTransaction(string $txid, array $sendResult, array $response): void
    {
        $this->transactionRepository->updateStatus($txid, Constants::STATUS_ACCEPTED, true);

        $signingData = $sendResult['signing_data'] ?? null;
        if ($signingData && isset($signingData['signature']) && isset($signingData['nonce'])) {
            $this->transactionRepository->updateSignatureData(
                $txid, $signingData['signature'], $signingData['nonce'],
                $signingData['signed_message'] ?? null
            );
        }

        if (isset($response['recipientSignature'])) {
            $this->transactionRepository->updateRecipientSignature($txid, $response['recipientSignature']);
        }
    }

    private function handleInvalidPreviousTxidDirect(array $message, array $response, string $txid): bool
    {
        $expectedTxid = $response['expected_txid'] ?? null;

        if ($expectedTxid !== null) {
            output(outputSyncInlineRetryAttempt(), 'SILENT');
            if ($this->updateAndResignTransaction($txid, $expectedTxid, true)) {
                $this->transactionRepository->updateStatus($txid, Constants::STATUS_PENDING, true);
                output(outputSyncInlineRetrySuccess(), 'SILENT');
                $this->triggerSyncIfNeeded($message);
                return true;
            }
            output(outputSyncInlineRetryFailed(), 'SILENT');
        }

        output(outputSyncHoldingForSync(), 'SILENT');
        if ($this->heldTransactionService !== null) {
            $holdResult = $this->heldTransactionService->holdTransactionForSync($message, $message['receiver_public_key'], $expectedTxid);
            if ($holdResult['held']) {
                output(outputSyncHeld(), 'SILENT');
                return true;
            }
        }

        output('Attempting immediate sync...', 'SILENT');
        $syncResult = $this->getSyncTrigger()->syncTransactionChain($message['receiver_address'], $message['receiver_public_key']);
        if ($syncResult['success'] && $syncResult['synced_count'] > 0) {
            output('Sync successful, ' . $syncResult['synced_count'] . ' transactions synced. Syncing balances...', 'SILENT');
            $this->getSyncTrigger()->syncContactBalance($message['receiver_public_key']);

            $correctPrevTxid = $this->transactionRepository->getPreviousTxid($this->currentUser->getPublicKey(), $message['receiver_public_key'], null, $message['currency'] ?? null);
            if ($correctPrevTxid !== null && $this->updateAndResignTransaction($txid, $correctPrevTxid, true)) {
                output('Transaction re-signed after sync. Retrying transaction...', 'SILENT');

                $updatedMessage = $this->fetchUpdatedTransaction($txid);
                if ($updatedMessage) {
                    $syncRetryPayload = $this->transactionPayload->buildStandardFromDatabase($updatedMessage);
                    $syncRetrySendResult = $this->sendTransactionMessage($updatedMessage['receiver_address'], $syncRetryPayload, $txid);
                    $syncRetryResponse = $syncRetrySendResult['response'];

                    if ($syncRetryResponse && $syncRetryResponse['status'] === Constants::STATUS_ACCEPTED) {
                        output('Sync retry: Transaction accepted!', 'SILENT');
                        $this->handleAcceptedTransaction($txid, $syncRetrySendResult, $syncRetryResponse);
                        return true;
                    }
                }
                output('Sync retry failed, resetting to pending for next cycle.', 'SILENT');
                $this->transactionRepository->updateStatus($txid, Constants::STATUS_PENDING, true);
                return true;
            }
        }

        output(outputSyncFallbackP2p(), 'SILENT');
        return false;
    }

    private function attemptP2pRetryAndSync(array $message, string $txid, ?string $expectedTxid, bool $isRelay): bool
    {
        if ($expectedTxid !== null) {
            output(outputSyncP2pInlineRetryAttempt(), 'SILENT');
            if ($this->updateAndResignTransaction($txid, $expectedTxid, false)) {
                output(outputSyncP2pInlineRetrySuccess(), 'SILENT');
                output('Inline retry: Immediately resending transaction...', 'SILENT');

                $retryMessage = $this->fetchUpdatedTransaction($txid);
                if ($retryMessage) {
                    $retryPayload = $this->transactionPayload->buildFromDatabase($retryMessage);
                    $retrySendResult = $this->sendTransactionMessage($retryMessage['receiver_address'], $retryPayload, $txid, $isRelay);
                    $retryResponse = $retrySendResult['response'];

                    if ($retryResponse && $retryResponse['status'] === Constants::STATUS_ACCEPTED) {
                        output('Inline retry: Transaction accepted!', 'SILENT');
                        $this->handleAcceptedTransaction($txid, $retrySendResult, $retryResponse);
                        return true;
                    }
                    output('Inline retry: Still rejected, reason: ' . ($retryResponse['reason'] ?? 'unknown'), 'SILENT');
                }
            }
            output(outputSyncInlineRetryFailed(), 'SILENT');
        }

        output(outputSyncP2pHoldingForSync(), 'SILENT');
        if ($this->heldTransactionService !== null) {
            $holdResult = $this->heldTransactionService->holdTransactionForSync($message, $message['receiver_public_key'], $expectedTxid);
            if ($holdResult['held']) {
                output(outputSyncHeld(), 'SILENT');
                return true;
            }
        }

        output('Attempting immediate sync...', 'SILENT');
        $syncResult = $this->getSyncTrigger()->syncTransactionChain($message['receiver_address'], $message['receiver_public_key']);
        if ($syncResult['success'] && $syncResult['synced_count'] > 0) {
            output('Sync successful, ' . $syncResult['synced_count'] . ' transactions synced.', 'SILENT');
            $this->getSyncTrigger()->syncContactBalance($message['receiver_public_key']);

            $correctPrevTxid = $this->transactionRepository->getPreviousTxid($this->currentUser->getPublicKey(), $message['receiver_public_key'], null, $message['currency'] ?? null);
            if ($correctPrevTxid !== null && $this->updateAndResignTransaction($txid, $correctPrevTxid, false)) {
                output('Transaction re-signed after sync', 'SILENT');
                output('Sync retry: Immediately resending...', 'SILENT');

                $updatedMessage = $this->fetchUpdatedTransaction($txid);
                if ($updatedMessage) {
                    $syncRetryPayload = $this->transactionPayload->buildFromDatabase($updatedMessage);
                    $syncRetrySendResult = $this->sendTransactionMessage($updatedMessage['receiver_address'], $syncRetryPayload, $txid, $isRelay);
                    $syncRetryResponse = $syncRetrySendResult['response'];

                    if ($syncRetryResponse && $syncRetryResponse['status'] === Constants::STATUS_ACCEPTED) {
                        output('Sync retry: Transaction accepted!', 'SILENT');
                        $this->handleAcceptedTransaction($txid, $syncRetrySendResult, $syncRetryResponse);
                        return true;
                    }
                }
            }
        }

        output('Sync retry failed', 'SILENT');
        return false;
    }

    private function updateAndResignTransaction(string $txid, string $expectedTxid, bool $isStandard): bool
    {
        if (!$this->transactionChainRepository->updatePreviousTxid($txid, $expectedTxid)) {
            return false;
        }

        $updatedMessage = $this->fetchUpdatedTransaction($txid);
        if (!$updatedMessage) {
            return false;
        }

        $newPayload = $isStandard
            ? $this->transactionPayload->buildStandardFromDatabase($updatedMessage)
            : $this->transactionPayload->buildFromDatabase($updatedMessage);

        $signResult = $this->transportUtility->signWithCapture($newPayload);
        if (!$signResult || !isset($signResult['signature']) || !isset($signResult['nonce'])) {
            return false;
        }

        $this->transactionRepository->updateSignatureData($txid, $signResult['signature'], $signResult['nonce']);
        return true;
    }

    private function fetchUpdatedTransaction(string $txid): ?array
    {
        $data = $this->transactionRepository->getByTxid($txid);
        return is_array($data) && isset($data[0]) ? $data[0] : $data;
    }

    private function triggerSyncIfNeeded(array $message): void
    {
        $syncResult = $this->getSyncTrigger()->syncTransactionChain($message['receiver_address'], $message['receiver_public_key']);
        if ($syncResult['success'] && $syncResult['synced_count'] > 0) {
            output(outputSyncTransactionsSynced($syncResult['synced_count']), 'SILENT');
        }
    }

    private function ensureDescriptionFromP2p(array &$message, ?string $memo = null): void
    {
        if (!isset($message['description']) || $message['description'] === null) {
            $hash = $memo ?? ($message['memo'] ?? null);
            if ($hash) {
                $p2p = $this->p2pRepository->getByHash($hash);
                if ($p2p && isset($p2p['description'])) {
                    $message['description'] = $p2p['description'];
                }
            }
        }
    }

    private function logDeliveryFailure(string $txid, array $sendResult, string $type, ?string $memo = null): void
    {
        $trackingResult = $sendResult['tracking'] ?? [];
        $context = [
            'txid' => $txid,
            'attempts' => $trackingResult['attempts'] ?? 'unknown',
            'error' => $trackingResult['error'] ?? 'Unknown error',
            'moved_to_dlq' => $trackingResult['dlq'] ?? false
        ];
        if ($memo !== null) {
            $context['memo'] = $memo;
        }
        $this->secureLogger->warning("$type delivery failed", $context);
    }

    private function sendTransactionMessage(string $address, array $payload, string $txid, bool $isRelay = false): array
    {
        $hasPrefix = strpos($txid, '-') !== false;
        $prefix = $hasPrefix ? '' : ($isRelay ? 'relay-' : 'send-');
        $messageId = $prefix . $txid . '-' . $this->timeUtility->getCurrentMicrotime();

        if ($this->messageDeliveryService !== null) {
            return $this->messageDeliveryService->sendMessage('transaction', $address, $payload, $messageId, false);
        }

        $rawResponse = $this->transportUtility->send($address, $payload);
        $response = json_decode($rawResponse, true);

        return [
            'success' => $response !== null && in_array($response['status'] ?? null, Constants::DELIVERY_SUCCESS_STATUSES, true),
            'response' => $response,
            'raw' => $rawResponse,
            'messageId' => $messageId
        ];
    }

    private function matchYourselfTransaction(array $request, string $address): bool
    {
        $p2pRequest = $this->p2pRepository->getByHash($request['memo']);

        if (hash(Constants::HASH_ALGORITHM, $address . $p2pRequest['salt'] . $p2pRequest['time']) === $request['memo']) {
            return true;
        }

        $allAddresses = $this->currentUser->getUserLocaters();
        foreach ($allAddresses as $userAddress) {
            if ($userAddress === $address) {
                continue;
            }
            if (hash(Constants::HASH_ALGORITHM, $userAddress . $p2pRequest['salt'] . $p2pRequest['time']) === $request['memo']) {
                return true;
            }
        }

        return false;
    }
}
