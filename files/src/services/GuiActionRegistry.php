<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;

/**
 * GuiActionRegistry — POST-action handlers the wallet GUI dispatches.
 *
 * Replaces the hardcoded action whitelist in `Functions.php`. Plugins
 * register their own POST handlers here and Functions.php dispatches
 * via the registry, so a plugin can ship a new form submission without
 * forking the host.
 *
 * Each registered entry carries a permission tier the registry enforces
 * before the handler runs:
 *
 *   - `public`     no auth gate (rare; the registry is reached only
 *                  from authenticated wallet pages today, so this tier
 *                  is reserved for unusual cases)
 *   - `auth`       authenticated session, no CSRF (read-only / probe
 *                  endpoints — e.g. fetching state without changing it)
 *   - `csrf`       authenticated session AND a valid CSRF token
 *                  (state-changing actions — the default)
 *   - `sensitive`  csrf gate PLUS the session must hold a sensitive-
 *                  access grant (deleting a contact, revealing an API
 *                  key — see Session::hasSensitiveAccess)
 *
 * Action-name convention is camelCase, mirroring the values the GUI
 * already posts (`addContact`, `sendEIOU`, `getDebugReportJson`). The
 * registry validates the name shape so plugins can't smuggle in keys
 * that collide with HTTP method names or look like attribute selectors.
 *
 * Last-write-wins on id collision. Lets a plugin override a core action
 * (e.g. ship a richer `sendEIOU` flow) without forking the host. The
 * symmetry with TabRegistry is intentional.
 *
 * The registry intentionally does NOT own response semantics. CSRF /
 * sensitive checks raise specific failure codes the caller maps to
 * JSON / redirect / 403 as appropriate — different actions have wildly
 * different response shapes (some return JSON + exit, some redirect
 * with a flash message), and binding the registry to one shape would
 * just push the special cases somewhere uglier.
 *
 * See docs/PLUGINS.md "Extending the GUI" for the action registry API.
 */
class GuiActionRegistry
{
    public const TIER_PUBLIC    = 'public';
    public const TIER_AUTH      = 'auth';
    public const TIER_CSRF      = 'csrf';
    public const TIER_SENSITIVE = 'sensitive';

    private const VALID_TIERS = [
        self::TIER_PUBLIC,
        self::TIER_AUTH,
        self::TIER_CSRF,
        self::TIER_SENSITIVE,
    ];

    /**
     * @var array<string, array{handler: callable, tier: string, plugin: ?string}>
     *      keyed by action name
     */
    private array $entries = [];

    /**
     * Register an action handler. Returns false (and logs) on any
     * validation failure so a misbehaving plugin can't take the page
     * down — the action simply won't be reachable.
     *
     * Handler signature: `function (array $request): mixed`. The
     * request array is whatever the dispatcher hands in (typically
     * `$_POST`). Return value is whatever the dispatcher chose to
     * surface; for the default Functions.php dispatcher it's ignored
     * because handlers write their own response and exit.
     */
    public function register(
        string $action,
        callable $handler,
        string $tier = self::TIER_CSRF,
        ?string $pluginId = null
    ): bool {
        if (!preg_match('/^[a-z][a-zA-Z0-9]*$/', $action)) {
            Logger::getInstance()->warning('GuiActionRegistry: invalid action name', [
                'action' => $action,
                'plugin' => $pluginId,
            ]);
            return false;
        }
        if (!in_array($tier, self::VALID_TIERS, true)) {
            Logger::getInstance()->warning('GuiActionRegistry: invalid tier', [
                'action' => $action,
                'tier' => $tier,
                'plugin' => $pluginId,
            ]);
            return false;
        }

        $this->entries[$action] = [
            'handler' => $handler,
            'tier' => $tier,
            'plugin' => $pluginId,
        ];
        return true;
    }

    public function has(string $action): bool
    {
        return isset($this->entries[$action]);
    }

    public function getTier(string $action): ?string
    {
        return $this->entries[$action]['tier'] ?? null;
    }

    public function getHandler(string $action): ?callable
    {
        return $this->entries[$action]['handler'] ?? null;
    }

    public function getPluginId(string $action): ?string
    {
        return $this->entries[$action]['plugin'] ?? null;
    }

    /**
     * @return string[] action names, in registration order
     */
    public function listActions(): array
    {
        return array_keys($this->entries);
    }

    /**
     * Whether the named action requires CSRF validation. Convenience
     * for the dispatcher — same answer as `getTier(...) in [csrf,
     * sensitive]` but spelled out so the call site reads naturally.
     */
    public function requiresCsrf(string $action): bool
    {
        $tier = $this->getTier($action);
        return $tier === self::TIER_CSRF || $tier === self::TIER_SENSITIVE;
    }

    public function requiresSensitiveAccess(string $action): bool
    {
        return $this->getTier($action) === self::TIER_SENSITIVE;
    }
}
