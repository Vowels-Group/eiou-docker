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
use Eiou\Database\TransactionRepository;
use Eiou\Database\AddressRepository;
use Eiou\Database\P2pRepository;
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
    private static array $contactSendLocks = [];

    public function __construct(
        TransactionRepository $transactionRepository, AddressRepository $addressRepository,
        P2pRepository $p2pRepository, TransactionPayload $transactionPayload,
        TransportUtilityService $transportUtility, TimeUtilityService $timeUtility,
        InputValidator $inputValidator, UserContext $currentUser, Logger $secureLogger,
        ?MessageDeliveryService $messageDeliveryService = null, ?LockingServiceInterface $lockingService = null
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
    }

    // Dependency injection setters
    public function setContactService(ContactServiceInterface $contactService): void { $this->contactService = $contactService; }
    public function setP2pService(P2pServiceInterface $p2pService): void { $this->p2pService = $p2pService; }
    /**
     * Set the sync trigger (accepts interface for loose coupling)
     *
     * @param SyncTriggerInterface $sync Sync trigger (can be proxy or actual service)
     */
    public function setSyncTrigger(SyncTriggerInterface $sync): void { $this->syncTrigger = $sync; }
    public function setLockingService(LockingServiceInterface $lockingService): void { $this->lockingService = $lockingService; }
    public function setTransactionChainRepository(TransactionChainRepository $repo): void { $this->transactionChainRepository = $repo; }
    public function setTransactionService(TransactionService $transactionService): void { $this->transactionService = $transactionService; }

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
    public function acquireContactSendLock(string $contactPubkeyHash, int $timeout = 30): bool
    {
        if ($this->lockingService !== null) {
            return $this->lockingService->acquireLock('contact_send_' . $contactPubkeyHash, $timeout);
        }

        $lockFile = sys_get_temp_dir() . '/eiou_send_lock_' . $contactPubkeyHash . '.lock';
        $lockHandle = @fopen($lockFile, 'c');

        if (!$lockHandle && file_exists($lockFile)) {
            @unlink($lockFile);
            $lockHandle = @fopen($lockFile, 'c');
        }

        if (!$lockHandle) {
            $this->secureLogger->warning("Failed to create lock file", [
                'contact_hash' => substr($contactPubkeyHash, 0, 16),
                'lock_file' => $lockFile
            ]);
            return false;
        }

        @chmod($lockFile, 0666);

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
    private function verifySenderChainAndSync(string $contactAddress, string $contactPublicKey): array {
        $result = ['success' => true, 'synced' => false, 'error' => null];
        if ($this->transactionChainRepository === null) return $result;

        $chainStatus = $this->transactionChainRepository->verifyChainIntegrity($this->currentUser->getPublicKey(), $contactPublicKey);
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

        if (!$syncResult['success']) {
            $recheckStatus = $this->transactionChainRepository->verifyChainIntegrity($this->currentUser->getPublicKey(), $contactPublicKey);
            if (!$recheckStatus['valid']) {
                return ['success' => false, 'synced' => true, 'error' => 'Failed to repair chain: ' . ($syncResult['error'] ?? 'unknown')];
            }
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

        $contactInfo = $this->getContactService()->lookupContactInfo($request[2]);
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
                $chainVerification = $this->verifySenderChainAndSync($contactAddress, $contactInfo['receiverPublicKey']);
                if (!$chainVerification['success']) {
                    $output->error("Cannot send transaction: " . ($chainVerification['error'] ?? 'Chain verification failed'),
                        ErrorCodes::CHAIN_INTEGRITY_FAILED, 500, ['recipient' => $request[2] ?? null, 'synced' => $chainVerification['synced']]);
                    return;
                }
                if ($chainVerification['synced']) output(outputSyncChainRepairedBeforeSend(), 'SILENT');
            }
            $data = $this->prepareStandardTransactionData($request, $contactInfo);
        } catch (\InvalidArgumentException $e) {
            $output->error("Cannot send transaction: " . $e->getMessage(), ErrorCodes::NO_VIABLE_TRANSPORT, 400, ['recipient' => $request[2] ?? null]);
            return;
        } finally {
            $this->releaseContactSendLock($contactPubkeyHash);
        }

        $payload = $this->transactionPayload->build($data);
        $this->transactionRepository->insertTransaction($payload, Constants::TX_TYPE_SENT);
        $this->transactionRepository->updateTrackingFields($data['txid'], $data['end_recipient_address'] ?? null, $data['initial_sender_address'] ?? null);

        $output->success("Transaction sent successfully to " . $data['receiverAddress'], [
            'status' => Constants::STATUS_SENT, 'type' => 'direct',
            'recipient' => $contactInfo['receiverName'] ?? $request[2], 'recipient_address' => $data['receiverAddress'] ?? null,
            'amount' => ($data['amount'] ?? 0) / Constants::TRANSACTION_USD_CONVERSION_FACTOR,
            'currency' => $data['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY,
            'description' => $data['description'] ?? null, 'txid' => $data['txid'] ?? null, 'timestamp' => $data['time'] ?? null
        ], "Direct transaction initiated");
    }

    /** Handle P2P transaction route */
    public function handleP2pRoute(array $request, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();
        $txData = ['recipient' => $request[2] ?? null, 'amount' => $request[3] ?? null, 'currency' => $request[4] ?? 'USD', 'description' => $request[5] ?? null];

        try {
            $this->getP2pService()->sendP2pRequest($request);
            $output->success("Searching for route via P2P network to " . $request[2],
                array_merge($txData, ['status' => Constants::STATUS_PENDING, 'type' => 'p2p', 'message' => 'P2P route discovery initiated']),
                "Searching for route to recipient via P2P network");
        } catch (\InvalidArgumentException $e) {
            $output->error("Recipient not found: " . ($request[2] ?? 'unknown') . " is not a valid address", ErrorCodes::INVALID_RECIPIENT, 400, $txData);
        }
    }

    /**
     * Send a P2P EIOU transaction after route discovery.
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
        $p2p = $this->p2pRepository->getByHash($request['hash']);
        $data = $this->prepareP2pTransactionData($request, $p2p['description'] ?? null);

        $payload = $this->transactionPayload->build($data);
        $this->transactionRepository->insertTransaction($payload, Constants::TX_TYPE_SENT);
        $this->p2pRepository->updateOutgoingTxid($data['memo'], $data['txid']);
        $this->transactionRepository->updateTrackingFields($data['txid'], $data['end_recipient_address'] ?? null, $data['initial_sender_address'] ?? null);
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
        return ['success' => $response !== null && isset($response['status']), 'response' => $response, 'raw' => $rawResponse, 'messageId' => $messageId];
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
