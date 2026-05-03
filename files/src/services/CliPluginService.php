<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

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

    public function __construct(
        PluginLoader $loader,
        ?PluginUninstallService $uninstallService = null
    ) {
        $this->loader = $loader;
        $this->uninstallService = $uninstallService;
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
            $output->error(
                'Failed to persist plugin state',
                ErrorCodes::GENERAL_ERROR
            );
            return;
        }

        $verb = $enabled ? 'enabled' : 'disabled';
        $output->success(
            "Plugin {$verb}: {$name}. Run 'eiou restart' for the change to take effect.",
            ['plugin' => $name, 'enabled' => $enabled, 'restart_required' => true]
        );
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
}
