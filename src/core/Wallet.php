<?php

class Wallet{
    /**
     * Generate wallet
     *
     * @param array $argv Command line arguments
     * @return void
     */
    public static function generateWallet(array $argv): void {
        // Add default user values in defaultconfig.json
        $defaultConfig = json_encode([
            'defaultFee' => 0.1,            // Default transaction fee in percent
            'defaultCurrency' => 'USD',     // Default currency
            'localhostOnly' => true,        // Network connection limited to localhost only or not
            'maxFee' => 5,                  // Maximum total fee for a transaction in percent
            'maxP2pLevel' => 6,             // Default maximum level for Peer to Peer propagation
            'p2pExpiration' => Constants::P2P_DEFAULT_EXPIRATION_SECONDS,         // Default expiration time for Peer to Peer requests in seconds
            'debug' => true,                // Enable debug mode
            'maxOutput' => 5                // Maximum lines of output for multi-line output
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

        $userconfig = [
            'public' => addslashes($publicKey),     // Public key
            'private' => addslashes($privateKey),   // Private key
            'authcode' => addslashes($authCode),    // Auth code
            'torAddress' => addslashes($torAddress) // Tor address
        ];

        // Check if torAddressOnly flag is set
        if (isset($argv[2]) && strtolower($argv[2]) === 'toraddressonly') {
            echo $torAddress . "\n";
            // Save the keys to userconfig.json
            file_put_contents('/etc/eiou/userconfig.json', json_encode($userconfig), LOCK_EX);
            return;
        }
        // Else argv2 is the (http/s) hostname of the container
        elseif (isset($argv[2])) {
            if (filter_var($argv[2], FILTER_VALIDATE_URL)) {
                // Save the hostname to the configuration
                $userconfig['hostname'] = addslashes($argv[2]); // HTTP address
                file_put_contents('/etc/eiou/userconfig.json', json_encode($userconfig), LOCK_EX);
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
}