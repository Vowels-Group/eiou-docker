<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../utils/InputValidator.php';
require_once __DIR__ . '/../cli/CliOutputManager.php';
require_once __DIR__ . '/MessageDeliveryService.php';
require_once __DIR__ . '/../core/ErrorCodes.php';
require_once __DIR__ . '/../contracts/TransactionServiceInterface.php';
require_once __DIR__ . '/../contracts/LockingServiceInterface.php';
require_once __DIR__ . '/../contracts/BalanceServiceInterface.php';
require_once __DIR__ . '/../contracts/ChainVerificationServiceInterface.php';
require_once __DIR__ . '/../contracts/TransactionValidationServiceInterface.php';
require_once __DIR__ . '/../contracts/TransactionProcessingServiceInterface.php';
require_once __DIR__ . '/../contracts/SendOperationServiceInterface.php';
require_once __DIR__ . '/../database/TransactionChainRepository.php';
require_once __DIR__ . '/../database/TransactionRecoveryRepository.php';
require_once __DIR__ . '/../database/TransactionContactRepository.php';
require_once __DIR__ . '/../database/TransactionStatisticsRepository.php';


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
    private SecureLogger $secureLogger;
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
    private ?SyncService $syncService = null;
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
        SecureLogger $secureLogger,
        UserContext $currentUser,
        ?MessageDeliveryService $messageDeliveryService = null,
        ?HeldTransactionService $heldTransactionService = null
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

        require_once '/etc/eiou/src/schemas/payloads/TransactionPayload.php';
        $this->transactionPayload = new TransactionPayload($this->currentUser, $this->utilityContainer);
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

    public function setSyncService(SyncService $service): void {
        $this->syncService = $service;
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

    private function getSyncService(): SyncService {
        if ($this->syncService === null) {
            throw new RuntimeException('SyncService not injected.');
        }
        return $this->syncService;
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
        return hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey() . $data['receiverPublicKey'] . $data['amount'] . $data['time']);
    }

    public function createUniqueDatabaseTxid(array $data, array $rp2p): string {
        return hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey() . $rp2p['sender_public_key'] . $data['amount'] . $rp2p['time']);
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

    public function removeTransactionFee(array $request): float {
        $p2p = $this->p2pRepository->getByHash($request['memo']);
        return $request['amount'] - $p2p['my_fee_amount'];
    }

    // =========================================================================
    // DATA PREPARATION (kept in facade)
    // =========================================================================

    public function prepareStandardTransactionData(array $request, array $contactInfo): array {
        output(outputPrepareSendData($request), 'SILENT');

        $data['txType'] = 'standard';
        $data['time'] = $this->timeUtility->getCurrentMicrotime();
        $data['amount'] = round($request[3] * Constants::TRANSACTION_USD_CONVERSION_FACTOR);
        $data['currency'] = $request[4] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
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
            $data['receiverPublicKey']
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
            $data['receiverPublicKey']
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
        $totalCents = 0;
        foreach ($balances as $balance) {
            $totalCents += (int) ($balance['total_balance'] ?? 0);
        }
        return $this->currencyUtility->convertCentsToDollars($totalCents);
    }

    public function contactBalanceConversion($contacts, int $transactionLimit = 5): array {
        if ($this->balanceService !== null) {
            return $this->balanceService->contactBalanceConversion($contacts, $transactionLimit);
        }
        if (empty($contacts)) return [];

        $pubkeys = array_column($contacts, 'pubkey');
        $balances = $this->transactionContactRepository->getAllContactBalances($this->currentUser->getPublicKey(), $pubkeys);
        $addressTypes = $this->addressRepository->getAllAddressTypes();
        $result = [];

        foreach ($contacts as $contact) {
            $balance = $balances[$contact['pubkey']] ?? 0;
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
                'balance' => $balance ? $this->currencyUtility->convertCentsToDollars($balance) : $balance,
                'fee' => $contact['fee_percent'] ? $this->currencyUtility->convertCentsToDollars($contact['fee_percent']) : $contact['fee_percent'],
                'credit_limit' => $contact['credit_limit'] ? $this->currencyUtility->convertCentsToDollars($contact['credit_limit']) : $contact['credit_limit'],
                'currency' => $contact['currency'],
                'pubkey' => $contact['pubkey'] ?? '',
                'contact_id' => $contact['contact_id'] ?? '',
                'transactions' => $transactions,
                'online_status' => $contact['online_status'] ?? 'unknown',
                'valid_chain' => $contact['valid_chain'] ?? null
            ]);
        }
        return $result;
    }

    public function getContactBalance(string $userPubkey, string $contactPubkey): int {
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

    public function verifySenderChainAndSync(string $contactAddress, string $contactPublicKey): array {
        if ($this->chainVerificationService !== null) {
            return $this->chainVerificationService->verifySenderChainAndSync($contactAddress, $contactPublicKey);
        }
        // Minimal fallback
        $result = ['success' => true, 'synced' => false, 'error' => null];
        $chainStatus = $this->transactionChainRepository->verifyChainIntegrity($this->currentUser->getPublicKey(), $contactPublicKey);
        if ($chainStatus['valid']) return $result;

        output(outputSyncChainIntegrityFailed(count($chainStatus['gaps'])), 'SILENT');
        $syncResult = $this->getSyncService()->syncTransactionChain($contactAddress, $contactPublicKey);
        $result['synced'] = true;

        if (!$syncResult['success']) {
            $recheck = $this->transactionChainRepository->verifyChainIntegrity($this->currentUser->getPublicKey(), $contactPublicKey);
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
        $expected = $this->transactionRepository->getPreviousTxid($request['senderPublicKey'], $request['receiverPublicKey']);
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
        $credit = $this->contactRepository->getCreditLimit($request['senderPublicKey']);
        return ($available + $credit) >= $request['amount'];
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

    public function getTransactionHistory(int $limit = 10): array {
        return $this->transactionRepository->getTransactionHistory($limit);
    }

    public function getStatistics(): array {
        return $this->transactionStatisticsRepository->getOverallStatistics();
    }

    public function getInProgressTransactions(int $limit = 10): array {
        return $this->transactionRecoveryRepository->getInProgressTransactions($limit);
    }
}
