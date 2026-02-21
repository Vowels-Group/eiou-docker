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
     * Master key file location
     */
    private const MASTER_KEY_FILE = '/etc/eiou/config/.master.key';

    /**
     * Get or generate master encryption key
     *
     * @return string Binary encryption key (32 bytes for AES-256)
     * @throws RuntimeException If key generation fails
     */
    private static function getMasterKey(): string {
        // Check if master key exists
        if (file_exists(self::MASTER_KEY_FILE)) {
            $key = file_get_contents(self::MASTER_KEY_FILE);

            if ($key === false || strlen($key) !== 32) {
                throw new RuntimeException('Master key file corrupted');
            }

            return $key;
        }

        // Generate new master key (32 bytes = 256 bits)
        // Note: random_bytes() throws Exception on failure in PHP 7+, never returns false
        $key = random_bytes(32);

        // Save master key with strict permissions
        $oldUmask = umask(0077); // Ensure 600 permissions
        $result = file_put_contents(self::MASTER_KEY_FILE, $key, LOCK_EX);
        umask($oldUmask);

        if ($result === false) {
            throw new RuntimeException('Failed to save master key');
        }

        chown(self::MASTER_KEY_FILE, 'www-data');
        
        // Verify file permissions
        chmod(self::MASTER_KEY_FILE, 0600);

        return $key;
    }

    /**
     * Encrypt private key
     *
     * @param string $plaintext Private key in plaintext
     * @return array Encrypted data with format: ['ciphertext' => base64, 'iv' => base64, 'tag' => base64]
     * @throws RuntimeException If encryption fails
     */
    public static function encrypt(string $plaintext): array {
        if (empty($plaintext)) {
            throw new InvalidArgumentException('Cannot encrypt empty data');
        }

        // Get master encryption key
        $key = self::getMasterKey();

        // Generate unique IV (Initialization Vector)
        // Note: random_bytes() throws Exception on failure in PHP 7+, never returns false
        $iv = random_bytes(self::IV_LENGTH);

        // Encrypt with AES-256-GCM
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',  // TODO (L-28): Add AAD for context binding (requires encrypted data format migration)
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Encode output before clearing sensitive source data
        $result = [
            'ciphertext' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag)
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
     * @param array $encrypted Encrypted data from encrypt()
     * @return string Decrypted plaintext
     * @throws RuntimeException If decryption fails
     */
    public static function decrypt(array $encrypted): string {
        if (!isset($encrypted['ciphertext'], $encrypted['iv'], $encrypted['tag'])) {
            throw new InvalidArgumentException('Invalid encrypted data format');
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

        // Decrypt with AES-256-GCM
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
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
            'master_key_exists' => file_exists(self::MASTER_KEY_FILE)
        ];
    }
}
