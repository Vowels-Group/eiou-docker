<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\InputValidator;
use Eiou\Utils\Logger;
use Eiou\Cli\CliOutputManager;
use Eiou\Core\ErrorCodes;
use Eiou\Core\Constants;
use Eiou\Core\UserContext;
use Eiou\Contracts\SendOperationServiceInterface;
use Eiou\Contracts\LockingServiceInterface;
use Eiou\Contracts\ContactServiceInterface;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Contracts\P2pTransactionSenderInterface;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Contracts\ChainDropServiceInterface;
use Eiou\Database\TransactionRepository;
use Eiou\Database\AddressRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\RepositoryFactory;
use Eiou\Database\TransactionChainRepository;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Schemas\Payloads\TransactionPayload;
use RuntimeException;

/**
 * Send Operation Service - High-level send orchestration for eIOU transactions.
 * Part of TransactionService refactoring.
 */
class SendOperationService implements SendOperationServiceInterface, P2pTransactionSenderInterface
{
    private TransactionRepository $transactionRepository;
    private AddressRepository $addressRepository;
    private P2pRepository $p2pRepository;
    private TransactionPayload $transactionPayload;
    private TransportUtilityService $transportUtility;
    private TimeUtilityService $timeUtility;
    private InputValidator $inputValidator;
    private UserContext $currentUser;
    private Logger $secureLogger;
    private ?MessageDeliveryService $messageDeliveryService = null;
    private ?LockingServiceInterface $lockingService = null;
    private ?ContactServiceInterface $contactService = null;
    private ?P2pServiceInterface $p2pService = null;
    /**
     * @var SyncTriggerInterface|null Sync trigger for pre-send sync
     */
    private ?SyncTriggerInterface $syncTrigger = null;
    private ?TransactionChainRepository $transactionChainRepository = null;
    private ?TransactionService $transactionService = null;
    private ?ChainDropServiceInterface $chainDropService = null;
    private static array $contactSendLocks = [];

    public function __construct(
        TransactionRepository $transactionRepository, AddressRepository $addressRepository,
        P2pRepository $p2pRepository, TransactionPayload $transactionPayload,
        TransportUtilityService $transportUtility, TimeUtilityService $timeUtility,
        InputValidator $inputValidator, UserContext $currentUser, Logger $secureLogger,
        ?MessageDeliveryService $messageDeliveryService = null, ?LockingServiceInterface $lockingService = null,
        ?RepositoryFactory $repositoryFactory = null, ?SyncTriggerInterface $syncTrigger = null
    ) {
        $this->transactionRepository = $transactionRepository;
        $this->addressRepository = $addressRepository;
        $this->p2pRepository = $p2pRepository;
        $this->transactionPayload = $transactionPayload;
        $this->transportUtility = $transportUtility;
        $this->timeUtility = $timeUtility;
        $this->inputValidator = $inputValidator;
        $this->currentUser = $currentUser;
        $this->secureLogger = $secureLogger;
        $this->messageDeliveryService = $messageDeliveryService;
        $this->lockingService = $lockingService;
        if ($repositoryFactory !== null) {
            $this->transactionChainRepository = $repositoryFactory->get(\Eiou\Database\TransactionChainRepository::class);
        }
        if ($syncTrigger !== null) {
            $this->syncTrigger = $syncTrigger;
        }
    }

    // Dependency injection setters
    public function setContactService(ContactServiceInterface $contactService): void { $this->contactService = $contactService; }
    public function setP2pService(P2pServiceInterface $p2pService): void { $this->p2pService = $p2pService; }
    public function setLockingService(LockingServiceInterface $lockingService): void { $this->lockingService = $lockingService; }
    public function setTransactionService(TransactionService $transactionService): void { $this->transactionService = $transactionService; }
    public function setChainDropService(ChainDropServiceInterface $chainDropService): void { $this->chainDropService = $chainDropService; }

    private function getContactService(): ContactServiceInterface {
        if ($this->contactService === null) throw new RuntimeException('ContactService not injected.');
        return $this->contactService;
    }

