<?php
/**
 * Cached Service Container
 *
 * Extended service container that uses cached versions of services
 * to optimize performance and reduce redundant operations.
 *
 * @package Services
 * @copyright 2025
 */

require_once __DIR__ . '/ServiceContainer.php';
require_once __DIR__ . '/../cache/DockerCache.php';
require_once __DIR__ . '/CachedTransactionService.php';
require_once __DIR__ . '/utilities/CachedTransportUtilityService.php';

class CachedServiceContainer extends ServiceContainer {
    /**
     * @var DockerCache Cache instance
     */
    private DockerCache $cache;

    /**
     * @var bool Enable caching
     */
    private bool $cachingEnabled = true;

    /**
     * Constructor - initialize cache
     */
    protected function __construct() {
        parent::__construct();
        $this->cache = DockerCache::getInstance();
    }

    /**
     * Get TransactionService instance (cached version)
     *
     * @return TransactionService
     */
    public function getTransactionService(): TransactionService {
        if (!isset($this->services['TransactionService'])) {
            require_once __DIR__ . '/CachedTransactionService.php';

            if ($this->cachingEnabled) {
                // Use cached version
                $this->services['TransactionService'] = new CachedTransactionService(
                    $this->getP2pRepository(),
                    $this->getRp2pRepository(),
                    $this->getTransactionRepository(),
                    $this->getContactRepository(),
                    $this->getUtilityContainer(),
                    $this->currentUser
                );
            } else {
                // Fall back to non-cached version
                parent::getTransactionService();
            }
        }
        return $this->services['TransactionService'];
    }

    /**
     * Get cached utility container
     *
     * @return UtilityServiceContainer
     */
    public function getUtilityContainer(): UtilityServiceContainer {
        if (!isset($this->services['UtilityServiceContainer'])) {
            require_once __DIR__ . '/utilities/CachedUtilityServiceContainer.php';

            if ($this->cachingEnabled) {
                $this->services['UtilityServiceContainer'] = new CachedUtilityServiceContainer($this);
            } else {
                parent::getUtilityContainer();
            }
        }
        return $this->services['UtilityServiceContainer'];
    }

    /**
     * Get ContactService with caching
     *
     * @return ContactService
     */
    public function getContactService(): ContactService {
        $cacheKey = 'service_contact';

        if ($this->cachingEnabled) {
            // Try to get from cache first
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        if (!isset($this->services['ContactService'])) {
            parent::getContactService();
        }

        // Cache the service instance
        if ($this->cachingEnabled) {
            $this->cache->set($cacheKey, $this->services['ContactService'], 60, 'service_instance');
        }

        return $this->services['ContactService'];
    }

    /**
     * Get P2pService with caching
     *
     * @return P2pService
     */
    public function getP2pService(): P2pService {
        $cacheKey = 'service_p2p';

        if ($this->cachingEnabled) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        if (!isset($this->services['P2pService'])) {
            parent::getP2pService();
        }

        if ($this->cachingEnabled) {
            $this->cache->set($cacheKey, $this->services['P2pService'], 60, 'service_instance');
        }

        return $this->services['P2pService'];
    }

    /**
     * Enable or disable caching
     *
     * @param bool $enabled Enable caching
     * @return void
     */
    public function setCachingEnabled(bool $enabled): void {
        $this->cachingEnabled = $enabled;

        if (!$enabled) {
            // Clear all caches when disabling
            $this->cache->clear();
        }
    }

    /**
     * Get cache statistics
     *
     * @return array Cache statistics
     */
    public function getCacheStatistics(): array {
        return $this->cache->getStats();
    }

    /**
     * Clear specific cache type
     *
     * @param string $type Cache type to clear
     * @return int Number of entries cleared
     */
    public function clearCacheByType(string $type): int {
        return $this->cache->invalidateByType($type);
    }

    /**
     * Warmup caches with common data
     *
     * @return void
     */
    public function warmupCaches(): void {
        // Pre-load current user balance
        if ($this->currentUser->hasKeys()) {
            $publicKey = $this->currentUser->getPublicKey();

            // Warmup transaction service cache
            $transactionService = $this->getTransactionService();
            if ($transactionService instanceof CachedTransactionService) {
                $transactionService->warmupCache([$publicKey]);
            }

            // Pre-load contacts
            $contacts = $this->getContactRepository()->getAllContacts();
            $this->cache->set('contacts_all', $contacts, 30, 'contact_data');
        }
    }

    /**
     * Register cache invalidation hooks
     *
     * @return void
     */
    public function registerCacheInvalidationHooks(): void {
        // Register hook for settings changes
        $this->cache->registerInvalidationHook('settings_changed', function($cache, $data) {
            // Clear user settings and service instances
            $cache->invalidateByType('user_settings');
            $cache->invalidateByType('service_instance');
        });

        // Register hook for contact updates
        $this->cache->registerInvalidationHook('contact_updated', function($cache, $data) {
            $cache->invalidateByType('contact_data');
            $cache->delete('contacts_all');
        });

        // Register hook for wallet updates
        $this->cache->registerInvalidationHook('wallet_updated', function($cache, $data) {
            $cache->invalidateByType('wallet_balance');
            $cache->invalidateByType('transaction_history');
        });
    }

    /**
     * Get cache hit rate
     *
     * @return float Hit rate percentage
     */
    public function getCacheHitRate(): float {
        $stats = $this->cache->getStats();
        return $stats['hit_rate'] ?? 0.0;
    }

    /**
     * Monitor cache performance
     *
     * @return array Performance metrics
     */
    public function getCachePerformanceMetrics(): array {
        $stats = $this->cache->getStats();

        return [
            'hit_rate' => $stats['hit_rate'],
            'total_entries' => $stats['total_entries'],
            'memory_usage' => $stats['memory_usage'],
            'memory_peak' => $stats['memory_peak'],
            'operations' => [
                'hits' => $stats['hits'],
                'misses' => $stats['misses'],
                'sets' => $stats['sets'],
                'deletes' => $stats['deletes'],
                'invalidations' => $stats['invalidations']
            ],
            'uptime' => $stats['uptime'],
            'type_distribution' => $stats['type_distribution'] ?? []
        ];
    }

    /**
     * Export cache for debugging
     *
     * @return array Current cache contents
     */
    public function exportCache(): array {
        return $this->cache->export();
    }

    /**
     * Reset cache statistics
     *
     * @return void
     */
    public function resetCacheStats(): void {
        $this->cache->resetStats();
    }
}