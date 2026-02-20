<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Core;

use Eiou\Security\KeyEncryption;
use Exception;

/**
 * DbContext - Singleton wrapper for database configuration
 *
 * This class provides a centralized, type-safe way to access user configuration
 *
 */

class DatabaseContext {
    private static ?DatabaseContext $instance = null;
    private array $databaseData = [];
    private bool $initialized = false;

    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct() {
        $this->loadConfigFromFiles();
    }

    /**
     * Get singleton instance
     *
     * @return DatabaseContext
     */
    public static function getInstance(): DatabaseContext {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize db context from File
     *
     * @return void
     */
    private function loadConfigFromFiles(): void {
        $path = '/etc/eiou/config/dbconfig.json';
        if (file_exists($path)){
            $contents = file_get_contents($path);
            if ($contents === false) {
                error_log("DatabaseContext: Failed to read $path");
                return;
            }
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                $this->databaseData = $decoded;
                $this->initialized = true;
            } else {
                error_log("DatabaseContext: Invalid JSON in $path (json_last_error: " . json_last_error_msg() . ")");
            }
        }
    }

    /**
     * Set database data directly
     *
     * @param array $databaseData Database configuration array
     * @return void
     */
    public function setdatabaseData(array $databaseData): void {
        $this->databaseData = $databaseData;
        $this->initialized = true;
    }

    /**
     * Get a db configuration value
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed
     */
    public function get(string $key, $default = null) {
        return $this->databaseData[$key] ?? $default;
    }

    /**
     * Set a db configuration value
     *
     * @param string $key Configuration key
     * @param mixed $value Value to set
     * @return void
     */
    public function set(string $key, $value): void {
        $this->databaseData[$key] = $value;
    }

    /**
     * Check if a configuration key exists
     *
     * @param string $key Configuration key
     * @return bool
     */
    public function has(string $key): bool {
        return isset($this->databaseData[$key]);
    }

    /**
     * Get all database data
     *
     * @return array
     */
    public function getAll(): array {
        return $this->databaseData;
    }

    /**
     * Get database host
     *
     * @return string|null
     */
    public function getDbHost(): ?string {
        return $this->get('dbHost') ?? null;
    }

    /**
     * Get database name
     *
     * @return string|null
     */
    public function getDbName(): ?string {
        return $this->get('dbName') ?? null;
    }

    /**
     * Get database user
     *
     * @return string|null
     */
    public function getDbUser(): ?string {
        return $this->get('dbUser') ?? null;
    }

    /**
     * Get database password (decrypts from encrypted storage)
     *
     * @return string|null
     */
    public function getDbPass(): ?string {
        $encrypted = $this->get('dbPassEncrypted');
        if (is_array($encrypted) && isset($encrypted['ciphertext'], $encrypted['iv'], $encrypted['tag'])) {
            try {
                return KeyEncryption::decrypt($encrypted);
            } catch (Exception $e) {
                error_log("DatabaseContext: Failed to decrypt dbPass: " . $e->getMessage());
                return null;
            }
        }
        error_log("DatabaseContext: No encrypted database password found in config");
        return null;
    }

    /**
     * Check if database configuration is valid
     *
     * @return bool
     */
    public function hasValidDbConfig(): bool {
        return $this->getDbHost() !== null
            && $this->getDbName() !== null
            && $this->getDbUser() !== null
            && $this->getDbPass() !== null;
    }

    /**
     * Get all database data as array
     *
     * @return array
     */
    public function toArray(): array {
        return $this->databaseData;
    }

    /**
     * Check if db context is properly initialized
     *
     * @return bool
     */
    public function isInitialized(): bool {
        return $this->initialized && !empty($this->databaseData);
    }

    /**
     * Clear database context (for testing or logout)
     *
     * @return void
     */
    public function clear(): void {
        $this->databaseData = [];
        $this->initialized = false;
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