<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Core;

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
    // NOTE: Set APP_ENV to 'production' and APP_DEBUG to false for production deployments
    const APP_ENV = 'development';
    const APP_VERSION = '0.0.1';
    const APP_DEBUG = true;

    // Rate limiting
    // WARNING: RATE_LIMIT_ENABLED should always be true in production.
    // Only set to false for debugging during development.
    const RATE_LIMIT_ENABLED = true;

    // API
    const API_ENABLED = true;
    const API_CORS_ALLOWED_ORIGINS = ''; // Comma-separated list of allowed origins. Empty = no CORS headers sent (blocks cross-origin browser requests). '*' = allow all origins (not recommended for production)
    //  With the current empty default (''):
    //   - Same-origin GUI: Works fine
    //   - CLI tools: Works fine
    //   - Mobile apps: Works fine
    //   - Browser extensions: BROKEN - will get CORS errors
    //   - External web applications: BROKEN - will get CORS errors

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

    // Contact status processor configuration
    // Set CONTACT_STATUS_ENABLED to false to disable contact pinging entirely
    // When disabled, all contacts will show 'unknown' status
    // Can be overridden by EIOU_CONTACT_STATUS_ENABLED environment variable
    const CONTACT_STATUS_ENABLED = true;

    /**
     * Check if contact status feature is enabled
     * Supports runtime override via EIOU_CONTACT_STATUS_ENABLED env variable
     * Used during testing to disable pings that interfere with sync tests
     *
     * @return bool Whether contact status polling is enabled
     */
    public static function isContactStatusEnabled(): bool {
        $envValue = getenv('EIOU_CONTACT_STATUS_ENABLED');
        if ($envValue !== false) {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }
        return self::CONTACT_STATUS_ENABLED;
    }
    const CONTACT_STATUS_POLLING_INTERVAL_MS = 300000;  // 5 minutes between ping cycles
    const CONTACT_STATUS_MIN_INTERVAL_MS = 300000;      // Minimum 5 minutes
    const CONTACT_STATUS_MAX_INTERVAL_MS = 1800000;     // Maximum 30 minutes
    const CONTACT_STATUS_IDLE_INTERVAL_MS = 300000;     // Idle at 5 minutes
    const CONTACT_STATUS_ADAPTIVE_POLLING = true;
    const CONTACT_STATUS_SYNC_ON_PING = true;           // Whether to trigger sync check on ping

    // Transaction Recovery configuration
    const RECOVERY_SENDING_TIMEOUT_SECONDS = 120; // Transactions stuck in 'sending' for this long are recovered
    const RECOVERY_MAX_RETRY_COUNT = 3; // Max times a transaction can be recovered before manual review
    const RECOVERY_LOCK_TIMEOUT_SECONDS = 300; // Lock timeout for processor exclusive access

    // Crypto/Security
    const HASH_ALGORITHM = 'sha256'; // Do not change

    // Network
    const DEFAULT_TRANSPORT_MODE = 'tor';
    const VALID_TRANSPORT_INDICES = ['http', 'https', 'tor'];

    // P2P Network Configuration
    // These constants control the peer-to-peer transaction routing system
    const P2P_DEFAULT_MAX_REQUEST_LEVEL = 6;  // Default maximum routing hops per user preference

    // P2P Request Level Randomization
    // The minimum request level is randomized to prevent network traffic analysis attacks.
    // Formula: abs(rand(RANGE_LOW, RANGE_HIGH) - rand(RANDOM_LOW, RANDOM_HIGH)) + rand(OFFSET_LOW, OFFSET_HIGH)
    // This creates overlapping random distributions that produce unpredictable but bounded values.
    // The randomization prevents attackers from correlating request patterns across the network.
    const P2P_MIN_REQUEST_LEVEL_RANGE_LOW = 300;    // Base range lower bound
    const P2P_MIN_REQUEST_LEVEL_RANGE_HIGH = 700;   // Base range upper bound
    const P2P_MIN_REQUEST_LEVEL_RANDOM_LOW = 200;   // Subtraction range lower bound
    const P2P_MIN_REQUEST_LEVEL_RANDOM_HIGH = 500;  // Subtraction range upper bound
    const P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_LOW = 1;   // Final offset lower bound
    const P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_HIGH = 10; // Final offset upper bound

    const P2P_DEFAULT_EXPIRATION_SECONDS = 300; // 5 minutes - time before P2P request expires
    const P2P_MIN_EXPIRATION_SECONDS = 60; // Minimum expiration time to allow network propagation
    const P2P_REQUEST_LEVEL_VALIDATION_MAX = 1000; // Maximum valid request level for input validation
    const P2P_MAX_ROUTING_LEVEL = 10; // Maximum allowed P2P routing hops (user setting cap)
    const P2P_HOP_WAIT_DIVISOR = 12; // Fixed divisor for hopWait formula (hides actual max level for privacy)
    const P2P_HOP_PROCESSING_BUFFER_SECONDS = 2; // Per-hop buffer for network latency and processing time
    const P2P_TOR_EXPIRATION_MULTIPLIER = 2; // Tor hidden services need longer expiration (6 Tor hops per EIOU hop)
    const P2P_QUEUE_BATCH_SIZE = 10; // Max queued P2Ps processed per daemon poll cycle (all sent in one curl_multi)
    const P2P_QUEUE_COALESCE_MS = 2000; // Milliseconds to wait for more P2Ps before firing mega-batch (default: 2000ms)
    const CURL_MULTI_MAX_CONCURRENT_HTTP = 10; // Max simultaneous HTTP/HTTPS connections per curl_multi batch
    const CURL_MULTI_MAX_CONCURRENT_TOR = 5;  // Max simultaneous Tor connections per curl_multi batch (Tor circuits overload easily)

    // Transport timeouts (single HTTP/TOR request to a node)
    const HTTP_TRANSPORT_TIMEOUT_SECONDS = 15; // Max time for one HTTP request between nodes
    const TOR_TRANSPORT_TIMEOUT_SECONDS = 30;  // Max time for one TOR request between nodes

    // Minimum per-hop wait: based on a single HTTP round-trip (send P2P + receive RP2P).
    // Must be at least the transport timeout so a relay never expires before a
    // back-and-forth with its direct contact can complete.
    const P2P_MIN_HOP_WAIT_SECONDS = self::HTTP_TRANSPORT_TIMEOUT_SECONDS;

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
    const STATUS_SENDING = 'sending'; // Claimed for processing, prevents duplicates
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

    // Contact online status values
    const CONTACT_ONLINE_STATUS_ONLINE = 'online';
    const CONTACT_ONLINE_STATUS_OFFLINE = 'offline';
    const CONTACT_ONLINE_STATUS_UNKNOWN = 'unknown';

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
    const AUTO_REFRESH_ENABLED = false; // Default OFF - user must enable in settings

    // File paths (relative to project root)
    const PATH_CONFIG_DIR = '/etc/eiou/';

    const LOG_FILE_APP = '/var/log/eiou/app.log';
    const LOG_LEVEL = 'INFO';
    const LOG_MAX_ENTRIES = 100;

    // Backup configuration
    const BACKUP_AUTO_ENABLED = true;        // Whether automated daily backups are enabled by default
    const BACKUP_RETENTION_COUNT = 3;         // Number of backups to keep (3 most recent)
    const BACKUP_DIRECTORY = '/var/lib/eiou/backups';
    const BACKUP_FILE_EXTENSION = '.eiou.enc';
    const BACKUP_CRON_HOUR = 0;              // Hour to run backup (0 = midnight)
    const BACKUP_CRON_MINUTE = 0;            // Minute to run backup

    // Queue/Processor batch sizes
    // Controls how many items are fetched and processed per daemon poll cycle
    const P2P_EXPIRING_BATCH_SIZE = 5;             // Max expiring P2P messages per cleanup cycle (default: 5)
    const RECOVERY_PENDING_BATCH_SIZE = 5;         // Max pending recovery transactions per cycle (default: 5)
    const RECOVERY_IN_PROGRESS_BATCH_SIZE = 10;    // Max in-progress recovery transactions per cycle (default: 10)
    const DELIVERY_RETRY_BATCH_SIZE = 10;          // Max failed deliveries retried per cycle (default: 10)
    const DELIVERY_EXHAUSTED_BATCH_SIZE = 10;      // Max exhausted-retry deliveries moved to DLQ per cycle (default: 10)
    const DLQ_BATCH_SIZE = 50;                     // Max dead letter queue items fetched per query (default: 50)
    const HELD_TX_BATCH_SIZE = 10;                 // Max held transactions processed per cycle (default: 10)
    const HELD_TX_EXHAUSTED_BATCH_SIZE = 10;       // Max exhausted held transactions fetched per cycle (default: 10)

    // Display/Query limits
    const DISPLAY_RECENT_TRANSACTIONS_LIMIT = 5;   // Max recent transactions shown in lists (default: 5)
    const DISPLAY_RECENT_CONTACTS_LIMIT = 5;       // Max recent contacts shown in lists (default: 5)
    const CONTACT_TRANSACTIONS_LIMIT = 5;          // Max transactions per contact in combined queries (default: 5)
    const BALANCE_TRANSACTION_LIMIT = 5;           // Max transactions used for balance conversion (default: 5)
    const CHAIN_DROP_PROPOSALS_LIMIT = 20;         // Max chain drop proposals per contact query (default: 20)

    // Debug logging limits
    const DEBUG_RECENT_ENTRIES_LIMIT = 100;        // Max recent debug entries per query (default: 100)
    const DEBUG_ALL_ENTRIES_MAX = 10000;            // Max total debug entries returned (default: 10000)
    const DEBUG_PRUNE_KEEP_COUNT = 100;            // Number of debug entries to keep when pruning (default: 100)

    // Message delivery configuration
    const DELIVERY_MAX_RETRIES = 5;                // Max delivery retry attempts before moving to DLQ (default: 5)
    const DELIVERY_BASE_DELAY_SECONDS = 2;         // Base delay between retries in seconds (default: 2)
    const DELIVERY_JITTER_FACTOR = 0.2;            // Random jitter factor for retry delay (default: 0.2)
    const DLQ_ALERT_THRESHOLD = 10;                // DLQ item count that triggers alerts (default: 10)

    // Data retention / cleanup (days unless noted)
    const CLEANUP_DELIVERY_RETENTION_DAYS = 30;    // Days to keep delivery records (default: 30)
    const CLEANUP_DLQ_RETENTION_DAYS = 90;         // Days to keep dead letter queue records (default: 90)
    const CLEANUP_HELD_TX_RETENTION_DAYS = 7;      // Days to keep resolved held transactions (default: 7)
    const CLEANUP_RP2P_RETENTION_DAYS = 30;        // Days to keep RP2P response records (default: 30)
    const CLEANUP_RP2P_CANDIDATE_RETENTION_DAYS = 1; // Days to keep RP2P candidate records (default: 1)
    const CLEANUP_P2P_SENDER_RETENTION_DAYS = 1;   // Days to keep P2P sender records (default: 1)
    const CLEANUP_P2P_RELAYED_RETENTION_DAYS = 1;  // Days to keep P2P relayed contact records (default: 1)
    const CLEANUP_METRICS_RETENTION_DAYS = 90;     // Days to keep delivery metrics (default: 90)
    const CLEANUP_RATE_LIMIT_SECONDS = 3600;       // Seconds before expired rate limit entries are cleaned (default: 3600)

    // Database locking
    const DB_LOCK_TIMEOUT_SECONDS = 30;            // Max seconds to wait for a database lock (default: 30)

    // Rate limiting defaults
    const RATE_LIMIT_MAX_ATTEMPTS = 10;            // Max attempts before rate-limiting kicks in (default: 10)
    const RATE_LIMIT_WINDOW_SECONDS = 60;          // Time window in seconds for counting attempts (default: 60)
    const RATE_LIMIT_BLOCK_SECONDS = 300;          // Seconds to block after limit exceeded (default: 300)

    /**
     * Check if automatic backups are enabled
     * Supports runtime override via EIOU_BACKUP_AUTO_ENABLED env variable
     *
     * @return bool Whether automatic backups are enabled
     */
    public static function isAutoBackupEnabled(): bool {
        $envValue = getenv('EIOU_BACKUP_AUTO_ENABLED');
        if ($envValue !== false) {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }
        return self::BACKUP_AUTO_ENABLED;
    }

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
        $reflection = new \ReflectionClass(self::class);
        return $reflection->getConstants();
    }
}