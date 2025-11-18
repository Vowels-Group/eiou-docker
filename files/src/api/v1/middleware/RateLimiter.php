<?php
/**
 * API Rate Limiter Middleware
 *
 * Copyright 2025
 * Implements token bucket algorithm for rate limiting
 */

class RateLimiter
{
    private array $config;
    private string $storagePath;

    public function __construct(array $config)
    {
        $this->config = $config['rate_limit'];
        $this->storagePath = $this->config['storage_path'];

        // Create storage directory if it doesn't exist
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0700, true);
        }
    }

    /**
     * Check if request is allowed under rate limit
     *
     * @param string $identifier Client identifier (API key, IP address, etc.)
     * @return array ['allowed' => bool, 'limit' => int, 'remaining' => int, 'reset' => int]
     */
    public function checkRateLimit(string $identifier): array
    {
        if (!$this->config['enabled']) {
            return [
                'allowed' => true,
                'limit' => PHP_INT_MAX,
                'remaining' => PHP_INT_MAX,
                'reset' => 0
            ];
        }

        $limit = $this->config['requests_per_minute'];
        $burstLimit = $this->config['burst_limit'];

        // Get current bucket state
        $bucket = $this->getBucket($identifier);

        // Calculate time since last refill
        $now = time();
        $timePassed = $now - $bucket['last_refill'];

        // Refill tokens based on time passed (1 token per second up to limit)
        $tokensToAdd = floor($timePassed * ($limit / 60)); // tokens per second
        $bucket['tokens'] = min($limit, $bucket['tokens'] + $tokensToAdd);
        $bucket['last_refill'] = $now;

        // Check if we have tokens available
        if ($bucket['tokens'] >= 1) {
            // Allow request and consume token
            $bucket['tokens'] -= 1;
            $bucket['requests_this_minute'] += 1;

            $this->saveBucket($identifier, $bucket);

            return [
                'allowed' => true,
                'limit' => $limit,
                'remaining' => (int)$bucket['tokens'],
                'reset' => $now + 60 // Reset in 60 seconds
            ];
        } else {
            // Rate limit exceeded
            return [
                'allowed' => false,
                'limit' => $limit,
                'remaining' => 0,
                'reset' => $now + (int)ceil((1 - $bucket['tokens']) * (60 / $limit))
            ];
        }
    }

    /**
     * Get bucket state for identifier
     *
     * @param string $identifier
     * @return array
     */
    private function getBucket(string $identifier): array
    {
        $bucketFile = $this->getBucketFilePath($identifier);

        if (file_exists($bucketFile)) {
            $content = file_get_contents($bucketFile);
            $bucket = json_decode($content, true);

            if ($bucket && isset($bucket['tokens'], $bucket['last_refill'])) {
                return $bucket;
            }
        }

        // Return new bucket
        return [
            'tokens' => $this->config['requests_per_minute'],
            'last_refill' => time(),
            'requests_this_minute' => 0
        ];
    }

    /**
     * Save bucket state
     *
     * @param string $identifier
     * @param array $bucket
     */
    private function saveBucket(string $identifier, array $bucket): void
    {
        $bucketFile = $this->getBucketFilePath($identifier);
        $json = json_encode($bucket);
        file_put_contents($bucketFile, $json, LOCK_EX);
    }

    /**
     * Get bucket file path for identifier
     *
     * @param string $identifier
     * @return string
     */
    private function getBucketFilePath(string $identifier): string
    {
        $hash = hash('sha256', $identifier);
        return $this->storagePath . '/bucket_' . $hash . '.json';
    }

    /**
     * Clean up old bucket files (maintenance)
     *
     * @param int $olderThanSeconds Delete buckets older than this
     * @return int Number of files deleted
     */
    public function cleanup(int $olderThanSeconds = 3600): int
    {
        $deleted = 0;
        $cutoff = time() - $olderThanSeconds;

        $files = glob($this->storagePath . '/bucket_*.json');
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Get rate limit headers for response
     *
     * @param array $limitInfo From checkRateLimit()
     * @return array HTTP headers
     */
    public static function getHeaders(array $limitInfo): array
    {
        return [
            'X-RateLimit-Limit' => (string)$limitInfo['limit'],
            'X-RateLimit-Remaining' => (string)$limitInfo['remaining'],
            'X-RateLimit-Reset' => (string)$limitInfo['reset']
        ];
    }
}
