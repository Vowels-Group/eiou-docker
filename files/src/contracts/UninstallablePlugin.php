<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Contracts;

use Eiou\Services\ServiceContainer;

/**
 * Optional hook a plugin implements to clean up before uninstall.
 *
 * Called during `PluginUninstallService::uninstall()` BEFORE the plugin's
 * MySQL privileges are revoked — so the plugin can still query / delete
 * its own data if that matters (e.g. ping an external service to revoke
 * a subscription, purge a cache, write a final audit log row).
 *
 * Implementations MUST be idempotent: the uninstall flow may retry after
 * a partial failure, and a half-cleaned up plugin should not break the
 * retry. Throwing is permitted but does NOT block uninstall — the
 * service logs the failure, events fire, and the remaining steps
 * (privilege revocation, table drop, user drop, config cleanup, file
 * removal) still run. The plugin's chance to clean up ends here.
 *
 * Plugins that don't need custom cleanup simply don't implement this
 * interface — the core uninstall flow handles table + user + file
 * removal automatically from the manifest-declared owned_tables list.
 *
 * See docs/PLUGIN_ISOLATION.md §10.
 */
interface UninstallablePlugin extends PluginInterface
{
    /**
     * Called once during uninstall, before MySQL privileges are revoked.
     * The plugin's `getPluginPdo()` connection is still authenticated
     * and has full grants at this moment.
     */
    public function onUninstall(ServiceContainer $container): void;
}
