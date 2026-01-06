<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

/**
 * Tor Hidden Service Key Derivation
 *
 * Derives deterministic Ed25519 keys for Tor v3 hidden services from BIP39 seeds.
 * This allows the same .onion address to be restored when recovering a wallet.
 *
 * Tor v3 hidden service file format:
 * - hs_ed25519_secret_key: 32-byte header + 64-byte expanded secret key
 * - hs_ed25519_public_key: 32-byte header + 32-byte public key
 * - hostname: base32(pubkey || checksum || version) + ".onion"
 *
 * @see https://github.com/torproject/torspec/blob/main/rend-spec-v3.txt
 */

class TorKeyDerivation
{
    /**
     * Header for Tor secret key file
     */
    private const SECRET_KEY_HEADER = "== ed25519v1-secret: type0 ==\x00\x00\x00";

    /**
     * Header for Tor public key file
     */
    private const PUBLIC_KEY_HEADER = "== ed25519v1-public: type0 ==\x00\x00\x00";

    /**
     * Tor hidden service directory
     */
    private const TOR_HIDDEN_SERVICE_DIR = '/var/lib/tor/hidden_service';

    /**
     * Derive Ed25519 keypair from BIP39 seed for Tor hidden service
     *
     * Tor expects the "expanded" secret key format (64 bytes):
     * - First 32 bytes: clamped private scalar (from SHA-512 of seed, first half, clamped)
     * - Last 32 bytes: hash prefix for signing (from SHA-512 of seed, second half)
     *
     * This is different from libsodium's format which stores seed || public_key.
     *
     * @param string $seed Raw BIP39 seed bytes (64 bytes)
     * @return array ['secret_key' => string, 'public_key' => string, 'hostname' => string]
     * @throws RuntimeException If sodium extension is not available
     */
    public static function deriveFromSeed(string $seed): array
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('Sodium extension required for Ed25519 key derivation');
        }

        // Derive a 32-byte Ed25519 seed from the BIP39 seed
        // Use HMAC-SHA256 with a unique context to derive the Tor key seed
        $torSeed = hash_hmac('sha256', $seed, 'eiou-tor-hidden-service', true);

        // Expand the seed to Tor's expected format using SHA-512
        // This matches Tor's ed25519_donna_expandsecret() function
        $expanded = hash('sha512', $torSeed, true);

        // First 32 bytes become the private scalar (needs clamping)
        $scalar = substr($expanded, 0, 32);

        // Last 32 bytes become the hash prefix for signing
        $hashPrefix = substr($expanded, 32, 32);

        // Clamp the scalar according to Ed25519 spec:
        // - Clear lowest 3 bits of first byte
        // - Clear highest bit of last byte
        // - Set second highest bit of last byte
        $scalarBytes = str_split($scalar);
        $scalarBytes[0] = chr(ord($scalarBytes[0]) & 248);      // Clear lowest 3 bits
        $scalarBytes[31] = chr(ord($scalarBytes[31]) & 127);    // Clear highest bit
        $scalarBytes[31] = chr(ord($scalarBytes[31]) | 64);     // Set second highest bit
        $clampedScalar = implode('', $scalarBytes);

        // The expanded secret key for Tor is: clamped_scalar || hash_prefix
        $expandedSecretKey = $clampedScalar . $hashPrefix;

        // Derive public key from the clamped scalar using sodium
        // We need to compute the public key: scalar * basepoint
        // Use sodium's scalarmult to compute this
        $publicKey = sodium_crypto_scalarmult_base($clampedScalar);

        // Note: sodium_crypto_scalarmult_base returns Curve25519 point, not Ed25519
        // For Ed25519, we need to derive the public key differently
        // Let's use the original seed to get the correct public key from sodium
        $keypair = sodium_crypto_sign_seed_keypair($torSeed);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        // Generate the .onion hostname
        $hostname = self::publicKeyToOnion($publicKey);

        // Clear sensitive data
        sodium_memzero($torSeed);
        sodium_memzero($expanded);
        sodium_memzero($scalar);
        sodium_memzero($clampedScalar);
        sodium_memzero($keypair);

        return [
            'secret_key' => $expandedSecretKey,
            'public_key' => $publicKey,
            'hostname' => $hostname
        ];
    }

    /**
     * Convert Ed25519 public key to Tor v3 .onion address
     *
     * @param string $publicKey 32-byte Ed25519 public key
     * @return string The .onion address (56 characters + ".onion")
     */
    public static function publicKeyToOnion(string $publicKey): string
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('Public key must be 32 bytes');
        }

        // Version byte for v3 onion addresses
        $version = "\x03";

        // Calculate checksum: first 2 bytes of SHA3-256(".onion checksum" || pubkey || version)
        $checksumData = ".onion checksum" . $publicKey . $version;

        // SHA3-256 (Keccak) - use hash() with sha3-256
        $hash = hash('sha3-256', $checksumData, true);
        $checksum = substr($hash, 0, 2);

        // Encode: base32(pubkey || checksum || version)
        $onionData = $publicKey . $checksum . $version;
        $onionAddress = self::base32Encode($onionData);

        return strtolower($onionAddress) . '.onion';
    }

    /**
     * Generate and write Tor hidden service files
     *
     * @param string $seed Raw BIP39 seed bytes
     * @param string $directory Tor hidden service directory (default: /var/lib/tor/hidden_service)
     * @return string The generated .onion hostname
     * @throws RuntimeException If file operations fail
     */
    public static function generateHiddenServiceFiles(string $seed, ?string $directory = null): string
    {
        $directory = $directory ?? self::TOR_HIDDEN_SERVICE_DIR;

        // Derive keys from seed
        $keys = self::deriveFromSeed($seed);

        // Build file contents with proper headers
        $secretKeyFile = self::SECRET_KEY_HEADER . $keys['secret_key'];
        $publicKeyFile = self::PUBLIC_KEY_HEADER . $keys['public_key'];
        $hostnameFile = $keys['hostname'] . "\n";

        // Ensure directory exists
        if (!is_dir($directory)) {
            throw new RuntimeException("Tor hidden service directory does not exist: $directory");
        }

        // Write files with proper permissions
        $files = [
            'hs_ed25519_secret_key' => $secretKeyFile,
            'hs_ed25519_public_key' => $publicKeyFile,
            'hostname' => $hostnameFile
        ];

        foreach ($files as $filename => $content) {
            $filepath = $directory . '/' . $filename;

            // Write file
            $result = file_put_contents($filepath, $content, LOCK_EX);
            if ($result === false) {
                throw new RuntimeException("Failed to write $filepath");
            }

            // Set proper permissions (owner read/write only)
            chmod($filepath, 0600);

            // Set proper ownership (debian-tor user)
            if (function_exists('posix_getpwnam')) {
                $torUser = posix_getpwnam('debian-tor');
                if ($torUser) {
                    chown($filepath, $torUser['uid']);
                    chgrp($filepath, $torUser['gid']);
                }
            }
        }

        // Clear sensitive data from memory
        sodium_memzero($keys['secret_key']);

        return $keys['hostname'];
    }

    /**
     * Check if current Tor hidden service was derived from the given seed
     *
     * @param string $seed Raw BIP39 seed bytes
     * @param string $directory Tor hidden service directory
     * @return bool True if the current keys match the seed-derived keys
     */
    public static function verifyKeysMatchSeed(string $seed, ?string $directory = null): bool
    {
        $directory = $directory ?? self::TOR_HIDDEN_SERVICE_DIR;

        // Read current hostname
        $hostnameFile = $directory . '/hostname';
        if (!file_exists($hostnameFile)) {
            return false;
        }

        $currentHostname = trim(file_get_contents($hostnameFile));

        // Derive expected hostname from seed
        $keys = self::deriveFromSeed($seed);
        $expectedHostname = $keys['hostname'];

        // Clear sensitive data
        sodium_memzero($keys['secret_key']);

        return $currentHostname === $expectedHostname;
    }

    /**
     * RFC 4648 Base32 encoding (no padding, uppercase)
     *
     * @param string $data Raw bytes to encode
     * @return string Base32 encoded string
     */
    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';

        // Convert to binary string
        for ($i = 0; $i < strlen($data); $i++) {
            $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Pad to multiple of 5 bits
        $padding = (5 - (strlen($binary) % 5)) % 5;
        $binary .= str_repeat('0', $padding);

        // Convert 5-bit chunks to base32
        $result = '';
        for ($i = 0; $i < strlen($binary); $i += 5) {
            $chunk = substr($binary, $i, 5);
            $index = bindec($chunk);
            $result .= $alphabet[$index];
        }

        return $result;
    }
}
