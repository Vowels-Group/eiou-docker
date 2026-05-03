<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Core;

use Eiou\Utils\Logger;

/**
 * Startup validator for the four-layer configuration hierarchy.
 *
 * Surfaces misconfigurations once at boot rather than letting them
 * manifest as opaque downstream failures (TLS handshake errors, JSON
 * parse warnings on the request path, debug-mode info leaks in
 * production). The validator is non-fatal by default — it logs and
 * returns the issue list — so a single bad config doesn't lock the
 * operator out of their wallet.
 *
 * Validation surface (deliberately conservative — extend as new
 * layer-conflict patterns emerge from incident reports):
 *
 *   - AppConfig sanity (prod + debug, prod + SSL-verify-off)
 *   - p2pCaCert path existence on disk
 *   - dbconfig.json shape (parseable + required keys)
 *   - defaultconfig.json + userconfig.json JSON parseability
 *   - trustedProxies entries parse as IP / CIDR
 *
 * Severity levels:
 *   - 'error'   — operator should fix before relying on the affected feature
 *   - 'warning' — likely misconfiguration, but won't fail outright
 *
 * @see docs/CONFIGURATION.md for the layer hierarchy this validates against.
 */
final class ConfigValidator
{
    /**
     * dbconfig.json shape after Application::migrateDbConfigEncryption()
     * (which always runs *before* this validator at boot — see
     * Application::init() line ordering):
     *
     *   - `dbHost` is plaintext, always required.
     *   - Credentials live in `dbNameEncrypted` / `dbUserEncrypted` /
     *     `dbPassEncrypted` (TDE-encrypted blobs). Plaintext `dbName` /
     *     `dbUser` / `dbPass` should NOT be present at this point —
     *     migration moves them to the encrypted variants on first boot.
     *
     * The validator therefore requires the encrypted form and surfaces
     * any leftover plaintext as a separate error: that means migration
     * didn't complete, the file was hand-edited, or a restore put back
     * a pre-TDE config — operator should investigate either way.
     */
    private const REQUIRED_DBCONFIG_KEYS = ['dbHost'];
    private const REQUIRED_DBCONFIG_ENCRYPTED_KEYS = [
        'dbNameEncrypted',
        'dbUserEncrypted',
        'dbPassEncrypted',
    ];
    private const PROHIBITED_DBCONFIG_PLAINTEXT_KEYS = ['dbName', 'dbUser', 'dbPass'];

    public function __construct(
        private readonly AppConfig $appConfig,
        private readonly string $configDir = '/etc/eiou/config'
    ) {
    }

    /**
     * Build a validator using the bootstrap AppConfig snapshot. Convenience
     * constructor for callers that don't already hold an AppConfig.
     */
    public static function fromEnvironment(string $configDir = '/etc/eiou/config'): self
    {
        return new self(AppConfig::fromEnvironment(), $configDir);
    }

    /**
     * Run all checks and return the issue list.
     *
     * @return array<int, array{severity: string, code: string, message: string}>
     */
    public function validate(): array
    {
        $issues = [];
        foreach ($this->validateAppConfig() as $issue) {
            $issues[] = $issue;
        }
        foreach ($this->validateDbConfig() as $issue) {
            $issues[] = $issue;
        }
        foreach ($this->validateJsonConfigFiles() as $issue) {
            $issues[] = $issue;
        }
        foreach ($this->validateTrustedProxies() as $issue) {
            $issues[] = $issue;
        }
        return $issues;
    }

    /**
     * Run validation and emit each issue through the supplied logger.
     * Returns the same issue list for callers that want to inspect it.
     *
     * @return array<int, array{severity: string, code: string, message: string}>
     */
    public function validateAndLog(?Logger $logger = null): array
    {
        $issues = $this->validate();
        $logger = $logger ?? Logger::getInstance();
        foreach ($issues as $issue) {
            $context = ['code' => $issue['code']];
            if ($issue['severity'] === 'error') {
                $logger->error('ConfigValidator: ' . $issue['message'], $context);
            } else {
                $logger->warning('ConfigValidator: ' . $issue['message'], $context);
            }
        }
        return $issues;
    }

