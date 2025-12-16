<?php
# Copyright 2025

/**
 * Claude Code File Hosting Plugin
 *
 * A decentralized file hosting service for eiou-docker nodes that allows users
 * to store and share files, paying for storage with eIOUs.
 *
 * Features:
 * - Upload and store files with configurable retention periods
 * - Pay for storage using eIOU cryptocurrency
 * - Public and private file sharing with optional password protection
 * - File encryption at rest
 * - Storage quota management
 * - Download tracking
 *
 * @package Plugins\ClaudeCodeFileHosting
 * @version 1.0.0
 */

class ClaudeCodeFileHostingPlugin {
    /**
     * @var ClaudeCodeFileHostingPlugin|null Singleton instance
     */
    private static ?ClaudeCodeFileHostingPlugin $instance = null;

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
     * @var ClaudeCodeFileHostingService Main file hosting service
     */
    private ?ClaudeCodeFileHostingService $fileHostingService = null;

    /**
     * @var SecureLogger Logger instance
     */
    private ?SecureLogger $logger = null;

    /**
     * Plugin constants
     */
    const PLUGIN_ID = 'claude_code_file_hosting';
    const PLUGIN_VERSION = '1.0.0';
    const PLUGIN_NAME = 'Claude Code File Hosting';
    const CONFIG_FILE = __DIR__ . '/plugin.json';
    const STORAGE_DIR = '/etc/eiou/file-hosting/';
    const TEMP_DIR = '/etc/eiou/file-hosting/temp/';

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        $this->loadConfig();
    }

    /**
     * Get singleton instance
     *
     * @return ClaudeCodeFileHostingPlugin
     */
    public static function getInstance(): ClaudeCodeFileHostingPlugin {
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
                    'max_file_size_mb' => 100,
                    'allowed_extensions' => ['*'],
                    'storage_price_per_mb_per_day' => 0.001,
                    'min_storage_days' => 1,
                    'max_storage_days' => 365,
                    'free_storage_mb' => 10,
                    'require_payment_upfront' => true,
                    'auto_delete_expired' => true,
                    'public_downloads_enabled' => true,
                    'encryption_enabled' => true,
                    'encryption_secret' => bin2hex(random_bytes(32))
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

            // Schedule cleanup task
            $this->scheduleCleanup();

            $this->initialized = true;
            $this->log('info', 'File hosting plugin initialized successfully');

            return true;
        } catch (Exception $e) {
            $this->log('error', 'Failed to initialize file hosting plugin', [
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
            self::STORAGE_DIR,
            self::TEMP_DIR
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
        require_once $basePath . '/models/HostedFile.php';
        require_once $basePath . '/models/StoragePlan.php';
        require_once $basePath . '/models/FilePayment.php';

        // Load repository
        require_once $basePath . '/repositories/ClaudeCodeFileHostingRepository.php';

        // Load service
        require_once $basePath . '/services/ClaudeCodeFileHostingService.php';

        // Load API controller
        require_once $basePath . '/api/ClaudeCodeFileHostingApiController.php';

        // Load CLI handler
        require_once $basePath . '/cli/ClaudeCodeFileHostingCliHandler.php';
    }

    /**
     * Initialize database tables for file hosting
     */
    private function initializeDatabase(): void {
        $pdo = $this->services->getPdo();

        // Create file_hosting_files table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS file_hosting_files (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                file_id VARCHAR(64) UNIQUE NOT NULL,
                owner_public_key VARCHAR(128) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                stored_filename VARCHAR(128) NOT NULL,
                mime_type VARCHAR(128) DEFAULT 'application/octet-stream',
                size_bytes BIGINT NOT NULL,
                checksum VARCHAR(64),
                is_encrypted BOOLEAN DEFAULT FALSE,
                is_public BOOLEAN DEFAULT FALSE,
                access_password_hash VARCHAR(255),
                download_count INTEGER DEFAULT 0,
                expires_at TIMESTAMP(6) NOT NULL,
                description TEXT,
                metadata TEXT,
                created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                INDEX idx_file_id (file_id),
                INDEX idx_owner (owner_public_key),
                INDEX idx_expires (expires_at),
                INDEX idx_public (is_public),
                INDEX idx_checksum (checksum)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create file_hosting_plans table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS file_hosting_plans (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                user_public_key VARCHAR(128) UNIQUE NOT NULL,
                plan_type VARCHAR(32) DEFAULT 'free',
                quota_bytes BIGINT DEFAULT 10485760,
                used_bytes BIGINT DEFAULT 0,
                file_count INTEGER DEFAULT 0,
                total_spent DECIMAL(20,8) DEFAULT 0,
                expires_at TIMESTAMP(6) NULL,
                auto_renew BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                INDEX idx_user (user_public_key),
                INDEX idx_plan_type (plan_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create file_hosting_payments table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS file_hosting_payments (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                payment_id VARCHAR(64) UNIQUE NOT NULL,
                file_id VARCHAR(64),
                payer_public_key VARCHAR(128) NOT NULL,
                node_public_key VARCHAR(128) NOT NULL,
                amount DECIMAL(20,8) NOT NULL,
                payment_type VARCHAR(32) NOT NULL,
                status VARCHAR(32) DEFAULT 'pending',
                transaction_id VARCHAR(128),
                storage_days INTEGER DEFAULT 0,
                storage_bytes BIGINT DEFAULT 0,
                price_per_mb_per_day DECIMAL(20,8),
                completed_at TIMESTAMP(6) NULL,
                notes TEXT,
                created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                INDEX idx_payment_id (payment_id),
                INDEX idx_payer (payer_public_key),
                INDEX idx_file (file_id),
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Register API routes for file hosting
     */
    private function registerApiRoutes(): void {
        // Routes are registered through the API controller
        // The main ApiController will delegate to FileHostingApiController
    }

    /**
     * Register CLI commands
     */
    private function registerCliCommands(): void {
        // Commands are registered through the CLI handler
        // The main CLI router will delegate to FileHostingCliHandler
    }

    /**
     * Schedule cleanup of expired files
     */
    private function scheduleCleanup(): void {
        // In a production environment, this would be handled by a cron job
        // or a scheduled task system
    }

    /**
     * Get the file hosting service
     *
     * @return ClaudeCodeFileHostingService
     */
    public function getFileHostingService(): ClaudeCodeFileHostingService {
        if ($this->fileHostingService === null) {
            $this->fileHostingService = new ClaudeCodeFileHostingService(
                new ClaudeCodeFileHostingRepository($this->services->getPdo()),
                $this->services->getUtilityContainer(),
                $this->logger,
                $this->config
            );
        }
        return $this->fileHostingService;
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
     * Hook: Called when a file is uploaded
     *
     * @param array $fileData File data
     */
    public function onFileUpload(array $fileData): void {
        $this->log('info', 'File uploaded', ['file_id' => $fileData['file_id'] ?? 'unknown']);
    }

    /**
     * Hook: Called when a file is deleted
     *
     * @param array $fileData File data
     */
    public function onFileDelete(array $fileData): void {
        $this->log('info', 'File deleted', ['file_id' => $fileData['file_id'] ?? 'unknown']);
    }

    /**
     * Hook: Called when a payment is received
     *
     * @param array $paymentData Payment data
     */
    public function onPaymentReceived(array $paymentData): void {
        $this->log('info', 'Payment received', [
            'payment_id' => $paymentData['payment_id'] ?? 'unknown',
            'amount' => $paymentData['amount'] ?? 0
        ]);
    }

    /**
     * Hook: Called when storage expires
     *
     * @param array $fileData File data
     */
    public function onStorageExpired(array $fileData): void {
        $this->log('info', 'Storage expired', ['file_id' => $fileData['file_id'] ?? 'unknown']);
    }

    /**
     * Hook: Called when plugin is installed
     */
    public function onInstall(): void {
        $this->log('info', 'File hosting plugin installed');
    }

    /**
     * Hook: Called when plugin is uninstalled
     */
    public function onUninstall(): void {
        $this->log('info', 'File hosting plugin uninstalled');
    }

    /**
     * Hook: Called when plugin is activated
     */
    public function onActivate(): void {
        $this->log('info', 'File hosting plugin activated');
    }

    /**
     * Hook: Called when plugin is deactivated
     */
    public function onDeactivate(): void {
        $this->log('info', 'File hosting plugin deactivated');
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
            $this->logger->$level("[FileHosting] $message", $context);
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
