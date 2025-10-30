<?php
/**
 * Cached Transport Utility Service
 *
 * Extends TransportUtilityService with caching capabilities to optimize
 * network communication and reduce redundant API calls.
 *
 * @package Services\Utilities
 * @copyright 2025
 */

require_once __DIR__ . '/TransportUtilityService.php';
require_once __DIR__ . '/../../cache/DockerCache.php';

class CachedTransportUtilityService extends TransportUtilityService {
    /**
     * @var DockerCache Cache instance
     */
    private DockerCache $cache;

    /**
     * @var bool Enable parallel requests using curl_multi
     */
    private bool $enableParallel = true;

    /**
     * @var array Connection pool for reusable curl handles
     */
    private array $connectionPool = [];

    /**
     * @var int Maximum connections in pool
     */
    private const MAX_POOL_SIZE = 10;

    /**
     * Constructor
     *
     * @param ServiceContainer $container Service container
     */
    public function __construct(ServiceContainer $container) {
        parent::__construct($container);
        $this->cache = DockerCache::getInstance();
    }

    /**
     * Send payload to recipient with caching
     *
     * @param string $recipient The address of the recipient
     * @param array $payload The payload to send
     * @return string The response from the recipient
     */
    public function send(string $recipient, array $payload) {
        // Check if this is a read-only request that can be cached
        if ($this->isCacheableRequest($payload)) {
            $cacheKey = $this->generateCacheKey($recipient, $payload);

            // Try to get from cache
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Execute the actual send
        $response = parent::send($recipient, $payload);

        // Cache the response if appropriate
        if ($this->isCacheableRequest($payload) && $response) {
            $ttl = $this->determineTTL($payload);
            $this->cache->set($cacheKey, $response, $ttl, 'api_response');
        }

        return $response;
    }

    /**
     * Send multiple requests in parallel (batch operation)
     *
     * @param array $requests Array of [recipient => payload] pairs
     * @return array Array of [recipient => response] pairs
     */
    public function sendBatch(array $requests): array {
        if (!$this->enableParallel || count($requests) === 1) {
            // Fall back to sequential processing
            return $this->sendBatchSequential($requests);
        }

        $results = [];
        $handles = [];
        $multiHandle = curl_multi_init();

        // Separate cacheable and non-cacheable requests
        $toFetch = [];
        foreach ($requests as $recipient => $payload) {
            if ($this->isCacheableRequest($payload)) {
                $cacheKey = $this->generateCacheKey($recipient, $payload);
                $cached = $this->cache->get($cacheKey);

                if ($cached !== null) {
                    $results[$recipient] = $cached;
                    continue;
                }
            }
            $toFetch[$recipient] = $payload;
        }

        // Prepare curl handles for non-cached requests
        foreach ($toFetch as $recipient => $payload) {
            $ch = $this->getConnectionFromPool($recipient);
            $signedPayload = json_encode($this->sign($payload));

            if ($this->isTorAddress($recipient)) {
                $this->configureCurlForTor($ch, $recipient, $signedPayload);
            } else {
                $this->configureCurlForHttp($ch, $recipient, $signedPayload);
            }

            $handles[$recipient] = $ch;
            curl_multi_add_handle($multiHandle, $ch);
        }

        // Execute all requests in parallel
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        // Collect responses
        foreach ($handles as $recipient => $ch) {
            $response = curl_multi_getcontent($ch);
            $results[$recipient] = $response;

            // Cache if appropriate
            if ($this->isCacheableRequest($toFetch[$recipient]) && $response) {
                $cacheKey = $this->generateCacheKey($recipient, $toFetch[$recipient]);
                $ttl = $this->determineTTL($toFetch[$recipient]);
                $this->cache->set($cacheKey, $response, $ttl, 'api_response');
            }

            curl_multi_remove_handle($multiHandle, $ch);
            $this->returnConnectionToPool($ch);
        }

        curl_multi_close($multiHandle);

        return $results;
    }

    /**
     * Send batch requests sequentially (fallback)
     *
     * @param array $requests Array of [recipient => payload] pairs
     * @return array Array of [recipient => response] pairs
     */
    private function sendBatchSequential(array $requests): array {
        $results = [];
        foreach ($requests as $recipient => $payload) {
            $results[$recipient] = $this->send($recipient, $payload);
        }
        return $results;
    }

    /**
     * Send by HTTP with connection pooling
     *
     * @param string $recipient The address of the recipient
     * @param string $signedPayload The JSON encoded signed payload to send
     * @return string The response from the recipient
     */
    public function sendByHttp(string $recipient, string $signedPayload): string {
        $ch = $this->getConnectionFromPool($recipient);
        $this->configureCurlForHttp($ch, $recipient, $signedPayload);

        $response = curl_exec($ch);
        $this->returnConnectionToPool($ch);

        return $response;
    }

    /**
     * Send by Tor with connection pooling
     *
     * @param string $recipient The address of the recipient
     * @param string $signedPayload The JSON encoded signed payload to send
     * @return string The response from the recipient
     */
    public function sendByTor(string $recipient, string $signedPayload): string {
        $ch = $this->getConnectionFromPool($recipient);
        $this->configureCurlForTor($ch, $recipient, $signedPayload);

        $response = curl_exec($ch);
        $this->returnConnectionToPool($ch);

        return $response;
    }

    /**
     * Configure curl handle for HTTP request
     *
     * @param resource $ch Curl handle
     * @param string $recipient Recipient address
     * @param string $signedPayload Signed payload
     * @return void
     */
    private function configureCurlForHttp($ch, string $recipient, string $signedPayload): void {
        $protocol = preg_match('/^https?:\/\//', $recipient) ? '' : 'http://';

        curl_setopt($ch, CURLOPT_URL, $protocol . $recipient . "/eiou?payload=" . urlencode($signedPayload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    }

    /**
     * Configure curl handle for Tor request
     *
     * @param resource $ch Curl handle
     * @param string $recipient Recipient address
     * @param string $signedPayload Signed payload
     * @return void
     */
    private function configureCurlForTor($ch, string $recipient, string $signedPayload): void {
        curl_setopt($ch, CURLOPT_URL, "http://$recipient/eiou?payload=" . urlencode($signedPayload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_PROXY, "127.0.0.1:9050");
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Tor is slower
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    }

    /**
     * Get a connection from the pool or create new
     *
     * @param string $recipient Recipient address for connection
     * @return resource Curl handle
     */
    private function getConnectionFromPool(string $recipient) {
        // Try to reuse an existing handle
        if (!empty($this->connectionPool)) {
            return array_pop($this->connectionPool);
        }

        // Create a new handle
        return curl_init();
    }

    /**
     * Return a connection to the pool
     *
     * @param resource $ch Curl handle
     * @return void
     */
    private function returnConnectionToPool($ch): void {
        // Reset the handle for reuse
        curl_reset($ch);

        // Only keep handles up to pool limit
        if (count($this->connectionPool) < self::MAX_POOL_SIZE) {
            $this->connectionPool[] = $ch;
        } else {
            curl_close($ch);
        }
    }

    /**
     * Check if a request is cacheable
     *
     * @param array $payload The payload to check
     * @return bool True if cacheable
     */
    private function isCacheableRequest(array $payload): bool {
        // Only cache read operations
        $cacheableTypes = [
            'getBalance',
            'getContacts',
            'getTransactions',
            'getStatus',
            'getPeers',
            'getInfo',
            'getMetrics'
        ];

        return isset($payload['type']) && in_array($payload['type'], $cacheableTypes);
    }

    /**
     * Generate a cache key for the request
     *
     * @param string $recipient Recipient address
     * @param array $payload Request payload
     * @return string Cache key
     */
    private function generateCacheKey(string $recipient, array $payload): string {
        // Create a unique key based on recipient and payload
        $keyData = [
            'recipient' => $recipient,
            'type' => $payload['type'] ?? 'unknown',
            'params' => $payload['params'] ?? []
        ];

        return 'api_' . md5(json_encode($keyData));
    }

    /**
     * Determine TTL based on payload type
     *
     * @param array $payload Request payload
     * @return int TTL in seconds
     */
    private function determineTTL(array $payload): int {
        $type = $payload['type'] ?? 'unknown';

        $ttlMap = [
            'getBalance' => 10,
            'getContacts' => 30,
            'getTransactions' => 20,
            'getStatus' => 5,
            'getPeers' => 15,
            'getInfo' => 60,
            'getMetrics' => 15
        ];

        return $ttlMap[$type] ?? 10;
    }

    /**
     * Clear connection pool (cleanup)
     *
     * @return void
     */
    public function clearConnectionPool(): void {
        foreach ($this->connectionPool as $ch) {
            curl_close($ch);
        }
        $this->connectionPool = [];
    }

    /**
     * Destructor - cleanup connections
     */
    public function __destruct() {
        $this->clearConnectionPool();
    }
}