<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Core;

use Eiou\Utils\Logger;
use Eiou\Utils\InputValidator;
use Eiou\Utils\Security;
use Eiou\Cli\CliOutputManager;
use Eiou\Services\ServiceContainer;
use Eiou\Services\RateLimiterService;
use Eiou\Processors\CleanupMessageProcessor;
use Eiou\Processors\P2pMessageProcessor;
use Eiou\Processors\TransactionMessageProcessor;
use Eiou\Processors\ContactStatusProcessor;
use PDO;
use Exception;
use RuntimeException;
use function Eiou\Database\createPDOConnection;
use function Eiou\Database\freshInstall;
use function Eiou\Database\runMigrations;

/**
 * Application singleton to manage global state
 * Replaces global variables with proper encapsulation
 */

class Application {
    private static ?Application $instance = null;

    /**
     * @var UserContext object of user data
     */
    protected $currentUser;

    /**
     * @var DbContext Database context instance
     */
    protected $currentDatabase;

    /**
     * @var PDO Database connection instance
     */
    protected $pdo;
    
    /**
     * @var ServiceContainer object of service container
     */
    public $services;

    /**
     * @var UtilityServiceContainer UtilityServiceContainer instance
     */
    public $utilityServices;

    /**
     * @var array Cached processor instances
     */
    public array $processors = [];

    /**
     * @var array Cached util instances
     */
    public array $utils = [];

    /**
     * Private constructor for singleton pattern
     *
     * @throws RuntimeException If database setup fails during initialization
     */
    private function __construct() {
        // Get logger wrapper
        $this->getLogger();

        // Setup database
        if(!file_exists('/etc/eiou/config/dbconfig.json')){
            // Performs a fresh installation of the eIOU system by creating db configuration files, database, and necessary tables
            try {
                $this->constructDatabase();
                $this->loadCurrentDatabase();
            } catch (RuntimeException $e) {
                if ($this->loggerLoaded()) {
                    $this->getLogger()->critical("Application: Database setup failed", [
                        'error' => $e->getMessage()
                    ]);
                } else {
                    Logger::getInstance()->logException($e, [], 'CRITICAL');
                }
                // If database setup fails, we cannot continue initialization
                throw new RuntimeException("Failed to initialize application: " . $e->getMessage(), 0, $e);
            }
        } elseif(!$this->currentDatabaseLoaded()){
            // Get DatabaseContext instance
            $this->loadCurrentDatabase();
        }

        // Start PDO connection
        $this->getDatabase();

        // Run migrations for existing databases to add new tables
        $this->runMigrations();

        // Setup user config
        if(file_exists('/etc/eiou/config/userconfig.json') && !$this->currentUserLoaded()){
            // Get UserContext instance
            $this->loadCurrentUser();
            // Get ServiceContainer instance
            $this->loadserviceContainer();
            $this->loadUtilityServiceContainer();
            // Wire circular dependencies between services
            $this->services->wireAllServices();

            // Run transaction recovery only for CLI/daemon processes (not HTTP API requests)
            // This prevents unnecessary recovery runs and potential race conditions on every API call
            // Recovery is only needed when message processor daemons start up after a crash
            if ($this->isCli()) {
                $this->runTransactionRecovery();
            }
        }
    }

