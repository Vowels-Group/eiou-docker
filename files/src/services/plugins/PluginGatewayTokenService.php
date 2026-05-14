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
     *
     * Sequencing matters: write the per-plugin file FIRST, then commit
     * to the index. If the file write throws, the index is untouched
     * and the plugin's old token (if any) remains valid for gateway
     * auth. If the index commit throws, the file is left in place; the
     * supervisor-mediated apply-pool path (and any later reconcile)
     * will repair the drift. Either way we don't end up in the failure
     * mode the old "index first" order produced: index holding a token
     * the on-disk file doesn't contain, dispatcher sending the old
     * token and getting 401-forever until someone manually realigns.
     *
     * NOTE: under dev bind-mount layouts the plugin dir is often owned
     * by the host operator's uid (which collides with `eiou-p-<hash>`
     * inside the container) and the wallet pool (`www-data`) can't
     * write to it directly. The supervisor-mediated path in
     * `PluginPoolService::applyPool()` handles that case; `rotate()`
     * is retained for in-container CLI and test paths where www-data
     * owns the plugin dir.
     */
    public function rotate(string $pluginId): string
    {
        $this->validatePluginId($pluginId);

        $token = $this->mint();

        // File first — if this throws the index hasn't moved, so the
        // plugin keeps using its previous token without auth drift.
        $perPluginPath = $this->pluginRoot . '/' . $pluginId . '/' . self::PER_PLUGIN_TOKEN_FILENAME;
        $this->writeAtomic($perPluginPath, $token, 0600);

        // Index second — now the on-disk file is the source of truth
        // and the index is being brought into alignment with it.
        $this->commitToken($pluginId, $token);

        return $token;
    }

    /**
     * Mint a fresh token without touching any state. Returned to the
     * caller; persistence (per-plugin file + central index) is handled
     * separately so callers that go through the supervisor can defer
     * the index commit until the supervisor confirms the file write
     * succeeded. See `commitToken()` and `reconcileFromFile()`.
     *
     * This split exists to honour the host-side rule that privileged
     * writes into `/etc/eiou/plugins/<id>/` route through the
     * supervisor's `plugin_routing_poller`, not directly from the
     * wallet pool — see docs/PLUGINS.md "Privileged writes into the
     * plugin directory" for the policy and why dev bind-mount layouts
     * make the direct-write path unreliable. Future features that need
     * the host to write into a plugin's dir should mirror this shape:
     * generate in memory, ship via the supervisor request file, commit
     * any local state only after the supervisor confirms success.
     */
    public function mint(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * Set the central index entry for a plugin to a specific token,
     * replacing any previous entry for that plugin. Used in two paths:
     *
     *   - After the supervisor confirms the per-plugin `.gateway-token`
     *     write succeeded — at that point the file is authoritative
     *     and we bring the index into alignment with it.
     *
     *   - From `reconcileFromFile()`, which heals drift left behind
     *     by an apply-pool that crashed or returned non-ok after a
     *     pre-fix release wrote the index but not the file.
     *
     * Read-modify-write under the same flock discipline as before;
     * the previous entry for this plugin is unconditionally removed.
     *
     * Throws on central-index filesystem failure (caller decides
     * whether that's recoverable).
     */
    public function commitToken(string $pluginId, string $token): void
    {
        $this->validatePluginId($pluginId);
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new InvalidArgumentException("Invalid token shape for plugin '{$pluginId}'");
        }

        $index = $this->readIndex();
        foreach ($index as $existingToken => $existingPluginId) {
            if ($existingPluginId === $pluginId) {
                unset($index[$existingToken]);
            }
        }
        $index[$token] = $pluginId;
        $this->writeIndex($index);
    }

    /**
     * Mint a fresh token, update the central index, and return the
     * token WITHOUT writing the per-plugin `.gateway-token` file.
     *
     * Retained for backward compatibility — new code should call
     * `mint()` to get a token, ship it through the supervisor for the
     * on-disk write, and only then call `commitToken()`. The split
     * order means a failed supervisor write doesn't leave the index
     * holding a token no `.gateway-token` file contains.
     */
    public function mintAndIndex(string $pluginId): string
    {
        $token = $this->mint();
        $this->commitToken($pluginId, $token);
        return $token;
    }

    /**
     * Heal index drift for a single plugin by treating its
     * `.gateway-token` file as authoritative.
     *
     * Reads the per-plugin file; if it exists and contains a
     * shape-valid token, ensures the central index maps that token to
     * the plugin (replacing any other entry the plugin had). Returns
     * the token if a reconcile happened (or was already correct) and
     * null when there's no file to reconcile against.
     *
     * Only readable from a context that has access to the per-plugin
     * file — in practice the supervisor or the plugin's own pool. The
     * wallet pool (www-data) does NOT have permission to read it. So
     * boot-time drift correction in startup.sh is the intended caller;
     * tests reach in directly.
     */
    public function reconcileFromFile(string $pluginId): ?string
    {
        $this->validatePluginId($pluginId);

        $perPluginPath = $this->pluginRoot . '/' . $pluginId . '/' . self::PER_PLUGIN_TOKEN_FILENAME;
        if (!is_file($perPluginPath) || !is_readable($perPluginPath)) {
            return null;
        }
        $token = trim((string) @file_get_contents($perPluginPath));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->log('warning', 'plugin_gateway_token_file_malformed', [
                'plugin' => $pluginId,
                'path'   => $perPluginPath,
            ]);
            return null;
        }

        $index = $this->readIndex();
        // Fast path — index already matches, nothing to write.
        if (($index[$token] ?? null) === $pluginId) {
            // Confirm no other entry claims this plugin. Defensive
            // pass: if a duplicate slipped in, this falls through to
            // commitToken which rebuilds the entry exclusively.
            $stale = false;
            foreach ($index as $idxToken => $idxPlugin) {
                if ($idxToken !== $token && $idxPlugin === $pluginId) {
                    $stale = true;
                    break;
                }
            }
            if (!$stale) {
                return $token;
            }
        }

        $this->commitToken($pluginId, $token);
        $this->log('info', 'plugin_gateway_token_reconciled', [
            'plugin' => $pluginId,
        ]);
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
