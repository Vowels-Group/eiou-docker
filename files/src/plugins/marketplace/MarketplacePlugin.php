<?php
# Copyright 2025

/**
 * Claude Code Marketplace Plugin
 *
 * A self-hosted marketplace/appstore plugin for Claude Code that allows users
 * to discover, install, and manage Claude Code extensions, tools, and integrations.
 *
 * This is the first plugin for eiou-docker and serves as a reference implementation
 * for the plugin architecture.
 *
 * @package Plugins\Marketplace
 * @version 1.0.0
 */

class MarketplacePlugin {
    /**
     * @var MarketplacePlugin|null Singleton instance
     */
    private static ?MarketplacePlugin $instance = null;

    /**
     * @var array Plugin configuration
     */
    private array $config;

    /**
     * @var bool Whether the plugin is initialized
     */
    private bool $initialized = false;

    /**
     * @var ServiceContainer Service container reference
     */
    private ServiceContainer $services;

    /**
     * @var MarketplaceService Main marketplace service
     */
    private ?MarketplaceService $marketplaceService = null;

    /**
     * @var SecureLogger Logger instance
     */
    private ?SecureLogger $logger = null;

    /**
     * Plugin constants
     */
    const PLUGIN_ID = 'claude-code-marketplace';
    const PLUGIN_VERSION = '1.0.0';
    const PLUGIN_NAME = 'Claude Code Marketplace';
    const CONFIG_FILE = __DIR__ . '/plugin.json';
    const PLUGINS_DIR = '/etc/eiou/plugins/';
    const CACHE_DIR = '/etc/eiou/cache/marketplace/';

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        $this->loadConfig();
    }

    /**
     * Get singleton instance
     *
     * @return MarketplacePlugin
     */
    public static function getInstance(): MarketplacePlugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load plugin configuration from plugin.json
     */
    private function loadConfig(): void {
        $configPath = self::CONFIG_FILE;
        if (file_exists($configPath)) {
            $json = file_get_contents($configPath);
            $this->config = json_decode($json, true) ?? [];
        } else {
            $this->config = [
                'name' => self::PLUGIN_NAME,
                'id' => self::PLUGIN_ID,
                'version' => self::PLUGIN_VERSION,
                'settings' => [
                    'auto_update' => false,
                    'check_updates_interval' => 86400,
                    'default_repository' => 'https://marketplace.eiou.org/api/v1',
                    'allow_unsigned_plugins' => false,
                    'max_plugin_size_mb' => 50
                ]
            ];
        }
    }

    /**
     * Initialize the plugin with required dependencies
     *
     * @param ServiceContainer $services Service container
     * @return bool True if initialization successful
     */
    public function initialize(ServiceContainer $services): bool {
        if ($this->initialized) {
            return true;
        }

        try {
            $this->services = $services;
            $this->logger = $services->getLogger();

            // Ensure required directories exist
            $this->ensureDirectories();

            // Load plugin dependencies
            $this->loadDependencies();

            // Initialize database schema
            $this->initializeDatabase();

            // Register API routes
            $this->registerApiRoutes();

            // Register CLI commands
            $this->registerCliCommands();

            $this->initialized = true;
            $this->log('info', 'Marketplace plugin initialized successfully');

            return true;
        } catch (Exception $e) {
            $this->log('error', 'Failed to initialize marketplace plugin', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Ensure required directories exist
     */
    private function ensureDirectories(): void {
        $directories = [
            self::PLUGINS_DIR,
            self::CACHE_DIR,
            self::PLUGINS_DIR . 'installed/',
            self::PLUGINS_DIR . 'downloads/'
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Load plugin dependencies
     */
    private function loadDependencies(): void {
        $basePath = __DIR__;

        // Load models
        require_once $basePath . '/models/Plugin.php';
        require_once $basePath . '/models/Repository.php';
        require_once $basePath . '/models/InstalledPlugin.php';

        // Load repository
        require_once $basePath . '/repositories/MarketplaceRepository.php';

        // Load service
        require_once $basePath . '/services/MarketplaceService.php';

        // Load API controller
        require_once $basePath . '/api/MarketplaceApiController.php';

        // Load CLI handler
        require_once $basePath . '/cli/MarketplaceCliHandler.php';
    }

    /**
     * Initialize database tables for marketplace
     */
    private function initializeDatabase(): void {
        $pdo = $this->services->getPdo();

        // Create marketplace_plugins table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS marketplace_plugins (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                plugin_id VARCHAR(128) UNIQUE NOT NULL,
                name VARCHAR(255) NOT NULL,
                version VARCHAR(32) NOT NULL,
                description TEXT,
                author VARCHAR(255),
                homepage VARCHAR(512),
                license VARCHAR(64),
                category VARCHAR(64) DEFAULT 'general',
                tags TEXT,
                downloads INTEGER DEFAULT 0,
                rating DECIMAL(3,2) DEFAULT 0.00,
                rating_count INTEGER DEFAULT 0,
                manifest TEXT NOT NULL,
                checksum VARCHAR(128),
                signature TEXT,
                repository_id INTEGER,
                created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                INDEX idx_plugin_id (plugin_id),
                INDEX idx_category (category),
                INDEX idx_downloads (downloads DESC),
                INDEX idx_rating (rating DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create marketplace_repositories table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS marketplace_repositories (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                url VARCHAR(512) UNIQUE NOT NULL,
                description TEXT,
                is_official BOOLEAN DEFAULT FALSE,
                is_enabled BOOLEAN DEFAULT TRUE,
                priority INTEGER DEFAULT 100,
                last_sync TIMESTAMP(6) NULL,
                sync_status VARCHAR(32) DEFAULT 'pending',
                plugin_count INTEGER DEFAULT 0,
                created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                INDEX idx_url (url),
                INDEX idx_enabled (is_enabled),
                INDEX idx_priority (priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create marketplace_installed table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS marketplace_installed (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                plugin_id VARCHAR(128) NOT NULL,
                installed_version VARCHAR(32) NOT NULL,
                available_version VARCHAR(32),
                install_path TEXT NOT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                auto_update BOOLEAN DEFAULT FALSE,
                installed_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                settings TEXT,
                UNIQUE KEY unique_plugin (plugin_id),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create marketplace_downloads table for tracking
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS marketplace_downloads (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                plugin_id VARCHAR(128) NOT NULL,
                version VARCHAR(32) NOT NULL,
                ip_hash VARCHAR(64),
                user_agent TEXT,
                downloaded_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                INDEX idx_plugin (plugin_id),
                INDEX idx_date (downloaded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create marketplace_reviews table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS marketplace_reviews (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                plugin_id VARCHAR(128) NOT NULL,
                user_hash VARCHAR(64) NOT NULL,
                rating TINYINT NOT NULL CHECK (rating >= 1 AND rating <= 5),
                title VARCHAR(255),
                review TEXT,
                is_verified BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                UNIQUE KEY unique_user_review (plugin_id, user_hash),
                INDEX idx_plugin (plugin_id),
                INDEX idx_rating (rating)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Insert default repository if not exists
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO marketplace_repositories (name, url, description, is_official, priority)
            VALUES (:name, :url, :description, :is_official, :priority)
        ");
        $stmt->execute([
            ':name' => 'EIOU Official Repository',
            ':url' => $this->config['settings']['default_repository'] ?? 'https://marketplace.eiou.org/api/v1',
            ':description' => 'Official Claude Code marketplace repository',
            ':is_official' => true,
            ':priority' => 1
        ]);
    }

    /**
     * Register API routes for marketplace
     */
    private function registerApiRoutes(): void {
        // Routes are registered through the API controller
        // The main ApiController will delegate to MarketplaceApiController
    }

    /**
     * Register CLI commands
     */
    private function registerCliCommands(): void {
        // Commands are registered through the CLI handler
        // The main CLI router will delegate to MarketplaceCliHandler
    }

    /**
     * Get the marketplace service
     *
     * @return MarketplaceService
     */
    public function getMarketplaceService(): MarketplaceService {
        if ($this->marketplaceService === null) {
            $this->marketplaceService = new MarketplaceService(
                new MarketplaceRepository($this->services->getPdo()),
                $this->services->getUtilityContainer(),
                $this->logger,
                $this->config
            );
        }
        return $this->marketplaceService;
    }

    /**
     * Get plugin configuration
     *
     * @return array
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Get a specific setting
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    public function getSetting(string $key, $default = null) {
        return $this->config['settings'][$key] ?? $default;
    }

    /**
     * Update a setting
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     */
    public function setSetting(string $key, $value): void {
        $this->config['settings'][$key] = $value;
    }

    /**
     * Check if plugin is initialized
     *
     * @return bool
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Hook: Called when a plugin is installed
     *
     * @param array $pluginData Plugin data
     */
    public function onPluginInstall(array $pluginData): void {
        $this->log('info', 'Plugin installed', ['plugin_id' => $pluginData['plugin_id'] ?? 'unknown']);
    }

    /**
     * Hook: Called when a plugin is uninstalled
     *
     * @param array $pluginData Plugin data
     */
    public function onPluginUninstall(array $pluginData): void {
        $this->log('info', 'Plugin uninstalled', ['plugin_id' => $pluginData['plugin_id'] ?? 'unknown']);
    }

    /**
     * Hook: Called when a plugin is activated
     *
     * @param array $pluginData Plugin data
     */
    public function onPluginActivate(array $pluginData): void {
        $this->log('info', 'Plugin activated', ['plugin_id' => $pluginData['plugin_id'] ?? 'unknown']);
    }

    /**
     * Hook: Called when a plugin is deactivated
     *
     * @param array $pluginData Plugin data
     */
    public function onPluginDeactivate(array $pluginData): void {
        $this->log('info', 'Plugin deactivated', ['plugin_id' => $pluginData['plugin_id'] ?? 'unknown']);
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
            $context['plugin'] = self::PLUGIN_ID;
            $this->logger->$level("[Marketplace] $message", $context);
        }
    }

    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}

    /**
     * Prevent unserialization of singleton
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
