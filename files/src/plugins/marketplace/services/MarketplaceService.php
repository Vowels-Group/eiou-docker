<?php
# Copyright 2025

/**
 * Marketplace Service
 *
 * Business logic for marketplace operations including plugin discovery,
 * installation, updates, and repository management.
 *
 * @package Plugins\Marketplace\Services
 */

class MarketplaceService {
    /**
     * @var MarketplaceRepository Data repository
     */
    private MarketplaceRepository $repository;

    /**
     * @var UtilityServiceContainer Utility services
     */
    private UtilityServiceContainer $utilities;

    /**
     * @var SecureLogger|null Logger
     */
    private ?SecureLogger $logger;

    /**
     * @var array Plugin configuration
     */
    private array $config;

    /**
     * Plugin directories
     */
    const PLUGINS_DIR = '/etc/eiou/plugins/';
    const DOWNLOADS_DIR = '/etc/eiou/plugins/downloads/';
    const INSTALLED_DIR = '/etc/eiou/plugins/installed/';

    /**
     * Constructor
     *
     * @param MarketplaceRepository $repository Data repository
     * @param UtilityServiceContainer $utilities Utility services
     * @param SecureLogger|null $logger Logger
     * @param array $config Plugin configuration
     */
    public function __construct(
        MarketplaceRepository $repository,
        UtilityServiceContainer $utilities,
        ?SecureLogger $logger,
        array $config = []
    ) {
        $this->repository = $repository;
        $this->utilities = $utilities;
        $this->logger = $logger;
        $this->config = $config;
    }

    // ==================== Plugin Discovery ====================

