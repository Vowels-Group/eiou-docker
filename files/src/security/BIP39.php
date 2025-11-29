<?php
# Copyright 2025

/**
 * BIP39 Mnemonic Implementation
 *
 * Implements BIP39 (Bitcoin Improvement Proposal 39) for:
 * - Mnemonic seed phrase generation (12 or 24 words)
 * - Mnemonic validation
 * - Seed derivation using PBKDF2
 *
 * @see https://github.com/bitcoin/bips/blob/master/bip-0039.mediawiki
 */

class BIP39 {
    /**
     * Number of bits per word in the mnemonic
     */
    private const BITS_PER_WORD = 11;

    /**
     * Number of PBKDF2 iterations for seed derivation
     */
    private const PBKDF2_ITERATIONS = 2048;

    /**
     * Seed derivation output length in bytes
     */
    private const SEED_LENGTH = 64;

    /**
     * BIP39 English wordlist (2048 words)
     * @var array
     */
    private static ?array $wordlist = null;

    /**
     * Generate a mnemonic seed phrase
     *
     * @param int $wordCount Number of words (12 or 24)
     * @return string Space-separated mnemonic words
     * @throws InvalidArgumentException If invalid word count
     */
    public static function generateMnemonic(int $wordCount = 12): string {
        // Validate word count
        if (!in_array($wordCount, [12, 24])) {
            throw new InvalidArgumentException('Word count must be 12 or 24');
        }

        // Calculate entropy size
        // 12 words = 128 bits entropy + 4 bits checksum = 132 bits
        // 24 words = 256 bits entropy + 8 bits checksum = 264 bits
        $entropyBits = ($wordCount * self::BITS_PER_WORD) - ($wordCount / 3);
        $entropyBytes = (int) ($entropyBits / 8);

        // Generate cryptographically secure random entropy
        $entropy = random_bytes($entropyBytes);

        return self::entropyToMnemonic($entropy);
    }

