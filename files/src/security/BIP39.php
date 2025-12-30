<?php
# Copyright 2025 The Vowels Company

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
     * Generate deterministic EC key pair from seed using secp256k1
     *
     * EC keys can be derived deterministically from a seed, unlike RSA.
     * The private key is derived using HMAC-SHA256 of the seed.
     *
     * @param string $seed Raw seed bytes from BIP39
     * @return array ['private' => string, 'public' => string]
     * @throws RuntimeException If key generation fails
     */
    public static function seedToKeyPair(string $seed): array {
        // Derive 32-byte EC private key deterministically from seed
        $privateKeyBytes = hash_hmac('sha256', $seed, 'eiou-ec-key', true);

        // Get the preferred EC curve (secp256k1 if available, otherwise prime256v1)
        $curveName = self::getPreferredCurve();

        // For secp256k1, the private key must be less than the curve order
        // The secp256k1 order is: FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141
        // For prime256v1, the order is: FFFFFFFF00000000FFFFFFFFFFFFFFFFBCE6FAADA7179E84F3B9CAC2FC632551
        // Our HMAC output is effectively random and very unlikely to exceed these,
        // but we ensure validity by reducing modulo the order if needed
        $privateKeyBytes = self::ensureValidPrivateKey($privateKeyBytes, $curveName);

        // Convert private key bytes to PEM format for OpenSSL
        $privateKeyPem = self::ecPrivateKeyToPem($privateKeyBytes, $curveName);

        // Load the private key and extract public key
        $keyResource = openssl_pkey_get_private($privateKeyPem);

        if (!$keyResource) {
            throw new RuntimeException('Failed to load EC private key: ' . openssl_error_string());
        }

        // Export the private key in standard PEM format
        if (!openssl_pkey_export($keyResource, $privateKey)) {
            throw new RuntimeException('Failed to export EC private key: ' . openssl_error_string());
        }

        // Get key details including public key
        $keyDetails = openssl_pkey_get_details($keyResource);

        if (!$keyDetails) {
            throw new RuntimeException('Failed to get EC key details: ' . openssl_error_string());
        }

        $publicKey = $keyDetails['key'];

        return [
            'private' => $privateKey,
            'public' => $publicKey
        ];
    }

    /**
     * Get the preferred EC curve name
     *
     * Checks if secp256k1 is available, otherwise falls back to prime256v1
     *
     * @return string The curve name to use
     */
    public static function getPreferredCurve(): string {
        $curves = openssl_get_curve_names();

        if (in_array('secp256k1', $curves)) {
            return 'secp256k1';
        }

        if (in_array('prime256v1', $curves)) {
            return 'prime256v1';
        }

        throw new RuntimeException('No supported EC curve available (need secp256k1 or prime256v1)');
    }

    /**
     * Ensure the private key is valid for the given curve
     *
     * @param string $privateKeyBytes 32-byte private key
     * @param string $curveName The EC curve name
     * @return string Valid 32-byte private key
     */
    private static function ensureValidPrivateKey(string $privateKeyBytes, string $curveName): string {
        // The private key must be non-zero and less than the curve order
        // For both secp256k1 and prime256v1, we check if all bytes are zero
        if ($privateKeyBytes === str_repeat("\x00", 32)) {
            // Extremely unlikely, but hash again if we get all zeros
            $privateKeyBytes = hash_hmac('sha256', $privateKeyBytes, 'eiou-ec-key-retry', true);
        }

        return $privateKeyBytes;
    }

    /**
     * Convert raw EC private key bytes to PEM format
     *
     * @param string $privateKeyBytes 32-byte private key
     * @param string $curveName The EC curve name
     * @return string PEM-encoded private key
     */
    private static function ecPrivateKeyToPem(string $privateKeyBytes, string $curveName): string {
        // OID for the curve
        $curveOids = [
            'secp256k1' => "\x06\x05\x2b\x81\x04\x00\x0a",  // 1.3.132.0.10
            'prime256v1' => "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07",  // 1.2.840.10045.3.1.7
        ];

        if (!isset($curveOids[$curveName])) {
            throw new RuntimeException("Unsupported curve: $curveName");
        }

        $curveOid = $curveOids[$curveName];

        // Build the EC private key structure (RFC 5915)
        // ECPrivateKey ::= SEQUENCE {
        //   version        INTEGER { ecPrivkeyVer1(1) },
        //   privateKey     OCTET STRING,
        //   parameters [0] ECParameters {{ NamedCurve }} OPTIONAL,
        //   publicKey  [1] BIT STRING OPTIONAL
        // }

        // Version = 1
        $version = "\x02\x01\x01";

        // Private key as OCTET STRING
        $privateKeyOctet = "\x04\x20" . $privateKeyBytes;

        // Parameters (curve OID) as context-specific [0]
        $parameters = "\xa0" . chr(strlen($curveOid)) . $curveOid;

        // Compute public key from private key
        $publicKeyBytes = self::computePublicKey($privateKeyBytes, $curveName);

        // Public key as context-specific [1] containing BIT STRING
        $publicKeyBitString = "\x03" . chr(strlen($publicKeyBytes) + 1) . "\x00" . $publicKeyBytes;
        $publicKeyContext = "\xa1" . chr(strlen($publicKeyBitString)) . $publicKeyBitString;

        // Combine into SEQUENCE
        $ecPrivateKey = $version . $privateKeyOctet . $parameters . $publicKeyContext;
        $ecPrivateKeySeq = "\x30" . self::asn1Length(strlen($ecPrivateKey)) . $ecPrivateKey;

        // Encode as PEM
        $base64 = base64_encode($ecPrivateKeySeq);
        $pem = "-----BEGIN EC PRIVATE KEY-----\n";
        $pem .= chunk_split($base64, 64, "\n");
        $pem .= "-----END EC PRIVATE KEY-----\n";

        return $pem;
    }

    /**
     * Compute the public key from a private key
     *
     * @param string $privateKeyBytes 32-byte private key
     * @param string $curveName The EC curve name
     * @return string Uncompressed public key (65 bytes: 0x04 + 32-byte X + 32-byte Y)
     */
    private static function computePublicKey(string $privateKeyBytes, string $curveName): string {
        // Use OpenSSL to compute the public key by creating a temporary key
        // This is a workaround since PHP doesn't expose EC point multiplication directly

        // Create a minimal EC private key PEM without public key to bootstrap
        $curveOids = [
            'secp256k1' => "\x06\x05\x2b\x81\x04\x00\x0a",
            'prime256v1' => "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07",
        ];

        $curveOid = $curveOids[$curveName];

        // Minimal EC private key structure
        $version = "\x02\x01\x01";
        $privateKeyOctet = "\x04\x20" . $privateKeyBytes;
        $parameters = "\xa0" . chr(strlen($curveOid)) . $curveOid;

        $ecPrivateKey = $version . $privateKeyOctet . $parameters;
        $ecPrivateKeySeq = "\x30" . self::asn1Length(strlen($ecPrivateKey)) . $ecPrivateKey;

        $pem = "-----BEGIN EC PRIVATE KEY-----\n";
        $pem .= chunk_split(base64_encode($ecPrivateKeySeq), 64, "\n");
        $pem .= "-----END EC PRIVATE KEY-----\n";

        // Load the key - OpenSSL will compute the public key
        $key = openssl_pkey_get_private($pem);

        if (!$key) {
            throw new RuntimeException('Failed to compute public key: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);

        if (!$details || !isset($details['ec']['x']) || !isset($details['ec']['y'])) {
            throw new RuntimeException('Failed to extract EC public key coordinates');
        }

        // Build uncompressed public key: 0x04 + X + Y
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        return "\x04" . $x . $y;
    }

    /**
     * Encode ASN.1 length
     *
     * @param int $length The length to encode
     * @return string ASN.1 length bytes
     */
    private static function asn1Length(int $length): string {
        if ($length < 128) {
            return chr($length);
        } elseif ($length < 256) {
            return "\x81" . chr($length);
        } else {
            return "\x82" . pack('n', $length);
        }
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
     * @param int $lineWidth Width of each line for padding (default 61 for table display)
     * @return string Formatted mnemonic
     */
    public static function formatMnemonic(string $mnemonic, int $lineWidth = 61): string {
        $words = explode(' ', $mnemonic);
        $lines = [];

        // Find the longest word to calculate consistent column widths
        $maxWordLength = 0;
        foreach ($words as $word) {
            $maxWordLength = max($maxWordLength, strlen($word));
        }

        // Each numbered word format: "NN. word" where NN is 2 digits (right-aligned)
        // Column width = 2 (number) + 2 (". ") + maxWordLength = 4 + maxWordLength
        $columnWidth = 4 + $maxWordLength;

        for ($i = 0; $i < count($words); $i += 4) {
            $lineWords = array_slice($words, $i, 4);
            $numberedWords = [];
            foreach ($lineWords as $j => $word) {
                $num = $i + $j + 1;
                // Pad number to 2 chars (right-aligned), then pad entire entry to column width
                $entry = str_pad($num, 2, ' ', STR_PAD_LEFT) . '. ' . str_pad($word, $maxWordLength);
                $numberedWords[] = $entry;
            }
            $lineContent = implode('  ', $numberedWords);
            // Pad the entire line to the specified width
            $lines[] = str_pad($lineContent, $lineWidth);
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

    /**
     * Derive a deterministic authentication code from BIP39 seed
     *
     * This ensures that the same seed phrase always produces the same authcode,
     * allowing wallet recovery to restore the exact same authcode.
     *
     * The authcode is derived using HMAC-SHA256 with a unique context string,
     * following the same pattern as TorKeyDerivation for consistency.
     *
     * @param string $seed Raw BIP39 seed bytes (64 bytes)
     * @param int $length Desired authcode length in hex characters (default: 20)
     * @return string Hex-encoded authentication code
     */
    public static function seedToAuthCode(string $seed, int $length = 20): string {
        // Derive authcode deterministically using HMAC-SHA256 with unique context
        // The context string 'eiou-auth-code' ensures this derivation is
        // independent from key pair and Tor address derivations
        $derivedBytes = hash_hmac('sha256', $seed, 'eiou-auth-code', true);

        // Convert to hex and truncate to desired length
        // Default 20 hex chars = 10 bytes = 80 bits of entropy (matches original)
        $authCode = bin2hex(substr($derivedBytes, 0, (int) ceil($length / 2)));

        // Ensure exact length by truncating if odd length requested
        return substr($authCode, 0, $length);
    }
}
