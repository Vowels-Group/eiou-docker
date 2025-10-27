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
        $this->initFromGlobal();
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
     * Initialize user context from global $user variable
     *
     * @return void
     */
    private function initFromGlobal(): void {
        if(!$this->initialized ){
            // Parse in default config values
            $this->parser('/etc/eiou/defaultconfig.php',"/=/","/\]/");
            // Parse in user config information
            $this->parser('/etc/eiou/userconfig.php',"/\]=\"/","/\[/");
            $this->initialized = true;
        }
    }

    /**
     * Parse in configuration from files
     *
     * @param string $filepath Path to config file
     * @param string $splitregex String regex for second split
     * @param string $lastreplace String regex for last replacement of key
     * @return void
     */
    public function parser($filepath, $splitregex, $lastreplace){
        if (file_exists($filepath)) {
            $config_content = file_get_contents($filepath);
            $config_content = preg_replace("/\<\?php/","",$config_content);
            $values = preg_split("/;/",$config_content);
            for ($x = 0; $x < count($values); $x++) {
                $keyvals = preg_split($splitregex,$values[$x]);
                $key = trim($keyvals[0]);
                if ($key === ""){
                    continue;
                }
                $key = preg_replace("/\\$/","",$key);
                $key = preg_replace("/user/","",$key);
                $key = preg_replace("/[\"\']/","",$key);
                $key = preg_replace("/\[/","",$key);
                $key = trim(preg_replace($lastreplace,"",$key));
                if($key === 'public' || $key === 'private'){
                    $value = preg_replace("/[\"\']/","",trim($keyvals[1]));
                } else{
                    $value = trim(preg_replace("/[\"\']/","",$keyvals[1]));
                }
                if(isset($key) && trim($key) !== ""){
                    $this->set($key, $value);
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