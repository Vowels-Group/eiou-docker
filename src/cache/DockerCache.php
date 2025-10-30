<?php
/**
 * DockerCache - High-performance in-memory caching system
 *
 * Provides TTL-based caching with smart invalidation for Docker EIOU nodes
 * to optimize API calls and reduce database load.
 *
 * Features:
 * - In-memory storage using PHP arrays
 * - TTL-based expiration
 * - Smart invalidation hooks
 * - Cache statistics and monitoring
 * - Thread-safe operations using file locks
 * - Batch operations support
 * - No external dependencies
 *
 * @package Cache
 * @copyright 2025
 */

class DockerCache {
    /**
     * @var array In-memory cache storage
     */
    private array $cache = [];

    /**
     * @var array Cache statistics
     */
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0,
        'invalidations' => 0,
        'batch_operations' => 0,
        'memory_peak' => 0,
        'start_time' => 0
    ];

    /**
     * @var array Default TTL values for different cache types (in seconds)
     */
    private const DEFAULT_TTLS = [
        'container_list' => 5,      // Container list cache
        'container_details' => 10,  // Container details cache
        'wallet_balance' => 10,     // Wallet balance cache
        'system_metrics' => 15,     // System metrics cache
        'contact_data' => 30,       // Contact data cache
        'transaction_history' => 20, // Transaction history cache
        'service_instance' => 60,   // Service instance cache
        'user_settings' => 120,     // User settings cache
        'public_keys' => 300       // Public key cache
    ];

    /**
     * @var resource|null File lock for thread safety
     */
    private $lockHandle = null;

    /**
     * @var string Lock file path
     */
    private const LOCK_FILE = '/tmp/docker_cache.lock';

    /**
     * @var DockerCache|null Singleton instance
     */
    private static ?DockerCache $instance = null;

    /**
     * @var array Invalidation hooks registry
     */
    private array $invalidationHooks = [];

    /**
     * @var int Maximum cache size in bytes (default 50MB)
     */
    private int $maxCacheSize = 52428800;

    /**
     * @var array Cache tags for grouped invalidation
     */
    private array $tags = [];

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        $this->stats['start_time'] = time();
        $this->registerDefaultInvalidationHooks();
    }

    /**
     * Get singleton instance
     *
     * @return DockerCache
     */
    public static function getInstance(): DockerCache {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Set a value in cache with TTL
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds (null for default)
     * @param string|null $type Cache type for default TTL
     * @param array $tags Optional tags for grouped invalidation
     * @return bool Success status
     */
    public function set(string $key, $value, ?int $ttl = null, ?string $type = null, array $tags = []): bool {
        try {
            $this->acquireLock();

            // Determine TTL
            if ($ttl === null && $type !== null && isset(self::DEFAULT_TTLS[$type])) {
                $ttl = self::DEFAULT_TTLS[$type];
            } elseif ($ttl === null) {
                $ttl = 60; // Default 60 seconds
            }

            // Check cache size limit
            if ($this->isMemoryLimitExceeded()) {
                $this->evictOldestEntries();
            }

            // Store cache entry
            $this->cache[$key] = [
                'value' => $value,
                'expires_at' => time() + $ttl,
                'created_at' => time(),
                'type' => $type,
                'hits' => 0
            ];

            // Store tags
            if (!empty($tags)) {
                foreach ($tags as $tag) {
                    if (!isset($this->tags[$tag])) {
                        $this->tags[$tag] = [];
                    }
                    $this->tags[$tag][] = $key;
                }
            }

            $this->stats['sets']++;
            $this->updateMemoryStats();

            return true;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Get a value from cache
     *
     * @param string $key Cache key
     * @param mixed $default Default value if not found
     * @return mixed Cached value or default
     */
    public function get(string $key, $default = null) {
        try {
            $this->acquireLock();

            // Check if key exists and is not expired
            if (isset($this->cache[$key])) {
                $entry = $this->cache[$key];

                if ($entry['expires_at'] > time()) {
                    $this->cache[$key]['hits']++;
                    $this->stats['hits']++;
                    return $entry['value'];
                } else {
                    // Remove expired entry
                    unset($this->cache[$key]);
                }
            }

            $this->stats['misses']++;
            return $default;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Get multiple values from cache (batch operation)
     *
     * @param array $keys Array of cache keys
     * @return array Associative array of key => value
     */
    public function getMultiple(array $keys): array {
        $results = [];

        try {
            $this->acquireLock();
            $this->stats['batch_operations']++;

            foreach ($keys as $key) {
                if (isset($this->cache[$key]) && $this->cache[$key]['expires_at'] > time()) {
                    $this->cache[$key]['hits']++;
                    $this->stats['hits']++;
                    $results[$key] = $this->cache[$key]['value'];
                } else {
                    $this->stats['misses']++;
                    $results[$key] = null;
                }
            }

            return $results;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Set multiple values in cache (batch operation)
     *
     * @param array $items Associative array of key => value
     * @param int|null $ttl Time to live in seconds
     * @param string|null $type Cache type
     * @return bool Success status
     */
    public function setMultiple(array $items, ?int $ttl = null, ?string $type = null): bool {
        try {
            $this->acquireLock();
            $this->stats['batch_operations']++;

            foreach ($items as $key => $value) {
                $this->set($key, $value, $ttl, $type);
            }

            return true;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Delete a cache entry
     *
     * @param string $key Cache key
     * @return bool Success status
     */
    public function delete(string $key): bool {
        try {
            $this->acquireLock();

            if (isset($this->cache[$key])) {
                unset($this->cache[$key]);
                $this->stats['deletes']++;

                // Remove from tags
                foreach ($this->tags as $tag => &$keys) {
                    $keys = array_diff($keys, [$key]);
                }

                return true;
            }

            return false;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Clear all cache entries
     *
     * @return void
     */
    public function clear(): void {
        try {
            $this->acquireLock();
            $this->cache = [];
            $this->tags = [];
            $this->stats['invalidations']++;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Invalidate cache by type
     *
     * @param string $type Cache type to invalidate
     * @return int Number of entries invalidated
     */
    public function invalidateByType(string $type): int {
        try {
            $this->acquireLock();

            $count = 0;
            foreach ($this->cache as $key => $entry) {
                if ($entry['type'] === $type) {
                    unset($this->cache[$key]);
                    $count++;
                }
            }

            if ($count > 0) {
                $this->stats['invalidations']++;
            }

            return $count;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Invalidate cache by tag
     *
     * @param string $tag Tag to invalidate
     * @return int Number of entries invalidated
     */
    public function invalidateByTag(string $tag): int {
        try {
            $this->acquireLock();

            $count = 0;
            if (isset($this->tags[$tag])) {
                foreach ($this->tags[$tag] as $key) {
                    if (isset($this->cache[$key])) {
                        unset($this->cache[$key]);
                        $count++;
                    }
                }
                unset($this->tags[$tag]);

                if ($count > 0) {
                    $this->stats['invalidations']++;
                }
            }

            return $count;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Register an invalidation hook
     *
     * @param string $event Event name (e.g., 'transaction_created')
     * @param callable $callback Callback function
     * @return void
     */
    public function registerInvalidationHook(string $event, callable $callback): void {
        if (!isset($this->invalidationHooks[$event])) {
            $this->invalidationHooks[$event] = [];
        }
        $this->invalidationHooks[$event][] = $callback;
    }

    /**
     * Trigger invalidation hooks for an event
     *
     * @param string $event Event name
     * @param array $data Event data
     * @return void
     */
    public function triggerInvalidation(string $event, array $data = []): void {
        if (isset($this->invalidationHooks[$event])) {
            foreach ($this->invalidationHooks[$event] as $callback) {
                $callback($this, $data);
            }
        }
    }

    /**
     * Register default invalidation hooks
     *
     * @return void
     */
    private function registerDefaultInvalidationHooks(): void {
        // Transaction created - invalidate balance and transaction history
        $this->registerInvalidationHook('transaction_created', function($cache, $data) {
            if (isset($data['sender'])) {
                $cache->delete('balance_' . $data['sender']);
                $cache->delete('transactions_' . $data['sender']);
            }
            if (isset($data['receiver'])) {
                $cache->delete('balance_' . $data['receiver']);
                $cache->delete('transactions_' . $data['receiver']);
            }
            $cache->invalidateByType('wallet_balance');
            $cache->invalidateByType('transaction_history');
        });

        // Contact added/updated - invalidate contact data
        $this->registerInvalidationHook('contact_updated', function($cache, $data) {
            $cache->invalidateByType('contact_data');
            if (isset($data['address'])) {
                $cache->delete('contact_' . $data['address']);
            }
        });

        // Settings changed - invalidate settings cache
        $this->registerInvalidationHook('settings_changed', function($cache, $data) {
            $cache->invalidateByType('user_settings');
        });

        // Container state changed - invalidate container caches
        $this->registerInvalidationHook('container_changed', function($cache, $data) {
            $cache->invalidateByType('container_list');
            $cache->invalidateByType('container_details');
            if (isset($data['container_id'])) {
                $cache->delete('container_' . $data['container_id']);
            }
        });

        // P2P message received - invalidate relevant caches
        $this->registerInvalidationHook('p2p_message', function($cache, $data) {
            if (isset($data['type']) && $data['type'] === 'balance_update') {
                $cache->invalidateByType('wallet_balance');
            }
        });
    }

    /**
     * Get cache statistics
     *
     * @return array Statistics array
     */
    public function getStats(): array {
        try {
            $this->acquireLock();

            $stats = $this->stats;
            $stats['total_entries'] = count($this->cache);
            $stats['memory_usage'] = $this->getMemoryUsage();
            $stats['hit_rate'] = $this->calculateHitRate();
            $stats['uptime'] = time() - $this->stats['start_time'];
            $stats['avg_entry_size'] = $stats['total_entries'] > 0
                ? round($stats['memory_usage'] / $stats['total_entries'])
                : 0;

            // Get type distribution
            $typeDistribution = [];
            foreach ($this->cache as $entry) {
                $type = $entry['type'] ?? 'unknown';
                if (!isset($typeDistribution[$type])) {
                    $typeDistribution[$type] = 0;
                }
                $typeDistribution[$type]++;
            }
            $stats['type_distribution'] = $typeDistribution;

            return $stats;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Calculate cache hit rate
     *
     * @return float Hit rate percentage
     */
    private function calculateHitRate(): float {
        $total = $this->stats['hits'] + $this->stats['misses'];
        if ($total === 0) {
            return 0.0;
        }
        return round(($this->stats['hits'] / $total) * 100, 2);
    }

    /**
     * Get current memory usage
     *
     * @return int Memory usage in bytes
     */
    private function getMemoryUsage(): int {
        $serialized = serialize($this->cache);
        return strlen($serialized);
    }

    /**
     * Update memory statistics
     *
     * @return void
     */
    private function updateMemoryStats(): void {
        $currentUsage = $this->getMemoryUsage();
        if ($currentUsage > $this->stats['memory_peak']) {
            $this->stats['memory_peak'] = $currentUsage;
        }
    }

    /**
     * Check if memory limit is exceeded
     *
     * @return bool True if exceeded
     */
    private function isMemoryLimitExceeded(): bool {
        return $this->getMemoryUsage() > $this->maxCacheSize;
    }

    /**
     * Evict oldest cache entries to free memory
     *
     * @param float $percentToFree Percentage of cache to free (default 20%)
     * @return int Number of entries evicted
     */
    private function evictOldestEntries(float $percentToFree = 0.2): int {
        $entriesToEvict = (int) (count($this->cache) * $percentToFree);
        if ($entriesToEvict === 0) {
            $entriesToEvict = 1;
        }

        // Sort by creation time (oldest first)
        uasort($this->cache, function($a, $b) {
            return $a['created_at'] <=> $b['created_at'];
        });

        $count = 0;
        foreach (array_keys($this->cache) as $key) {
            if ($count >= $entriesToEvict) {
                break;
            }
            unset($this->cache[$key]);
            $count++;
        }

        return $count;
    }

    /**
     * Acquire lock for thread safety
     *
     * @param int $maxWait Maximum wait time in seconds
     * @return bool Success status
     */
    private function acquireLock(int $maxWait = 5): bool {
        $waitTime = 0;

        while ($waitTime < $maxWait) {
            $this->lockHandle = @fopen(self::LOCK_FILE, 'c');
            if ($this->lockHandle && flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
                return true;
            }

            if ($this->lockHandle) {
                fclose($this->lockHandle);
                $this->lockHandle = null;
            }

            usleep(10000); // Wait 10ms
            $waitTime += 0.01;
        }

        // Fallback: proceed without lock after timeout
        return false;
    }

    /**
     * Release lock
     *
     * @return void
     */
    private function releaseLock(): void {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * Get cache entries by pattern (for debugging)
     *
     * @param string $pattern Key pattern (supports * wildcard)
     * @return array Matching entries
     */
    public function getByPattern(string $pattern): array {
        try {
            $this->acquireLock();

            $pattern = str_replace('*', '.*', $pattern);
            $pattern = '/^' . $pattern . '$/';

            $results = [];
            foreach ($this->cache as $key => $entry) {
                if (preg_match($pattern, $key) && $entry['expires_at'] > time()) {
                    $results[$key] = $entry['value'];
                }
            }

            return $results;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Warmup cache with predefined data
     *
     * @param array $data Data to warmup
     * @return void
     */
    public function warmup(array $data): void {
        foreach ($data as $key => $config) {
            if (isset($config['value'], $config['ttl'], $config['type'])) {
                $this->set($key, $config['value'], $config['ttl'], $config['type']);
            }
        }
    }

    /**
     * Export cache for debugging
     *
     * @return array Cache data
     */
    public function export(): array {
        try {
            $this->acquireLock();

            $export = [];
            foreach ($this->cache as $key => $entry) {
                if ($entry['expires_at'] > time()) {
                    $export[$key] = [
                        'value' => $entry['value'],
                        'expires_in' => $entry['expires_at'] - time(),
                        'type' => $entry['type'] ?? 'unknown',
                        'hits' => $entry['hits']
                    ];
                }
            }

            return $export;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Reset statistics
     *
     * @return void
     */
    public function resetStats(): void {
        $this->stats = [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'deletes' => 0,
            'invalidations' => 0,
            'batch_operations' => 0,
            'memory_peak' => $this->getMemoryUsage(),
            'start_time' => time()
        ];
    }
}