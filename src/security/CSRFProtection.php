<?php
/**
 * CSRF Protection Utility
 *
 * Copyright 2025
 *
 * Provides comprehensive Cross-Site Request Forgery (CSRF) protection
 * for the eIOU Wallet application. This class implements industry-standard
 * CSRF protection mechanisms including:
 *
 * - Cryptographically secure token generation
 * - Session-based token storage
 * - Token validation with timing-attack protection
 * - Automatic token rotation on use
 * - Token expiration (1 hour default)
 * - One-time use tokens for critical operations
 * - CSRF violation logging and monitoring
 *
 * @package EIOU\Security
 */

class CSRFProtection
{
    /**
     * Token expiration time in seconds (default: 1 hour)
     */
    private const TOKEN_EXPIRATION = 3600;

    /**
     * Token length in bytes (will be hex-encoded to 64 characters)
     */
    private const TOKEN_LENGTH = 32;

    /**
     * Session key for CSRF token storage
     */
    private const SESSION_TOKEN_KEY = 'csrf_token';

    /**
     * Session key for token timestamp
     */
    private const SESSION_TIME_KEY = 'csrf_token_time';

    /**
     * Session key for one-time tokens
     */
    private const SESSION_ONETIME_KEY = 'csrf_onetime_tokens';

    /**
     * Log file path for CSRF violations
     */
    private const LOG_FILE = '/var/log/eiou/csrf_violations.log';

    /**
     * @var Session Session manager instance
     */
    private Session $session;

