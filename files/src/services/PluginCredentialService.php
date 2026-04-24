<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Database\PluginCredentialRepository;
use Eiou\Security\KeyEncryption;
use Eiou\Utils\Logger;
use InvalidArgumentException;
use RuntimeException;

/**
 * PluginCredentialService
 *
 * Generates, stores, retrieves, rotates, and deletes the MySQL password used
 * by each plugin's isolated DB user. Passwords are 32 bytes of `random_bytes`
 * base64-encoded (~42 chars), wrapped via `KeyEncryption` with the plugin id
 * as AAD so the ciphertext is bound to its row and cannot be swapped between
 * plugins.
 *
 * This service is the ONLY caller that should touch plaintext plugin
 * passwords. Downstream (user creation, PDO factory) always calls
 * getPlaintext() — the plaintext never lives outside a single function scope.
 *
 * See docs/PLUGIN_ISOLATION.md §1 and §12.
 */
class PluginCredentialService
{
    /**
     * Matches the plugin-id regex enforced everywhere else: kebab-case,
     * 1–64 chars, lowercase alphanumerics + hyphens. Prevents bad inputs
     * from landing in the plugin_id column and prevents them being used
     * as AAD context unchecked.
     */
    public const PLUGIN_ID_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/';

    /**
     * Password generation size. 32 raw bytes → 44 base64 chars including
     * padding, comfortably above MySQL's minimum-complexity requirements
     * and well below its max (native_password = 41 hashed, caching_sha2
     * = unlimited plaintext acceptance before hashing).
     */
    private const PASSWORD_RAW_BYTES = 32;

    /** AAD context domain — so a plugin credential envelope cannot be
     *  reused to decrypt (or re-wrap) a non-plugin context if a future
     *  caller ever shares the master key in a different domain.
     */
    private const AAD_DOMAIN = 'plugin_credential:';

    private PluginCredentialRepository $repo;
    private ?Logger $logger;

    public function __construct(
        PluginCredentialRepository $repo,
        ?Logger $logger = null
    ) {
        $this->repo = $repo;
        $this->logger = $logger;
    }

    /**
     * Generate a new password for a plugin that doesn't yet have one.
     * Returns the plaintext password for the caller to pass to
     * `CREATE USER ... IDENTIFIED BY` — the same plaintext is NOT
     * retrievable from the returned row; callers must save it if they
     * need it later (typical path: create user immediately, then discard).
     *
     * Throws if credentials already exist — use rotate() to replace.
     *
     * @throws InvalidArgumentException Invalid plugin id
     * @throws RuntimeException Credentials already exist, or persistence failed
     */
    public function generate(string $pluginId): string
    {
        $this->validatePluginId($pluginId);

        if ($this->repo->existsForPlugin($pluginId)) {
            throw new RuntimeException(
                "Credentials already exist for plugin '{$pluginId}'; use rotate()"
            );
        }

        $plaintext = $this->generatePlaintext();
        $envelope = $this->encryptPassword($plaintext, $pluginId);
        $encoded = json_encode($envelope);
        if ($encoded === false) {
            throw new RuntimeException('Failed to JSON-encode encryption envelope');
        }

        if (!$this->repo->createCredential($pluginId, $encoded)) {
            throw new RuntimeException("Failed to persist credentials for '{$pluginId}'");
        }

        $this->log('info', 'plugin_credentials_generated', ['plugin_id' => $pluginId]);
        return $plaintext;
    }