    /**
     * Get singleton instance
     *
     * @return Application
     */
    public static function getInstance(): Application {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Create Database
     */
    private function constructDatabase(): void {
        freshInstall();
    }

    /**
     * Get database connection (lazy loaded)
     *
     * @return void
     */
    private function getDatabase(): void {
        try{
            $this->pdo = createPDOConnection();
        } catch (Exception $e) {
            Logger::getInstance()->logException($e, [], 'ERROR');
        }
    }

    /**
     * Run database migrations to add new tables
     * This is idempotent - safe to run on every startup
     */
    private function runMigrations(): void {
        if ($this->pdo === null) {
            return;
        }

        try {
            $results = runMigrations($this->pdo);

            // Log any newly created tables
            foreach ($results as $table => $status) {
                if ($status === 'created') {
                    if ($this->loggerLoaded()) {
                        $this->getLogger()->info("Migration: Created table $table");
                    }
                }
            }
        } catch (Exception $e) {
            if ($this->loggerLoaded()) {
                $this->getLogger()->warning("Migration failed", [
                    'error' => $e->getMessage()
                ]);
            }
            // Don't throw - migrations failing shouldn't prevent app startup
        }
    }

    /**
     * Run transaction recovery to handle stuck transactions from previous crashes
     *
     * This method recovers transactions that were in 'sending' status when the
     * process crashed. It resets them to 'pending' for retry or marks them for
     * manual review if they've exceeded max retry attempts.
     */
    private function runTransactionRecovery(): void {
        if (!isset($this->services)) {
            return;
        }

        try {
            $recoveryService = $this->services->getTransactionRecoveryService();
            $results = $recoveryService->recoverStuckTransactions();

            // Log recovery results
            if ($results['recovered'] > 0 || $results['needs_review'] > 0) {
                if ($this->loggerLoaded()) {
                    $this->getLogger()->info("Transaction recovery completed on startup", [
                        'recovered' => $results['recovered'],
                        'needs_review' => $results['needs_review'],
                        'errors' => $results['errors']
                    ]);
                }

                // Output to console for visibility during startup
                if (function_exists('output')) {
                    if ($results['recovered'] > 0) {
                        output("[Recovery] Recovered {$results['recovered']} stuck transactions", 'INFO');
                    }
                    if ($results['needs_review'] > 0) {
                        output("[Recovery] {$results['needs_review']} transactions need manual review", 'WARNING');
                    }
                }
            }
        } catch (Exception $e) {
            if ($this->loggerLoaded()) {
                $this->getLogger()->warning("Transaction recovery failed on startup", [
                    'error' => $e->getMessage()
                ]);
            }
            // Don't throw - recovery failing shouldn't prevent app startup
        }
    }

    /**
     * Check if database connection has been started
     *
     * @return bool
     */
    public function currentPdoLoaded(): bool {
        return(isset($this->pdo) && $this->pdo instanceof PDO);
    }

    /**
     * Set database connection (for testing)
     *
     * @param PDO $pdo
     * @return void
     */
    public function setDatabase($pdo): void {
        $this->pdo = $pdo;
    }

    /**
     * Check if DatabaseContext has been loaded
     *
     * @return bool
     */
    public function currentDatabaseLoaded(): bool {
        return(isset($this->currentDatabase) && $this->currentDatabase !== []);
    }

    /**
     * Load current database config
     *
     * @return void
     */
    private function loadCurrentDatabase(): void {
        $this->currentDatabase = DatabaseContext::getInstance();
    }


    /**
     * Check if userContext has been loaded
     *
     * @return bool
     */
    public function currentUserLoaded(): bool {
        return(isset($this->currentUser) && $this->currentUser !== []);
    }

    /**
     * Load current user config
     *
     * @return void
     */
    public function loadCurrentUser(): void {
        $this->currentUser = UserContext::getInstance();
    }

    /**
     * Get public key from loaded user
     *
     * @return string|null
     */
    public function getPublicKey(): ?string {
        if($this->currentUserLoaded()){
            return $this->currentUser->getPublicKey();
        }
        return null;
    }

    /**
     * Get public key hash from loaded user
     *
     * @return string|null
     */
    public function getPublicKeyHash(): ?string {
        if($this->currentUserLoaded()){
            return $this->currentUser->getPublicKeyHash();
        }
        return null;
    }

    /**
     * Generate Wallet from CLI input
     *
     * @param array $argv CLI input
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function generateWallet(array $argv, ?CliOutputManager $output = null): void {
        Wallet::generateWallet($argv, $output);
    }

    /**
     * Restore Wallet from CLI input
     *
     * @param array $argv CLI input
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function restoreWallet(array $argv, ?CliOutputManager $output = null): void {
        Wallet::restoreWallet($argv, $output);
    }

    /**
     * Load services from serviceContainer
     *
     * @return void
     */
    public function loadserviceContainer(): void {
        $this->services = ServiceContainer::getInstance($this->currentUser, $this->pdo);
    }

    /**
     * Load utility services from ServiceContainer
     *
     * @return void
     */
    public function loadUtilityServiceContainer(): void {
        $this->utilityServices = $this->services->getUtilityContainer();
    }

    /**
     * Get rate limiter service instance
     *
     * @return RateLimiterService
     */
    public function getRateLimiter(): RateLimiterService {
        return $this->services->getRateLimiterService();
    }

    /**
     * Get InputValidator instance
     *
     *
     * @return InputValidator
     */
    public function getInputValidator(): InputValidator {
        if (!isset($this->utils['InputValidator'])) {
            $this->utils['InputValidator'] = new InputValidator();
        }
        return $this->utils['InputValidator'];
    }

    /**
     * Check if Logger has been loaded
     *
     * @return bool
     */
    public function loggerLoaded(): bool {
        return isset($this->utils['Logger']);
    }

    /**
     * Get logger instance
     *
     * @return Logger
     */
    public function getLogger(): Logger {
        if (!isset($this->utils['Logger'])) {
            Logger::init(Constants::LOG_FILE_APP, Constants::LOG_LEVEL);
            $this->utils['Logger'] = Logger::getInstance();
        }
        return $this->utils['Logger'];
    }

    /**
     * Get Security instance
     *
     * @return Security
     */
    public function getSecurity(): Security {
        if (!isset($this->utils['Security'])) {
            $this->utils['Security'] = new Security();
        }
        return $this->utils['Security'];
    }

    /**
     * Get CleanupMessageProcessor instance
     *
     * @return CleanupMessageProcessor
     */
    public function getCleanupMessageProcessor(): CleanupMessageProcessor {
         if (!isset($this->processors['cleanupProcessor'])) {
            $this->processors['cleanupProcessor'] = new CleanupMessageProcessor();
         }
         return $this->processors['cleanupProcessor'];
    }
    /**
     * Get P2pMessageProcessor instance
     *
     * @return P2pMessageProcessor
     */
    public function getP2pMessageProcessor(): P2pMessageProcessor {
         if (!isset($this->processors['p2pProcessor'])) {
            $this->processors['p2pProcessor'] = new P2pMessageProcessor();
         }
         return $this->processors['p2pProcessor'];
    }

    /**
     * Get TransactionMessageProcessor instance
     *
     * @return TransactionMessageProcessor
     */
    public function getTransactionMessageProcessor(): TransactionMessageProcessor {
          if (!isset($this->processors['transactionProcessor'])) {
            $this->processors['transactionProcessor'] = new TransactionMessageProcessor();
         }
         return $this->processors['transactionProcessor'];
    }

    /**
     * Get ContactStatusProcessor instance
     *
     * @return ContactStatusProcessor
     */
    public function getContactStatusProcessor(): ContactStatusProcessor {
         if (!isset($this->processors['contactStatusProcessor'])) {
            $this->processors['contactStatusProcessor'] = new ContactStatusProcessor();
         }
         return $this->processors['contactStatusProcessor'];
    }

    /**
     * Register a service
     *
     * @deprecated Use ServiceContainer::registerService() instead via $app->services->registerService()
     * @param string $name
     * @param object|callable $service
     * @return void
     */
    public function registerService($name, $service): void {
        // Delegate to ServiceContainer
        if ($this->services instanceof ServiceContainer) {
            $this->services->registerService($name, $service);
        }
    }

    /**
     * Get a service
     *
     * @deprecated Use ServiceContainer::getService() instead via $app->services->getService()
     * @param string $name
     * @return mixed
     */
    public function getService($name): mixed {
        // Delegate to ServiceContainer
        if ($this->services instanceof ServiceContainer) {
            return $this->services->getService($name);
        }
        return null;
    }

    /**
     * Check if running in CLI mode
     *
     * @return bool
     */
    public function isCli(): bool {
        return php_sapi_name() === 'cli';
    }

    /**
     * Check if in development mode
     *
     * @return bool
     */
    public function isDevelopment(): bool {
        return Constants::APP_ENV === 'development';
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function isDebug(): bool {
        return Constants::APP_DEBUG === true;
    }

    /**
     * Get application root path
     *
     * @return string
     */
    public function getRootPath(): string {
        return dirname(__DIR__, 2);
    }

    /**
     * Get config directory path
     *
     * @return string
     */
    public function getConfigPath(): string {
        return Constants::PATH_CONFIG_DIR ?: '/etc/eiou';
    }

    /**
     * Reload processor configs
     *
     * @return void
     */
    public function reloadProcessors(): void {
        $items = glob('/tmp/' . '*.pid');
        foreach ($items as $item) {
            if (is_file($item)) {
                posix_kill(trim(file_get_contents($item)), SIGHUP);
            }
        }
    }

    /**
     * Clean up resources
     *
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function shutdown(?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Set shutdown flag to prevent watchdog from restarting processors
        $shutdownFlag = '/tmp/eiou_shutdown.flag';
        file_put_contents($shutdownFlag, (string)time());

        $pidFiles = glob('/tmp/' . '*.pid');
        $processesTerminated = 0;

        foreach ($pidFiles as $item) {
            if (is_file($item)) {
                $pid = trim(file_get_contents($item));
                if (posix_kill((int)$pid, SIGTERM)) {
                    $processesTerminated++;
                }
                unlink($item);
            }
        }

        $this->processors = [];
        if ($this->services) {
            $this->services->getUtilityContainer()->clearUtilities();
            $this->services->clearServices();
        }
        $this->utils = [];
        $this->currentUser = null;
        if ($this->pdo) {
            $this->pdo = null;
        }

        $output->success("Application shutdown complete", [
            'processes_terminated' => $processesTerminated,
            'pid_files_cleaned' => count($pidFiles),
            'resources_released' => true
        ], "All resources have been released");
    }

    /**
     * Start processors after a previous shutdown
     *
     * Removes the shutdown flag so the watchdog resumes monitoring and
     * restarts processors on its next cycle.
     *
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function start(?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        $shutdownFlag = '/tmp/eiou_shutdown.flag';
        $wasShutdown = file_exists($shutdownFlag);

        if (!$wasShutdown) {
            $output->success("Processors are already running", [
                'shutdown_flag_present' => false,
                'action' => 'none'
            ], "No shutdown flag found — processors are already active");
            return;
        }

        unlink($shutdownFlag);

        $output->success("Processor restart initiated", [
            'shutdown_flag_removed' => true,
            'action' => 'watchdog_will_restart'
        ], "Shutdown flag removed. The watchdog will restart all processors within 30 seconds");
    }

    /**
     * Prevent cloning (singleton pattern)
     *
     * @return void
     */
    private function __clone(): void {}

    /**
     * Prevent unserialization (singleton pattern)
     *
     * @throws Exception Always throws to prevent unserialization
     */
    public function __wakeup(): void {
        throw new Exception("Cannot unserialize singleton");
    }
}