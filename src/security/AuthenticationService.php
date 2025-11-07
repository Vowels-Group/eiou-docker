<?php
/**
 * AuthenticationService - Hardened Authentication for eIOU Wallet
 *
 * Copyright 2025
 *
 * Implements comprehensive authentication security including:
 * - Account lockout after failed attempts
 * - Rate limiting for login attempts
 * - Secure password hashing with bcrypt (cost >= 12)
 * - Constant-time comparison to prevent timing attacks
 * - Login attempt logging and monitoring
 * - Preparation for two-factor authentication
 */

require_once dirname(__DIR__) . '/core/Constants.php';
require_once dirname(__DIR__) . '/utils/SecureLogger.php';
require_once __DIR__ . '/SessionManager.php';

class AuthenticationService
{
    private PDO $pdo;
    private SessionManager $sessionManager;

    // Security constants
    private const MAX_LOGIN_ATTEMPTS = 5;           // Maximum failed attempts before lockout
    private const LOCKOUT_DURATION = 900;           // 15 minutes lockout
    private const ATTEMPT_WINDOW = 300;             // 5 minute window for counting attempts
    private const PASSWORD_MIN_LENGTH = 8;          // Minimum password length
    private const BCRYPT_COST = 12;                 // bcrypt cost factor (recommended: 10-12)

    // Account status constants
    private const STATUS_ACTIVE = 'active';
    private const STATUS_LOCKED = 'locked';
    private const STATUS_SUSPENDED = 'suspended';

    /**
     * Initialize authentication service
     *
     * @param PDO $pdo Database connection
     * @param SessionManager $sessionManager Session manager instance
     */
    public function __construct(PDO $pdo, SessionManager $sessionManager)
    {
        $this->pdo = $pdo;
        $this->sessionManager = $sessionManager;
        $this->initializeTables();
    }

