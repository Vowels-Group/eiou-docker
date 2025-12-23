<?php
# Copyright 2025 The Vowels Company

/**
 * Application singleton to manage global state
 * Replaces global variables with proper encapsulation
 */

require_once("/etc/eiou/src/utils/SecureLogger.php");
require_once("/etc/eiou/src/cli/CliOutputManager.php");

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
     */
    private function __construct() {
        // Get logger wrapper
        $this->getLogger();

        // Setup database
        if(!file_exists('/etc/eiou/dbconfig.json')){
            // Performs a fresh installation of the eIOU system by creating db configuration files, database, and necessary tables
            try {
                $this->constructDatabase();
                $this->loadCurrentDatabase();
            } catch (RuntimeException $e) {
                if ($this->secureLoggerLoaded()) {
                    $this->getLogger()->critical("Application: Database setup failed", [
                        'error' => $e->getMessage()
                    ]);
                } else {
                    SecureLogger::logException($e, 'CRITICAL');
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
        if(file_exists('/etc/eiou/userconfig.json') && !$this->currentUserLoaded()){
            // Get UserContext instance
            $this->loadCurrentUser();
            // Get ServiceContainer instance
            $this->loadserviceContainer();
            $this->loadUtilityServiceContainer();
        }
    }

    /**
     * Get singleton instance
     *
     * @return Application
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Create Database
     */
    private function constructDatabase() {
        require_once '/etc/eiou/src/database/DatabaseSetup.php';
        freshInstall();
    }

    /**
     * Get database connection (lazy loaded)
     *
     * @return PDO|null
     */
    private function getDatabase() {
        require_once '/etc/eiou/src/database/Pdo.php';
        try{
            $this->pdo = createPDOConnection();
        } catch (Exception $e) {
            $this->utils['SecureLogger']->logException($e,'ERROR');
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
            require_once '/etc/eiou/src/database/DatabaseSetup.php';
            $results = runMigrations($this->pdo);

            // Log any newly created tables
            foreach ($results as $table => $status) {
                if ($status === 'created') {
                    if ($this->secureLoggerLoaded()) {
                        $this->getLogger()->info("Migration: Created table $table");
                    }
                }
            }
        } catch (Exception $e) {
            if ($this->secureLoggerLoaded()) {
                $this->getLogger()->warning("Migration failed", [
                    'error' => $e->getMessage()
                ]);
            }
            // Don't throw - migrations failing shouldn't prevent app startup
        }
    }

    /**
     * Check if database connection has been started
     *
     */
    public function currentPdoLoaded() {
        return(isset($this->pdo) && $this->pdo instanceof PDO);
    }

    /**
     * Set database connection (for testing)
     *
     * @param PDO $pdo
     */
    public function setDatabase($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Check if DatabaseContext has been loaded
     */
    public function currentDatabaseLoaded() {
        return(isset($this->currentDatabase) && $this->currentDatabase !== []);
    }

    /**
     * Load current database config
     */
    private function loadCurrentDatabase() {
        require_once '/etc/eiou/src/core/DatabaseContext.php';
        $this->currentDatabase = DatabaseContext::getInstance();
    }


    /**
     * Check if userContext has been loaded
     */
    public function currentUserLoaded() {
        return(isset($this->currentUser) && $this->currentUser !== []);
    }

    /**
     * Load current user config
     */
    public function loadCurrentUser() {
        require_once '/etc/eiou/src/core/UserContext.php';
        $this->currentUser = UserContext::getInstance();
    }

    /**
     * Get public key from loaded user
     * 
     * @return string|null
     */
    public function getPublicKey() {
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
    public function getPublicKeyHash() {
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
        require_once '/etc/eiou/src/core/Wallet.php';
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
        require_once '/etc/eiou/src/core/Wallet.php';
        Wallet::restoreWallet($argv, $output);
    }

    /**
     * Load services from serviceContainer
     */
    public function loadserviceContainer() {
        require_once $this->getRootPath() . '/src/services/ServiceContainer.php';
        $this->services = ServiceContainer::getInstance($this->currentUser, $this->pdo);
    }

    /**
     * Load utility services from utilityServiceContainer
     */
    public function loadUtilityServiceContainer() {
        require_once $this->getRootPath() . '/src/services/utilities/UtilityServiceContainer.php';
        $this->utilityServices = UtilityServiceContainer::getInstance($this->services);
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
            require_once $this->getRootPath() . '/src/utils/InputValidator.php';
            $this->utils['InputValidator'] = new InputValidator();
        }
        return $this->utils['InputValidator'];
    }

    /**
     * Check if SecureLogger has been loaded
     */
    public function secureLoggerLoaded() {
        return isset($this->utils['SecureLogger']);
    }

    /**
     * Get logger instance
     *
     * @return SecureLogger
     */
    public function getLogger(): SecureLogger {
        if (!isset($this->utils['SecureLogger'])) {
            require_once $this->getRootPath() . '/src/utils/SecureLogger.php';
            $secureLogger = new SecureLogger();
            $secureLogger->init(Constants::LOG_FILE_APP, Constants::LOG_LEVEL);
            $this->utils['SecureLogger'] = $secureLogger;
        }
        return $this->utils['SecureLogger'];
    }

    /**
     * Get Security instance
     *
     * @return Security
     */
    public function getSecurity(): Security {
        if (!isset($this->utils['Security'])) {
            require_once $this->getRootPath() . '/src/utils/Security.php';
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
            require_once $this->getRootPath() . '/src/processors/CleanupMessageProcessor.php';
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
            require_once $this->getRootPath() . '/src/processors/P2pMessageProcessor.php';
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
            require_once $this->getRootPath() . '/src/processors/TransactionMessageProcessor.php';
            $this->processors['transactionProcessor'] = new TransactionMessageProcessor();
         }
         return $this->processors['transactionProcessor'];
    }

    /**
     * Register a service
     *
     * @param string $name
     * @param object|callable $service
     */
    public function registerService($name, $service) {
        $this->services[$name] = $service;
    }

    /**
     * Get a service
     *
     * @param string $name
     * @return mixed
     */
    public function getService($name) {
        if (!isset($this->services[$name])) {
            return null;
        }

        if (is_callable($this->services[$name])) {
            // Lazy load service
            $this->services[$name] = call_user_func($this->services[$name]);
        }
        return $this->services[$name];
    }

    /**
     * Check if running in CLI mode
     *
     * @return bool
     */
    public function isCli() {
        return php_sapi_name() === 'cli';
    }

    /**
     * Check if in development mode
     *
     * @return bool
     */
    public function isDevelopment() {
        return Constants::APP_ENV === 'development';
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function isDebug() {
        return Constants::APP_DEBUG === true;
    }

    /**
     * Get application root path
     *
     * @return string
     */
    public function getRootPath() {
        return dirname(__DIR__, 2);
    }

    /**
     * Get config directory path
     *
     * @return string
     */
    public function getConfigPath() {
        return Constants::PATH_CONFIG_DIR ?: '/etc/eiou';
    }

    /**
     * Reload processor configs
     */
    public function reloadProcessors() {
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
     * Prevent cloning (singleton pattern)
     */
    private function __clone() {}

    /**
     * Prevent unserialization (singleton pattern)
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}