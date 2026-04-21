<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\InputValidator;
use Eiou\Utils\Logger;
use Eiou\Cli\CliOutputManager;
use Eiou\Core\ErrorCodes;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
use Eiou\Contracts\TransactionServiceInterface;
use Eiou\Contracts\LockingServiceInterface;
use Eiou\Contracts\BalanceServiceInterface;
use Eiou\Contracts\ChainVerificationServiceInterface;
use Eiou\Contracts\TransactionValidationServiceInterface;
use Eiou\Contracts\TransactionProcessingServiceInterface;
use Eiou\Contracts\SendOperationServiceInterface;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Database\ContactRepository;
use Eiou\Database\AddressRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Database\TransactionRecoveryRepository;
use Eiou\Database\TransactionContactRepository;
use Eiou\Database\TransactionStatisticsRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Schemas\Payloads\TransactionPayload;
use RuntimeException;
use InvalidArgumentException;

/**
 * Transaction Service (Facade)
 *
 * Coordinates transaction operations by delegating to specialized services:
 * - BalanceService: Balance operations
 * - ChainVerificationService: Chain integrity
 * - TransactionValidationService: Validation logic
 * - TransactionProcessingService: Core processing
 * - SendOperationService: Send orchestration
 *
 * Maintains backward compatibility for all existing callers.
 */
class TransactionService implements TransactionServiceInterface {

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    private ContactRepository $contactRepository;
    private AddressRepository $addressRepository;
    private BalanceRepository $balanceRepository;
    private P2pRepository $p2pRepository;
    private Rp2pRepository $rp2pRepository;
    private TransactionRepository $transactionRepository;
    private UtilityServiceContainer $utilityContainer;
    private CurrencyUtilityService $currencyUtility;
    private TransportUtilityService $transportUtility;
    private TimeUtilityService $timeUtility;
    private Logger $secureLogger;
    private UserContext $currentUser;
    private TransactionPayload $transactionPayload;
    private TransactionChainRepository $transactionChainRepository;
    private TransactionRecoveryRepository $transactionRecoveryRepository;
    private TransactionContactRepository $transactionContactRepository;
    private TransactionStatisticsRepository $transactionStatisticsRepository;

    // Extracted services (facade delegates to these)
    private ?BalanceServiceInterface $balanceService = null;
    private ?ChainVerificationServiceInterface $chainVerificationService = null;
    private ?TransactionValidationServiceInterface $transactionValidationService = null;
    private ?TransactionProcessingServiceInterface $transactionProcessingService = null;
    private ?SendOperationServiceInterface $sendOperationService = null;

    // Circular dependency services
    /**
     * @var SyncTriggerInterface|null Sync trigger for chain repair (fallback when ChainVerificationService not available)
     */
    private ?SyncTriggerInterface $syncTrigger = null;
    private ?P2pService $p2pService = null;
    private ?ContactService $contactService = null;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct(
        ContactRepository $contactRepository,
        AddressRepository $addressRepository,
        BalanceRepository $balanceRepository,
        P2pRepository $p2pRepository,
        Rp2pRepository $rp2pRepository,
        TransactionRepository $transactionRepository,
        TransactionChainRepository $transactionChainRepository,
        TransactionRecoveryRepository $transactionRecoveryRepository,
        TransactionContactRepository $transactionContactRepository,
        TransactionStatisticsRepository $transactionStatisticsRepository,
        UtilityServiceContainer $utilityContainer,
        InputValidator $inputValidator,
        Logger $secureLogger,
        UserContext $currentUser,
        ?MessageDeliveryService $messageDeliveryService = null,
        ?HeldTransactionService $heldTransactionService = null,
        ?SyncTriggerInterface $syncTrigger = null
    ) {
        $this->contactRepository = $contactRepository;
        $this->addressRepository = $addressRepository;
        $this->balanceRepository = $balanceRepository;
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->transactionChainRepository = $transactionChainRepository;
        $this->transactionRecoveryRepository = $transactionRecoveryRepository;
        $this->transactionContactRepository = $transactionContactRepository;
        $this->transactionStatisticsRepository = $transactionStatisticsRepository;
        $this->utilityContainer = $utilityContainer;
        $this->currencyUtility = $this->utilityContainer->getCurrencyUtility();
        $this->transportUtility = $this->utilityContainer->getTransportUtility();
        $this->timeUtility = $this->utilityContainer->getTimeUtility();
        $this->secureLogger = $secureLogger;
        $this->currentUser = $currentUser;

        $this->transactionPayload = new TransactionPayload($this->currentUser, $this->utilityContainer);
        if ($syncTrigger !== null) {
            $this->syncTrigger = $syncTrigger;
        }
    }

