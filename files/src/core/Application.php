<?php
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
    protected $serviceContainer;

    /**
     * @var UtilityServiceContainer UtilityServiceContainer instance
     */
    protected $utilityService;

    public ServiceContainer $services;

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
        // Setup database
        if(!file_exists('/etc/eiou/dbconfig.json')){
            // Performs a fresh installation of the eIOU system by creating db configuration files, database, and necessary tables
            try {
                $this->constructDatabase();
                $this->loadCurrentDatabase();
            } catch (RuntimeException $e) {
                if (class_exists('SecureLogger')) {
                    SecureLogger::critical("Application: Database setup failed", [
                        'error' => $e->getMessage()
                    ]);
                } else {
                    error_log("Application: Database setup failed - " . $e->getMessage());
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

        // Setup user config
        if(file_exists('/etc/eiou/userconfig.json') && !$this->currentUserLoaded()){
            // Get UserContext instance
            $this->loadCurrentUser();
            // Get ServiceContainer instance
            $this->loadserviceContainer();
        }
        
        // Get logger wrapper
        $this->getLogger();
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
        require_once '/etc/eiou/src/database/databaseSetup.php';
        freshInstall();
    }

    /**
     * Get database connection (lazy loaded)
     *
     * @return PDO|null
     */
    private function getDatabase() {
        require_once '/etc/eiou/src/database/pdo.php';
        try{
            $this->pdo = createPDOConnection();
        } catch (Exception $e) {
            $this->utils['SecureLogger']->logException($e,'ERROR');
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
     * Get auth code loaded user
     * 
     * @return string|null
     */
    public function getAuthCode() {
        if($this->currentUserLoaded()){
            return $this->currentUser->getAuthCode();
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
     * Generate Wallet from CLI input
     * 
     * @param array $argv CLI input
     * @return void
     */
    public function generateWallet(array $argv): void {
        require_once '/etc/eiou/src/core/Wallet.php';
        Wallet::generateWallet($argv);
    }

    /**
     * Load services from serviceContainer
     */
    public function loadserviceContainer() {
        require_once $this->getRootPath() . '/src/services/ServiceContainer.php';
        $this->services = ServiceContainer::getInstance($this->currentUser, $this->pdo);
    }

    /**
     * Get rate limiter instance
     *
     * @return RateLimiter
     */
    public function getRateLimiter(): RateLimiter {
        if (!isset($this->utils['rateLimiter'])) {
            require_once $this->getRootPath() . '/src/utils/RateLimiter.php';
            $this->utils['rateLimiter'] = new RateLimiter($this->pdo);
        }
        return $this->utils['rateLimiter'];
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
     */
    public function shutdown() {
        $items = glob('/tmp/' . '*.pid');
        foreach ($items as $item) {
            if (is_file($item)) {
                posix_kill(trim(file_get_contents($item)), SIGTERM);
            }
        }
        $this->processors = [];
        $this->services->getUtilityContainer()->clearUtilities();
        $this->services->clearServices();
        $this->utils = [];
        $this->currentUser = null;
        if ($this->pdo) {
            $this->pdo = null;
        }
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