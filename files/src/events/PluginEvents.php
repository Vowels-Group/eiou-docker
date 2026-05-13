<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Events;

/**
 * Plugin Lifecycle Events
 *
 * Event constants for plugin discovery and lifecycle, dispatched by
 * PluginLoader itself. Useful for plugins that want to observe other
 * plugins' lifecycles (e.g. a monitoring plugin that logs every plugin
 * that boots).
 *
 * Usage:
 *   EventDispatcher::getInstance()->subscribe(PluginEvents::PLUGIN_FAILED, function($data) {
 *       Logger::getInstance()->warning('plugin failed', $data);
 *   });
 *
 * Note: subscriptions made in register() will not observe other plugins'
 * PLUGIN_REGISTERED events because dispatch happens as each plugin is
 * registered in iteration order. Subscribe in boot() to observe registered
 * plugins that ran ahead of you; subscribe in register() only if you want
 * to catch the tail of the registration pass.
 */
class PluginEvents
{
    /**
     * Dispatched after a plugin's register() phase completes successfully.
     *
     * Event data:
     *   - name: string    - Plugin name (matches manifest)
     *   - version: string - Plugin version
     */
    public const PLUGIN_REGISTERED = 'plugin.registered';

    /**
     * Dispatched after a plugin's files have been written to
     * /etc/eiou/plugins/<name>/ by PluginInstallService. Fires once per
     * successful install; not fired for plugins seeded from the Docker
     * image at first boot (those are present before the event dispatcher
     * is wired anyway).
     *
     * The plugin is staged as DISABLED at this point — register() / boot()
     * have not run, and won't until the operator toggles it on and
     * restarts. Subscribers that want to observe activation should listen
     * for PLUGIN_REGISTERED / PLUGIN_BOOTED instead.
     *
     * Event data:
     *   - name: string    - Plugin name (matches manifest + directory)
     *   - version: string - Plugin version from the manifest
     *   - source: string  - Where the install came from (e.g. 'zip_upload')
     */
    public const PLUGIN_INSTALLED = 'plugin.installed';

    /**
     * Dispatched after a plugin's boot() phase completes successfully.
     *
     * Event data:
     *   - name: string    - Plugin name
     *   - version: string - Plugin version
     */
    public const PLUGIN_BOOTED = 'plugin.booted';

    /**
     * Dispatched when a plugin throws during discovery, register, or boot.
     *
     * The failing plugin is disabled for the rest of the process; core
     * bootstrap continues. This event fires AFTER the plugin's status has
     * been flipped to 'failed' and the error recorded.
     *
     * Event data:
     *   - name: string    - Plugin name
     *   - version: string - Plugin version
     *   - phase: string   - Which lifecycle phase failed: 'register' or 'boot'
     *   - error: string   - Exception message
     */
    public const PLUGIN_FAILED = 'plugin.failed';

    /**
     * Dispatched at the start of uninstall — before any side effects
     * (onUninstall hook, MySQL revoke, file deletion) run. Subscribers
     * that need to observe the PLUGIN's final state (before data is
     * wiped) should listen here.
     *
     * Event data:
     *   - name: string - Plugin name
     */
    public const PLUGIN_UNINSTALLING = 'plugin.uninstalling';

    /**
     * Dispatched after every uninstall step has run. The plugin's files,
     * MySQL user, tables, and credentials are all gone by the time this
     * fires (unless a step reported an error — see `steps` in the payload).
     *
     * Event data:
     *   - name: string    - Plugin name
     *   - success: bool   - True iff every step completed without error
     *   - steps: array    - Per-step status map: 'ok' / 'skipped' / 'error:<msg>'
     */
    public const PLUGIN_UNINSTALLED = 'plugin.uninstalled';

    /**
     * Dispatched after a successful plugin upgrade. The new plugin
     * directory is in place, the onUpgrade hook (if implemented) has
     * run, MySQL grants have been reconciled against the new manifest's
     * owned_tables, and the FPM pool has been reloaded if the plugin
     * was enabled at upgrade time. The old plugin directory is
     * preserved at `<pluginDir>.backup-<oldver>-<ts>/` for rollback.
     *
     * NOT dispatched for partial / rolled-back upgrades — those throw
     * before reaching the dispatch site so subscribers never observe a
     * "half upgraded" state.
     *
     * Event data:
     *   - name: string         — Plugin name
     *   - old_version: string  — Version recorded in the previous manifest
     *   - new_version: string  — Version in the new manifest
     *   - source: string       — Origin of the new bundle: 'zip_upload' or 'bundled'
     */
    public const PLUGIN_UPGRADED = 'plugin.upgraded';
}
