<?php
/**
 * UserContext - Singleton wrapper for user configuration
 *
 * This class provides a centralized, type-safe way to access user configuration
 *
 */

class UserContext {
    private static ?UserContext $instance = null;
    private array $userData = [];
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
     * @return UserContext
     */
    public static function getInstance(): UserContext {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load configuration from config files
     *
     * @return void
     */
    private function loadConfigFromFiles(): void {
        if(!$this->initialized ){
            // Load in default config values
            if (file_exists('/etc/eiou/defaultconfig.json')){
                $this->userData = json_decode(file_get_contents('/etc/eiou/defaultconfig.json'),true);
                 // Load in user config information
                if (file_exists('/etc/eiou/userconfig.json')){
                    $this->userData = array_merge($this->userData, json_decode(file_get_contents('/etc/eiou/userconfig.json'),true));
                    $this->initialized = true;
                }
            }     
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
        $this->userData[$key] = $value;
    }

    /**
     * Check if a configuration key exists
     *
     * @param string $key Configuration key
     * @return bool
     */
    public function has(string $key): bool {
        return isset($this->userData[$key]);
    }

    /**
     * Get all user data
     *
     * @return array
     */
    public function getAll(): array {
        return $this->userData;
    }

    /**
     * Get public key
     *
     * @return string|null
     */
    public function getPublicKey(): ?string {
        return $this->get('public') ?? null;
    }

    /**
     * Get private key
     *
     * @return string|null
     */
    public function getPrivateKey(): ?string {
        return $this->get('private') ?? null;
    }

    /**
     * Check if wallet has keys
     *
     * @return bool True if wallet has both public and private keys
     */
    public function hasKeys(): bool {
        return (null!== $this->getPublicKey()) && (null!== $this->getPrivateKey());
    }

    /**
     * Get authentication code
     *
     * @return string|null Auth code or null
     */
    public function getAuthCode(): ?string {
        return $this->get('authcode') ?? null;
    }

    /**
     * Get hostname
     *
     * @return string|null
     */
    public function getHttpAddress(): ?string {
        return $this->get('hostname') ?? null;
    }

    /**
     * Get Tor address
     *
     * @return string|null
     */
    public function getTorAddress(): ?string {
        return $this->get('torAddress') ?? null;
    }

    /**
     * Validate wallet configuration
     *
     * @return array Validation result with status and errors
     */
    public function validateWallet(): array {
        $errors = [];

        if (null === $this->getPublicKey()) {
            $errors[] = 'Public key is missing';
        }

        if (null === $this->getPrivateKey()) {
            $errors[] = 'Private key is missing';
        }

        if (null === $this->getAuthCode()) {
            $errors[] = 'Authentication code is missing';
        }

        if ((null!== $this->getHttpAddress()) && (null!== $this->getTorAddress())) {
            $errors[] = 'No network address configured (Tor or HTTP)';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get all user addresses (hostname and tor)
     *
     * @return array
     */
    public function getUserAddresses(): array {
        $addresses = [];
        if ($hostname = $this->getHttpAddress()) {
            $addresses[] = $hostname;
        }
        if ($torAddress = $this->getTorAddress()) {
            $addresses[] = $torAddress;
        }
        return $addresses;
    }

    /**
     * Get all user locaters (hostname and tor)
     *
     * @return array
     */
    public function getUserLocaters(): array {
        $locaters = [];
        foreach($this->getUserAddresses() as $address){
            if ($this->isTorAddress($address)){
                $locaters['Tor'] = $address;
            } elseif ($this->isHttpAddress($address)) {
                $locaters['Http'] = $address;
            }
        }
        return $locaters;
    }

    /**
     * Determine if adress is HTTP/HTTPS
     *
     * @param string $address The address of the sender
     * @return bool True if HTTP(S) address, False otherwise
    */
    public function isHttpAddress($address): bool {
        return preg_match('/^https?:\/\//', $address) === 1;
    }

    /**
     * Determine if adress is valid HTTP or TOR
     *
     * @param string $address The address of the sender
     * @return bool True if HTTP(S)/TOR address, False otherwise
    */
    public function isAddress($address): bool {
        return ($this->isHttpAddress($address) || $this->isTorAddress($address));
    }

    /**
     * Determine if adress is TOR
     *
     * @param string $address The address of the sender
     * @return bool True if Tor address, False otherwise
    */
    public function isTorAddress($address): bool {
        return preg_match('/\.onion$/', $address) === 1;
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
        return (float) ($this->get('defaultFee') ?? 0.1);
    }

    /**
     * Get default currency
     *
     * @return string
     */
    public function getDefaultCurrency(): string {
        return $this->get('defaultCurrency') ?? 'USD';
    }

    /**
     * Check if localhost only mode is enabled
     *
     * @return bool
     */
    public function isLocalhostOnly(): bool {
        return filter_var($this->get('localhostOnly'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get maximum fee percentage
     *
     * @return float
     */
    public function getMaxFee(): float {
        return (float) ($this->get('maxFee') ?? 5.0);
    }

    /**
     * Get maximum P2P level
     *
     * @return int
     */
    public function getMaxP2pLevel(): int {
        return (int) ($this->get('maxP2pLevel') ?? 6);
    }

    /**
     * Get P2P expiration time in seconds
     *
     * @return int
     */
    public function getP2pExpirationTime(): int {
        return (int) ($this->get('p2pExpiration') ?? 300);
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function isDebugMode(): bool {
        return filter_var($this->get('debug'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get maximum output lines
     *
     * @return int
     */
    public function getMaxOutput(): int {
        return (int) ($this->get('maxOutput') ?? 5);
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