    private function getP2pService(): P2pServiceInterface {
        if ($this->p2pService === null) throw new RuntimeException('P2pService not injected.');
        return $this->p2pService;
    }

    private function getSyncTrigger(): SyncTriggerInterface {
        if ($this->syncTrigger === null) {
            throw new RuntimeException('SyncTrigger not injected. Call setSyncTrigger() or ensure ServiceContainer properly injects the dependency.');
        }
        return $this->syncTrigger;
    }

    /** Acquire a lock for sending to a specific contact */
    public function acquireContactSendLock(string $contactPubkeyHash, int $timeout = Constants::DB_LOCK_TIMEOUT_SECONDS): bool
    {
        if ($this->lockingService !== null) {
            return $this->lockingService->acquireLock('contact_send_' . $contactPubkeyHash, $timeout);
        }

        $lockFile = sys_get_temp_dir() . '/eiou_send_lock_' . $contactPubkeyHash . '.lock';
        $lockHandle = fopen($lockFile, 'c');

        if (!$lockHandle && file_exists($lockFile)) {
            unlink($lockFile);
            $lockHandle = fopen($lockFile, 'c');
        }

        if (!$lockHandle) {
            $this->secureLogger->warning("Failed to create lock file", [
                'contact_hash' => substr($contactPubkeyHash, 0, 16),
                'lock_file' => $lockFile
            ]);
            return false;
        }

        chmod($lockFile, 0600);

        $startTime = time();
        while (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (time() - $startTime >= $timeout) {
                fclose($lockHandle);
                $this->secureLogger->warning("Timeout acquiring send lock", [
                    'contact_hash' => substr($contactPubkeyHash, 0, 16),
                    'timeout' => $timeout
                ]);
                return false;
            }
            usleep(100000);
        }

        self::$contactSendLocks[$contactPubkeyHash] = $lockHandle;
        return true;
    }

    /** Release a contact send lock */
    public function releaseContactSendLock(string $contactPubkeyHash): void
    {
        if ($this->lockingService !== null) {
            $this->lockingService->releaseLock('contact_send_' . $contactPubkeyHash);
            return;
        }

        if (isset(self::$contactSendLocks[$contactPubkeyHash])) {
            $lockHandle = self::$contactSendLocks[$contactPubkeyHash];
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            unset(self::$contactSendLocks[$contactPubkeyHash]);
        }
    }

    /** Verify sender's local chain integrity and sync if needed */
    private function verifySenderChainAndSync(string $contactAddress, string $contactPublicKey, ?string $currency = null): array {
        $result = ['success' => true, 'synced' => false, 'error' => null];
        if ($this->transactionChainRepository === null) return $result;

        $chainStatus = $this->transactionChainRepository->verifyChainIntegrity($this->currentUser->getPublicKey(), $contactPublicKey, $currency);
        if ($chainStatus['valid']) return $result;

        output(outputSyncChainIntegrityFailed(count($chainStatus['gaps'])), 'SILENT');
        $this->secureLogger->info("Sender-side chain verification detected gaps, triggering sync", [
            'contact_address' => $contactAddress, 'gap_count' => count($chainStatus['gaps']),
            'transaction_count' => $chainStatus['transaction_count']
        ]);

        if ($this->syncTrigger === null) {
            try { $this->getSyncTrigger(); }
            catch (\Exception $e) { return ['success' => false, 'synced' => false, 'error' => 'Sync service not available']; }
        }

        $syncResult = $this->syncTrigger->syncTransactionChain($contactAddress, $contactPublicKey);
        $result['synced'] = true;

        // ALWAYS re-verify chain integrity after sync, regardless of sync success.
        // Sync can return success=true with 0 transactions synced when both sides
        // are missing the same transactions (mutual gap). In that case the gap persists.
        $recheckStatus = $this->transactionChainRepository->verifyChainIntegrity($this->currentUser->getPublicKey(), $contactPublicKey, $currency);
        if (!$recheckStatus['valid']) {
            $errorMsg = !$syncResult['success']
                ? 'Failed to repair chain: ' . ($syncResult['error'] ?? 'unknown')
                : 'Chain gap remains after sync (both sides missing same transactions)';
            return ['success' => false, 'synced' => true, 'error' => $errorMsg];
        }

        output(outputSyncChainRepaired(), 'SILENT');
        return $result;
    }

