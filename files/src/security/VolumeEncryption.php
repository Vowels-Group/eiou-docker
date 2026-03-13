<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Security;

use RuntimeException;

/**
 * Volume Encryption Service
 *
 * Protects the master encryption key at rest using a passphrase provided by the
 * node operator. Without the passphrase, the host server cannot read the master
 * key from the Docker volume, and therefore cannot decrypt private keys,
 * database passwords, seed phrases, or backups.
 *
 * Flow:
 *   1. Node operator sets EIOU_VOLUME_KEY or EIOU_VOLUME_KEY_FILE
 *   2. On first boot: master key is encrypted with passphrase-derived key
 *      and stored as .master.key.enc on the config volume
 *   3. On every boot: master key is decrypted from .master.key.enc into
 *      /dev/shm/.master.key (RAM-only, never on persistent storage)
 *   4. Application reads master key from /dev/shm/.master.key at runtime
 *
 * Key derivation uses Argon2id (via libsodium) for resistance to GPU/ASIC
 * brute-force attacks on the passphrase.
 */
class VolumeEncryption
{
    /**
     * Encrypted master key file on the persistent config volume
     */
    private const ENCRYPTED_KEY_FILE = '/etc/eiou/config/.master.key.enc';

    /**
     * Plaintext master key on the persistent config volume (legacy/unprotected)
     */
    private const PLAINTEXT_KEY_FILE = '/etc/eiou/config/.master.key';

    /**
     * Runtime master key in RAM-backed filesystem (never persisted to disk)
     */
    public const RUNTIME_KEY_FILE = '/dev/shm/.master.key';

    /**
     * Encryption cipher
     */
    private const CIPHER = 'aes-256-gcm';

    /**
     * AAD context for master key encryption
     */
    private const AAD_CONTEXT = 'volume-master-key';

    /**
     * IV length for AES-256-GCM (12 bytes = 96 bits)
     */
    private const IV_LENGTH = 12;

    /**
     * GCM tag length (16 bytes = 128 bits)
     */
    private const TAG_LENGTH = 16;

    /**
     * Initialize volume encryption on startup.
     *
     * Called from startup.sh after MariaDB is ready. Handles four scenarios:
     *   1. Volume key + encrypted key exists → decrypt to /dev/shm
     *   2. Volume key + plaintext key exists → migrate: encrypt, store, decrypt to /dev/shm
     *   3. Volume key + no key exists → nothing (wallet not generated yet)
     *   4. No volume key + encrypted key exists → ERROR (can't decrypt)
     *   5. No volume key + plaintext key exists → copy to /dev/shm (backward compatible)
     *   6. No volume key + no key exists → nothing (wallet not generated yet)
     *
     * @param string|null $passphrase The volume encryption passphrase, or null if not set
     * @return string Status message for startup logging
     * @throws RuntimeException If decryption fails or required passphrase is missing
     */
    public static function init(?string $passphrase): string
    {
        $hasEncryptedKey = file_exists(self::ENCRYPTED_KEY_FILE);
        $hasPlaintextKey = file_exists(self::PLAINTEXT_KEY_FILE);
        $hasPassphrase = $passphrase !== null && $passphrase !== '';

        // Case 4: Encrypted key exists but no passphrase provided
        if ($hasEncryptedKey && !$hasPassphrase) {
            throw new RuntimeException(
                'Volume encryption is enabled but EIOU_VOLUME_KEY was not provided. '
                . 'The master key cannot be decrypted without the passphrase. '
                . 'Set EIOU_VOLUME_KEY or EIOU_VOLUME_KEY_FILE to start this node.'
            );
        }

        // Case 1: Decrypt existing encrypted key
        if ($hasEncryptedKey && $hasPassphrase) {
            self::decryptMasterKey($passphrase);

            // If plaintext key still exists from before migration, remove it
            if ($hasPlaintextKey) {
                self::secureDeleteFile(self::PLAINTEXT_KEY_FILE);
            }

            return 'Volume encryption: master key decrypted to runtime memory';
        }

        // Case 2: Migrate plaintext key to encrypted
        if ($hasPlaintextKey && $hasPassphrase) {
            self::migratePlaintextKey($passphrase);
            return 'Volume encryption: existing master key encrypted and moved to runtime memory';
        }

        // Case 5: No volume key, plaintext key exists — backward compatible mode
        if ($hasPlaintextKey && !$hasPassphrase) {
            self::copyToRuntime(self::PLAINTEXT_KEY_FILE);
            return 'Volume encryption: disabled (master key unprotected on volume)';
        }

        // Cases 3 & 6: No key exists yet (wallet not generated)
        if ($hasPassphrase) {
            return 'Volume encryption: ready (will encrypt master key after wallet generation)';
        }

        return 'Volume encryption: disabled (no volume key configured)';
    }

