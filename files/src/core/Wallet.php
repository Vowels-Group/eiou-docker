<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

require_once __DIR__ . '/../cli/CliOutputManager.php';
require_once __DIR__ . '/ErrorCodes.php';
require_once __DIR__ . '/../utils/SecureSeedphraseDisplay.php';

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

        // Check for restore-file command (reads seedphrase from file for security)
        if (isset($argv[2]) && strtolower($argv[2]) === 'restore-file') {
            self::restoreWalletFromFile($argv, $output);
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
        chown('/etc/eiou/defaultconfig.json', 'www-data');
        chmod('/etc/eiou/defaultconfig.json', 0600);

        // Generate BIP39 mnemonic seed phrase (24 words)
        $mnemonic = BIP39::generateMnemonic(24);

        // Derive deterministic EC key pair from seed using secp256k1
        // This allows wallet restoration to generate the EXACT same key pair
        $seed = BIP39::mnemonicToSeed($mnemonic);
        $keyPair = BIP39::seedToKeyPair($seed);
        $privateKey = $keyPair['private'];
        $publicKey = $keyPair['public'];

        // Derive deterministic Tor hidden service keys from seed
        // This ensures the same .onion address is restored from the seed phrase
        require_once __DIR__ . '/../security/TorKeyDerivation.php';
        $torAddress = TorKeyDerivation::generateHiddenServiceFiles($seed);

        // Derive deterministic authentication code from seed
        // This ensures the same authcode is restored from the seed phrase
        $authCode = BIP39::seedToAuthCode($seed);

        // Clear seed from memory
        BIP39::secureClear($seed);

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
                $output->error("Invalid hostname format. Must be a valid URL.", ErrorCodes::INVALID_HOSTNAME, 400, [
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
                ErrorCodes::INVALID_SEED_PHRASE,
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
                ErrorCodes::INVALID_WORD_COUNT,
                400,
                ['expected' => '24', 'provided' => $wordCount]
            );
            return;
        }

        // Validate the mnemonic (checksum verification)
        if (!BIP39::validateMnemonic($mnemonic)) {
            $output->error(
                "Invalid seed phrase. Checksum verification failed.",
                ErrorCodes::INVALID_CHECKSUM,
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
            'defaultCreditLimit' => Constants::CONTACT_DEFAULT_CREDIT_LIMIT,
            'maxP2pLevel' => Constants::P2P_DEFAULT_MAX_REQUEST_LEVEL,
            'p2pExpiration' => Constants::P2P_DEFAULT_EXPIRATION_SECONDS,
            'maxOutput' => Constants::DISPLAY_DEFAULT_OUTPUT_LINES_MAX,
            'defaultTransportMode' => Constants::DEFAULT_TRANSPORT_MODE
        ]);
        file_put_contents('/etc/eiou/defaultconfig.json', $defaultConfig, LOCK_EX);
        chown('/etc/eiou/defaultconfig.json', 'www-data');
        chmod('/etc/eiou/defaultconfig.json', 0600);

        // Derive deterministic EC key pair from seed using secp256k1
        // This generates the EXACT same key pair as the original wallet
        $seed = BIP39::mnemonicToSeed($mnemonic);
        $keyPair = BIP39::seedToKeyPair($seed);
        $privateKey = $keyPair['private'];
        $publicKey = $keyPair['public'];

        // Derive deterministic Tor hidden service keys from seed
        // This restores the SAME .onion address as the original wallet
        require_once __DIR__ . '/../security/TorKeyDerivation.php';
        $torAddress = TorKeyDerivation::generateHiddenServiceFiles($seed);

        // Derive deterministic authentication code from seed
        // This restores the SAME authcode as the original wallet
        $authCode = BIP39::seedToAuthCode($seed);

        // Clear seed from memory
        BIP39::secureClear($seed);

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
     * Restore wallet from seed phrase stored in a file
     *
     * SECURITY: This method reads the seedphrase from a file instead of
     * command line arguments. This prevents the seedphrase from appearing
     * in process listings (ps aux) and shell history.
     *
     * Usage: eiou generate restore-file /path/to/seedphrase/file
     *
     * @param array $argv Command line arguments
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public static function restoreWalletFromFile(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Get file path from arguments
        if (!isset($argv[3])) {
            $output->error(
                "File path required. Usage: eiou generate restore-file <filepath>",
                ErrorCodes::INVALID_ARGUMENT,
                400,
                ['hint' => 'Provide the path to a file containing the 24-word seed phrase']
            );
            return;
        }

        $filepath = $argv[3];

        // Validate file exists and is readable
        if (!file_exists($filepath)) {
            $output->error(
                "Seed phrase file not found: $filepath",
                ErrorCodes::FILE_NOT_FOUND,
                404
            );
            return;
        }

        if (!is_readable($filepath)) {
            $output->error(
                "Cannot read seed phrase file: $filepath",
                ErrorCodes::FILE_NOT_READABLE,
                403
            );
            return;
        }

        // Read seedphrase from file
        $mnemonic = trim(file_get_contents($filepath));

        if (empty($mnemonic)) {
            $output->error(
                "Seed phrase file is empty",
                ErrorCodes::INVALID_SEED_PHRASE,
                400
            );
            return;
        }

        // Build argv array for restoreWallet with the seedphrase words
        $words = preg_split('/\s+/', $mnemonic);
        $restoreArgv = array_merge(
            [$argv[0], 'generate', 'restore'],
            $words
        );

        // Call the standard restore method
        self::restoreWallet($restoreArgv, $output);
    }

    /**
     * Display seed phrase to user securely
     *
     * SECURITY: This method uses SecureSeedphraseDisplay to prevent the
     * seedphrase from being captured in Docker logs. Two methods are used:
     *
     * 1. TTY mode (preferred): Writes directly to /dev/tty which bypasses
     *    Docker's stdout/stderr capture entirely.
     *
     * 2. Secure file mode (fallback): Writes to /dev/shm (memory-only tmpfs)
     *    with automatic deletion. Instructions are logged but NOT the phrase.
     *
     * @param string $mnemonic The seed phrase
     * @param CliOutputManager $output Output manager
     * @return void
     */
    private static function displaySeedPhrase(string $mnemonic, CliOutputManager $output): void {
        // In JSON mode, we need special handling
        // DO NOT include the seedphrase in JSON output that goes to stdout
        // Instead, use the secure display mechanism
        if ($output->isJsonMode()) {
            // For JSON mode in non-interactive contexts, use secure file
            $result = SecureSeedphraseDisplay::display($mnemonic, false);

            if ($result['method'] === 'file' && isset($result['instructions'])) {
                // Output instructions as a separate info line to stderr to avoid log capture
                // The actual seedphrase is in the secure file, not in the JSON response
                $instructionText = implode("\n", $result['instructions']);
                fwrite(STDERR, "\n" . $instructionText . "\n\n");
            }
            return;
        }

        // Text mode: use secure display
        $result = SecureSeedphraseDisplay::display($mnemonic, true);

        // If file method was used, output the retrieval instructions
        // (these are safe to log as they don't contain the seedphrase)
        if ($result['method'] === 'file') {
            if (isset($result['warning'])) {
                echo "\nWARNING: " . $result['warning'] . "\n";
            }

            echo "\n";
            echo "════════════════════════════════════════════════════════════════\n";
            echo " SEEDPHRASE STORED SECURELY\n";
            echo "════════════════════════════════════════════════════════════════\n";

            if (isset($result['instructions'])) {
                foreach ($result['instructions'] as $line) {
                    echo " $line\n";
                }
            }

            echo "════════════════════════════════════════════════════════════════\n";
            echo "\n";
        }
        // If TTY method was used, the display is already complete
    }
}