<?php
# Copyright 2025-2026 Vowels Group, LLC

declare(strict_types=1);

/**
 * plugin-sign.php — generate Ed25519 keypairs, sign plugins, and verify
 * signatures without the full eIOU runtime. Bundled inside the image at
 * /app/eiou/scripts/plugin-sign.php and usable from any shell.
 *
 * Usage:
 *
 *   php plugin-sign.php generate-key
 *       Generates a new Ed25519 keypair. Writes two files to the current
 *       working directory:
 *
 *         <fingerprint>.key   — private key (KEEP SECRET; drop mode 0600)
 *         <fingerprint>.pub   — public key (share with operators to mark
 *                               your plugins as trusted)
 *
 *       Both files carry a leading comment line with the fingerprint so
 *       operators can match them later. The fingerprint is the SHA-256 of
 *       the raw 32-byte public key, rendered as `sha256:<hex>`.
 *
 *   php plugin-sign.php sign --key=<path> --plugin=<dir>
 *       Signs the plugin directory at <dir> and writes plugin.sig next to
 *       plugin.json. The signed payload covers plugin.json bytes + a
 *       deterministic hash of the src/ tree — any change to manifest or
 *       source invalidates the signature on next verification pass.
 *
 *   php plugin-sign.php verify --plugin=<dir> [--keys=<dir>]
 *       Verifies the plugin's signature against one or more trusted key
 *       directories (default: /app/eiou/config/trusted-plugin-keys and
 *       /etc/eiou/config/trusted-plugin-keys). Exit code 0 on success, 1
 *       on any failure — scriptable for pre-release CI.
 *
 * This script does not require the eIOU runtime classloader — it reuses
 * the same canonical message-construction logic as PluginSignatureVerifier
 * but re-implemented in ~60 LOC so contributors can audit the signing
 * path independently of the verifier.
 *
 * Wire format (same as PluginSignatureVerifier consumes):
 *
 *   plugin.sig (JSON):
 *     { "algorithm": "ed25519",
 *       "key_fingerprint": "sha256:<hex>",
 *       "signature": "<base64 raw 64 bytes>" }
 *
 *   trusted-plugin-keys/*.pub (text):
 *     # optional human-readable comments, prefixed with #
 *     <base64 raw 32-byte Ed25519 public key>
 *
 *   private-key file (same text format — operator's responsibility to
 *   keep the file mode-600 and out of backups / CI images).
 */

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "sodium extension is required (PHP 7.2+ bundles it)\n");
    exit(2);
}

$argv = $_SERVER['argv'] ?? $GLOBALS['argv'];
$cmd = $argv[1] ?? 'help';

switch ($cmd) {
    case 'generate-key': cmdGenerateKey(); break;
    case 'sign':         cmdSign(parseFlags($argv)); break;
    case 'verify':       cmdVerify(parseFlags($argv)); break;
    case 'help':
    case '-h':
    case '--help':
    default:             printHelp(); exit($cmd === 'help' || $cmd === '-h' || $cmd === '--help' ? 0 : 1);
}

// ---------------------------------------------------------------------------
// Subcommands
// ---------------------------------------------------------------------------

