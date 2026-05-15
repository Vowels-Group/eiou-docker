<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Plugins;

use Eiou\Contracts\UpgradablePlugin;
use Eiou\Events\EventDispatcher;
use Eiou\Events\PluginEvents;
use Eiou\Utils\Logger;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * PluginUpgradeService
 *
 * Replaces an installed plugin's on-disk code with a newer version
 * while preserving the operator's state (MySQL tables, plugin user,
 * credentials, gateway token). Install-then-uninstall would lose all
 * that — DROPing owned_tables and revoking grants — so a real product
 * upgrade flow goes through here instead.
 *
 * Two entry points:
 *
 *   - `upgradeFromZip($zipPath, $originalFilename)` — operator-uploaded
 *     new version, validated through the same pipeline `installFromZip`
 *     uses (zip magic, entry walk, manifest, signature). Driven by the
 *     GUI's plugin-upload flow when the target id is already installed.
 *
 *   - `upgradeFromBundle($pluginId)` — image-baked new version (the
 *     `cp -rn` seed at startup deliberately doesn't overwrite, so a
 *     newer version in `/app/plugins/<id>/` stays invisible to operators
 *     until they trigger an upgrade explicitly). Driven by the GUI's
 *     "upgrade available" badge and the CLI's `eiou plugin upgrade`
 *     command.
 *
 * Flow, in order:
 *
 *   1. Validate the new bundle (via PluginInstallService::stageAndValidate
 *      for the zip path, or via a staged copy of the image dir for the
 *      bundled path).
 *   2. Read the old on-disk manifest — refuse if the plugin isn't
 *      actually installed (this is upgrade, not install).
 *   3. version_compare new vs old. Refuse:
 *        - Equal versions (nothing to do).
 *        - Downgrades (operator would have to uninstall + install
 *          explicitly to acknowledge the destructive intent).
 *   4. Honour the new manifest's optional `min_upgradable_from` — if
 *      the installed version is below the declared floor, refuse so the
 *      operator picks an intermediate version (or accepts the
 *      uninstall-and-reinstall data loss).
 *   5. Atomic swap: rename old dir to `<id>.backup-<oldver>-<ts>/`,
 *      rename staged new into the canonical location. On the second
 *      rename's failure, the first is rolled back before throwing.
 *   6. Run `UpgradablePlugin::onUpgrade()` if the new entry class
 *      implements it. Hook runs with the OLD MySQL grants still in
 *      effect (so the plugin can read/transform its existing data
 *      via the unchanged plugin user) and the NEW code loaded. A
 *      thrown exception triggers full rollback and the operator
 *      sees the failure.
 *   7. Reconcile grants against the new `owned_tables` — REVOKE ALL
 *      (clears the old set in one statement) then GRANT per-table
 *      (the new set). Both grow and shrink are handled.
 *   8. Re-export the credentials file (the on-disk creds JSON for
 *      sibling-container mounting) if the plugin is enabled — picks
 *      up any manifest-driven changes to the file shape across
 *      versions.
 *   9. Reload the FPM pool if the plugin is enabled. Disabled plugins
 *      will reload on their next enable; the dir is correct on disk
 *      and the boot reconcile fixes everything else.
 *  10. Fire PLUGIN_UPGRADED. Subscribers observe a fully-upgraded
 *      plugin — no half-upgraded states reach the dispatch site.
 *
 * Old plugin dirs ("backup-<oldver>-<ts>") accumulate on the plugins
 * volume; PluginLoader's boot pass prunes anything older than
 * `BACKUP_RETENTION_DAYS` so operators don't manage that disk usage
 * by hand.
 *
 * See docs/PLUGINS.md (Upgrading Plugins).
 */
class PluginUpgradeService
{
    /** Backup dirs older than this are pruned by the boot-time
     *  cleanup pass. 30d leaves a comfortable rollback window without
     *  letting old bundles accumulate forever; operators who need a
     *  longer window can preserve a specific backup by renaming it
     *  out of the .backup-<ver>-<ts> shape (the prune regex is
     *  anchored on that exact pattern).
     */
    public const BACKUP_RETENTION_DAYS = 30;

