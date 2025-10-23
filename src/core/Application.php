<?php
/**
 * Application singleton to manage global state
 * Replaces global variables with proper encapsulation
 */

class Application {
    private static ?Application $instance = null;
    private RateLimiter $rateLimiter;
    private SecureLogger $logger;
    private ServiceContainer $services;

    /**
     * @var PDO Database connection instance
     */
    protected $pdo;

    /**
     * @var UserContext object of user data
     */
    protected $currentUser;

    /**
     * @var Constants object of constants data
     */
    protected $envVariables;

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        $this->loadConfiguration();
        $this->loadUser();
        $this->getDatabase();
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
     * Get database connection (lazy loaded)
     *
     * @return PDO|null
     */
    public function getDatabase() {
        if ($this->pdo === null) {
            try {
                require_once dirname(__DIR__, 2) . '/src/database/pdo.php';
                $this->pdo = createPDOConnection();
            } catch (Exception $e) {
                $this->logError("Database connection failed", $e);
                return null;
            }
        }
        return $this->pdo;
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
     * Load user from config
     */
    private function loadUser() {
        require_once dirname(__DIR__) . '/core/UserContext.php';
        $this->currentUser = UserContext::getInstance();
    }

    /**
     * Load configuration from constants
     */
    private function loadConfiguration() {
        // Load from environment variables 
        require_once dirname(__DIR__) . '/core/Constants.php';
        $this->envVariables = Constants::getInstance();
    }

    /**
     * Get rate limiter instance
     *
     * @return RateLimiter|null
     */
    public function getRateLimiter() {
        if ($this->rateLimiter === null && $this->getDatabase()) {
            require_once dirname(__DIR__) . '/utils/RateLimiter.php';
            $this->rateLimiter = new RateLimiter($this->getDatabase());
        }
        return $this->rateLimiter;
    }

    /**
     * Get logger instance
     *
     * @return SecureLogger
     */
    public function getLogger() {
        if ($this->logger === null) {
            require_once dirname(__DIR__) . '/utils/SecureLogger.php';
            SecureLogger::init($this->envVariables->get('LOG_FILE_APP'), $this->envVariables->get('LOG_LEVEL'));
            $this->logger = new SecureLogger();
        }
        return $this->logger;
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
     * Log (database) errors
     *
     * @param string $message Error message
     * @param PDOException|null $exception Exception object
     * @param string|null $query SQL query that failed
     */
    protected function logError(string $message, ?PDOException $exception = null, ?string $query = null): void {
        $logMessage = "[" . static::class . "] $message";

        if ($exception) {
            $logMessage .= ": " . $exception->getMessage();
        }

        if ($query) {
            $logMessage .= " | Query: $query";
        }

        error_log($logMessage);

        // Additional logging for development
        if ($this->envVariables->get('APP_DEBUG') === 'true' && $exception) {
            error_log("Stack trace: " . $exception->getTraceAsString());
        }
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