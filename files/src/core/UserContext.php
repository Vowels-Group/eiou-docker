<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Core;

use Eiou\Utils\AddressValidator;
use Eiou\Security\KeyEncryption;
use Eiou\Utils\Logger;
use Exception;

/**
 * UserContext - Singleton wrapper for user configuration
 *
 * This class provides a centralized, type-safe way to access user configuration
 *
 */

class UserContext {
    private static ?UserContext $instance = null;
    private array $userData = [];

    /**
     * Keys containing sensitive encrypted data that should not be exposed via getAll()/toArray()
     */
    private const SENSITIVE_KEYS = ['private_encrypted', 'authcode_encrypted', 'mnemonic_encrypted'];

    private bool $initialized = false;

    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct() {
        $this->loadConfigFromFiles();
    }

    /**
     * Get singleton instance
     *
     * @return UserContext
     */
    public static function getInstance(): UserContext {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load configuration from config files
     *
     * @return void
     */
    private function loadConfigFromFiles(): void {
        if(!$this->initialized ){
            // Load in default config values
            $defaultPath = '/etc/eiou/config/defaultconfig.json';
            if (file_exists($defaultPath)){
                $contents = file_get_contents($defaultPath);
                if ($contents === false) {
                    error_log("UserContext: Failed to read $defaultPath");
                    return;
                }
                $decoded = json_decode($contents, true);
                if (!is_array($decoded)) {
                    error_log("UserContext: Invalid JSON in $defaultPath (json_last_error: " . json_last_error_msg() . ")");
                    return;
                }
                $this->userData = $decoded;
                // Load in user config information
                $userPath = '/etc/eiou/config/userconfig.json';
                if (file_exists($userPath)){
                    $userContents = file_get_contents($userPath);
                    if ($userContents === false) {
                        error_log("UserContext: Failed to read $userPath");
                        return;
                    }
                    $userDecoded = json_decode($userContents, true);
                    if (is_array($userDecoded)) {
                        $this->userData = array_merge($this->userData, $userDecoded);
                        $this->initialized = true;
                    } else {
                        error_log("UserContext: Invalid JSON in $userPath (json_last_error: " . json_last_error_msg() . ")");
                    }
                }
            }
        }
    }

    /**
     * Set user data directly
     *
     * @param array $userData User configuration array
     * @return void
     */
    public function setUserData(array $userData): void {
        $this->userData = $userData;
        $this->initialized = true;
    }

    /**
     * Get a user configuration value
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed
     */
    public function get(string $key, $default = null) {
        return $this->userData[$key] ?? $default;
    }

    /**
     * Set a user configuration value
     *
     * @param string $key Configuration key
     * @param mixed $value Value to set
     * @return void
     */
    public function set(string $key, $value): void {
        $this->userData[$key] = $value;
    }

    /**
     * Check if a configuration key exists
     *
     * @param string $key Configuration key
     * @return bool
     */
    public function has(string $key): bool {
        return isset($this->userData[$key]);
    }

    /**
     * Get all user data
     *
     * @return array
     */
    public function getAll(): array {
        return array_diff_key($this->userData, array_flip(self::SENSITIVE_KEYS));
    }

    /**
     * Get public key
     *
     * @return string|null
     */
    public function getPublicKey(): ?string {
        return $this->get('public') ?? null;
    }

    /**
     * Get public key hash
     *
     * @return string|null
     */
    public function getPublicKeyHash(): ?string {
        return hash(Constants::HASH_ALGORITHM, $this->get('public')) ?? null;
    }

    /**
     * Get private key (decrypts if encrypted)
     *
     * SECURITY: This method decrypts the private key. The caller MUST clear
     * the returned value from memory using KeyEncryption::secureClear() after use.
     *
     * @return string|null Decrypted private key or null if not found
     */
    public function getPrivateKey(): ?string {
        // Try new encrypted format first
        if ($this->has('private_encrypted')) {
            try {
                return KeyEncryption::decrypt($this->get('private_encrypted'));
            } catch (\Throwable $e) {
                // Log decryption failure but don't expose details
                Logger::getInstance()->error('Failed to decrypt private key', [
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        }
        return null;
    }

    /**
     * Check if wallet has keys
     *
     * @return bool True if wallet has both public and private keys
     */
    public function hasKeys(): bool {
        // Check for public key
        $hasPublic = null !== $this->getPublicKey();

        // Check for private key (encrypted only - plaintext keys are no longer supported)
        $hasPrivate = $this->has('private_encrypted');

        return $hasPublic && $hasPrivate;
    }

    /**
     * Get authentication code (decrypts if encrypted)
     *
     * SECURITY: This method decrypts the auth code. The caller MUST clear
     * the returned value from memory using KeyEncryption::secureClear() after use.
     *
     * @return string|null Decrypted auth code or null if not found
     */
    public function getAuthCode(): ?string {
        // Try new encrypted format first
        if ($this->has('authcode_encrypted')) {
            try {
                return KeyEncryption::decrypt($this->get('authcode_encrypted'));
            } catch (\Throwable $e) {
                // Log decryption failure but don't expose details
                Logger::getInstance()->error('Failed to decrypt auth code', [
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        }
        return null;
    }

    /**
     * Get hostname
     *
     * @return string|null
     */
    public function getHttpAddress(): ?string {
        return $this->get('hostname') ?? null;
    }

    /**
     * Get HTTPS hostname
     *
     * @return string|null
     */
    public function getHttpsAddress(): ?string {
        return $this->get('hostname_secure') ?? null;
    }

    /**
     * Get display name
     *
     * @return string|null
     */
    public function getName(): ?string {
        return $this->get('name') ?? null;
    }

    /**
     * Get Tor address
     *
     * @return string|null
     */
    public function getTorAddress(): ?string {
        return $this->get('torAddress') ?? null;
    }

    /**
     * Validate wallet configuration
     *
     * @return array Validation result with status and errors
     */
    public function validateWallet(): array {
        $errors = [];

        if (null === $this->getPublicKey()) {
            $errors[] = 'Public key is missing';
        }

        if (null === $this->getPrivateKey()) {
            $errors[] = 'Private key is missing';
        }

        if (null === $this->getAuthCode()) {
            $errors[] = 'Authentication code is missing';
        }

        if ((null === $this->getHttpAddress()) && (null === $this->getHttpsAddress()) && (null === $this->getTorAddress())) {
            $errors[] = 'No network address configured (HTTP, HTTPS, or Tor)';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get all user addresses (hostname, hostname_secure, and tor)
     *
     * @return array
     */
    public function getUserAddresses(): array {
        $addresses = [];
        if ($hostname = $this->getHttpAddress()) {
            $addresses[] = $hostname;
        }
        if ($hostnameSecure = $this->getHttpsAddress()) {
            $addresses[] = $hostnameSecure;
        }
        if ($torAddress = $this->getTorAddress()) {
            $addresses[] = $torAddress;
        }
        return $addresses;
    }

    /**
     * Get all user locaters (hostname, hostname_secure, and tor)
     *
     * @return array
     */
    public function getUserLocaters(): array {
        $locaters = [];
        foreach($this->getUserAddresses() as $address){
            if ($this->isTorAddress($address)){
                $locaters['tor'] = $address;
            } elseif ($this->isHttpsAddress($address)) {
                $locaters['https'] = $address;
            } elseif ($this->isHttpAddress($address)) {
                $locaters['http'] = $address;
            }
        }
        return $locaters;
    }

    /**
     * Check if address is HTTPS
     *
     * @param string $address The address to check
     * @return bool True if HTTPS address, false otherwise
     */
    public function isHttpsAddress(string $address): bool {
        return AddressValidator::isHttpsAddress($address);
    }

    /**
     * Determine if address is HTTP only (not HTTPS)
     *
     * @param string $address The address of the sender
     * @return bool True if HTTP address, false otherwise
     */
    public function isHttpAddress(string $address): bool {
        return AddressValidator::isHttpAddress($address);
    }

    /**
     * Determine if address is valid HTTP, HTTPS, or TOR
     *
     * @param string $address The address of the sender
     * @return bool True if HTTP/HTTPS/TOR address, false otherwise
     */
    public function isAddress(string $address): bool {
        return AddressValidator::isAddress($address);
    }

    /**
     * Determine if address is TOR
     *
     * @param string $address The address of the sender
     * @return bool True if Tor address, false otherwise
     */
    public function isTorAddress(string $address): bool {
        return AddressValidator::isTorAddress($address);
    }
    
    /**
     * Check if an address belongs to this user
     *
     * @param string $address
     * @return bool
     */
    public function isMyAddress(string $address): bool {
        return in_array($address, $this->getUserAddresses(), true);
    }

    /**
     * Get mimumum fee amount
     *
     * @return float
     */
    public function getMinimumFee(): float {
        return (float) ($this->get('minFee') ?? Constants::TRANSACTION_MINIMUM_FEE);
    }


    /**
     * Get default fee percentage
     *
     * @return float
     */
    public function getDefaultFee(): float {
        return (float) ($this->get('defaultFee') ?? Constants::CONTACT_DEFAULT_FEE_PERCENT);
    }

    /**
     * Get default credit limit
     *
     * @return float
     */
    public function getDefaultCreditLimit(): float {
        return (float) ($this->get('defaultCreditLimit') ?? Constants::CONTACT_DEFAULT_CREDIT_LIMIT);
    }

    /**
     * Get default currency
     *
     * @return string
     */
    public function getDefaultCurrency(): string {
        return $this->get('defaultCurrency') ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
    }

    /**
     * Get maximum fee percentage
     *
     * @return float
     */
    public function getMaxFee(): float {
        return (float) ($this->get('maxFee') ?? Constants::CONTACT_DEFAULT_FEE_PERCENT_MAX);
    }

    /**
     * Get maximum P2P level
     *
     * @return int
     */
    public function getMaxP2pLevel(): int {
        return (int) ($this->get('maxP2pLevel') ?? Constants::P2P_DEFAULT_MAX_REQUEST_LEVEL);
    }

    /**
     * Get P2P expiration time in seconds
     *
     * @return int
     */
    public function getP2pExpirationTime(): int {
        return (int) ($this->get('p2pExpiration') ?? Constants::P2P_DEFAULT_EXPIRATION_SECONDS);
    }

    /**
     * Get maximum output lines
     *
     * @return int
     */
    public function getMaxOutput(): int {
        return (int) ($this->get('maxOutput') ?? Constants::DISPLAY_DEFAULT_OUTPUT_LINES_MAX);
    }

    /**
     * Get deafult transport type for messages
     *
     * @return string
     */
    public function getDefaultTransportMode(): string {
        return $this->get('defaultTransportMode') ?? Constants::DEFAULT_TRANSPORT_MODE;
    }

    /**
     * Get auto-refresh enabled status for transaction history
     *
     * @return bool
     */
    public function getAutoRefreshEnabled(): bool {
        return (bool) ($this->get('autoRefreshEnabled') ?? Constants::AUTO_REFRESH_ENABLED);
    }

    /**
     * Get auto-backup enabled status for daily database backups
     *
     * @return bool
     */
    public function getAutoBackupEnabled(): bool {
        return (bool) ($this->get('autoBackupEnabled') ?? Constants::BACKUP_AUTO_ENABLED);
    }

    /**
     * Get trusted proxy IPs (comma-separated)
     *
     * @return string
     */
    public function getTrustedProxies(): string {
        return (string) ($this->get('trustedProxies') ?? Constants::TRUSTED_PROXIES);
    }

    // =========================================================================
    // FEATURE TOGGLE GETTERS
    // =========================================================================

    /**
     * Get contact status enabled setting
     *
     * @return bool
     */
    public function getContactStatusEnabled(): bool {
        $envValue = getenv('EIOU_CONTACT_STATUS_ENABLED');
        if ($envValue !== false) {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool) ($this->get('contactStatusEnabled') ?? Constants::CONTACT_STATUS_ENABLED);
    }

    /**
     * Get contact status sync on ping setting
     *
     * @return bool
     */
    public function getContactStatusSyncOnPing(): bool {
        return (bool) ($this->get('contactStatusSyncOnPing') ?? Constants::CONTACT_STATUS_SYNC_ON_PING);
    }

    /**
     * Get auto chain drop propose setting
     *
     * @return bool
     */
    public function getAutoChainDropPropose(): bool {
        $envValue = getenv('EIOU_AUTO_CHAIN_DROP_PROPOSE');
        if ($envValue !== false) {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool) ($this->get('autoChainDropPropose') ?? Constants::AUTO_CHAIN_DROP_PROPOSE);
    }

    /**
     * Get auto chain drop accept setting
     *
     * @return bool
     */
    public function getAutoChainDropAccept(): bool {
        $envValue = getenv('EIOU_AUTO_CHAIN_DROP_ACCEPT');
        if ($envValue !== false) {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool) ($this->get('autoChainDropAccept') ?? Constants::AUTO_CHAIN_DROP_ACCEPT);
    }

    /**
     * Get API enabled setting
     *
     * @return bool
     */
    public function getApiEnabled(): bool {
        return (bool) ($this->get('apiEnabled') ?? Constants::API_ENABLED);
    }

    /**
     * Get API CORS allowed origins
     *
     * @return string
     */
    public function getApiCorsAllowedOrigins(): string {
        return (string) ($this->get('apiCorsAllowedOrigins') ?? Constants::API_CORS_ALLOWED_ORIGINS);
    }

    /**
     * Get rate limit enabled setting
     *
     * @return bool
     */
    public function getRateLimitEnabled(): bool {
        return (bool) ($this->get('rateLimitEnabled') ?? Constants::RATE_LIMIT_ENABLED);
    }

    // =========================================================================
    // BACKUP & LOGGING GETTERS
    // =========================================================================

    /**
     * Get backup retention count
     *
     * @return int
     */
    public function getBackupRetentionCount(): int {
        return max(1, (int) ($this->get('backupRetentionCount') ?? Constants::BACKUP_RETENTION_COUNT));
    }

    /**
     * Get backup cron hour (0-23)
     *
     * @return int
     */
    public function getBackupCronHour(): int {
        $val = (int) ($this->get('backupCronHour') ?? Constants::BACKUP_CRON_HOUR);
        return max(0, min(23, $val));
    }

    /**
     * Get backup cron minute (0-59)
     *
     * @return int
     */
    public function getBackupCronMinute(): int {
        $val = (int) ($this->get('backupCronMinute') ?? Constants::BACKUP_CRON_MINUTE);
        return max(0, min(59, $val));
    }

    /**
     * Get log level
     *
     * @return string
     */
    public function getLogLevel(): string {
        return (string) ($this->get('logLevel') ?? Constants::LOG_LEVEL);
    }

    /**
     * Get log max entries
     *
     * @return int
     */
    public function getLogMaxEntries(): int {
        return max(10, (int) ($this->get('logMaxEntries') ?? Constants::LOG_MAX_ENTRIES));
    }

    // =========================================================================
    // DATA RETENTION GETTERS
    // =========================================================================

    /**
     * Get delivery retention days
     *
     * @return int
     */
    public function getCleanupDeliveryRetentionDays(): int {
        return max(1, (int) ($this->get('cleanupDeliveryRetentionDays') ?? Constants::CLEANUP_DELIVERY_RETENTION_DAYS));
    }

    /**
     * Get DLQ retention days
     *
     * @return int
     */
    public function getCleanupDlqRetentionDays(): int {
        return max(1, (int) ($this->get('cleanupDlqRetentionDays') ?? Constants::CLEANUP_DLQ_RETENTION_DAYS));
    }

    /**
     * Get held transaction retention days
     *
     * @return int
     */
    public function getCleanupHeldTxRetentionDays(): int {
        return max(1, (int) ($this->get('cleanupHeldTxRetentionDays') ?? Constants::CLEANUP_HELD_TX_RETENTION_DAYS));
    }

    /**
     * Get RP2P retention days
     *
     * @return int
     */
    public function getCleanupRp2pRetentionDays(): int {
        return max(1, (int) ($this->get('cleanupRp2pRetentionDays') ?? Constants::CLEANUP_RP2P_RETENTION_DAYS));
    }

    /**
     * Get metrics retention days
     *
     * @return int
     */
    public function getCleanupMetricsRetentionDays(): int {
        return max(1, (int) ($this->get('cleanupMetricsRetentionDays') ?? Constants::CLEANUP_METRICS_RETENTION_DAYS));
    }

    // =========================================================================
    // RATE LIMITING GETTERS
    // =========================================================================

    /**
     * Get P2P rate limit per minute
     *
     * @return int
     */
    public function getP2pRateLimitPerMinute(): int {
        return max(1, (int) ($this->get('p2pRateLimitPerMinute') ?? Constants::P2P_RATE_LIMIT_PER_MINUTE));
    }

    /**
     * Get rate limit max attempts
     *
     * @return int
     */
    public function getRateLimitMaxAttempts(): int {
        return max(1, (int) ($this->get('rateLimitMaxAttempts') ?? Constants::RATE_LIMIT_MAX_ATTEMPTS));
    }

    /**
     * Get rate limit window seconds
     *
     * @return int
     */
    public function getRateLimitWindowSeconds(): int {
        return max(1, (int) ($this->get('rateLimitWindowSeconds') ?? Constants::RATE_LIMIT_WINDOW_SECONDS));
    }

    /**
     * Get rate limit block seconds
     *
     * @return int
     */
    public function getRateLimitBlockSeconds(): int {
        return max(1, (int) ($this->get('rateLimitBlockSeconds') ?? Constants::RATE_LIMIT_BLOCK_SECONDS));
    }

    // =========================================================================
    // NETWORK GETTERS
    // =========================================================================

    /**
     * Get HTTP transport timeout seconds
     *
     * @return int
     */
    public function getHttpTransportTimeoutSeconds(): int {
        $val = (int) ($this->get('httpTransportTimeoutSeconds') ?? Constants::HTTP_TRANSPORT_TIMEOUT_SECONDS);
        return max(5, min(120, $val));
    }

    /**
     * Get Tor transport timeout seconds
     *
     * @return int
     */
    public function getTorTransportTimeoutSeconds(): int {
        $val = (int) ($this->get('torTransportTimeoutSeconds') ?? Constants::TOR_TRANSPORT_TIMEOUT_SECONDS);
        return max(10, min(300, $val));
    }

    // =========================================================================
    // SYNC GETTERS
    // =========================================================================

    /**
     * Get sync chunk size (max transactions per sync response)
     *
     * @return int
     */
    public function getSyncChunkSize(): int {
        return max(10, min(500, (int) ($this->get('syncChunkSize') ?? Constants::SYNC_CHUNK_SIZE)));
    }

    /**
     * Get sync max chunks (max chunk requests per sync session)
     *
     * @return int
     */
    public function getSyncMaxChunks(): int {
        return max(10, min(1000, (int) ($this->get('syncMaxChunks') ?? Constants::SYNC_MAX_CHUNKS)));
    }

    /**
     * Get held transaction sync timeout seconds
     *
     * Must be less than P2P_DEFAULT_EXPIRATION_SECONDS since P2P hops expire
     * independently on all relay nodes.
     *
     * @return int
     */
    public function getHeldTxSyncTimeoutSeconds(): int {
        $val = (int) ($this->get('heldTxSyncTimeoutSeconds') ?? Constants::HELD_TX_SYNC_TIMEOUT_SECONDS);
        return max(30, min(Constants::P2P_DEFAULT_EXPIRATION_SECONDS - 1, $val));
    }

    // =========================================================================
    // DISPLAY GETTERS
    // =========================================================================

    /**
     * Get display date format
     *
     * @return string
     */
    public function getDisplayDateFormat(): string {
        return (string) ($this->get('displayDateFormat') ?? Constants::DISPLAY_DATE_FORMAT);
    }

    /**
     * Get display currency decimals
     *
     * @return int
     */
    public function getDisplayCurrencyDecimals(): int {
        $val = (int) ($this->get('displayCurrencyDecimals') ?? Constants::DISPLAY_CURRENCY_DECIMALS);
        return max(0, min(8, $val));
    }

    /**
     * Get display recent transactions limit
     *
     * @return int
     */
    public function getDisplayRecentTransactionsLimit(): int {
        return max(1, (int) ($this->get('displayRecentTransactionsLimit') ?? Constants::DISPLAY_RECENT_TRANSACTIONS_LIMIT));
    }

    // =========================================================================
    // CONFIGURABLE DEFAULTS (canonical source of truth)
    // =========================================================================

    /**
     * Get the canonical map of all configurable settings with their default values.
     * This is the single source of truth for which settings are user-configurable
     * and what their defaults are. Used by Wallet generation, config migration,
     * and settings UI.
     *
     * @return array<string, mixed>
     */
    public static function getConfigurableDefaults(): array {
        return [
            // Transaction settings (original 11)
            'defaultCurrency' => Constants::TRANSACTION_DEFAULT_CURRENCY,
            'minFee' => Constants::TRANSACTION_MINIMUM_FEE,
            'defaultFee' => Constants::CONTACT_DEFAULT_FEE_PERCENT,
            'maxFee' => Constants::CONTACT_DEFAULT_FEE_PERCENT_MAX,
            'defaultCreditLimit' => Constants::CONTACT_DEFAULT_CREDIT_LIMIT,
            'maxP2pLevel' => Constants::P2P_DEFAULT_MAX_REQUEST_LEVEL,
            'p2pExpiration' => Constants::P2P_DEFAULT_EXPIRATION_SECONDS,
            'maxOutput' => Constants::DISPLAY_DEFAULT_OUTPUT_LINES_MAX,
            'defaultTransportMode' => Constants::DEFAULT_TRANSPORT_MODE,
            'autoRefreshEnabled' => Constants::AUTO_REFRESH_ENABLED,
            'autoBackupEnabled' => Constants::BACKUP_AUTO_ENABLED,

            // Feature toggles
            'contactStatusEnabled' => Constants::CONTACT_STATUS_ENABLED,
            'contactStatusSyncOnPing' => Constants::CONTACT_STATUS_SYNC_ON_PING,
            'autoChainDropPropose' => Constants::AUTO_CHAIN_DROP_PROPOSE,
            'autoChainDropAccept' => Constants::AUTO_CHAIN_DROP_ACCEPT,
            'apiEnabled' => Constants::API_ENABLED,
            'apiCorsAllowedOrigins' => Constants::API_CORS_ALLOWED_ORIGINS,
            'rateLimitEnabled' => Constants::RATE_LIMIT_ENABLED,

            // Backup & logging
            'backupRetentionCount' => Constants::BACKUP_RETENTION_COUNT,
            'backupCronHour' => Constants::BACKUP_CRON_HOUR,
            'backupCronMinute' => Constants::BACKUP_CRON_MINUTE,
            'logLevel' => Constants::LOG_LEVEL,
            'logMaxEntries' => Constants::LOG_MAX_ENTRIES,

            // Data retention
            'cleanupDeliveryRetentionDays' => Constants::CLEANUP_DELIVERY_RETENTION_DAYS,
            'cleanupDlqRetentionDays' => Constants::CLEANUP_DLQ_RETENTION_DAYS,
            'cleanupHeldTxRetentionDays' => Constants::CLEANUP_HELD_TX_RETENTION_DAYS,
            'cleanupRp2pRetentionDays' => Constants::CLEANUP_RP2P_RETENTION_DAYS,
            'cleanupMetricsRetentionDays' => Constants::CLEANUP_METRICS_RETENTION_DAYS,

            // Rate limiting
            'p2pRateLimitPerMinute' => Constants::P2P_RATE_LIMIT_PER_MINUTE,
            'rateLimitMaxAttempts' => Constants::RATE_LIMIT_MAX_ATTEMPTS,
            'rateLimitWindowSeconds' => Constants::RATE_LIMIT_WINDOW_SECONDS,
            'rateLimitBlockSeconds' => Constants::RATE_LIMIT_BLOCK_SECONDS,

            // Network
            'httpTransportTimeoutSeconds' => Constants::HTTP_TRANSPORT_TIMEOUT_SECONDS,
            'torTransportTimeoutSeconds' => Constants::TOR_TRANSPORT_TIMEOUT_SECONDS,

            // Sync
            'syncChunkSize' => Constants::SYNC_CHUNK_SIZE,
            'syncMaxChunks' => Constants::SYNC_MAX_CHUNKS,
            'heldTxSyncTimeoutSeconds' => Constants::HELD_TX_SYNC_TIMEOUT_SECONDS,

            // Display
            'displayDateFormat' => Constants::DISPLAY_DATE_FORMAT,
            'displayCurrencyDecimals' => Constants::DISPLAY_CURRENCY_DECIMALS,
            'displayRecentTransactionsLimit' => Constants::DISPLAY_RECENT_TRANSACTIONS_LIMIT,
        ];
    }

    /**
     * Get all user data as array
     *
     * @return array
     */
    public function toArray(): array {
        return array_diff_key($this->userData, array_flip(self::SENSITIVE_KEYS));
    }

    /**
     * Check if user context is properly initialized
     *
     * @return bool
     */
    public function isInitialized(): bool {
        return $this->initialized && !empty($this->userData);
    }

    /**
     * Clear user context (for testing or logout)
     *
     * @return void
     */
    public function clear(): void {
        $this->userData = [];
        $this->initialized = false;
    }

    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}

    /**
     * Prevent unserialization of singleton
     *
     * @return void
     * @throws Exception Always throws to prevent unserialization
     */
    public function __wakeup(): void {
        throw new Exception("Cannot unserialize singleton");
    }
}