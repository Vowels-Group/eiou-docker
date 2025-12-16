<?php
# Copyright 2025

/**
 * Messages Plugin
 *
 * Peer-to-peer messaging between users on eiou-docker nodes.
 * Messages are encrypted using public keys.
 *
 * @package Plugins\Messages
 * @version 1.0.0
 */

class MessagesPlugin {
    /**
     * @var MessagesPlugin|null Singleton instance
     */
    private static ?MessagesPlugin $instance = null;

    /**
     * @var array Plugin configuration
     */
    private array $config;

    /**
     * @var bool Whether initialized
     */
    private bool $initialized = false;

    /**
     * @var ServiceContainer Service container
     */
    private ServiceContainer $services;

    /**
     * @var MessagesService|null Messages service
     */
    private ?MessagesService $messagesService = null;

    /**
     * @var SecureLogger|null Logger
     */
    private ?SecureLogger $logger = null;

    /**
     * Plugin constants
     */
    const PLUGIN_ID = 'messages';
    const PLUGIN_VERSION = '1.0.0';
    const PLUGIN_NAME = 'Messages';
    const CONFIG_FILE = __DIR__ . '/plugin.json';

    /**
     * Private constructor
     */
    private function __construct() {
        $this->loadConfig();
    }

    /**
     * Get singleton instance
     *
     * @return MessagesPlugin
     */
    public static function getInstance(): MessagesPlugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load configuration
     */
    private function loadConfig(): void {
        if (file_exists(self::CONFIG_FILE)) {
            $json = file_get_contents(self::CONFIG_FILE);
            $this->config = json_decode($json, true) ?? [];
        } else {
            $this->config = [
                'name' => self::PLUGIN_NAME,
                'id' => self::PLUGIN_ID,
                'version' => self::PLUGIN_VERSION,
                'settings' => [
                    'max_message_length' => 10000,
                    'message_retention_days' => 365,
                    'encryption_enabled' => true,
                    'read_receipts_enabled' => true
                ]
            ];
        }
    }

    /**
     * Initialize the plugin
     *
     * @param ServiceContainer $services Service container
     * @return bool Success
     */
    public function initialize(ServiceContainer $services): bool {
        if ($this->initialized) {
            return true;
        }

        try {
            $this->services = $services;
            $this->logger = $services->getLogger();

            $this->loadDependencies();
            $this->initializeDatabase();

            $this->initialized = true;
            $this->log('info', 'Messages plugin initialized');

            return true;
        } catch (Exception $e) {
            $this->log('error', 'Failed to initialize messages plugin', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Load dependencies
     */
    private function loadDependencies(): void {
        $basePath = __DIR__;

        require_once $basePath . '/models/Message.php';
        require_once $basePath . '/models/Conversation.php';
        require_once $basePath . '/repositories/MessagesRepository.php';
        require_once $basePath . '/services/MessagesService.php';
        require_once $basePath . '/api/MessagesApiController.php';
        require_once $basePath . '/cli/MessagesCliHandler.php';
    }

    /**
     * Initialize database tables
     */
    private function initializeDatabase(): void {
        $pdo = $this->services->getPdo();

        // Messages table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS plugin_messages (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                message_id VARCHAR(64) UNIQUE NOT NULL,
                conversation_id VARCHAR(64) NOT NULL,
                sender_public_key VARCHAR(128) NOT NULL,
                recipient_public_key VARCHAR(128) NOT NULL,
                content TEXT NOT NULL,
                is_encrypted BOOLEAN DEFAULT FALSE,
                message_type VARCHAR(32) DEFAULT 'text',
                attachments TEXT,
                reply_to_id VARCHAR(64),
                is_read BOOLEAN DEFAULT FALSE,
                read_at TIMESTAMP(6) NULL,
                deleted_by_sender BOOLEAN DEFAULT FALSE,
                deleted_by_recipient BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                INDEX idx_message_id (message_id),
                INDEX idx_conversation (conversation_id),
                INDEX idx_sender (sender_public_key),
                INDEX idx_recipient (recipient_public_key),
                INDEX idx_created (created_at),
                INDEX idx_unread (recipient_public_key, is_read)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Conversations table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS plugin_conversations (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                conversation_id VARCHAR(64) UNIQUE NOT NULL,
                participant1_public_key VARCHAR(128) NOT NULL,
                participant2_public_key VARCHAR(128) NOT NULL,
                last_message_preview TEXT,
                last_message_at TIMESTAMP(6) NULL,
                unread_count_1 INTEGER DEFAULT 0,
                unread_count_2 INTEGER DEFAULT 0,
                archived_by_1 BOOLEAN DEFAULT FALSE,
                archived_by_2 BOOLEAN DEFAULT FALSE,
                muted_by_1 BOOLEAN DEFAULT FALSE,
                muted_by_2 BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                INDEX idx_conv_id (conversation_id),
                INDEX idx_participant1 (participant1_public_key),
                INDEX idx_participant2 (participant2_public_key),
                INDEX idx_last_message (last_message_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Get messages service
     *
     * @return MessagesService
     */
    public function getMessagesService(): MessagesService {
        if ($this->messagesService === null) {
            $this->messagesService = new MessagesService(
                new MessagesRepository($this->services->getPdo()),
                $this->logger,
                $this->config
            );
        }
        return $this->messagesService;
    }

    /**
     * Get configuration
     *
     * @return array
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Get a setting
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    public function getSetting(string $key, $default = null) {
        return $this->config['settings'][$key] ?? $default;
    }

    /**
     * Check if initialized
     *
     * @return bool
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    // ==================== Hooks ====================

    public function onInstall(): void {
        $this->log('info', 'Messages plugin installed');
    }

    public function onUninstall(): void {
        $this->log('info', 'Messages plugin uninstalled');
    }

    public function onMessageSent(array $data): void {
        $this->log('info', 'Message sent', ['message_id' => $data['message_id'] ?? 'unknown']);
    }

    public function onMessageReceived(array $data): void {
        $this->log('info', 'Message received', ['message_id' => $data['message_id'] ?? 'unknown']);
    }

    public function onMessageRead(array $data): void {
        $this->log('info', 'Message read', ['message_id' => $data['message_id'] ?? 'unknown']);
    }

    /**
     * Log a message
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logger) {
            $context['plugin'] = self::PLUGIN_ID;
            $this->logger->$level("[Messages] $message", $context);
        }
    }

    private function __clone() {}

    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
