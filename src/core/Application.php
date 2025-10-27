<?php
/**
 * Application singleton to manage global state
 * Replaces global variables with proper encapsulation
 */

class Application {
    private static ?Application $instance = null;

    /**
     * @var Constants object of constants data
     */
    protected $envVariables;

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
        // Get constants
        $this->loadConfiguration();

        // Setup database
        if(!file_exists('/etc/eiou/dbconfig.php')){
            // Performs a fresh installation of the eIOU system by creating db configuration files, database, and necessary tables
            $this->constructDatabase();
            $this->loadCurrentDatabase();
        } elseif(!$this->currentDatabaseLoaded()){
            // Get DatabaseContext instance
            $this->loadCurrentDatabase();
        }

        // Start PDO connection
        $this->getDatabase();

        // Setup user config
        if(file_exists('/etc/eiou/userconfig.php') && !$this->currentUserLoaded()){
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
     * Load configuration from constants
     */
    private function loadConfiguration() {
        require_once $this->getRootPath() . '/src/core/Constants.php';
        $this->envVariables = Constants::getInstance();
    }

    /**
     * Load services from serviceContainer
     */
    public function loadserviceContainer() {
        require_once $this->getRootPath() . '/src/services/ServiceContainer.php';
        $this->services = ServiceContainer::getInstance();
    }

    /**
     * Get rate limiter instance
     *
     * @return RateLimiter|null
     */
    public function getRateLimiter() {
        if (!isset($this->utils['rateLimiter'])) {
            require_once $this->getRootPath() . '/src/utils/RateLimiter.php';
            $this->utils['rateLimiter'] = new RateLimiter($this->pdo);
        }
        return $this->utils['rateLimiter'];
    }

    /**
     * Get logger instance
     *
     * @return SecureLogger
     */
    public function getLogger() {
        if (!isset($this->utils['SecureLogger'])) {
            require_once $this->getRootPath() . '/src/utils/SecureLogger.php';
            SecureLogger::init($this->envVariables->get('LOG_FILE_APP'), $this->envVariables->get('LOG_LEVEL'));
            $this->utils['SecureLogger'] = new SecureLogger();
        }
        $this->utils['SecureLogger'];
    }

    /**
     * Get CleanupMessageProcessor instance
     *
     * @return CleanupMessageProcessor
     */
    public function getCleanupMessageProcessor() {
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
    public function getP2pMessageProcessor() {
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
    public function getTransactionMessageProcessor() {
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
        return $this->envVariables->get('APP_ENV') === 'development';
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function isDebug() {
        return $this->envVariables->get('APP_DEBUG') === true;
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
        return $this->envVariables->get('PATH_CONFIG_DIR') ?: '/etc/eiou';
    }

    /**
     * Clean up resources
     */
    public function shutdown() {
        foreach($this->processors as $processor_name => $processor_instance){
            $success = posix_kill(trim(file_get_contents($processor_instance->lockfile)), SIGTERM);
        }
        $this->processors = [];
        $this->services->getUtilityContainer()->clearUtilities();
        $this->services->clearServices();
        $this->utils = [];
        $this->currentUser = null;
        if ($this->pdo) {
            $this->pdo = null;
        }
        $this->envVariables = null;
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