<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Plugins;

use Eiou\Cli\CliOutputManager;
use Eiou\Core\ErrorCodes;

/**
 * CLI Plugin Service
 *
 * Handles `eiou plugin <list|enable|disable>` subcommands. Each operation is
 * a thin wrapper around `PluginLoader` — list reads the manifest + runtime
 * state, enable/disable flips the persisted flag in
 * `/etc/eiou/config/plugins.json`.
 *
 * None of these operations restart the node. Enable/disable updates the
 * persisted state immediately but the running processes keep their booted
 * service graph until the operator runs `eiou restart` (or the equivalent
 * REST API / GUI action). This mirrors the GUI behaviour so operators don't
 * have to learn two restart models.
 */
class CliPluginService
{
    private PluginLoader $loader;
    private ?PluginUninstallService $uninstallService;
    private ?PluginUpgradeService $upgradeService;
    private ?PluginCronService $cronService;

    public function __construct(
        PluginLoader $loader,
        ?PluginUninstallService $uninstallService = null,
        ?PluginUpgradeService $upgradeService = null,
        ?PluginCronService $cronService = null
    ) {
        $this->loader = $loader;
        $this->uninstallService = $uninstallService;
        $this->upgradeService = $upgradeService;
        $this->cronService = $cronService;
    }

    /**
     * eiou plugin [list]
     *
     * Human: prints a compact table (name, version, enabled, status, license).
     * JSON: returns the full listAllPlugins() payload so scripts see author,
     *       homepage, changelog, description, etc. without a schema split.
     */
    public function listPlugins(array $argv, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();
        $plugins = $this->loader->listAllPlugins();

        if ($output->isJsonMode()) {
            $output->success(
                'Plugin list retrieved',
                ['plugins' => $plugins]
            );
            return;
        }

        if (count($plugins) === 0) {
            $output->info('No plugins installed. Drop a plugin folder into /etc/eiou/plugins/ and re-run this command.');
            return;
        }

        $rows = [];
        foreach ($plugins as $p) {
            $rows[] = [
                $p['name'],
                $p['version'],
                $p['enabled'] ? 'yes' : 'no',
                $p['status'],
                $p['license'] ?? '—',
            ];
        }
        $output->table(
            ['NAME', 'VERSION', 'ENABLED', 'STATUS', 'LICENSE'],
            $rows,
            'Installed plugins'
        );
    }

    /**
     * eiou plugin enable <name>
     */
    public function enablePlugin(array $argv, ?CliOutputManager $output = null): void
    {
        $this->setEnabled($argv, $output, true);
    }

    /**
     * eiou plugin disable <name>
     */
    public function disablePlugin(array $argv, ?CliOutputManager $output = null): void
    {
        $this->setEnabled($argv, $output, false);
    }