    /**
     * Encrypt and store the master key after wallet generation.
     *
     * Called by KeyEncryption::initMasterKeyFromSeed() when a volume key is active.
     * The plaintext key has just been written to PLAINTEXT_KEY_FILE by initMasterKeyFromSeed.
     * This method encrypts it and moves the plaintext to /dev/shm.
     *
     * @param string $passphrase The volume encryption passphrase
     * @throws RuntimeException If encryption fails
     */
    public static function encryptNewMasterKey(string $passphrase): void
    {
        if (!file_exists(self::PLAINTEXT_KEY_FILE)) {
            throw new RuntimeException('Master key file not found for volume encryption');
        }

        self::migratePlaintextKey($passphrase);
    }

    /**
     * Check if volume encryption is active (passphrase was provided).
     *
     * Reads the passphrase from /dev/shm where startup.sh stores it.
     *
     * @return bool True if volume encryption passphrase is available
     */
    public static function isActive(): bool
    {
        return file_exists('/dev/shm/.volume_key');
    }

    /**
     * Get the volume encryption passphrase from runtime memory.
     *
     * @return string|null The passphrase, or null if not set
     */
    public static function getPassphrase(): ?string
    {
        if (!file_exists('/dev/shm/.volume_key')) {
            return null;
        }

        $passphrase = file_get_contents('/dev/shm/.volume_key');
        if ($passphrase === false || $passphrase === '') {
            return null;
        }

        return $passphrase;
    }

    /**
     * Get the path where the master key is available at runtime.
     *
     * When volume encryption is active, the master key lives in /dev/shm.
     * When not active, it lives on the persistent volume (legacy behavior).
     *
     * @return string Path to the master key file
     */
    public static function getMasterKeyPath(): string
    {
        if (file_exists(self::RUNTIME_KEY_FILE)) {
            return self::RUNTIME_KEY_FILE;
        }

        return self::PLAINTEXT_KEY_FILE;
    }

    /**
     * Decrypt the encrypted master key and write it to /dev/shm.
     *
     * @param string $passphrase The volume encryption passphrase
     * @throws RuntimeException If decryption fails
     */
    private static function decryptMasterKey(string $passphrase): void
    {
        $raw = file_get_contents(self::ENCRYPTED_KEY_FILE);
        if ($raw === false) {
            throw new RuntimeException('Failed to read encrypted master key file');
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['ciphertext'], $data['iv'], $data['tag'], $data['salt'])) {
            throw new RuntimeException('Encrypted master key file is corrupted');
        }

        // Derive the encryption key from passphrase + stored salt
        $salt = base64_decode($data['salt'], true);
        if ($salt === false) {
            throw new RuntimeException('Invalid salt in encrypted master key');
        }

        $derivedKey = self::deriveKey($passphrase, $salt);

        // Decrypt
        $ciphertext = base64_decode($data['ciphertext'], true);
        $iv = base64_decode($data['iv'], true);
        $tag = base64_decode($data['tag'], true);

        if ($ciphertext === false || $iv === false || $tag === false) {
            throw new RuntimeException('Invalid base64 in encrypted master key');
        }

