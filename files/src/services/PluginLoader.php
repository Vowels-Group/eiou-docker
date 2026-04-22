<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Contracts\PluginInterface;
use Eiou\Utils\Logger;
use Throwable;

/**
 * Plugin Loader
 *
 * Discovers, autoloads, and manages the lifecycle of plugins living under a
 * filesystem root (default: /etc/eiou/plugins/). Each plugin is its own
 * subdirectory with a plugin.json manifest and an entry class.
 *
 * Manifest schema (plugin.json):
 *
 *   {
 *     "name":        "hello-eiou",                          // required, kebab-case
 *     "version":     "1.0.0",                               // required, semver
 *     "description": "...",                                 // optional
 *     "entryClass":  "Eiou\\Plugins\\HelloEiou\\Plugin",    // required, FQCN
 *     "autoload":    { "psr-4": { "Eiou\\Plugins\\HelloEiou\\": "src/" } }
 *                                                           // required, PSR-4 map
 *   }
 *
 * Lifecycle (driven by Application::__construct):
 *
 *   1. discover()    — scan plugin root, parse manifests, register PSR-4
 *                      autoloaders, instantiate entry classes
 *   2. registerAll() — call register() on each plugin (before wireAllServices)
 *   3. bootAll()     — call boot() on each plugin (after wireAllServices)
 *
 * Plugin failures are isolated: a thrown exception during any phase disables
 * that plugin and is logged, but never aborts core startup.
 */
class PluginLoader
{
    public const DEFAULT_STATE_FILE = '/etc/eiou/config/plugins.json';

    private string $pluginDir;
    private string $stateFile;
    private Logger $logger;

    /** @var array<string, PluginInterface> Indexed by plugin name */
    private array $plugins = [];

    /** @var array<string, array<string, mixed>> name => ['version' => x, 'status' => y, 'enabled' => bool, 'description' => z?, 'error' => err?] */
    private array $metadata = [];

    public function __construct(
        string $pluginDir = '/etc/eiou/plugins',
        ?Logger $logger = null,
        ?string $stateFile = null
    ) {
        $this->pluginDir = rtrim($pluginDir, '/');
        $this->stateFile = $stateFile ?? self::DEFAULT_STATE_FILE;
        $this->logger = $logger ?? Logger::getInstance();
    }

    /**
     * Scan the plugin directory, register autoloaders, and instantiate
     * each plugin's entry class. Disabled plugins are recorded in metadata
     * but their autoloaders are NOT registered and their entry class is
     * NOT instantiated — they are completely inert until re-enabled.
     *
     * Idempotent — safe to call once per boot.
     *
     * @return array<string, PluginInterface> Successfully loaded enabled plugins
     */
    public function discover(): array
    {
        if (!is_dir($this->pluginDir)) {
            return [];
        }

        $entries = @scandir($this->pluginDir);
        if ($entries === false) {
            $this->logger->warning("PluginLoader: cannot read plugin directory", [
                'path' => $this->pluginDir
            ]);
            return [];
        }

        $state = $this->readState();

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $pluginPath = $this->pluginDir . '/' . $entry;
            if (!is_dir($pluginPath)) {
                continue;
            }
            $this->loadPlugin($pluginPath, $state);
        }

