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
    private const REQUIRED_DBCONFIG_KEYS = ['dbHost', 'dbName', 'dbUser', 'dbPass'];

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

        $missing = [];
        foreach (self::REQUIRED_DBCONFIG_KEYS as $key) {
            if (!array_key_exists($key, $decoded) || $decoded[$key] === '' || $decoded[$key] === null) {
                $missing[] = $key;
            }
        }
        if (!empty($missing)) {
            return [[
                'severity' => 'error',
                'code' => 'dbconfig_missing_fields',
                'message' => sprintf('dbconfig.json is missing required field(s): %s.', implode(', ', $missing)),
            ]];
        }

        return [];
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