        $masterKey = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $derivedKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD_CONTEXT
        );

        // Clear derived key from memory
        KeyEncryption::secureClear($derivedKey);

        if ($masterKey === false) {
            throw new RuntimeException(
                'Volume key decryption failed — wrong passphrase or corrupted data. '
                . 'Verify EIOU_VOLUME_KEY matches the passphrase used when the node was created.'
            );
        }

        if (strlen($masterKey) !== 32) {
            KeyEncryption::secureClear($masterKey);
            throw new RuntimeException('Decrypted master key has invalid length');
        }

        // Write to /dev/shm (RAM-backed, never persisted to disk)
        self::writeRuntimeKey($masterKey);
        KeyEncryption::secureClear($masterKey);
    }

    /**
     * Migrate a plaintext master key to encrypted format.
     *
     * Reads the plaintext key, encrypts it, stores the encrypted version on the
     * persistent volume, writes the plaintext to /dev/shm, then securely deletes
     * the plaintext from the persistent volume.
     *
     * @param string $passphrase The volume encryption passphrase
     * @throws RuntimeException If migration fails
     */
    private static function migratePlaintextKey(string $passphrase): void
    {
        $masterKey = file_get_contents(self::PLAINTEXT_KEY_FILE);
        if ($masterKey === false || strlen($masterKey) !== 32) {
            throw new RuntimeException('Cannot read plaintext master key for migration');
        }

        // Generate random salt for Argon2id
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);

        // Derive encryption key from passphrase
        $derivedKey = self::deriveKey($passphrase, $salt);

        // Encrypt the master key
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $masterKey,
            self::CIPHER,
            $derivedKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD_CONTEXT,
            self::TAG_LENGTH
        );

        KeyEncryption::secureClear($derivedKey);

        if ($ciphertext === false) {
            KeyEncryption::secureClear($masterKey);
            throw new RuntimeException('Failed to encrypt master key: ' . openssl_error_string());
        }

        // Store encrypted key on persistent volume
        $encryptedData = json_encode([
            'version' => 1,
            'kdf' => 'argon2id',
            'salt' => base64_encode($salt),
            'opslimit' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            'memlimit' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            'ciphertext' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'aad' => self::AAD_CONTEXT,
        ]);

        $oldUmask = umask(0077);
        $result = file_put_contents(self::ENCRYPTED_KEY_FILE, $encryptedData, LOCK_EX);
        umask($oldUmask);

        if ($result === false) {
            KeyEncryption::secureClear($masterKey);
            throw new RuntimeException('Failed to write encrypted master key');
        }

        chmod(self::ENCRYPTED_KEY_FILE, 0600);
        if (posix_getuid() === 0) {
            chown(self::ENCRYPTED_KEY_FILE, 'www-data');
        }

        // Write plaintext to /dev/shm for runtime use
        self::writeRuntimeKey($masterKey);
        KeyEncryption::secureClear($masterKey);

        // Securely delete plaintext from persistent volume
        self::secureDeleteFile(self::PLAINTEXT_KEY_FILE);
    }

    /**
     * Derive a 32-byte encryption key from a passphrase using Argon2id.
     *
     * @param string $passphrase The user's passphrase
     * @param string $salt Random salt (SODIUM_CRYPTO_PWHASH_SALTBYTES bytes)
     * @return string 32-byte derived key
     * @throws RuntimeException If key derivation fails
     */
    private static function deriveKey(string $passphrase, string $salt): string
    {
        if (!function_exists('sodium_crypto_pwhash')) {
            throw new RuntimeException(
                'php-sodium extension is required for volume encryption. '
                . 'Install it with: apt-get install php-sodium'
            );
        }

        $key = sodium_crypto_pwhash(
            32, // 32 bytes for AES-256
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        if ($key === false) {
            throw new RuntimeException('Argon2id key derivation failed');
        }

        return $key;
    }

    /**
     * Write the master key to /dev/shm with secure permissions.
     *
     * @param string $key The 32-byte master key
     * @throws RuntimeException If write fails
     */
    private static function writeRuntimeKey(string $key): void
    {
        $oldUmask = umask(0077);
        $result = file_put_contents(self::RUNTIME_KEY_FILE, $key, LOCK_EX);
        umask($oldUmask);

        if ($result === false) {
            throw new RuntimeException('Failed to write master key to runtime memory (/dev/shm)');
        }

        chmod(self::RUNTIME_KEY_FILE, 0600);
        if (posix_getuid() === 0) {
            chown(self::RUNTIME_KEY_FILE, 'www-data');
        }
    }

    /**
     * Copy a key file to /dev/shm for runtime use.
     *
     * @param string $sourcePath Path to the key file
     * @throws RuntimeException If copy fails
     */
    private static function copyToRuntime(string $sourcePath): void
    {
        $key = file_get_contents($sourcePath);
        if ($key === false) {
            throw new RuntimeException("Failed to read key file: $sourcePath");
        }

        self::writeRuntimeKey($key);
        KeyEncryption::secureClear($key);
    }

    /**
     * Securely delete a file by overwriting its contents before unlinking.
     *
     * @param string $path Path to the file to delete
     */
    private static function secureDeleteFile(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $size = filesize($path);
        if ($size > 0) {
            // Overwrite with random data
            $fh = fopen($path, 'r+');
            if ($fh !== false) {
                fwrite($fh, random_bytes($size));
                fflush($fh);
                fclose($fh);
            }

            // Overwrite with zeros
            $fh = fopen($path, 'r+');
            if ($fh !== false) {
                fwrite($fh, str_repeat("\0", $size));
                fflush($fh);
                fclose($fh);
            }
        }

        unlink($path);
    }

    /**
     * Check if the sodium extension is available for volume encryption.
     *
     * @return bool True if sodium is available
     */
    public static function isAvailable(): bool
    {
        return function_exists('sodium_crypto_pwhash')
            && extension_loaded('openssl')
            && in_array(self::CIPHER, openssl_get_cipher_methods());
    }

    /**
     * Get volume encryption status for diagnostics.
     *
     * @return array Status information
     */
    public static function getStatus(): array
    {
        return [
            'available' => self::isAvailable(),
            'active' => self::isActive(),
            'encrypted_key_exists' => file_exists(self::ENCRYPTED_KEY_FILE),
            'plaintext_key_exists' => file_exists(self::PLAINTEXT_KEY_FILE),
            'runtime_key_exists' => file_exists(self::RUNTIME_KEY_FILE),
            'sodium_available' => function_exists('sodium_crypto_pwhash'),
        ];
    }
}
