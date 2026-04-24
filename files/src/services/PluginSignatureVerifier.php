<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use InvalidArgumentException;
use RuntimeException;

/**
 * PluginSignatureVerifier
 *
 * Verifies Ed25519 detached signatures over a plugin's manifest + source
 * tree. Plugins that ship with a valid signature against a trusted public
 * key can be loaded; unsigned plugins (or plugins signed with an untrusted
 * key) are rejected when signature enforcement is on.
 *
 * Trust model — two layers, both scanned:
 *
 *   1. Baked-in first-party keys at /app/eiou/config/trusted-plugin-keys/
 *      (read-only, shipped inside the Docker image). This is where the
 *      eIOU release signer's key lives, so bundled and official plugins
 *      load without operator action.
 *
 *   2. Operator-added third-party keys at /etc/eiou/config/trusted-plugin-keys/
 *      (on the config volume). Operators drop in `.pub` files they
 *      explicitly trust — same protection model as wallet keys.
 *
 * Adding a trusted key is a deliberate operator action that says "I trust
 * whoever holds the corresponding private key to publish plugins on my
 * node." The verifier cannot distinguish "this plugin is safe" from
 * "this key is trusted" — trust is a human decision, signatures are the
 * machine-enforceable bit.
 *
 * Plugin signature file (`plugin.sig`), JSON object:
 *
 *   {
 *     "algorithm": "ed25519",
 *     "key_fingerprint": "sha256:<hex>",
 *     "signature": "<base64 raw 64-byte signature>"
 *   }
 *
 * Signed payload (deterministic across filesystems):
 *
 *   plugin.json bytes  +  0x00  +  src-tree-hash hex
 *
 * where src-tree-hash = SHA-256 over concat of, for every file under src/
 * in sorted path order:
 *
 *   relpath  +  0x00  +  SHA-256(content)  +  0x00
 *
 * Any byte change in the manifest or any source file invalidates the
 * signature on next verification pass.
 */
class PluginSignatureVerifier
{
    /** Baked into the Docker image — first-party / bundled trust. */
    public const BUILTIN_KEYS_DIR = '/app/eiou/config/trusted-plugin-keys';

    /** Operator-added keys on the config volume. */
    public const OPERATOR_KEYS_DIR = '/etc/eiou/config/trusted-plugin-keys';

    /**
     * Enforcement modes:
     *   - 'off'     — don't verify; treat every plugin as trusted (legacy)
     *   - 'warn'    — verify but log failures without blocking load
     *   - 'require' — verify and refuse to load any plugin that fails
     */
    public const MODE_OFF     = 'off';
    public const MODE_WARN    = 'warn';
    public const MODE_REQUIRE = 'require';

    private array $trustedKeyDirs;
    private ?Logger $logger;

    /** @var array<string, string>|null Fingerprint → raw 32-byte pubkey. Lazy-loaded. */
    private ?array $trustedKeys = null;

    /**
     * @param array<int, string>|null $trustedKeyDirs Override for tests; null = defaults
     */
    public function __construct(?array $trustedKeyDirs = null, ?Logger $logger = null)
    {
        $this->trustedKeyDirs = $trustedKeyDirs ?? [
            self::BUILTIN_KEYS_DIR,
            self::OPERATOR_KEYS_DIR,
        ];
        $this->logger = $logger;
    }

    /**
     * Verify a plugin at the given directory. Returns a structured result
     * so the caller can make its own mode decision rather than this class
     * fixing one.
     *
     * @return array{
     *   status: 'ok'|'unsigned'|'untrusted_key'|'bad_signature'|'malformed_sig'|'malformed_manifest',
     *   key_fingerprint?: string,
     *   error?: string
     * }
     */
    public function verify(string $pluginPath): array
    {
        $manifestPath = $pluginPath . '/plugin.json';
        $sigPath = $pluginPath . '/plugin.sig';

        if (!is_file($manifestPath)) {
            return ['status' => 'malformed_manifest', 'error' => 'plugin.json missing'];
        }
        if (!is_file($sigPath)) {
            return ['status' => 'unsigned'];
        }

        $sigRaw = @file_get_contents($sigPath);
        if ($sigRaw === false) {
            return ['status' => 'malformed_sig', 'error' => 'unable to read plugin.sig'];
        }
        $sigObj = json_decode($sigRaw, true);
        if (!is_array($sigObj)) {
            return ['status' => 'malformed_sig', 'error' => 'plugin.sig is not valid JSON'];
        }

        $algo = (string) ($sigObj['algorithm'] ?? '');
        $fp = (string) ($sigObj['key_fingerprint'] ?? '');
        $sigB64 = (string) ($sigObj['signature'] ?? '');
        if ($algo !== 'ed25519') {
            return ['status' => 'malformed_sig', 'error' => "unsupported algorithm '{$algo}'"];
        }
        if ($fp === '' || $sigB64 === '') {
            return ['status' => 'malformed_sig', 'error' => 'missing key_fingerprint or signature'];
        }

        $signature = base64_decode($sigB64, true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return ['status' => 'malformed_sig', 'error' => 'signature is not base64 of 64 bytes'];
        }

        $this->loadTrustedKeys();
        if (!isset($this->trustedKeys[$fp])) {
            return ['status' => 'untrusted_key', 'key_fingerprint' => $fp];
        }

        $pubkey = $this->trustedKeys[$fp];
        $message = $this->buildSignedMessage($pluginPath);

        // sodium_crypto_sign_verify_detached returns a bool — true on valid.
        // It is a constant-time comparison so a timing side channel cannot
        // distinguish "wrong signature" from "wrong key".
        $ok = sodium_crypto_sign_verify_detached($signature, $message, $pubkey);
        if (!$ok) {
            return ['status' => 'bad_signature', 'key_fingerprint' => $fp];
        }
        return ['status' => 'ok', 'key_fingerprint' => $fp];
    }