    /** @return array<int, array{severity: string, code: string, message: string}> */
    private function validateAppConfig(): array
    {
        $issues = [];

        if ($this->appConfig->appEnv === 'production' && $this->appConfig->appDebug) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'prod_debug_enabled',
                'message' => 'APP_DEBUG=true with APP_ENV=production exposes debug output to operators of remote nodes; flip APP_DEBUG=false outside development.',
            ];
        }

        if ($this->appConfig->appEnv === 'production' && !$this->appConfig->p2pSslVerify) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'prod_ssl_verify_disabled',
                'message' => 'P2P_SSL_VERIFY=false with APP_ENV=production disables peer certificate verification on outbound P2P traffic; only acceptable in test setups.',
            ];
        }

        if ($this->appConfig->p2pCaCert !== null && !is_file($this->appConfig->p2pCaCert)) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'p2p_ca_cert_missing',
                'message' => sprintf('P2P_CA_CERT points at "%s" but no file exists at that path; outbound P2P TLS will fail until the file is provided or the env var is unset.', $this->appConfig->p2pCaCert),
            ];
        }

        return $issues;
    }

    /** @return array<int, array{severity: string, code: string, message: string}> */
    private function validateDbConfig(): array
    {
        $path = $this->configDir . '/dbconfig.json';
        if (!file_exists($path)) {
            // First-boot path — Application::init() handles fresh-install creation.
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [[
                'severity' => 'error',
                'code' => 'dbconfig_unreadable',
                'message' => 'dbconfig.json exists but is not readable by the running process.',
            ]];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [[
                'severity' => 'error',
                'code' => 'dbconfig_invalid_json',
                'message' => 'dbconfig.json is not valid JSON; the database connection cannot be established.',
            ]];
        }

        $issues = [];

        // Are we pre-wallet (master key not yet generated) or post-wallet?
        // The two states have different valid dbconfig shapes:
        //
        //   Pre-wallet  : plaintext dbName/dbUser/dbPass (deferred encryption,
        //                 will be migrated as soon as wallet generation runs);
        //                 encrypted blobs MUST be absent.
        //   Post-wallet : encrypted dbNameEncrypted/dbUserEncrypted/dbPassEncrypted;
        //                 plaintext counterparts MUST be absent.
        //
        // Using the master key file as the state signal closes the false-
        // positive on first boot (logged by the user as
        // dbconfig_missing_fields + dbconfig_plaintext_credentials before
        // migrateDbConfigEncryption ran for the first time) without
        // weakening post-wallet checks.
        $preWallet = $this->isPreWalletBootstrap();

        // dbHost is required in both states.
        $missing = [];
        foreach (self::REQUIRED_DBCONFIG_KEYS as $key) {
            if (!array_key_exists($key, $decoded) || $decoded[$key] === '' || $decoded[$key] === null) {
                $missing[] = $key;
            }
        }

        $hasPlaintext = false;
        $hasEncrypted = false;
        foreach (self::PROHIBITED_DBCONFIG_PLAINTEXT_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                $hasPlaintext = true;
                break;
            }
        }
        foreach (self::REQUIRED_DBCONFIG_ENCRYPTED_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                $hasEncrypted = true;
                break;
            }
        }

        if ($preWallet) {
            // Pre-wallet bootstrap: plaintext is the legitimate shape.
            // Encrypted blobs without a master key indicate a broken
            // state — either the master key was deleted, the plaintext
            // key file is at /dev/shm only and we lost the persistent
            // copy, or the file was tampered with.
            if ($hasEncrypted) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'dbconfig_encrypted_without_master_key',
                    'message' => 'dbconfig.json contains encrypted credential field(s) but no master key is present on disk. The wallet cannot decrypt these. Restore /etc/eiou/config/.master.key from backup, or regenerate the wallet from the seed phrase.',
                ];
            }
            // Pre-wallet without ANY credential fields means the file is
            // empty of useful data — bootstrap can't proceed.
            if (!$hasPlaintext && !$hasEncrypted) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'dbconfig_no_credentials',
                    'message' => 'dbconfig.json contains no credential fields (neither plaintext nor encrypted). The DB connection cannot be opened.',
                ];
            }
            // Skip the post-wallet "missing encrypted" / "leftover plaintext"
            // checks — both are expected during pre-wallet bootstrap.
            if (!empty($missing)) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'dbconfig_missing_fields',
                    'message' => sprintf('dbconfig.json is missing required field(s): %s.', implode(', ', $missing)),
                ];
            }
            return $issues;
        }

        // Post-wallet path: full strict checks.
        $malformed = [];
        foreach (self::REQUIRED_DBCONFIG_ENCRYPTED_KEYS as $key) {
            if (!array_key_exists($key, $decoded)) {
                $missing[] = $key;
                continue;
            }
            $blob = $decoded[$key];
            // Structural blob check rejects `"Dave"`, `{"foo":"bar"}`,
            // wrong IV/tag length, missing version, etc. — see
            // looksLikeKeyEncryptionBlob() below.
            if (!is_array($blob) || empty($blob) || !$this->looksLikeKeyEncryptionBlob($blob)) {
                $malformed[] = $key;
            }
        }

        if (!empty($missing)) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'dbconfig_missing_fields',
                'message' => sprintf('dbconfig.json is missing required field(s): %s.', implode(', ', $missing)),
            ];
        }

        if (!empty($malformed)) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'dbconfig_malformed_encrypted_blob',
                'message' => sprintf(
                    'dbconfig.json field(s) %s do not match the KeyEncryption blob shape (expected ciphertext/iv/tag base64 strings + version). The DB connection will fail at decrypt time.',
                    implode(', ', $malformed)
                ),
            ];
        }

        // Plaintext should never be present post-wallet. If it is,
        // either migrateDbConfigEncryption() didn't complete, the file
        // was hand-edited, or a pre-TDE backup was restored without a
        // re-migration boot.
        $leakedPlaintext = [];
        foreach (self::PROHIBITED_DBCONFIG_PLAINTEXT_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                $leakedPlaintext[] = $key;
            }
        }
        if (!empty($leakedPlaintext)) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'dbconfig_plaintext_credentials',
                'message' => sprintf(
                    'dbconfig.json contains plaintext credential field(s) (%s) that should have been migrated to TDE-encrypted form. Restart the node so Application::migrateDbConfigEncryption() can re-encrypt them, or restore from a known-good backup.',
                    implode(', ', $leakedPlaintext)
                ),
            ];
        }

        return $issues;
    }

    /**
     * Pre-wallet bootstrap state: the master key file isn't on disk yet,
     * so dbconfig.json plaintext credentials are legitimate (transient,
     * encryption migration runs as soon as generateWallet/restoreWallet
     * creates the key). Once the wallet is initialized, the master key
     * persists at /etc/eiou/config/.master.key (and a runtime copy at
     * /dev/shm/.master.key may also exist post-VolumeEncryption load).
     */
    private function isPreWalletBootstrap(): bool
    {
        // Use the validator's $configDir for testability (so the test
        // suite can simulate either state with a temp dir). The runtime
        // key at /dev/shm is derived from the persistent one, so absence
        // of the persistent key is the canonical "no wallet yet" signal.
        return !file_exists($this->configDir . '/.master.key');
    }

    /**
     * Best-effort structural check on a KeyEncryption blob. We don't
     * have the master key here so we can't actually decrypt — but we
     * CAN verify the shape and lengths match what KeyEncryption::encrypt()
     * produces, which catches:
     *   - non-array values (`"Dave"`)
     *   - missing required keys (`{"foo": "bar"}`)
     *   - non-base64 strings
     *   - IV / tag of the wrong byte length (expected 12 / 16 for AES-256-GCM)
     *   - non-string ciphertext
     * AAD is checked as a string when present (it's optional in older
     * blobs but always written by the current code).
     *
     * @param array<mixed> $blob
     */
    private function looksLikeKeyEncryptionBlob(array $blob): bool
    {
        foreach (['ciphertext', 'iv', 'tag'] as $field) {
            if (!array_key_exists($field, $blob) || !is_string($blob[$field]) || $blob[$field] === '') {
                return false;
            }
            $decoded = base64_decode($blob[$field], true);
            if ($decoded === false) {
                return false;
            }
            if ($field === 'iv' && strlen($decoded) !== 12) {
                return false;
            }
            if ($field === 'tag' && strlen($decoded) !== 16) {
                return false;
            }
            if ($field === 'ciphertext' && strlen($decoded) < 1) {
                return false;
            }
        }
        // version is mandatory on the current write path. Older v1 blobs
        // (pre-AAD) wouldn't have it, but Application::init() rotates
        // those forward, so seeing one without `version` here is a sign
        // of tampering or a downgrade attack.
        if (!array_key_exists('version', $blob) || !is_int($blob['version']) || $blob['version'] < 1) {
            return false;
        }
        // aad is optional but if present must be a string (so a caller
        // can't smuggle a typed value in).
        if (array_key_exists('aad', $blob) && !is_string($blob['aad'])) {
            return false;
        }
        return true;
    }

    /** @return array<int, array{severity: string, code: string, message: string}> */
    private function validateJsonConfigFiles(): array
    {
        $issues = [];
        foreach (['defaultconfig.json', 'userconfig.json'] as $file) {
            $path = $this->configDir . '/' . $file;
            if (!file_exists($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            if ($raw === false || $raw === '') {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'config_unreadable',
                    'message' => sprintf('%s exists but is empty or unreadable; falling back to defaults from Constants.', $file),
                ];
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'config_invalid_json',
                    'message' => sprintf('%s is not valid JSON; falling back to defaults from Constants until the file is fixed.', $file),
                ];
            }
        }
        return $issues;
    }

    /** @return array<int, array{severity: string, code: string, message: string}> */
    private function validateTrustedProxies(): array
    {
        $raw = trim($this->appConfig->trustedProxies);
        if ($raw === '') {
            return [];
        }
        $bad = [];
        foreach (preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            if (!$this->isValidIpOrCidr($entry)) {
                $bad[] = $entry;
            }
        }
        if (empty($bad)) {
            return [];
        }
        return [[
            'severity' => 'warning',
            'code' => 'trusted_proxies_malformed',
            'message' => sprintf(
                'TRUSTED_PROXIES contains entries that do not parse as IP or CIDR: %s. The Security::getClientIp() proxy chain will treat them as untrusted.',
                implode(', ', $bad)
            ),
        ]];
    }

    private function isValidIpOrCidr(string $entry): bool
    {
        if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (str_contains($entry, '/')) {
            [$ip, $mask] = explode('/', $entry, 2);
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return false;
            }
            if (!ctype_digit($mask)) {
                return false;
            }
            $maskInt = (int) $mask;
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $maskInt >= 0 && $maskInt <= 32;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                return $maskInt >= 0 && $maskInt <= 128;
            }
        }
        return false;
    }
}
