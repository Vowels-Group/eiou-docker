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
     * @var array Cached service instances
     */
    private array $services = [];

    /**
     * @var array Current user data
     */
    private array $currentUser = [];

    /**
     * @var PDO Database connection
     */
    private PDO $pdo;

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        $this->pdo = createPDOConnection();
        $this->loadCurrentUser();
    }

    /**
     * Get singleton instance
     *
     * @return ServiceContainer
     */
    public static function getInstance(): ServiceContainer {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load current user from global scope
     * This is a bridge to gradually transition away from global $user
     */
    private function loadCurrentUser(): void {
        global $user;
        $this->currentUser = $user ?? [];
    }

    /**
     * Set current user (for testing or manual injection)
     *
     * @param array $user User data
     */
    public function setCurrentUser(array $user): void {
        $this->currentUser = $user;
    }

    /**
     * Get current user
     *
     * @return array Current user data
     */
    public function getCurrentUser(): array {
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
     * Get ContactRepository instance
     *
     * @return ContactRepository
     */
    public function getContactRepository(): ContactRepository {
        if (!isset($this->services['ContactRepository'])) {
            $this->services['ContactRepository'] = new ContactRepository($this->pdo);
        }
        return $this->services['ContactRepository'];
    }

    /**
     * Get TransactionRepository instance
     *
     * @return TransactionRepository
     */
    public function getTransactionRepository(): TransactionRepository {
        if (!isset($this->services['TransactionRepository'])) {
            $this->services['TransactionRepository'] = new TransactionRepository($this->pdo);
        }
        return $this->services['TransactionRepository'];
    }

    /**
     * Get P2pRepository instance
     *
     * @return P2pRepository
     */
    public function getP2pRepository(): P2pRepository {
        if (!isset($this->services['P2pRepository'])) {
            $this->services['P2pRepository'] = new P2pRepository($this->pdo);
        }
        return $this->services['P2pRepository'];
    }

    /**
     * Get Rp2pRepository instance
     *
     * @return Rp2pRepository
     */
    public function getRp2pRepository(): Rp2pRepository {
        if (!isset($this->services['Rp2pRepository'])) {
            $this->services['Rp2pRepository'] = new Rp2pRepository($this->pdo);
        }
        return $this->services['Rp2pRepository'];
    }


    /**
     * Get DebugRepository instance
     *
     * @return DebugRepository
     */
    public function getDebugRepository(): DebugRepository {
        if (!isset($this->services['DebugRepository'])) {
            $this->services['DebugRepository'] = new DebugRepository($this->pdo);
        }
        return $this->services['DebugRepository'];
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
                $this->getTransactionRepository(),
                $this->getContactRepository(),
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
                $this->getP2pRepository(),
                $this->getContactRepository(),
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
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
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
            $this->services['WalletService'] = new WalletService($this->currentUser);
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
                $this->getP2pRepository(),
                $this->getTransactionRepository(),
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
     * Clear all cached services (useful for testing)
     */
    public function clearServices(): void {
        $this->services = [];
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
     * Check if a service is registered
     *
     * @param string $name Service name
     * @return bool True if service exists
     */
    public function hasService(string $name): bool {
        return isset($this->services[$name]);
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
