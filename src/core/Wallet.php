<?php

class Wallet{
    /**
     * Generate wallet
     *
     * @param array $argv Command line arguments
     * @return void
     */
    public static function generateWallet(array $argv): void {
        // Add default user values in defaultconfig.php
        $defaultConfig = "<?php\n";
        $defaultConfig .= "\$user['defaultFee'] = 0.1;\n";  // Default transaction fee in percent
        $defaultConfig .= "\$user['defaultCurrency'] = 'USD';\n"; // Default currency
        $defaultConfig .= "\$user['localhostOnly'] = true;\n";  // Network connection limited to localhost only or not
        $defaultConfig .= "\$user['maxFee'] = 5;\n"; // Maximum total fee for a transaction in percent
        $defaultConfig .= "\$user['maxP2pLevel'] = 6;\n"; // Default maximum level for Peer to Peer propagation
        $defaultConfig .= "\$user['p2pExpiration'] = 300;\n";  // Default expiration time for Peer to Peer requests in seconds
        $defaultConfig .= "\$user['debug'] = true;\n";  // Enable debug mode
        $defaultConfig .= "\$user['maxOutput'] = 5;\n";  // Maximum lines of output for multi-line output
        file_put_contents('/etc/eiou/defaultconfig.php', $defaultConfig, LOCK_EX);

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
        
        // Save the keys to userconfig.php
        file_put_contents('/etc/eiou/userconfig.php', "<?php\n" . '$user["public"]="' . addslashes($publicKey) . '";' . "\n". '$user["private"]="' . addslashes($privateKey) . '";' . "\n" . '$user["authcode"]="' . addslashes($authCode) . '";' . "\n", FILE_APPEND | LOCK_EX);

        // Output Tor address
        $torAddress = trim(file_get_contents('/var/lib/tor/hidden_service/hostname'));   

        // Append the Tor address to the config file
        file_put_contents('/etc/eiou/userconfig.php', "\n" . '$user["torAddress"]="' . addslashes($torAddress) . '";' . "\n", FILE_APPEND | LOCK_EX);

        // Check if torAddressOnly flag is set
        if (isset($argv[2]) && strtolower($argv[2]) === 'toraddressonly') {
            echo $torAddress . "\n";
            return;
        }
        // Else argv2 is the (http/s) hostname of the container
        elseif (isset($argv[2])) {
            if (filter_var($argv[2], FILTER_VALIDATE_URL)) {
                // Save the hostname to the configuration
                $config_content = file_get_contents('/etc/eiou/userconfig.php');
                $config_content .= "\n" . '$user["hostname"]="' . addslashes($argv[2]) . '";' . "\n";
                file_put_contents('/etc/eiou/userconfig.php', $config_content, LOCK_EX);
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