function cmdGenerateKey(): void
{
    $kp = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($kp);
    $pk = sodium_crypto_sign_publickey($kp);
    $fp = 'sha256:' . hash('sha256', $pk);
    $fpShort = substr(hash('sha256', $pk), 0, 16);

    $pubFile = $fpShort . '.pub';
    $keyFile = $fpShort . '.key';

    $pubContent =
        "# eIOU plugin signing public key\n"
        . "# fingerprint: {$fp}\n"
        . "# issued: " . gmdate('Y-m-d') . "\n"
        . base64_encode($pk) . "\n";

    $keyContent =
        "# eIOU plugin SIGNING PRIVATE KEY — keep secret, chmod 600, don't commit.\n"
        . "# fingerprint: {$fp}\n"
        . "# issued: " . gmdate('Y-m-d') . "\n"
        . base64_encode($sk) . "\n";

    if (file_exists($pubFile) || file_exists($keyFile)) {
        fwrite(STDERR, "refusing to overwrite existing {$pubFile} / {$keyFile}\n");
        exit(1);
    }
    file_put_contents($pubFile, $pubContent);
    file_put_contents($keyFile, $keyContent);
    @chmod($keyFile, 0600);

    fwrite(STDOUT, "Wrote {$pubFile} (share with operators)\n");
    fwrite(STDOUT, "Wrote {$keyFile} (private — chmod 600 applied)\n");
    fwrite(STDOUT, "Fingerprint: {$fp}\n");
}

function cmdSign(array $flags): void
{
    $keyPath = $flags['key'] ?? null;
    $pluginDir = $flags['plugin'] ?? null;
    if ($keyPath === null || $pluginDir === null) {
        fwrite(STDERR, "Usage: plugin-sign.php sign --key=<file> --plugin=<dir>\n");
        exit(1);
    }

    $sk = readKeyFile($keyPath, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'private');
    $pk = sodium_crypto_sign_publickey_from_secretkey($sk);
    $fp = 'sha256:' . hash('sha256', $pk);

    $pluginDir = rtrim($pluginDir, '/');
    if (!is_dir($pluginDir) || !is_file($pluginDir . '/plugin.json')) {
        fwrite(STDERR, "Not a plugin directory (missing plugin.json): {$pluginDir}\n");
        exit(1);
    }

    $message = buildSignedMessage($pluginDir);
    $signature = sodium_crypto_sign_detached($message, $sk);
    $payload = json_encode([
        'algorithm' => 'ed25519',
        'key_fingerprint' => $fp,
        'signature' => base64_encode($signature),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    file_put_contents($pluginDir . '/plugin.sig', $payload);
    fwrite(STDOUT, "Signed {$pluginDir}/plugin.sig with key {$fp}\n");
}

function cmdVerify(array $flags): void
{
    $pluginDir = $flags['plugin'] ?? null;
    if ($pluginDir === null) {
        fwrite(STDERR, "Usage: plugin-sign.php verify --plugin=<dir> [--keys=<dir>[,<dir>]]\n");
        exit(1);
    }

    $keyDirs = isset($flags['keys'])
        ? explode(',', $flags['keys'])
        : [
            '/app/eiou/config/trusted-plugin-keys',
            '/etc/eiou/config/trusted-plugin-keys',
        ];

    $pluginDir = rtrim($pluginDir, '/');
    $sigPath = $pluginDir . '/plugin.sig';
    if (!is_file($sigPath)) {
        fwrite(STDERR, "unsigned (no plugin.sig)\n");
        exit(1);
    }
    $sigObj = json_decode((string) file_get_contents($sigPath), true);
    if (!is_array($sigObj)) {
        fwrite(STDERR, "malformed plugin.sig\n");
        exit(1);
    }
    if (($sigObj['algorithm'] ?? '') !== 'ed25519') {
        fwrite(STDERR, "unsupported algorithm\n");
        exit(1);
    }
    $fp = (string) ($sigObj['key_fingerprint'] ?? '');
    $sigRaw = base64_decode((string) ($sigObj['signature'] ?? ''), true);
    if ($sigRaw === false || strlen($sigRaw) !== SODIUM_CRYPTO_SIGN_BYTES) {
        fwrite(STDERR, "malformed signature bytes\n");
        exit(1);
    }

    $trust = loadTrustedKeys($keyDirs);
    if (!isset($trust[$fp])) {
        fwrite(STDERR, "untrusted key: {$fp}\n");
        exit(1);
    }
    $message = buildSignedMessage($pluginDir);
    $ok = sodium_crypto_sign_verify_detached($sigRaw, $message, $trust[$fp]);
    if (!$ok) {
        fwrite(STDERR, "signature failed (key {$fp})\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$pluginDir} signed by {$fp}\n");
    exit(0);
}

// ---------------------------------------------------------------------------
// Helpers (self-contained — no framework autoload)
// ---------------------------------------------------------------------------

function buildSignedMessage(string $pluginDir): string
{
    $manifest = (string) file_get_contents($pluginDir . '/plugin.json');
    return $manifest . "\0" . hashSrcTree($pluginDir);
}

function hashSrcTree(string $pluginDir): string
{
    $srcDir = $pluginDir . '/src';
    if (!is_dir($srcDir)) {
        return hash('sha256', '');
    }
    $files = [];
    collectFiles($srcDir, '', $files);
    sort($files, SORT_STRING);
    $buf = '';
    foreach ($files as $rel) {
        $content = (string) file_get_contents($srcDir . '/' . $rel);
        $buf .= $rel . "\0" . hash('sha256', $content) . "\0";
    }
    return hash('sha256', $buf);
}

function collectFiles(string $dir, string $prefix, array &$out): void
{
    foreach ((array) scandir($dir) as $e) {
        if ($e === '.' || $e === '..') continue;
        $abs = $dir . '/' . $e;
        $rel = $prefix === '' ? $e : $prefix . '/' . $e;
        if (is_dir($abs)) collectFiles($abs, $rel, $out);
        elseif (is_file($abs)) $out[] = $rel;
    }
}

function loadTrustedKeys(array $dirs): array
{
    $map = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        foreach ((array) scandir($dir) as $f) {
            if ($f === '.' || $f === '..' || !str_ends_with((string) $f, '.pub')) continue;
            $raw = (string) file_get_contents($dir . '/' . $f);
            foreach (extractBase64Keys($raw) as $pk) {
                $map['sha256:' . hash('sha256', $pk)] = $pk;
            }
        }
    }
    return $map;
}

function extractBase64Keys(string $raw): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $line = trim((string) $line);
        if ($line === '' || $line[0] === '#') continue;
        $b = base64_decode($line, true);
        if ($b !== false && strlen($b) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $out[] = $b;
        }
    }
    return $out;
}

