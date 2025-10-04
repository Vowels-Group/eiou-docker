<?php
/**
 * Application-wide constants and configuration values
 * Replaces magic numbers throughout the codebase
 */

class Constants {

    // Transaction limits
    const TRANSACTION_MIN_AMOUNT = 0;
    const TRANSACTION_MAX_AMOUNT = 999999999;
    const TRANSACTION_DEFAULT_CURRENCY = 'USD';
    const TRANSACTION_USD_CONVERSION_FACTOR = 100; // Store cents as integers

    // Time intervals (milliseconds)
    const POLLING_MIN_INTERVAL_MS = 100;
    const POLLING_MAX_INTERVAL_MS = 5000;
    const POLLING_IDLE_INTERVAL_MS = 2000;
    const CLEANUP_MIN_INTERVAL_MS = 1000;
    const CLEANUP_MAX_INTERVAL_MS = 30000;
    const CLEANUP_IDLE_INTERVAL_MS = 10000;

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

    // Crypto/Security
    const HASH_ALGORITHM = 'sha256';
    const RANDOM_BYTES_LENGTH = 32;
    const CSRF_TOKEN_LENGTH = 64; // 32 bytes hex encoded

    // P2P Network
    const P2P_DEFAULT_REQUEST_LEVEL = 1;
    const P2P_MAX_REQUEST_LEVEL = 10;
    const P2P_MIN_REQUEST_LEVEL_RANGE_LOW = 300;
    const P2P_MIN_REQUEST_LEVEL_RANGE_HIGH = 700;
    const P2P_MIN_REQUEST_LEVEL_RANDOM_LOW = 200;
    const P2P_MIN_REQUEST_LEVEL_RANDOM_HIGH = 500;
    const P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_LOW = 1;
    const P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_HIGH = 10;

    // Contact management
    const CONTACT_DEFAULT_FEE_PERCENT = 0;
    const CONTACT_DEFAULT_CREDIT_LIMIT = 0;
    const CONTACT_MAX_NAME_LENGTH = 255;
    const CONTACT_MAX_ADDRESS_LENGTH = 255;

    // UI/Display
    const DISPLAY_TRANSACTION_HISTORY_LIMIT = 10;
    const DISPLAY_DATE_FORMAT = 'Y-m-d H:i:s';
    const DISPLAY_CURRENCY_DECIMALS = 2;

    // File paths (relative to project root)
    const PATH_LOCKFILE_PREFIX = '/tmp/';
    const PATH_LOG_DIR = '/var/log/eiou/';
    const PATH_CONFIG_DIR = '/etc/eiou/';

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