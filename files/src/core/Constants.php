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
    // APP_DEBUG defaults to true during alpha/development. Set APP_DEBUG=false in
    // docker-compose.yml for production deployments. Use Constants::isDebug() to
    // check debug state — it respects the env override.
    const APP_ENV = 'development';
    const APP_VERSION = '0.1.14-alpha';
    const MIN_COMPATIBLE_VERSION = '0.1.3-alpha';
    const APP_DEBUG = true;

    // Database schema version — bump this when adding new migrations in DatabaseSetup.php.
    // Migrations only run when the stored version (in /etc/eiou/config/.schema_version)
    // is lower than this value. After all migrations succeed the file is updated,
    // so subsequent requests skip migration queries entirely.
    const SCHEMA_VERSION = 11;

    // Rate limiting
    // WARNING: RATE_LIMIT_ENABLED should always be true in production.
    // Only set to false for debugging during development.
    const RATE_LIMIT_ENABLED = true;
    const P2P_RATE_LIMIT_PER_MINUTE = 60;

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
    // With split amount storage (whole + frac), the whole part can be up to PHP_INT_MAX.
    // However, relay routing adds fees to amounts (up to 100% per hop), so the amount
    // after fees must also fit. TRANSACTION_MAX_AMOUNT is set conservatively so that
    // even a 100% fee (amount doubles) plus multi-hop compounding won't overflow.
    // PHP_INT_MAX / 4 ≈ 2.3 quintillion — leaves headroom for multi-hop fee accumulation.
    const TRANSACTION_MAX_AMOUNT = 2305843009213693951; // PHP_INT_MAX / 4, ~2.3 quintillion
    const TRANSACTION_DEFAULT_CURRENCY = 'USD';
    const TRANSACTION_MINIMUM_FEE = 0.00000001;

    // PROTOCOL CONSTANT — DO NOT CHANGE. All currencies use 8 decimal places
    // internally. The fractional part is stored as a separate BIGINT multiplied
    // by 10^8 (FRAC_MODULUS in SplitAmount). The whole part is stored as-is,
    // so the maximum representable amount is PHP_INT_MAX.99999999.
    const INTERNAL_CONVERSION_FACTOR = 100000000;
    const INTERNAL_PRECISION = 8;

    // Display decimal places (default): controls how many decimal places are
    // shown in the UI for all currencies. Does NOT affect input validation or
    // internal storage — all input is validated and stored at INTERNAL_PRECISION
    // (8) decimal places. Configurable via changesettings displayDecimals (0-8).
    const DISPLAY_DECIMALS = 2;
    // Allowed currencies (defaults): configurable via changesettings
    const ALLOWED_CURRENCIES = ['USD'];

    /**
     * Get the internal conversion factor for storage.
     * Always returns INTERNAL_CONVERSION_FACTOR — the same for all currencies.
     *
     * @param string $currency Currency code (ignored — factor is universal)
     * @return int Conversion factor (always 10^8)
     */
    public static function getConversionFactor(string $currency): int
    {
        return self::INTERNAL_CONVERSION_FACTOR;
    }

    /**
     * Get the internal decimal precision for storage.
     * Always returns INTERNAL_PRECISION (8) — the same for all currencies.
     *
     * @param string $currency Currency code (ignored — precision is universal)
     * @return int Number of decimal places (always 8)
     */
    public static function getCurrencyDecimals(string $currency): int
    {
        return self::INTERNAL_PRECISION;
    }

    /**
     * Get the display decimal places (global setting, applies to all currencies).
     * Checks UserContext config first, falls back to DISPLAY_DECIMALS default.
     * Controls UI formatting only — input validation uses INTERNAL_PRECISION.
     *
     * @return int Number of display decimal places (0-8)
     */
    public static function getDisplayDecimals(): int
    {
        try {
            return UserContext::getInstance()->getAllDisplayDecimals();
        } catch (\Throwable $e) {
            // UserContext not initialized yet (startup/tests)
        }
        return self::DISPLAY_DECIMALS;
    }

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
     * @deprecated Use UserContext::getContactStatusEnabled() instead
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

    // Tor force-fast configuration
    // When true (default), P2P routing over Tor always uses fast mode because
    // per-hop Tor latency (~5s × 6 Tor relays) makes best-fee candidate
    // collection impractical. Set to false via EIOU_TOR_FORCE_FAST env variable
    // to allow best-fee mode over Tor (useful for testing).
    const TOR_FORCE_FAST = true;

    // Hop budget randomization
    // When true (default), P2P routing uses a geometric distribution (30% stop
    // probability per hop) to randomize the hop budget, preventing traffic
    // analysis attacks based on observed hop counts.
    // Set to false via EIOU_HOP_BUDGET_RANDOMIZED env variable to use the full
    // maxP2pLevel deterministically (required for predictable test behavior).
    const HOP_BUDGET_RANDOMIZED = true;
    // Minimum hop budget as a fraction of maxP2pLevel (e.g. 0.5 = half).
    // Ensures routing always has meaningful depth — a budget of 1 hop would
    // only reach direct contacts, defeating the purpose of P2P routing.
    // Floor is applied: for maxP2pLevel=6 and ratio=0.5, minHops=3.
    const HOP_BUDGET_MIN_RATIO = 0.5;

    /**
     * Check if Tor routes should force fast mode
     * Supports runtime override via EIOU_TOR_FORCE_FAST env variable
     *
     * @return bool Whether Tor routes force fast mode
     */
    public static function isTorForceFast(): bool {
        $envValue = getenv('EIOU_TOR_FORCE_FAST');
        if ($envValue !== false) {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }
        return self::TOR_FORCE_FAST;
    }

    /**
     * Check if hop budget randomization is enabled
     * Supports runtime override via EIOU_HOP_BUDGET_RANDOMIZED env variable
     *
     * When disabled, the hop budget equals maxP2pLevel (deterministic),
     * which is needed for tests that assert specific routing depths.
     *
     * @return bool Whether hop budget randomization is enabled
     */
    public static function isHopBudgetRandomized(): bool {
        $envValue = getenv('EIOU_HOP_BUDGET_RANDOMIZED');
        if ($envValue !== false) {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }
        return self::HOP_BUDGET_RANDOMIZED;
    }

    // Transaction Recovery configuration
    const RECOVERY_SENDING_TIMEOUT_SECONDS = 120; // Transactions stuck in 'sending' for this long are recovered
    const RECOVERY_MAX_RETRY_COUNT = 3; // Max times a transaction can be recovered before manual review
    const RECOVERY_LOCK_TIMEOUT_SECONDS = 300; // Lock timeout for processor exclusive access

    // Transaction delivery expiry
    // Direct transaction: two Tor round-trips (4 × TOR_TRANSPORT_TIMEOUT_SECONDS = 180s).
    // P2P transaction: P2P_DEFAULT_EXPIRATION_SECONDS + DIRECT_TX_DELIVERY_EXPIRATION_SECONDS
    //   (gives the in-flight transaction a delivery window after the P2P routing request itself expires).
    // The P2P and transaction expiries are decoupled so a transaction being propagated when its
    // parent P2P expires is not cancelled mid-flight; it gets the extra delivery window to complete.
    const DIRECT_TX_DELIVERY_EXPIRATION_SECONDS = 180; // Max time for a direct Tor delivery (4× TOR_TRANSPORT_TIMEOUT_SECONDS; two round-trips)

    // Crypto/Security
    const HASH_ALGORITHM = 'sha256'; // Do not change

    // Network
    // Default transport when sending to a contact name instead of an address.
    // Can be overridden by EIOU_DEFAULT_TRANSPORT_MODE env variable (http, https, tor).
    // Production defaults to 'tor' for privacy; tests can set to 'http' to avoid
    // Tor's force-fast behavior when testing best-fee mode.
    const DEFAULT_TRANSPORT_MODE = 'tor';
    const VALID_TRANSPORT_INDICES = ['tor', 'https', 'http'];

    // Address-type display registry — label + FontAwesome icon per known
    // transport. Consumed by the contact modal's address dropdown (script.js)
    // and the pending-contact modal's address pills (contactSection.html).
    //
    // Lookups go through `getAddressTypeDisplay()` which falls back to an
    // uppercased column name + `fa-question` icon when a new transport is
    // added to the `addresses` schema before its entry lands here — so the
    // UI never breaks on a schema-only change, it just renders the new
    // type with a placeholder icon until someone fills in the entry.
    const ADDRESS_TYPE_DISPLAY = [
        'tor'   => ['label' => 'Tor',   'icon' => 'fa-user-secret'],
        'https' => ['label' => 'HTTPS', 'icon' => 'fa-lock'],
        'http'  => ['label' => 'HTTP',  'icon' => 'fa-globe'],
    ];

    /**
     * Get the display metadata for an address type, with a fall-back for
     * types that exist in the `addresses` schema but don't yet have an entry
     * in ADDRESS_TYPE_DISPLAY. Keeps the GUI from breaking when a new
     * transport column is added before the display map is updated.
     *
     * @param string $type Schema column name (e.g. 'http', 'tor', 'i2p')
     * @return array{label: string, icon: string}
     */
    public static function getAddressTypeDisplay(string $type): array {
        return self::ADDRESS_TYPE_DISPLAY[$type]
            ?? ['label' => strtoupper($type), 'icon' => 'fa-question'];
    }

    /**
     * Get the default transport mode, with env override support
     *
     * @return string Transport mode ('http', 'https', or 'tor')
     */
    public static function getDefaultTransportMode(): string {
        $envValue = getenv('EIOU_DEFAULT_TRANSPORT_MODE');
        if ($envValue !== false && in_array($envValue, self::VALID_TRANSPORT_INDICES, true)) {
            return $envValue;
        }
        return self::DEFAULT_TRANSPORT_MODE;
    }

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
    const P2P_TOR_EXPIRATION_MULTIPLIER = 2; // Tor hidden services need longer expiration (6 Tor hops per eIOU hop)
    const P2P_QUEUE_BATCH_SIZE = 10; // Max queued P2Ps processed per daemon poll cycle (all sent in one curl_multi)
    const P2P_QUEUE_COALESCE_MS = 2000; // Milliseconds to wait for more P2Ps before firing mega-batch (default: 2000ms)
    // Max concurrent worker processes for parallel P2P processing, keyed by transport protocol.
    // Each worker uses ~15-20MB RAM, 1 MySQL connection, and up to CURL_MULTI_MAX_CONCURRENT curl handles.
    // Tor is limited because each hidden service connection creates 6 Tor hops; too many circuits saturates the daemon.
    // HTTP/HTTPS are lightweight — higher limit lets burst P2Ps process without queueing.
    // Override all via EIOU_P2P_MAX_WORKERS env var.
    const P2P_MAX_WORKERS = [
        'http'  => 50,
        'https' => 50,
        'tor'   => 5,
    ];
    const P2P_SENDING_TIMEOUT_SECONDS = 300; // Seconds before a P2P stuck in 'sending' is recovered (worker assumed dead)
    // Max simultaneous connections per curl_multi batch, keyed by protocol.
    // Lower Tor limit prevents circuit overload; HTTP/HTTPS can handle more.
    // Unlisted protocols fall back to the lowest value.
    const CURL_MULTI_MAX_CONCURRENT = [
        'http'  => 10,
        'https' => 10,
        'tor'   => 5,
    ];

    // Transport timeouts (single HTTP/TOR request to a node)
    const HTTP_TRANSPORT_TIMEOUT_SECONDS = 15; // Max time for one HTTP request between nodes
    const TOR_TRANSPORT_TIMEOUT_SECONDS = 45;  // Max time for one TOR request between nodes (overall: connect + transfer)
    const TOR_CONNECT_TIMEOUT_SECONDS = 20;    // Max time for Tor SOCKS5 circuit establishment + hidden service connect

    // Tor circuit health tracking
    // When a specific .onion address times out repeatedly, stop retrying and enter cooldown.
    const TOR_CIRCUIT_MAX_FAILURES = 3;           // Consecutive Tor failures before cooldown (default: 3)
    const TOR_CIRCUIT_COOLDOWN_SECONDS = 300;     // Cooldown duration in seconds (default: 300 = 5 min)
    const TOR_FAILURE_TRANSPORT_FALLBACK = true;  // Fall back to HTTPS/HTTP when Tor fails (default: true)
    const TOR_FALLBACK_REQUIRE_ENCRYPTED = true; // Only fall back to HTTPS (not HTTP) for privacy (default: true)

    // Transaction sync chunking
    // 50 txns * ~3KB = ~150KB per chunk — well within PHP post_max_size and transport timeouts
    const SYNC_CHUNK_SIZE = 50;          // Max transactions per sync response
    const SYNC_MAX_CHUNKS = 100;         // Max chunk requests per sync session (safety limit: 5000 txns total)

    // Minimum per-hop wait: based on a single HTTP round-trip (send P2P + receive RP2P).
    // Must be at least the transport timeout so a relay never expires before a
    // back-and-forth with its direct contact can complete.
    const P2P_MIN_HOP_WAIT_SECONDS = self::HTTP_TRANSPORT_TIMEOUT_SECONDS;

    // Contact management
    const CONTACT_DEFAULT_FEE_PERCENT = 0.01;
    const CONTACT_DEFAULT_FEE_PERCENT_MAX = 5;
    const CONTACT_DEFAULT_CREDIT_LIMIT = 1000;
    const CONTACT_MAX_NAME_LENGTH = 255;
    const CONTACT_MIN_NAME_LENGTH = 2;

    // Validation limits
    const VALIDATION_PUBLIC_KEY_MIN_LENGTH = 100;
    const VALIDATION_SIGNATURE_MIN_LENGTH = 100;
    const VALIDATION_SIGNATURE_MAX_LENGTH = 1024;
    const VALIDATION_TOR_V3_ADDRESS_LENGTH = 56;
    const VALIDATION_TOR_V2_ADDRESS_LENGTH = 16;
    const VALIDATION_HASH_LENGTH_SHA256 = 64;
    const VALIDATION_CURRENCY_CODE_MIN_LENGTH = 3;
    const VALIDATION_CURRENCY_CODE_MAX_LENGTH = 9;
    const VALIDATION_MEMO_MAX_LENGTH = 500;
    const VALIDATION_FEE_MIN_PERCENT = 0;
    const VALIDATION_FEE_MAX_PERCENT = 100;

    // Time conversion factors
    const TIME_MICROSECONDS_TO_INT = 10000;
    const TIME_SECONDS_PER_MINUTE = 60;
    const TIME_MINUTES_PER_HOUR = 60;
    const TIME_HOURS_PER_DAY = 24;

    // Fee percentage storage: multiply by 100 to store as integer with 2 decimal precision.
    // Example: 0.01% → stored as 1, 1.50% → stored as 150, 100% → stored as 10000.
    // Display: stored_value / FEE_CONVERSION_FACTOR = original percentage.
    const FEE_CONVERSION_FACTOR = 100;
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
    const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
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
    const CONTACT_ONLINE_STATUS_PARTIAL = 'partial';
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
    const DELIVERY_CONTACT_DESCRIPTION = 'contact_description';
    const DELIVERY_REJECTED = 'rejected';
    const DELIVERY_MAINTENANCE = 'maintenance';
    const DELIVERY_ERROR = 'error';

    /**
     * Statuses that indicate a successful delivery response from a remote node.
     * Any status NOT in this list should be treated as a failure/retry.
     */
    const DELIVERY_SUCCESS_STATUSES = [
        'received', 'inserted', 'forwarded', 'accepted',
        'acknowledged', 'completed', 'warning', 'updated', 'already_relayed'
    ];

    // UI/Display
    const DISPLAY_DATE_FORMAT = 'd/m/Y H:i:s';
    const VALID_DATE_FORMATS = [
        'Y-m-d H:i:s.u',   // 2026-03-24 17:43:20.123456 (default, microseconds)
        'Y-m-d H:i:s',     // 2026-03-24 17:43:20
        'Y-m-d H:i',       // 2026-03-24 17:43
        'Y-m-d',           // 2026-03-24
        'd/m/Y H:i:s',     // 24/03/2026 17:43:20
        'd/m/Y H:i',       // 24/03/2026 17:43
        'd/m/Y',           // 24/03/2026
        'm/d/Y H:i:s',     // 03/24/2026 17:43:20
        'm/d/Y H:i',       // 03/24/2026 17:43
        'm/d/Y',           // 03/24/2026
        'd-m-Y H:i:s',     // 24-03-2026 17:43:20
        'd-m-Y H:i',       // 24-03-2026 17:43
        'd-m-Y',           // 24-03-2026
        'd M Y H:i:s',     // 24 Mar 2026 17:43:20
        'd M Y H:i',       // 24 Mar 2026 17:43
        'd M Y',           // 24 Mar 2026
        'M d, Y H:i:s',    // Mar 24, 2026 17:43:20
        'M d, Y H:i',      // Mar 24, 2026 17:43
        'M d, Y',          // Mar 24, 2026
        'U',               // Unix timestamp
    ];
    const DISPLAY_CURRENCY_DECIMALS = 8;
    const DISPLAY_DEFAULT_OUTPUT_LINES_MAX = 10;
    const AUTO_REFRESH_ENABLED = false; // Default OFF - user must enable in settings

    // Live event notifications — non-reloading XHR poll that surfaces new
    // incoming payment requests / contact requests / transactions / DLQ
    // entries as toasts, and keeps tab badges fresh. Supersedes the page
    // reload path: when LIVE_NOTIFICATIONS_ENABLED is true, the
    // AUTO_REFRESH_ENABLED 15s reload is suppressed at runtime (both can be
    // ON in settings; live-notifs wins to avoid reloading the page out from
    // under a just-fired toast).
    const LIVE_NOTIFICATIONS_ENABLED = true;                    // Default ON — unobtrusive by design (visibility-gated, idle-backoff, modal-suppressed)
    const LIVE_NOTIFICATIONS_POLL_INTERVAL_MS = 10000;          // Base poll cadence; loop backs off when idle
    const LIVE_NOTIFICATIONS_VERBOSITY = 'balanced';            // quiet | balanced | live — see matrix in GUI settings
    const LIVE_NOTIFICATIONS_VERBOSITY_OPTIONS = ['quiet', 'balanced', 'live'];
    const LIVE_NOTIFICATIONS_TOAST_DURATION_MS = 10000;         // 0 = until-dismissed
    const LIVE_NOTIFICATIONS_TOAST_DURATION_OPTIONS = [5000, 10000, 20000, 0];
    const LIVE_NOTIFICATIONS_MAX_PER_KIND = 25;                 // Hard cap on deltas returned per kind per poll (keeps payload bounded)

    // Session
    const SESSION_TIMEOUT_MINUTES = 30;             // Default session inactivity timeout in minutes
    const SESSION_TIMEOUT_OPTIONS = [5, 10, 15, 30, 60]; // Allowed session timeout values in minutes

    // Remember-me login tokens (long-lived browser cookies that auto-authenticate)
    const REMEMBER_ME_COOKIE_NAME = 'EIOU_REMEMBER';     // Cookie carrying the raw rotation token
    const REMEMBER_ME_TOKEN_BYTES = 32;                  // Raw entropy per token (→ 64 hex chars)
    const REMEMBER_ME_DEFAULT_MAX_DAYS = 30;             // Default cap on a token's lifetime
    const REMEMBER_ME_MAX_DAYS_OPTIONS = [1, 7, 14, 30, 60, 90]; // User-selectable caps in days
    const REMEMBER_ME_DEFAULT_MAX_DEVICES = 3;           // Default cap on simultaneous remembered devices
    const REMEMBER_ME_MAX_DEVICES_OPTIONS = [1, 3, 5, 10]; // User-selectable device caps
    const CLEANUP_REMEMBER_TOKEN_RETENTION_DAYS = 1;     // Days to keep revoked/expired rows before pruning

    // Contact avatar style — controls how contact avatars are rendered in the contacts list
    const CONTACT_AVATAR_STYLE = 'gradient';
    const CONTACT_AVATAR_STYLE_OPTIONS = ['gradient', 'pixel', 'tile'];

    // Amount color scheme — controls how +/- transaction amounts and balances are colored
    //   neutral: black for both (default)
    //   western: green positive, red negative (Rest of the World)
    //   eastern: red positive, green negative (Asia)
    const AMOUNT_COLOR_SCHEME = 'western';
    const AMOUNT_COLOR_SCHEME_OPTIONS = ['neutral', 'western', 'eastern'];

    // Status color scheme — controls accepted/completed/resolved (success)
    // and rejected/blocked/failed (failure) badges. Sent/received direction
    // badges are always neutral, regardless of this setting.
    //   neutral: muted gray for both (default)
    //   western: green success, red failure
    //   eastern: red success, green failure
    const STATUS_COLOR_SCHEME = 'western';
    const STATUS_COLOR_SCHEME_OPTIONS = ['neutral', 'western', 'eastern'];


    // File paths (relative to project root)
    const PATH_CONFIG_DIR = '/etc/eiou/config/';

    // System binaries — use absolute paths to avoid silent PATH drift
    // across exec contexts (php-fpm, cron, CLI). The original analytics
    // outage was caused by a bare `runuser` failing to resolve on Debian
    // because /usr/sbin wasn't on the exec'd shell's PATH
    const BIN_RUNUSER = '/usr/sbin/runuser';

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
    const HELD_TX_SYNC_TIMEOUT_SECONDS = 120;      // Max seconds a sync can be in_progress before considered stale (must be < P2P_DEFAULT_EXPIRATION_SECONDS since P2P hops expire on all relay nodes independently)

    // Display/Query limits
    const DISPLAY_RECENT_TRANSACTIONS_LIMIT = 100;  // Max recent transactions shown in Activity tab (default: 100)
    const DISPLAY_RECENT_CONTACTS_LIMIT = 5;       // Max recent contacts shown in lists (default: 5)
    const CONTACT_TRANSACTIONS_LIMIT = 10;         // Max transactions per contact in combined queries (default: 10)
    const BALANCE_TRANSACTION_LIMIT = 5;           // Max transactions used for balance conversion (default: 5)
    const CHAIN_DROP_PROPOSALS_LIMIT = 20;         // Max tx drop proposals per contact query (default: 20)
    const AUTO_CHAIN_DROP_PROPOSE = true;          // Auto-propose tx drops when mutual gaps detected
    const AUTO_CHAIN_DROP_ACCEPT = false;          // Auto-accept incoming tx drop proposals - default OFF for safety
    const AUTO_CHAIN_DROP_ACCEPT_GUARD = true;     // Balance guard for auto-accept: compares stored vs calculated balances to block acceptance when missing transactions would erase debt owed to us. Disable to accept unconditionally.
    const AUTO_ACCEPT_RESTORED_CONTACT = true;     // Auto-accept contacts on wallet restore when transaction history proves prior relationship - default ON; when OFF, restored contacts stay pending for manual review
    const AUTO_ACCEPT_TRANSACTION = true;          // Auto-accept P2P transactions when route found - default ON for backward compatibility
    const HIDE_EMPTY_GUI_SECTIONS = false;         // GUI-only: when true, hide the Failed Messages / Payment Requests / Pending Contact Requests sections when they have nothing in them. Default OFF (discoverability over tidiness — users see the empty panels and know the feature exists)
    const AUTO_REJECT_UNKNOWN_CURRENCY = false;   // Auto-reject incoming contact requests with currencies not in allowedCurrencies - default OFF; requests arrive as pending for manual review (accepting auto-adds the currency). Operators who only ever want the currencies they've explicitly allowed can flip this ON.
    const UPDATE_CHECK_ENABLED = true;            // Check Docker Hub for newer image versions daily - default ON; when OFF, no external API calls are made
    const ANALYTICS_ENABLED = false;              // Share anonymous usage statistics - default OFF (opt-in only); when ON, sends aggregate counts to analytics.eiou.org daily
    const ANALYTICS_OPT_IN_AT = null;             // ISO 8601 timestamp written when the user flips analyticsEnabled from off to on. Bounds the heartbeat rollup window so catch-up submissions after an outage never pull pre-consent data. Null = never opted in (legacy or currently OFF)

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
    const PAYMENT_REQUESTS_ARCHIVE_RETENTION_DAYS = 180; // Days after resolution before a payment request is archived (default: 180)
    const PAYMENT_REQUESTS_ARCHIVE_BATCH_SIZE = 500; // Rows moved per transaction in the archival job (default: 500)
    const TRANSACTIONS_ARCHIVE_RETENTION_DAYS = 30; // Days after completion before a transaction is archived (default: 30). Only applies to bilateral chains that verify gap-free at the moment of archival. Shorter than the payment-requests default because chain-integrity verification runs on every outbound send — moving settled txs out of the live table sooner keeps the hot path tight.
    const TRANSACTIONS_ARCHIVE_BATCH_SIZE = 500; // Rows moved per transaction in the transactions archival job (default: 500)
    const CLEANUP_RATE_LIMIT_SECONDS = 3600;       // Seconds before expired rate limit entries are cleaned (default: 3600)

    // Database locking
    const DB_LOCK_TIMEOUT_SECONDS = 30;            // Max seconds to wait for a database lock (default: 30)

    // Rate limiting defaults
    const RATE_LIMIT_MAX_ATTEMPTS = 10;            // Max attempts before rate-limiting kicks in (default: 10)
    const RATE_LIMIT_WINDOW_SECONDS = 60;          // Time window in seconds for counting attempts (default: 60)
    const RATE_LIMIT_BLOCK_SECONDS = 300;          // Seconds to block after limit exceeded (default: 300)

    // Trusted proxy IPs (comma-separated). Only trust proxy headers (X-Forwarded-For, CF-Connecting-IP)
    // when REMOTE_ADDR is in this list. Configure via CLI: changesettings trustedProxies "10.0.0.1,172.16.0.1"
    const TRUSTED_PROXIES = '';

    /**
     * Check if automatic backups are enabled
     * Supports runtime override via EIOU_BACKUP_AUTO_ENABLED env variable
     *
     * @deprecated Use UserContext::getAutoBackupEnabled() instead
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
     * Check if automatic tx drop proposals are enabled
     * Supports runtime override via EIOU_AUTO_CHAIN_DROP_PROPOSE env variable
     *
     * @deprecated Use UserContext::getAutoChainDropPropose() instead
     * @return bool Whether automatic tx drop proposals are enabled
     */
    public static function isAutoChainDropProposeEnabled(): bool {
        $envValue = getenv('EIOU_AUTO_CHAIN_DROP_PROPOSE');
        if ($envValue !== false) {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }
        return self::AUTO_CHAIN_DROP_PROPOSE;
    }

    /**
     * Check if automatic tx drop acceptance is enabled
     * Supports runtime override via EIOU_AUTO_CHAIN_DROP_ACCEPT env variable
     *
     * @deprecated Use UserContext::getAutoChainDropAccept() instead
     * @return bool Whether automatic tx drop acceptance is enabled
     */
    public static function isAutoChainDropAcceptEnabled(): bool {
        $envValue = getenv('EIOU_AUTO_CHAIN_DROP_ACCEPT');
        if ($envValue !== false) {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }
        return self::AUTO_CHAIN_DROP_ACCEPT;
    }

    /**
     * Check if the auto-accept balance guard is enabled
     * Supports runtime override via EIOU_AUTO_CHAIN_DROP_ACCEPT_GUARD env variable
     *
     * When enabled (default), auto-accept compares stored vs calculated balances
     * and blocks acceptance if missing transactions would erase debt owed to us.
     * When disabled, auto-accept proceeds unconditionally.
     *
     * Only meaningful when AUTO_CHAIN_DROP_ACCEPT is true.
     *
     * @return bool Whether the balance guard is enabled
     */
    public static function isAutoChainDropAcceptGuardEnabled(): bool {
        $envValue = getenv('EIOU_AUTO_CHAIN_DROP_ACCEPT_GUARD');
        if ($envValue !== false) {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }
        return self::AUTO_CHAIN_DROP_ACCEPT_GUARD;
    }

    /**
     * Get max P2P worker processes for a given transport protocol.
     * Supports runtime override via EIOU_P2P_MAX_WORKERS env variable (overrides all protocols).
     *
     * @param string $transport Transport protocol ('http', 'https', 'tor')
     * @return int Maximum concurrent worker processes for that transport
     */
    public static function getMaxP2pWorkers(string $transport = 'tor'): int {
        $envValue = getenv('EIOU_P2P_MAX_WORKERS');
        if ($envValue !== false && ctype_digit($envValue) && (int)$envValue > 0) {
            return (int)$envValue;
        }
        if (isset(self::P2P_MAX_WORKERS[$transport])) {
            return self::P2P_MAX_WORKERS[$transport];
        }
        // Unknown protocol: use the lowest configured limit
        return min(self::P2P_MAX_WORKERS);
    }

    /**
     * Get the application environment
     * Supports runtime override via APP_ENV env variable
     *
     * @return string Current environment ('development', 'production', etc.)
     */
    public static function getAppEnv(): string {
        $envValue = getenv('APP_ENV');
        if ($envValue !== false && $envValue !== '') {
            return $envValue;
        }
        return self::APP_ENV;
    }

    /**
     * Check if debug mode is enabled
     * Supports runtime override via APP_DEBUG env variable
     *
     * @return bool Whether debug mode is enabled
     */
    public static function isDebug(): bool {
        $env = getenv('APP_DEBUG');
        if ($env !== false) {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }
        return self::APP_DEBUG;
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
