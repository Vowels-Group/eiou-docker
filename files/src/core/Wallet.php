<?php

require_once __DIR__ . '/../cli/CliOutputManager.php';

class Wallet{
    /**
     * Generate wallet
     *
     * @param array $argv Command line arguments
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public static function generateWallet(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Load KeyEncryption for secure key storage
        require_once __DIR__ . '/../security/KeyEncryption.php';

        // Add default user values in defaultconfig.json
        $defaultConfig = json_encode([
            'defaultCurrency' => Constants::TRANSACTION_DEFAULT_CURRENCY,           // Default currency
            'minFee' => Constants::TRANSACTION_MINIMUM_FEE,                         // Mimum transaction fee amount
            'defaultFee' => Constants::CONTACT_DEFAULT_FEE_PERCENT,                 // Default transaction fee in percent
            'maxFee' => Constants::CONTACT_DEFAULT_FEE_PERCENT_MAX,                 // Maximum total fee for a transaction in percent
            'maxP2pLevel' => Constants::P2P_DEFAULT_MAX_REQUEST_LEVEL,              // Default maximum level for Peer to Peer propagation
            'p2pExpiration' => Constants::P2P_DEFAULT_EXPIRATION_SECONDS,           // Default expiration time for Peer to Peer requests in seconds
            'maxOutput' => Constants::DISPLAY_DEFAULT_OUTPUT_LINES_MAX,             // Maximum lines of output for multi-line output
            'localhostOnly' => Constants::LOCAL_HOST_ONLY,                          // Network connection limited to localhost only or not
            'defaultTransportMode' => Constants::DEFAULT_TRANSPORT_MODE             // Default way to send messages (fallback in case uncertain)
        ]);
        file_put_contents('/etc/eiou/defaultconfig.json', $defaultConfig, LOCK_EX);

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

        // Output Tor address
        $torAddress = trim(file_get_contents('/var/lib/tor/hidden_service/hostname'));

        // SECURITY: Encrypt private key and auth code before storage
        $encryptedPrivateKey = KeyEncryption::encrypt($privateKey);
        $encryptedAuthCode = KeyEncryption::encrypt($authCode);

        // Clear plaintext private key from memory
        KeyEncryption::secureClear($privateKey);

        $userconfig = [
            'public' => addslashes($publicKey),           // Public key (not sensitive, can be shared)
            'private_encrypted' => $encryptedPrivateKey,  // ENCRYPTED private key
            'authcode_encrypted' => $encryptedAuthCode,   // ENCRYPTED auth code
            'torAddress' => addslashes($torAddress)       // Tor address
        ];

        $walletData = [
            'tor_address' => $torAddress,
            'public_key_generated' => true
        ];

        // If argv2 is the (http/s) hostname of the container
        if (isset($argv[2])) {
            if (filter_var($argv[2], FILTER_VALIDATE_URL)) {
                // Save the hostname to the configuration
                $userconfig['hostname'] = addslashes($argv[2]); // HTTP address
                $walletData['http_address'] = $argv[2];
            } else {
                $output->error("Invalid hostname format. Must be a valid URL.", 'INVALID_HOSTNAME', 400, [
                    'provided' => $argv[2]
                ]);
                return;
            }
        }

        // Set strict file permissions before saving
        $oldUmask = umask(0077); // Ensure 600 permissions
        file_put_contents('/etc/eiou/userconfig.json', json_encode($userconfig), LOCK_EX);
        umask($oldUmask);

        chown('/etc/eiou/userconfig.json','www-data');

        // Verify and set file permissions
        chmod('/etc/eiou/userconfig.json', 0600);

        $output->success("Wallet generated successfully", $walletData, "New wallet created with secure key storage");
    }
}