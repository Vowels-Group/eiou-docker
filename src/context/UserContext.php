<?php
# Copyright 2025

namespace EIOU\Context;

/**
 * UserContext Class
 *
 * Encapsulates user configuration and credentials, replacing the global $user array.
 * This class provides a clean, object-oriented interface for accessing user data
 * throughout the application.
 *
 * @package EIOU\Context
 */
class UserContext {
    /**
     * @var array User data storage
     */
    private array $userData = [];

    /**
     * Constructor
     *
     * @param array $userData Initial user data (typically from config or global $user)
     */
    public function __construct(array $userData = []) {
        $this->userData = $userData;
    }

    /**
     * Create UserContext from global $user variable
     * This is a bridge method to help with migration
     *
     * @return UserContext
     */
    public static function fromGlobal(): UserContext {
        global $user;
        return new self($user ?? []);
    }

    /**
     * Get public key
     *
     * @return string|null
     */
    public function getPublicKey(): ?string {
        return $this->userData['public'] ?? null;
    }

    /**
     * Get private key
     *
     * @return string|null
     */
    public function getPrivateKey(): ?string {
        return $this->userData['private'] ?? null;
    }

    /**
     * Get hostname
     *
     * @return string|null
     */
    public function getHostname(): ?string {
        return $this->userData['hostname'] ?? null;
    }

    /**
     * Get Tor address
     *
     * @return string|null
     */
    public function getTorAddress(): ?string {
        return $this->userData['torAddress'] ?? null;
    }

    /**
     * Get all user addresses (hostname and tor)
     *
     * @return array
     */
    public function getUserAddresses(): array {
        $addresses = [];
        if ($hostname = $this->getHostname()) {
            $addresses[] = $hostname;
        }
        if ($torAddress = $this->getTorAddress()) {
            $addresses[] = $torAddress;
        }
        return $addresses;
    }

    /**
     * Check if an address belongs to this user
     *
     * @param string $address
     * @return bool
     */
    public function isMyAddress(string $address): bool {
        return in_array($address, $this->getUserAddresses(), true);
    }

    /**
     * Get default fee percentage
     *
     * @return float
     */
    public function getDefaultFee(): float {
        return (float) ($this->userData['defaultFee'] ?? 0.1);
    }

    /**
     * Get default currency
     *
     * @return string
     */
    public function getDefaultCurrency(): string {
        return $this->userData['defaultCurrency'] ?? 'USD';
    }

    /**
     * Check if localhost only mode is enabled
     *
     * @return bool
     */
    public function isLocalhostOnly(): bool {
        return (bool) ($this->userData['localhostOnly'] ?? true);
    }

    /**
     * Get maximum fee percentage
     *
     * @return float
     */
    public function getMaxFee(): float {
        return (float) ($this->userData['maxFee'] ?? 5.0);
    }

    /**
     * Get maximum P2P level
     *
     * @return int
     */
    public function getMaxP2pLevel(): int {
        return (int) ($this->userData['maxP2pLevel'] ?? 6);
    }

    /**
     * Get P2P expiration time in seconds
     *
     * @return int
     */
    public function getP2pExpiration(): int {
        return (int) ($this->userData['p2pExpiration'] ?? 300);
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function isDebugMode(): bool {
        return (bool) ($this->userData['debug'] ?? false);
    }

    /**
     * Get maximum output lines
     *
     * @return int
     */
    public function getMaxOutput(): int {
        return (int) ($this->userData['maxOutput'] ?? 5);
    }

    /**
     * Get database host
     *
     * @return string|null
     */
    public function getDbHost(): ?string {
        return $this->userData['dbHost'] ?? null;
    }

    /**
     * Get database name
     *
     * @return string|null
     */
    public function getDbName(): ?string {
        return $this->userData['dbName'] ?? null;
    }

    /**
     * Get database user
     *
     * @return string|null
     */
    public function getDbUser(): ?string {
        return $this->userData['dbUser'] ?? null;
    }

    /**
     * Get database password
     *
     * @return string|null
     */
    public function getDbPass(): ?string {
        return $this->userData['dbPass'] ?? null;
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
     * Get a custom user property
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null) {
        return $this->userData[$key] ?? $default;
    }

    /**
     * Set a user property
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function set(string $key, $value): self {
        $this->userData[$key] = $value;
        return $this;
    }

    /**
     * Check if a property exists
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool {
        return isset($this->userData[$key]);
    }

    /**
     * Get all user data as array
     *
     * @return array
     */
    public function toArray(): array {
        return $this->userData;
    }

    /**
     * Update the global $user variable with current state
     * This is a bridge method to help with gradual migration
     *
     * @return void
     */
    public function updateGlobal(): void {
        global $user;
        $user = $this->userData;
    }

    /**
     * Create a clone with modified data
     * Useful for testing or temporary modifications
     *
     * @param array $overrides
     * @return UserContext
     */
    public function withOverrides(array $overrides): UserContext {
        return new self(array_merge($this->userData, $overrides));
    }
}