    /**
     * List available plugins
     *
     * @param array $filters Filter options (category, search, sort)
     * @param int $page Page number (1-indexed)
     * @param int $perPage Items per page
     * @return array Paginated plugin list
     */
    public function listPlugins(array $filters = [], int $page = 1, int $perPage = 20): array {
        $offset = ($page - 1) * $perPage;
        $plugins = $this->repository->getPlugins($filters, $perPage, $offset);
        $total = $this->repository->getPluginCount($filters);

        return [
            'plugins' => array_map(fn($p) => $p->toApiResponse(), $plugins),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage)
            ]
        ];
    }

    /**
     * Get a specific plugin
     *
     * @param string $pluginId Plugin identifier
     * @return array|null Plugin data or null if not found
     */
    public function getPlugin(string $pluginId): ?array {
        $plugin = $this->repository->getPluginById($pluginId);
        if (!$plugin) {
            return null;
        }

        $response = $plugin->toApiResponse();

        // Add installation status
        $installed = $this->repository->getInstalledPlugin($pluginId);
        if ($installed) {
            $response['installed'] = true;
            $response['installed_version'] = $installed->installedVersion;
            $response['has_update'] = $installed->hasUpdate();
            $response['is_active'] = $installed->isActive;
        } else {
            $response['installed'] = false;
        }

        // Add reviews
        $response['reviews'] = $this->repository->getPluginReviews($pluginId, 5);

        return $response;
    }

    /**
     * Search for plugins
     *
     * @param string $query Search query
     * @param int $limit Result limit
     * @return array Search results
     */
    public function searchPlugins(string $query, int $limit = 20): array {
        $filters = ['search' => $query];
        $plugins = $this->repository->getPlugins($filters, $limit);

        return [
            'query' => $query,
            'results' => array_map(fn($p) => $p->toApiResponse(), $plugins),
            'count' => count($plugins)
        ];
    }

    /**
     * Get all categories
     *
     * @return array Category list with counts
     */
    public function getCategories(): array {
        return $this->repository->getCategories();
    }

    // ==================== Plugin Installation ====================

    /**
     * Install a plugin
     *
     * @param string $pluginId Plugin identifier
     * @return array Installation result
     * @throws Exception On installation failure
     */
    public function installPlugin(string $pluginId): array {
        // Check if already installed
        $existing = $this->repository->getInstalledPlugin($pluginId);
        if ($existing && $existing->isActive) {
            throw new Exception("Plugin {$pluginId} is already installed");
        }

        // Get plugin info
        $plugin = $this->repository->getPluginById($pluginId);
        if (!$plugin) {
            throw new Exception("Plugin {$pluginId} not found");
        }

        // Verify plugin signature if required
        if (!($this->config['settings']['allow_unsigned_plugins'] ?? false)) {
            if (empty($plugin->signature)) {
                throw new Exception("Plugin {$pluginId} is not signed. Enable allow_unsigned_plugins to install.");
            }
            if (!$this->verifySignature($plugin)) {
                throw new Exception("Plugin {$pluginId} signature verification failed");
            }
        }

        try {
            // Download plugin package
            $packagePath = $this->downloadPlugin($plugin);

            // Verify checksum
            if (!empty($plugin->checksum)) {
                $actualChecksum = hash_file('sha256', $packagePath);
                if ($actualChecksum !== $plugin->checksum) {
                    unlink($packagePath);
                    throw new Exception("Checksum verification failed for {$pluginId}");
                }
            }

            // Extract plugin
            $installPath = $this->extractPlugin($packagePath, $pluginId);

            // Validate plugin manifest
            $manifestPath = $installPath . '/plugin.json';
            if (!file_exists($manifestPath)) {
                $this->removeDirectory($installPath);
                throw new Exception("Plugin {$pluginId} has no valid manifest");
            }

            // Create installed record
            $installed = new InstalledPlugin();
            $installed->pluginId = $pluginId;
            $installed->installedVersion = $plugin->version;
            $installed->installPath = $installPath;
            $installed->isActive = true;
            $installed->autoUpdate = false;

            $this->repository->saveInstalledPlugin($installed);

            // Record download
            $this->repository->recordDownload($pluginId, $plugin->version);

            // Clean up
            unlink($packagePath);

            $this->log('info', "Installed plugin {$pluginId} v{$plugin->version}");

            return [
                'success' => true,
                'plugin_id' => $pluginId,
                'version' => $plugin->version,
                'install_path' => $installPath,
                'message' => "Plugin {$plugin->name} installed successfully"
            ];

        } catch (Exception $e) {
            $this->log('error', "Failed to install plugin {$pluginId}", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Uninstall a plugin
     *
     * @param string $pluginId Plugin identifier
     * @return array Uninstallation result
     * @throws Exception On uninstallation failure
     */
    public function uninstallPlugin(string $pluginId): array {
        $installed = $this->repository->getInstalledPlugin($pluginId);
        if (!$installed) {
            throw new Exception("Plugin {$pluginId} is not installed");
        }

        try {
            // Run plugin's uninstall hook if it exists
            $this->runPluginHook($installed, 'onUninstall');

            // Remove plugin files
            if (is_dir($installed->installPath)) {
                $this->removeDirectory($installed->installPath);
            }

            // Remove from database
            $this->repository->removeInstalledPlugin($pluginId);

            $this->log('info', "Uninstalled plugin {$pluginId}");

            return [
                'success' => true,
                'plugin_id' => $pluginId,
                'message' => "Plugin {$pluginId} uninstalled successfully"
            ];

        } catch (Exception $e) {
            $this->log('error', "Failed to uninstall plugin {$pluginId}", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update a plugin
     *
     * @param string $pluginId Plugin identifier
     * @return array Update result
     * @throws Exception On update failure
     */
    public function updatePlugin(string $pluginId): array {
        $installed = $this->repository->getInstalledPlugin($pluginId);
        if (!$installed) {
            throw new Exception("Plugin {$pluginId} is not installed");
        }

        $plugin = $this->repository->getPluginById($pluginId);
        if (!$plugin) {
            throw new Exception("Plugin {$pluginId} not found in marketplace");
        }

        if (!$installed->hasUpdate()) {
            return [
                'success' => true,
                'plugin_id' => $pluginId,
                'message' => "Plugin {$pluginId} is already up to date"
            ];
        }

        // Backup current version
        $backupPath = $installed->installPath . '.backup';
        if (is_dir($installed->installPath)) {
            rename($installed->installPath, $backupPath);
        }

        try {
            // Install new version
            $packagePath = $this->downloadPlugin($plugin);
            $installPath = $this->extractPlugin($packagePath, $pluginId);

            // Update installed record
            $installed->installedVersion = $plugin->version;
            $installed->availableVersion = null;
            $installed->installPath = $installPath;
            $this->repository->saveInstalledPlugin($installed);

            // Clean up
            unlink($packagePath);
            if (is_dir($backupPath)) {
                $this->removeDirectory($backupPath);
            }

            $this->log('info', "Updated plugin {$pluginId} to v{$plugin->version}");

            return [
                'success' => true,
                'plugin_id' => $pluginId,
                'version' => $plugin->version,
                'message' => "Plugin {$pluginId} updated to v{$plugin->version}"
            ];

        } catch (Exception $e) {
            // Restore backup
            if (is_dir($backupPath)) {
                if (is_dir($installed->installPath)) {
                    $this->removeDirectory($installed->installPath);
                }
                rename($backupPath, $installed->installPath);
            }

            $this->log('error', "Failed to update plugin {$pluginId}", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get list of installed plugins
     *
     * @return array Installed plugins
     */
    public function getInstalledPlugins(): array {
        $installed = $this->repository->getInstalledPlugins();
        return array_map(fn($i) => $i->toApiResponse(), $installed);
    }

    /**
     * Check for available updates
     *
     * @return array Updates available
     */
    public function checkUpdates(): array {
        $installed = $this->repository->getInstalledPlugins();
        $updates = [];

        foreach ($installed as $plugin) {
            $available = $this->repository->getPluginById($plugin->pluginId);
            if ($available && version_compare($available->version, $plugin->installedVersion, '>')) {
                $updates[] = [
                    'plugin_id' => $plugin->pluginId,
                    'installed_version' => $plugin->installedVersion,
                    'available_version' => $available->version,
                    'name' => $available->name
                ];

                // Update available version in database
                $plugin->availableVersion = $available->version;
                $this->repository->saveInstalledPlugin($plugin);
            }
        }

        return [
            'updates_available' => count($updates),
            'updates' => $updates
        ];
    }

    // ==================== Repository Management ====================

    /**
     * Get all configured repositories
     *
     * @return array Repository list
     */
    public function getRepositories(): array {
        $repositories = $this->repository->getRepositories();
        return array_map(fn($r) => $r->toApiResponse(), $repositories);
    }

    /**
     * Add a new repository
     *
     * @param string $url Repository URL
     * @param string $name Repository name
     * @param string $description Repository description
     * @return array Result
     * @throws Exception On failure
     */
    public function addRepository(string $url, string $name, string $description = ''): array {
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception("Invalid repository URL");
        }

        // Check if already exists
        $existing = $this->repository->getRepositoryByUrl($url);
        if ($existing) {
            throw new Exception("Repository already exists");
        }

        // Test connectivity
        $testUrl = rtrim($url, '/') . '/plugins';
        $response = @file_get_contents($testUrl, false, stream_context_create([
            'http' => ['timeout' => 10, 'ignore_errors' => true]
        ]));

        if ($response === false) {
            throw new Exception("Cannot connect to repository at {$url}");
        }

        // Create repository
        $repo = new Repository();
        $repo->name = $name;
        $repo->url = $url;
        $repo->description = $description;
        $repo->isOfficial = false;
        $repo->isEnabled = true;
        $repo->priority = 100;

        $this->repository->saveRepository($repo);

        $this->log('info', "Added repository: {$name} ({$url})");

        return [
            'success' => true,
            'message' => "Repository {$name} added successfully",
            'repository' => $repo->toApiResponse()
        ];
    }

    /**
     * Remove a repository
     *
     * @param int $id Repository ID
     * @return array Result
     * @throws Exception On failure
     */
    public function removeRepository(int $id): array {
        $repo = $this->repository->getRepositoryById($id);
        if (!$repo) {
            throw new Exception("Repository not found");
        }

        if ($repo->isOfficial) {
            throw new Exception("Cannot remove official repository");
        }

        $this->repository->deleteRepository($id);

        $this->log('info', "Removed repository: {$repo->name}");

        return [
            'success' => true,
            'message' => "Repository {$repo->name} removed successfully"
        ];
    }

    /**
     * Sync a repository's plugin list
     *
     * @param int|null $repositoryId Repository ID (null for all)
     * @return array Sync results
     */
    public function syncRepository(?int $repositoryId = null): array {
        $repositories = $repositoryId
            ? [$this->repository->getRepositoryById($repositoryId)]
            : $this->repository->getRepositories(true);

        $results = [];

        foreach ($repositories as $repo) {
            if (!$repo) continue;

            try {
                $this->repository->updateRepositorySyncStatus($repo->id, 'syncing');

                // Fetch plugin list from repository
                $pluginsUrl = $repo->getEndpoint('/plugins');
                $response = @file_get_contents($pluginsUrl, false, stream_context_create([
                    'http' => ['timeout' => 30]
                ]));

                if ($response === false) {
                    throw new Exception("Failed to fetch plugins from {$repo->name}");
                }

                $data = json_decode($response, true);
                if (!$data || !isset($data['plugins'])) {
                    throw new Exception("Invalid response from {$repo->name}");
                }

                $count = 0;
                foreach ($data['plugins'] as $pluginData) {
                    $plugin = Plugin::fromManifest($pluginData);
                    $plugin->repositoryId = $repo->id;

                    $errors = $plugin->validate();
                    if (empty($errors)) {
                        $this->repository->savePlugin($plugin);
                        $count++;
                    }
                }

                $this->repository->updateRepositorySyncStatus($repo->id, 'success', $count);

                $results[] = [
                    'repository' => $repo->name,
                    'success' => true,
                    'plugins_synced' => $count
                ];

                $this->log('info', "Synced repository {$repo->name}: {$count} plugins");

            } catch (Exception $e) {
                $this->repository->updateRepositorySyncStatus($repo->id, 'error');
                $results[] = [
                    'repository' => $repo->name,
                    'success' => false,
                    'error' => $e->getMessage()
                ];

                $this->log('error', "Failed to sync repository {$repo->name}", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'results' => $results,
            'total_synced' => array_sum(array_column(array_filter($results, fn($r) => $r['success']), 'plugins_synced'))
        ];
    }

    // ==================== Plugin Publishing ====================

    /**
     * Publish a plugin to the local marketplace
     *
     * @param array $manifest Plugin manifest
     * @param string|null $packagePath Path to plugin package
     * @return array Result
     * @throws Exception On failure
     */
    public function publishPlugin(array $manifest, ?string $packagePath = null): array {
        $plugin = Plugin::fromManifest($manifest);

        $errors = $plugin->validate();
        if (!empty($errors)) {
            throw new Exception("Invalid plugin manifest: " . implode(', ', $errors));
        }

        // Check if already exists
        $existing = $this->repository->getPluginById($plugin->pluginId);
        if ($existing) {
            // Verify newer version
            if ($plugin->compareVersion($existing->version) <= 0) {
                throw new Exception("Version must be greater than existing ({$existing->version})");
            }
        }

        // Calculate checksum if package provided
        if ($packagePath && file_exists($packagePath)) {
            $plugin->checksum = hash_file('sha256', $packagePath);
        }

        $this->repository->savePlugin($plugin);

        $this->log('info', "Published plugin {$plugin->pluginId} v{$plugin->version}");

        return [
            'success' => true,
            'plugin_id' => $plugin->pluginId,
            'version' => $plugin->version,
            'message' => "Plugin {$plugin->name} published successfully"
        ];
    }

    /**
     * Unpublish a plugin from the local marketplace
     *
     * @param string $pluginId Plugin identifier
     * @return array Result
     * @throws Exception On failure
     */
    public function unpublishPlugin(string $pluginId): array {
        $plugin = $this->repository->getPluginById($pluginId);
        if (!$plugin) {
            throw new Exception("Plugin {$pluginId} not found");
        }

        $this->repository->deletePlugin($pluginId);

        $this->log('info', "Unpublished plugin {$pluginId}");

        return [
            'success' => true,
            'plugin_id' => $pluginId,
            'message' => "Plugin {$pluginId} unpublished successfully"
        ];
    }

    // ==================== Helper Methods ====================

    /**
     * Download a plugin package
     *
     * @param Plugin $plugin Plugin to download
     * @return string Path to downloaded package
     * @throws Exception On failure
     */
    private function downloadPlugin(Plugin $plugin): string {
        $downloadUrl = $plugin->manifest['download_url'] ?? null;
        if (!$downloadUrl) {
            throw new Exception("No download URL available for plugin {$plugin->pluginId}");
        }

        $downloadPath = self::DOWNLOADS_DIR . $plugin->pluginId . '-' . $plugin->version . '.zip';

        // Ensure downloads directory exists
        if (!is_dir(self::DOWNLOADS_DIR)) {
            mkdir(self::DOWNLOADS_DIR, 0755, true);
        }

        // Download with size limit
        $maxSize = ($this->config['settings']['max_plugin_size_mb'] ?? 50) * 1024 * 1024;

        $context = stream_context_create([
            'http' => [
                'timeout' => 120,
                'max_redirects' => 5
            ]
        ]);

        $content = @file_get_contents($downloadUrl, false, $context, 0, $maxSize);
        if ($content === false) {
            throw new Exception("Failed to download plugin from {$downloadUrl}");
        }

        file_put_contents($downloadPath, $content);

        return $downloadPath;
    }

    /**
     * Extract a plugin package
     *
     * @param string $packagePath Path to package file
     * @param string $pluginId Plugin identifier
     * @return string Installation path
     * @throws Exception On failure
     */
    private function extractPlugin(string $packagePath, string $pluginId): string {
        $installPath = self::INSTALLED_DIR . $pluginId;

        // Ensure installed directory exists
        if (!is_dir(self::INSTALLED_DIR)) {
            mkdir(self::INSTALLED_DIR, 0755, true);
        }

        // Remove existing installation
        if (is_dir($installPath)) {
            $this->removeDirectory($installPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            throw new Exception("Failed to open plugin package");
        }

        if (!$zip->extractTo($installPath)) {
            $zip->close();
            throw new Exception("Failed to extract plugin package");
        }

        $zip->close();

        return $installPath;
    }

    /**
     * Verify a plugin's digital signature
     *
     * @param Plugin $plugin Plugin to verify
     * @return bool True if valid
     */
    private function verifySignature(Plugin $plugin): bool {
        // Signature verification implementation
        // This would use public key cryptography to verify the package
        // For now, return true if signature exists
        return !empty($plugin->signature);
    }

    /**
     * Run a plugin's lifecycle hook
     *
     * @param InstalledPlugin $installed Installed plugin
     * @param string $hook Hook name
     */
    private function runPluginHook(InstalledPlugin $installed, string $hook): void {
        $manifest = $installed->loadManifest();
        if (!$manifest || !isset($manifest['hooks'][$hook])) {
            return;
        }

        $hookMethod = $manifest['hooks'][$hook];
        $entrypoint = $manifest['entrypoint'] ?? null;

        if (!$entrypoint) {
            return;
        }

        $entrypointPath = $installed->installPath . '/' . $entrypoint;
        if (!file_exists($entrypointPath)) {
            return;
        }

        try {
            require_once $entrypointPath;
            $className = pathinfo($entrypoint, PATHINFO_FILENAME);

            if (class_exists($className) && method_exists($className, $hookMethod)) {
                $instance = new $className();
                $instance->$hookMethod(['plugin_id' => $installed->pluginId]);
            }
        } catch (Exception $e) {
            $this->log('error', "Failed to run hook {$hook} for {$installed->pluginId}", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Recursively remove a directory
     *
     * @param string $path Directory path
     */
    private function removeDirectory(string $path): void {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($path);
    }

    /**
     * Log a message
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logger) {
            $this->logger->$level("[Marketplace] $message", $context);
        }
    }
}
