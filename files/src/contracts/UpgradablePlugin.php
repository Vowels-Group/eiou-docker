<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Contracts;

use Eiou\Services\ServiceContainer;

/**
 * Optional hook a plugin implements to migrate state across versions.
 *
 * Called during `PluginUpgradeService::upgradeFromZip()` and
 * `PluginUpgradeService::upgradeFromBundle()` AFTER the new plugin
 * directory has been atomically swapped in but BEFORE the per-plugin
 * FPM pool is reloaded. The hook runs:
 *
 *   - As the wallet pool (www-data), not the plugin pool, so it has
 *     full container privileges. This is the same context register()
 *     and boot() run under during in-process plugin loading.
 *   - With the plugin's MySQL grants from the *previous* version still
 *     applied. New `owned_tables` additions from the new manifest are
 *     not yet granted; new tables won't exist yet. The migration logic
 *     can `CREATE TABLE IF NOT EXISTS` against the upcoming grants
 *     (the reconcile pass after this hook adds them) or use the still-
 *     valid old grants to read/transform existing data.
 *   - With the NEW version's code loaded — `$container->getPluginPdo()`
 *     returns a PDO authenticated as the unchanged plugin user, and
 *     `$this` resolves to the new entry class.
 *
 * Typical implementations:
 *
 *   - Rename a column on an `owned_tables` entry whose name didn't
 *     change in the new manifest but whose schema did
 *   - Backfill a new column the new version's code expects
 *   - Drop or rename an old table that's gone from the new manifest
 *     (the upgrade flow does NOT auto-drop tables removed from
 *     owned_tables — operators may want to keep the data)
 *   - Sync external state with the version delta
 *
 * Implementations MUST be idempotent: a failed upgrade may be retried,
 * and the same `onUpgrade` may fire twice for the same delta. Throwing
 * is permitted; the upgrade service catches, rolls back the directory
 * swap from the `.backup-<oldver>-<ts>` snapshot, and surfaces the
 * failure to the operator. Boot reconcile then re-establishes the
 * old MySQL grants and pool config so the operator can investigate.
 *
 * Plugins that don't need cross-version migration simply don't
 * implement this interface — the upgrade flow handles the directory
 * swap, manifest re-parse, grant reconcile, and pool reload
 * automatically.
 *
 * See docs/PLUGINS.md (Upgrading Plugins).
 */
interface UpgradablePlugin extends PluginInterface
{
    /**
     * Called once per upgrade transition. $oldVersion is the version
     * recorded in the previous on-disk manifest; $newVersion is the
     * version in the new manifest (and matches `$this->getVersion()`).
     *
     * Both are semver-ish strings as authored in plugin.json — the
     * service hands them to `version_compare()` to decide direction,
     * so equal versions skip the hook entirely.
     */
    public function onUpgrade(
        ServiceContainer $container,
        string $oldVersion,
        string $newVersion
    ): void;
}
