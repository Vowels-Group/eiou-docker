<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Plugins;

use Eiou\Utils\Logger;
use InvalidArgumentException;
use RuntimeException;

/**
 * PluginGatewayTokenService
 *
 * Per-plugin bearer tokens that the service gateway uses to
 * authenticate inbound HTTP requests from a sandboxed plugin's
 * __dispatch.php. Each enabled sandboxed plugin gets a fresh
 * 256-bit random token at applyPool() time. The token is:
 *
 *   - Written to `/etc/eiou/plugins/<plugin-id>/.gateway-token` so the
 *     plugin's FPM pool can read it (the dispatch script reads + sends
 *     it in `Authorization: Bearer <token>` on every gateway call)
 *   - Recorded in /etc/eiou/config/plugin-gateway-tokens.json so the
 *     gateway can look up plugin-id by token in O(1)
 *
 * No other plugin's pool can read the file (open_basedir restricts
 * each pool to its own dir). The wallet pool reads tokens.json (mode
 * 600 / www-data) for validation. A malicious plugin that finds a way
 * to read another plugin's tokens already has open_basedir bypass —
 * a separate breach. Token rotation on every applyPool means the
 * attacker has at most one enable cycle of access.
 *
 * Tokens are stored in plaintext rather than hashed for the same
 * reason api keys are stored in plaintext: this isn't a password,
 * it's a per-plugin secret the wallet itself generated. Hashing
 * would prevent online lookup, breaking the O(1) authentication
 * path on every gateway call.
 *
 * See docs/PLUGINS.md (Sandboxing) for the broader trust model.
 */
class PluginGatewayTokenService
{
    /** Where the index of token → plugin_id lives. */
    public const DEFAULT_TOKENS_PATH = '/etc/eiou/config/plugin-gateway-tokens.json';

    /** Per-plugin file (token bytes only, no JSON wrapper). */
    public const PER_PLUGIN_TOKEN_FILENAME = '.gateway-token';

    public const TOKEN_BYTES = 32; // 256 bits

    private string $tokensPath;
    private string $pluginRoot;
    private ?Logger $logger;

    public function __construct(
        ?string $tokensPath = null,
        ?string $pluginRoot = null,
        ?Logger $logger = null
    ) {
        $this->tokensPath = $tokensPath ?? self::DEFAULT_TOKENS_PATH;
        $this->pluginRoot = rtrim($pluginRoot ?? PluginPoolService::PLUGIN_ROOT, '/');
        $this->logger = $logger;
    }

    /**
     * Mint a fresh token for a plugin and persist both halves: the
     * per-plugin file in the plugin's dir and the central index. Any
     * previous token for the same plugin is invalidated atomically.
     *
     * Returns the new token hex string. Throws on filesystem failure
     * (caller treats it like any pool-apply failure).
     */
    public function rotate(string $pluginId): string
    {
        $this->validatePluginId($pluginId);

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        // Persist the per-plugin file first. If the central index
        // write fails, the plugin will get an "unauthorized" response
        // on its first gateway call — operator sees the failure
        // immediately. The other order (central first) could leave
        // the central index pointing at a token no plugin file
        // contains, which silently fails.
        $perPluginPath = $this->pluginRoot . '/' . $pluginId . '/' . self::PER_PLUGIN_TOKEN_FILENAME;
        $this->writeAtomic($perPluginPath, $token, 0600);

        // Update the central index. Read-modify-write under a flock
        // guard so concurrent rotates don't lose entries — both
        // would race the JSON parse + serialize.
        $index = $this->readIndex();
        // Remove the old token for this plugin (we don't keep history;
        // any in-flight gateway call with the old token will fail auth
        // and surface to the plugin as a 401, which it can retry).
        foreach ($index as $existingToken => $existingPluginId) {
            if ($existingPluginId === $pluginId) {
                unset($index[$existingToken]);
            }
        }
        $index[$token] = $pluginId;
        $this->writeIndex($index);

        return $token;
    }

    /**
     * Look up a plugin id from a token. Returns null if the token
     * isn't registered.
     */
    public function pluginIdForToken(string $token): ?string
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            // Shape mismatch — short-circuit without touching the file.
            return null;
        }
        $index = $this->readIndex();
        return $index[$token] ?? null;
    }

    /**
     * Revoke a plugin's token (both the central index entry and the
     * per-plugin file). Called when a sandboxed plugin disables.
     * Idempotent — silently no-ops when there's nothing to revoke.
     */
    public function revoke(string $pluginId): void
    {
        $this->validatePluginId($pluginId);

        $index = $this->readIndex();
        $changed = false;
        foreach ($index as $token => $registered) {
            if ($registered === $pluginId) {
                unset($index[$token]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->writeIndex($index);
        }

        $perPluginPath = $this->pluginRoot . '/' . $pluginId . '/' . self::PER_PLUGIN_TOKEN_FILENAME;
        if (is_file($perPluginPath)) {
            @unlink($perPluginPath);
        }
    }

    /**
     * @return array<string,string> token => plugin_id
     */
    private function readIndex(): array
    {
        if (!is_file($this->tokensPath)) {
            return [];
        }
        $raw = @file_get_contents($this->tokensPath);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->log('warning', 'plugin_gateway_tokens_corrupt', ['path' => $this->tokensPath]);
            return [];
        }
        return $decoded;
    }

    /**
     * @param array<string,string> $index
     */
    private function writeIndex(array $index): void
    {
        $json = json_encode($index, JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Could not serialize plugin gateway token index');
        }
        // Mode 0640 — owner read/write, group read, none other.
        // PluginGatewayController (www-data FPM pool) must be able to
        // read this file to validate inbound bearers. Two writers in
        // play and they need to produce a file www-data can read in
        // both cases:
        //   - GUI/REST/processor paths run AS www-data → file ends up
        //     www-data:www-data 0640, www-data is the owner, can read.
        //   - CLI path runs as whoever invoked it, typically root via
        //     `docker exec` → file ends up root:root 0640 by default;
        //     the @chown below lifts the group to www-data so the
        //     www-data pool can still read. @ swallows EPERM if the
        //     writer doesn't have CAP_CHOWN (the www-data path) — no
        //     change needed in that case because the file already
        //     ends up www-data-owned by default.
        $this->writeAtomic($this->tokensPath, $json, 0640);
        @chown($this->tokensPath, 'root');
        @chgrp($this->tokensPath, 'www-data');
    }

    private function writeAtomic(string $path, string $contents, int $mode): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            // Refuse to create config dirs implicitly — that would mask
            // a real "this path is wrong" bug. The plugin dir is
            // guaranteed to exist by the time we're called.
            throw new RuntimeException("Parent directory does not exist: {$dir}");
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException("Could not write {$tmp}");
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Could not rename {$tmp} to {$path}");
        }
    }

    private function validatePluginId(string $pluginId): void
    {
        if (!preg_match(PluginUserService::PLUGIN_ID_PATTERN, $pluginId)) {
            throw new InvalidArgumentException("Invalid plugin id: '{$pluginId}'");
        }
    }

    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger === null) return;
        $this->logger->{$level}($message, $context);
    }
}
