<?php
/**
 * Enable Caching Integration
 *
 * This file integrates the caching layer into the existing EIOU Docker application.
 * Include this file early in your application bootstrap to enable caching.
 *
 * Usage:
 *   require_once '/etc/eiou/src/cache/EnableCaching.php';
 *
 * @package Cache
 * @copyright 2025
 */

// Load cache components
require_once __DIR__ . '/DockerCache.php';
require_once __DIR__ . '/../services/CachedServiceContainer.php';

/**
 * Enable caching in the application
 *
 * @param bool $enabled Enable or disable caching
 * @param array $config Optional configuration
 * @return void
 */
function enableCaching(bool $enabled = true, array $config = []): void {
    if (!$enabled) {
        return;
    }

    // Initialize cache instance
    $cache = DockerCache::getInstance();

    // Apply configuration
    if (isset($config['max_cache_size'])) {
        // This would require adding a setter method in DockerCache
        // For now, the max size is hardcoded to 50MB
    }

    // Register shutdown handler to export stats
    register_shutdown_function(function() use ($cache) {
        $stats = $cache->getStats();

        // Log cache statistics on shutdown
        $logFile = '/var/log/eiou_cache_stats.log';
        $logEntry = sprintf(
            "[%s] Hits: %d, Misses: %d, Rate: %.1f%%, Memory: %d bytes, Entries: %d\n",
            date('Y-m-d H:i:s'),
            $stats['hits'],
            $stats['misses'],
            $stats['hit_rate'],
            $stats['memory_usage'],
            $stats['total_entries']
        );

        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    });

    // Override ServiceContainer singleton to use cached version
    overrideServiceContainer();

    // Warmup cache if requested
    if (!empty($config['warmup']) && class_exists('UserContext')) {
        warmupApplicationCache();
    }

    // Register custom invalidation hooks if provided
    if (!empty($config['invalidation_hooks'])) {
        foreach ($config['invalidation_hooks'] as $event => $callback) {
            $cache->registerInvalidationHook($event, $callback);
        }
    }
}

/**
 * Override the default ServiceContainer with cached version
 *
 * @return void
 */
function overrideServiceContainer(): void {
    // This is a bit tricky since ServiceContainer uses singleton pattern
    // We need to modify how ServiceContainer::getInstance() works

    // One approach is to use class aliasing if the original hasn't been loaded yet
    if (!class_exists('ServiceContainer', false)) {
        class_alias('CachedServiceContainer', 'ServiceContainer');
    } else {
        // If already loaded, we need to replace the instance
        // This requires reflection to access the private static property
        $reflection = new ReflectionClass('ServiceContainer');
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);

        // Create cached instance
        require_once __DIR__ . '/../services/CachedServiceContainer.php';
        $cachedInstance = CachedServiceContainer::getInstance();

        // Replace the instance
        $instanceProperty->setValue(null, $cachedInstance);
    }
}

/**
 * Warmup application cache with common data
 *
 * @return void
 */
function warmupApplicationCache(): void {
    try {
        $cache = DockerCache::getInstance();
        $container = ServiceContainer::getInstance();

        // Warmup user data if available
        if (method_exists($container, 'getCurrentUser')) {
            $user = $container->getCurrentUser();

            if ($user && method_exists($user, 'hasKeys') && $user->hasKeys()) {
                $publicKey = $user->getPublicKey();

                // Pre-cache user balance
                if (method_exists($container, 'getTransactionService')) {
                    $transactionService = $container->getTransactionService();

                    if (method_exists($transactionService, 'warmupCache')) {
                        $transactionService->warmupCache([$publicKey]);
                    }
                }

                // Pre-cache contacts
                if (method_exists($container, 'getContactRepository')) {
                    $contactRepo = $container->getContactRepository();

                    if (method_exists($contactRepo, 'getAllContacts')) {
                        $contacts = $contactRepo->getAllContacts();
                        $cache->set('contacts_all', $contacts, 30, 'contact_data');
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Silently fail warmup - it's not critical
        error_log("Cache warmup failed: " . $e->getMessage());
    }
}

/**
 * Get cache statistics
 *
 * @return array Cache statistics
 */
function getCacheStatistics(): array {
    $cache = DockerCache::getInstance();
    return $cache->getStats();
}

/**
 * Clear cache by type
 *
 * @param string|null $type Cache type to clear, or null for all
 * @return int Number of entries cleared
 */
function clearCache(?string $type = null): int {
    $cache = DockerCache::getInstance();

    if ($type === null) {
        $cache->clear();
        return -1; // All cleared
    }

    return $cache->invalidateByType($type);
}

/**
 * Trigger cache invalidation
 *
 * @param string $event Event name
 * @param array $data Event data
 * @return void
 */
function triggerCacheInvalidation(string $event, array $data = []): void {
    $cache = DockerCache::getInstance();
    $cache->triggerInvalidation($event, $data);
}

/**
 * Check if caching is available
 *
 * @return bool True if caching is available
 */
function isCachingAvailable(): bool {
    return class_exists('DockerCache') && class_exists('CachedServiceContainer');
}

/**
 * Get cache monitor CLI path
 *
 * @return string Path to cache monitor CLI
 */
function getCacheMonitorPath(): string {
    return '/etc/eiou/src/cli/CacheMonitor.php';
}

// Auto-enable caching if ENABLE_CACHE environment variable is set
if (getenv('ENABLE_CACHE') === 'true' || getenv('ENABLE_CACHE') === '1') {
    $config = [];

    // Check for warmup configuration
    if (getenv('CACHE_WARMUP') === 'true') {
        $config['warmup'] = true;
    }

    // Check for custom cache size
    if ($maxSize = getenv('CACHE_MAX_SIZE')) {
        $config['max_cache_size'] = intval($maxSize);
    }

    enableCaching(true, $config);
}