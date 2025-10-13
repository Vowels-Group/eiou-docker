<?php
/**
 * UserContext - Singleton wrapper for user configuration
 *
 * This class provides a centralized, type-safe way to access user configuration
 * and gradually transition away from the global $user variable.
 *
 * @todo Eventually replace all global $user references with this class
 */

class UserContext {
    private static ?UserContext $instance = null;
    private array $userData = [];
    private bool $initialized = false;

    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct() {}

    /**
     * Get singleton instance
     *
     * @return UserContext
     */
    public static function getInstance(): UserContext {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize user context from global $user variable
     * This method allows gradual migration from global $user
     *
     * @return void
     */
    public function initFromGlobal(): void {
        global $user;
        if ($user && is_array($user)) {
            $this->userData = $user;
            $this->initialized = true;
        }
    }

    /**
     * Set user data directly
     *
     * @param array $userData User configuration array
     * @return void
     */
    public function setUserData(array $userData): void {
        $this->userData = $userData;
        $this->initialized = true;
    }

    /**
     * Get a user configuration value
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed
     */
    public function get(string $key, $default = null) {
        if (!$this->initialized) {
            $this->initFromGlobal();
        }
        return $this->userData[$key] ?? $default;
    }

    /**
     * Set a user configuration value
     *
     * @param string $key Configuration key
     * @param mixed $value Value to set
     * @return void
     */
    public function set(string $key, $value): void {
        if (!$this->initialized) {
            $this->initFromGlobal();
        }
        $this->userData[$key] = $value;

        // Also update global for backward compatibility
        global $user;
        if (is_array($user)) {
            $user[$key] = $value;
        }
    }

    /**
     * Check if a configuration key exists
     *
     * @param string $key Configuration key
     * @return bool
     */
    public function has(string $key): bool {
        if (!$this->initialized) {
            $this->initFromGlobal();
        }
        return isset($this->userData[$key]);
    }

    /**
     * Get all user data
     *
     * @return array
     */
    public function getAll(): array {
        if (!$this->initialized) {
            $this->initFromGlobal();
        }
        return $this->userData;
    }

    /**
     * Common getters for frequently used properties
     */

    public function getPublicKey(): ?string {
        return $this->get('myPublicKey');
    }

    public function getPrivateKey(): ?string {
        return $this->get('myPrivateKey');
    }

    public function getTorAddress(): ?string {
        return $this->get('myTorAddress');
    }

    public function isDebugMode(): bool {
        return (bool) $this->get('debug', false);
    }

    public function getDefaultFee(): float {
        return (float) $this->get('myDefaultFee', 0);
    }

    public function getCurrency(): string {
        return $this->get('myCurrency', 'USD');
    }

    /**
     * Check if user context is properly initialized
     *
     * @return bool
     */
    public function isInitialized(): bool {
        return $this->initialized && !empty($this->userData);
    }

    /**
     * Clear user context (for testing or logout)
     *
     * @return void
     */
    public function clear(): void {
        $this->userData = [];
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