    /**
     * Path where bundled (image-baked) plugins live before being
     * seeded into the plugins volume. The bundled-upgrade path
     * reads new versions from here.
     */
    public const BUNDLED_PLUGINS_DIR = '/app/plugins';

    private string $pluginDir;
    private PluginInstallService $installService;
    private PluginLoader $loader;
    private PluginPoolService $poolService;
    private PluginUserService $userService;
    private PluginDbUserService $dbUserService;
    private PluginCredentialService $credentialService;
    private ?PluginCredentialsExportService $credentialsExport;
    /** @var object Loose-typed (ServiceContainer-like) so tests can supply a fixture. */
    private object $container;
    private ?Logger $logger;

    public function __construct(
        PluginInstallService $installService,
        PluginLoader $loader,
        PluginPoolService $poolService,
        PluginUserService $userService,
        PluginDbUserService $dbUserService,
        PluginCredentialService $credentialService,
        ?PluginCredentialsExportService $credentialsExport,
        object $container,
        ?Logger $logger = null,
        string $pluginDir = '/etc/eiou/plugins'
    ) {
        $this->installService = $installService;
        $this->loader = $loader;
        $this->poolService = $poolService;
        $this->userService = $userService;
        $this->dbUserService = $dbUserService;
        $this->credentialService = $credentialService;
        $this->credentialsExport = $credentialsExport;
        $this->container = $container;
        $this->logger = $logger;
        $this->pluginDir = rtrim($pluginDir, '/');
    }

    /**
     * Upgrade an installed plugin from an operator-uploaded zip. Returns
     * a step-status envelope on success; throws on validation or swap
     * failure.
     *
     * @return array{
     *     plugin_id: string,
     *     old_version: string,
     *     new_version: string,
     *     backup_dir: string,
     *     steps: array<string, string>,
     * }
     */
    public function upgradeFromZip(string $zipPath, ?string $originalFilename = null): array
    {
        $staged = $this->installService->stageAndValidate($zipPath);
        try {
            return $this->commitUpgrade(
                $staged['plugin_id'],
                $staged['staged_dir'],
                $staged['staging_parent'],
                $staged['manifest'],
                'zip_upload',
                $originalFilename
            );
        } catch (Throwable $e) {
            $this->installService->discardStaging($staged['staging_parent']);
            throw $e;
        }
    }

    /**
     * Upgrade an installed plugin from its image-baked version under
     * /app/plugins/<id>/. The image directory is copied into a staging
     * area first (so it goes through the same validation pipeline as a
     * zip upload would) before the atomic swap.
     */
    public function upgradeFromBundle(string $pluginId): array
    {
        $this->validatePluginId($pluginId);

        $bundleDir = static::BUNDLED_PLUGINS_DIR . '/' . $pluginId;
        if (!is_dir($bundleDir)) {
            throw new InvalidArgumentException(
                "No bundled version of '{$pluginId}' exists at {$bundleDir}"
            );
        }
        $bundleManifestPath = $bundleDir . '/plugin.json';
        if (!is_file($bundleManifestPath)) {
            throw new RuntimeException(
                "Bundle at {$bundleDir} has no plugin.json"
            );
        }

        // Stage by copying the bundle into a fresh .staging-<rand>/<id>/
        // directory next to the plugins root, mirroring the layout the
        // zip-extraction path produces. We deliberately don't run the
        // full zip-validation pipeline here — bundled plugins are
        // first-party and already passed image-build scrutiny — but we
        // do validate the manifest's shape so a malformed image bundle
        // can't poison the upgrade.
        $stagingParent = $this->pluginDir . '/.staging-bundle-' . bin2hex(random_bytes(8));
        if (!@mkdir($stagingParent, 0o755, false)) {
            throw new RuntimeException("Could not create staging directory");
        }
        try {
            $stagedDir = $stagingParent . '/' . $pluginId;
            if (!$this->copyDirRecursive($bundleDir, $stagedDir)) {
                throw new RuntimeException(
                    "Could not copy bundled plugin from {$bundleDir} to staging"
                );
            }
            $manifest = $this->readManifest($stagedDir);
            if (($manifest['name'] ?? null) !== $pluginId) {
                throw new RuntimeException(
                    "Bundle manifest's name doesn't match directory '{$pluginId}'"
                );
            }
            return $this->commitUpgrade(
                $pluginId,
                $stagedDir,
                $stagingParent,
                $manifest,
                'bundled',
                null
            );
        } catch (Throwable $e) {
            $this->installService->discardStaging($stagingParent);
            throw $e;
        }
    }