    // =========================================================================
    // SERVICE SETTERS
    // =========================================================================

    public function setBalanceService(BalanceServiceInterface $service): void {
        $this->balanceService = $service;
    }

    public function setChainVerificationService(ChainVerificationServiceInterface $service): void {
        $this->chainVerificationService = $service;
    }

    public function setTransactionValidationService(TransactionValidationServiceInterface $service): void {
        $this->transactionValidationService = $service;
    }

    public function setTransactionProcessingService(TransactionProcessingServiceInterface $service): void {
        $this->transactionProcessingService = $service;
    }

    public function setSendOperationService(SendOperationServiceInterface $service): void {
        $this->sendOperationService = $service;
    }

    public function setP2pService(P2pService $service): void {
        $this->p2pService = $service;
    }

    public function setContactService(ContactService $service): void {
        $this->contactService = $service;
    }

    public function setLockingService(LockingServiceInterface $service): void {
        if ($this->sendOperationService !== null) {
            $this->sendOperationService->setLockingService($service);
        }
    }

    public function setMessageDeliveryService(MessageDeliveryService $service): void {
        // Kept for backward compatibility
    }

    public function setHeldTransactionService(HeldTransactionService $service): void {
        // Kept for backward compatibility
    }

    private function getSyncTrigger(): SyncTriggerInterface {
        if ($this->syncTrigger === null) {
            throw new RuntimeException('SyncTrigger not injected. Call setSyncTrigger() or ensure ServiceContainer properly injects the dependency.');
        }
        return $this->syncTrigger;
    }

    private function getP2pService(): P2pService {
        if ($this->p2pService === null) {
            throw new RuntimeException('P2pService not injected.');
        }
        return $this->p2pService;
    }

    private function getContactService(): ContactService {
        if ($this->contactService === null) {
            throw new RuntimeException('ContactService not injected.');
        }
        return $this->contactService;
    }

    // =========================================================================
    // ID & HASH GENERATION (kept in facade)
    // =========================================================================

