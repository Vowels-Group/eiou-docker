<?php
# Copyright 2025

require_once __DIR__ . '/../utils/SecureLogger.php';

/**
 * Service Container
 *
 * Centralized dependency injection container for managing service instances.
 *
 * @package Services
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
     * @return ServiceContainer
     */
    public static function getInstance(?UserContext $currentUser, ?PDO $pdo): ServiceContainer {
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
     * @return PDO|null
     */
    public function loadDatabase() {
        require_once '/etc/eiou/src/database/pdo.php';
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
     */
    public function getPdo(): PDO {
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
     * Get ContactService instance
     *
     * Integrates MessageDeliveryService for reliable contact message delivery
     * with retry logic and dead letter queue support.
     *
     * @return ContactService
     */
    public function getContactService(): ContactService {
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
     * @return TransactionService
     */
    public function getTransactionService(): TransactionService {
        if (!isset($this->services['TransactionService'])) {
            require_once __DIR__ . '/TransactionService.php';
            $this->services['TransactionService'] = new TransactionService(
                $this->getContactRepository(),
                $this->getAddressRepository(),
                $this->getBalanceRepository(),
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getTransactionRepository(),
                $this->getUtilityContainer(),
                $this->getInputValidator(),
                $this->getLogger(),
                $this->currentUser,
                $this->getMessageDeliveryService()
            );
        }
        return $this->services['TransactionService'];
    }

    /**
     * Get P2pService instance
     *
     * Integrates MessageDeliveryService for reliable P2P message delivery
     * with retry logic and dead letter queue support.
     *
     * @return P2pService
     */
    public function getP2pService(): P2pService {
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
    public function getRp2pService(): Rp2pService {
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
    public function getWalletService(): WalletService {
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
    public function getMessageService(): MessageService {
        if (!isset($this->services['MessageService'])) {
            require_once __DIR__ . '/MessageService.php';
            $this->services['MessageService'] = new MessageService(
                $this->getContactRepository(),
                $this->getBalanceRepository(),
                $this->getP2pRepository(),
                $this->getTransactionRepository(),
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
    public function getCleanupService(): CleanupService {
        if (!isset($this->services['CleanupService'])) {
            require_once __DIR__ . '/CleanupService.php';
            $this->services['CleanupService'] = new CleanupService(
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getTransactionRepository(),
                $this->getUtilityContainer(),
                $this->currentUser
            );
        }
        return $this->services['CleanupService'];
    }

    /**
     * Get SyncService instance
     *
     * @return SyncService
     */
    public function getSyncService(): SyncService {
        if (!isset($this->services['SyncService'])) {
            require_once __DIR__ . '/SyncService.php';
            $this->services['SyncService'] = new SyncService(
                $this->getContactRepository(),
                $this->getAddressRepository(),
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getTransactionRepository(),
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
    public function getDebugService(): DebugService {
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
    public function getMessageDeliveryService(): MessageDeliveryService {
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
     * Get CliService instance
     *
     * @return CliService
     */
    public function getCliService(): CliService {
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
    public function getRateLimiterService(): RateLimiterService {
        if (!isset($this->services['RateLimiterService'])) {
            require_once __DIR__ . '/RateLimiterService.php';
            $this->services['RateLimiterService'] = new RateLimiterService(
                $this->getRateLimiterRepository()
            );
        }
        return $this->services['RateLimiterService'];
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
     */
    private function __clone() {}

    /**
     * Prevent unserialization of singleton
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}