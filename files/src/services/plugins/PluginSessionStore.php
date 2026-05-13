<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Plugins;

use Eiou\Utils\Logger;

/**
 * PluginSessionStore — namespaced session API for plugin code.
 *
 * Plugins run in-process, so nothing structurally prevents a plugin
 * handler from doing `$_SESSION[SessionKeys::CSRF_TOKEN] = '…'` and
 * forging core auth state. The trust model is "operator-vetted
 * plugins" today, but defense-in-depth says: give plugins a clearly-
 * supported session API that's namespaced under `plugin_<id>_*`, and
 * mark direct `$_SESSION` access from plugin code as out-of-contract.
 *
 * Usage from a plugin's boot() / handler:
 *
 *   $store = $container->getPluginSessionStore('hello-eiou');
 *   $store->set('last_fortune', $fortuneText);
 *   $current = $store->get('last_fortune', 'default');
 *
 * Stored keys land in `$_SESSION['plugin_hello-eiou_last_fortune']`.
 * Core SessionKeys::* values cannot be reached through this API — the
 * key prefix is enforced.
 *
 * The store does NOT prevent a determined plugin from calling
 * `$_SESSION[...]` directly. That's a process-isolation problem
 * (would require running plugins in a separate fpm worker / sandbox)
 * and is out of scope for v1. What this DOES provide:
 *
 *   1. A clear convention plugin authors can follow without thinking.
 *   2. A surface that's easy to audit (grep for raw `$_SESSION[` in
 *      `files/plugins/` and you've found every off-spec access).
 *   3. An optional dev-mode runtime guard (PLUGIN_HOOKS_TRACE=1)
 *      that asserts plugin entry points only touch namespaced keys.
 */
class PluginSessionStore
{
    private string $pluginId;
    private string $prefix;

    /**
     * Plugin id format mirrors what plugin.json's `name` field
     * accepts: kebab-case, lowercase letters / digits / hyphens / underscores.
     * Validated at construction so a malformed id cannot widen the
     * session-key namespace (e.g. `plugin_..auth` would be rejected).
     */
    private const VALID_PLUGIN_ID = '/^[a-z0-9][a-z0-9_-]*$/';

    /**
     * Reserved key suffixes — even within the plugin's own namespace
     * we forbid keys that look like core session fields, to prevent
     * a plugin author from accidentally shadowing system behavior
     * (`plugin_foo_authenticated` etc).
     */
    private const RESERVED_SUFFIXES = [
        'authenticated',
        'auth_time',
        'csrf_token',
        'csrf_token_time',
        'sensitive_access_until',
        'sensitive_access_auth_time',
        'last_regeneration',
        'last_activity',
    ];

    public function __construct(string $pluginId)
    {
        if (!preg_match(self::VALID_PLUGIN_ID, $pluginId)) {
            throw new \InvalidArgumentException("PluginSessionStore: invalid plugin id '{$pluginId}' (must match " . self::VALID_PLUGIN_ID . ")");
        }
        $this->pluginId = $pluginId;
        $this->prefix = "plugin_{$pluginId}_";
    }

    /**
     * Read a plugin-namespaced session value. Returns the default if
     * the key is absent or if the session isn't started.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return $default;
        }
        return $_SESSION[$this->prefix . $key] ?? $default;
    }

    /**
     * Write a plugin-namespaced session value. No-op if the session
     * isn't started (mirrors $_SESSION's permissive behavior).
     */
    public function set(string $key, mixed $value): void
    {
        $this->validateKey($key);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION[$this->prefix . $key] = $value;
    }

    /**
     * Remove a plugin-namespaced session value.
     */
    public function unset(string $key): void
    {
        $this->validateKey($key);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        unset($_SESSION[$this->prefix . $key]);
    }

    /**
     * Clear every key under this plugin's namespace. Useful on
     * uninstall / disable.
     */
    public function clearAll(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        foreach (array_keys($_SESSION) as $sessKey) {
            if (is_string($sessKey) && str_starts_with($sessKey, $this->prefix)) {
                unset($_SESSION[$sessKey]);
            }
        }
    }

    /**
     * Test seam — used by the dev-mode session-write guard to detect
     * plugin handlers that bypass the store. Not for production use.
     *
     * @return string The full key prefix (e.g. `plugin_hello-eiou_`)
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Validate a per-plugin key. Suffix rules:
     *   - non-empty
     *   - 64 chars or fewer (cap to keep $_SESSION key total length sane)
     *   - alphanum / underscore / hyphen only (no `.`, `[`, `]`, etc)
     *   - not on the reserved-suffix list (no shadowing of core fields)
     */
    private function validateKey(string $key): void
    {
        if ($key === '' || strlen($key) > 64) {
            throw new \InvalidArgumentException("PluginSessionStore: key empty or too long");
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            throw new \InvalidArgumentException("PluginSessionStore: key '{$key}' contains disallowed characters (alphanumeric / underscore / hyphen only)");
        }
        if (in_array($key, self::RESERVED_SUFFIXES, true)) {
            // Logger may not be available in all contexts; silently
            // throw — the exception itself is the loud signal.
            try {
                Logger::getInstance()->warning("PluginSessionStore: blocked reserved suffix", [
                    'plugin' => $this->pluginId,
                    'key' => $key,
                ]);
            } catch (\Throwable $_) {
                // No logger available — exception below is enough.
            }
            throw new \InvalidArgumentException("PluginSessionStore: key '{$key}' shadows a reserved session field");
        }
    }
}