    /**
     * Return the plaintext password for a plugin that already has
     * credentials. Used by the boot-time reconciler to replay CREATE USER
     * IDENTIFIED BY on every restart without generating a new password.
     *
     * Returns null if the plugin has no credentials row — caller decides
     * whether that's a fatal condition or a cue to generate().
     *
     * @throws RuntimeException Decryption failed (typically: wrong master key)
     */
    public function getPlaintext(string $pluginId): ?string
    {
        $this->validatePluginId($pluginId);

        $row = $this->repo->getByPluginId($pluginId);
        if ($row === null) {
            return null;
        }
        $envelope = $this->decodeEnvelope((string) $row['encrypted_password']);
        try {
            return $this->decryptPassword($envelope, $pluginId);
        } catch (\Throwable $e) {
            // KeyEncryption verifies AAD automatically — a mismatch here means
            // the envelope was either written for a different plugin (AAD
            // binding caught it) or the master key doesn't match what wrote
            // the row. Both are unrecoverable without operator intervention.
            $this->log('error', 'plugin_credentials_decrypt_failed', [
                'plugin_id' => $pluginId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                "Failed to decrypt credentials for plugin '{$pluginId}'",
                0,
                $e
            );
        }
    }

    /**
     * Replace a plugin's password with a freshly-generated one. Returns the
     * new plaintext so the caller can feed it to `ALTER USER ... IDENTIFIED BY`.
     *
     * Throws if the plugin has no existing credentials — call generate() instead.
     */
    public function rotate(string $pluginId): string
    {
        $this->validatePluginId($pluginId);

        if (!$this->repo->existsForPlugin($pluginId)) {
            throw new RuntimeException(
                "No credentials to rotate for plugin '{$pluginId}'; use generate()"
            );
        }

        $plaintext = $this->generatePlaintext();
        $envelope = $this->encryptPassword($plaintext, $pluginId);
        $encoded = json_encode($envelope);
        if ($encoded === false) {
            throw new RuntimeException('Failed to JSON-encode encryption envelope');
        }

        $affected = $this->repo->rotatePassword($pluginId, $encoded);
        if ($affected === 0) {
            // Concurrent delete between our existsForPlugin() and the UPDATE.
            // Exceedingly unlikely — but if it happens, surface it clearly
            // rather than silently discarding the new password.
            throw new RuntimeException(
                "Rotation affected 0 rows for plugin '{$pluginId}' — concurrent delete?"
            );
        }

        $this->log('info', 'plugin_credentials_rotated', ['plugin_id' => $pluginId]);
        return $plaintext;
    }

    /**
     * Delete a plugin's credential row. Used at uninstall after the MySQL
     * user has been dropped. Returns true if a row was removed, false if
     * there was nothing to remove.
     */
    public function delete(string $pluginId): bool
    {
        $this->validatePluginId($pluginId);
        $affected = $this->repo->deleteCredential($pluginId);
        if ($affected > 0) {
            $this->log('info', 'plugin_credentials_deleted', ['plugin_id' => $pluginId]);
        }
        return $affected > 0;
    }

    public function exists(string $pluginId): bool
    {
        $this->validatePluginId($pluginId);
        return $this->repo->existsForPlugin($pluginId);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Wrap a plaintext password via KeyEncryption with the plugin id baked
     * into the AAD. Protected so tests can substitute a deterministic codec
     * (same pattern `PaybackMethodService` uses for its encrypted_fields
     * blob — avoids needing a master key on disk during unit tests).
     *
     * @return array<string, mixed> KeyEncryption envelope
     */
    protected function encryptPassword(string $plaintext, string $pluginId): array
    {
        return KeyEncryption::encrypt($plaintext, $this->aadFor($pluginId));
    }

    /**
     * Inverse of encryptPassword(). AAD mismatch is detected by KeyEncryption
     * automatically (openssl_decrypt returns false with mismatched AAD); we
     * still double-check the `aad` field in the envelope matches the caller's
     * expected plugin id so a corrupted row cannot return the wrong plugin's
     * password even if the master key accepts both.
     */
    protected function decryptPassword(array $envelope, string $pluginId): string
    {
        $expectedAad = $this->aadFor($pluginId);
        $storedAad = $envelope['aad'] ?? null;
        if (is_string($storedAad) && $storedAad !== $expectedAad) {
            throw new RuntimeException(
                "Credential envelope AAD mismatch: expected '{$expectedAad}', got '{$storedAad}'"
            );
        }
        return KeyEncryption::decrypt($envelope);
    }

    private function validatePluginId(string $pluginId): void
    {
        if (!preg_match(self::PLUGIN_ID_PATTERN, $pluginId)) {
            throw new InvalidArgumentException(
                "Invalid plugin id '{$pluginId}': must be kebab-case, 1-64 chars"
            );
        }
    }

    private function aadFor(string $pluginId): string
    {
        return self::AAD_DOMAIN . $pluginId;
    }

    private function generatePlaintext(): string
    {
        return base64_encode(random_bytes(self::PASSWORD_RAW_BYTES));
    }

    /**
     * Decode a stored envelope string into the array shape KeyEncryption wants.
     * The column is stored as JSON but PDO might return it as a string or as
     * a pre-decoded array depending on MySQL version + PDO fetch mode.
     *
     * @param string $raw
     * @return array<string, mixed>
     */
    private function decodeEnvelope(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Stored credential envelope is not a JSON object');
        }
        return $decoded;
    }

    private function log(string $level, string $event, array $ctx = []): void
    {
        if ($this->logger === null) {
            return;
        }
        $this->logger->$level($event, $ctx);
    }
}
