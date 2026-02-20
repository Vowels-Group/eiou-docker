<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Core;

use Eiou\Cli\CliOutputManager;
use Eiou\Utils\SecureSeedphraseDisplay;
use Eiou\Security\KeyEncryption;
use Eiou\Security\BIP39;
use Eiou\Security\TorKeyDerivation;

/**
 * Wallet management for BIP39 seed phrase generation and restoration
 *
 * This class handles cryptographic wallet operations for the EIOU system including:
 * - Generating new wallets with secure random 24-word BIP39 seed phrases
 * - Restoring wallets from existing seed phrases (via CLI arguments or file)
 * - Deriving deterministic secp256k1 keypairs from seed data
 * - Generating Tor hidden service addresses for anonymous communication
 * - Creating authentication codes for wallet access
 *
 * Key Responsibilities:
 * - Wallet generation with BIP39 mnemonic creation
 * - Wallet restoration with checksum validation
 * - Deterministic key derivation ensuring identical keypairs from same seed
 * - Tor hidden service key generation for .onion addresses
 * - Secure storage of encrypted private keys and mnemonics
 * - Configuration file management for wallet settings
 *
 * Security Considerations:
 * - Seed phrases and private keys are sensitive cryptographic material
 * - All private keys, auth codes, and mnemonics are encrypted before storage
 * - Sensitive values are cleared from memory after use via secureClear()
 * - Seed phrases are displayed via SecureSeedphraseDisplay to prevent log capture
 * - File-based restore (restore-file) prevents exposure in process listings
 * - Configuration files use strict 0600 permissions (owner read/write only)
 * - Seed phrases should only be displayed once during generation/restore
 *
 * Workflow:
 * - New wallet: generateWallet() -> BIP39::generateMnemonic() -> derives keypair
 *   -> generates Tor address -> encrypts and stores credentials
 * - Restore via CLI: restoreWallet() -> validates mnemonic checksum -> derives
 *   same keypair deterministically -> restores identical wallet
 * - Restore via file: restoreWalletFromFile() -> reads seed from file (avoids
 *   shell history exposure) -> calls restoreWallet() with extracted words
 *
 * @package EIOU\Core
 * @see BIP39 For mnemonic generation and validation
 * @see KeyEncryption For secure storage of sensitive data
 * @see TorKeyDerivation For deterministic Tor hidden service generation
 * @see SecureSeedphraseDisplay For secure seed phrase output
 */
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
            'defaultTransportMode' => Constants::DEFAULT_TRANSPORT_MODE,            // Default way to send messages (fallback in case uncertain)
            'autoRefreshEnabled' => Constants::AUTO_REFRESH_ENABLED,                // Auto-refresh for pending transactions (default: off)
            'autoBackupEnabled' => Constants::BACKUP_AUTO_ENABLED                   // Auto-backup for daily database backups (default: on)
        ]);
        file_put_contents('/etc/eiou/config/defaultconfig.json', $defaultConfig, LOCK_EX);
        chown('/etc/eiou/config/defaultconfig.json', 'www-data');
        chmod('/etc/eiou/config/defaultconfig.json', 0600);

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
                $hostname = $argv[2];

                // Derive hostname and hostname_secure from the provided URL
                // Convert http:// to https:// or normalize if already https://
                if (strpos($hostname, 'http://') === 0) {
                    $hostnameSecure = 'https://' . substr($hostname, 7);
                } elseif (strpos($hostname, 'https://') === 0) {
                    $hostnameSecure = $hostname;
                    $hostname = 'http://' . substr($hostname, 8); // hostname should be http
                } else {
                    // No protocol prefix, assume http for hostname
                    $hostnameSecure = 'https://' . $hostname;
                    $hostname = 'http://' . $hostname;
                }

                // Save both hostname (HTTP) and hostname_secure (HTTPS) to the configuration
                $userconfig['hostname'] = addslashes($hostname);
                $userconfig['hostname_secure'] = addslashes($hostnameSecure);

                $walletData['http_address'] = $hostname;
                $walletData['https_address'] = $hostnameSecure;
            } else {
                $output->error("Invalid hostname format. Must be a valid URL.", ErrorCodes::INVALID_HOSTNAME, 400, [
                    'provided' => $argv[2]
                ]);
                exit(1);
            }
        }

        // Optional display name parameter (EIOU_NAME)
        // When provided, stores a human-readable name in userconfig.json
        if (isset($argv[3]) && !empty($argv[3])) {
            $userconfig['name'] = $argv[3];
            $walletData['name'] = $argv[3];
        }

        // Set strict file permissions before saving
        $oldUmask = umask(0077); // Ensure 600 permissions
        file_put_contents('/etc/eiou/config/userconfig.json', json_encode($userconfig), LOCK_EX);
        umask($oldUmask);

        chown('/etc/eiou/config/userconfig.json','www-data');

        // Verify and set file permissions
        chmod('/etc/eiou/config/userconfig.json', 0600);

        // Display seed phrase and authcode prominently with warning BEFORE the success message
        self::displaySeedPhrase($mnemonic, $output, $authCode);

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

        // Get seed phrase from arguments (words 3 onwards)
        // Usage: eiou generate restore word1 word2 word3 ... word24
        if (count($argv) < 27) { // eiou generate restore + 24 words
            $output->error(
                "Seed phrase required. Usage: eiou generate restore <24 words>",
                ErrorCodes::INVALID_SEED_PHRASE,
                400,
                ['expected_words' => '24', 'provided' => count($argv) - 3]
            );
            exit(1);
        }

        // Extract seed phrase words (everything after "restore")
        $seedWords = array_slice($argv, 3);
        $mnemonic = implode(' ', $seedWords);

        // SECURITY WARNING (L-27): Seed phrase passed via CLI arguments is visible in
        // process listings (ps aux). For production use, prefer the restore-file method.
        if (function_exists('error_log')) {
            error_log('SECURITY NOTICE: Wallet restore initiated via CLI arguments. '
                . 'Seed phrase may be visible in process listings. Consider using restore-file method instead.');
        }

        // Validate word count
        $wordCount = count($seedWords);
        if ($wordCount !== 24) {
            $output->error(
                "Invalid seed phrase length. Must be 24 words.",
                ErrorCodes::INVALID_WORD_COUNT,
                400,
                ['expected' => '24', 'provided' => $wordCount]
            );
            exit(1);
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
            exit(1);
        }

        // Check if wallet already exists
        if (file_exists('/etc/eiou/config/userconfig.json')) {
            $output->error(
                "Wallet already exists. Delete existing wallet first to restore.",
                'WALLET_EXISTS',
                409
            );
            KeyEncryption::secureClear($mnemonic);
            exit(1);
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
            'defaultTransportMode' => Constants::DEFAULT_TRANSPORT_MODE,
            'autoRefreshEnabled' => Constants::AUTO_REFRESH_ENABLED,
            'autoBackupEnabled' => Constants::BACKUP_AUTO_ENABLED
        ]);
        file_put_contents('/etc/eiou/config/defaultconfig.json', $defaultConfig, LOCK_EX);
        chown('/etc/eiou/config/defaultconfig.json', 'www-data');
        chmod('/etc/eiou/config/defaultconfig.json', 0600);

        // Derive deterministic EC key pair from seed using secp256k1
        // This generates the EXACT same key pair as the original wallet
        $seed = BIP39::mnemonicToSeed($mnemonic);
        $keyPair = BIP39::seedToKeyPair($seed);
        $privateKey = $keyPair['private'];
        $publicKey = $keyPair['public'];

        // Derive deterministic Tor hidden service keys from seed
        // This restores the SAME .onion address as the original wallet
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
        file_put_contents('/etc/eiou/config/userconfig.json', json_encode($userconfig), LOCK_EX);
        umask($oldUmask);

        chown('/etc/eiou/config/userconfig.json', 'www-data');
        chmod('/etc/eiou/config/userconfig.json', 0600);

        $walletData = [
            'tor_address' => $torAddress,
            'public_key_generated' => true,
            'restored_from_seed' => true,
            'word_count' => $wordCount
        ];

        // Display authcode only — the user already has the seedphrase (they just
        // used it to restore), so re-creating a seedphrase file is unnecessary
        // and an unneeded security exposure. The authcode is re-displayed so the
        // user can retrieve it if they lost it.
        if ($authCode) {
            SecureSeedphraseDisplay::displayAuthcode($authCode);
        }

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
            exit(1);
        }

        $filepath = $argv[3];

        // Validate file exists and is readable
        if (!file_exists($filepath)) {
            $output->error(
                "Seed phrase file not found: $filepath",
                ErrorCodes::FILE_NOT_FOUND,
                404
            );
            exit(1);
        }

        if (!is_readable($filepath)) {
            $output->error(
                "Cannot read seed phrase file: $filepath",
                ErrorCodes::FILE_NOT_READABLE,
                403
            );
            exit(1);
        }

        // Read seedphrase from file
        $fileContent = trim(file_get_contents($filepath));

        if (empty($fileContent)) {
            $output->error(
                "Seed phrase file is empty",
                ErrorCodes::INVALID_SEED_PHRASE,
                400
            );
            exit(1);
        }

        // Extract seed phrase words from file content
        // This handles multiple formats:
        // 1. Plain 24 words separated by spaces/newlines
        // 2. Numbered format (e.g., "1. word  2. word ...")
        // 3. Full secure file with headers and authcode section
        $words = self::extractSeedWordsFromContent($fileContent);

        if (count($words) !== 24) {
            $output->error(
                "Invalid seed phrase: expected 24 words, found " . count($words),
                ErrorCodes::INVALID_SEED_PHRASE,
                400,
                ['hint' => 'Ensure the file contains a valid 24-word BIP39 seed phrase']
            );
            exit(1);
        }

        // Build argv array for restoreWallet with the seedphrase words
        $restoreArgv = array_merge(
            [$argv[0], 'generate', 'restore'],
            $words
        );

        // Call the standard restore method
        self::restoreWallet($restoreArgv, $output);
    }

    /**
     * Extract BIP39 seed words from file content
     *
     * Handles multiple input formats:
     * - Plain 24 words separated by spaces/newlines
     * - Numbered format (e.g., "1. word  2. word ...")
     * - Full secure file output with headers and authcode section
     *
     * @param string $content File content
     * @return array Array of extracted BIP39 words
     */
    private static function extractSeedWordsFromContent(string $content): array {
        // Get the BIP39 wordlist for validation
        $wordlist = BIP39::getWordlist();
        $wordlistLower = array_map('strtolower', $wordlist);

        // Strategy 1: Try to extract numbered words first (e.g., "1. word", "12. word")
        // This is the most reliable for formatted secure files
        $numberedWords = [];
        if (preg_match_all('/\b(\d{1,2})\.\s*([a-z]+)\b/i', $content, $matches, PREG_SET_ORDER)) {
            // Sort by number to ensure correct order
            usort($matches, function($a, $b) {
                return intval($a[1]) - intval($b[1]);
            });

            foreach ($matches as $match) {
                $num = intval($match[1]);
                $word = strtolower($match[2]);

                // Only accept words numbered 1-24
                if ($num >= 1 && $num <= 24 && in_array($word, $wordlistLower)) {
                    $index = array_search($word, $wordlistLower);
                    $numberedWords[$num] = $wordlist[$index];
                }
            }

            // If we found exactly 24 numbered words (1-24), use them
            if (count($numberedWords) === 24) {
                ksort($numberedWords);
                return array_values($numberedWords);
            }
        }

        // Strategy 2: Fall back to extracting plain words (for simple "word1 word2 ... word24" format)
        // Split content by any whitespace
        $tokens = preg_split('/\s+/', $content);

        // Extract only valid BIP39 words
        $seedWords = [];
        foreach ($tokens as $token) {
            // Clean the token (remove any leading/trailing punctuation)
            $cleaned = preg_replace('/^[^a-z]+|[^a-z]+$/i', '', $token);
            $cleaned = strtolower(trim($cleaned));

            // Skip empty tokens or very short tokens
            if (empty($cleaned) || strlen($cleaned) < 3) {
                continue;
            }

            // Check if it's a valid BIP39 word
            if (in_array($cleaned, $wordlistLower)) {
                // Find the original case from wordlist
                $index = array_search($cleaned, $wordlistLower);
                $seedWords[] = $wordlist[$index];

                // Stop after finding 24 words
                if (count($seedWords) >= 24) {
                    break;
                }
            }
        }

        return $seedWords;
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
     * @param string|null $authCode Optional authentication code to display
     * @return void
     */
    private static function displaySeedPhrase(string $mnemonic, CliOutputManager $output, ?string $authCode = null): void {
        // In JSON mode, we need special handling
        // DO NOT include the seedphrase in JSON output that goes to stdout
        // Instead, use the secure display mechanism
        if ($output->isJsonMode()) {
            // For JSON mode in non-interactive contexts, use secure file
            $result = SecureSeedphraseDisplay::display($mnemonic, false, $authCode);

            if ($result['method'] === 'file' && isset($result['instructions'])) {
                // Output instructions as a separate info line to stderr to avoid log capture
                // The actual seedphrase is in the secure file, not in the JSON response
                $instructionText = implode("\n", $result['instructions']);
                fwrite(STDERR, "\n" . $instructionText . "\n\n");
            }
            return;
        }

        // Text mode: use secure display
        $result = SecureSeedphraseDisplay::display($mnemonic, true, $authCode);

        // If file method was used, output the retrieval instructions
        // (these are safe to log as they don't contain the seedphrase or authcode)
        if ($result['method'] === 'file') {
            if (isset($result['warning'])) {
                echo "\nWARNING: " . $result['warning'] . "\n";
            }

            echo "\n";
            echo "════════════════════════════════════════════════════════════════\n";
            if ($authCode !== null) {
                echo " SEEDPHRASE & AUTHCODE STORED SECURELY\n";
            } else {
                echo " SEEDPHRASE STORED SECURELY\n";
            }
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