    /** Send eIOU transaction */
    public function sendEiou(array $request, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();
        $txData = ['recipient' => $request[2] ?? null, 'amount' => $request[3] ?? null, 'currency' => $request[4] ?? 'USD', 'description' => $request[5] ?? null];

        if (isset($request)) {
            $amountValidation = $this->inputValidator->validateArgvAmount($request, 4);
            if (!$amountValidation['valid']) {
                $this->secureLogger->warning("Invalid parameter amount", ['value' => $request, 'error' => $amountValidation['error']]);
                $output->error("Invalid parameter amount: " . $amountValidation['error'], ErrorCodes::INVALID_PARAMS, 400);
                return;
            }
        }

        if (isset($request[2])) {
            $addressValidation = $this->inputValidator->validateAddress($request[2]);
            $nameValidation = $this->inputValidator->validateContactName($request[2]);
            if (!$addressValidation['valid'] && !$nameValidation['valid']) {
                $this->secureLogger->warning("Invalid Address/name", ['value' => $request[2], 'error' => $addressValidation['error']]);
                $output->error("Invalid Address/name: " . $addressValidation['error'], ErrorCodes::INVALID_RECIPIENT, 400);
                return;
            }
            if ($addressValidation['valid']) {
                $selfSendValidation = $this->inputValidator->validateNotSelfSend($request[2], $this->currentUser);
                if (!$selfSendValidation['valid']) {
                    $this->secureLogger->warning("Self-send transaction attempted", ['recipient' => $request[2]]);
                    $output->error("Cannot send to yourself: " . $selfSendValidation['error'], ErrorCodes::SELF_SEND, 400);
                    return;
                }
            }
        }

        if (isset($request[3])) {
            $amountValidation = $this->inputValidator->validateAmount($request[3], $request[4] ?? 'USD');
            if (!$amountValidation['valid']) {
                $this->secureLogger->warning("Invalid transaction amount", ['amount' => $request[3], 'error' => $amountValidation['error']]);
                $output->error("Invalid amount: " . $amountValidation['error'], ErrorCodes::INVALID_AMOUNT, 400);
                return;
            }
            $request[3] = $amountValidation['value'];
            $txData['amount'] = $request[3];
        }

        if (isset($request[4])) {
            $currencyValidation = $this->inputValidator->validateCurrency($request[4]);
            if (!$currencyValidation['valid']) {
                $this->secureLogger->warning("Invalid currency code", ['currency' => $request[4]]);
                $output->error("Invalid currency: " . $currencyValidation['error'], ErrorCodes::INVALID_CURRENCY, 400);
                return;
            }
            $request[4] = $currencyValidation['value'];
            $txData['currency'] = $request[4];
        }

        if (!$this->addressRepository->getAllAddresses()) {
            $output->error("No contacts available for transaction", 'NO_CONTACTS', 400, $txData);
            return;
        }

        $contactInfo = $this->getContactService()->lookupContactInfoWithDisambiguation($request[2], $output);
        if (!$contactInfo) { $this->handleP2pRoute($request, $output); return; }

        if ($contactInfo['status'] === Constants::CONTACT_STATUS_ACCEPTED) {
            $this->handleDirectRoute($request, $contactInfo, $output);
        } elseif ($contactInfo['status'] === Constants::CONTACT_STATUS_PENDING) {
            $transportIndex = $this->transportUtility->fallbackTransportType($request[2], $contactInfo);
            if ($transportIndex === null) { $this->handleP2pRoute($request, $output); }
            else {
                $syncResult = $this->getSyncTrigger()->syncSingleContact($contactInfo[$transportIndex], 'SILENT');
                $syncResult ? $this->handleDirectRoute($request, $contactInfo, $output) : $this->handleP2pRoute($request, $output);
            }
        } elseif ($contactInfo['status'] === Constants::CONTACT_STATUS_BLOCKED) {
            $output->error("Cannot send to blocked contact", 'CONTACT_BLOCKED', 403, $txData);
        }
    }

