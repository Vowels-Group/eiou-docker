<?php
# Copyright 2025

/**
 * Docker API Optimizer
 *
 * Optimizes Docker/HTTP API calls through:
 * - Request batching
 * - Parallel execution with curl_multi
 * - Connection reuse and pooling
 * - Request deduplication
 * - Response compression
 *
 * Performance Features:
 * - Batch multiple requests into single operation
 * - Execute up to 10 parallel requests simultaneously
 * - Reuse HTTP connections (persistent connections)
 * - Deduplicate identical requests
 * - Enable gzip compression for responses
 *
 * @package Services
 */

require_once __DIR__ . '/ApiCache.php';

class DockerApiOptimizer {
    /**
     * @var ApiCache Cache service
     */
    private ApiCache $cache;

    /**
     * @var array Pending batch requests
     */
    private array $batchQueue = [];

    /**
     * @var int Maximum parallel requests
     */
    private const MAX_PARALLEL = 10;

    /**
     * @var int Connection timeout (seconds)
     */
    private const CONNECT_TIMEOUT = 5;

    /**
     * @var int Request timeout (seconds)
     */
    private const REQUEST_TIMEOUT = 30;

    /**
     * @var array Performance statistics
     */
    private array $stats = [
        'requests_total' => 0,
        'requests_cached' => 0,
        'requests_batched' => 0,
        'requests_parallel' => 0,
        'bytes_transferred' => 0,
        'time_saved_ms' => 0
    ];

    /**
     * Constructor
     */
    public function __construct() {
        $this->cache = new ApiCache();
    }

    /**
     * Get cache instance
     *
     * @return ApiCache
     */
    public function getCache(): ApiCache {
        return $this->cache;
    }

