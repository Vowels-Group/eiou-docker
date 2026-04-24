<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Contracts\PluginInterface;
use Eiou\Events\EventDispatcher;
use Eiou\Events\PluginEvents;
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
 *     "autoload":    { "psr-4": { "Eiou\\Plugins\\HelloEiou\\": "src/" } },
 *                                                           // required, PSR-4 map
 *
 *     // Optional metadata — surfaced in the GUI detail modal when present.
 *     // All URLs must be http(s); anything else is silently dropped so a
 *     // hostile manifest can't slip in `javascript:` or `data:` links.
 *     "author":      "Acme Co."                             // string, OR
 *     "author":      { "name": "Acme Co.",                  // object form
 *                      "url":  "https://acme.example" },
 *     "homepage":    "https://acme.example/plugins/hello",
 *     "changelog":   "https://acme.example/plugins/hello/CHANGELOG.md",
 *                                                           // per-plugin changelog,
 *                                                           // not the host project's
 *     "license":     "MIT"                                  // SPDX id, <= 64 chars
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

    /**
     * Optional plugin-isolation services. When both are present, setEnabled()
     * generates MySQL credentials + grants / revokes privileges as side
     * effects. In test and bootstrap contexts where the DB isn't up yet,
     * leave them unset and the loader degrades to "just flip the flag".
     */
    private ?PluginCredentialService $credentialService = null;
    private ?PluginDbUserService $dbUserService = null;

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
     * Wire the isolation services after construction. Called by
     * Application::__construct once the ServiceContainer has them wired.
     * If either argument is null, isolation is effectively disabled
     * (setEnabled/setDisabled falls back to just flipping the flag).
     */
    public function setIsolationServices(
        ?PluginCredentialService $credentialService,
        ?PluginDbUserService $dbUserService
    ): void {
        $this->credentialService = $credentialService;
        $this->dbUserService = $dbUserService;
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
                EventDispatcher::getInstance()->dispatch(PluginEvents::PLUGIN_REGISTERED, [
                    'name' => $name,
                    'version' => $this->metadata[$name]['version'] ?? '',
                ]);
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
                EventDispatcher::getInstance()->dispatch(PluginEvents::PLUGIN_BOOTED, [
                    'name' => $name,
                    'version' => $this->metadata[$name]['version'] ?? '',
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
     * @return list<array{
     *   name:string,version:string,description:string,enabled:bool,status:string,
     *   error?:string,
     *   author?:array{name:string,url?:string},
     *   homepage?:string,changelog?:string,license?:string,has_changelog?:bool
     * }>
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

            // Optional metadata — only surface when validation passes so the
            // GUI can do a simple `if (p.homepage)` check without re-validating.
            $author = $this->normalizeAuthor($manifest['author'] ?? null);
            if ($author !== null) {
                $row['author'] = $author;
            }
            foreach (['homepage', 'changelog'] as $urlField) {
                $url = $this->normalizeUrl($manifest[$urlField] ?? null);
                if ($url !== null) {
                    $row[$urlField] = $url;
                }
            }
            $license = $this->normalizeLicense($manifest['license'] ?? null);
            if ($license !== null) {
                $row['license'] = $license;
            }
            if (is_file($this->pluginDir . '/' . $entry . '/CHANGELOG.md')) {
                $row['has_changelog'] = true;
            }

            // Surface the normalized database block so the GUI / REST list
            // can show operators which plugins want their own MySQL user
            // before they enable. An invalid block here (same validation as
            // loadPlugin uses) also surfaces an error row so the GUI can
            // render a disabled-with-warning state rather than silently
            // skipping — otherwise an operator would wonder why a plugin
            // they can see on disk isn't in the list at all.
            $dbResult = $this->normalizeDatabase($manifest['database'] ?? null, $name);
            if (!$dbResult['valid']) {
                $row['status'] = 'failed';
                $row['error'] = 'manifest: ' . $dbResult['error'];
            } elseif ($dbResult['config'] !== null) {
                $row['database'] = $dbResult['config'];
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
     *
     * Isolation side effects (only when isolation services are wired —
     * see setIsolationServices() — and the plugin's manifest declares
     * `database.user: true`):
     *
     *   enable  → generate credentials on first call, ensureUser(), grant()
     *   disable → revoke()  (credentials + user row stay in place; a later
     *                        re-enable re-grants without a password rotation)
     *
     * DDL runs BEFORE the state flip so a failure leaves the flag at its
     * previous value and the operator can retry. Partial-success states
     * are the boot-time reconciler's problem to heal (phase 5).
     */
    public function setEnabled(string $name, bool $enabled): bool
    {
        // Run the DDL side-effects first — if they fail, we don't flip
        // the flag, so the operator can retry without a stale state file.
        try {
            $this->applyIsolationSideEffects($name, $enabled);
        } catch (Throwable $e) {
            $this->logger->error("Plugin isolation side-effect failed; state flip aborted", [
                'plugin' => $name,
                'target_enabled' => $enabled,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $state = $this->readState();
        $state[$name] = ['enabled' => $enabled];
        return $this->writeState($state);
    }

    /**
     * Look up the plugin's manifest database block and, when the caller
     * has wired isolation services, drive the matching DDL:
     *
     *   - enable  + database.user = true  → credentials + CREATE/ALTER USER + GRANT
     *   - disable + database.user = true  → REVOKE
     *
     * No-op if isolation services aren't wired, or the plugin doesn't declare
     * a `database.user` block, or the plugin is unknown. The no-op fallback
     * means callers (CLI, REST, GUI) stay unchanged — a node running without
     * the services just flips the flag like it did before.
     */
    private function applyIsolationSideEffects(string $name, bool $enabled): void
    {
        if ($this->credentialService === null || $this->dbUserService === null) {
            return;
        }
        $dbConfig = $this->getManifestDatabase($name);
        if ($dbConfig === null || ($dbConfig['user'] ?? false) !== true) {
            return;
        }

        if ($enabled) {
            $plaintext = $this->credentialService->exists($name)
                ? $this->credentialService->getPlaintext($name)
                : $this->credentialService->generate($name);
            if ($plaintext === null) {
                // getPlaintext() returned null despite exists() saying true —
                // a race, or a decryption failure surfaced as null upstream.
                // Regenerate so the plugin can proceed rather than being
                // stuck in a "exists but unreadable" state.
                $plaintext = $this->credentialService->rotate($name);
            }
            $limits = (array) ($dbConfig['db_limits'] ?? self::DEFAULT_DB_LIMITS);
            $owned = (array) ($dbConfig['owned_tables'] ?? []);

            $this->dbUserService->ensureUser($name, $plaintext, $limits);
            $this->dbUserService->grant($name, $owned);
        } else {
            // Leave credentials + user + tables in place. Disable is
            // meant to be cheaply reversible; uninstall is the path that
            // drops everything, and it goes through a separate method.
            $this->dbUserService->revoke($name);
        }
    }

    /**
     * Boot-time reconciliation — re-apply the correct MySQL state for every
     * plugin on every node boot. Idempotent: CREATE USER IF NOT EXISTS,
     * ALTER USER with current credentials, GRANT (or REVOKE for disabled
     * plugins). Self-heals after:
     *
     *   - mysql-data volume recreation (plugin users and grants are gone;
     *     we restore them using the still-encrypted password from the
     *     plugin_credentials table)
     *   - someone manually DROP USER / REVOKE on a plugin
     *   - operator changing `db_limits` in plugins.json between boots
     *   - master-key rotation (credentials were re-wrapped; the new
     *     plaintext is applied via ALTER USER)
     *
     * No-op when isolation services aren't wired. Failures are logged but
     * do NOT abort node boot — a plugin with a broken MySQL user is simply
     * non-functional; the operator investigates via the plugin list which
     * now surfaces the error.
     *
     * Call once per boot, after discover() and before bootAll().
     *
     * @return array<string, string> Map of plugin_id → 'granted' / 'revoked' / 'skipped' / 'error:<msg>'.
     *                               Returned for tests and operator logs; production callers can ignore.
     */
    public function reconcileIsolation(): array
    {
        if ($this->credentialService === null || $this->dbUserService === null) {
            return [];
        }

        $results = [];
        foreach ($this->listAllPlugins() as $row) {
            $pluginId = $row['name'];
            $db = $row['database'] ?? null;
            if (!is_array($db) || ($db['user'] ?? false) !== true) {
                $results[$pluginId] = 'skipped';
                continue;
            }
            $enabled = (bool) ($row['enabled'] ?? false);
            try {
                if ($enabled) {
                    $plaintext = $this->credentialService->exists($pluginId)
                        ? $this->credentialService->getPlaintext($pluginId)
                        : $this->credentialService->generate($pluginId);
                    if ($plaintext === null) {
                        // Corrupted credential row (null plaintext despite
                        // exists()). Reconciler can't recover blindly — a
                        // silent rotate() would lock out whatever still
                        // holds the old password. Surface the error so the
                        // operator decides between manual rotate-and-reset
                        // or dropping the plugin entirely.
                        throw new \RuntimeException(
                            "credential row exists for '{$pluginId}' but plaintext is null — likely master-key mismatch; manual intervention required"
                        );
                    }
                    $limits = (array) ($db['db_limits'] ?? self::DEFAULT_DB_LIMITS);
                    $owned = (array) ($db['owned_tables'] ?? []);
                    $this->dbUserService->ensureUser($pluginId, $plaintext, $limits);
                    $this->dbUserService->grant($pluginId, $owned);
                    $results[$pluginId] = 'granted';
                } else {
                    // For disabled plugins that *have* credentials, make
                    // sure privileges are revoked — self-heals cases where
                    // the row says disabled but a grant leaked through a
                    // partial-failure in setEnabled(false) from before the
                    // DDL-first ordering was added.
                    if ($this->credentialService->exists($pluginId)) {
                        $this->dbUserService->revoke($pluginId);
                        $results[$pluginId] = 'revoked';
                    } else {
                        $results[$pluginId] = 'skipped';
                    }
                }
            } catch (Throwable $e) {
                $this->logger->error("Plugin isolation reconcile failed", [
                    'plugin' => $pluginId,
                    'error' => $e->getMessage(),
                ]);
                $results[$pluginId] = 'error:' . $e->getMessage();
            }
        }
        return $results;
    }

    /**
     * Fresh-read the manifest for a single plugin and return the normalized
     * `database` block (or null if absent). Avoids depending on state that
     * discover() may not have populated yet — setEnabled() can legitimately
     * be called in an early-boot context where nothing is in $this->metadata.
     *
     * @return array<string, mixed>|null
     */
    private function getManifestDatabase(string $name): ?array
    {
        if (!is_dir($this->pluginDir)) {
            return null;
        }
        $manifestPath = $this->pluginDir . '/' . $name . '/plugin.json';
        if (!is_file($manifestPath)) {
            return null;
        }
        $raw = @file_get_contents($manifestPath);
        if ($raw === false) {
            return null;
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            return null;
        }
        $result = $this->normalizeDatabase($manifest['database'] ?? null, $name);
        return $result['valid'] ? ($result['config'] ?? null) : null;
    }

    /**
     * Read a plugin's bundled `CHANGELOG.md` file and return its raw markdown.
     * Returns null if the plugin is unknown, the file is missing, unreadable,
     * or exceeds the 256KB cap (a sane safety net — a per-plugin changelog
     * larger than that is either a mistake or an attack vector).
     *
     * The plugin name is validated against the on-disk listing rather than
     * trusted as filesystem input, so `../etc/passwd` style traversal is
     * impossible even if the controller forgets to validate.
     */
    public function readChangelog(string $name): ?string
    {
        $known = false;
        foreach ($this->listAllPlugins() as $row) {
            if ($row['name'] === $name) { $known = true; break; }
        }
        if (!$known) {
            return null;
        }
        $path = $this->pluginDir . '/' . $name . '/CHANGELOG.md';
        if (!is_file($path)) {
            return null;
        }
        $size = @filesize($path);
        if ($size === false || $size > 256 * 1024) {
            return null;
        }
        $raw = @file_get_contents($path);
        return $raw === false ? null : $raw;
    }

    /**
     * Normalize the author field to `['name' => string, 'url' => ?string]`.
     * Accepts a plain string ("Acme Co.") or an object with `name` and an
     * optional `url`. Returns null for anything else so the row stays clean.
     *
     * @return array{name:string,url?:string}|null
     */
    private function normalizeAuthor(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $name = trim($raw);
            return $name === '' ? null : ['name' => $name];
        }
        if (is_array($raw) && isset($raw['name']) && is_string($raw['name'])) {
            $name = trim($raw['name']);
            if ($name === '') {
                return null;
            }
            $out = ['name' => $name];
            $url = $this->normalizeUrl($raw['url'] ?? null);
            if ($url !== null) {
                $out['url'] = $url;
            }
            return $out;
        }
        return null;
    }

    /**
     * Accept only absolute http(s) URLs so a hostile manifest can't slip
     * in `javascript:` or `data:` schemes that the GUI would then render
     * as clickable links. Returns null for anything else.
     */
    private function normalizeUrl(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '' || !preg_match('#^https?://#i', $trimmed)) {
            return null;
        }
        if (filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        return $trimmed;
    }

    /**
     * Default MySQL resource limits applied to plugin users when the manifest
     * doesn't override them. Chosen to be non-restrictive for honest plugins
     * but cap a runaway loop at roughly 3 queries/second sustained.
     *
     * See docs/PLUGIN_ISOLATION.md §11 for rationale.
     */
    public const DEFAULT_DB_LIMITS = [
        'max_queries_per_hour'     => 10000,
        'max_updates_per_hour'     => 5000,
        'max_connections_per_hour' => 500,
        'max_user_connections'     => 10,
    ];

    /**
     * Maximum length of the plugin id prefix baked into plugin_<id>_<table>
     * names. Enough to hold any reasonable plugin id without pushing table
     * names near MySQL's 64-char identifier cap once the table suffix is
     * appended. `plugin_` (7) + 24 + `_` (1) + 32 = 64 — exact budget.
     */
    private const MAX_TABLE_NAME_PLUGIN_ID_LEN = 24;

    /**
     * Validate and normalize the optional `database` block from a manifest.
     *
     * Returns:
     *   - ['valid' => true, 'config' => null] when the block is absent
     *     (plugin declared no DB needs — legal and common)
     *   - ['valid' => true, 'config' => array] when the block is well-formed
     *   - ['valid' => false, 'error' => string] when the block is present
     *     but malformed — caller refuses to load the plugin
     *
     * Invalid `db_limits` values are dropped silently (logged at debug) and
     * core defaults fill in — an operator typo on a limit should not brick
     * the plugin. But `user`, `owned_tables`, and the presence/absence of
     * the block itself are strict: get those wrong and the plugin is
     * rejected with a clear error, same as missing required top-level
     * fields.
     *
     * See docs/PLUGIN_ISOLATION.md §4 and §11.
     *
     * @param mixed $raw The raw manifest value at the `database` key
     * @param string $pluginId The plugin's `name` field — used to validate
     *                         the `plugin_<snake_case(id)>_` owned-table prefix
     * @return array{valid: bool, config?: ?array, error?: string}
     */
    private function normalizeDatabase(mixed $raw, string $pluginId): array
    {
        if ($raw === null) {
            return ['valid' => true, 'config' => null];
        }
        if (!is_array($raw)) {
            return ['valid' => false, 'error' => '`database` must be an object'];
        }

        // `database.user` is an explicit acknowledgement — an operator reading
        // the manifest sees "yes I really want a DB user" before enabling.
        // If the block is present but `user` is missing or falsy, refuse —
        // silently treating a typo'd key as "no user" would be surprising.
        if (!array_key_exists('user', $raw)) {
            return ['valid' => false, 'error' => '`database.user` is required when the database block is present'];
        }
        if ($raw['user'] !== true) {
            return ['valid' => false, 'error' => '`database.user` must be literally `true`'];
        }

        // Plugin id → table-name prefix: plugin names are kebab-case
        // (enforced elsewhere at registration time); MySQL identifiers use
        // underscore. A plugin called `my-plugin` owns tables prefixed
        // `plugin_my_plugin_`.
        $snakeId = str_replace('-', '_', $pluginId);
        if (strlen($snakeId) > self::MAX_TABLE_NAME_PLUGIN_ID_LEN) {
            return ['valid' => false, 'error' => sprintf(
                'plugin name is too long (%d) for MySQL identifier budget — max %d chars once snake-cased',
                strlen($snakeId),
                self::MAX_TABLE_NAME_PLUGIN_ID_LEN
            )];
        }
        $expectedPrefix = 'plugin_' . $snakeId . '_';

        if (!array_key_exists('owned_tables', $raw)) {
            return ['valid' => false, 'error' => '`database.owned_tables` is required when `database.user` is true'];
        }
        if (!is_array($raw['owned_tables'])) {
            return ['valid' => false, 'error' => '`database.owned_tables` must be an array'];
        }
        $ownedTables = [];
        foreach ($raw['owned_tables'] as $i => $table) {
            if (!is_string($table)) {
                return ['valid' => false, 'error' => sprintf(
                    '`database.owned_tables[%d]` must be a string',
                    $i
                )];
            }
            // MySQL identifiers are case-insensitive on most platforms but
            // filename-sensitive on others; lowercase-only keeps the grant
            // pattern portable. Limit to safe charset — no backticks, no
            // quotes, no spaces.
            if (!preg_match('/^plugin_[a-z0-9_]+$/', $table)) {
                return ['valid' => false, 'error' => sprintf(
                    '`database.owned_tables[%d]` (%s) must match /^plugin_[a-z0-9_]+$/',
                    $i,
                    $table
                )];
            }
            if (strpos($table, $expectedPrefix) !== 0) {
                return ['valid' => false, 'error' => sprintf(
                    '`database.owned_tables[%d]` (%s) must start with `%s` (derived from plugin name)',
                    $i,
                    $table,
                    $expectedPrefix
                )];
            }
            // Must have content after the prefix — `plugin_myplugin_` alone
            // is not a valid table name.
            if (strlen($table) <= strlen($expectedPrefix)) {
                return ['valid' => false, 'error' => sprintf(
                    '`database.owned_tables[%d]` (%s) has no table-name suffix after `%s`',
                    $i,
                    $table,
                    $expectedPrefix
                )];
            }
            // MySQL identifier length cap (64 chars). Cheaper to reject here
            // than to catch a MySQL syntax error during grant.
            if (strlen($table) > 64) {
                return ['valid' => false, 'error' => sprintf(
                    '`database.owned_tables[%d]` (%s) exceeds MySQL 64-char identifier limit',
                    $i,
                    $table
                )];
            }
            $ownedTables[] = $table;
        }

        $limits = self::DEFAULT_DB_LIMITS;
        if (isset($raw['db_limits'])) {
            if (!is_array($raw['db_limits'])) {
                return ['valid' => false, 'error' => '`database.db_limits` must be an object'];
            }
            foreach (array_keys(self::DEFAULT_DB_LIMITS) as $key) {
                if (!isset($raw['db_limits'][$key])) {
                    continue;
                }
                $v = $raw['db_limits'][$key];
                if (!is_int($v) || $v <= 0) {
                    // Bad values are silently dropped and the default stays;
                    // a single typo on a limit shouldn't prevent the plugin
                    // from loading. Logged at debug so operators can notice.
                    $this->logger->debug(
                        "PluginLoader: ignoring invalid db_limits.{$key}, using default",
                        ['plugin' => $pluginId, 'value' => $v, 'default' => $limits[$key]]
                    );
                    continue;
                }
                $limits[$key] = $v;
            }
        }

        return [
            'valid' => true,
            'config' => [
                'user' => true,
                'owned_tables' => $ownedTables,
                'db_limits' => $limits,
            ],
        ];
    }

    /**
     * License is rendered as plain text next to the version, so cap it at
     * a reasonable length and drop anything non-stringy.
     */
    private function normalizeLicense(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '' || strlen($trimmed) > 64) {
            return null;
        }
        return $trimmed;
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

        // Validate the optional database-isolation block. A malformed block
        // rejects the plugin outright — a half-wired DB manifest would leave
        // the plugin with no tables / no grants / no way to know it was
        // broken until its first query. See docs/PLUGIN_ISOLATION.md.
        $dbResult = $this->normalizeDatabase($manifest['database'] ?? null, $name);
        if (!$dbResult['valid']) {
            $this->logger->warning("PluginLoader: invalid database block in manifest", [
                'name' => $name,
                'path' => $manifestPath,
                'error' => $dbResult['error'],
            ]);
            return;
        }
        $dbConfig = $dbResult['config'];

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
                'database' => $dbConfig,
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
            'database' => $dbConfig,
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
        EventDispatcher::getInstance()->dispatch(PluginEvents::PLUGIN_FAILED, [
            'name' => $name,
            'version' => $this->metadata[$name]['version'] ?? '',
            'phase' => $phase,
            'error' => $e->getMessage(),
        ]);
    }
}