    /** Handle direct transaction route */
    public function handleDirectRoute(array $request, array $contactInfo, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contactInfo['receiverPublicKey']);

        if (!$this->acquireContactSendLock($contactPubkeyHash)) {
            $output->error("Cannot send transaction: Another transaction to this contact is in progress",
                ErrorCodes::TRANSACTION_IN_PROGRESS, 429, ['recipient' => $request[2] ?? null]);
            return;
        }

        try {
            $transportIndex = $this->transportUtility->fallbackTransportType($request[2] ?? '', $contactInfo);
            $contactAddress = $transportIndex !== null ? ($contactInfo[$transportIndex] ?? null) : null;

            if ($contactAddress !== null && isset($contactInfo['receiverPublicKey'])) {
                $txCurrency = $request[4] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
                $chainVerification = $this->verifySenderChainAndSync($contactAddress, $contactInfo['receiverPublicKey'], $txCurrency);
                if (!$chainVerification['success']) {
                    // Auto-propose chain drop if sync couldn't repair the gap
                    if ($chainVerification['synced'] && $this->chainDropService !== null && $this->currentUser->getAutoChainDropPropose()) {
                        $proposalResult = $this->chainDropService->proposeChainDrop($contactPubkeyHash);
                        if ($proposalResult['success']) {
                            $output->error("Cannot send: chain gap detected. A chain drop proposal has been sent to your contact.",
                                ErrorCodes::CHAIN_INTEGRITY_FAILED, 500, [
                                    'recipient' => $request[2] ?? null,
                                    'chain_drop_proposed' => true,
                                    'proposal_id' => $proposalResult['proposal_id'],
                                    'missing_txid' => $proposalResult['missing_txid'],
                                    'broken_txid' => $proposalResult['broken_txid']
                                ]);
                            return;
                        } elseif (isset($proposalResult['proposal_id'])) {
                            // Active proposal already exists for this gap
                            $output->error("Cannot send: chain gap detected. A chain drop proposal is already pending.",
                                ErrorCodes::CHAIN_INTEGRITY_FAILED, 500, [
                                    'recipient' => $request[2] ?? null,
                                    'chain_drop_pending' => true,
                                    'proposal_id' => $proposalResult['proposal_id']
                                ]);
                            return;
                        }
                    }
                    // Fallback: no chain drop service or proposal failed
                    $output->error("Cannot send transaction: " . ($chainVerification['error'] ?? 'Chain verification failed'),
                        ErrorCodes::CHAIN_INTEGRITY_FAILED, 500, ['recipient' => $request[2] ?? null, 'synced' => $chainVerification['synced']]);
                    return;
                }
                if ($chainVerification['synced']) output(outputSyncChainRepairedBeforeSend(), 'SILENT');
            }
            $data = $this->prepareStandardTransactionData($request, $contactInfo);

            $payload = $this->transactionPayload->build($data);
            $this->transactionRepository->insertTransaction($payload, Constants::TX_TYPE_SENT);
            $this->transactionRepository->updateTrackingFields($data['txid'], $data['end_recipient_address'] ?? null, $data['initial_sender_address'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $output->error("Cannot send transaction: " . $e->getMessage(), ErrorCodes::NO_VIABLE_TRANSPORT, 400, ['recipient' => $request[2] ?? null]);
            return;
        } finally {
            $this->releaseContactSendLock($contactPubkeyHash);
        }

        $output->success("Transaction sent successfully to " . $data['receiverAddress'], [
            'status' => Constants::STATUS_SENT, 'type' => 'direct',
            'recipient' => $contactInfo['receiverName'] ?? $request[2], 'recipient_address' => $data['receiverAddress'] ?? null,
            'amount' => $data['amount']->toMajorUnits(),
            'currency' => $data['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY,
            'description' => $data['description'] ?? null, 'txid' => $data['txid'] ?? null, 'timestamp' => $data['time'] ?? null
        ], "Direct transaction initiated");
    }

    /** Handle P2P transaction route */
    public function handleP2pRoute(array $request, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Default is fast mode (first response wins). --best flag enables
        // best-fee mode (collect all responses, pick cheapest route).
        $request['fast'] = !in_array('--best', $request, true);

        // Remove flags from positional arguments so they aren't treated as description/currency
        foreach ($request as $key => $value) {
            if (is_int($key) && is_string($value) && strncmp($value, '--', 2) === 0) {
                unset($request[$key]);
            }
        }

        $txData = ['recipient' => $request[2] ?? null, 'amount' => $request[3] ?? null, 'currency' => $request[4] ?? 'USD', 'description' => $request[5] ?? null];

        try {
            $this->getP2pService()->sendP2pRequest($request);
            $output->success("Searching for route via P2P network to " . $request[2],
                array_merge($txData, ['status' => Constants::STATUS_PENDING, 'type' => 'p2p', 'message' => 'P2P route discovery initiated']),
                "Searching for route to recipient via P2P network");
        } catch (\InvalidArgumentException $e) {
            $output->error("Recipient not found: " . ($request[2] ?? 'unknown') . " is not a valid address or known contact", ErrorCodes::INVALID_RECIPIENT, 400, $txData);
        }
    }

    /**
     * Send a P2P eIOU transaction after route discovery.
     *
     * Implements P2pTransactionSenderInterface::sendP2pEiou().
     * This allows Rp2pService to depend on this interface instead of
     * the full TransactionService, breaking circular dependencies.
     *
     * @param array $request The P2P request data containing transaction details
     * @return void
     */
    public function sendP2pEiou(array $request): void {
        output(outputP2pEiouSend($request), 'SILENT');

        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $request['senderPublicKey'] ?? '');

        if (!$this->acquireContactSendLock($contactPubkeyHash)) {
            $this->secureLogger->warning("P2P send lock contention, skipping", ['hash' => $request['hash'] ?? 'unknown']);
            return;
        }

        try {
            $p2p = $this->p2pRepository->getByHash($request['hash']);
            $data = $this->prepareP2pTransactionData($request, $p2p['description'] ?? null);

            $payload = $this->transactionPayload->build($data);
            $this->transactionRepository->insertTransaction($payload, Constants::TX_TYPE_SENT);
            $this->p2pRepository->updateOutgoingTxid($data['memo'], $data['txid']);
            $this->transactionRepository->updateTrackingFields($data['txid'], $data['end_recipient_address'] ?? null, $data['initial_sender_address'] ?? null);
        } finally {
            $this->releaseContactSendLock($contactPubkeyHash);
        }
    }

    /** Send a transaction message with optional delivery tracking */
    public function sendTransactionMessage(string $address, array $payload, string $txid, bool $isRelay = false): array {
        $hasPrefix = strpos($txid, '-') !== false;
        $prefix = $hasPrefix ? '' : ($isRelay ? 'relay-' : 'send-');
        $messageId = $prefix . $txid . '-' . $this->timeUtility->getCurrentMicrotime();

        if ($this->messageDeliveryService !== null) {
            return $this->messageDeliveryService->sendMessage('transaction', $address, $payload, $messageId, false);
        }

        $rawResponse = $this->transportUtility->send($address, $payload);
        $response = json_decode($rawResponse, true);
        return ['success' => $response !== null && in_array($response['status'] ?? null, Constants::DELIVERY_SUCCESS_STATUSES, true), 'response' => $response, 'raw' => $rawResponse, 'messageId' => $messageId];
    }

    private function prepareStandardTransactionData(array $request, array $contactInfo): array {
        if ($this->transactionService === null) throw new RuntimeException('TransactionService not injected.');
        return $this->transactionService->prepareStandardTransactionData($request, $contactInfo);
    }

    private function prepareP2pTransactionData(array $request, ?string $description = null): array {
        if ($this->transactionService === null) throw new RuntimeException('TransactionService not injected.');
        return $this->transactionService->prepareP2pTransactionData($request, $description);
    }
}
