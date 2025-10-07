<?php
# Copyright 2025

/**
 * Wallet Service
 *
 * Handles all business logic for wallet management.
 * Replaces procedural functions from src/functions/wallet.php
 *
 * @package Services
 */
class WalletService {
    /**
     * @var array Current user data
     */
    private array $currentUser;

    /**
     * Constructor
     *
     * @param array $currentUser Current user data
     */
    public function __construct(array $currentUser = []) {
        $this->currentUser = $currentUser;
    }

    /**
     * Check if wallet exists
     *
     * @param string $request Request type
     * @return void
     */
    public function checkWalletExists(string $request): void {
        // Check if wallet exists
        if ((!isset($this->currentUser['public']) || !isset($this->currentUser['private'])) && $request != 'generate' && $request != 'restore') {
            echo returnNoWalletExists();
            exit();
        }
    }

    /**
     * Generate wallet
     *
     * @param array $argv Command line arguments
     * @return void
     */
    public function generateWallet(array $argv): void {
        // If config (wallet) exists query user about overwriting
        // On restart of container keeps from appending new values
        if(file_exists("/etc/eiou/config.php") && isset($this->currentUser["public"])){
            echo returnUserInputRequestOverwritingWallet();
            $decision = trim(fgets(STDIN));
            if(strtolower($decision) !== 'y'){
                echo returnOverwritingExistingWalletCancelled();
                exit(0);
            } else{
                // Note: new values are appended, old still exist in config.php. But new ones are used due to way php reads in
                echo returnOverwritingExistingWallet();
            }
        }

        // Generate a private key
        $config = array(
            "private_key_bits" => 2048,
            "curve_name" => "secp256k1"
        );
        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privateKey);

        // Extract public key from the private key
        $keyDetails = openssl_pkey_get_details($res);
        $publicKey = $keyDetails['key'];

        // Generate random authentication code of length 20
        $authCode = bin2hex(random_bytes(10));

        // Save the keys to config.php
        file_put_contents('/etc/eiou/config.php', "\n" . '$user["public"]="' . addslashes($publicKey) . '";' . "\n". '$user["private"]="' . addslashes($privateKey) . '";' . "\n" . '$user["authcode"]="' . addslashes($authCode) . '";' . "\n", FILE_APPEND | LOCK_EX);

        // Output Tor address
        $torAddress = trim(file_get_contents('/var/lib/tor/hidden_service/hostname'));

        // Check if torAddressOnly flag is set
        if (isset($argv[2]) && strtolower($argv[2]) === 'toraddressonly') {
            echo $torAddress . "\n";
            return;
        }
        // Else argv2 is the (http/s) hostname of the container
        elseif (isset($argv[2])) {
            if (filter_var($argv[2], FILTER_VALIDATE_URL)) {
                // Save the hostname to the configuration
                $config_content = file_get_contents('/etc/eiou/config.php');
                $config_content .= "\n" . '$user["hostname"]="' . addslashes($argv[2]) . '";' . "\n";
                file_put_contents('/etc/eiou/config.php', $config_content, LOCK_EX);
                echo returnHostnameSaved($argv[2]);
            } else {
                echo returnInvalidHostnameFormat();
                exit(1);
            }
            return;
        }

        // Only display if generate is called without arguments (eiou generate)
        echo "Public key: $publicKey\n";
        echo "Private key: $privateKey\n";
        echo "Authentication Code: $authCode\n";
        echo "Tor Address: $torAddress\n";
        echo "Please save these keys securely, or write the name of a file to output to (leave blank for none): \n";
        $privateKeyFile = trim(fgets(STDIN));
        if (!empty($privateKeyFile)) {
            // Save the private key to the specified file
            file_put_contents($privateKeyFile, $privateKey);
            echo "Private key saved to $privateKeyFile\n";
        }
    }

    /**
     * Get public key
     *
     * @return string|null Public key or null
     */
    public function getPublicKey(): ?string {
        return $this->currentUser['public'] ?? null;
    }

    /**
     * Get private key
     *
     * @return string|null Private key or null
     */
    public function getPrivateKey(): ?string {
        return $this->currentUser['private'] ?? null;
    }

    /**
     * Get authentication code
     *
     * @return string|null Auth code or null
     */
    public function getAuthCode(): ?string {
        return $this->currentUser['authcode'] ?? null;
    }

    /**
     * Get Tor address
     *
     * @return string|null Tor address or null
     */
    public function getTorAddress(): ?string {
        return $this->currentUser['torAddress'] ?? null;
    }

    /**
     * Get hostname
     *
     * @return string|null Hostname or null
     */
    public function getHostname(): ?string {
        return $this->currentUser['hostname'] ?? null;
    }

    /**
     * Check if wallet has keys
     *
     * @return bool True if wallet has both public and private keys
     */
    public function hasKeys(): bool {
        return isset($this->currentUser['public']) && isset($this->currentUser['private']);
    }

    /**
     * Validate wallet configuration
     *
     * @return array Validation result with status and errors
     */
    public function validateWallet(): array {
        $errors = [];

        if (!isset($this->currentUser['public'])) {
            $errors[] = 'Public key is missing';
        }

        if (!isset($this->currentUser['private'])) {
            $errors[] = 'Private key is missing';
        }

        if (!isset($this->currentUser['authcode'])) {
            $errors[] = 'Authentication code is missing';
        }

        if (!isset($this->currentUser['torAddress']) && !isset($this->currentUser['hostname'])) {
            $errors[] = 'No network address configured (Tor or HTTP)';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
