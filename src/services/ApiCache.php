<?php
# Copyright 2025

/**
 * API Cache Service
 *
 * Provides intelligent caching for API responses with TTL-based expiration.
 * Uses APCu if available, falls back to file-based caching.
 *
 * Performance Features:
 * - In-memory caching (APCu) for maximum speed
 * - File-based fallback for compatibility
 * - TTL-based automatic expiration
 * - Cache invalidation on write operations
 * - Statistics tracking for monitoring
 *
 * @package Services
 */

class ApiCache {
    /**
     * @var string Cache storage directory for file-based cache
     */
    private const CACHE_DIR = '/tmp/eiou-cache';

    /**
     * @var bool Whether APCu is available
     */
    private bool $apcu_available;

    /**
     * @var array Cache statistics
     */
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0
    ];

    /**
     * Cache TTL configurations (in seconds)
     */
    private const TTL_BALANCE = 10;          // Balance queries
    private const TTL_CONTACTS = 30;         // Contact list
    private const TTL_TRANSACTIONS = 60;     // Transaction history
    private const TTL_CONTAINER_STATUS = 5;  // Docker container status
    private const TTL_USER_INFO = 30;        // User information
    private const TTL_DEFAULT = 30;          // Default TTL

    /**
     * Constructor - Initialize cache system
     */
    public function __construct() {
        $this->apcu_available = function_exists('apcu_fetch') && ini_get('apc.enabled');

        // Create cache directory if using file-based cache
        if (!$this->apcu_available && !is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0755, true);
        }
    }

    /**
     * Get TTL for a specific cache type
     *
     * @param string $type Cache type (balance, contacts, transactions, etc.)
     * @return int TTL in seconds
     */
    private function getTTL(string $type): int {
        return match($type) {
            'balance' => self::TTL_BALANCE,
            'contacts' => self::TTL_CONTACTS,
            'transactions' => self::TTL_TRANSACTIONS,
            'container_status' => self::TTL_CONTAINER_STATUS,
            'user_info' => self::TTL_USER_INFO,
            default => self::TTL_DEFAULT
        };
    }

    /**
     * Get value from cache
     *
     * @param string $key Cache key
     * @return mixed|null Cached value or null if not found/expired
     */
    public function get(string $key) {
        if ($this->apcu_available) {
            $success = false;
            $value = apcu_fetch($key, $success);

            if ($success) {
                $this->stats['hits']++;
                return $value;
            }
        } else {
            $filepath = $this->getFilePath($key);

            if (file_exists($filepath)) {
                $data = unserialize(file_get_contents($filepath));

                // Check if expired
                if ($data['expires'] > time()) {
                    $this->stats['hits']++;
                    return $data['value'];
                } else {
                    // Expired - delete it
                    unlink($filepath);
                }
            }
        }

        $this->stats['misses']++;
        return null;
    }

    /**
     * Set value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param string $type Cache type for TTL determination
     * @return bool Success status
     */
    public function set(string $key, $value, string $type = 'default'): bool {
        $ttl = $this->getTTL($type);
        $this->stats['sets']++;

        if ($this->apcu_available) {
            return apcu_store($key, $value, $ttl);
        } else {
            $filepath = $this->getFilePath($key);
            $data = [
                'value' => $value,
                'expires' => time() + $ttl,
                'type' => $type
            ];

            return file_put_contents($filepath, serialize($data), LOCK_EX) !== false;
        }
    }

    /**
     * Delete specific cache entry
     *
     * @param string $key Cache key
     * @return bool Success status
     */
    public function delete(string $key): bool {
        $this->stats['deletes']++;

        if ($this->apcu_available) {
            return apcu_delete($key);
        } else {
            $filepath = $this->getFilePath($key);

            if (file_exists($filepath)) {
                return unlink($filepath);
            }

            return true;
        }
    }

    /**
     * Clear all cache entries
     *
     * @return bool Success status
     */
    public function clear(): bool {
        if ($this->apcu_available) {
            return apcu_clear_cache();
        } else {
            if (!is_dir(self::CACHE_DIR)) {
                return true;
            }

            $files = glob(self::CACHE_DIR . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            return true;
        }
    }

    /**
     * Clear cache entries by pattern
     *
     * @param string $pattern Key pattern (e.g., 'balance_*')
     * @return int Number of entries deleted
     */
    public function clearPattern(string $pattern): int {
        $count = 0;

        if ($this->apcu_available) {
            // APCu doesn't support pattern matching easily
            // Would need to iterate over all keys
            // For now, use cache tagging in application layer
            return 0;
        } else {
            $files = glob(self::CACHE_DIR . '/' . $pattern);
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Invalidate cache for write operations
     * Call this after add/edit/delete operations
     *
     * @param string $type Type of data modified (contacts, transactions, etc.)
     */
    public function invalidateType(string $type): void {
        switch ($type) {
            case 'contact':
                // Invalidate contact list and related balances
                $this->clearPattern('contacts_*');
                $this->clearPattern('balance_*');
                break;

            case 'transaction':
                // Invalidate balances and transaction history
                $this->clearPattern('balance_*');
                $this->clearPattern('transactions_*');
                break;

            case 'user':
                // Invalidate user info
                $this->clearPattern('user_info_*');
                break;

            default:
                // Clear all cache if type unknown
                $this->clear();
        }
    }

    /**
     * Get cache statistics
     *
     * @return array Statistics array
     */
    public function getStats(): array {
        $total = $this->stats['hits'] + $this->stats['misses'];
        $hitRate = $total > 0 ? ($this->stats['hits'] / $total) * 100 : 0;

        return array_merge($this->stats, [
            'hit_rate' => round($hitRate, 2),
            'backend' => $this->apcu_available ? 'APCu' : 'File',
            'total_requests' => $total
        ]);
    }

    /**
     * Get file path for file-based cache
     *
     * @param string $key Cache key
     * @return string File path
     */
    private function getFilePath(string $key): string {
        // Use hash to avoid filesystem issues with special characters
        $hash = md5($key);
        return self::CACHE_DIR . '/' . $hash . '.cache';
    }

    /**
     * Clean up expired cache entries (for file-based cache)
     * Should be called periodically via cron or on initialization
     *
     * @return int Number of entries deleted
     */
    public function cleanupExpired(): int {
        if ($this->apcu_available) {
            // APCu handles expiration automatically
            return 0;
        }

        $count = 0;

        if (!is_dir(self::CACHE_DIR)) {
            return 0;
        }

        $files = glob(self::CACHE_DIR . '/*.cache');
        foreach ($files as $file) {
            if (is_file($file)) {
                $data = unserialize(file_get_contents($file));

                if ($data['expires'] <= time()) {
                    unlink($file);
                    $count++;
                }
            }
        }

        return $count;
    }
}