    /**
     * Constructor
     *
     * @param Session $session Session manager instance
     */
    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    /**
     * Generate a new CSRF token
     *
     * Creates a cryptographically secure random token using random_bytes().
     * The token is stored in the session along with a timestamp for expiration.
     *
     * @param bool $forceRegenerate Force generation of new token even if one exists
     * @return string The generated CSRF token (hex-encoded)
     */
    public function generateToken(bool $forceRegenerate = false): string
    {
        // Check if valid token already exists
        if (!$forceRegenerate && $this->session->has(self::SESSION_TOKEN_KEY)) {
            $tokenTime = $this->session->get(self::SESSION_TIME_KEY, 0);

            // Return existing token if not expired
            if (time() - $tokenTime < self::TOKEN_EXPIRATION) {
                return $this->session->get(self::SESSION_TOKEN_KEY);
            }
        }

        // Generate new cryptographically secure token
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));

        // Store token and timestamp in session
        $this->session->set(self::SESSION_TOKEN_KEY, $token);
        $this->session->set(self::SESSION_TIME_KEY, time());

        return $token;
    }

    /**
     * Get the current CSRF token (generates one if it doesn't exist)
     *
     * @return string The current CSRF token
     */
    public function getToken(): string
    {
        return $this->generateToken(false);
    }

    /**
     * Validate a CSRF token
     *
     * Performs constant-time comparison to prevent timing attacks.
     * Checks token existence, expiration, and value match.
     *
     * @param string $token The token to validate
     * @param bool $isOneTime Whether this is a one-time use token
     * @return bool True if token is valid, false otherwise
     */
    public function validateToken(string $token, bool $isOneTime = false): bool
    {
        // Validate one-time token if requested
        if ($isOneTime) {
            return $this->validateOneTimeToken($token);
        }

        // Check if token exists in session
        if (!$this->session->has(self::SESSION_TOKEN_KEY)) {
            $this->logViolation('Token not found in session', $token);
            return false;
        }

        // Check if token timestamp exists
        if (!$this->session->has(self::SESSION_TIME_KEY)) {
            $this->logViolation('Token timestamp missing', $token);
            return false;
        }

        // Check token expiration
        $tokenTime = $this->session->get(self::SESSION_TIME_KEY, 0);
        if (time() - $tokenTime > self::TOKEN_EXPIRATION) {
            $this->logViolation('Token expired', $token);
            // Clean up expired token
            $this->clearToken();
            return false;
        }

        // Get stored token
        $storedToken = $this->session->get(self::SESSION_TOKEN_KEY);

        // Validate token using constant-time comparison to prevent timing attacks
        if (!hash_equals($storedToken, $token)) {
            $this->logViolation('Token mismatch', $token);
            return false;
        }

        return true;
    }

    /**
     * Rotate the CSRF token
     *
     * Generates a new token and invalidates the old one.
     * Should be called after successful form submission for enhanced security.
     *
     * @return string The new CSRF token
     */
    public function rotateToken(): string
    {
        return $this->generateToken(true);
    }

    /**
     * Clear the CSRF token from session
     *
     * @return void
     */
    public function clearToken(): void
    {
        $this->session->remove(self::SESSION_TOKEN_KEY);
        $this->session->remove(self::SESSION_TIME_KEY);
    }

    /**
     * Generate a one-time use token for critical operations
     *
     * One-time tokens provide additional security for sensitive operations
     * like password changes, account deletions, or large transactions.
     *
     * @param string $operation Identifier for the operation (e.g., 'delete_account')
     * @return string The one-time token
     */
    public function generateOneTimeToken(string $operation): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));

        // Store one-time token with operation identifier and timestamp
        $oneTimeTokens = $this->session->get(self::SESSION_ONETIME_KEY, []);
        $oneTimeTokens[$token] = [
            'operation' => $operation,
            'timestamp' => time()
        ];
        $this->session->set(self::SESSION_ONETIME_KEY, $oneTimeTokens);

        return $token;
    }

    /**
     * Validate and consume a one-time token
     *
     * @param string $token The one-time token to validate
     * @param string|null $operation Optional operation identifier to verify
     * @return bool True if token is valid and consumed, false otherwise
     */
    private function validateOneTimeToken(string $token, ?string $operation = null): bool
    {
        $oneTimeTokens = $this->session->get(self::SESSION_ONETIME_KEY, []);

        // Check if token exists
        if (!isset($oneTimeTokens[$token])) {
            $this->logViolation('One-time token not found', $token);
            return false;
        }

        $tokenData = $oneTimeTokens[$token];

        // Check token expiration
        if (time() - $tokenData['timestamp'] > self::TOKEN_EXPIRATION) {
            $this->logViolation('One-time token expired', $token);
            // Clean up expired token
            unset($oneTimeTokens[$token]);
            $this->session->set(self::SESSION_ONETIME_KEY, $oneTimeTokens);
            return false;
        }

        // Verify operation if provided
        if ($operation !== null && $tokenData['operation'] !== $operation) {
            $this->logViolation('One-time token operation mismatch', $token);
            return false;
        }

        // Token is valid - consume it (remove from session)
        unset($oneTimeTokens[$token]);
        $this->session->set(self::SESSION_ONETIME_KEY, $oneTimeTokens);

        return true;
    }

    /**
     * Verify CSRF token for current request
     *
     * Checks POST requests for CSRF token and validates it.
     * Sends 403 Forbidden if validation fails.
     *
     * @param bool $autoRotate Automatically rotate token after successful validation
     * @return void
     * @throws \Exception If CSRF validation fails
     */
    public function verifyRequest(bool $autoRotate = false): void
    {
        // Only check POST, PUT, DELETE requests
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            return;
        }

        // Get token from request
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        // Check if this is a one-time token request
        $isOneTime = isset($_POST['csrf_onetime']) && $_POST['csrf_onetime'] === '1';

        if (empty($token)) {
            $this->logViolation('Missing CSRF token in request', '');
            http_response_code(403);
            die('CSRF token is required. Please refresh the page and try again.');
        }

        if (!$this->validateToken($token, $isOneTime)) {
            http_response_code(403);
            die('CSRF token validation failed. Please refresh the page and try again.');
        }

        // Optionally rotate token after successful validation
        if ($autoRotate && !$isOneTime) {
            $this->rotateToken();
        }
    }

    /**
     * Get HTML hidden input field for CSRF token
     *
     * @param bool $oneTime Generate a one-time use token
     * @param string|null $operation Operation identifier for one-time token
     * @return string HTML input field
     */
    public function getTokenField(bool $oneTime = false, ?string $operation = null): string
    {
        if ($oneTime && $operation) {
            $token = $this->generateOneTimeToken($operation);
            return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">' . "\n" .
                   '<input type="hidden" name="csrf_onetime" value="1">';
        }

        $token = $this->getToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Get CSRF token value for AJAX requests
     *
     * @return string The CSRF token value
     */
    public function getTokenValue(): string
    {
        return $this->getToken();
    }

    /**
     * Get JavaScript code to include CSRF token in AJAX requests
     *
     * @return string JavaScript code
     */
    public function getAjaxScript(): string
    {
        $token = $this->getToken();
        return <<<JS
<script>
// CSRF token for AJAX requests
const CSRF_TOKEN = '{$token}';

// Add CSRF token to all AJAX requests
document.addEventListener('DOMContentLoaded', function() {
    // Fetch API
    const originalFetch = window.fetch;
    window.fetch = function(url, options = {}) {
        if (options.method && ['POST', 'PUT', 'DELETE', 'PATCH'].includes(options.method.toUpperCase())) {
            options.headers = options.headers || {};
            if (options.body instanceof FormData) {
                options.body.append('csrf_token', CSRF_TOKEN);
            } else {
                options.headers['X-CSRF-TOKEN'] = CSRF_TOKEN;
            }
        }
        return originalFetch(url, options);
    };

    // XMLHttpRequest
    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function(method, url, async, user, password) {
        this._method = method;
        return originalOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function(data) {
        if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(this._method?.toUpperCase())) {
            this.setRequestHeader('X-CSRF-TOKEN', CSRF_TOKEN);
        }
        return originalSend.apply(this, arguments);
    };
});
</script>
JS;
    }

    /**
     * Log CSRF violation
     *
     * Records CSRF violation attempts for security monitoring.
     * Logs include timestamp, IP address, user agent, and violation details.
     *
     * @param string $reason Reason for violation
     * @param string $token The invalid token (truncated for security)
     * @return void
     */
    private function logViolation(string $reason, string $token): void
    {
        $logDir = dirname(self::LOG_FILE);

        // Create log directory if it doesn't exist
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        // Truncate token for logging (security measure)
        $tokenPreview = substr($token, 0, 8) . '...';

        // Gather request information
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $requestUri = $_SERVER['REQUEST_URI'] ?? 'unknown';
        $referer = $_SERVER['HTTP_REFERER'] ?? 'none';

        // Format log entry
        $logEntry = sprintf(
            "[%s] CSRF Violation - Reason: %s | Token: %s | IP: %s | URI: %s | Referer: %s | User-Agent: %s\n",
            $timestamp,
            $reason,
            $tokenPreview,
            $ip,
            $requestUri,
            $referer,
            $userAgent
        );

        // Write to log file
        @file_put_contents(self::LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);

        // Also log to system error log for critical monitoring
        error_log("CSRF Violation: {$reason} from {$ip}");
    }

    /**
     * Clean up expired tokens
     *
     * Should be called periodically (e.g., during session cleanup)
     * to remove expired one-time tokens from session.
     *
     * @return int Number of tokens cleaned up
     */
    public function cleanupExpiredTokens(): int
    {
        $oneTimeTokens = $this->session->get(self::SESSION_ONETIME_KEY, []);
        $originalCount = count($oneTimeTokens);
        $currentTime = time();

        // Remove expired tokens
        $oneTimeTokens = array_filter($oneTimeTokens, function($tokenData) use ($currentTime) {
            return ($currentTime - $tokenData['timestamp']) < self::TOKEN_EXPIRATION;
        });

        $this->session->set(self::SESSION_ONETIME_KEY, $oneTimeTokens);

        return $originalCount - count($oneTimeTokens);
    }

    /**
     * Get CSRF protection statistics
     *
     * Returns information about current token status for debugging/monitoring.
     *
     * @return array Statistics array
     */
    public function getStatistics(): array
    {
        $hasToken = $this->session->has(self::SESSION_TOKEN_KEY);
        $tokenAge = 0;
        $isExpired = true;

        if ($hasToken) {
            $tokenTime = $this->session->get(self::SESSION_TIME_KEY, 0);
            $tokenAge = time() - $tokenTime;
            $isExpired = $tokenAge > self::TOKEN_EXPIRATION;
        }

        $oneTimeTokens = $this->session->get(self::SESSION_ONETIME_KEY, []);

        return [
            'has_token' => $hasToken,
            'token_age_seconds' => $tokenAge,
            'is_expired' => $isExpired,
            'onetime_tokens_count' => count($oneTimeTokens),
            'token_expiration_seconds' => self::TOKEN_EXPIRATION
        ];
    }
}
