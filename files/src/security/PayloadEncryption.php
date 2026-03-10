<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * Payload Encryption Service
 *
 * Provides end-to-end encryption for transaction payloads using ECDH + AES-256-GCM.
 *
 * Encryption Flow:
 * 1. Generate ephemeral EC key pair (same curve as recipient)
 * 2. Derive shared secret via ECDH(ephemeral_private, recipient_public)
 * 3. Derive symmetric key via HKDF-SHA256
 * 4. Encrypt sensitive fields with AES-256-GCM
 * 5. Include ephemeral public key in output for recipient to derive same secret
 *
 * Decryption Flow:
 * 1. Load ephemeral public key from encrypted data
 * 2. Derive shared secret via ECDH(recipient_private, ephemeral_public)
 * 3. Derive same symmetric key via HKDF-SHA256
 * 4. Decrypt with AES-256-GCM
 */
class PayloadEncryption
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const IV_LENGTH = 12;
    private const HKDF_CONTEXT = 'eiou-payload-e2e';
    private const HKDF_KEY_LENGTH = 32;

    /**
     * Fields that are encrypted in direct transaction payloads
     */
    public const ENCRYPTED_FIELDS = ['amount', 'currency', 'txid', 'previousTxid', 'memo'];

    /**
     * Encrypt sensitive fields for a recipient using ECDH + AES-256-GCM
     *
     * @param array $sensitiveFields Associative array of fields to encrypt
     * @param string $recipientPublicKeyPem Recipient's EC public key in PEM format
     * @return array Encrypted data: {ciphertext, iv, tag, ephemeralKey} (all base64)
     * @throws RuntimeException If encryption fails
     * @throws InvalidArgumentException If inputs are invalid
     */
    public static function encryptForRecipient(array $sensitiveFields, string $recipientPublicKeyPem): array
    {
        if (empty($sensitiveFields)) {
            throw new InvalidArgumentException('Cannot encrypt empty payload');
        }

        if (empty($recipientPublicKeyPem)) {
            throw new InvalidArgumentException('Recipient public key is required');
        }

        // Load recipient's public key to detect curve
        $recipientKey = openssl_pkey_get_public($recipientPublicKeyPem);
        if ($recipientKey === false) {
            throw new RuntimeException('Invalid recipient public key: ' . openssl_error_string());
        }

        $recipientDetails = openssl_pkey_get_details($recipientKey);
        if ($recipientDetails === false || $recipientDetails['type'] !== OPENSSL_KEYTYPE_EC) {
            throw new RuntimeException('Recipient key must be an EC key');
        }

        $curveName = $recipientDetails['ec']['curve_name'];

        // Generate ephemeral EC key pair on same curve
        $ephemeralKey = openssl_pkey_new([
            'ec' => ['curve_name' => $curveName],
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($ephemeralKey === false) {
            throw new RuntimeException('Failed to generate ephemeral key: ' . openssl_error_string());
        }

        // Derive shared secret via ECDH
        $sharedSecret = openssl_pkey_derive($recipientKey, $ephemeralKey);
        if ($sharedSecret === false) {
            throw new RuntimeException('ECDH key agreement failed: ' . openssl_error_string());
        }

        // Derive symmetric encryption key via HKDF
        $symmetricKey = hash_hkdf('sha256', $sharedSecret, self::HKDF_KEY_LENGTH, self::HKDF_CONTEXT);

        // Generate unique IV
        $iv = random_bytes(self::IV_LENGTH);

        // Encrypt the sensitive fields
        $plaintext = json_encode($sensitiveFields);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $symmetricKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            self::secureClear($sharedSecret);
            self::secureClear($symmetricKey);
            throw new RuntimeException('Payload encryption failed: ' . openssl_error_string());
        }

        // Export ephemeral public key in PEM format
        $ephemeralDetails = openssl_pkey_get_details($ephemeralKey);
        $ephemeralPublicKeyPem = $ephemeralDetails['key'];

        $result = [
            'ciphertext' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ephemeralKey' => base64_encode($ephemeralPublicKeyPem),
        ];

        // Clear sensitive data
        self::secureClear($sharedSecret);
        self::secureClear($symmetricKey);
        self::secureClear($plaintext);

        return $result;
    }

    /**
     * Decrypt payload encrypted by encryptForRecipient
     *
     * @param array $encryptedData {ciphertext, iv, tag, ephemeralKey} (all base64)
     * @param string $recipientPrivateKeyPem Recipient's EC private key in PEM format
     * @return array Decrypted sensitive fields
     * @throws RuntimeException If decryption fails
     * @throws InvalidArgumentException If inputs are invalid
     */
    public static function decryptFromSender(array $encryptedData, string $recipientPrivateKeyPem): array
    {
        $requiredFields = ['ciphertext', 'iv', 'tag', 'ephemeralKey'];
        foreach ($requiredFields as $field) {
            if (!isset($encryptedData[$field])) {
                throw new InvalidArgumentException("Missing required encrypted field: $field");
            }
        }

        // Load recipient's private key
        $recipientKey = openssl_pkey_get_private($recipientPrivateKeyPem);
        if ($recipientKey === false) {
            throw new RuntimeException('Invalid recipient private key: ' . openssl_error_string());
        }

        // Load ephemeral public key
        $ephemeralPublicKeyPem = base64_decode($encryptedData['ephemeralKey'], true);
        if ($ephemeralPublicKeyPem === false) {
            throw new RuntimeException('Invalid ephemeral key encoding');
        }

        $ephemeralKey = openssl_pkey_get_public($ephemeralPublicKeyPem);
        if ($ephemeralKey === false) {
            throw new RuntimeException('Invalid ephemeral public key: ' . openssl_error_string());
        }

        // Derive shared secret via ECDH (reverse direction)
        $sharedSecret = openssl_pkey_derive($ephemeralKey, $recipientKey);
        if ($sharedSecret === false) {
            throw new RuntimeException('ECDH key agreement failed: ' . openssl_error_string());
        }

        // Derive same symmetric key
        $symmetricKey = hash_hkdf('sha256', $sharedSecret, self::HKDF_KEY_LENGTH, self::HKDF_CONTEXT);

        // Decode encrypted components
        $ciphertext = base64_decode($encryptedData['ciphertext'], true);
        $iv = base64_decode($encryptedData['iv'], true);
        $tag = base64_decode($encryptedData['tag'], true);

        if ($ciphertext === false || $iv === false || $tag === false) {
            self::secureClear($sharedSecret);
            self::secureClear($symmetricKey);
            throw new RuntimeException('Invalid base64 encoding in encrypted data');
        }

        // Decrypt
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $symmetricKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        // Clear sensitive key material
        self::secureClear($sharedSecret);
        self::secureClear($symmetricKey);

        if ($plaintext === false) {
            throw new RuntimeException('Payload decryption failed — data may be corrupted or tampered');
        }

        $result = json_decode($plaintext, true);
        self::secureClear($plaintext);

        if (!is_array($result)) {
            throw new RuntimeException('Decrypted payload is not valid JSON');
        }

        return $result;
    }

    /**
     * Check if payload encryption is available in this environment
     *
     * @return bool True if all required functions are available
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('openssl')
            && in_array(self::CIPHER, openssl_get_cipher_methods())
            && function_exists('openssl_pkey_derive')
            && function_exists('hash_hkdf')
            && function_exists('random_bytes');
    }

    /**
     * Securely clear string from memory
     *
     * @param string &$data String to clear
     */
    private static function secureClear(string &$data): void
    {
        KeyEncryption::secureClear($data);
    }
}
