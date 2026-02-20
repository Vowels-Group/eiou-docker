<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\ErrorCodes;
use Eiou\Contracts\ApiAuthServiceInterface;
use Eiou\Database\ApiKeyRepository;
use Eiou\Utils\Logger;
use Eiou\Utils\Security;

/**
 * API Authentication Service with HMAC Signature Verification
 *
 * Provides secure API authentication using:
 * - API Key identification
 * - HMAC-SHA256 request signing (server-side verification)
 * - Timestamp validation (prevents replay attacks)
 * - Rate limiting per API key
 *
 * Security: API secrets are stored encrypted in the database and retrieved
 * only when needed for HMAC verification. The client never sends the secret
 * in requests - only the computed HMAC signature.
 */

class ApiAuthService implements ApiAuthServiceInterface {
    private ApiKeyRepository $apiKeyRepository;
    private $logger;

    /**
     * Maximum age of a request timestamp in seconds (5 minutes)
     */
    private const MAX_REQUEST_AGE = 300;

    /**
     * HMAC algorithm to use
     */
    private const HMAC_ALGORITHM = 'sha256';

    /**
     * Constructor
     *
     * @param ApiKeyRepository $apiKeyRepository
     * @param Logger|null $logger
     */
    public function __construct($apiKeyRepository, $logger = null) {
        $this->apiKeyRepository = $apiKeyRepository;
        $this->logger = $logger;
    }

    /**
     * Authenticate an API request
     *
     * Required headers:
     * - X-API-Key: The API key ID (eiou_...)
     * - X-API-Timestamp: Unix timestamp of request
     * - X-API-Signature: HMAC-SHA256 signature
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path Request path (e.g., /api/v1/wallet/balance)
     * @param string $body Request body (empty string for GET)
     * @param array $headers Request headers
     * @return array ['success' => bool, 'key' => array|null, 'error' => string|null]
     */
    public function authenticate(
        string $method,
        string $path,
        string $body,
        array $headers
    ): array {
        // Extract required headers (case-insensitive)
        $apiKey = $this->getHeader($headers, 'X-API-Key');
        $timestamp = $this->getHeader($headers, 'X-API-Timestamp');
        $signature = $this->getHeader($headers, 'X-API-Signature');
        $nonce = $this->getHeader($headers, 'X-API-Nonce');

        // Validate all required headers are present
        if (!$apiKey) {
            return $this->authError('Missing X-API-Key header', ErrorCodes::AUTH_MISSING_KEY);
        }
        if (!$timestamp) {
            return $this->authError('Missing X-API-Timestamp header', ErrorCodes::AUTH_MISSING_TIMESTAMP);
        }
        if (!$signature) {
            return $this->authError('Missing X-API-Signature header', ErrorCodes::AUTH_MISSING_SIGNATURE);
        }
        if (!$nonce) {
            return $this->authError('Missing X-API-Nonce header', ErrorCodes::AUTH_MISSING_NONCE);
        }
        if (strlen($nonce) < 8 || strlen($nonce) > 64) {
            return $this->authError('Invalid nonce format', ErrorCodes::AUTH_INVALID_NONCE);
        }

        // Validate timestamp format
        if (!is_numeric($timestamp)) {
            return $this->authError('Invalid timestamp format', ErrorCodes::AUTH_INVALID_TIMESTAMP);
        }

        // Validate timestamp age (prevent replay attacks)
        $timestampInt = (int) $timestamp;
        $now = time();
        if (abs($now - $timestampInt) > self::MAX_REQUEST_AGE) {
            return $this->authError(
                'Request timestamp too old or in the future',
                ErrorCodes::AUTH_EXPIRED_TIMESTAMP
            );
        }

        // Look up the API key to get the secret hash
        $keyData = $this->apiKeyRepository->getByKeyId($apiKey);
        if (!$keyData) {
            return $this->authError('Invalid or inactive API key', ErrorCodes::AUTH_INVALID_KEY);
        }

        // Check if key is enabled (generic message to prevent key enumeration)
        if (!$keyData['enabled']) {
            return $this->authError('Invalid or inactive API key', ErrorCodes::AUTH_KEY_DISABLED);
        }

        // Check if key is expired (generic message to prevent key enumeration)
        if ($keyData['expires_at'] && strtotime($keyData['expires_at']) < time()) {
            return $this->authError('Invalid or inactive API key', ErrorCodes::AUTH_KEY_EXPIRED);
        }

        // Check rate limit
        $requestCount = $this->apiKeyRepository->getRequestCount($apiKey, 60);
        if ($requestCount >= $keyData['rate_limit_per_minute']) {
            return $this->authError(
                'Rate limit exceeded',
                ErrorCodes::RATE_LIMIT_EXCEEDED
            );
        }

        // Nonce replay protection — reject duplicate nonces within the timestamp window
        if (!$this->apiKeyRepository->checkAndStoreNonce($apiKey, $nonce, self::MAX_REQUEST_AGE)) {
            return $this->authError('Duplicate nonce (possible replay)', ErrorCodes::AUTH_REPLAY_DETECTED);
        }

        // Retrieve decrypted secret from database for HMAC verification
        // The secret is stored encrypted, not hashed, allowing server-side HMAC computation
        $secret = $this->apiKeyRepository->getSecretByKeyId($apiKey);
        if (!$secret) {
            return $this->authError('Invalid API credentials', ErrorCodes::AUTH_INVALID_CREDENTIALS);
        }

        // Build the string to sign (includes nonce for replay protection)
        $stringToSign = $this->buildStringToSign($method, $path, $timestamp, $body, $nonce);

        // Calculate expected HMAC using the decrypted secret
        $expectedHmac = hash_hmac(self::HMAC_ALGORITHM, $stringToSign, $secret);

        // Constant-time comparison to prevent timing attacks
        // Client sends only the HMAC signature, not the secret
        if (!hash_equals($expectedHmac, $signature)) {
            $this->log('warning', 'HMAC signature mismatch', [
                'key_id' => $apiKey,
                'path' => $path
            ]);

            // Clear secret from memory
            if (function_exists('sodium_memzero')) {
                sodium_memzero($secret);
            }

            return $this->authError('Invalid signature', ErrorCodes::AUTH_INVALID_SIGNATURE);
        }

        // Clear secret from memory after use
        if (function_exists('sodium_memzero')) {
            sodium_memzero($secret);
        }

        // Get full key data for return
        $keyResult = $keyData;
        $keyResult['permissions'] = $keyData['permissions'];

        // Update last used timestamp
        $this->apiKeyRepository->updateLastUsed($apiKey);

        // Authentication successful
        $this->log('info', 'API authentication successful', [
            'key_id' => $apiKey,
            'key_name' => $keyData['name'],
            'path' => $path
        ]);

        return [
            'success' => true,
            'key' => $keyResult,
            'error' => null,
            'code' => null
        ];
    }