    private function setEnabled(array $argv, ?CliOutputManager $output, bool $enabled): void
    {
        $output = $output ?? CliOutputManager::getInstance();
        $name = $argv[3] ?? '';

        // Kebab-case alphanumerics only — keeps arbitrary keys out of the
        // state file even if the shell is doing something strange.
        if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9-_]{0,63}$/i', $name)) {
            $output->error(
                "Usage: eiou plugin " . ($enabled ? 'enable' : 'disable') . " <name>",
                ErrorCodes::VALIDATION_ERROR
            );
            return;
        }

        $known = array_column($this->loader->listAllPlugins(), 'name');
        if (!in_array($name, $known, true)) {
            $output->error(
                "Unknown plugin: {$name}. Run 'eiou plugin list' to see installed plugins.",
                ErrorCodes::NOT_FOUND
            );
            return;
        }

        if (!$this->loader->setEnabled($name, $enabled)) {
            // Surface the actual failure stage rather than the legacy
            // generic "Failed to persist plugin state" string, which
            // historically lit up for three distinct failure modes
            // (refused, isolation, sandbox, state) and made operator
            // diagnostics needlessly hard.
            $failure = $this->loader->getLastSetEnabledFailure();
            $message = $failure['message'] ?? 'Plugin state change failed (no detail available)';
            $output->error($message, ErrorCodes::GENERAL_ERROR);
            return;
        }

        $verb = $enabled ? 'enabled' : 'disabled';

        // Sandboxed plugins don't need a node restart on toggle — their
        // FPM pool + nginx route already reloaded gracefully inside
        // applyPool/dropPool via the supervisor. Restart is only
        // needed for in-process plugins whose register()/boot() runs
        // bind at PHP-FPM master startup.
        $isSandboxed = false;
        foreach ($this->loader->listAllPlugins() as $row) {
            if (($row['name'] ?? null) === $name) {
                $isSandboxed = !empty($row['sandboxed']);
                break;
            }
        }

        $message = $isSandboxed
            ? "Plugin {$verb}: {$name}. Change took effect immediately (sandboxed)."
            : "Plugin {$verb}: {$name}. Run 'eiou restart' for the change to take effect.";

        $output->success($message, [
            'plugin' => $name,
            'enabled' => $enabled,
            'restart_required' => !$isSandboxed,
            'sandboxed' => $isSandboxed,
        ]);
    }

    /**
     * eiou plugin uninstall <name>
     *
     * Runs the full uninstall flow: onUninstall hook (if plugin implements
     * UninstallablePlugin), REVOKE, DROP TABLE for every owned table, DROP
     * USER, DELETE credentials row, rm -rf plugin dir, remove from state.
     * The plugin must be disabled first — the service refuses to uninstall
     * an enabled plugin.
     *
     * Per-step status is returned so operators can see exactly what happened
     * (ok / skipped / error:<msg>) — especially helpful when something
     * partial-fails and the operator needs to know what still needs manual
     * cleanup.
     */
    public function uninstallPlugin(array $argv, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();
        $name = $argv[3] ?? '';

        if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9-_]{0,63}$/i', $name)) {
            $output->error(
                "Usage: eiou plugin uninstall <name>",
                ErrorCodes::VALIDATION_ERROR
            );
            return;
        }

        if ($this->uninstallService === null) {
            $output->error(
                'Plugin uninstall service is not available in this context',
                ErrorCodes::GENERAL_ERROR
            );
            return;
        }

        try {
            $result = $this->uninstallService->uninstall($name);
        } catch (\InvalidArgumentException $e) {
            $output->error($e->getMessage(), ErrorCodes::NOT_FOUND);
            return;
        } catch (\RuntimeException $e) {
            // "Cannot uninstall enabled plugin" lands here.
            $output->error($e->getMessage(), ErrorCodes::VALIDATION_ERROR);
            return;
        }

        if ($result['success']) {
            $output->success(
                "Plugin uninstalled: {$name}",
                $result
            );
        } else {
            $output->error(
                "Plugin uninstall completed with errors — see 'steps' for details",
                ErrorCodes::GENERAL_ERROR,
                500,
                $result
            );
        }
    }

    /**
     * eiou plugin upgrade <name>
     *
     * Replaces an installed plugin's on-disk code with the version baked
     * into the current image (`/app/plugins/<name>/`). Preserves the
     * plugin's MySQL tables, user, credentials, and gateway token —
     * uninstall + install would lose all of that, which is the whole
     * reason this command exists.
     *
     * Flow at a glance:
     *
     *   1. The image's bundled version must be strictly newer than what's
     *      installed; same version is "nothing to do" (refused), downgrade
     *      is refused outright (the operator would have to uninstall to
     *      acknowledge the destructive intent).
     *   2. The new manifest's `min_upgradable_from` field is honoured —
     *      if the installed version is below that floor, the operator
     *      gets a clear error telling them to install an intermediate
     *      version first.
     *   3. Old directory is renamed to `<name>.backup-<oldver>-<ts>/`
     *      next to the live plugin dir, kept for 30 days for rollback.
     *   4. If the plugin's entry class implements `UpgradablePlugin`, its
     *      `onUpgrade()` hook runs with old grants still active.
     *   5. MySQL grants are reconciled against the new manifest's
     *      `owned_tables` — REVOKE ALL then GRANT per-table.
     *   6. If the plugin is currently enabled, the FPM pool is reloaded
     *      so workers pick up the new code. Disabled plugins reload
     *      on their next enable.
     *
     * For uploading a new version from a zip (the GUI flow), see
     * `PluginUpgradeService::upgradeFromZip()`.
     */
    public function upgradePlugin(array $argv, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();
        $name = $argv[3] ?? '';

        if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9-_]{0,63}$/i', $name)) {
            $output->error(
                "Usage: eiou plugin upgrade <name>",
                ErrorCodes::VALIDATION_ERROR
            );
            return;
        }

        if ($this->upgradeService === null) {
            $output->error(
                'Plugin upgrade service is not available in this context',
                ErrorCodes::GENERAL_ERROR
            );
            return;
        }

        try {
            $result = $this->upgradeService->upgradeFromBundle($name);
        } catch (\InvalidArgumentException $e) {
            // 400-class — refused upgrade attempts (no bundle, same
            // version, downgrade, min_upgradable_from violation, etc.).
            $output->error($e->getMessage(), ErrorCodes::VALIDATION_ERROR);
            return;
        } catch (\RuntimeException $e) {
            // 500-class — supervisor / filesystem / hook failures.
            $output->error($e->getMessage(), ErrorCodes::GENERAL_ERROR);
            return;
        }

        // Check whether any step downgraded to error:<msg>. The directory
        // swap is the load-bearing step; if it failed the whole upgrade
        // would have thrown above. Anything below that (grant reconcile,
        // pool reload, credentials re-export) might surface a partial
        // error — surface it but don't fail-loud because the new code is
        // already on disk.
        $stepErrors = [];
        foreach ($result['steps'] ?? [] as $stepName => $status) {
            if (strpos((string) $status, 'error:') === 0) {
                $stepErrors[$stepName] = $status;
            }
        }
        if ($stepErrors !== []) {
            $output->error(
                "Plugin upgraded but post-swap steps had errors — see 'steps' for details",
                ErrorCodes::GENERAL_ERROR,
                500,
                $result
            );
            return;
        }

        $output->success(
            "Plugin upgraded: {$name} ({$result['old_version']} → {$result['new_version']})",
            $result
        );
    }

    /**
     * eiou plugin cron-tick
     *
     * One pass of the cron scheduler. Iterates enabled + sandboxed
     * plugins, fires any cron entries whose interval has elapsed since
     * their last fire, returns a per-tick report. Driven externally by
     * startup.sh's plugin_cron_poller at one-minute cadence; operators
     * can also invoke it manually for diagnostics or forced-fire
     * scenarios. Idempotent on the no-work path.
     */
    public function cronTick(array $argv, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();
        if ($this->cronService === null) {
            $output->error(
                'Plugin cron service not initialized',
                ErrorCodes::GENERAL_ERROR
            );
            return;
        }
        $report = $this->cronService->tick();

        if ($output->isJsonMode()) {
            $output->success('Plugin cron tick complete', $report);
            return;
        }

        $firedCount   = count($report['fired']);
        $skippedCount = count($report['skipped']);
        $errorCount   = count($report['errors']);
        if ($firedCount === 0 && $skippedCount === 0 && $errorCount === 0) {
            $output->info('No cron entries to evaluate.');
            return;
        }
        $output->info(sprintf(
            'Plugin cron tick: fired=%d skipped=%d errors=%d',
            $firedCount, $skippedCount, $errorCount
        ));
        foreach ($report['fired'] as $f) {
            $output->info(sprintf('  fired   %s.%s (every %dm)', $f['plugin'], $f['action'], $f['interval']));
        }
        foreach ($report['errors'] as $e) {
            $output->info(sprintf('  error   %s.%s: %s', $e['plugin'], $e['action'], $e['reason']));
        }
    }
}