    /**
     * Inspect the bundled-plugin directory and the on-disk plugins
     * directory and return per-plugin upgrade availability. Used by
     * the GUI to render the "upgrade available" badge and by the
     * loader to surface the same fact in listAllPlugins().
     *
     * @return array<string, array{installed_version: string, bundled_version: string}>
     *         Keyed by plugin id; only includes plugins where the
     *         bundled version is strictly newer than the installed
     *         version. Plugins with no bundled counterpart or with
     *         equal versions are omitted.
     */
    public function availableBundledUpgrades(): array
    {
        $out = [];
        if (!is_dir(static::BUNDLED_PLUGINS_DIR)) {
            return $out;
        }
        $entries = @scandir(static::BUNDLED_PLUGINS_DIR) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (preg_match(PluginInstallService::PLUGIN_ID_PATTERN, $entry) !== 1) continue;

            $bundleManifestPath = static::BUNDLED_PLUGINS_DIR . '/' . $entry . '/plugin.json';
            $installedManifestPath = $this->pluginDir . '/' . $entry . '/plugin.json';
            if (!is_file($bundleManifestPath) || !is_file($installedManifestPath)) {
                continue;
            }
            $bundled = $this->decodeManifestSilently($bundleManifestPath);
            $installed = $this->decodeManifestSilently($installedManifestPath);
            if ($bundled === null || $installed === null) continue;
            $bv = (string)($bundled['version'] ?? '');
            $iv = (string)($installed['version'] ?? '');
            if ($bv === '' || $iv === '') continue;
            if (version_compare($bv, $iv, '>')) {
                $out[$entry] = [
                    'installed_version' => $iv,
                    'bundled_version'   => $bv,
                ];
            }
        }
        return $out;
    }

    /**
     * Sweep `.backup-<ver>-<ts>` directories older than
     * BACKUP_RETENTION_DAYS. Idempotent. Called from the loader's
     * boot pass; safe to call from operator-driven cleanup too.
     *
     * @return list<string> Absolute paths of backups that were removed.
     */
    public function pruneOldBackups(): array
    {
        $removed = [];
        if (!is_dir($this->pluginDir)) {
            return $removed;
        }
        $cutoff = time() - (self::BACKUP_RETENTION_DAYS * 86400);
        $entries = @scandir($this->pluginDir) ?: [];
        foreach ($entries as $entry) {
            if (!preg_match('/\.backup-[A-Za-z0-9._-]+-\d{8}-\d{6}$/', $entry)) continue;
            $path = $this->pluginDir . '/' . $entry;
            if (!is_dir($path)) continue;
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff) {
                $this->rrmdir($path);
                $removed[] = $path;
                $this->log('info', 'plugin_backup_pruned', [
                    'path' => $path,
                    'mtime' => $mtime,
                ]);
            }
        }
        return $removed;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Commit an already-validated upgrade: snapshot old → swap new in →
     * run hook → reconcile grants → reload pool → fire event.
     *
     * @param array<string, mixed> $newManifest
     */
    private function commitUpgrade(
        string $pluginId,
        string $stagedDir,
        string $stagingParent,
        array $newManifest,
        string $source,
        ?string $originalFilename
    ): array {
        $targetDir = $this->pluginDir . '/' . $pluginId;
        if (!is_dir($targetDir)) {
            throw new InvalidArgumentException(
                "Plugin '{$pluginId}' is not installed — install it first, then upgrade"
            );
        }
        $oldManifest = $this->readManifest($targetDir);

        $oldVersion = (string)($oldManifest['version'] ?? '');
        $newVersion = (string)($newManifest['version'] ?? '');
        if ($oldVersion === '' || $newVersion === '') {
            throw new InvalidArgumentException(
                "Both manifests must declare a `version` field"
            );
        }
        $cmp = version_compare($newVersion, $oldVersion);
        if ($cmp === 0) {
            throw new InvalidArgumentException(
                "Plugin '{$pluginId}' is already at version {$newVersion}"
            );
        }
        if ($cmp < 0) {
            throw new InvalidArgumentException(
                "Refusing downgrade of '{$pluginId}' from {$oldVersion} to {$newVersion}. "
                . "Uninstall and reinstall to accept the destructive intent."
            );
        }

        $minFrom = $newManifest['min_upgradable_from'] ?? null;
        if (is_string($minFrom) && $minFrom !== ''
            && version_compare($oldVersion, $minFrom, '<')) {
            throw new InvalidArgumentException(
                "Cannot upgrade '{$pluginId}' from {$oldVersion} to {$newVersion}: "
                . "new manifest declares min_upgradable_from={$minFrom}. "
                . "Install an intermediate version first, or uninstall and reinstall."
            );
        }

        // Step 1: snapshot old → backup
        $verSlug = preg_replace('/[^A-Za-z0-9.-]/', '_', $oldVersion);
        $backupDir = $this->pluginDir . '/' . $pluginId
                   . '.backup-' . $verSlug . '-' . date('Ymd-His');
        if (!@rename($targetDir, $backupDir)) {
            throw new RuntimeException(
                "Could not snapshot old plugin directory to {$backupDir}"
            );
        }

        $steps = [];

        // Step 2: swap new in
        if (!@rename($stagedDir, $targetDir)) {
            // First rename succeeded; second failed. Roll back the
            // first so the operator's plugin doesn't disappear.
            @rename($backupDir, $targetDir);
            throw new RuntimeException(
                "Could not move staged plugin into {$targetDir}; restored from backup"
            );
        }
        $steps['swap'] = 'ok';

        try {
            // Step 3: onUpgrade hook (if implemented). Hook runs with
            // OLD grants intact — reconcileGrants runs next.
            $steps['on_upgrade'] = $this->runOnUpgradeHook(
                $pluginId, $targetDir, $newManifest, $oldVersion, $newVersion
            );

            // Step 4: reconcile grants for the new owned_tables. REVOKE
            // ALL clears the old set in one statement; GRANT per-table
            // re-builds the new set (which may add or remove tables vs
            // the old). The plugin's MySQL user itself is unchanged.
            $steps['reconcile_grants'] = $this->reconcileGrants($pluginId, $newManifest);

            // Step 5: re-export credentials file (sibling-mountable JSON)
            // so any manifest-driven format change in the new version
            // is reflected without waiting for a node restart.
            $steps['re_export_credentials'] = $this->reExportCredentialsIfEnabled($pluginId);

            // Step 6: reload FPM pool — only when the plugin is enabled,
            // otherwise the next enable picks up the new code naturally.
            $steps['reload_pool'] = $this->reloadPoolIfEnabled($pluginId);

        } catch (Throwable $e) {
            // Hook or reconcile threw. Roll back the directory swap so
            // the operator's plugin is restored to the old version. The
            // failed-new dir is preserved at <targetDir>.failed-<ts> for
            // post-mortem rather than discarded.
            $failedDir = $targetDir . '.failed-' . date('Ymd-His');
            @rename($targetDir, $failedDir);
            @rename($backupDir, $targetDir);
            throw new RuntimeException(
                "Upgrade aborted after directory swap; rolled back to v{$oldVersion}. "
                . "Failed bundle preserved at {$failedDir} for investigation. "
                . "Cause: " . $e->getMessage(),
                0,
                $e
            );
        }

        $this->installService->discardStaging($stagingParent);

        $logger = $this->logger ?? Logger::getInstance();
        $logger->info('plugin_upgraded', [
            'plugin' => $pluginId,
            'old_version' => $oldVersion,
            'new_version' => $newVersion,
            'source' => $source,
            'original_filename' => $originalFilename,
            'backup_dir' => $backupDir,
            'steps' => $steps,
        ]);

        EventDispatcher::getInstance()->dispatch(PluginEvents::PLUGIN_UPGRADED, [
            'name' => $pluginId,
            'old_version' => $oldVersion,
            'new_version' => $newVersion,
            'source' => $source,
        ]);

        return [
            'plugin_id' => $pluginId,
            'old_version' => $oldVersion,
            'new_version' => $newVersion,
            'backup_dir' => $backupDir,
            'steps' => $steps,
        ];
    }

    /**
     * If the new entry class implements UpgradablePlugin, instantiate
     * it (with the new on-disk code) and invoke onUpgrade(). The
     * plugin's old MySQL grants are still active at this point so the
     * hook can read/transform existing rows via $container->getPluginPdo().
     *
     * Returns 'ok' / 'skipped' / 'error:<msg>' for the step status map.
     * Throws on hook exception — the caller rolls back the swap.
     */
    private function runOnUpgradeHook(
        string $pluginId,
        string $newPluginDir,
        array $newManifest,
        string $oldVersion,
        string $newVersion
    ): string {
        $entryClass = (string)($newManifest['entryClass'] ?? '');
        if ($entryClass === '') return 'skipped';

        $autoload = $newManifest['autoload']['psr-4'] ?? null;
        if (!is_array($autoload) || $autoload === []) return 'skipped';

        // Register a one-shot PSR-4 autoloader scoped to the new dir
        // so we can instantiate the new entry class without polluting
        // global autoload registrations. Match PluginLoader's loader
        // shape for consistency.
        foreach ($autoload as $prefix => $relDir) {
            if (!is_string($prefix) || !is_string($relDir)) continue;
            $prefix = trim($prefix, '\\') . '\\';
            $baseDir = rtrim($newPluginDir . '/' . trim($relDir, '/'), '/') . '/';
            spl_autoload_register(function (string $class) use ($prefix, $baseDir): void {
                if (strpos($class, $prefix) !== 0) return;
                $relative = substr($class, strlen($prefix));
                $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
                if (is_file($file)) require_once $file;
            });
        }

        if (!class_exists($entryClass)) return 'skipped';
        $reflection = new \ReflectionClass($entryClass);
        if (!$reflection->implementsInterface(UpgradablePlugin::class)) {
            return 'skipped';
        }

        try {
            $instance = $reflection->newInstance();
            /** @var UpgradablePlugin $instance */
            $instance->onUpgrade($this->container, $oldVersion, $newVersion);
            return 'ok';
        } catch (Throwable $e) {
            // Surface the failure with a tight message; caller wraps
            // it into the rollback exception.
            $this->log('warning', 'plugin_upgrade_hook_threw', [
                'plugin' => $pluginId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }

    /**
     * REVOKE ALL → GRANT per new owned_tables entry. Symmetric with
     * the loader's reconcile pass, but scoped to this single plugin
     * and run synchronously so the upgrade returns a deterministic
     * status. Returns 'ok' / 'skipped' / 'error:<msg>'.
     *
     * @param array<string, mixed> $manifest
     */
    private function reconcileGrants(string $pluginId, array $manifest): string
    {
        $dbConfig = $manifest['database'] ?? null;
        if (!is_array($dbConfig) || ($dbConfig['user'] ?? false) !== true) {
            return 'skipped';
        }
        if (!$this->credentialService->exists($pluginId)) {
            // No DB user exists for this plugin — nothing to reconcile.
            return 'skipped';
        }
        $owned = (array)($dbConfig['owned_tables'] ?? []);
        try {
            $this->dbUserService->revoke($pluginId);
            $this->dbUserService->grant($pluginId, $owned);
            return 'ok';
        } catch (Throwable $e) {
            return 'error:' . $e->getMessage();
        }
    }

    /**
     * Re-export the credentials file. Idempotent; safe to skip when
     * the plugin is disabled (no consumer) or when no credential row
     * exists (no DB user to export).
     */
    private function reExportCredentialsIfEnabled(string $pluginId): string
    {
        if ($this->credentialsExport === null) return 'skipped';
        if (!$this->isPluginEnabled($pluginId)) return 'skipped';
        if (!$this->credentialService->exists($pluginId)) return 'skipped';
        $plaintext = $this->credentialService->getPlaintext($pluginId);
        if ($plaintext === null) return 'skipped';
        return $this->credentialsExport->export($pluginId, $plaintext) ? 'ok' : 'error:export_failed';
    }

    /**
     * Re-run applyPool on an enabled plugin so its FPM pool reloads
     * the new code. Disabled plugins are not re-pooled — the next
     * enable does the right thing via the normal enable path.
     *
     * The snippet + zones content is the same as the supervisor would
     * write under steady state (the route shape didn't change across
     * the upgrade), but the act of re-applying is what triggers
     * SIGUSR2 to FPM, which recycles workers so they pick up the new
     * on-disk plugin code rather than continuing to hold stale class
     * definitions from before the directory swap.
     */
    private function reloadPoolIfEnabled(string $pluginId): string
    {
        if (!$this->isPluginEnabled($pluginId)) return 'skipped';
        try {
            $systemUser = $this->userService->systemUsername($pluginId);
            [$snippet, $zones] = $this->loader->renderActiveSandboxArtifacts();
            // Force-rotate = false: the gateway token is unrelated to
            // the plugin version and rotating it here would invalidate
            // any in-flight gateway calls the plugin made via the old
            // code's bearer cache.
            $ok = $this->poolService->applyPool($pluginId, $systemUser, $snippet, false, $zones);
            return $ok ? 'ok' : 'error:apply_pool_failed';
        } catch (Throwable $e) {
            return 'error:' . $e->getMessage();
        }
    }

    /**
     * Best-effort enabled-state lookup. Reads from PluginLoader's view
     * of state file; absent state file → not enabled.
     */
    private function isPluginEnabled(string $pluginId): bool
    {
        foreach ($this->loader->listAllPlugins() as $row) {
            if (($row['name'] ?? null) === $pluginId) {
                return !empty($row['enabled']);
            }
        }
        return false;
    }

    /**
     * Read + JSON-decode a plugin manifest. Throws on read or parse
     * failure so the caller can surface a clear message.
     *
     * @return array<string, mixed>
     */
    private function readManifest(string $pluginDir): array
    {
        $path = $pluginDir . '/plugin.json';
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Could not read manifest at {$path}");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Manifest at {$path} is not a JSON object");
        }
        return $decoded;
    }

    /**
     * Silent variant used by `availableBundledUpgrades` — returns null
     * on any read/parse error rather than throwing, because that path
     * surveys every plugin and a single malformed manifest shouldn't
     * abort the whole survey.
     *
     * @return array<string, mixed>|null
     */
    private function decodeManifestSilently(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function validatePluginId(string $pluginId): void
    {
        if (!preg_match(PluginInstallService::PLUGIN_ID_PATTERN, $pluginId)) {
            throw new InvalidArgumentException(
                "Invalid plugin id '{$pluginId}': must be kebab-case, 1-64 chars"
            );
        }
    }

    /**
     * Copy a directory tree. Used by the bundled-upgrade path to move
     * the image bundle into staging without the zip ceremony.
     */
    private function copyDirRecursive(string $src, string $dst): bool
    {
        if (!is_dir($src)) return false;
        if (!@mkdir($dst, 0o755, true) && !is_dir($dst)) return false;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($src) + 1);
            $target = $dst . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0o755, true)) return false;
            } else {
                if (!@copy($item->getPathname(), $target)) return false;
            }
        }
        return true;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function log(string $level, string $event, array $context = []): void
    {
        if ($this->logger === null) return;
        $this->logger->{$level}($event, $context);
    }
}
