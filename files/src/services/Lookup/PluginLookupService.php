<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Lookup;

use Eiou\Contracts\PluginCallable;
use Eiou\Contracts\PluginCallerAware;
use Eiou\Services\Plugins\PluginLoader;
use RuntimeException;

/**
 * PluginLookupService
 *
 * Self-introspection surface for sandboxed plugins. Lets a plugin
 * read back fields about *itself* — what permissions the operator
 * granted, what manifest fields the host registered — without
 * letting it read another plugin's row. Implements PluginCallerAware
 * so the gateway-resolved plugin id is the source of truth for
 * "which plugin am I"; methods refuse with RuntimeException if the
 * caller id wasn't set (defence in depth against any path that
 * bypasses the gateway).
 *
 * Why this is a separate service rather than a method on each
 * existing one: plugins frequently want to fail fast at boot if a
 * required permission isn't granted, render a "this app uses…"
 * page in their own UI, or branch on whether they were enabled
 * with a specific manifest surface. All of those want the same
 * cross-cutting data (own row, projected safely) without the
 * caller having to know which other service hosts it. Grouping
 * the introspection under one PluginCallable target keeps plugin
 * authors from threading the plugin id through their own code.
 *
 * Read-only by design — there's no setter surface here. A plugin
 * cannot escalate its own permissions, edit its own manifest, or
 * change its own enabled state through this service; those flow
 * through operator action (GUI / CLI) only.
 */
class PluginLookupService implements PluginCallerAware
{
    /**
     * Manifest fields plugins are allowed to read back about
     * themselves. Intentionally narrow — the full plugin row
     * carries host-injected fields (gateway tokens, system-user
     * derivations, runtime status flags) that aren't part of the
     * plugin's declared surface and shouldn't be reflected back
     * to it. Adding a field here is the place to weigh "does the
     * plugin author have a legitimate reason to read this?"
     * against "could a hostile plugin learn something useful
     * about the host's state?"
     */
    private const PROJECTABLE_MANIFEST_FIELDS = [
        'name',
        'version',
        'enabled',
        'sandboxed',
        'description',
        'author',
        'license',
        'homepage',
        'changelog',
        'core_services',
        'permissions',
        'subscribes_to',
        'filter_hooks',
        'render_hooks',
        'tabs',
        'plugin_tab_panel',
        'gui_actions',
        'gui_assets',
        'api_routes',
        'cli_commands',
        'public_routes',
        'cron',
        'payback_method_types',
        'min_upgradable_from',
    ];

    private PluginLoader $loader;
    private ?string $callingPluginId = null;

    public function __construct(PluginLoader $loader)
    {
        $this->loader = $loader;
    }

    public function setCallingPluginId(?string $pluginId): void
    {
        $this->callingPluginId = $pluginId;
    }

    #[PluginCallable(
        description: 'Return the calling plugin\'s granted permissions as a list of strings (the `permissions` field from the plugin\'s manifest row). Empty list when the plugin requested none. Plugins use this to render their own "this app uses…" page, fail-fast at boot if a required permission isn\'t granted, or audit their own surface — symmetric with what the operator sees in the plugin modal\'s Permissions panel.',
        ratePerMinute: 60
    )]
    public function getOwnPermissions(): array
    {
        $pluginId = $this->requireCallerId();
        $row = $this->findOwnRow($pluginId);
        if ($row === null) {
            return [];
        }
        $perms = $row['permissions'] ?? [];
        if (!is_array($perms)) {
            return [];
        }
        return array_values(array_filter($perms, 'is_string'));
    }

    #[PluginCallable(
        description: 'Return the calling plugin\'s own manifest, projected to a documented subset of fields (name, version, enabled, sandboxed, description, author, license, homepage, changelog, core_services, permissions, subscribes_to, filter_hooks, render_hooks, tabs, plugin_tab_panel, gui_actions, gui_assets, api_routes, cli_commands, public_routes, cron, payback_method_types, min_upgradable_from). Host-injected fields like gateway tokens or runtime-derived system-user names are deliberately omitted. Plugins use this to read back their own declared surface — e.g. to render a settings page that lists their own api_routes, or to check at boot whether the host saw the manifest field the plugin author expected.',
        ratePerMinute: 60
    )]
    public function getOwnManifest(): array
    {
        $pluginId = $this->requireCallerId();
        $row = $this->findOwnRow($pluginId);
        if ($row === null) {
            return [];
        }
        $out = [];
        foreach (self::PROJECTABLE_MANIFEST_FIELDS as $field) {
            if (array_key_exists($field, $row)) {
                $out[$field] = $row[$field];
            }
        }
        return $out;
    }

    #[PluginCallable(
        description: 'List the ids and versions of OTHER enabled plugins on this node (the calling plugin\'s own row is omitted from the result so the caller doesn\'t have to filter it out). Returns a list of {name, version} entries. Orchestration plugins use this to branch on whether a companion plugin is installed — e.g. "send a Slack notification only if notification-slack is enabled." Requires the plugin_inventory_read permission since it discloses which other software the operator has chosen to enable alongside this plugin.',
        ratePerMinute: 30,
        permission: 'plugin_inventory_read'
    )]
    public function listEnabledPluginIds(): array
    {
        $pluginId = $this->requireCallerId();
        $out = [];
        foreach ($this->loader->listAllPlugins() as $row) {
            $name = $row['name'] ?? null;
            if (!is_string($name) || $name === $pluginId) {
                continue;
            }
            if (empty($row['enabled'])) {
                continue;
            }
            $out[] = [
                'name'    => $name,
                'version' => isset($row['version']) ? (string) $row['version'] : '',
            ];
        }
        return $out;
    }

    /**
     * Resolve the plugin's own row from the loader. Returns null if
     * the gateway-resolved id doesn't appear in the loader's view —
     * a state that shouldn't be reachable in practice (the gateway
     * resolved the id from a token derived from that same loader's
     * state file) but guarded against here so a stale fixture or
     * test seam can't crash the call path.
     *
     * @return array<string, mixed>|null
     */
    private function findOwnRow(string $pluginId): ?array
    {
        foreach ($this->loader->listAllPlugins() as $row) {
            if (($row['name'] ?? null) === $pluginId) {
                return $row;
            }
        }
        return null;
    }

    private function requireCallerId(): string
    {
        if ($this->callingPluginId === null) {
            // Defence in depth — the gateway always sets the caller
            // id before dispatching. A null here means someone
            // reached the method outside the gateway path.
            throw new RuntimeException(
                'PluginLookupService requires gateway-injected caller id'
            );
        }
        return $this->callingPluginId;
    }
}