        return $this->plugins;
    }

    /**
     * Phase 1: call register() on each loaded plugin. Plugin failures
     * mark that plugin as failed but do not affect siblings.
     *
     * Idempotent: a plugin already past 'discovered' (i.e. registered or
     * booted) is skipped on a second call. This protects against double
     * service registration if the lifecycle ever re-runs on the same
     * loader instance.
     */
    public function registerAll(ServiceContainer $container): void
    {
        foreach ($this->plugins as $name => $plugin) {
            $status = $this->metadata[$name]['status'] ?? '';
            if (in_array($status, ['registered', 'booted', 'failed'], true)) {
                continue;
            }
            try {
                $plugin->register($container);
                $this->metadata[$name]['status'] = 'registered';
            } catch (Throwable $e) {
                $this->disablePlugin($name, 'register', $e);
            }
        }
    }

    /**
     * Phase 2: call boot() on each successfully-registered plugin.
     *
     * Idempotent: a plugin already 'booted' is skipped. Without this guard,
     * a re-invocation of the lifecycle (which we've seen happen inside
     * PHP-FPM workers under `pm.process_idle_timeout` cycling) would call
     * boot() multiple times and double-subscribe event listeners — meaning
     * one `sync.completed` event would fire N reactions in the same worker.
     *
     * Logged at DEBUG, not INFO: with short-lived child workers (P2pWorker,
     * CLI commands, cron jobs) each spawning a fresh process that boots
     * plugins, INFO-level logging here would dominate app.log.
     */
    public function bootAll(ServiceContainer $container): void
    {
        foreach ($this->plugins as $name => $plugin) {
            $status = $this->metadata[$name]['status'] ?? '';
            // Skip plugins that failed in register() AND plugins already booted.
            if ($status === 'failed' || $status === 'booted') {
                continue;
            }
            try {
                $plugin->boot($container);
                $this->metadata[$name]['status'] = 'booted';
                $this->logger->debug("Plugin booted", [
                    'name' => $name,
                    'version' => $this->metadata[$name]['version'] ?? '?'
                ]);
            } catch (Throwable $e) {
                $this->disablePlugin($name, 'boot', $e);
            }
        }
    }

    /**
     * Metadata for all discovered plugins (loaded, registered, booted, failed,
     * or disabled).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getLoadedPlugins(): array
    {
        return $this->metadata;
    }

    /**
     * List every plugin found on disk, regardless of whether it is enabled
     * or actually loaded into the running process. Reads each manifest fresh
     * and merges in the persisted enabled flag and the live-process status
     * from getLoadedPlugins().
     *
     * Intended for the GUI plugins table — the GUI needs to show disabled
     * plugins so the user can re-enable them, which getLoadedPlugins() alone
     * cannot do reliably (a plugin disabled before discover() ran is in
     * metadata, but a plugin disabled in a previous boot lifecycle isn't).
     *
     * @return list<array{name:string,version:string,description:string,enabled:bool,status:string,error?:string}>
     */
    public function listAllPlugins(): array
    {
        $state = $this->readState();
        $live = $this->metadata;
        $result = [];

        if (!is_dir($this->pluginDir)) {
            return $result;
        }

        $entries = @scandir($this->pluginDir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $manifestPath = $this->pluginDir . '/' . $entry . '/plugin.json';
            if (!is_file($manifestPath)) {
                continue;
            }
            $raw = @file_get_contents($manifestPath);
            if ($raw === false) {
                continue;
            }
            $manifest = json_decode($raw, true);
            if (!is_array($manifest) || empty($manifest['name'])) {
                continue;
            }

            $name = $manifest['name'];
            $enabled = (bool) ($state[$name]['enabled'] ?? false);
            $status = $live[$name]['status'] ?? ($enabled ? 'not_loaded' : 'disabled');

            $row = [
                'name' => $name,
                'version' => $manifest['version'] ?? '',
                'description' => $manifest['description'] ?? '',
                'enabled' => $enabled,
                'status' => $status,
            ];
            if (isset($live[$name]['error'])) {
                $row['error'] = (string) $live[$name]['error'];
            }
            $result[] = $row;
        }

        usort($result, fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $result;
    }

    /**
     * Persist a plugin's enabled flag. Returns true on success.
     *
     * Note: state changes only take effect on the next process boot.
     * Plugins that have already booted in this process keep their event
     * subscriptions and registered services until a restart.
     */
    public function setEnabled(string $name, bool $enabled): bool
    {
        $state = $this->readState();
        $state[$name] = ['enabled' => $enabled];
        return $this->writeState($state);
    }

    /**
     * Read the persisted plugin state file. Missing or malformed file
     * yields an empty map (which means "default everything to enabled").
     *
     * @return array<string, array<string, mixed>>
     */
    private function readState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }
        $raw = @file_get_contents($this->stateFile);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Write the plugin state file atomically (tmp file + rename).
     *
     * @param array<string, array<string, mixed>> $state
     */
    private function writeState(array $state): bool
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->logger->warning("PluginLoader: cannot create state file directory", [
                'dir' => $dir
            ]);
            return false;
        }
        $tmp = $this->stateFile . '.tmp.' . getmypid();
        $bytes = @file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT));
        if ($bytes === false) {
            $this->logger->warning("PluginLoader: cannot write state file", [
                'path' => $this->stateFile
            ]);
            return false;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $this->stateFile)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Read manifest. If enabled, register PSR-4 autoloader and instantiate
     * entry class. Disabled plugins are recorded in metadata only.
     *
     * @param array<string, array<string, mixed>> $state Persisted state map keyed by plugin name
     */
    private function loadPlugin(string $pluginPath, array $state): void
    {
        $manifestPath = $pluginPath . '/plugin.json';
        if (!is_file($manifestPath)) {
            return;
        }

        $raw = @file_get_contents($manifestPath);
        if ($raw === false) {
            $this->logger->warning("PluginLoader: cannot read manifest", ['path' => $manifestPath]);
            return;
        }

        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            $this->logger->warning("PluginLoader: invalid JSON in manifest", ['path' => $manifestPath]);
            return;
        }

        // Validate required fields
        foreach (['name', 'version', 'entryClass', 'autoload'] as $required) {
            if (empty($manifest[$required])) {
                $this->logger->warning("PluginLoader: manifest missing required field", [
                    'path' => $manifestPath,
                    'field' => $required
                ]);
                return;
            }
        }

        $name = $manifest['name'];
        if (isset($this->metadata[$name])) {
            $this->logger->warning("PluginLoader: duplicate plugin name, skipping", [
                'name' => $name,
                'path' => $pluginPath
            ]);
            return;
        }

        // Default: DISABLED. A newly discovered plugin is inert until the
        // user explicitly enables it via the GUI (or by editing plugins.json
        // and restarting). This is a safety stance — a malicious or merely
        // buggy plugin dropped into the plugin directory cannot crash the
        // node on its first boot, because it never runs without consent.
        $enabled = (bool) ($state[$name]['enabled'] ?? false);

        if (!$enabled) {
            $this->metadata[$name] = [
                'version' => $manifest['version'],
                'description' => $manifest['description'] ?? '',
                'status' => 'disabled',
                'enabled' => false,
            ];
            return;
        }

        // Register PSR-4 autoloader for this plugin's namespace(s)
        $psr4 = $manifest['autoload']['psr-4'] ?? [];
        if (!is_array($psr4) || empty($psr4)) {
            $this->logger->warning("PluginLoader: manifest missing autoload.psr-4", [
                'name' => $name
            ]);
            return;
        }
        foreach ($psr4 as $prefix => $relativeDir) {
            $this->registerPsr4($prefix, $pluginPath . '/' . trim($relativeDir, '/'));
        }

        // Instantiate entry class
        $entryClass = $manifest['entryClass'];
        try {
            if (!class_exists($entryClass)) {
                $this->logger->warning("PluginLoader: entry class not found", [
                    'name' => $name,
                    'class' => $entryClass
                ]);
                return;
            }
            $instance = new $entryClass();
            if (!$instance instanceof PluginInterface) {
                $this->logger->warning("PluginLoader: entry class does not implement PluginInterface", [
                    'name' => $name,
                    'class' => $entryClass
                ]);
                return;
            }
        } catch (Throwable $e) {
            $this->logger->warning("PluginLoader: failed to instantiate entry class", [
                'name' => $name,
                'class' => $entryClass,
                'error' => $e->getMessage()
            ]);
            return;
        }

        $this->plugins[$name] = $instance;
        $this->metadata[$name] = [
            'version' => $manifest['version'],
            'description' => $manifest['description'] ?? '',
            'status' => 'discovered',
            'enabled' => true,
        ];
    }

    /**
     * Register a PSR-4 namespace → directory mapping with PHP's autoloader.
     * Self-contained — does not depend on Composer's ClassLoader at runtime.
     */
    private function registerPsr4(string $prefix, string $baseDir): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, '/') . '/';

        spl_autoload_register(function (string $class) use ($prefix, $baseDir): void {
            if (strpos($class, $prefix) !== 0) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    private function disablePlugin(string $name, string $phase, Throwable $e): void
    {
        $this->metadata[$name]['status'] = 'failed';
        $this->metadata[$name]['error'] = "Failed in {$phase}(): " . $e->getMessage();
        $this->logger->error("Plugin disabled after exception", [
            'name' => $name,
            'phase' => $phase,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }
}
