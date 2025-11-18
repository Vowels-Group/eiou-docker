<?php
# Copyright 2025

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
     * @param UserContext $currentUser Current user data
     * @param PDO $pdo Database connection
     */
    private function __construct(
        UserContext $currentUser,
        PDO $pdo
    ) {
        $this->currentUser = $currentUser;
        $this->pdo = $pdo;
    }

    /**
     * Get singleton instance
     *
     * @return ServiceContainer
     */
    public static function getInstance($currentUser, $pdo): ServiceContainer {
        if (self::$instance === null) {
            self::$instance = new self($currentUser, $pdo);
        }
        return self::$instance;
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
     * Get PDO instance
     *
     * @return PDO Database connection
     */
    public function getPdo(): PDO {
        return $this->pdo;
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
     * Get ContactService instance
     *
     * @return ContactService
     */
    public function getContactService(): ContactService {
        if (!isset($this->services['ContactService'])) {
            require_once __DIR__ . '/ContactService.php';
            $this->services['ContactService'] = new ContactService(
                $this->getContactRepository(),
                $this->getBalanceRepository(),
                $this->getUtilityContainer(),
                $this->getInputValidator(),
                $this->getLogger(),
                $this->currentUser
            );
        }
        return $this->services['ContactService'];
    }

    /**
     * Get TransactionService instance
     *
     * @return TransactionService
     */
    public function getTransactionService(): TransactionService {
        if (!isset($this->services['TransactionService'])) {
            require_once __DIR__ . '/TransactionService.php';
            $this->services['TransactionService'] = new TransactionService(
                $this->getContactRepository(),
                $this->getBalanceRepository(),
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getTransactionRepository(),
                $this->getUtilityContainer(),
                $this->getInputValidator(),
                $this->getLogger(),
                $this->currentUser
            );
        }
        return $this->services['TransactionService'];
    }

    /**
     * Get P2pService instance
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
                $this->currentUser
            );
        }
        return $this->services['P2pService'];
    }

    /**
     * Get R2pService instance
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
                $this->currentUser
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
                $this->currentUser
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
     * Get SynchService instance
     *
     * @return SynchService
     */
    public function getSynchService(): SynchService {
        if (!isset($this->services['SynchService'])) {
            require_once __DIR__ . '/SynchService.php';
            $this->services['SynchService'] = new SynchService(
                $this->getContactRepository(),
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getTransactionRepository(),
                $this->getUtilityContainer(),
                $this->currentUser
            );
        }
        return $this->services['SynchService'];
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