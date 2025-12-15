<?php
/**
 * Application-wide constants and configuration values
 * Replaces magic numbers throughout the codebase
 */

# Performance tuning notes:
# - Lower MIN_INTERVAL for faster response times (costs more CPU)
# - Higher MAX_INTERVAL for better CPU efficiency when idle
# - IDLE_INTERVAL is used when no work for extended period
# - Set ADAPTIVE_POLLING=false to use fixed intervals only

class Constants {
    private static ?Constants $instance = null;
    private array $envVariables = [];
    private bool $initialized = false;

    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct() {
        $this->envVariables = $this->all();
        $this->initialized = true;
    }

    /**
     * Get singleton instance
     *
     * @return Constants
     */
    public static function getInstance(): Constants {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Development/Production
    const APP_ENV = 'development';
    const APP_VERSION = '0.0.1';
    const APP_DEBUG = true;

    // API
    const API_ENABLED = true;

    // Transaction limits
    const TRANSACTION_MIN_AMOUNT = 0;
    const TRANSACTION_MAX_AMOUNT = 999999999;
    const TRANSACTION_DEFAULT_CURRENCY = 'USD';
    const TRANSACTION_MINIMUM_FEE = 0.01;
    const TRANSACTION_USD_CONVERSION_FACTOR = 100; // Store cents as integers

    // Time intervals (milliseconds)
    const POLLING_MIN_INTERVAL_MS = 100;
    const POLLING_MAX_INTERVAL_MS = 5000;
    const POLLING_IDLE_INTERVAL_MS = 2000;

    // Transaction processor polling intervals (milliseconds)
    const TRANSACTION_MIN_INTERVAL_MS = 100;
    const TRANSACTION_MAX_INTERVAL_MS = 5000;
    const TRANSACTION_IDLE_INTERVAL_MS = 2000;
    const TRANSACTION_ADAPTIVE_POLLING = true;

    // P2P processor polling intervals (milliseconds)
    const P2P_MIN_INTERVAL_MS = 100;
    const P2P_MAX_INTERVAL_MS = 5000;
    const P2P_IDLE_INTERVAL_MS = 2000;
    const P2P_ADAPTIVE_POLLING = true;

    // Cleanup processor polling intervals (milliseconds)
    const CLEANUP_MIN_INTERVAL_MS = 1000;
    const CLEANUP_MAX_INTERVAL_MS = 30000;
    const CLEANUP_IDLE_INTERVAL_MS = 10000;
    const CLEANUP_ADAPTIVE_POLLING = true;
    const CLEANUP_LOG_INTERVAL_SECONDS = 300; // 5 minutes

    // Load thresholds for adaptive behavior
    const HIGH_LOAD_CPU = 80;
    const HIGH_LOAD_QUEUE = 100;
    const LOW_LOAD_QUEUE = 10;

    // Queue processing
    const QUEUE_BATCH_SIZE = 5;
    const QUEUE_MAX_RETRIES = 3;
    const QUEUE_RETRY_DELAY_MS = 1000;

    // Session configuration
    const SESSION_TIMEOUT_SECONDS = 3600; // 1 hour
    const SESSION_REGENERATION_INTERVAL = 300; // 5 minutes

    // Rate limiting defaults
    const RATE_LIMIT_API_MAX = 100;
    const RATE_LIMIT_API_WINDOW = 60;
    const RATE_LIMIT_API_BLOCK = 300;

    const RATE_LIMIT_LOGIN_MAX = 5;
    const RATE_LIMIT_LOGIN_WINDOW = 300;
    const RATE_LIMIT_LOGIN_BLOCK = 900;

    const RATE_LIMIT_TRANSACTION_MAX = 20;
    const RATE_LIMIT_TRANSACTION_WINDOW = 60;
    const RATE_LIMIT_TRANSACTION_BLOCK = 600;

    // Logging
    const LOG_MAX_FILE_SIZE = 10485760; // 10MB
    const LOG_ROTATION_DAYS = 30;
    const LOG_DEFAULT_LEVEL = 'INFO';

    // Database
    const DB_CONNECTION_TIMEOUT = 5;
    const DB_MAX_RETRIES = 3;
    const DB_RETRY_DELAY_SECONDS = 1;
    const DB_VARCHAR_TINY = 32;
    const DB_VARCHAR_SMALL = 64;
    const DB_VARCHAR_MEDIUM = 100;
    const DB_VARCHAR_STANDARD = 255;
    const DB_VARCHAR_LARGE = 500;
    const DB_QUERY_LIMIT_SINGLE = 1;

    // Crypto/Security
    const HASH_ALGORITHM = 'sha256'; // Do not change
    const RANDOM_BYTES_LENGTH = 32;
    const CSRF_TOKEN_LENGTH = 64; // 32 bytes hex encoded

    // Network
    const DEFAULT_TRANSPORT_MODE = 'tor';

    // P2P Network
    const P2P_DEFAULT_REQUEST_LEVEL = 1;
    const P2P_DEFAULT_MAX_REQUEST_LEVEL = 6;
    const P2P_MIN_REQUEST_LEVEL_RANGE_LOW = 300;
    const P2P_MIN_REQUEST_LEVEL_RANGE_HIGH = 700;
    const P2P_MIN_REQUEST_LEVEL_RANDOM_LOW = 200;
    const P2P_MIN_REQUEST_LEVEL_RANDOM_HIGH = 500;
    const P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_LOW = 1;
    const P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_HIGH = 10;
    const P2P_DEFAULT_EXPIRATION_SECONDS = 300; // 5 minutes
    const P2P_MIN_EXPIRATION_SECONDS = 60; // Minimum expiration time
    const P2P_MAX_ROUTING_LEVEL = 20; // Maximum allowed P2P routing hops
    const P2P_REQUEST_LEVEL_VALIDATION_MAX = 1000;

    // Contact management
    const CONTACT_DEFAULT_FEE_PERCENT = 0.1;
    const CONTACT_DEFAULT_FEE_PERCENT_MAX = 5;
    const CONTACT_DEFAULT_CREDIT_LIMIT = 1000;
    const CONTACT_MAX_NAME_LENGTH = 255;
    const CONTACT_MIN_NAME_LENGTH = 2;
    const CONTACT_MAX_ADDRESS_LENGTH = 255;
    const CONTACT_RATE_LIMIT_MAX = 10;
    const CONTACT_RATE_LIMIT_WINDOW = 60;
    const CONTACT_RATE_LIMIT_BLOCK = 300;

    // Validation limits
    const VALIDATION_PUBLIC_KEY_MIN_LENGTH = 100;
    const VALIDATION_SIGNATURE_MIN_LENGTH = 100;
    const VALIDATION_TOR_V3_ADDRESS_LENGTH = 56;
    const VALIDATION_TOR_V2_ADDRESS_LENGTH = 16;
    const VALIDATION_HASH_LENGTH_SHA256 = 64;
    const VALIDATION_CURRENCY_CODE_LENGTH = 3;
    const VALIDATION_MEMO_MAX_LENGTH = 500;
    const VALIDATION_FEE_MIN_PERCENT = 0;
    const VALIDATION_FEE_MAX_PERCENT = 100;
    const VALIDATION_STRING_MAX_LENGTH = 255;
    const VALIDATION_STRING_MIN_LENGTH = 1;

    // HTTP status codes
    const HTTP_OK = 200;
    const HTTP_BAD_REQUEST = 400;
    const HTTP_UNAUTHORIZED = 401;
    const HTTP_FORBIDDEN = 403;
    const HTTP_NOT_FOUND = 404;
    const HTTP_TOO_MANY_REQUESTS = 429;
    const HTTP_INTERNAL_SERVER_ERROR = 500;

    // Time conversion factors
    const TIME_MICROSECONDS_PER_MILLISECOND = 1000;
    const TIME_MICROSECONDS_TO_INT = 10000;
    const TIME_SECONDS_PER_MINUTE = 60;
    const TIME_MINUTES_PER_HOUR = 60;
    const TIME_HOURS_PER_DAY = 24;
    const TIME_ONE_MINUTE_SECONDS = 60;
    const TIME_FIVE_MINUTES_SECONDS = 300;
    const TIME_FIFTEEN_MINUTES_SECONDS = 900;
    const TIME_ONE_HOUR_SECONDS = 3600;

    // Percentage/Math constants
    const CREDIT_CONVERSION_FACTOR = 100;
    const FEE_CONVERSION_FACTOR = 100; // Convert percentage to basis points (0.1% = 10)
    const FEE_PERCENT_DECIMAL_PRECISION = 2;

    // Adaptive polling thresholds
    const ADAPTIVE_POLLING_EMPTY_CYCLES_HIGH = 10;
    const ADAPTIVE_POLLING_EMPTY_CYCLES_MID = 5;
    const ADAPTIVE_POLLING_QUEUE_SIZE_HIGH = 50;
    const ADAPTIVE_POLLING_QUEUE_DIVISOR = 100;
    const ADAPTIVE_POLLING_SUCCESS_MULTIPLIER = 1.2;
    const ADAPTIVE_POLLING_MIN_FACTOR = 0.1;

    // UI/Display
    const DISPLAY_TRANSACTION_HISTORY_LIMIT = 10;
    const DISPLAY_DATE_FORMAT = 'Y-m-d H:i:s.u';
    const DISPLAY_CURRENCY_DECIMALS = 2;
    const DISPLAY_ADDRESS_COLUMN_WIDTH = 56;
    const DISPLAY_NAME_COLUMN_WIDTH = 20;
    const DISPLAY_NAME_ADDRESS_COLUMN_WIDTH = 82;
    const DISPLAY_DEFAULT_OUTPUT_LINES_MAX = 5;

    // File paths (relative to project root)
    const PATH_LOCKFILE_PREFIX = '/tmp/';
    const PATH_LOG_DIR = '/var/log/eiou/';
    const PATH_CONFIG_DIR = '/etc/eiou/';

    const LOG_FILE_APP = '/var/log/eiou/app.log';
    const LOG_LEVEL = 'INFO';

    // Error messages
    const ERROR_GENERIC = "An error occurred. Please try again later.";
    const ERROR_INVALID_INPUT = "Invalid input provided.";
    const ERROR_DATABASE_CONNECTION = "Database connection failed.";
    const ERROR_UNAUTHORIZED = "Unauthorized access.";
    const ERROR_RATE_LIMITED = "Too many requests. Please try again later.";

    /**
     * Get a constant value with optional default
     *
     * @param string $key Constant name
     * @param mixed $default Default value if constant doesn't exist
     * @return mixed
     */
    public static function get($key, $default = null) {
        $constantName = 'self::' . $key;
        if (defined($constantName)) {
            return constant($constantName);
        }
        return $default;
    }

    /**
     * Get all constants as array
     *
     * @return array
     */
    public static function all() {
        $reflection = new ReflectionClass(self::class);
        return $reflection->getConstants();
    }
}