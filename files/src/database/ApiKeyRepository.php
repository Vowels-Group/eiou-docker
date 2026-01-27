<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Repository for managing API keys
 *
 * Handles CRUD operations for API keys with secure storage.
 * Secrets are stored encrypted (not hashed) to enable server-side HMAC verification.
 */

require_once dirname(__DIR__) . '/database/AbstractRepository.php';
require_once dirname(__DIR__) . '/security/KeyEncryption.php';

class ApiKeyRepository extends AbstractRepository {
    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'key_id', 'encrypted_secret', 'name', 'permissions',
        'rate_limit_per_minute', 'enabled', 'created_at', 'last_used_at', 'expires_at'
    ];

    /**
     * Generate a new API key
     *
     * @param string $name Human-readable name for the key
     * @param array $permissions Array of allowed permissions
     * @param int|null $rateLimitPerMinute Custom rate limit (default: 100)
     * @param string|null $expiresAt Expiration timestamp (null = never expires)
     * @return array ['key_id' => string, 'secret' => string] - secret is only returned once!
     */
    public function createKey(
        string $name,
        array $permissions = ['wallet:read'],
        ?int $rateLimitPerMinute = 100,
        ?string $expiresAt = null
    ): array {
        // Generate unique key_id (public identifier)
        $keyId = 'eiou_' . bin2hex(random_bytes(12));

        // Generate secret key (shown only once to user)
        $secret = bin2hex(random_bytes(32));

        // Encrypt the secret for secure storage (allows retrieval for HMAC verification)
        $encryptedSecret = KeyEncryption::encrypt($secret);

        $sql = "INSERT INTO api_keys (key_id, encrypted_secret, name, permissions, rate_limit_per_minute, expires_at)
                VALUES (:key_id, :encrypted_secret, :name, :permissions, :rate_limit, :expires_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':key_id' => $keyId,
            ':encrypted_secret' => json_encode($encryptedSecret),
            ':name' => $name,
            ':permissions' => json_encode($permissions),
            ':rate_limit' => $rateLimitPerMinute,
            ':expires_at' => $expiresAt
        ]);

        return [
            'key_id' => $keyId,
            'secret' => $secret,
            'name' => $name,
            'permissions' => $permissions,
            'rate_limit_per_minute' => $rateLimitPerMinute,
            'expires_at' => $expiresAt
        ];
    }

    /**
     * Get API key by key_id (without validating secret)
     *
     * @param string $keyId The public key identifier
     * @return array|null Key details if found
     */
    public function getByKeyId(string $keyId): ?array {
        $sql = "SELECT id, key_id, name, permissions, rate_limit_per_minute, enabled, created_at, last_used_at, expires_at
                FROM api_keys
                WHERE key_id = :key_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':key_id' => $keyId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $result['permissions'] = json_decode($result['permissions'], true);
        }

        return $result ?: null;
    }

    /**
     * Get decrypted API secret by key_id
     *
     * Retrieves and decrypts the API secret for server-side HMAC verification.
     * This method should only be called during authentication.
     *
     * @param string $keyId The public key identifier
     * @return string|null Decrypted secret if found and valid, null otherwise
     */
    public function getSecretByKeyId(string $keyId): ?string {
        $sql = "SELECT encrypted_secret
                FROM api_keys
                WHERE key_id = :key_id
                AND enabled = 1
                AND (expires_at IS NULL OR expires_at > NOW())";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':key_id' => $keyId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result || empty($result['encrypted_secret'])) {
            return null;
        }

        try {
            $encryptedData = json_decode($result['encrypted_secret'], true);
            if (!$encryptedData) {
                return null;
            }
            return KeyEncryption::decrypt($encryptedData);
        } catch (Exception $e) {
            // Log decryption failure but don't expose details
            error_log('API key decryption failed for key_id: ' . $keyId);
            return null;
        }
    }

    /**
     * List all API keys (without secrets)
     *
     * @param bool $includeDisabled Include disabled keys
     * @return array List of API keys
     */
    public function listKeys(bool $includeDisabled = false): array {
        $sql = "SELECT id, key_id, name, permissions, rate_limit_per_minute, enabled, created_at, last_used_at, expires_at
                FROM api_keys";

        if (!$includeDisabled) {
            $sql .= " WHERE enabled = 1";
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->pdo->query($sql);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($results as &$result) {
            $result['permissions'] = json_decode($result['permissions'], true);
        }

        return $results;
    }

    /**
     * Update last used timestamp for an API key
     *
     * @param string $keyId The public key identifier
     */
    public function updateLastUsed(string $keyId): void {
        $sql = "UPDATE api_keys SET last_used_at = NOW(6) WHERE key_id = :key_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':key_id' => $keyId]);
    }

    /**
     * Disable an API key
     *
     * @param string $keyId The public key identifier
     * @return bool True if key was disabled
     */
    public function disableKey(string $keyId): bool {
        $sql = "UPDATE api_keys SET enabled = 0 WHERE key_id = :key_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':key_id' => $keyId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Enable an API key
     *
     * @param string $keyId The public key identifier
     * @return bool True if key was enabled
     */
    public function enableKey(string $keyId): bool {
        $sql = "UPDATE api_keys SET enabled = 1 WHERE key_id = :key_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':key_id' => $keyId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete an API key permanently
     *
     * @param string $keyId The public key identifier
     * @return bool True if key was deleted
     */
    public function deleteKey(string $keyId): bool {
        $sql = "DELETE FROM api_keys WHERE key_id = :key_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':key_id' => $keyId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update API key permissions
     *
     * @param string $keyId The public key identifier
     * @param array $permissions New permissions array
     * @return bool True if updated
     */
    public function updatePermissions(string $keyId, array $permissions): bool {
        $sql = "UPDATE api_keys SET permissions = :permissions WHERE key_id = :key_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':key_id' => $keyId,
            ':permissions' => json_encode($permissions)
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Log an API request
     *
     * @param string $keyId API key used
     * @param string $endpoint Endpoint accessed
     * @param string $method HTTP method
     * @param string $ipAddress Client IP
     * @param int $responseCode HTTP response code
     * @param int|null $responseTimeMs Response time in milliseconds
     */
    public function logRequest(
        string $keyId,
        string $endpoint,
        string $method,
        string $ipAddress,
        int $responseCode,
        ?int $responseTimeMs = null
    ): void {
        $sql = "INSERT INTO api_request_log (key_id, endpoint, method, ip_address, response_code, response_time_ms)
                VALUES (:key_id, :endpoint, :method, :ip_address, :response_code, :response_time_ms)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':key_id' => $keyId,
            ':endpoint' => $endpoint,
            ':method' => $method,
            ':ip_address' => $ipAddress,
            ':response_code' => $responseCode,
            ':response_time_ms' => $responseTimeMs
        ]);
    }

    /**
     * Get request count for rate limiting
     *
     * @param string $keyId API key
     * @param int $windowSeconds Time window in seconds
     * @return int Number of requests in the window
     */
    public function getRequestCount(string $keyId, int $windowSeconds = 60): int {
        $sql = "SELECT COUNT(*) as count FROM api_request_log
                WHERE key_id = :key_id
                AND request_timestamp > DATE_SUB(NOW(6), INTERVAL :window SECOND)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':key_id' => $keyId,
            ':window' => $windowSeconds
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $result['count'];
    }

    /**
     * Check if a key has a specific permission
     *
     * @param array $keyPermissions The key's permissions array
     * @param string $requiredPermission Permission to check (e.g., 'wallet:read')
     * @return bool True if permission is granted
     */
    public static function hasPermission(array $keyPermissions, string $requiredPermission): bool {
        // Check for 'all' permission (or legacy '*' for backwards compatibility)
        if (in_array('all', $keyPermissions) || in_array('*', $keyPermissions) || in_array('admin', $keyPermissions)) {
            return true;
        }

        // Check for exact match
        if (in_array($requiredPermission, $keyPermissions)) {
            return true;
        }

        // Check for category wildcard (e.g., 'wallet:*' grants 'wallet:read')
        $parts = explode(':', $requiredPermission);
        if (count($parts) === 2) {
            $categoryWildcard = $parts[0] . ':*';
            if (in_array($categoryWildcard, $keyPermissions)) {
                return true;
            }
        }

        return false;
    }
}