    /**
     * Build the string to sign for HMAC
     *
     * @param string $method HTTP method
     * @param string $path Request path
     * @param string $timestamp Unix timestamp
     * @param string $body Request body
     * @param string $nonce Unique request nonce for replay protection
     * @return string String to sign
     */
    public function buildStringToSign(
        string $method,
        string $path,
        string $timestamp,
        string $body,
        string $nonce = ''
    ): string {
        // Normalize method to uppercase
        $method = strtoupper($method);

        // Build canonical string: METHOD\nPATH\nTIMESTAMP\nNONCE\nBODY
        return implode("\n", [
            $method,
            $path,
            $timestamp,
            $nonce,
            $body
        ]);
    }

    /**
     * Generate a signature for a request (client-side helper)
     *
     * The client computes HMAC-SHA256(secret, stringToSign) and sends only the
     * resulting signature. The secret is NEVER transmitted in the request.
     *
     * @param string $secret The API secret
     * @param string $method HTTP method
     * @param string $path Request path
     * @param string $timestamp Unix timestamp
     * @param string $body Request body
     * @param string $nonce Unique request nonce for replay protection
     * @return string The HMAC signature (hex encoded)
     */
    public static function generateSignature(
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $body = '',
        string $nonce = ''
    ): string {
        $stringToSign = implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            $body
        ]);

        return hash_hmac(self::HMAC_ALGORITHM, $stringToSign, $secret);
    }

    /**
     * Get a header value (case-insensitive)
     *
     * @param array $headers Headers array
     * @param string $name Header name
     * @return string|null Header value or null
     */
    private function getHeader(array $headers, string $name): ?string {
        // Try exact match first
        if (isset($headers[$name])) {
            return $headers[$name];
        }

        // Try case-insensitive match
        $nameLower = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $nameLower) {
                return $value;
            }
        }

        // Try HTTP_ prefix (for $_SERVER style headers)
        $httpName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($headers[$httpName])) {
            return $headers[$httpName];
        }

        return null;
    }

    /**
     * Create an authentication error response
     *
     * @param string $message Error message
     * @param string $code Error code (use ErrorCodes constants)
     * @return array Error response
     */
    private function authError(string $message, string $code): array {
        $this->log('warning', 'API authentication failed: ' . $message, [
            'code' => $code
        ]);

        return [
            'success' => false,
            'key' => null,
            'error' => $message,
            'code' => $code
        ];
    }

    /**
     * Log a message if logger is available
     *
     * @param string $level Log level
     * @param string $message Message
     * @param array $context Context data
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logger) {
            $this->logger->$level($message, $context);
        }
    }

    /**
     * Check if authenticated key has a specific permission
     *
     * @param array $keyData Key data from authentication
     * @param string $permission Permission to check
     * @return bool True if permission is granted
     */
    public function hasPermission(array $keyData, string $permission): bool {
        return ApiKeyRepository::hasPermission($keyData['permissions'], $permission);
    }

    /**
     * Get all request headers from $_SERVER
     *
     * @return array Headers array
     */
    public static function getRequestHeaders(): array {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                // Convert HTTP_X_API_KEY to X-Api-Key
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[$headerName] = $value;
            }
        }

        // Also check for headers that don't have HTTP_ prefix
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * Get client IP address
     *
     * Delegates to Security::getClientIp() which only trusts proxy headers
     * when REMOTE_ADDR is in the trusted proxies list.
     *
     * @return string IP address
     */
    public static function getClientIp(): string {
        return Security::getClientIp();
    }
}
