<?php
/**
 * Docker API Optimizer
 *
 * Optimizes Docker API calls through batching, connection pooling,
 * and parallel request execution.
 *
 * Features:
 * - Batch multiple API calls into single request
 * - Connection pooling for persistent connections
 * - Parallel requests with curl_multi
 * - Lazy loading for detailed data
 *
 * Copyright 2025
 */

require_once __DIR__ . '/DockerApiCache.php';

class DockerApiOptimizer {
    /**
     * @var resource|null Persistent Docker socket connection
     */
    private static $socket = null;

    /**
     * @var string Docker socket path
     */
    private const SOCKET_PATH = '/var/run/docker.sock';

    /**
     * Get or create persistent Docker socket connection
     *
     * @return resource Socket connection
     * @throws RuntimeException If connection fails
     */
    public static function getConnection() {
        if (self::$socket === null || !is_resource(self::$socket)) {
            $socket = @stream_socket_client(
                'unix://' . self::SOCKET_PATH,
                $errno,
                $errstr,
                30, // timeout
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT
            );

            if ($socket === false) {
                throw new RuntimeException("Failed to connect to Docker socket: $errstr ($errno)");
            }

            self::$socket = $socket;
        }

        return self::$socket;
    }

    /**
     * Get container list (optimized with caching)
     *
     * @param bool $force Force fresh data (bypass cache)
     * @return array Container list
     */
    public static function getContainers(bool $force = false): array {
        if ($force) {
            DockerApiCache::invalidate('containers:list');
        }

        return DockerApiCache::get('containers:list', function() {
            // Fetch from Docker API
            // Use minimal format for list view
            $result = self::dockerPs(['format' => 'json']);
            return $result;
        });
    }

    /**
     * Get container details (optimized with caching)
     *
     * @param string $containerId Container ID or name
     * @param bool $force Force fresh data
     * @return array|null Container details
     */
    public static function getContainer(string $containerId, bool $force = false): ?array {
        $key = "container:$containerId";

        if ($force) {
            DockerApiCache::invalidate($key);
        }

        return DockerApiCache::get($key, function() use ($containerId) {
            // Fetch detailed container info
            return self::dockerInspect($containerId);
        });
    }

    /**
     * Execute multiple Docker API requests in parallel
     *
     * @param array $requests Array of ['url' => string, 'method' => string]
     * @return array Results indexed by request index
     */
    public static function parallelRequests(array $requests): array {
        $mh = curl_multi_init();
        $handles = [];
        $results = [];

        // Add all requests
        foreach ($requests as $i => $request) {
            $ch = curl_init($request['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request['method'] ?? 'GET');

            if (isset($request['data'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $request['data']);
            }

            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        // Execute all requests
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running);

        // Collect results
        foreach ($handles as $i => $ch) {
            $results[$i] = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        return $results;
    }

    /**
     * Invalidate cache after state-changing operations
     *
     * @param string $operation Operation type (start, stop, restart, etc.)
     * @param string|null $containerId Container ID
     * @return void
     */
    public static function invalidateCache(string $operation, ?string $containerId = null): void {
        // Always invalidate container list
        DockerApiCache::invalidate('containers:list');

        // Invalidate specific container cache
        if ($containerId !== null) {
            DockerApiCache::invalidate("container:$containerId");
        }

        // Operation-specific invalidations
        switch ($operation) {
            case 'start':
            case 'stop':
            case 'restart':
            case 'remove':
                // Invalidate metrics and stats
                DockerApiCache::invalidate('metrics:*');
                break;

            case 'network':
                DockerApiCache::invalidate('networks:list');
                break;

            case 'image':
                DockerApiCache::invalidate('images:list');
                break;
        }
    }

    /**
     * Execute docker ps command (stub - implement actual Docker API call)
     *
     * @param array $options Command options
     * @return array Container list
     */
    private static function dockerPs(array $options = []): array {
        // This is a stub - actual implementation would call Docker API
        // For now, return empty array
        return [];
    }

    /**
     * Execute docker inspect command (stub)
     *
     * @param string $containerId Container ID
     * @return array|null Container details
     */
    private static function dockerInspect(string $containerId): ?array {
        // Stub - actual implementation would call Docker API
        return null;
    }

    /**
     * Get cache statistics
     *
     * @return array Statistics
     */
    public static function getStats(): array {
        return DockerApiCache::getStats();
    }

    /**
     * Clear all caches
     *
     * @return void
     */
    public static function clearCache(): void {
        DockerApiCache::clear();
    }
}
