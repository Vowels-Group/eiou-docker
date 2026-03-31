<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * Key Encryption Service
 *
 * Provides secure encryption/decryption for private keys using AES-256-GCM.
 *
 * Security Features:
 * - AES-256-GCM encryption (AEAD - Authenticated Encryption with Associated Data)
 * - Unique IV (Initialization Vector) per encryption
 * - Authentication tag for integrity verification
 * - Key derivation from system entropy
 * - Secure random number generation
 *
 * Copyright 2025
 */

class KeyEncryption {
    /**
     * Encryption algorithm
     */
    private const CIPHER = 'aes-256-gcm';

    /**
     * Tag length for GCM mode (16 bytes = 128 bits)
     */
    private const TAG_LENGTH = 16;

    /**
     * IV length (12 bytes = 96 bits recommended for GCM)
     */
    private const IV_LENGTH = 12;

    /**
     * Encryption format version (v2 adds AAD context binding)
     */
    private const ENCRYPTION_VERSION = 2;

    /**
     * HMAC context for master key derivation from BIP39 seed
     */
    private const HMAC_CONTEXT_MASTER_KEY = 'eiou-master-key';

    /**
     * Master key file location (persistent volume — may be encrypted)
     */
    private const MASTER_KEY_FILE = '/etc/eiou/config/.master.key';

    /**
     * Runtime master key location (RAM-backed, used when volume encryption is active)
     */
    private const RUNTIME_KEY_FILE = '/dev/shm/.master.key';

    /**
     * Get master encryption key
     *
     * Checks /dev/shm first (runtime key from volume encryption), then falls
     * back to the persistent volume. When volume encryption is active, the key
     * only exists in /dev/shm — the persistent copy is encrypted.
     *
     * The master key must be initialized via initMasterKeyFromSeed() during
     * wallet generation or restore. This method only reads the existing key.
     *
     * @return string Binary encryption key (32 bytes for AES-256)
     * @throws RuntimeException If master key file does not exist or is corrupted
     */
    private static function getMasterKey(): string {
        // Prefer runtime key in RAM (set by VolumeEncryption during startup)
        $keyFile = self::RUNTIME_KEY_FILE;
        if (!file_exists($keyFile)) {
            $keyFile = self::MASTER_KEY_FILE;
        }

        if (!file_exists($keyFile)) {
            throw new RuntimeException(
                'Master key not found. Initialize wallet first via generate or restore.'
            );
        }

        $key = file_get_contents($keyFile);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Master key file corrupted');
        }

