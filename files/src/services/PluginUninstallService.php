<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Contracts\UninstallablePlugin;
use Eiou\Events\EventDispatcher;
use Eiou\Events\PluginEvents;
use Eiou\Utils\Logger;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Uninstalls a plugin end-to-end: runs its onUninstall() hook (if any),
 * revokes MySQL privileges, drops the plugin's owned tables, drops its
 * MySQL user, deletes its credential row, removes the plugin's files
 * from /etc/eiou/plugins/, and removes its entry from plugins.json.
 *
 * Every step is idempotent. Partial failure in any single step is
 * logged, events fire (PLUGIN_UNINSTALLING / PLUGIN_UNINSTALLED), and
 * the boot-time reconciler self-heals whatever was left in a mixed
 * state — but the operator sees the specific failure in the return
 * value so they know what to clean up manually if anything.
 *
 * Safety: the plugin must be DISABLED before uninstall. The service
 * refuses to uninstall an enabled plugin — enable/disable is cheap,
 * uninstall is permanent. The GUI surfaces this as a two-step flow
 * (disable → confirm uninstall); the CLI / REST both enforce it.
 *
 * See docs/PLUGIN_ISOLATION.md §10.
 */
class PluginUninstallService
{
    public const PLUGIN_ID_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/';

    private PluginLoader $loader;
    private PluginCredentialService $credentials;
    private PluginDbUserService $dbUser;
    private PluginPdoFactory $pdoFactory;
    private PDO $rootPdo;
    private ?Logger $logger;
    private string $pluginDir;

    public function __construct(
        PluginLoader $loader,
        PluginCredentialService $credentials,
        PluginDbUserService $dbUser,
        PluginPdoFactory $pdoFactory,
        PDO $rootPdo,
        ?Logger $logger = null,
        string $pluginDir = '/etc/eiou/plugins'
    ) {
        $this->loader = $loader;
        $this->credentials = $credentials;
        $this->dbUser = $dbUser;
        $this->pdoFactory = $pdoFactory;
        $this->rootPdo = $rootPdo;
        $this->logger = $logger;
        $this->pluginDir = rtrim($pluginDir, '/');
    }

    /**
     * Remove the plugin completely.
     *
     * Returns a `{step: status}` map so the caller (CLI, REST, GUI) can
     * render step-by-step feedback. Each step's status is one of
     * 'ok' / 'skipped' / 'error:<msg>'. The overall method does NOT
     * throw — a hostile plugin that breaks one step must not prevent
     * the rest of the cleanup from running.
     *
     * @return array{
     *     plugin_id: string,
     *     steps: array<string, string>,
     *     success: bool,
     * }
     */
    public function uninstall(string $pluginId): array
    {
        $this->validatePluginId($pluginId);

        if (!$this->isPluginOnDisk($pluginId)) {
            throw new InvalidArgumentException(
                "Plugin '{$pluginId}' not found in {$this->pluginDir}"
            );
        }
        if ($this->isPluginEnabled($pluginId)) {
            throw new RuntimeException(
                "Cannot uninstall enabled plugin '{$pluginId}'; disable it first"
            );
        }

        EventDispatcher::getInstance()->dispatch(PluginEvents::PLUGIN_UNINSTALLING, [
            'name' => $pluginId,
        ]);

        $manifest = $this->readManifest($pluginId);
        $ownedTables = [];
        if (is_array($manifest) && isset($manifest['database']['owned_tables'])
            && is_array($manifest['database']['owned_tables'])) {
            foreach ($manifest['database']['owned_tables'] as $t) {
                if (is_string($t)) {
                    $ownedTables[] = $t;
                }
            }
        }

        $steps = [];

        // 1. onUninstall() hook — runs while the plugin still has MySQL
        //    grants, so it can query / delete its own data.
        $steps['on_uninstall'] = $this->runOnUninstallHook($pluginId);

        // 2. Revoke privileges so steps 3+ run against a locked-out user.
        //    If there are no credentials the plugin never had grants, skip.
        if ($this->credentials->exists($pluginId)) {
            $steps['revoke'] = $this->safeStep(fn() => $this->dbUser->revoke($pluginId));
        } else {
            $steps['revoke'] = 'skipped';
        }

        // 3. Drop owned tables. Each table is dropped individually so a
        //    missing table (already dropped, never created) doesn't abort
        //    the rest.
        $steps['drop_tables'] = $this->dropOwnedTables($ownedTables);

        // 4. Drop the MySQL user entirely.
        if ($this->credentials->exists($pluginId) || $this->dbUser->userExists($pluginId)) {
            $steps['drop_user'] = $this->safeStep(fn() => $this->dbUser->dropUser($pluginId));
        } else {
            $steps['drop_user'] = 'skipped';
        }

        // 5. Drop the credential row. Also purge the PDO cache so any
        //    lingering connection doesn't outlive the user it authed as.
        $this->pdoFactory->purge($pluginId);
        if ($this->credentials->exists($pluginId)) {
            $steps['drop_credentials'] = $this->safeStep(fn() => $this->credentials->delete($pluginId));
        } else {
            $steps['drop_credentials'] = 'skipped';
        }

        // 6. Remove the plugin's files from /etc/eiou/plugins/<id>/.
        $steps['remove_files'] = $this->removePluginDir($pluginId);

        // 7. Remove the plugin entry from plugins.json.
        $steps['remove_state'] = $this->removeFromStateFile($pluginId);

        $allOk = true;
        foreach ($steps as $status) {
            if (strpos($status, 'error:') === 0) {
                $allOk = false;
                break;
            }
        }

        EventDispatcher::getInstance()->dispatch(PluginEvents::PLUGIN_UNINSTALLED, [
            'name' => $pluginId,
            'success' => $allOk,
            'steps' => $steps,
        ]);

        $this->log('info', 'plugin_uninstalled', [
            'plugin_id' => $pluginId,
            'success' => $allOk,
            'steps' => $steps,
        ]);

        return ['plugin_id' => $pluginId, 'steps' => $steps, 'success' => $allOk];
    }

