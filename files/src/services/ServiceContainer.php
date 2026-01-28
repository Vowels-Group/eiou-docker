<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../utils/SecureLogger.php';

/**
 * Service Container
 *
 * Centralized dependency injection container for managing service instances.
 */

class ServiceContainer {
    /**
     * @var ServiceContainer|null Singleton instance
     */
    private static ?ServiceContainer $instance = null;

    /**
     * @var array Cached repository instances
     */
    private array $repositories = [];

    /**
     * @var array Cached service instances
     */
    private array $services = [];

    /**
     * @var array Cached Util instances
     */
    private array $utils = [];

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var PDO Database connection
     */
    private PDO|null $pdo;

    /**
     * Private constructor for singleton pattern
     * 
     * @param UserContext $currentUser Current user data (optional)
     * @param PDO $pdo Database connection (optional)
     */
    private function __construct(
        ?UserContext $currentUser,
        ?PDO $pdo
    ) {
        if($currentUser){
            $this->currentUser = $currentUser;
        } else{
            $this->loadCurrentUser();
        }
        if($pdo){
            $this->pdo = $pdo;
        } else{
            $this->loadDatabase();
        }
    }

    /**
     * Get singleton instance
     *
     * @param UserContext|null $currentUser Current user context (optional)
     * @param PDO|null $pdo Database connection (optional)
     * @return ServiceContainer
     */
    public static function getInstance(?UserContext $currentUser = null, ?PDO $pdo = null): ServiceContainer {
        if (self::$instance === null) {
            self::$instance = new self($currentUser, $pdo);
        }
        return self::$instance;
    }

    /**
     * Load current user from global scope
     */
    private function loadCurrentUser(): void {
        require_once '/etc/eiou/src/core/UserContext.php';
        $this->currentUser = UserContext::getInstance();
    }

    /**
     * Set current user (for testing or manual injection)
     *
     * @param UserContext $user User data
     */
    public function setCurrentUser(UserContext $user): void {
        $this->currentUser = $user;
    }

    /**
     * Get current user
     *
     * @return UserContext Current user data
     */
    public function getCurrentUser(): UserContext {
        return $this->currentUser;
    }

    /**
     * Get database connection (lazy loaded)
     *
     * @return void
     */
    public function loadDatabase(): void {
        require_once '/etc/eiou/src/database/Pdo.php';
        try {
            $this->pdo = createPDOConnection();
        } catch (RuntimeException $e) {
            // Log the error
            SecureLogger::logException($e, 'CRITICAL');
            // Set PDO to null to indicate unavailability
            $this->pdo = null;
        }
    }

    /**
     * Get PDO instance
     *
     * @return PDO Database connection
     * @throws RuntimeException If database connection is not available
     */
    public function getPdo(): PDO {
        if ($this->pdo === null) {
            throw new RuntimeException('Database connection is not available');
        }
        return $this->pdo;
    }