        return $key;
    }

    /**
     * Derive a deterministic master key from a BIP39 seed
     *
     * Uses HMAC-SHA256 with a unique context string to ensure domain separation
     * from other seed-derived values (keypair, Tor keys, auth code).
     *
     * @param string $seed Raw BIP39 seed bytes (64 bytes)
     * @return string 32-byte deterministic master key
     */
    public static function deriveMasterKeyFromSeed(string $seed): string {
        return hash_hmac('sha256', $seed, self::HMAC_CONTEXT_MASTER_KEY, true);
    }

    /**
     * Initialize the master key file from a BIP39 seed
     *
     * Derives the master key deterministically and writes it to disk.
     * Must be called during wallet generation or restore, before any
     * encrypt/decrypt operations.
     *
     * @param string $seed Raw BIP39 seed bytes (64 bytes)
     * @throws RuntimeException If the key file cannot be written
     */
    public static function initMasterKeyFromSeed(string $seed): void {
        $key = self::deriveMasterKeyFromSeed($seed);

        $dir = dirname(self::MASTER_KEY_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        // Always write plaintext key first (needed for immediate use)
        $oldUmask = umask(0077);
        $result = file_put_contents(self::MASTER_KEY_FILE, $key, LOCK_EX);
        umask($oldUmask);

        self::secureClear($key);

        if ($result === false) {
            throw new RuntimeException('Failed to save master key');
        }

        if (posix_getuid() === 0) {
            chown(self::MASTER_KEY_FILE, 'www-data');
        }
        chmod(self::MASTER_KEY_FILE, 0600);

        // If volume encryption is active, encrypt the key and move plaintext to /dev/shm
        if (VolumeEncryption::isActive()) {
            $passphrase = VolumeEncryption::getPassphrase();
            if ($passphrase !== null) {
                VolumeEncryption::encryptNewMasterKey($passphrase);
                self::secureClear($passphrase);
            }
        }
    }

    /**
     * Encrypt private key
     *
     * @param string $plaintext Private key in plaintext
     * @param string $context AAD context string for domain binding (e.g. 'private_key', 'backup')
     * @return array Encrypted data with version, AAD, ciphertext, iv, tag
     * @throws RuntimeException If encryption fails
     */
    public static function encrypt(string $plaintext, string $context = ''): array {
        if (empty($plaintext)) {
            throw new InvalidArgumentException('Cannot encrypt empty data');
        }

        // Get master encryption key
        $key = self::getMasterKey();

        // Generate unique IV (Initialization Vector)
        // Note: random_bytes() throws Exception on failure in PHP 7+, never returns false
        $iv = random_bytes(self::IV_LENGTH);

        // Encrypt with AES-256-GCM using context as AAD (L-28)
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context,
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Encode output before clearing sensitive source data
        $result = [
            'ciphertext' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'version' => self::ENCRYPTION_VERSION,
            'aad' => $context
        ];

        // Clear sensitive data from memory
        self::secureClear($key);
        self::secureClear($plaintext);
        self::secureClear($iv);

        return $result;
    }

    /**
     * Decrypt private key
     *
     * Requires v2 format with AAD context field.
     *
     * @param array $encrypted Encrypted data from encrypt()
     * @return string Decrypted plaintext
     * @throws RuntimeException If decryption fails
     * @throws InvalidArgumentException If format is invalid or missing version/aad
     */
    public static function decrypt(array $encrypted): string {
        if (!isset($encrypted['ciphertext'], $encrypted['iv'], $encrypted['tag'])) {
            throw new InvalidArgumentException('Invalid encrypted data format');
        }

        if (!isset($encrypted['version']) || $encrypted['version'] < 2) {
            throw new InvalidArgumentException('Unsupported encryption format: v2+ required');
        }

        // Get master encryption key
        $key = self::getMasterKey();

        // Decode base64 data
        $ciphertext = base64_decode($encrypted['ciphertext'], true);
        $iv = base64_decode($encrypted['iv'], true);
        $tag = base64_decode($encrypted['tag'], true);

        if ($ciphertext === false || $iv === false || $tag === false) {
            throw new RuntimeException('Invalid base64 encoding');
        }

        $aad = $encrypted['aad'] ?? '';

        // Decrypt with AES-256-GCM
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );

        if ($plaintext === false) {
            // Clear sensitive data before throwing
            self::secureClear($key);
            throw new RuntimeException('Decryption failed - data may be corrupted or tampered');
        }

        // Clear encryption key from memory
        self::secureClear($key);

        return $plaintext;
    }

    /**
     * Securely clear string from memory
     *
     * @param string &$data String to clear (passed by reference)
     * @return void
     */
    public static function secureClear(string &$data): void {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($data);
        } else {
            // Fallback: overwrite with random data then empty string
            $len = strlen($data);
            $data = str_repeat("\0", $len);
            $data = '';
        }
    }

    /**
     * Verify encryption is available
     *
     * @return bool True if encryption is properly configured
     */
    public static function isAvailable(): bool {
        return extension_loaded('openssl') &&
               in_array(self::CIPHER, openssl_get_cipher_methods()) &&
               function_exists('random_bytes');
    }

    /**
     * Get encryption info for diagnostics
     *
     * @return array Encryption configuration details
     */
    public static function getInfo(): array {
        return [
            'cipher' => self::CIPHER,
            'key_size' => 256,
            'iv_length' => self::IV_LENGTH,
            'tag_length' => self::TAG_LENGTH,
            'openssl_available' => extension_loaded('openssl'),
            'sodium_available' => extension_loaded('sodium'),
            'master_key_exists' => file_exists(self::RUNTIME_KEY_FILE) || file_exists(self::MASTER_KEY_FILE),
            'volume_encryption' => VolumeEncryption::getStatus()
        ];
    }
}
