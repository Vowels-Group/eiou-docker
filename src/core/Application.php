<?php
/**
 * Application singleton to manage global state
 * Replaces global variables with proper encapsulation
 */

class Application {
    private static $instance = null;
    private $pdo = null;
    private $user = null;
    private $config = [];
    private $rateLimiter = null;
    private $logger = null;
    private $services = [];

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        $this->loadConfiguration();
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
     * Get current user
     *
     * @return array|null
     */
    public function getUser() {
        if ($this->user === null) {
            $this->loadUser();
        }
        return $this->user;
    }

    /**
     * Set current user
     *
     * @param array $user
     */
    public function setUser($user) {
        $this->user = $user;
    }

    /**
     * Load user from session or config
     */
    private function loadUser() {
        // Try session first
        if (isset($_SESSION['user'])) {
            $this->user = $_SESSION['user'];
            return;
        }

        // Try config file for CLI
        $configFile = $this->getConfigPath() . '/config.php';
        if (file_exists($configFile)) {
            require_once $configFile;
            if (isset($user)) {
                $this->user = $user;
            }
        }
    }

    /**
     * Get configuration value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getConfig($key, $default = null) {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set configuration value
     *
     * @param string $key
     * @param mixed $value
     */
    public function setConfig($key, $value) {
        $this->config[$key] = $value;
    }

    /**
     * Load configuration from files and environment
     */
    private function loadConfiguration() {
        // Load from environment variables
        $this->config['app_env'] = getenv('APP_ENV') ?: 'production';
        $this->config['app_debug'] = getenv('APP_DEBUG') === 'true';
        $this->config['log_level'] = getenv('LOG_LEVEL') ?: 'INFO';
        $this->config['log_file'] = getenv('LOG_FILE') ?: '/var/log/eiou/app.log';

        // Load from config file if exists
        $configFile = $this->getConfigPath() . '/app.config.php';
        if (file_exists($configFile)) {
            $fileConfig = require $configFile;
            $this->config = array_merge($this->config, $fileConfig);
        }
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
            SecureLogger::init($this->config['log_file'], $this->config['log_level']);
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
     * Log an error
     *
     * @param string $message
     * @param Exception $e
     */
    public function logError($message, $e = null) {
        if ($this->config['app_debug']) {
            echo "Error: $message\n";
            if ($e) {
                echo $e->getMessage() . "\n";
                echo $e->getTraceAsString() . "\n";
            }
        }

        // Log to file
        error_log($message . ($e ? ': ' . $e->getMessage() : ''));
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
        return $this->config['app_env'] === 'development';
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function isDebug() {
        return $this->config['app_debug'] === true;
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
        return getenv('CONFIG_PATH') ?: '/etc/eiou';
    }

    /**
     * Clean up resources
     */
    public function shutdown() {
        if ($this->pdo) {
            $this->pdo = null;
        }
        $this->services = [];
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