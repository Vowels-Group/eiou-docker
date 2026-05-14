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
     * eiou plugin enable <name> [--grant-all | --grant <key,key,...>]
     *
     * When the plugin's manifest declares permissions, the operator
     * must consent to them before the gateway will route any
     * permission-gated call. Three paths to consent:
     *
     *   --grant-all          approve every permission the manifest declares
     *   --grant a,b,c        approve a specific subset (must be subset of manifest)
     *   (no flag, TTY)       interactive prompt — operator types y/N
     *   (no flag, non-TTY)   refused with a helpful message
     *
     * Plugins that don't declare any manifest permissions skip the
     * consent flow entirely (there's nothing to consent to).
     */
    public function enablePlugin(array $argv, ?CliOutputManager $output = null): void
    {
        $this->setEnabled($argv, $output, true);
    }

    /**
     * eiou plugin disable <name>
     *
     * Disable never prompts — the operator is reducing access, not
     * granting it. Approved permissions stay on file so a future
     * re-enable can skip the prompt when the manifest hasn't drifted.
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
                "Usage: eiou plugin " . ($enabled ? 'enable' : 'disable') . " <name>"
                . ($enabled ? ' [--grant-all | --grant <key,key,...>]' : ''),
                ErrorCodes::VALIDATION_ERROR
            );
            return;
        }

        $known = $this->loader->listAllPlugins();
        $row = null;
        foreach ($known as $candidate) {
            if (($candidate['name'] ?? null) === $name) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null) {
            $output->error(
                "Unknown plugin: {$name}. Run 'eiou plugin list' to see installed plugins.",
                ErrorCodes::NOT_FOUND
            );
            return;
        }

        // Permission consent — only on enable, only when manifest
        // declares any. Resolves to one of:
        //   - null       : pass-through (no manifest perms, or re-enable
        //                  with previously-approved set still covering)
        //   - list<string>: explicit approval to persist
        //   - false       : consent refused or required-but-missing; bail
        $approvals = null;
        if ($enabled) {
            $manifestPerms = is_array($row['permissions'] ?? null) ? $row['permissions'] : [];
            $approvals = $this->resolveCliApprovals($argv, $name, $manifestPerms, $output);
            if ($approvals === false) {
                return;
            }
        }

        if (!$this->loader->setEnabled($name, $enabled, $approvals === null ? null : $approvals)) {
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

        $payload = [
            'plugin' => $name,
            'enabled' => $enabled,
            'restart_required' => !$isSandboxed,
            'sandboxed' => $isSandboxed,
        ];
        if ($enabled && is_array($approvals)) {
            $payload['approved_permissions'] = $approvals;
        }
        $output->success($message, $payload);
    }

    /**
     * Resolve the CLI's permission-approval input into an explicit set
     * of grants (or null for "no manifest perms, nothing to grant"),
     * or false if the operator hasn't granted consent and we can't
     * proceed without it.
     *
     * Flag precedence: --grant-all wins over --grant. Combining both
     * is refused so the operator's intent stays unambiguous.
     *
     * @param list<string> $manifestPerms
     * @return list<string>|null|false
     */
    private function resolveCliApprovals(
        array $argv,
        string $name,
        array $manifestPerms,
        CliOutputManager $output
    ) {
        $grantAll = false;
        $grantSubset = null;
        for ($i = 4; $i < count($argv); $i++) {
            $arg = (string) $argv[$i];
            if ($arg === '--grant-all') {
                $grantAll = true;
                continue;
            }
            if ($arg === '--grant') {
                $grantSubset = $argv[$i + 1] ?? '';
                $i++;
                continue;
            }
            if (strpos($arg, '--grant=') === 0) {
                $grantSubset = substr($arg, strlen('--grant='));
                continue;
            }
            $output->error(
                "Unknown flag for 'plugin enable': {$arg}",
                ErrorCodes::VALIDATION_ERROR
            );
            return false;
        }

        // Manifest declares nothing → consent is a no-op. Surface the
        // flag-misuse case so an operator who typed --grant-all on a
        // plugin without permissions gets a clear message rather than
        // silent success.
        if ($manifestPerms === []) {
            if ($grantAll || $grantSubset !== null) {
                $output->info("Plugin '{$name}' declares no permissions — '--grant-all' / '--grant' is a no-op.");
            }
            return null;
        }

        if ($grantAll && $grantSubset !== null) {
            $output->error(
                "Pass either --grant-all or --grant <keys>, not both.",
                ErrorCodes::VALIDATION_ERROR
            );
            return false;
        }

        if ($grantAll) {
            $this->printManifestPermissions($name, $manifestPerms, $output, 'Approving (--grant-all):');
            return $manifestPerms;
        }

        if ($grantSubset !== null) {
            $keys = array_values(array_filter(array_map('trim', explode(',', $grantSubset)), 'strlen'));
            $unknown = array_values(array_diff($keys, $manifestPerms));
            if ($unknown !== []) {
                $output->error(
                    "Cannot grant permission(s) not in plugin '{$name}' manifest: "
                    . implode(', ', $unknown)
                    . ". Manifest declares: " . implode(', ', $manifestPerms),
                    ErrorCodes::VALIDATION_ERROR
                );
                return false;
            }
            $this->printManifestPermissions($name, $keys, $output, 'Approving (--grant):');
            return $keys;
        }

        // No flag — re-enable shortcut first: if the operator
        // previously approved a superset of the manifest, the loader
        // will accept setEnabled($name, true, null) and re-use the
        // existing approval set. Return null so the loader takes that
        // branch — no prompt needed for a no-op re-enable.
        $existing = $this->loader->getApprovedPermissions($name);
        if ($existing !== [] && array_diff($manifestPerms, $existing) === []) {
            return null;
        }

        // No flag and re-enable doesn't cover the manifest — try
        // interactive consent. Non-TTY (piped stdin, automation) must
        // use a flag so the script never silently hangs waiting for
        // input. Drift on a non-TTY context tells the operator which
        // keys are missing so they know what to put in --grant.
        $isTty = function_exists('posix_isatty') && @posix_isatty(STDIN);
        if (!$isTty) {
            $missing = array_values(array_diff($manifestPerms, $existing));
            $detail = $existing === []
                ? "requests permissions: " . implode(', ', $manifestPerms)
                : "requests new permissions not in the previously approved set: " . implode(', ', $missing);
            $output->error(
                "Plugin '{$name}' {$detail}. Re-run with --grant-all to approve them all, "
                . "or --grant <key,key,...> to approve a subset.",
                ErrorCodes::VALIDATION_ERROR
            );
            return false;
        }

        $this->printManifestPermissions($name, $manifestPerms, $output, 'This plugin requests these permissions:');
        fwrite(STDERR, "Grant all of the above and enable '{$name}'? [y/N] ");
        $answer = strtolower(trim((string) fgets(STDIN)));
        if ($answer !== 'y' && $answer !== 'yes') {
            $output->info("Enable cancelled — plugin '{$name}' remains disabled.");
            return false;
        }
        return $manifestPerms;
    }

    /**
     * Render the catalog description for a list of permission keys to
     * stderr so it's visible even when stdout is captured for JSON
     * mode. Each entry shows the key + the operator-facing label so the
     * operator can correlate what they're approving with the plugin's
     * documentation.
     *
     * @param list<string> $keys
     */
    private function printManifestPermissions(
        string $name,
        array $keys,
        CliOutputManager $output,
        string $heading
    ): void {
        // Skip noise in JSON mode — automation already has the list via
        // the success-payload's approved_permissions field.
        if ($output->isJsonMode()) {
            return;
        }
        fwrite(STDERR, $heading . "\n");
        foreach ($keys as $key) {
            $entry = PluginPermissionCatalog::get($key);
            $label = $entry !== null ? $entry['label'] : $key;
            fwrite(STDERR, "  - {$key}\n      {$label}\n");
        }
        fwrite(STDERR, "\n");
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