    /**
     * Execute single HTTP request with caching
     *
     * @param string $url Request URL
     * @param array $options cURL options
     * @param string $cacheKey Optional cache key
     * @param string $cacheType Cache type for TTL
     * @return string Response body
     */
    public function request(
        string $url,
        array $options = [],
        ?string $cacheKey = null,
        string $cacheType = 'default'
    ): string {
        $this->stats['requests_total']++;

        // Check cache if key provided
        if ($cacheKey) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $this->stats['requests_cached']++;
                return $cached;
            }
        }

        // Execute request with optimizations
        $ch = curl_init($url);

        // Apply default optimizations
        $optimizedOptions = $this->getOptimizedOptions($options);
        foreach ($optimizedOptions as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        $startTime = microtime(true);
        $response = curl_exec($ch);
        $elapsed = (microtime(true) - $startTime) * 1000;

        $info = curl_get_info($ch);
        $this->stats['bytes_transferred'] += $info['size_download'];

        curl_close($ch);

        // Cache if successful and cache key provided
        if ($response !== false && $cacheKey) {
            $this->cache->set($cacheKey, $response, $cacheType);
        }

        return $response !== false ? $response : '';
    }

    /**
     * Add request to batch queue
     *
     * @param string $url Request URL
     * @param array $options cURL options
     * @param string|null $cacheKey Optional cache key
     * @param string $cacheType Cache type
     * @return string Batch ID for retrieving result
     */
    public function addToBatch(
        string $url,
        array $options = [],
        ?string $cacheKey = null,
        string $cacheType = 'default'
    ): string {
        $batchId = uniqid('batch_', true);

        $this->batchQueue[$batchId] = [
            'url' => $url,
            'options' => $options,
            'cache_key' => $cacheKey,
            'cache_type' => $cacheType,
            'response' => null
        ];

        return $batchId;
    }

    /**
     * Execute all batched requests in parallel
     *
     * @return array Map of batch IDs to responses
     */
    public function executeBatch(): array {
        if (empty($this->batchQueue)) {
            return [];
        }

        $startTime = microtime(true);
        $results = [];

        // Check cache for all requests first
        $uncachedRequests = [];
        foreach ($this->batchQueue as $batchId => $request) {
            if ($request['cache_key']) {
                $cached = $this->cache->get($request['cache_key']);
                if ($cached !== null) {
                    $results[$batchId] = $cached;
                    $this->stats['requests_cached']++;
                    continue;
                }
            }

            $uncachedRequests[$batchId] = $request;
        }

        // Execute uncached requests in parallel
        if (!empty($uncachedRequests)) {
            $parallelResults = $this->executeParallel($uncachedRequests);
            $results = array_merge($results, $parallelResults);
        }

        $elapsed = (microtime(true) - $startTime) * 1000;

        // Estimate time saved by batching
        $sequentialTime = count($this->batchQueue) * 100; // Assume 100ms per request
        $this->stats['time_saved_ms'] += max(0, $sequentialTime - $elapsed);
        $this->stats['requests_batched'] += count($this->batchQueue);

        // Clear batch queue
        $this->batchQueue = [];

        return $results;
    }

    /**
     * Execute multiple requests in parallel using curl_multi
     *
     * @param array $requests Map of IDs to request configurations
     * @return array Map of IDs to responses
     */
    private function executeParallel(array $requests): array {
        $results = [];
        $chunks = array_chunk($requests, self::MAX_PARALLEL, true);

        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];

            // Initialize all handles
            foreach ($chunk as $batchId => $request) {
                $ch = curl_init($request['url']);

                // Apply optimized options
                $optimizedOptions = $this->getOptimizedOptions($request['options']);
                foreach ($optimizedOptions as $option => $value) {
                    curl_setopt($ch, $option, $value);
                }

                curl_multi_add_handle($mh, $ch);
                $handles[$batchId] = $ch;
            }

            // Execute all requests in parallel
            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            // Collect results
            foreach ($handles as $batchId => $ch) {
                $response = curl_multi_getcontent($ch);
                $info = curl_get_info($ch);

                $this->stats['bytes_transferred'] += $info['size_download'];
                $this->stats['requests_parallel']++;

                // Cache if successful
                $request = $chunk[$batchId];
                if ($response !== false && $request['cache_key']) {
                    $this->cache->set(
                        $request['cache_key'],
                        $response,
                        $request['cache_type']
                    );
                }

                $results[$batchId] = $response !== false ? $response : '';

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            curl_multi_close($mh);
        }

        return $results;
    }

    /**
     * Get optimized cURL options
     *
     * @param array $userOptions User-provided options
     * @return array Merged optimized options
     */
    private function getOptimizedOptions(array $userOptions): array {
        // Default optimized settings
        $defaults = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,

            // Connection reuse
            CURLOPT_FRESH_CONNECT => false,
            CURLOPT_FORBID_REUSE => false,

            // Compression
            CURLOPT_ENCODING => 'gzip,deflate',

            // HTTP version
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,

            // Follow redirects
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,

            // Headers
            CURLOPT_HTTPHEADER => [
                'Accept-Encoding: gzip, deflate',
                'Connection: keep-alive'
            ]
        ];

        // Merge user options (user options take precedence)
        return array_merge($defaults, $userOptions);
    }

    /**
     * Deduplicate requests in batch queue
     * Combines identical requests to reduce API calls
     *
     * @return int Number of duplicates removed
     */
    public function deduplicateBatch(): int {
        $seen = [];
        $duplicates = [];

        foreach ($this->batchQueue as $batchId => $request) {
            $signature = md5($request['url'] . serialize($request['options']));

            if (isset($seen[$signature])) {
                // This is a duplicate
                $duplicates[$batchId] = $seen[$signature];
            } else {
                $seen[$signature] = $batchId;
            }
        }

        // Remove duplicates from queue
        foreach ($duplicates as $dupId => $originalId) {
            unset($this->batchQueue[$dupId]);
        }

        return count($duplicates);
    }

    /**
     * Get performance statistics
     *
     * @return array Statistics array
     */
    public function getStats(): array {
        $cacheStats = $this->cache->getStats();

        return array_merge($this->stats, [
            'cache_hit_rate' => $cacheStats['hit_rate'],
            'cache_backend' => $cacheStats['backend'],
            'bytes_transferred_mb' => round($this->stats['bytes_transferred'] / 1024 / 1024, 2),
            'avg_time_saved_per_request_ms' => $this->stats['requests_total'] > 0
                ? round($this->stats['time_saved_ms'] / $this->stats['requests_total'], 2)
                : 0
        ]);
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void {
        $this->stats = [
            'requests_total' => 0,
            'requests_cached' => 0,
            'requests_batched' => 0,
            'requests_parallel' => 0,
            'bytes_transferred' => 0,
            'time_saved_ms' => 0
        ];
    }

    /**
     * Invalidate cache for data type
     *
     * @param string $type Data type (contact, transaction, user)
     */
    public function invalidateCache(string $type): void {
        $this->cache->invalidateType($type);
    }

    /**
     * Clear all caches
     */
    public function clearCache(): void {
        $this->cache->clear();
    }
}
