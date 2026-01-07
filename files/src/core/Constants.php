<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

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
    const API_CORS_ALLOWED_ORIGINS = ''; // Comma-separated list of allowed origins, empty = none, '*' = all (not recommended)

    // Transaction limits
    const TRANSACTION_MAX_AMOUNT = 999999999;
    const TRANSACTION_DEFAULT_CURRENCY = 'USD';
    const TRANSACTION_MINIMUM_FEE = 0.01;
    const TRANSACTION_USD_CONVERSION_FACTOR = 100; // Store cents as integers

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

    // Crypto/Security
    const HASH_ALGORITHM = 'sha256'; // Do not change

    // Network
    const DEFAULT_TRANSPORT_MODE = 'tor';
    const VALID_TRANSPORT_INDICES = ['http', 'tor'];

    // P2P Network
    const P2P_DEFAULT_MAX_REQUEST_LEVEL = 6;
    const P2P_MIN_REQUEST_LEVEL_RANGE_LOW = 300;
    const P2P_MIN_REQUEST_LEVEL_RANGE_HIGH = 700;
    const P2P_MIN_REQUEST_LEVEL_RANDOM_LOW = 200;
    const P2P_MIN_REQUEST_LEVEL_RANDOM_HIGH = 500;
    const P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_LOW = 1;
    const P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_HIGH = 10;
    const P2P_DEFAULT_EXPIRATION_SECONDS = 300; // 5 minutes
    const P2P_MIN_EXPIRATION_SECONDS = 60; // Minimum expiration time
    const P2P_REQUEST_LEVEL_VALIDATION_MAX = 1000;
    const P2P_MAX_ROUTING_LEVEL = 20; // Maximum allowed P2P routing hops

    // Contact management
    const CONTACT_DEFAULT_FEE_PERCENT = 0.1;
    const CONTACT_DEFAULT_FEE_PERCENT_MAX = 5;
    const CONTACT_DEFAULT_CREDIT_LIMIT = 1000;
    const CONTACT_MAX_NAME_LENGTH = 255;
    const CONTACT_MIN_NAME_LENGTH = 2;

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

    // Time conversion factors
    const TIME_MICROSECONDS_TO_INT = 10000;
    const TIME_SECONDS_PER_MINUTE = 60;
    const TIME_MINUTES_PER_HOUR = 60;
    const TIME_HOURS_PER_DAY = 24;

    // Percentage/Math constants
    const CREDIT_CONVERSION_FACTOR = 100;
    const FEE_CONVERSION_FACTOR = 100; // Convert percentage to basis points (0.1% = 10)
    const FEE_PERCENT_DECIMAL_PRECISION = 2;

    // Adaptive polling thresholds
    const ADAPTIVE_POLLING_QUEUE_DIVISOR = 100;
    const ADAPTIVE_POLLING_MIN_FACTOR = 0.1;

    // Transaction/P2P Status values
    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REJECTED = 'rejected';
    const STATUS_EXPIRED = 'expired';
    const STATUS_PAID = 'paid';
    const STATUS_FAILED = 'failed';
    const STATUS_INITIAL = 'initial';
    const STATUS_QUEUED = 'queued';
    const STATUS_PROCESSED = 'processed';

    // Transaction direction types
    const TX_TYPE_SENT = 'sent';
    const TX_TYPE_RECEIVED = 'received';

    // Contact status values
    const CONTACT_STATUS_PENDING = 'pending';
    const CONTACT_STATUS_ACCEPTED = 'accepted';
    const CONTACT_STATUS_BLOCKED = 'blocked';

    // Message delivery stages
    const DELIVERY_PENDING = 'pending';
    const DELIVERY_SENT = 'sent';
    const DELIVERY_COMPLETED = 'completed';
    const DELIVERY_FAILED = 'failed';
    const DELIVERY_RECEIVED = 'received';
    const DELIVERY_INSERTED = 'inserted';
    const DELIVERY_FORWARDED = 'forwarded';
    const DELIVERY_ACKNOWLEDGED = 'acknowledged';
    const DELIVERY_WARNING = 'warning';
    const DELIVERY_UPDATED = 'updated';
    const DELIVERY_REJECTED = 'rejected';

    // UI/Display
    const DISPLAY_DATE_FORMAT = 'Y-m-d H:i:s.u';
    const DISPLAY_CURRENCY_DECIMALS = 2;
    const DISPLAY_DEFAULT_OUTPUT_LINES_MAX = 5;

    // File paths (relative to project root)
    const PATH_CONFIG_DIR = '/etc/eiou/';

    const LOG_FILE_APP = '/var/log/eiou/app.log';
    const LOG_LEVEL = 'INFO';
    const LOG_MAX_ENTRIES = 100;

    /**
     * Get a constant value with optional default
     *
     * @param string $key Constant name
     * @param mixed $default Default value if constant doesn't exist
     * @return mixed
     */
    public static function get($key, $default = null) {
        $constantName = self::class . '::' . $key;
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