    /**
     * Get AddressRepository instance
     *
     * @return AddressRepository
     */
    public function getAddressRepository(): AddressRepository {
        if (!isset($this->repositories['AddressRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/AddressRepository.php';
            $this->repositories['AddressRepository'] = new AddressRepository(
                $this->pdo
            );
        }
        return $this->repositories['AddressRepository'];
    }

    /**
     * Get BalanceRepository instance
     *
     * @return BalanceRepository
     */
    public function getBalanceRepository(): BalanceRepository {
        if (!isset($this->repositories['BalanceRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/BalanceRepository.php';
            $this->repositories['BalanceRepository'] = new BalanceRepository(
                $this->pdo
            );
        }
        return $this->repositories['BalanceRepository'];
    }

    /**
     * Get ContactRepository instance
     *
     * @return ContactRepository
     */
    public function getContactRepository(): ContactRepository {
        if (!isset($this->repositories['ContactRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/ContactRepository.php';
            $this->repositories['ContactRepository'] = new ContactRepository(
                $this->pdo
            );
        }
        return $this->repositories['ContactRepository'];
    }

    /**
     * Get P2pRepository instance
     *
     * @return P2pRepository
     */
    public function getP2pRepository(): P2pRepository {
        if (!isset($this->repositories['P2pRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/P2pRepository.php';
            $this->repositories['P2pRepository'] = new P2pRepository(
                $this->pdo
            );
        }
        return $this->repositories['P2pRepository'];
    }

    /**
     * Get Rp2pRepository instance
     *
     * @return Rp2pRepository
     */
    public function getRp2pRepository(): Rp2pRepository {
        if (!isset($this->repositories['Rp2pRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/Rp2pRepository.php';
            $this->repositories['Rp2pRepository'] = new Rp2pRepository(
                $this->pdo
            );
        }
        return $this->repositories['Rp2pRepository'];
    }

    /**
     * Get TransactionRepository instance
     *
     * @return TransactionRepository
     */
    public function getTransactionRepository(): TransactionRepository {
        if (!isset($this->repositories['TransactionRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/TransactionRepository.php';
            $this->repositories['TransactionRepository'] = new TransactionRepository(
                $this->pdo
            );
        }
        return $this->repositories['TransactionRepository'];
    }

    /**
     * Get DebugRepository instance
     *
     * @return DebugRepository
     */
    public function getDebugRepository(): DebugRepository {
        if (!isset($this->repositories['DebugRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/DebugRepository.php';
            $this->repositories['DebugRepository'] = new DebugRepository(
                $this->pdo
            );
        }
        return $this->repositories['DebugRepository'];
    }

    /**
     * Get ApiKeyRepository instance
     *
     * @return ApiKeyRepository
     */
    public function getApiKeyRepository(): ApiKeyRepository {
        if (!isset($this->repositories['ApiKeyRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/ApiKeyRepository.php';
            $this->repositories['ApiKeyRepository'] = new ApiKeyRepository(
                $this->pdo
            );
        }
        return $this->repositories['ApiKeyRepository'];
    }
    
    /** Get MessageDeliveryRepository instance
     *
     * @return MessageDeliveryRepository
     */
    public function getMessageDeliveryRepository(): MessageDeliveryRepository {
        if (!isset($this->repositories['MessageDeliveryRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/MessageDeliveryRepository.php';
            $this->repositories['MessageDeliveryRepository'] = new MessageDeliveryRepository(
                $this->pdo
            );
        }
        return $this->repositories['MessageDeliveryRepository'];
    }

    /**
     * Get DeadLetterQueueRepository instance
     *
     * @return DeadLetterQueueRepository
     */
    public function getDeadLetterQueueRepository(): DeadLetterQueueRepository {
        if (!isset($this->repositories['DeadLetterQueueRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/DeadLetterQueueRepository.php';
            $this->repositories['DeadLetterQueueRepository'] = new DeadLetterQueueRepository(
                $this->pdo
            );
        }
        return $this->repositories['DeadLetterQueueRepository'];
    }

    /**
     * Get DeliveryMetricsRepository instance
     *
     * @return DeliveryMetricsRepository
     */
    public function getDeliveryMetricsRepository(): DeliveryMetricsRepository {
        if (!isset($this->repositories['DeliveryMetricsRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/DeliveryMetricsRepository.php';
            $this->repositories['DeliveryMetricsRepository'] = new DeliveryMetricsRepository(
                $this->pdo
            );
        }
        return $this->repositories['DeliveryMetricsRepository'];
    }

    /**
     * Get HeldTransactionRepository instance
     *
     * @return HeldTransactionRepository
     */
    public function getHeldTransactionRepository(): HeldTransactionRepository {
        if (!isset($this->repositories['HeldTransactionRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/HeldTransactionRepository.php';
            $this->repositories['HeldTransactionRepository'] = new HeldTransactionRepository(
                $this->pdo
            );
        }
        return $this->repositories['HeldTransactionRepository'];
    }

    /**
     * Get RateLimiterRepository instance
     *
     * @return RateLimiterRepository
     */
    public function getRateLimiterRepository(): RateLimiterRepository {
        if (!isset($this->repositories['RateLimiterRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/RateLimiterRepository.php';
            $this->repositories['RateLimiterRepository'] = new RateLimiterRepository(
                $this->pdo
            );
        }
        return $this->repositories['RateLimiterRepository'];
    }

    /**
     * Get TransactionStatisticsRepository instance
     *
     * @return TransactionStatisticsRepository
     */
    public function getTransactionStatisticsRepository(): TransactionStatisticsRepository {
        if (!isset($this->repositories['TransactionStatisticsRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/TransactionStatisticsRepository.php';
            $this->repositories['TransactionStatisticsRepository'] = new TransactionStatisticsRepository(
                $this->pdo
            );
        }
        return $this->repositories['TransactionStatisticsRepository'];
    }

    /**
     * Get TransactionChainRepository instance
     *
     * @return TransactionChainRepository
     */
    public function getTransactionChainRepository(): TransactionChainRepository {
        if (!isset($this->repositories['TransactionChainRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/TransactionChainRepository.php';
            $this->repositories['TransactionChainRepository'] = new TransactionChainRepository(
                $this->pdo
            );
        }
        return $this->repositories['TransactionChainRepository'];
    }

    /**
     * Get TransactionRecoveryRepository instance
     *
     * @return TransactionRecoveryRepository
     */
    public function getTransactionRecoveryRepository(): TransactionRecoveryRepository {
        if (!isset($this->repositories['TransactionRecoveryRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/TransactionRecoveryRepository.php';
            $this->repositories['TransactionRecoveryRepository'] = new TransactionRecoveryRepository(
                $this->pdo
            );
        }
        return $this->repositories['TransactionRecoveryRepository'];
    }

    /**
     * Get TransactionContactRepository instance
     *
     * @return TransactionContactRepository
     */
    public function getTransactionContactRepository(): TransactionContactRepository {
        if (!isset($this->repositories['TransactionContactRepository'])) {
            require_once dirname(__DIR__,2) . '/src/database/TransactionContactRepository.php';
            $this->repositories['TransactionContactRepository'] = new TransactionContactRepository(
                $this->pdo
            );
        }
        return $this->repositories['TransactionContactRepository'];
    }

    /**
     * Get ContactService instance
     *
     * Integrates MessageDeliveryService for reliable contact message delivery
     * with retry logic and dead letter queue support.
     *
     * @return ContactServiceInterface
     */
    public function getContactService(): ContactServiceInterface {
        if (!isset($this->services['ContactService'])) {
            require_once __DIR__ . '/ContactService.php';
            $this->services['ContactService'] = new ContactService(
                $this->getContactRepository(),
                $this->getAddressRepository(),
                $this->getBalanceRepository(),
                $this->getUtilityContainer(),
                $this->getInputValidator(),
                $this->getLogger(),
                $this->currentUser,
                $this->getTransactionRepository(),
                $this->getTransactionContactRepository(),
                $this->getMessageDeliveryService()
            );
        }
        return $this->services['ContactService'];
    }

    /**
     * Get TransactionService instance
     *
     * Integrates MessageDeliveryService for reliable transaction message delivery
     * with retry logic and dead letter queue support.
     *
     * @return TransactionServiceInterface
     */
    public function getTransactionService(): TransactionServiceInterface {
        if (!isset($this->services['TransactionService'])) {
            require_once __DIR__ . '/TransactionService.php';
            $this->services['TransactionService'] = new TransactionService(
                $this->getContactRepository(),
                $this->getAddressRepository(),
                $this->getBalanceRepository(),
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getTransactionRepository(),
                $this->getTransactionChainRepository(),
                $this->getTransactionRecoveryRepository(),
                $this->getTransactionContactRepository(),
                $this->getTransactionStatisticsRepository(),
                $this->getUtilityContainer(),
                $this->getInputValidator(),
                $this->getLogger(),
                $this->currentUser,
                $this->getMessageDeliveryService(),
                $this->getHeldTransactionService() // Set HeldTransactionService for handling invalid_previous_txid rejections
            );
            // Inject LockingService for distributed contact send locks
            $this->services['TransactionService']->setLockingService($this->getLockingService());
        }
        return $this->services['TransactionService'];
    }

    /**
     * Get P2pService instance
     *
     * Integrates MessageDeliveryService for reliable P2P message delivery
     * with retry logic and dead letter queue support.
     *
     * @return P2pServiceInterface
     */
    public function getP2pService(): P2pServiceInterface {
        if (!isset($this->services['P2pService'])) {
            require_once __DIR__ . '/P2pService.php';
            $this->services['P2pService'] = new P2pService(
                $this->getContactRepository(),
                $this->getBalanceRepository(),
                $this->getP2pRepository(),
                $this->getTransactionRepository(),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getMessageDeliveryService()
            );
        }
        return $this->services['P2pService'];
    }

    /**
     * Get Rp2pService instance
     *
     * Integrates MessageDeliveryService for reliable RP2P message delivery
     * with retry logic and dead letter queue support.
     *
     * @return Rp2pService
     */
    public function getRp2pService(): Rp2pServiceInterface {
        if (!isset($this->services['Rp2pService'])) {
            require_once __DIR__ . '/Rp2pService.php';
            $this->services['Rp2pService'] = new Rp2pService(
                $this->getContactRepository(),
                $this->getBalanceRepository(),
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getMessageDeliveryService()
            );
        }
        return $this->services['Rp2pService'];
    }

    /**
     * Get WalletService instance
     *
     * @return WalletService
     */
    public function getWalletService(): WalletServiceInterface {
        if (!isset($this->services['WalletService'])) {
            require_once __DIR__ . '/WalletService.php';
            $this->services['WalletService'] = new WalletService(
                $this->currentUser
            );
        }
        return $this->services['WalletService'];
    }

    /**
     * Get MessageService instance
     *
     * @return MessageService
     */
    public function getMessageService(): MessageServiceInterface {
        if (!isset($this->services['MessageService'])) {
            require_once __DIR__ . '/MessageService.php';
            $this->services['MessageService'] = new MessageService(
                $this->getContactRepository(),
                $this->getBalanceRepository(),
                $this->getP2pRepository(),
                $this->getTransactionRepository(),
                $this->getTransactionContactRepository(),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getMessageDeliveryService()
            );
        }
        return $this->services['MessageService'];
    }

    /**
     * Get CleanupService instance
     *
     * @return CleanupService
     */
    public function getCleanupService(): CleanupServiceInterface {
        if (!isset($this->services['CleanupService'])) {
            require_once __DIR__ . '/CleanupService.php';
            $this->services['CleanupService'] = new CleanupService(
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getTransactionRepository(),
                $this->getBalanceRepository(),
                $this->getUtilityContainer(),
                $this->currentUser
            );
        }
        return $this->services['CleanupService'];
    }

    /**
     * Get SyncService instance
     *
     * @return SyncServiceInterface
     */
    public function getSyncService(): SyncServiceInterface {
        if (!isset($this->services['SyncService'])) {
            require_once __DIR__ . '/SyncService.php';
            $this->services['SyncService'] = new SyncService(
                $this->getContactRepository(),
                $this->getAddressRepository(),
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getTransactionRepository(),
                $this->getTransactionChainRepository(),
                $this->getTransactionContactRepository(),
                $this->getBalanceRepository(),
                $this->getUtilityContainer(),
                $this->currentUser
            );
        }
        return $this->services['SyncService'];
    }

    /**
     * Get DebugService instance
     *
     * @return DebugService
     */
    public function getDebugService(): DebugServiceInterface {
        if (!isset($this->services['DebugService'])) {
            require_once __DIR__ . '/DebugService.php';
            $this->services['DebugService'] = new DebugService(
                $this->getDebugRepository(),
                $this->currentUser
            );
        }
        return $this->services['DebugService'];
    }

    /**
     * Get MessageDeliveryService instance
     *
     * @return MessageDeliveryService
     */
    public function getMessageDeliveryService(): MessageDeliveryServiceInterface {
        if (!isset($this->services['MessageDeliveryService'])) {
            require_once __DIR__ . '/MessageDeliveryService.php';
            $this->services['MessageDeliveryService'] = new MessageDeliveryService(
                $this->getMessageDeliveryRepository(),
                $this->getDeadLetterQueueRepository(),
                $this->getDeliveryMetricsRepository(),
                $this->getUtilityContainer()->getTransportUtility(),
                $this->getUtilityContainer()->getTimeUtility(),
                $this->currentUser
            );
        }
        return $this->services['MessageDeliveryService'];
    }

    /**
     * Get HeldTransactionService instance
     *
     * @return HeldTransactionService
     */
    public function getHeldTransactionService(): HeldTransactionServiceInterface {
        if (!isset($this->services['HeldTransactionService'])) {
            require_once __DIR__ . '/HeldTransactionService.php';
            $this->services['HeldTransactionService'] = new HeldTransactionService(
                $this->getHeldTransactionRepository(),
                $this->getTransactionRepository(),
                $this->getTransactionChainRepository(),
                $this->getUtilityContainer(),
                $this->currentUser
            );
        }
        return $this->services['HeldTransactionService'];
    }

    /**
     * Get TransactionRecoveryService instance
     *
     * @return TransactionRecoveryService
     */
    public function getTransactionRecoveryService(): TransactionRecoveryServiceInterface {
        if (!isset($this->services['TransactionRecoveryService'])) {
            require_once __DIR__ . '/TransactionRecoveryService.php';
            $this->services['TransactionRecoveryService'] = new TransactionRecoveryService(
                $this->getTransactionRepository(),
                $this->getTransactionRecoveryRepository()
            );
        }
        return $this->services['TransactionRecoveryService'];
    }

    /**
     * Get CliService instance
     *
     * @return CliService
     */
    public function getCliService(): CliServiceInterface {
        if (!isset($this->services['CliService'])) {
            require_once __DIR__ . '/CliService.php';
            $this->services['CliService'] = new CliService(
                $this->getContactRepository(),
                $this->getBalanceRepository(),
                $this->getTransactionRepository(),
                $this->getUtilityContainer(),
                $this->currentUser
            );
        }
        return $this->services['CliService'];
    }

    /**
     * Get RateLimiterService instance
     *
     * @return RateLimiterService
     */
    public function getRateLimiterService(): RateLimiterServiceInterface {
        if (!isset($this->services['RateLimiterService'])) {
            require_once __DIR__ . '/RateLimiterService.php';
            $this->services['RateLimiterService'] = new RateLimiterService(
                $this->getRateLimiterRepository()
            );
        }
        return $this->services['RateLimiterService'];
    }

    /**
     * Get LockingService instance
     *
     * Provides database-backed distributed locking for concurrent operations.
     *
     * @return LockingServiceInterface
     */
    public function getLockingService(): LockingServiceInterface {
        if (!isset($this->services['LockingService'])) {
            require_once __DIR__ . '/DatabaseLockingService.php';
            $this->services['LockingService'] = new DatabaseLockingService(
                $this->getPdo()
            );
        }
        return $this->services['LockingService'];
    }

    /**
     * Get ContactStatusService instance
     *
     * Handles incoming ping requests and chain validation.
     *
     * @return ContactStatusService
     */
    public function getContactStatusService(): ContactStatusServiceInterface {
        if (!isset($this->services['ContactStatusService'])) {
            require_once __DIR__ . '/ContactStatusService.php';
            $this->services['ContactStatusService'] = new ContactStatusService(
                $this->getContactRepository(),
                $this->getTransactionRepository(),
                $this->getUtilityContainer(),
                $this->currentUser
            );
        }
        return $this->services['ContactStatusService'];
    }

    /**
     * Get ApiAuthService instance
     *
     * Provides API authentication using HMAC signature verification.
     *
     * @return ApiAuthService
     */
    public function getApiAuthService(): ApiAuthServiceInterface {
        if (!isset($this->services['ApiAuthService'])) {
            require_once __DIR__ . '/ApiAuthService.php';
            $this->services['ApiAuthService'] = new ApiAuthService(
                $this->getApiKeyRepository(),
                $this->getLogger()
            );
        }
        return $this->services['ApiAuthService'];
    }

    /**
     * Get ApiKeyService instance
     *
     * Provides CLI management for API keys.
     *
     * @param CliOutputManager $output CLI output manager for formatting
     * @return ApiKeyService
     */
    public function getApiKeyService(CliOutputManager $output): ApiKeyServiceInterface {
        if (!isset($this->services['ApiKeyService'])) {
            require_once __DIR__ . '/ApiKeyService.php';
            $this->services['ApiKeyService'] = new ApiKeyService(
                $this->getApiKeyRepository(),
                $output
            );
        }
        return $this->services['ApiKeyService'];
    }

    /**
     * Get BackupService instance
     *
     * Provides database backup and restore functionality with encryption.
     *
     * @return BackupServiceInterface
     */
    public function getBackupService(): BackupServiceInterface {
        if (!isset($this->services['BackupService'])) {
            require_once __DIR__ . '/BackupService.php';
            $this->services['BackupService'] = new BackupService(
                $this->currentUser,
                $this->pdo
            );
        }
        return $this->services['BackupService'];
    }

    /**
     * Get BalanceService instance
     *
     * Handles balance-related operations including user balance retrieval,
     * contact balance calculations, and contact information conversion.
     * Extracted from TransactionService as part of God Class refactoring.
     *
     * @return BalanceServiceInterface
     */
    public function getBalanceService(): BalanceServiceInterface {
        if (!isset($this->services['BalanceService'])) {
            require_once __DIR__ . '/BalanceService.php';
            $this->services['BalanceService'] = new BalanceService(
                $this->getBalanceRepository(),
                $this->getTransactionContactRepository(),
                $this->getAddressRepository(),
                $this->getUtilityContainer()->getCurrencyUtility(),
                $this->currentUser
            );
        }
        return $this->services['BalanceService'];
    }

    /**
     * Get ChainVerificationService instance
     *
     * Handles verification of transaction chain integrity before new transactions
     * are created. Coordinates with SyncService to repair chains when gaps exist.
     * Extracted from TransactionService as part of God Class refactoring.
     *
     * @return ChainVerificationServiceInterface
     */
    public function getChainVerificationService(): ChainVerificationServiceInterface {
        if (!isset($this->services['ChainVerificationService'])) {
            require_once __DIR__ . '/ChainVerificationService.php';
            $this->services['ChainVerificationService'] = new ChainVerificationService(
                $this->getTransactionChainRepository(),
                $this->currentUser,
                $this->getLogger()
            );
        }
        return $this->services['ChainVerificationService'];
    }

    /**
     * Get TransactionValidationService instance
     *
     * Handles all validation logic for transaction processing including
     * previous transaction ID validation, funds checking, and full
     * transaction possibility validation with proactive sync.
     * Extracted from TransactionService as part of God Class refactoring.
     *
     * @return TransactionValidationServiceInterface
     */
    public function getTransactionValidationService(): TransactionValidationServiceInterface {
        if (!isset($this->services['TransactionValidationService'])) {
            require_once __DIR__ . '/TransactionValidationService.php';
            $this->services['TransactionValidationService'] = new TransactionValidationService(
                $this->getTransactionRepository(),
                $this->getContactRepository(),
                $this->getUtilityContainer()->getValidationUtility(),
                $this->getInputValidator(),
                $this->getTransactionPayload(),
                $this->currentUser,
                $this->getLogger()
            );
        }
        return $this->services['TransactionValidationService'];
    }

    /**
     * Get TransactionProcessingService instance
     *
     * Handles core transaction processing logic including incoming transactions,
     * pending transactions, and P2P transactions.
     * Extracted from TransactionService as part of God Class refactoring.
     *
     * @return TransactionProcessingServiceInterface
     */
    public function getTransactionProcessingService(): TransactionProcessingServiceInterface {
        if (!isset($this->services['TransactionProcessingService'])) {
            require_once __DIR__ . '/TransactionProcessingService.php';
            $this->services['TransactionProcessingService'] = new TransactionProcessingService(
                $this->getTransactionRepository(),
                $this->getTransactionRecoveryRepository(),
                $this->getTransactionChainRepository(),
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getBalanceRepository(),
                $this->getTransactionPayload(),
                $this->getUtilityContainer()->getTransportUtility(),
                $this->getUtilityContainer()->getTimeUtility(),
                $this->currentUser,
                $this->getLogger(),
                $this->getMessageDeliveryService()
            );
        }
        return $this->services['TransactionProcessingService'];
    }

    /**
     * Get SendOperationService instance
     *
     * Handles high-level send operation orchestration for eIOU transactions.
     * Manages direct sends, P2P routing, transaction message delivery,
     * and distributed locking for concurrent send prevention.
     * Extracted from TransactionService as part of God Class refactoring.
     *
     * @return SendOperationServiceInterface
     */
    public function getSendOperationService(): SendOperationServiceInterface {
        if (!isset($this->services['SendOperationService'])) {
            require_once __DIR__ . '/SendOperationService.php';
            $this->services['SendOperationService'] = new SendOperationService(
                $this->getTransactionRepository(),
                $this->getAddressRepository(),
                $this->getP2pRepository(),
                $this->getTransactionPayload(),
                $this->getUtilityContainer()->getTransportUtility(),
                $this->getUtilityContainer()->getTimeUtility(),
                $this->getInputValidator(),
                $this->currentUser,
                $this->getLogger(),
                $this->getMessageDeliveryService(),
                $this->getLockingService()
            );
        }
        return $this->services['SendOperationService'];
    }

    /**
     * Get UtilityServiceContainer instance
     *
     *
     * @return UtilityServiceContainer
     */
    public function getUtilityContainer(): UtilityServiceContainer {
        if (!isset($this->services['UtilityServiceContainer'])) {
            require_once __DIR__ . '/utilities/UtilityServiceContainer.php';
            $this->services['UtilityServiceContainer'] = new UtilityServiceContainer($this);
        }
        return $this->services['UtilityServiceContainer'];
    }

    /**
     * Get InputValidator instance
     *
     *
     * @return InputValidator
     */
    public function getInputValidator(): InputValidator {
        if (!isset($this->utils['InputValidator'])) {
            require_once '/etc/eiou/src/utils/InputValidator.php';
            $this->utils['InputValidator'] = new InputValidator();
        }
        return $this->utils['InputValidator'];
    }

    /**
     * Get logger instance
     *
     * @return SecureLogger
     */
    public function getLogger(): SecureLogger {
        if (!isset($this->utils['SecureLogger'])) {
            require_once '/etc/eiou/src/utils/SecureLogger.php';
            $secureLogger = new SecureLogger();
            $secureLogger->init(Constants::LOG_FILE_APP, Constants::LOG_LEVEL);
            $this->utils['SecureLogger'] = $secureLogger;
        }
        return $this->utils['SecureLogger'];
    }
    /**
     * Get Security instance
     *
     *
     * @return Security
     */
    public function getSecurity(): Security {
        if (!isset($this->utils['Security'])) {
            require_once '/etc/eiou/src/utils/Security.php';
            $this->utils['Security'] = new Security();
        }
        return $this->utils['Security'];
    }

    /**
     * Get TransactionPayload instance
     *
     * @return TransactionPayload
     */
    public function getTransactionPayload(): TransactionPayload {
        if (!isset($this->utils['TransactionPayload'])) {
            require_once '/etc/eiou/src/schemas/payloads/TransactionPayload.php';
            $this->utils['TransactionPayload'] = new TransactionPayload($this->currentUser, $this->getUtilityContainer());
        }
        return $this->utils['TransactionPayload'];
    }

    /**
     * Clear all cached services (useful for testing)
     */
    public function clearServices(): void {
        $this->services = [];
    }

    /**
     * Clear all cached utils (useful for testing)
     */
    public function clearUtils(): void {
        $this->utils = [];
    }

    /**
     * Wire circular dependencies between services using setter injection
     *
     * This method should be called after all services that have circular dependencies
     * are initialized. It uses setter injection to break circular dependency cycles
     * while maintaining the lazy loading pattern.
     *
     * Circular dependencies handled:
     * - TransactionService <--> SyncService
     * - SyncService <--> HeldTransactionService
     * - ContactService --> SyncService
     * - ContactStatusService --> SyncService, RateLimiterService
     * - MessageService --> SyncService
     * - HeldTransactionService --> SyncService
     * - Rp2pService --> TransactionService
     * - TransactionService --> P2pService, ContactService
     * - ChainVerificationService --> SyncService
     * - TransactionValidationService --> SyncService, TransactionService
     * - TransactionProcessingService --> SyncService, P2pService, HeldTransactionService
     * - SendOperationService --> ContactService, P2pService, SyncService, TransactionService
     *
     * Note: Repositories are now passed via constructor injection, not setter injection.
     */
    public function wireCircularDependencies(): void {
        // Wire TransactionService <-> SyncService
        if (isset($this->services['TransactionService']) && isset($this->services['SyncService'])) {
            $this->services['TransactionService']->setSyncService($this->services['SyncService']);
        }

        // Wire SyncService <-> HeldTransactionService
        if (isset($this->services['SyncService']) && isset($this->services['HeldTransactionService'])) {
            $this->services['SyncService']->setHeldTransactionService($this->services['HeldTransactionService']);
        }

        // Wire HeldTransactionService -> SyncService
        if (isset($this->services['HeldTransactionService']) && isset($this->services['SyncService'])) {
            $this->services['HeldTransactionService']->setSyncService($this->services['SyncService']);
        }

        // Wire ContactService -> SyncService
        if (isset($this->services['ContactService']) && isset($this->services['SyncService'])) {
            $this->services['ContactService']->setSyncService($this->services['SyncService']);
        }

        // Wire ContactStatusService -> SyncService, RateLimiterService
        if (isset($this->services['ContactStatusService'])) {
            if (isset($this->services['SyncService'])) {
                $this->services['ContactStatusService']->setSyncService($this->services['SyncService']);
            }
            if (isset($this->services['RateLimiterService'])) {
                $this->services['ContactStatusService']->setRateLimiterService($this->services['RateLimiterService']);
            }
        }

        // Wire MessageService -> SyncService
        if (isset($this->services['MessageService']) && isset($this->services['SyncService'])) {
            $this->services['MessageService']->setSyncService($this->services['SyncService']);
        }

        // Wire Rp2pService -> TransactionService
        if (isset($this->services['Rp2pService']) && isset($this->services['TransactionService'])) {
            $this->services['Rp2pService']->setTransactionService($this->services['TransactionService']);
        }

        // Wire TransactionService -> P2pService, ContactService, and new refactored services
        if (isset($this->services['TransactionService'])) {
            if (isset($this->services['P2pService'])) {
                $this->services['TransactionService']->setP2pService($this->services['P2pService']);
            }
            if (isset($this->services['ContactService'])) {
                $this->services['TransactionService']->setContactService($this->services['ContactService']);
            }
            // Wire the 5 new refactored services to TransactionService facade
            if (isset($this->services['BalanceService'])) {
                $this->services['TransactionService']->setBalanceService($this->services['BalanceService']);
            }
            if (isset($this->services['ChainVerificationService'])) {
                $this->services['TransactionService']->setChainVerificationService($this->services['ChainVerificationService']);
            }
            if (isset($this->services['TransactionValidationService'])) {
                $this->services['TransactionService']->setTransactionValidationService($this->services['TransactionValidationService']);
            }
            if (isset($this->services['TransactionProcessingService'])) {
                $this->services['TransactionService']->setTransactionProcessingService($this->services['TransactionProcessingService']);
            }
            if (isset($this->services['SendOperationService'])) {
                $this->services['TransactionService']->setSendOperationService($this->services['SendOperationService']);
            }
        }

        // Wire ChainVerificationService -> SyncService
        if (isset($this->services['ChainVerificationService']) && isset($this->services['SyncService'])) {
            $this->services['ChainVerificationService']->setSyncService($this->services['SyncService']);
        }

        // Wire TransactionValidationService -> SyncService, TransactionService
        if (isset($this->services['TransactionValidationService'])) {
            if (isset($this->services['SyncService'])) {
                $this->services['TransactionValidationService']->setSyncService($this->services['SyncService']);
            }
            if (isset($this->services['TransactionService'])) {
                $this->services['TransactionValidationService']->setTransactionService($this->services['TransactionService']);
            }
        }

        // Wire TransactionProcessingService -> SyncService, P2pService, HeldTransactionService
        if (isset($this->services['TransactionProcessingService'])) {
            if (isset($this->services['SyncService'])) {
                $this->services['TransactionProcessingService']->setSyncService($this->services['SyncService']);
            }
            if (isset($this->services['P2pService'])) {
                $this->services['TransactionProcessingService']->setP2pService($this->services['P2pService']);
            }
            if (isset($this->services['HeldTransactionService'])) {
                $this->services['TransactionProcessingService']->setHeldTransactionService($this->services['HeldTransactionService']);
            }
        }

        // Wire SendOperationService -> ContactService, P2pService, SyncService, TransactionService
        if (isset($this->services['SendOperationService'])) {
            if (isset($this->services['ContactService'])) {
                $this->services['SendOperationService']->setContactService($this->services['ContactService']);
            }
            if (isset($this->services['P2pService'])) {
                $this->services['SendOperationService']->setP2pService($this->services['P2pService']);
            }
            if (isset($this->services['SyncService'])) {
                $this->services['SendOperationService']->setSyncService($this->services['SyncService']);
            }
            if (isset($this->services['TransactionService'])) {
                $this->services['SendOperationService']->setTransactionService($this->services['TransactionService']);
            }
            // Set TransactionChainRepository for chain verification
            $this->services['SendOperationService']->setTransactionChainRepository($this->getTransactionChainRepository());
        }
    }

    /**
     * Initialize all services and wire circular dependencies
     *
     * This method eagerly initializes all commonly used services and wires up
     * their circular dependencies. Call this when you want to ensure all services
     * are ready and properly connected before processing requests.
     *
     * IMPORTANT: This method MUST be called before using services with circular
     * dependencies. Services no longer fall back to Application::getInstance()
     * and will throw RuntimeException if dependencies are not wired.
     */
    public function wireAllServices(): void {
        // Initialize core services (order matters for some dependencies)
        $this->getSyncService();
        $this->getHeldTransactionService();
        $this->getContactService();
        $this->getTransactionService();
        $this->getP2pService();
        $this->getRp2pService();
        $this->getMessageService();
        $this->getContactStatusService();
        $this->getRateLimiterService();
        $this->getTransactionRecoveryService();

        // Initialize new refactored services
        $this->getBalanceService();
        $this->getChainVerificationService();
        $this->getTransactionValidationService();
        $this->getTransactionProcessingService();
        $this->getSendOperationService();

        // Wire circular dependencies
        $this->wireCircularDependencies();
    }

    /**
     * Register a custom service instance
     *
     * @param string $name Service name
     * @param mixed $instance Service instance
     */
    public function registerService(string $name, $instance): void {
        $this->services[$name] = $instance;
    }

    /**
     * Register a custom util instance
     *
     * @param string $name util name
     * @param mixed $instance Util instance
     */
    public function registerUtil(string $name, $instance): void {
        $this->utils[$name] = $instance;
    }

    /**
     * Check if a service is registered
     *
     * @param string $name Service name
     * @return bool True if service exists
     */
    public function hasService(string $name): bool {
        return isset($this->services[$name]);
    }

    /**
     * Check if a util is registered
     *
     * @param string $name Util name
     * @return bool True if Util exists
     */
    public function hasUtil(string $name): bool {
        return isset($this->utils[$name]);
    }

    /**
     * Get a registered service by name
     *
     * @param string $name Service name
     * @return mixed Service instance or null
     */
    public function getService(string $name) {
        return $this->services[$name] ?? null;
    }

    /**
     * Get a registered util by name
     *
     * @param string $name Util name
     * @return mixed Util instance or null
     */
    public function getUtil(string $name) {
        return $this->utils[$name] ?? null;
    }

    /**
     * Prevent cloning of singleton
     *
     * @return void
     */
    private function __clone(): void {}

    /**
     * Prevent unserialization of singleton
     *
     * @throws Exception Always throws to prevent unserialization
     */
    public function __wakeup(): void {
        throw new Exception("Cannot unserialize singleton");
    }
}