function readKeyFile(string $path, int $expectedLen, string $kind): string
{
    if (!is_file($path)) {
        fwrite(STDERR, "key file not found: {$path}\n");
        exit(1);
    }
    $raw = (string) file_get_contents($path);
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $line = trim((string) $line);
        if ($line === '' || $line[0] === '#') continue;
        $b = base64_decode($line, true);
        if ($b !== false && strlen($b) === $expectedLen) {
            return $b;
        }
    }
    fwrite(STDERR, "no valid {$kind} key (expected {$expectedLen} bytes base64) in {$path}\n");
    exit(1);
}

function parseFlags(array $argv): array
{
    $out = [];
    for ($i = 2; $i < count($argv); $i++) {
        $a = (string) $argv[$i];
        if (str_starts_with($a, '--') && str_contains($a, '=')) {
            [$k, $v] = explode('=', substr($a, 2), 2);
            $out[$k] = $v;
        }
    }
    return $out;
}

function printHelp(): void
{
    fwrite(STDOUT, <<<'TXT'
plugin-sign.php — Ed25519 plugin signing helper

Commands:
  generate-key                          Create a new keypair (writes *.pub + *.key in CWD).
  sign --key=<file> --plugin=<dir>      Sign plugin directory; writes plugin.sig.
  verify --plugin=<dir> [--keys=<dir>]  Verify a plugin's signature. Exit 0 on valid.

Trust directories searched by default:
  /app/eiou/config/trusted-plugin-keys   (baked into the image)
  /etc/eiou/config/trusted-plugin-keys   (operator-added, config volume)

See docs/PLUGINS.md (Plugin Signatures) for the full operator guide.

TXT);
}