    public function createUniqueTxid(array $data): string {
        if (!isset($data['receiverPublicKey'], $data['amount'], $data['time'])) {
            throw new InvalidArgumentException("Missing required fields for txid creation");
        }
        $currency = $data['currency'] ?? '';
        $amount = $data['amount'];
        $amountStr = (string) (($amount instanceof SplitAmount) ? $amount : SplitAmount::from($amount));
        return hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey() . $data['receiverPublicKey'] . $amountStr . $currency . $data['time']);
    }

    public function createUniqueDatabaseTxid(array $data, array $rp2p): string {
        $currency = $data['currency'] ?? $rp2p['currency'] ?? '';
        $amount = $data['amount'];
        $amountStr = (string) (($amount instanceof SplitAmount) ? $amount : SplitAmount::from($amount));
        return hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey() . $rp2p['sender_public_key'] . $amountStr . $currency . $rp2p['time']);
    }

    public function createContactHash(string $receiverAddress, string $salt, string $time): string {
        return hash(Constants::HASH_ALGORITHM, $receiverAddress . $salt . $time);
    }

    // =========================================================================
    // TRANSACTION MATCHING & FEES (kept in facade)
    // =========================================================================

    public function matchYourselfTransaction($request, $address) {
        $p2pRequest = $this->p2pRepository->getByHash($request['memo']);
        if (hash(Constants::HASH_ALGORITHM, $address . $p2pRequest['salt'] . $p2pRequest['time']) === $request['memo']) {
            return true;
        }
        $allAddresses = $this->currentUser->getUserLocaters();
        foreach ($allAddresses as $userAddress) {
            if ($userAddress === $address) continue;
            if (hash(Constants::HASH_ALGORITHM, $userAddress . $p2pRequest['salt'] . $p2pRequest['time']) === $request['memo']) {
                return true;
            }
        }
        return false;
    }

    public function removeTransactionFee(array $request): SplitAmount {
        $p2p = $this->p2pRepository->getByHash($request['memo']);
        $amount = SplitAmount::from($request['amount']);
        $feeAmount = SplitAmount::from($p2p['my_fee_amount']);
        return $amount->subtract($feeAmount);
    }

    // =========================================================================
    // DATA PREPARATION (kept in facade)
    // =========================================================================

    public function prepareStandardTransactionData(array $request, array $contactInfo): array {
        output(outputPrepareSendData($request), 'SILENT');

        $data['txType'] = 'standard';
        $data['time'] = $this->timeUtility->getCurrentMicrotime();
        $data['currency'] = $request[4] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
        $data['amount'] = SplitAmount::from($request[3]);
        $data['memo'] = 'standard';
        $data['description'] = $request[5] ?? null;

        $transportIndex = $this->transportUtility->fallbackTransportType($request[2], $contactInfo);
        if ($transportIndex === null) {
            throw new \InvalidArgumentException("No viable transport mode found for recipient");
        }

        $data['receiverAddress'] = $contactInfo[$transportIndex];
        $data['receiverPublicKey'] = $contactInfo['receiverPublicKey'];
        $data['txid'] = $this->createUniqueTxid($data);
        $data['previousTxid'] = $this->transactionRepository->getPreviousTxid(
            $this->currentUser->getPublicKey(),
            $data['receiverPublicKey'],
            null,
            $data['currency']
        );
        $data['end_recipient_address'] = $data['receiverAddress'];
        $data['initial_sender_address'] = $this->transportUtility->resolveUserAddressForTransport($data['receiverAddress']);

        return $data;
    }

    public function prepareP2pTransactionData(array $request, ?string $description = null): array {
        $data['time'] = $request['time'];
        $data['receiverAddress'] = $request['senderAddress'];
        $data['receiverPublicKey'] = $request['senderPublicKey'];
        $data['amount'] = $request['amount'];
        $data['currency'] = $request['currency'];
        $data['txid'] = $this->createUniqueTxid($data);
        $data['memo'] = $request['hash'];
        $data['previousTxid'] = $this->transactionRepository->getPreviousTxid(
            $this->currentUser->getPublicKey(),
            $data['receiverPublicKey'],
            null,
            $data['currency']
        );

        if ($description !== null) {
            $data['description'] = $description;
        }

        $p2p = $this->p2pRepository->getByHash($request['hash']);
        if ($p2p && isset($p2p['destination_address'])) {
            $data['end_recipient_address'] = $p2p['destination_address'];
        }
        $data['initial_sender_address'] = $this->transportUtility->resolveUserAddressForTransport($data['receiverAddress']);

        return $data;
    }

    // =========================================================================
    // DELEGATED: Balance Operations
    // =========================================================================

    public function getUserTotalBalance(): string {
        if ($this->balanceService !== null) {
            return $this->balanceService->getUserTotalBalance();
        }
        // Inline fallback for backward compatibility
        $balances = $this->balanceRepository->getUserBalance();
        if ($balances === null || empty($balances)) return "0.00";
        $total = SplitAmount::zero();
        foreach ($balances as $balance) {
            if (isset($balance['total_balance']) && $balance['total_balance'] instanceof SplitAmount) {
                $total = $total->add($balance['total_balance']);
            }
        }
        return $this->currencyUtility->convertMinorToMajor($total);
    }

    public function contactBalanceConversion($contacts, int $transactionLimit = Constants::CONTACT_TRANSACTIONS_LIMIT): array {
        if ($this->balanceService !== null) {
            return $this->balanceService->contactBalanceConversion($contacts, $transactionLimit);
        }
        if (empty($contacts)) return [];

        $pubkeys = array_column($contacts, 'pubkey');
        $balances = $this->transactionContactRepository->getAllContactBalances($this->currentUser->getPublicKey(), $pubkeys);
        $addressTypes = $this->addressRepository->getAllAddressTypes();
        $result = [];

        foreach ($contacts as $contact) {
            $contactBalances = $balances[$contact['pubkey']] ?? [];
            $primaryCurrency = !empty($contactBalances) ? array_key_first($contactBalances) : Constants::TRANSACTION_DEFAULT_CURRENCY;
            $balance = $contactBalances[$primaryCurrency] ?? 0;

            $balancesByCurrency = [];
            foreach ($contactBalances as $cur => $bal) {
                $balancesByCurrency[$cur] = ($bal instanceof SplitAmount) ? $this->currencyUtility->convertMinorToMajor($bal) : 0;
            }

            $addresses = [];
            $contactAddrs = [];
            foreach ($addressTypes as $type) {
                $addr = $contact[$type] ?? '';
                $addresses[$type] = $addr;
                if (!empty($addr)) $contactAddrs[] = $addr;
            }
            $transactions = $this->transactionContactRepository->getTransactionsWithContact($contactAddrs, $transactionLimit);
            $result[] = array_merge($addresses, [
                'name' => $contact['name'],
                'balance' => ($balance instanceof SplitAmount) ? $this->currencyUtility->convertMinorToMajor($balance) : $balance,
                'balances_by_currency' => $balancesByCurrency,
                'pubkey' => $contact['pubkey'] ?? '',
                'contact_id' => $contact['contact_id'] ?? '',
                'transactions' => $transactions,
                'online_status' => $contact['online_status'] ?? 'unknown',
                'valid_chain' => $contact['valid_chain'] ?? null,
                'pubkey_hash' => $contact['pubkey_hash'] ?? '',
                'status' => $contact['status'] ?? ''
            ]);
        }
        return $result;
    }

    public function getContactBalance(string $userPubkey, string $contactPubkey): SplitAmount {
        if ($this->balanceService !== null) {
            return $this->balanceService->getContactBalance($userPubkey, $contactPubkey);
        }
        return $this->transactionContactRepository->getContactBalance($userPubkey, $contactPubkey);
    }

    public function getAllContactBalances(string $userPubkey, array $contactPubkeys): array {
        if ($this->balanceService !== null) {
            return $this->balanceService->getAllContactBalances($userPubkey, $contactPubkeys);
        }
        return $this->transactionContactRepository->getAllContactBalances($userPubkey, $contactPubkeys);
    }

    // =========================================================================
    // DELEGATED: Chain Verification
    // =========================================================================

    public function verifySenderChainAndSync(string $contactAddress, string $contactPublicKey, ?string $currency = null): array {
        if ($this->chainVerificationService !== null) {
            return $this->chainVerificationService->verifySenderChainAndSync($contactAddress, $contactPublicKey, $currency);
        }
        // Minimal fallback
        $result = ['success' => true, 'synced' => false, 'error' => null];
        $chainStatus = $this->transactionChainRepository->verifyChainIntegrity($this->currentUser->getPublicKey(), $contactPublicKey, $currency);
        if ($chainStatus['valid']) return $result;

        output(outputSyncChainIntegrityFailed(count($chainStatus['gaps'])), 'SILENT');
        $syncResult = $this->getSyncTrigger()->syncTransactionChain($contactAddress, $contactPublicKey);
        $result['synced'] = true;

        if (!$syncResult['success']) {
            $recheck = $this->transactionChainRepository->verifyChainIntegrity($this->currentUser->getPublicKey(), $contactPublicKey, $currency);
            if (!$recheck['valid']) {
                $result['success'] = false;
                $result['error'] = 'Failed to repair chain: ' . ($syncResult['error'] ?? 'unknown');
                return $result;
            }
        }
        output(outputSyncChainRepaired(), 'SILENT');
        return $result;
    }

    // =========================================================================
    // DELEGATED: Validation
    // =========================================================================

    public function checkPreviousTxid(array $request): bool {
        if ($this->transactionValidationService !== null) {
            return $this->transactionValidationService->checkPreviousTxid($request);
        }
        if (!isset($request['senderPublicKey'], $request['receiverPublicKey'])) return false;
        $expected = $this->transactionRepository->getPreviousTxid($request['senderPublicKey'], $request['receiverPublicKey'], null, $request['currency'] ?? null);
        return $expected === ($request['previousTxid'] ?? null);
    }

    public function checkAvailableFundsTransaction(array $request): bool {
        if ($this->transactionValidationService !== null) {
            return $this->transactionValidationService->checkAvailableFundsTransaction($request);
        }
        if (!isset($request['senderPublicKey'], $request['amount'], $request['currency'])) return false;
        $validation = InputValidator::validateAmount($request['amount'], $request['currency']);
        if (!$validation['valid']) return false;
        $request['amount'] = $validation['value'];
        $validationUtility = $this->utilityContainer->getValidationUtility();
        $available = $validationUtility->calculateAvailableFunds($request);
        $credit = $this->contactRepository->getCreditLimit($request['senderPublicKey'], $request['currency']);
        $totalAvailable = $available->add($credit);
        $requestAmount = SplitAmount::from($request['amount']);
        return $totalAvailable->gte($requestAmount);
    }

    public function checkTransactionPossible(array $request, $echo = true): bool {
        if ($this->transactionValidationService !== null) {
            return $this->transactionValidationService->checkTransactionPossible($request, $echo);
        }
        throw new RuntimeException('TransactionValidationService not injected.');
    }

    // =========================================================================
    // DELEGATED: Processing
    // =========================================================================

    public function processTransaction(array $request): void {
        if ($this->transactionProcessingService !== null) {
            $this->transactionProcessingService->processTransaction($request);
            return;
        }
        throw new RuntimeException('TransactionProcessingService not injected.');
    }

    public function processPendingTransactions(): int {
        if ($this->transactionProcessingService !== null) {
            return $this->transactionProcessingService->processPendingTransactions();
        }
        throw new RuntimeException('TransactionProcessingService not injected.');
    }

    // =========================================================================
    // DELEGATED: Send Operations
    // =========================================================================

    public function sendEiou(array $request, ?CliOutputManager $output = null): void {
        if ($this->sendOperationService !== null) {
            $this->sendOperationService->sendEiou($request, $output);
            return;
        }
        throw new RuntimeException('SendOperationService not injected.');
    }

    public function handleDirectRoute(array $request, $contactInfo, ?CliOutputManager $output = null): void {
        if ($this->sendOperationService !== null) {
            $this->sendOperationService->handleDirectRoute($request, $contactInfo, $output);
            return;
        }
        throw new RuntimeException('SendOperationService not injected.');
    }

    public function handleP2pRoute(array $request, ?CliOutputManager $output = null): void {
        if ($this->sendOperationService !== null) {
            $this->sendOperationService->handleP2pRoute($request, $output);
            return;
        }
        throw new RuntimeException('SendOperationService not injected.');
    }

    public function sendP2pEiou(array $request): void {
        if ($this->sendOperationService !== null) {
            $this->sendOperationService->sendP2pEiou($request);
            return;
        }
        throw new RuntimeException('SendOperationService not injected.');
    }

    // =========================================================================
    // REPOSITORY WRAPPERS
    // =========================================================================

    public function getByTxid(string $txid): ?array {
        return $this->transactionRepository->getByTxid($txid);
    }

    public function getByMemo(string $memo): ?array {
        return $this->transactionRepository->getByMemo($memo);
    }

    public function updateStatus(string $identifier, string $status, bool $isTxid = false): bool {
        return $this->transactionRepository->updateStatus($identifier, $status, $isTxid);
    }

    public function getSentUserTransactions(int $limit): array {
        return $this->transactionRepository->getSentUserTransactions($limit);
    }

    public function getSentUserTransactionsAddress(string $receiverAddress, int $limit): array {
        return $this->transactionRepository->getSentUserTransactionsAddress($receiverAddress, $limit);
    }

    public function getReceivedUserTransactions(int $limit): array {
        return $this->transactionRepository->getReceivedUserTransactions($limit);
    }

    public function getReceivedUserTransactionsAddress(string $senderAddress, int $limit): array {
        return $this->transactionRepository->getReceivedUserTransactionsAddress($senderAddress, $limit);
    }

    public function checkForNewTransactions($lastCheckTime) {
        return $this->transactionRepository->checkForNewTransactions($lastCheckTime);
    }

    /**
     * Database-wide search backing the Recent Transactions "Search entire
     * database" button. Thin passthrough to the repository; filter dims
     * default to null so the GUI can hand through the currently-selected
     * dropdown values verbatim without null-coalescing.
     *
     * @param string      $term       Non-empty substring search term
     * @param string|null $direction  sent / received filter (null = any)
     * @param string|null $txType     direct / p2p / contact filter (null = any)
     * @param string|null $status     status filter (null = any)
     * @param int         $maxResults Hard cap on returned rows (default 500)
     */
    public function searchTransactions(
        string $term,
        ?string $direction = null,
        ?string $txType = null,
        ?string $status = null,
        int $maxResults = 500
    ): array {
        return $this->transactionRepository->searchTransactions($term, $direction, $txType, $status, $maxResults);
    }

    public function getTransactionHistory(int $limit = 10, int $offset = 0): array {
        // Over-fetch from the primary table so the cancelled-P2P merge still
        // has a stable `$limit` rows of material after slicing. Offset is
        // applied to the primary table query; cancelled P2Ps are only
        // fetched on the first page (offset === 0) because they're a small
        // supplementary set that doesn't paginate cleanly.
        $transactions = $this->transactionRepository->getTransactionHistory($limit, null, $offset);

        // Merge in cancelled/expired originator P2Ps that never created a transaction,
        // so failed P2P attempts are visible in the Recent Transactions view.
        // Only on the first page — subsequent pages keep the primary-table
        // ordering without reshuffling in older cancelled P2Ps.
        try {
            $cancelledP2ps = $offset === 0 ? $this->p2pRepository->getCancelledOriginatorP2ps($limit) : [];
            foreach ($cancelledP2ps as $p2p) {
                $amount = ($p2p['amount'] instanceof SplitAmount) ? $p2p['amount'] : SplitAmount::from($p2p['amount'] ?? 0);
                $transactions[] = [
                    'id' => 0,
                    'txid' => $p2p['hash'],
                    'tx_type' => 'p2p',
                    'direction' => null,
                    'status' => $p2p['status'],
                    'date' => $p2p['created_at'] ?? null,
                    'type' => Constants::TX_TYPE_SENT,
                    'amount' => $amount->toMajorUnits(),
                    'currency' => $p2p['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY,
                    'counterparty' => null,
                    'counterparty_address' => null,
                    'counterparty_name' => null,
                    'sender_address' => null,
                    'receiver_address' => null,
                    'sender_public_key' => null,
                    'receiver_public_key' => null,
                    'memo' => $p2p['hash'],
                    'description' => $p2p['description'] ?? 'No P2P route found',
                    'previous_txid' => null,
                    'end_recipient_address' => $p2p['destination_address'],
                    'initial_sender_address' => null,
                    'p2p_destination' => $p2p['destination_address'],
                    'p2p_amount' => null,
                    'p2p_fee' => null,
                ];
            }
        } catch (\Throwable $e) {
            // Non-critical — transaction history still works without cancelled P2Ps
            Logger::getInstance()->warning("Failed to merge cancelled P2Ps into transaction history", [
                'error' => $e->getMessage()
            ]);
        }

        // Re-sort by date descending and trim to limit
        usort($transactions, function ($a, $b) {
            $dateA = strtotime($a['date'] ?? '') ?: 0;
            $dateB = strtotime($b['date'] ?? '') ?: 0;
            return $dateB <=> $dateA;
        });

        return array_slice($transactions, 0, $limit);
    }

    public function getStatistics(): array {
        return $this->transactionStatisticsRepository->getOverallStatistics();
    }

    public function getInProgressTransactions(int $limit = 10): array {
        return $this->transactionRecoveryRepository->getInProgressTransactions($limit);
    }
}