    /**
     * Create authentication tables if they don't exist
     *
     * @return void
     */
    private function initializeTables(): void
    {
        // Login attempts tracking table
        $sql = "CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            identifier VARCHAR(255) NOT NULL,
            attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT,
            success BOOLEAN DEFAULT FALSE,
            INDEX idx_identifier (identifier),
            INDEX idx_attempt_time (attempt_time),
            INDEX idx_ip_address (ip_address)
        )";

        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            SecureLogger::error('Failed to create login_attempts table', [
                'error' => $e->getMessage()
            ]);
        }

        // Account lockout tracking table
        $sql = "CREATE TABLE IF NOT EXISTS account_lockouts (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            identifier VARCHAR(255) NOT NULL UNIQUE,
            locked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            locked_until TIMESTAMP NOT NULL,
            reason VARCHAR(100),
            attempt_count INTEGER DEFAULT 0,
            INDEX idx_identifier (identifier),
            INDEX idx_locked_until (locked_until)
        )";

        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            SecureLogger::error('Failed to create account_lockouts table', [
                'error' => $e->getMessage()
            ]);
        }

        // Auth codes table (stores hashed auth codes)
        $sql = "CREATE TABLE IF NOT EXISTS auth_codes (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            identifier VARCHAR(255) NOT NULL UNIQUE,
            code_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_used TIMESTAMP NULL,
            status VARCHAR(20) DEFAULT 'active',
            INDEX idx_identifier (identifier),
            INDEX idx_status (status)
        )";

        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            SecureLogger::error('Failed to create auth_codes table', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Authenticate user with auth code
     *
     * @param string $authCode User-provided auth code
     * @param string $identifier User identifier (username, email, etc.)
     * @return bool True if authentication successful
     */
    public function authenticate(string $authCode, string $identifier = 'wallet'): bool
    {
        $ipAddress = $this->getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Check if account is locked
        if ($this->isAccountLocked($identifier)) {
            $this->logLoginAttempt($identifier, $ipAddress, $userAgent, false);

            SecureLogger::warning('Login attempt on locked account', [
                'identifier' => $identifier,
                'ip' => $ipAddress
            ]);

            // Add random delay to prevent timing attacks
            $this->randomDelay();
            return false;
        }

        // Check rate limiting
        if ($this->isRateLimited($identifier, $ipAddress)) {
            SecureLogger::warning('Rate limit exceeded for login', [
                'identifier' => $identifier,
                'ip' => $ipAddress
            ]);

            // Add random delay
            $this->randomDelay();
            return false;
        }

        // Get stored auth code hash
        $storedHash = $this->getAuthCodeHash($identifier);

        if ($storedHash === null) {
            // No auth code found - authentication fails
            $this->logLoginAttempt($identifier, $ipAddress, $userAgent, false);
            $this->handleFailedAttempt($identifier);

            SecureLogger::warning('Authentication failed - no auth code found', [
                'identifier' => $identifier,
                'ip' => $ipAddress
            ]);

            $this->randomDelay();
            return false;
        }

        // Verify auth code using constant-time comparison
        if ($this->verifyAuthCode($authCode, $storedHash)) {
            // Authentication successful
            $this->logLoginAttempt($identifier, $ipAddress, $userAgent, true);
            $this->clearFailedAttempts($identifier);
            $this->updateLastUsed($identifier);

            // Establish authenticated session
            $this->sessionManager->authenticate($identifier, [
                'auth_method' => 'authcode',
                'ip_address' => $ipAddress
            ]);

            SecureLogger::info('Authentication successful', [
                'identifier' => $identifier,
                'ip' => $ipAddress
            ]);

            return true;
        }

        // Authentication failed
        $this->logLoginAttempt($identifier, $ipAddress, $userAgent, false);
        $this->handleFailedAttempt($identifier);

        SecureLogger::warning('Authentication failed - invalid auth code', [
            'identifier' => $identifier,
            'ip' => $ipAddress
        ]);

        // Random delay to prevent timing attacks
        $this->randomDelay();
        return false;
    }

    /**
     * Verify auth code against stored hash
     *
     * @param string $authCode Provided auth code
     * @param string $storedHash Stored hash
     * @return bool
     */
    private function verifyAuthCode(string $authCode, string $storedHash): bool
    {
        // Support both hashed and plain-text comparison (for migration)
        // First try password_verify (bcrypt)
        if (password_verify($authCode, $storedHash)) {
            return true;
        }

        // Fall back to constant-time string comparison (for plain-text codes)
        // This is for backward compatibility - new codes should be hashed
        return hash_equals($storedHash, $authCode);
    }

    /**
     * Check if account is currently locked
     *
     * @param string $identifier User identifier
     * @return bool
     */
    public function isAccountLocked(string $identifier): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM account_lockouts
            WHERE identifier = ?
            AND locked_until > NOW()
        ");
        $stmt->execute([$identifier]);

        $lockout = $stmt->fetch();

        if ($lockout) {
            return true;
        }

        return false;
    }

    /**
     * Get account lockout information
     *
     * @param string $identifier User identifier
     * @return array|null Lockout info or null
     */
    public function getLockoutInfo(string $identifier): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM account_lockouts
            WHERE identifier = ?
            AND locked_until > NOW()
        ");
        $stmt->execute([$identifier]);

        $lockout = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($lockout) {
            return [
                'locked_until' => $lockout['locked_until'],
                'reason' => $lockout['reason'],
                'attempt_count' => $lockout['attempt_count'],
                'remaining_seconds' => strtotime($lockout['locked_until']) - time()
            ];
        }

        return null;
    }

    /**
     * Check if login attempts are rate limited
     *
     * @param string $identifier User identifier
     * @param string $ipAddress IP address
     * @return bool
     */
    private function isRateLimited(string $identifier, string $ipAddress): bool
    {
        // Check attempts by identifier
        $identifierAttempts = $this->getRecentAttemptCount($identifier, self::ATTEMPT_WINDOW);

        // Check attempts by IP
        $ipAttempts = $this->getRecentAttemptCountByIp($ipAddress, self::ATTEMPT_WINDOW);

        // Rate limit if too many attempts from either identifier or IP
        return $identifierAttempts >= self::MAX_LOGIN_ATTEMPTS
            || $ipAttempts >= (self::MAX_LOGIN_ATTEMPTS * 2); // IP can have more attempts
    }

    /**
     * Get number of recent login attempts
     *
     * @param string $identifier User identifier
     * @param int $windowSeconds Time window in seconds
     * @return int
     */
    private function getRecentAttemptCount(string $identifier, int $windowSeconds): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM login_attempts
            WHERE identifier = ?
            AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)
            AND success = FALSE
        ");
        $stmt->execute([$identifier, $windowSeconds]);

        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }

    /**
     * Get number of recent login attempts by IP
     *
     * @param string $ipAddress IP address
     * @param int $windowSeconds Time window in seconds
     * @return int
     */
    private function getRecentAttemptCountByIp(string $ipAddress, int $windowSeconds): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM login_attempts
            WHERE ip_address = ?
            AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)
            AND success = FALSE
        ");
        $stmt->execute([$ipAddress, $windowSeconds]);

        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }

    /**
     * Handle failed authentication attempt
     *
     * @param string $identifier User identifier
     * @return void
     */
    private function handleFailedAttempt(string $identifier): void
    {
        $attemptCount = $this->getRecentAttemptCount($identifier, self::ATTEMPT_WINDOW);

        if ($attemptCount >= self::MAX_LOGIN_ATTEMPTS) {
            // Lock the account
            $this->lockAccount($identifier, self::LOCKOUT_DURATION, 'too_many_failed_attempts');
        }
    }

    /**
     * Lock account for specified duration
     *
     * @param string $identifier User identifier
     * @param int $durationSeconds Lockout duration in seconds
     * @param string $reason Lockout reason
     * @return void
     */
    public function lockAccount(string $identifier, int $durationSeconds, string $reason = 'manual'): void
    {
        $lockedUntil = date('Y-m-d H:i:s', time() + $durationSeconds);
        $attemptCount = $this->getRecentAttemptCount($identifier, self::ATTEMPT_WINDOW);

        $stmt = $this->pdo->prepare("
            INSERT INTO account_lockouts (identifier, locked_until, reason, attempt_count)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                locked_until = VALUES(locked_until),
                reason = VALUES(reason),
                attempt_count = VALUES(attempt_count),
                locked_at = NOW()
        ");
        $stmt->execute([$identifier, $lockedUntil, $reason, $attemptCount]);

        SecureLogger::warning('Account locked', [
            'identifier' => $identifier,
            'duration' => $durationSeconds,
            'reason' => $reason,
            'attempt_count' => $attemptCount
        ]);
    }

    /**
     * Unlock account
     *
     * @param string $identifier User identifier
     * @return void
     */
    public function unlockAccount(string $identifier): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM account_lockouts
            WHERE identifier = ?
        ");
        $stmt->execute([$identifier]);

        // Clear failed attempts
        $this->clearFailedAttempts($identifier);

        SecureLogger::info('Account unlocked', [
            'identifier' => $identifier
        ]);
    }

    /**
     * Clear failed login attempts
     *
     * @param string $identifier User identifier
     * @return void
     */
    private function clearFailedAttempts(string $identifier): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM login_attempts
            WHERE identifier = ?
            AND success = FALSE
        ");
        $stmt->execute([$identifier]);
    }

    /**
     * Log login attempt
     *
     * @param string $identifier User identifier
     * @param string $ipAddress IP address
     * @param string $userAgent User agent
     * @param bool $success Whether attempt was successful
     * @return void
     */
    private function logLoginAttempt(
        string $identifier,
        string $ipAddress,
        string $userAgent,
        bool $success
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO login_attempts (identifier, ip_address, user_agent, success)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$identifier, $ipAddress, $userAgent, $success]);
    }

    /**
     * Get stored auth code hash
     *
     * @param string $identifier User identifier
     * @return string|null
     */
    private function getAuthCodeHash(string $identifier): ?string
    {
        $stmt = $this->pdo->prepare("
            SELECT code_hash FROM auth_codes
            WHERE identifier = ?
            AND status = ?
        ");
        $stmt->execute([$identifier, self::STATUS_ACTIVE]);

        $result = $stmt->fetch();
        return $result['code_hash'] ?? null;
    }

    /**
     * Store or update auth code (hashed)
     *
     * @param string $identifier User identifier
     * @param string $authCode Plain-text auth code
     * @return bool
     */
    public function setAuthCode(string $identifier, string $authCode): bool
    {
        // Validate auth code strength
        if (strlen($authCode) < self::PASSWORD_MIN_LENGTH) {
            SecureLogger::warning('Auth code too short', [
                'identifier' => $identifier,
                'length' => strlen($authCode)
            ]);
            return false;
        }

        // Hash auth code with bcrypt
        $hash = password_hash($authCode, PASSWORD_BCRYPT, [
            'cost' => self::BCRYPT_COST
        ]);

        if ($hash === false) {
            SecureLogger::error('Failed to hash auth code', [
                'identifier' => $identifier
            ]);
            return false;
        }

        // Store or update hashed code
        $stmt = $this->pdo->prepare("
            INSERT INTO auth_codes (identifier, code_hash, status)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                code_hash = VALUES(code_hash),
                updated_at = NOW(),
                status = VALUES(status)
        ");

        try {
            $stmt->execute([$identifier, $hash, self::STATUS_ACTIVE]);

            SecureLogger::info('Auth code updated', [
                'identifier' => $identifier
            ]);

            return true;
        } catch (PDOException $e) {
            SecureLogger::error('Failed to store auth code', [
                'identifier' => $identifier,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Update last used timestamp
     *
     * @param string $identifier User identifier
     * @return void
     */
    private function updateLastUsed(string $identifier): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE auth_codes
            SET last_used = NOW()
            WHERE identifier = ?
        ");
        $stmt->execute([$identifier]);
    }

    /**
     * Get client IP address
     *
     * @return string
     */
    private function getClientIp(): string
    {
        $ipKeys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }

        return '0.0.0.0';
    }

    /**
     * Add random delay to prevent timing attacks
     *
     * @return void
     */
    private function randomDelay(): void
    {
        // Random delay between 100ms and 500ms
        $delayMicroseconds = random_int(100000, 500000);
        usleep($delayMicroseconds);
    }

    /**
     * Clean up old login attempts and expired lockouts
     *
     * @param int $olderThanSeconds Remove records older than this
     * @return void
     */
    public function cleanup(int $olderThanSeconds = 86400): void
    {
        try {
            // Clean old login attempts (older than specified time)
            $stmt = $this->pdo->prepare("
                DELETE FROM login_attempts
                WHERE attempt_time < DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$olderThanSeconds]);

            // Clean expired lockouts
            $stmt = $this->pdo->prepare("
                DELETE FROM account_lockouts
                WHERE locked_until < NOW()
            ");
            $stmt->execute();

            SecureLogger::debug('Authentication cleanup completed', [
                'older_than' => $olderThanSeconds
            ]);
        } catch (PDOException $e) {
            SecureLogger::error('Authentication cleanup failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get login attempt history
     *
     * @param string $identifier User identifier
     * @param int $limit Maximum number of records
     * @return array
     */
    public function getLoginHistory(string $identifier, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT attempt_time, ip_address, success
            FROM login_attempts
            WHERE identifier = ?
            ORDER BY attempt_time DESC
            LIMIT ?
        ");
        $stmt->execute([$identifier, $limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if auth code needs rehashing (algorithm updated)
     *
     * @param string $identifier User identifier
     * @return bool
     */
    public function needsRehash(string $identifier): bool
    {
        $hash = $this->getAuthCodeHash($identifier);

        if ($hash === null) {
            return false;
        }

        return password_needs_rehash($hash, PASSWORD_BCRYPT, [
            'cost' => self::BCRYPT_COST
        ]);
    }

    /**
     * Migrate plain-text auth code to hashed version
     *
     * @param string $identifier User identifier
     * @param string $plainAuthCode Plain-text auth code
     * @return bool
     */
    public function migratePlainAuthCode(string $identifier, string $plainAuthCode): bool
    {
        return $this->setAuthCode($identifier, $plainAuthCode);
    }
}
