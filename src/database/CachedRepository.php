<?php
# Copyright 2025

/**
 * Cached Repository Helper
 *
 * Provides caching wrapper for database queries to reduce load.
 * Used by repositories to cache frequently accessed data.
 *
 * Usage:
 *   $cachedRepo = new CachedRepository($apiCache);
 *   $contacts = $cachedRepo->cached('contacts_all', 'contacts', function() {
 *       return $this->getAllContacts();
 *   });
 *
 * @package Database
 */

require_once __DIR__ . '/../services/ApiCache.php';

class CachedRepository {
    /**
     * @var ApiCache Cache service
     */
    private ApiCache $cache;

    /**
     * Constructor
     *
     * @param ApiCache $cache Cache service
     */
    public function __construct(ApiCache $cache) {
        $this->cache = $cache;
    }

    /**
     * Execute query with caching
     *
     * @param string $key Cache key
     * @param string $type Cache type (for TTL)
     * @param callable $callback Query callback to execute if cache miss
     * @return mixed Query result (from cache or fresh)
     */
    public function cached(string $key, string $type, callable $callback) {
        // Try to get from cache first
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        // Cache miss - execute query
        $result = $callback();

        // Store in cache
        $this->cache->set($key, $result, $type);

        return $result;
    }

    /**
     * Invalidate cache for specific type
     *
     * @param string $type Data type (contact, transaction, user)
     */
    public function invalidate(string $type): void {
        $this->cache->invalidateType($type);
    }

    /**
     * Delete specific cache entry
     *
     * @param string $key Cache key
     */
    public function delete(string $key): void {
        $this->cache->delete($key);
    }

    /**
     * Get cache instance
     *
     * @return ApiCache
     */
    public function getCache(): ApiCache {
        return $this->cache;
    }
}
