<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Security;

use Eiou\Core\UserContext;
use InvalidArgumentException;
use RuntimeException;

/**
 * Payload Encryption Service
 *
 * Provides end-to-end encryption for all contact message payloads using ECDH + AES-256-GCM.
 * Every message sent to a known contact (P2P, RP2P, transactions, pings, etc.) is encrypted.
 * Only contact requests (type=create) are excluded since the recipient may not be a contact yet.
 *
 * Cleartext fallback (recipient public key unavailable):
 * - Transaction inquiry to P2P end-recipient (not necessarily a direct contact)
 * - Contact acceptance inquiry to pending contacts (public key not yet known)
 * - Any message where ContactRepository::getPublicKeyFromAddress() returns null
 *
 * Encryption Flow:
 * 1. Generate ephemeral EC key pair (same curve as recipient)
 * 2. Derive shared secret via ECDH(ephemeral_private, recipient_public)
 * 3. Derive symmetric key via HKDF-SHA256
 * 4. Encrypt all message fields with AES-256-GCM
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
     * Fields that were encrypted in the legacy direct-transaction-only E2E mode.
     * Kept for test compatibility. New code encrypts ALL message content fields.
     */
    public const ENCRYPTED_FIELDS = ['amount', 'currency', 'txid', 'previousTxid', 'memo'];

    /**
     * Message types excluded from E2E encryption.
     * - 'create': contact requests — recipient may not be a contact yet (no public key available)
     */
    public const TYPES_EXCLUDED_FROM_ENCRYPTION = ['create'];

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

        // The network standardises on secp256k1. Rejecting any other curve here
        // prevents a misconfigured peer (or a malicious one) from tricking us
        // into generating ephemeral keys on a curve the rest of the network
        // cannot process. See BIP39::getPreferredCurve() for the full rationale.
        if ($curveName !== 'secp256k1') {
            throw new RuntimeException(
                "Recipient key uses unsupported curve '$curveName' — this network requires secp256k1"
            );
        }

        // Generate ephemeral EC key pair on the same (secp256k1) curve
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
     * Retrieves the recipient's private key internally from UserContext
     * so that key material never leaves the Security namespace.
     *
     * @param array $encryptedData {ciphertext, iv, tag, ephemeralKey} (all base64)
     * @param string|null $privateKeyPem Private key override (testing only)
     * @return array Decrypted sensitive fields
     * @throws RuntimeException If decryption fails or private key unavailable
     * @throws InvalidArgumentException If inputs are invalid
     */
    public static function decryptFromSender(array $encryptedData, ?string $privateKeyPem = null): array
    {
        $requiredFields = ['ciphertext', 'iv', 'tag', 'ephemeralKey'];
        foreach ($requiredFields as $field) {
            if (!isset($encryptedData[$field])) {
                throw new InvalidArgumentException("Missing required encrypted field: $field");
            }
        }

        // Use provided key (testing) or retrieve from UserContext (production)
        $recipientPrivateKeyPem = $privateKeyPem ?? UserContext::getInstance()->getPrivateKey();
        if (empty($recipientPrivateKeyPem)) {
            throw new RuntimeException('Private key unavailable for decryption');
        }

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