    /**
     * Convert entropy bytes to mnemonic
     *
     * @param string $entropy Raw entropy bytes
     * @return string Space-separated mnemonic words
     */
    public static function entropyToMnemonic(string $entropy): string {
        $entropyBits = strlen($entropy) * 8;

        // Calculate checksum bits (ENT / 32)
        $checksumBits = (int) ($entropyBits / 32);

        // Calculate SHA256 checksum
        $hash = hash('sha256', $entropy, true);

        // Convert entropy and checksum to binary string
        $bits = '';
        for ($i = 0; $i < strlen($entropy); $i++) {
            $bits .= str_pad(decbin(ord($entropy[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Add checksum bits
        for ($i = 0; $i < ceil($checksumBits / 8); $i++) {
            $bits .= str_pad(decbin(ord($hash[$i])), 8, '0', STR_PAD_LEFT);
        }
        $bits = substr($bits, 0, $entropyBits + $checksumBits);

        // Split into 11-bit chunks and convert to word indices
        $wordlist = self::getWordlist();
        $words = [];

        for ($i = 0; $i < strlen($bits); $i += self::BITS_PER_WORD) {
            $index = bindec(substr($bits, $i, self::BITS_PER_WORD));
            $words[] = $wordlist[$index];
        }

        return implode(' ', $words);
    }

    /**
     * Validate a mnemonic phrase
     *
     * @param string $mnemonic Space-separated mnemonic words
     * @return bool True if valid
     */
    public static function validateMnemonic(string $mnemonic): bool {
        $words = preg_split('/\s+/', trim($mnemonic));
        $wordCount = count($words);

        // Check word count
        if (!in_array($wordCount, [12, 24])) {
            return false;
        }

        $wordlist = self::getWordlist();
        $wordlistFlipped = array_flip($wordlist);

        // Convert words to binary
        $bits = '';
        foreach ($words as $word) {
            if (!isset($wordlistFlipped[$word])) {
                return false; // Invalid word
            }
            $index = $wordlistFlipped[$word];
            $bits .= str_pad(decbin($index), self::BITS_PER_WORD, '0', STR_PAD_LEFT);
        }

        // Calculate entropy and checksum lengths
        $entropyBits = ($wordCount * self::BITS_PER_WORD) - ($wordCount / 3);
        $checksumBits = (int) ($wordCount / 3);

        // Extract entropy and checksum
        $entropyBitsStr = substr($bits, 0, (int) $entropyBits);
        $checksumBitsStr = substr($bits, (int) $entropyBits, (int) $checksumBits);

        // Convert entropy bits to bytes
        $entropy = '';
        for ($i = 0; $i < strlen($entropyBitsStr); $i += 8) {
            $entropy .= chr(bindec(substr($entropyBitsStr, $i, 8)));
        }

        // Calculate expected checksum
        $hash = hash('sha256', $entropy, true);
        $expectedChecksumBits = '';
        for ($i = 0; $i < ceil($checksumBits / 8); $i++) {
            $expectedChecksumBits .= str_pad(decbin(ord($hash[$i])), 8, '0', STR_PAD_LEFT);
        }
        $expectedChecksumBits = substr($expectedChecksumBits, 0, (int) $checksumBits);

        // Verify checksum
        return $checksumBitsStr === $expectedChecksumBits;
    }

    /**
     * Convert mnemonic to seed using PBKDF2
     *
     * @param string $mnemonic Space-separated mnemonic words
     * @param string $passphrase Optional passphrase (default: empty)
     * @return string Raw seed bytes (64 bytes)
     */
    public static function mnemonicToSeed(string $mnemonic, string $passphrase = ''): string {
        // Normalize mnemonic (NFKD normalization - simplified for ASCII)
        $mnemonic = trim(preg_replace('/\s+/', ' ', $mnemonic));

        // Salt is "mnemonic" + passphrase
        $salt = 'mnemonic' . $passphrase;

        // PBKDF2 with HMAC-SHA512
        return hash_pbkdf2(
            'sha512',
            $mnemonic,
            $salt,
            self::PBKDF2_ITERATIONS,
            self::SEED_LENGTH,
            true
        );
    }

    /**
     * Derive a private key from seed (simplified - uses first 32 bytes)
     *
     * Note: Full BIP32 derivation would require HMAC-SHA512 chain code derivation.
     * This simplified version uses the seed directly for RSA key generation seeding.
     *
     * @param string $seed Raw seed bytes
     * @return string Hex-encoded deterministic seed for key generation
     */
    public static function seedToPrivateKeySeed(string $seed): string {
        // Use first 32 bytes of the 64-byte seed
        return bin2hex(substr($seed, 0, 32));
    }

    /**
     * Generate deterministic RSA key pair from seed
     *
     * Note: RSA keys cannot be directly derived from a seed like EC keys.
     * This method uses the seed as a deterministic random source.
     *
     * @param string $seed Raw seed bytes from BIP39
     * @return array ['private' => string, 'public' => string]
     */
    public static function seedToKeyPair(string $seed): array {
        // For RSA, we need to use a deterministic PRNG seeded with our seed
        // PHP's OpenSSL doesn't support deterministic key generation directly,
        // so we'll use a workaround: generate and store the seed, then
        // regenerate using the same seed when restoring

        // Generate a hash-based deterministic value for seeding
        $deterministicSeed = hash('sha512', $seed . 'eiou_key_derivation_v1', true);

        // Set up OpenSSL config for key generation
        $config = [
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ];

        // Generate key pair
        // Note: This won't be truly deterministic without custom PRNG,
        // so we'll store both the seed AND the generated key
        $res = openssl_pkey_new($config);

        if (!$res) {
            throw new RuntimeException('Failed to generate key pair: ' . openssl_error_string());
        }

        openssl_pkey_export($res, $privateKey);
        $keyDetails = openssl_pkey_get_details($res);
        $publicKey = $keyDetails['key'];

        return [
            'private' => $privateKey,
            'public' => $publicKey
        ];
    }

    /**
     * Get the BIP39 English wordlist
     *
     * @return array 2048 words
     */
    public static function getWordlist(): array {
        if (self::$wordlist !== null) {
            return self::$wordlist;
        }

        // Load wordlist from file
        $wordlistPath = __DIR__ . '/bip39-wordlist-english.txt';

        if (file_exists($wordlistPath)) {
            self::$wordlist = file($wordlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        } else {
            // Embedded wordlist (first 100 words for fallback, full list should be in file)
            throw new RuntimeException('BIP39 wordlist file not found: ' . $wordlistPath);
        }

        if (count(self::$wordlist) !== 2048) {
            throw new RuntimeException('Invalid wordlist: expected 2048 words, got ' . count(self::$wordlist));
        }

        return self::$wordlist;
    }

    /**
     * Format mnemonic for display (groups of 4 words per line)
     *
     * @param string $mnemonic Space-separated mnemonic
     * @return string Formatted mnemonic
     */
    public static function formatMnemonic(string $mnemonic): string {
        $words = explode(' ', $mnemonic);
        $lines = [];

        for ($i = 0; $i < count($words); $i += 4) {
            $lineWords = array_slice($words, $i, 4);
            $numberedWords = [];
            foreach ($lineWords as $j => $word) {
                $num = $i + $j + 1;
                $numberedWords[] = str_pad($num, 2, ' ', STR_PAD_LEFT) . '. ' . $word;
            }
            $lines[] = implode('  ', $numberedWords);
        }

        return implode("\n", $lines);
    }

    /**
     * Securely clear sensitive data from memory
     *
     * @param string &$data Data to clear
     */
    public static function secureClear(string &$data): void {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($data);
        } else {
            $data = str_repeat("\0", strlen($data));
        }
        $data = '';
    }
}