    // =========================================================================
    // Step implementations
    // =========================================================================

    private function runOnUninstallHook(string $pluginId): string
    {
        $plugin = $this->loader->getLoadedPlugins()[$pluginId] ?? null;
        // The loader's metadata is keyed the same way but we need the
        // actual instance. It lives in plugins() but that's private —
        // fall back to instantiating only for UninstallablePlugin, which
        // is rare. In practice the plugin is already disabled (and thus
        // not instantiated), so this step is skipped for most cases.
        $instance = $this->loader->getPluginInstance($pluginId);
        if ($instance === null) {
            return 'skipped'; // plugin was disabled / never instantiated
        }
        if (!$instance instanceof UninstallablePlugin) {
            return 'skipped'; // plugin declined to implement the hook
        }
        try {
            $instance->onUninstall(ServiceContainer::getInstance());
            return 'ok';
        } catch (Throwable $e) {
            $this->log('warning', 'plugin_on_uninstall_failed', [
                'plugin_id' => $pluginId,
                'error' => $e->getMessage(),
            ]);
            return 'error:' . $e->getMessage();
        }
    }

    /**
     * @param list<string> $tables
     */
    private function dropOwnedTables(array $tables): string
    {
        if ($tables === []) {
            return 'skipped';
        }
        $errors = [];
        foreach ($tables as $table) {
            // Defence: owned_tables was validated at manifest-load time
            // already, but revalidate the shape here — this service may
            // be invoked with a manifest that changed on disk between
            // validation and uninstall.
            if (!preg_match('/^plugin_[a-z0-9_]+$/', $table) || strlen($table) > 64) {
                $errors[] = "{$table} (rejected by shape check)";
                continue;
            }
            try {
                $this->rootPdo->exec("DROP TABLE IF EXISTS `{$table}`");
            } catch (PDOException $e) {
                $errors[] = "{$table}: " . $e->getMessage();
            }
        }
        if ($errors === []) {
            return 'ok';
        }
        return 'error:' . implode('; ', $errors);
    }

    private function removePluginDir(string $pluginId): string
    {
        $path = $this->pluginDir . '/' . $pluginId;
        if (!is_dir($path)) {
            return 'skipped';
        }
        try {
            $this->rmrf($path);
            return 'ok';
        } catch (Throwable $e) {
            return 'error:' . $e->getMessage();
        }
    }

    private function removeFromStateFile(string $pluginId): string
    {
        try {
            $removed = $this->loader->removeFromState($pluginId);
            return $removed ? 'ok' : 'skipped';
        } catch (Throwable $e) {
            return 'error:' . $e->getMessage();
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function safeStep(callable $fn): string
    {
        try {
            $fn();
            return 'ok';
        } catch (Throwable $e) {
            return 'error:' . $e->getMessage();
        }
    }

    private function validatePluginId(string $pluginId): void
    {
        if (!preg_match(self::PLUGIN_ID_PATTERN, $pluginId)) {
            throw new InvalidArgumentException(
                "Invalid plugin id '{$pluginId}': must be kebab-case, 1-64 chars"
            );
        }
    }

    private function isPluginOnDisk(string $pluginId): bool
    {
        return is_dir($this->pluginDir . '/' . $pluginId)
            && is_file($this->pluginDir . '/' . $pluginId . '/plugin.json');
    }

    private function isPluginEnabled(string $pluginId): bool
    {
        foreach ($this->loader->listAllPlugins() as $row) {
            if (($row['name'] ?? '') === $pluginId) {
                return (bool) ($row['enabled'] ?? false);
            }
        }
        return false;
    }

    private function readManifest(string $pluginId): ?array
    {
        $path = $this->pluginDir . '/' . $pluginId . '/plugin.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path) || is_link($path)) {
                if (!@unlink($path)) {
                    throw new RuntimeException("Failed to unlink {$path}");
                }
            }
            return;
        }
        $items = @scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $item);
        }
        if (!@rmdir($path)) {
            throw new RuntimeException("Failed to rmdir {$path}");
        }
    }

    private function log(string $level, string $event, array $ctx = []): void
    {
        if ($this->logger === null) {
            return;
        }
        $this->logger->$level($event, $ctx);
    }
}
