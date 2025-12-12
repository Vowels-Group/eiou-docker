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

        // Check for restore command
        if (isset($argv[2]) && strtolower($argv[2]) === 'restore') {
            self::restoreWallet($argv, $output);
            return;
        }

        // Load KeyEncryption for secure key storage
        require_once __DIR__ . '/../security/KeyEncryption.php';
        require_once __DIR__ . '/../security/BIP39.php';

        // Add default user values in defaultconfig.json
        $defaultConfig = json_encode([
            'defaultCurrency' => Constants::TRANSACTION_DEFAULT_CURRENCY,           // Default currency
            'minFee' => Constants::TRANSACTION_MINIMUM_FEE,                         // Mimum transaction fee amount
            'defaultFee' => Constants::CONTACT_DEFAULT_FEE_PERCENT,                 // Default transaction fee in percent
            'maxFee' => Constants::CONTACT_DEFAULT_FEE_PERCENT_MAX,                 // Maximum total fee for a transaction in percent
            'defaultCreditLimit' => Constants::CONTACT_DEFAULT_CREDIT_LIMIT,        // Default credit limit
            'maxP2pLevel' => Constants::P2P_DEFAULT_MAX_REQUEST_LEVEL,              // Default maximum level for Peer to Peer propagation
            'p2pExpiration' => Constants::P2P_DEFAULT_EXPIRATION_SECONDS,           // Default expiration time for Peer to Peer requests in seconds
            'maxOutput' => Constants::DISPLAY_DEFAULT_OUTPUT_LINES_MAX,             // Maximum lines of output for multi-line output
            'defaultTransportMode' => Constants::DEFAULT_TRANSPORT_MODE             // Default way to send messages (fallback in case uncertain)
        ]);
        file_put_contents('/etc/eiou/defaultconfig.json', $defaultConfig, LOCK_EX);

        // Generate BIP39 mnemonic seed phrase (24 words)
        $mnemonic = BIP39::generateMnemonic(24);

        // Derive deterministic EC key pair from seed using secp256k1
        // This allows wallet restoration to generate the EXACT same key pair
        $seed = BIP39::mnemonicToSeed($mnemonic);
        $keyPair = BIP39::seedToKeyPair($seed);
        $privateKey = $keyPair['private'];
        $publicKey = $keyPair['public'];

        // Clear seed from memory
        BIP39::secureClear($seed);

        // Generate random authentication code of length 20
        $authCode = bin2hex(random_bytes(10));

        // Output Tor address
        $torAddress = trim(file_get_contents('/var/lib/tor/hidden_service/hostname'));

        // SECURITY: Encrypt private key, auth code, and mnemonic before storage
        $encryptedPrivateKey = KeyEncryption::encrypt($privateKey);
        $encryptedAuthCode = KeyEncryption::encrypt($authCode);
        $encryptedMnemonic = KeyEncryption::encrypt($mnemonic);

        // Clear plaintext private key from memory
        KeyEncryption::secureClear($privateKey);

        $userconfig = [
            'public' => addslashes($publicKey),           // Public key (not sensitive, can be shared)
            'private_encrypted' => $encryptedPrivateKey,  // ENCRYPTED private key
            'authcode_encrypted' => $encryptedAuthCode,   // ENCRYPTED auth code
            'mnemonic_encrypted' => $encryptedMnemonic,   // ENCRYPTED seed phrase for recovery
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

        // Display seed phrase prominently with warning BEFORE the success message
        self::displaySeedPhrase($mnemonic, $output);

        $output->success("Wallet generated successfully", $walletData, "New wallet created with secure key storage");

        // SECURITY: Clear plaintext mnemonic from memory after output
        KeyEncryption::secureClear($mnemonic);
    }

    /**
     * Restore wallet from BIP39 seed phrase
     *
     * @param array $argv Command line arguments
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public static function restoreWallet(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Load required security components
        require_once __DIR__ . '/../security/KeyEncryption.php';
        require_once __DIR__ . '/../security/BIP39.php';

        // Get seed phrase from arguments (words 3 onwards)
        // Usage: eiou generate restore word1 word2 word3 ... word24
        if (count($argv) < 27) { // eiou generate restore + 24 words
            $output->error(
                "Seed phrase required. Usage: eiou generate restore <24 words>",
                'INVALID_SEED_PHRASE',
                400,
                ['expected_words' => '24', 'provided' => count($argv) - 3]
            );
            return;
        }

        // Extract seed phrase words (everything after "restore")
        $seedWords = array_slice($argv, 3);
        $mnemonic = implode(' ', $seedWords);

        // Validate word count
        $wordCount = count($seedWords);
        if ($wordCount !== 24) {
            $output->error(
                "Invalid seed phrase length. Must be 24 words.",
                'INVALID_WORD_COUNT',
                400,
                ['expected' => '24', 'provided' => $wordCount]
            );
            return;
        }

        // Validate the mnemonic (checksum verification)
        if (!BIP39::validateMnemonic($mnemonic)) {
            $output->error(
                "Invalid seed phrase. Checksum verification failed.",
                'INVALID_CHECKSUM',
                400,
                ['hint' => 'Check that all words are correct and in the right order']
            );
            // Clear invalid mnemonic from memory
            KeyEncryption::secureClear($mnemonic);
            return;
        }

        // Check if wallet already exists
        if (file_exists('/etc/eiou/userconfig.json')) {
            $output->error(
                "Wallet already exists. Delete existing wallet first to restore.",
                'WALLET_EXISTS',
                409
            );
            KeyEncryption::secureClear($mnemonic);
            return;
        }

        // Add default user values in defaultconfig.json
        $defaultConfig = json_encode([
            'defaultCurrency' => Constants::TRANSACTION_DEFAULT_CURRENCY,
            'minFee' => Constants::TRANSACTION_MINIMUM_FEE,
            'defaultFee' => Constants::CONTACT_DEFAULT_FEE_PERCENT,
            'maxFee' => Constants::CONTACT_DEFAULT_FEE_PERCENT_MAX,
            'maxP2pLevel' => Constants::P2P_DEFAULT_MAX_REQUEST_LEVEL,
            'p2pExpiration' => Constants::P2P_DEFAULT_EXPIRATION_SECONDS,
            'maxOutput' => Constants::DISPLAY_DEFAULT_OUTPUT_LINES_MAX,
            'defaultTransportMode' => Constants::DEFAULT_TRANSPORT_MODE
        ]);
        file_put_contents('/etc/eiou/defaultconfig.json', $defaultConfig, LOCK_EX);

        // Derive deterministic EC key pair from seed using secp256k1
        // This generates the EXACT same key pair as the original wallet
        $seed = BIP39::mnemonicToSeed($mnemonic);
        $keyPair = BIP39::seedToKeyPair($seed);
        $privateKey = $keyPair['private'];
        $publicKey = $keyPair['public'];

        // Clear seed from memory
        BIP39::secureClear($seed);

        // Generate random authentication code of length 20
        $authCode = bin2hex(random_bytes(10));

        // Get Tor address
        $torAddress = trim(file_get_contents('/var/lib/tor/hidden_service/hostname'));

        // SECURITY: Encrypt private key, auth code, and mnemonic before storage
        $encryptedPrivateKey = KeyEncryption::encrypt($privateKey);
        $encryptedAuthCode = KeyEncryption::encrypt($authCode);
        $encryptedMnemonic = KeyEncryption::encrypt($mnemonic);

        // Clear plaintext private key from memory
        KeyEncryption::secureClear($privateKey);

        $userconfig = [
            'public' => addslashes($publicKey),
            'private_encrypted' => $encryptedPrivateKey,
            'authcode_encrypted' => $encryptedAuthCode,
            'mnemonic_encrypted' => $encryptedMnemonic,
            'torAddress' => addslashes($torAddress),
            'restored_from_seed' => true,
            'restored_at' => date('c')
        ];

        // Set strict file permissions before saving
        $oldUmask = umask(0077);
        file_put_contents('/etc/eiou/userconfig.json', json_encode($userconfig), LOCK_EX);
        umask($oldUmask);

        chown('/etc/eiou/userconfig.json', 'www-data');
        chmod('/etc/eiou/userconfig.json', 0600);

        $walletData = [
            'tor_address' => $torAddress,
            'public_key_generated' => true,
            'restored_from_seed' => true,
            'word_count' => $wordCount
        ];

        $output->success("Wallet restored successfully from seed phrase", $walletData, "Wallet restored with deterministic EC keys derived from seed phrase.");

        // SECURITY: Clear plaintext mnemonic from memory
        KeyEncryption::secureClear($mnemonic);
    }

    /**
     * Display seed phrase to user with formatting
     *
     * @param string $mnemonic The seed phrase
     * @param CliOutputManager $output Output manager
     * @return void
     */
    private static function displaySeedPhrase(string $mnemonic, CliOutputManager $output): void {
        // Table uses 65 characters total width:
        // ║ (1) + space (1) + content (61) + space (1) + ║ (1) = 65
        $contentWidth = 61;
        $formatted = BIP39::formatMnemonic($mnemonic, $contentWidth);

        // In JSON mode, the seed phrase is already in walletData
        // In text mode, we display it formatted
        if (!$output->isJsonMode()) {
            echo "\n";
            echo "╔═══════════════════════════════════════════════════════════════╗\n";
            echo "║ IMPORTANT: WRITE DOWN YOUR SEED PHRASE AND STORE SAFELY       ║\n";
            echo "╠═══════════════════════════════════════════════════════════════╣\n";
            echo "║ This is the ONLY way to restore your wallet if lost.          ║\n";
            echo "║ Never share it. Never store it digitally.                     ║\n";
            echo "╠═══════════════════════════════════════════════════════════════╣\n";

            $lines = explode("\n", $formatted);
            foreach ($lines as $line) {
                // Ensure each line is exactly contentWidth characters
                $padded = str_pad($line, $contentWidth);
                echo "║ " . $padded . " ║\n";
            }

            echo "╚═══════════════════════════════════════════════════════════════╝\n";
            echo "\n";
        }
    }
}