    /**
     * Build the canonical signed payload for a plugin directory.
     *
     * Structure (byte-level): plugin.json bytes || 0x00 || sha256-hex-of-src-tree
     *
     * The src tree hash is deterministic across platforms: sort paths
     * lexicographically, then for each file emit `relpath \0 sha256 \0`,
     * then SHA-256 the whole thing. Same algorithm the sign-plugin CLI
     * uses.
     */
    public function buildSignedMessage(string $pluginPath): string
    {
        $manifestPath = $pluginPath . '/plugin.json';
        $manifestBytes = @file_get_contents($manifestPath);
        if ($manifestBytes === false) {
            throw new RuntimeException("Cannot read {$manifestPath}");
        }
        $srcHashHex = $this->hashSrcTree($pluginPath);
        return $manifestBytes . "\0" . $srcHashHex;
    }

    /**
     * SHA-256 of the plugin's `src/` directory. Missing src dir hashes
     * the empty string so plugins with no autoloaded code still have a
     * defined signature base.
     */
    public function hashSrcTree(string $pluginPath): string
    {
        $srcDir = $pluginPath . '/src';
        if (!is_dir($srcDir)) {
            return hash('sha256', '');
        }
        $files = [];
        $this->collectFiles($srcDir, '', $files);
        sort($files, SORT_STRING);

        $buf = '';
        foreach ($files as $rel) {
            $abs = $srcDir . '/' . $rel;
            $content = @file_get_contents($abs);
            if ($content === false) {
                throw new RuntimeException("Cannot read plugin source file: {$abs}");
            }
            $buf .= $rel . "\0" . hash('sha256', $content) . "\0";
        }
        return hash('sha256', $buf);
    }

    /**
     * Return the per-key fingerprint map the verifier has loaded. Useful
     * for diagnostics and the plugin-list API (so operators can see
     * which key signed each plugin).
     *
     * @return list<string>
     */
    public function listTrustedFingerprints(): array
    {
        $this->loadTrustedKeys();
        return array_keys($this->trustedKeys);
    }

    /**
     * Compute the fingerprint of a raw Ed25519 public key — the same
     * form the signing CLI emits and the trust directory expects.
     * Format: `sha256:<lower-hex>`.
     */
    public static function fingerprintOf(string $rawPubkey): string
    {
        if (strlen($rawPubkey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('Raw Ed25519 public key must be 32 bytes');
        }
        return 'sha256:' . hash('sha256', $rawPubkey);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Recursively collect every regular file under $dir. Returns paths
     * relative to $dir using forward slashes.
     *
     * @param list<string> $out Populated in place.
     */
    private function collectFiles(string $dir, string $prefix, array &$out): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $abs = $dir . '/' . $e;
            $rel = $prefix === '' ? $e : $prefix . '/' . $e;
            if (is_dir($abs)) {
                $this->collectFiles($abs, $rel, $out);
            } elseif (is_file($abs)) {
                $out[] = $rel;
            }
            // Symlinks and special files are deliberately ignored — a plugin
            // that relies on them for signed content is broken by design.
        }
    }

    /**
     * Populate $this->trustedKeys on first use. Scans both configured
     * key dirs; each `.pub` file may contain multiple keys (base64 per
     * non-comment line) so operators can batch-add publishers without
     * stacking many tiny files. Duplicate fingerprints across files are
     * de-duplicated silently.
     */
    private function loadTrustedKeys(): void
    {
        if ($this->trustedKeys !== null) {
            return;
        }
        $map = [];
        foreach ($this->trustedKeyDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = @scandir($dir);
            if ($files === false) {
                continue;
            }
            foreach ($files as $f) {
                if ($f === '.' || $f === '..' || !str_ends_with($f, '.pub')) {
                    continue;
                }
                $raw = @file_get_contents($dir . '/' . $f);
                if ($raw === false) {
                    continue;
                }
                foreach ($this->extractPubkeys($raw) as $pubkey) {
                    $fp = self::fingerprintOf($pubkey);
                    $map[$fp] = $pubkey;
                }
            }
        }
        $this->trustedKeys = $map;
        $this->log('debug', 'plugin_trust_keys_loaded', ['count' => count($map)]);
    }

    /**
     * Parse a `.pub` file's contents. Comment lines (`#...`) and blank
     * lines are ignored; every other non-empty line must be base64 of a
     * 32-byte Ed25519 public key.
     *
     * @return list<string> Raw 32-byte public keys.
     */
    private function extractPubkeys(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $bytes = base64_decode($line, true);
            if ($bytes !== false && strlen($bytes) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $out[] = $bytes;
            }
        }
        return $out;
    }

    private function log(string $level, string $event, array $ctx = []): void
    {
        if ($this->logger !== null) {
            $this->logger->$level($event, $ctx);
        }
    }
}
