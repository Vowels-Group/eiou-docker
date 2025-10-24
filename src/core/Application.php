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
    private array $processors = [];

    /**
     * @var array Cached util instances
     */
    private array $utils = [];

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        // Get constants
        $this->loadConfiguration();

        // Setup database
        $this->constructDatabase();

        // Get logger wrapper
        $this->getLogger();

        // Get user data
        $this->loadUser();

        // // Get Database connection
         $this->getDatabase();

        // Start services
        $this->loadserviceContainer();
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

    // /**
    //  * Get database connection (lazy loaded)
    //  *
    //  * @return PDO|null
    //  */
    // public function getDatabase() {
    //     require_once '/etc/eiou/src/database/pdo.php';
    //     try{
    //         $this->pdo = createPDOConnection($this->currentUser);
    //     } catch (Exception $e) {
    //         $this->utils['SecureLogger']->logException($e,'ERROR');
    //     }
        
    // }

    /**
     * Set database connection (for testing)
     *
     * @param PDO $pdo
     */
    public function setDatabase($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Load user from config
     */
    public function loadUser() {
        require_once '/etc/eiou/src/core/UserContext.php';
        $this->currentUser = UserContext::getInstance();
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
    private function loadserviceContainer() {
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
        if ($this->pdo) {
            $this->pdo = null;
        }
        $this->services->clearServices();
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