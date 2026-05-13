<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Plugins;

use Eiou\Contracts\PluginInterface;
use Eiou\Events\EventDispatcher;
use Eiou\Events\PluginEvents;
use Eiou\Services\ServiceContainer;
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

    /**
     * Last failure context from setEnabled(), or null after success. Holds
     * a [stage, message] pair so callers can surface a specific reason
     * instead of the legacy generic "Failed to persist plugin state"
     * string. Cleared at the top of each setEnabled() call.
     *
     * Stage values:
     *   - 'refused'   : manifest refused (e.g. non-sandboxed)
     *   - 'isolation' : MySQL credentials / user / grant step threw
     *   - 'sandbox'   : FPM pool / system-user / nginx apply returned false
     *   - 'state'     : writeState failed after side-effects committed
     *
     * @var array{stage:string, message:string}|null
     */
    private ?array $lastSetEnabledFailure = null;

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

    /**
     * Optional sibling-container credential exporter. When present (the
     * normal path on a real node), enable / reconcile write the plugin's
     * MySQL credentials to /etc/eiou/credentials/plugin-<id>.json so
     * operator-deployed sibling containers can mount the file and share
     * state with the plugin. Disable and uninstall remove the file.
     *
     * When null (test harness / early boot without the supervisor),
     * isolation still functions; only the sibling-container surface is
     * skipped, with a "skipped" log entry.
     */
    private ?PluginCredentialsExportService $credentialsExportService = null;

    /**
     * Optional signature verifier + enforcement mode. Enforcement is a
     * global policy (one setting controls behaviour for every plugin):
     *
     *   - 'off'     — don't verify at all; load every plugin regardless
     *                 of whether it has a signature. Backwards-compatible
     *                 default; any future rollout path turns this up
     *                 through 'warn' first before 'require'.
     *   - 'warn'    — verify, log failures, but still load the plugin.
     *                 The `sig_status` field on the plugin's metadata
     *                 surfaces the result so operators can fix before
     *                 flipping to 'require'.
     *   - 'require' — verify and refuse to load any plugin whose
     *                 signature is missing, malformed, bound to an
     *                 untrusted key, or fails verification.
     */
    private ?PluginSignatureVerifier $sigVerifier = null;
    private string $sigMode = PluginSignatureVerifier::MODE_OFF;

    /**
     * Sandbox services — wired by setSandboxServices() after
     * construction. When all three are present, plugins whose manifest
     * declares "sandboxed": true get their Unix user created and FPM
     * pool generated on enable. When any is null (test scaffold,
     * early-boot path) the sandbox short-circuits gracefully: enabling
     * a sandboxed plugin returns false and logs why, instead of half-
     * committing some side effects and crashing on the missing ones.
     *
     * See docs/PLUGINS.md (Sandboxing).
     */
    private ?PluginUserService $userService = null;
    private ?PluginPoolService $poolService = null;
    private ?PluginNginxConfigService $nginxConfigService = null;

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
     * Wire the signature verifier + enforcement mode. Accepted modes:
     * 'off', 'warn', 'require' — anything else collapses to 'off' to
     * prevent a typo from silently allowing unsigned plugins through
     * in an operator that thought they had verification on.
     */
    public function setSignatureVerifier(
        ?PluginSignatureVerifier $verifier,
        string $mode = PluginSignatureVerifier::MODE_OFF
    ): void {
        $this->sigVerifier = $verifier;
        $validModes = [
            PluginSignatureVerifier::MODE_OFF,
            PluginSignatureVerifier::MODE_WARN,
            PluginSignatureVerifier::MODE_REQUIRE,
        ];
        $this->sigMode = in_array($mode, $validModes, true)
            ? $mode
            : PluginSignatureVerifier::MODE_OFF;
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
     * Wire the sibling-container credentials exporter. Optional — when
     * null, applyIsolationSideEffects() and reconcileIsolation() skip
     * the on-disk credential file step but still drive the MySQL DDL
     * normally.
     */
    public function setCredentialsExportService(
        ?PluginCredentialsExportService $exportService
    ): void {
        $this->credentialsExportService = $exportService;
    }

    /**
     * Wire the sandbox services. Called by Application::__construct
     * after the ServiceContainer is ready. When any of the three is
     * null the sandbox path is effectively unavailable — setEnabled()
     * on a sandboxed plugin will refuse rather than half-commit. See
     * docs/PLUGINS.md (Sandboxing).
     */
    public function setSandboxServices(
        ?PluginUserService $userService,
        ?PluginPoolService $poolService,
        ?PluginNginxConfigService $nginxConfigService
    ): void {
        $this->userService = $userService;
        $this->poolService = $poolService;
        $this->nginxConfigService = $nginxConfigService;
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
            if (self::isUpgradeBackupDir($entry)) {
                // Upgrade backup snapshots ("<id>.backup-<ver>-<ts>")
                // live alongside live plugins so operators can ls the
                // dir and find both. They carry a copy of the old
                // manifest with the same plugin name, which would
                // otherwise collide with the live plugin on discover.
                continue;
            }
            $pluginPath = $this->pluginDir . '/' . $entry;
            if (!is_dir($pluginPath)) {
                continue;
            }
            $this->loadPlugin($pluginPath, $state);
        }

        // At boot, find every plugin that's enabled in plugins.json
        // but lacks "sandboxed": true in its manifest. These are inert
        // (we refused to load them above); leaving them as enabled in
        // the state file would mean every subsequent boot logs the
        // same warning forever, and the GUI would keep showing them
        // as "enabled". Auto-flip them to disabled in the state file
        // and emit a one-shot notice telling the operator what
        // happened.
        $autoDisabled = [];
        foreach ($this->metadata as $name => $meta) {
            if (($meta['status'] ?? '') !== 'legacy_unsupported') continue;
            if (!array_key_exists($name, $state)) continue;
            if (empty($state[$name]['enabled'])) continue;
            $state[$name]['enabled'] = false;
            $autoDisabled[] = $name;
            // Mirror the metadata flag so listAllPlugins shows
            // enabled=false from this point on, without waiting for
            // the next reconcile pass.
            $this->metadata[$name]['enabled'] = false;
        }
        if ($autoDisabled !== []) {
            $this->writeState($state);
            $this->logger->warning(
                'plugin_legacy_auto_disabled',
                [
                    'plugins' => $autoDisabled,
                    'reason' => 'Sandboxing is mandatory. Plugins missing "sandboxed": true in '
                              . 'their manifest were enabled in plugins.json but inert; this '
                              . 'boot auto-flipped them to disabled. Operator should either '
                              . 'wait for the plugin author to ship a sandboxed migration, or '
                              . 'uninstall the plugin. See docs/PLUGINS.md.',
                ]
            );
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
            if (self::isUpgradeBackupDir($entry)) {
                // See discover() for the rationale — backup snapshots
                // co-located with live plugins must be filtered out so
                // they don't surface as duplicate-named plugins.
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

            $sandboxed = !empty($manifest['sandboxed']);
            // For sandboxed-enabled plugins the in-process metadata
            // status is 'sandboxed' (see loadPlugin), which is more
            // informative than the default 'not_loaded' fallback.
            if ($enabled && $sandboxed && !isset($live[$name]['status'])) {
                $status = 'sandboxed';
            }

            $row = [
                'name' => $name,
                'version' => $manifest['version'] ?? '',
                'description' => $manifest['description'] ?? '',
                'enabled' => $enabled,
                'status' => $status,
                'sandboxed' => $sandboxed,
            ];

            // Optional upgrade-compatibility floor. When the upgrade
            // service runs, it refuses to apply this manifest if the
            // currently-installed version is below this floor — the
            // plugin author declares "I cannot migrate state from
            // below version X." Validated as a non-empty string here;
            // PluginUpgradeService hands it to version_compare() at
            // upgrade time so the comparison semantics are deferred
            // (we don't try to parse semver up front).
            if (isset($manifest['min_upgradable_from'])
                && is_string($manifest['min_upgradable_from'])
                && $manifest['min_upgradable_from'] !== '') {
                $row['min_upgradable_from'] = $manifest['min_upgradable_from'];
            }

            // Hard-deprecation surface — non-sandboxed plugins are no
            // longer loaded at all. The flag tells the GUI / CLI to
            // render them as "unsupported until migrated".
            if (!$sandboxed) {
                $row['legacy_in_process'] = true;
                $row['legacy_warning']    = 'In-process plugins are no longer supported. This plugin '
                                          . 'is enabled in plugins.json but inert — its register() / '
                                          . 'boot() will not run. The plugin author must ship an '
                                          . 'updated manifest with "sandboxed": true (see '
                                          . 'docs/PLUGINS.md, Sandboxing section).';
            }

            // core_services allow-list. Each entry is "<Service>.<method>"
            // — the gateway checks every sandboxed-plugin call against
            // this list before dispatching. Manifest fields outside the
            // kebab/dotted shape are filtered out so a malformed manifest
            // can't poison the gateway's match.
            $coreServices = $manifest['core_services'] ?? [];
            if (is_array($coreServices)) {
                $row['core_services'] = array_values(array_filter(
                    $coreServices,
                    fn($entry): bool => is_string($entry)
                        && preg_match('/^[A-Z][A-Za-z0-9]*\.[a-z][A-Za-z0-9_]*$/', $entry) === 1
                ));
            } else {
                $row['core_services'] = [];
            }

            // Declarative surface fields. These tell the IPC forwarder
            // which events/filters/renders to bridge from in-process
            // firing to a sandboxed plugin's __dispatch.php. Each list
            // is filtered to a safe shape so a malformed manifest can't
            // poison the forwarder's registrations.
            $row['subscribes_to'] = $this->stringListField($manifest, 'subscribes_to', '/^[a-z][a-z0-9_.-]*$/');
            $row['filter_hooks']  = $this->stringListField($manifest, 'filter_hooks',  '/^[a-z][a-zA-Z0-9_.-]*$/');
            $row['render_hooks']  = $this->stringListField($manifest, 'render_hooks',  '/^[a-z][a-zA-Z0-9_.-]*$/');
            // gui_actions, tabs, gui_assets, api_routes, cli_commands
            // carry richer payloads. IPC forwarders read them through
            // the list; basic structural validation here keeps the
            // forwarder code simpler.
            $row['gui_actions'] = $this->shapedListField(
                $manifest,
                'gui_actions',
                fn($e): bool => is_array($e)
                    && isset($e['name']) && is_string($e['name'])
                    && preg_match('/^[a-z][a-zA-Z0-9_]*$/', $e['name']) === 1
            );
            $row['tabs'] = $this->shapedListField(
                $manifest,
                'tabs',
                fn($e): bool => is_array($e)
                    && isset($e['id']) && is_string($e['id'])
                    && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $e['id']) === 1
                    && isset($e['label']) && is_string($e['label'])
            );
            $row['gui_assets'] = $this->shapedListField(
                $manifest,
                'gui_assets',
                fn($e): bool => is_array($e)
                    && isset($e['type'], $e['path'])
                    && in_array($e['type'], ['css', 'js'], true)
                    && is_string($e['path'])
                    && strpos($e['path'], '..') === false
            );
            // api_routes entries mirror PluginApiRegistry::register
            // shape: {method, action} where method ∈ HTTP verbs and
            // action is the path suffix under /api/v1/plugins/<id>/.
            $row['api_routes'] = $this->shapedListField(
                $manifest,
                'api_routes',
                fn($e): bool => is_array($e)
                    && isset($e['method'], $e['action'])
                    && in_array($e['method'], ['GET','POST','PUT','PATCH','DELETE'], true)
                    && is_string($e['action'])
                    && preg_match('/^[a-z][a-z0-9-]{0,63}$/', $e['action']) === 1
            );
            // public_routes entries expose a non-admin surface to
            // unauthenticated-to-eIOU customers, routed at /p/<plugin-id>/
            // <action>. Each entry: {method, action, auth, rate_per_minute,
            // max_body_bytes, cors_allowed_origins}. auth is restricted
            // to "bearer" today — the plugin validates the bearer
            // against its own state; the host only shape-validates
            // and rate-limits. Bounded rate cap so a misconfigured
            // manifest can't disable rate limiting; bounded max body
            // so a misconfigured manifest can't accept arbitrarily
            // large payloads. cors_allowed_origins, when present, must
            // be a list of explicit origin strings (no wildcard `*` —
            // that defeats the whole point of allow-listing) capped
            // at 10 entries to keep the generated nginx block sane.
            $row['public_routes'] = $this->shapedListField(
                $manifest,
                'public_routes',
                $this->publicRouteEntryValidator()
            );
            $row['cli_commands'] = $this->shapedListField(
                $manifest,
                'cli_commands',
                fn($e): bool => is_array($e)
                    && isset($e['name']) && is_string($e['name'])
                    && preg_match('/^[a-z][a-z0-9-]*$/', $e['name']) === 1
            );
            // payback_method_types entries declare plugin-provided rail
            // types (BTC, PayPal, etc.) that bridge into the wallet's
            // PaybackMethodTypeRegistry. Each entry carries the static
            // catalog row (id, label, group, icon, description,
            // currencies, fields); the dynamic methods (validate, mask,
            // defaultPrecision) are forwarded into the plugin's
            // __dispatch.php with type "payback_method". `id` must match
            // ^[a-z][a-z0-9_]{0,31}$ (the same shape the registry
            // enforces) and not collide with the reserved core ids
            // bank_wire / custom — the registry would refuse those at
            // registration time, but filtering here keeps malformed
            // manifests off the row entirely.
            $row['payback_method_types'] = $this->shapedListField(
                $manifest,
                'payback_method_types',
                fn($e): bool => is_array($e)
                    && isset($e['id']) && is_string($e['id'])
                    && preg_match('/^[a-z][a-z0-9_]{0,31}$/', $e['id']) === 1
                    && !in_array($e['id'], ['bank_wire', 'custom'], true)
                    && isset($e['catalog']) && is_array($e['catalog'])
            );
            // cron entries declare host-driven scheduled tasks for the
            // plugin. The host's PluginCronService ticks every minute
            // and POSTs a `cron`-typed envelope to the plugin's
            // __dispatch.php whenever an entry's interval has elapsed
            // since its last fire. interval_minutes is bounded
            // [1, 1440] (one minute to one day) — finer granularity is
            // out of scope (the tick is per-minute), and longer-than-
            // daily entries should fold the day-of check into the
            // plugin handler. Action is the same kebab-case shape as
            // public_routes; the plugin's __dispatch.php switch routes
            // on it.
            $row['cron'] = $this->shapedListField(
                $manifest,
                'cron',
                fn($e): bool => is_array($e)
                    && isset($e['interval_minutes'], $e['action'])
                    && is_int($e['interval_minutes'])
                    && $e['interval_minutes'] >= 1
                    && $e['interval_minutes'] <= 1440
                    && is_string($e['action'])
                    && preg_match('/^[a-z][a-z0-9-]{0,63}$/', $e['action']) === 1
            );
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

            // Surface signature verification status. Verifier may be null
            // in tests and early-boot paths; when null, emit `sig_mode:off`
            // so consumers can distinguish "not checked" from "no signature".
            if ($this->sigVerifier !== null && $this->sigMode !== PluginSignatureVerifier::MODE_OFF) {
                $sigResult = $this->sigVerifier->verify($this->pluginDir . '/' . $entry);
                $row['signature'] = $sigResult + ['mode' => $this->sigMode];
            } else {
                $row['signature'] = ['status' => 'disabled', 'mode' => $this->sigMode];
            }

            $result[] = $row;
        }

        usort($result, fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $result;
    }

    /**
     * Return the live PluginInterface instance for a plugin, or null if
     * it isn't currently loaded (disabled, failed, or never discovered).
     * Used by the uninstall flow to locate an UninstallablePlugin's
     * onUninstall() hook. Most plugins are disabled at uninstall time
     * (uninstall requires disabled first) so this usually returns null —
     * which is fine, the hook is optional.
     */
    public function getPluginInstance(string $name): ?PluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * Remove a plugin's entry from the persisted state file entirely
     * (as opposed to setEnabled(false) which just flips the flag).
     * Used by the uninstall flow after the plugin's files and MySQL
     * artefacts are gone.
     *
     * Returns true if an entry was removed, false if the plugin had no
     * state entry to begin with (idempotent on re-run).
     */
    public function removeFromState(string $name): bool
    {
        $state = $this->readState();
        if (!array_key_exists($name, $state)) {
            return false;
        }
        unset($state[$name]);
        return $this->writeState($state);
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
     * are healed by the boot-time reconciler on the next boot.
     */
    public function setEnabled(string $name, bool $enabled): bool
    {
        $this->lastSetEnabledFailure = null;

        // Refuse to enable a plugin whose manifest doesn't opt into
        // sandboxing. Disabling a non-sandboxed plugin always
        // succeeds at the state-flip layer (operator wants to mark
        // it disabled even if it was never going to load anyway).
        if ($enabled) {
            $manifest = $this->readManifestFromDisk($name);
            if ($manifest !== null && empty($manifest['sandboxed'])) {
                $this->logger->warning(
                    "Refusing to enable non-sandboxed plugin",
                    [
                        'plugin' => $name,
                        'remediation' => 'Set "sandboxed": true in plugin.json. '
                                       . 'See docs/PLUGINS.md (Sandboxed Plugin Authoring).',
                    ]
                );
                $this->lastSetEnabledFailure = [
                    'stage' => 'refused',
                    'message' => "Plugin '{$name}' is not sandboxed. "
                               . 'Add "sandboxed": true to its plugin.json.',
                ];
                return false;
            }
        }

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
            $this->lastSetEnabledFailure = [
                'stage' => 'isolation',
                'message' => "MySQL isolation step failed for '{$name}': " . $e->getMessage(),
            ];
            return false;
        }

        // Sandbox side-effects. Only run for plugins that declare
        // "sandboxed": true in their manifest. Failures abort the
        // state flip the same way isolation failures do — operator
        // can retry; boot-time reconcile self-heals partial states.
        if (!$this->applySandboxSideEffects($name, $enabled)) {
            $this->lastSetEnabledFailure = [
                'stage' => 'sandbox',
                'message' => "FPM pool / system-user / nginx step failed for '{$name}'. "
                           . 'Check /var/log/app.log for the supervisor response — '
                           . 'common causes: apply-pool timeout, nginx -t failure, '
                           . 'or system-user creation refused.',
            ];
            return false;
        }

        $state = $this->readState();
        $state[$name] = ['enabled' => $enabled];
        if ($this->writeState($state)) {
            return true;
        }

        // State write failed AFTER sandbox side effects committed (the
        // pool is alive but plugins.json doesn't reflect it). Before
        // rolling back, re-read the state file: if the rename actually
        // landed despite writeState reporting failure (transient
        // filesystem quirks have produced this on WSL2 / overlayfs in
        // the past — the rename succeeds but rename()'s return value
        // surfaces a stale errno), treat that as success rather than
        // tearing down a pool that's already configured correctly.
        $verify = $this->readState();
        if (($verify[$name]['enabled'] ?? null) === $enabled) {
            $this->logger->warning(
                "writeState reported failure but on-disk state matches target; treating as success",
                ['plugin' => $name, 'target_enabled' => $enabled]
            );
            return true;
        }

        $this->logger->error("writeState failed after sandbox side-effects — rolling back", [
            'plugin' => $name,
            'target_enabled' => $enabled,
        ]);
        if (!$this->applySandboxSideEffects($name, !$enabled)) {
            $this->logger->critical(
                "sandbox rollback failed; plugin pool is in a state divergent from plugins.json. "
                . "Boot-time reconcileSandbox will attempt cleanup.",
                ['plugin' => $name, 'target_enabled' => $enabled]
            );
        }
        $this->lastSetEnabledFailure = [
            'stage' => 'state',
            'message' => "Could not persist plugins.json for '{$name}'. "
                       . 'Sandbox side-effects were rolled back; '
                       . 'check filesystem permissions on /etc/eiou/config/.',
        ];
        return false;
    }

    /**
     * Return the failure context from the last setEnabled() call, or
     * null after a successful call (or before any call). The returned
     * array has 'stage' (refused | isolation | sandbox | state) and
     * 'message' (operator-facing description) keys. Callers use this
     * to print a specific error instead of the generic legacy string.
     *
     * @return array{stage:string, message:string}|null
     */
    public function getLastSetEnabledFailure(): ?array
    {
        return $this->lastSetEnabledFailure;
    }

    /**
     * Apply the sandbox side-effects for a plugin transition:
     *
     *   enable + sandboxed=true  → ensureUser + applyPool with refreshed
     *                              nginx snippet that now includes this
     *                              plugin
     *   disable + sandboxed=true → dropPool with refreshed snippet that
     *                              now excludes this plugin, then dropUser
     *   either + sandboxed=false → no-op (non-sandboxed plugin path)
     *
     * Returns true on success or no-op. Returns false (and logs) if the
     * plugin is sandboxed but the sandbox services aren't wired, or if
     * any step fails. On false the caller (setEnabled) aborts the state
     * flip — partial commits are healed by reconcileSandbox() on the
     * next boot.
     */
    private function applySandboxSideEffects(string $name, bool $enabled): bool
    {
        $manifest = $this->readManifestFromDisk($name);
        if ($manifest === null || empty($manifest['sandboxed'])) {
            return true; // non-sandboxed plugin — nothing to do
        }

        if ($this->userService === null
            || $this->poolService === null
            || $this->nginxConfigService === null
        ) {
            $this->logger->error(
                "Sandbox transition refused — sandbox services not wired",
                ['plugin' => $name, 'target_enabled' => $enabled]
            );
            return false;
        }

        try {
            if ($enabled) {
                // Ensure user first. Pool config references the user, and
                // applyPool's nginx -t would fail if the user doesn't exist
                // yet (FPM refuses to start a pool whose user is unknown).
                if (!$this->userService->ensureUser($name)) {
                    $this->logger->error("ensureUser failed for sandboxed plugin", ['plugin' => $name]);
                    return false;
                }
                $systemUser = $this->userService->systemUsername($name);
                [$snippet, $zones] = $this->renderNginxArtifactsWithDelta($name, $systemUser, true);
                // Force-rotate the gateway token on explicit
                // operator toggle so any previously-leaked token is
                // invalidated. Reconcile uses the idempotent path.
                if (!$this->poolService->applyPool($name, $systemUser, $snippet, true, $zones)) {
                    $this->logger->error("applyPool failed for sandboxed plugin", ['plugin' => $name]);
                    return false;
                }
            } else {
                [$snippet, $zones] = $this->renderNginxArtifactsWithDelta($name, null, false);
                if (!$this->poolService->dropPool($name, $snippet, $zones)) {
                    $this->logger->error("dropPool failed for sandboxed plugin", ['plugin' => $name]);
                    return false;
                }
                if (!$this->userService->dropUser($name)) {
                    // User removal failed but pool is already gone — log
                    // but treat as success since the security boundary is
                    // intact (no pool = no place for the user to run code).
                    // Reconcile at next boot will clean the orphaned user.
                    $this->logger->warning("dropUser failed; will retry at next boot", ['plugin' => $name]);
                }
            }
            return true;
        } catch (Throwable $e) {
            $this->logger->error("Sandbox side-effect threw", [
                'plugin' => $name,
                'target_enabled' => $enabled,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Render the full nginx snippet for the supervisor's apply step.
     *
     * "Delta" because the caller is mid-transition — the on-disk state
     * doesn't yet reflect the change being applied. So we collect the
     * current sandboxed+enabled set from state, then add / remove the
     * plugin being transitioned to produce the AFTER view.
     */
    /**
     * Render both nginx artifacts the supervisor needs in lockstep:
     * the location-blocks snippet and the http{}-scope zone
     * declarations. Returned as a two-tuple [snippet, zones] so the
     * caller passes them to applyPool/dropPool together.
     *
     * @return array{0:string, 1:string}
     */
    private function renderNginxArtifactsWithDelta(string $name, ?string $systemUserOnEnable, bool $enabling): array
    {
        $entries = $this->collectSandboxedRouteEntries();

        if ($enabling) {
            $alreadyPresent = false;
            foreach ($entries as $e) {
                if ($e['plugin_id'] === $name) { $alreadyPresent = true; break; }
            }
            if (!$alreadyPresent && $systemUserOnEnable !== null) {
                // The in-flight plugin isn't in state yet, so pull its
                // public_routes directly from its on-disk manifest so
                // the about-to-be-applied snippet already routes its
                // public endpoints (if any) when the supervisor reloads.
                $manifest = $this->readManifestFromDisk($name);
                $publicRoutes = [];
                if (is_array($manifest)) {
                    $publicRoutes = $this->shapedListField(
                        $manifest,
                        'public_routes',
                        $this->publicRouteEntryValidator()
                    );
                }
                $entries[] = [
                    'plugin_id'     => $name,
                    'system_user'   => $systemUserOnEnable,
                    'public_routes' => $publicRoutes,
                ];
            }
        } else {
            $entries = array_values(array_filter(
                $entries,
                fn(array $e): bool => $e['plugin_id'] !== $name
            ));
        }

        // Stable order so the rendered snippet diffs cleanly between calls.
        usort($entries, fn(array $a, array $b): int => strcmp($a['plugin_id'], $b['plugin_id']));

        return [
            $this->nginxConfigService->renderSnippet($entries),
            $this->nginxConfigService->renderZones($entries),
        ];
    }

    /**
     * Render the nginx snippet + zones for the current sandboxed-and-enabled
     * plugin set as it appears in on-disk state right now — no delta
     * applied. Used by PluginUpgradeService when re-applying a pool
     * after replacing a plugin's code: the route shape hasn't changed,
     * but the re-apply is what triggers the supervisor's SIGUSR2 to
     * FPM so the new code is picked up by fresh workers.
     *
     * @return array{0:string, 1:string} [snippet, zones]
     */
    public function renderActiveSandboxArtifacts(): array
    {
        $entries = $this->collectSandboxedRouteEntries();
        usort($entries, fn(array $a, array $b): int => strcmp($a['plugin_id'], $b['plugin_id']));
        return [
            $this->nginxConfigService->renderSnippet($entries),
            $this->nginxConfigService->renderZones($entries),
        ];
    }

    /**
     * Collect the (plugin_id, system_user, public_routes) entries for every
     * sandboxed-enabled plugin currently on disk. Reads from the state file
     * + on-disk manifests so it works before / after / during loadPlugin().
     *
     * @return list<array{plugin_id:string, system_user:string, public_routes:list<array<string,mixed>>}>
     */
    private function collectSandboxedRouteEntries(): array
    {
        if ($this->userService === null) return [];
        $state = $this->readState();
        $result = [];
        foreach ($state as $pluginId => $entry) {
            if (empty($entry['enabled'])) continue;
            $manifest = $this->readManifestFromDisk((string) $pluginId);
            if ($manifest === null || empty($manifest['sandboxed'])) continue;
            try {
                // Run the same shaped-list filter the discover() row uses
                // so the route data the renderer sees is already
                // validated. Without this the renderer would do its own
                // shape check anyway, but here we drop bad entries
                // earlier and they don't pollute the snippet at all.
                $publicRoutes = $this->shapedListField(
                    $manifest,
                    'public_routes',
                    $this->publicRouteEntryValidator()
                );
                $result[] = [
                    'plugin_id'     => (string) $pluginId,
                    'system_user'   => $this->userService->systemUsername((string) $pluginId),
                    'public_routes' => $publicRoutes,
                ];
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning("Skipping malformed plugin id in state", [
                    'plugin_id' => $pluginId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $result;
    }

    /**
     * Detect directory names that PluginUpgradeService creates when
     * snapshotting an old plugin during upgrade ("<id>.backup-<ver>-
     * <YYYYMMDD-HHMMSS>"). The scan loops skip these so a backup of
     * `hello-eiou` doesn't surface as a duplicate "hello-eiou" plugin
     * (its manifest carries the same `name` field). Anchored on the
     * exact shape PluginUpgradeService produces; renaming a backup
     * out of this pattern (e.g. for long-term retention) re-exposes
     * it to the loader, which is fine because such a rename was an
     * operator decision.
     */
    public static function isUpgradeBackupDir(string $entry): bool
    {
        return (bool) preg_match('/\.backup-[A-Za-z0-9._-]+-\d{8}-\d{6}$/', $entry);
    }

    /**
     * Extract a manifest field that should be a list of strings matching
     * a regex. Drops entries that don't match. Empty / non-array values
     * normalize to []. Used by declarative surface parsing.
     *
     * @return list<string>
     */
    private function stringListField(array $manifest, string $key, string $regex): array
    {
        $raw = $manifest[$key] ?? [];
        if (!is_array($raw)) return [];
        return array_values(array_filter(
            $raw,
            fn($entry): bool => is_string($entry) && preg_match($regex, $entry) === 1
        ));
    }

    /**
     * Extract a manifest field that should be a list of objects passing
     * a per-entry validator. Used for richer manifest fields (tabs,
     * actions, etc.) where entries carry sub-keys.
     *
     * @return list<array<string, mixed>>
     */
    private function shapedListField(array $manifest, string $key, callable $validator): array
    {
        $raw = $manifest[$key] ?? [];
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $entry) {
            if ($validator($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Validator closure for one public_routes entry. Centralized so the
     * three call sites (discover / renderNginxSnippetWithDelta /
     * collectSandboxedRouteEntries) can't drift apart and pass a
     * manifest entry the others would reject.
     */
    private function publicRouteEntryValidator(): callable
    {
        return fn($e): bool => is_array($e)
            && isset($e['method'], $e['action'])
            && in_array($e['method'], ['GET','POST','PUT','PATCH','DELETE'], true)
            && is_string($e['action'])
            && preg_match('/^[a-z][a-z0-9-]{0,63}$/', $e['action']) === 1
            && (!isset($e['auth']) || $e['auth'] === 'bearer')
            && (!isset($e['rate_per_minute']) || (is_int($e['rate_per_minute']) && $e['rate_per_minute'] > 0 && $e['rate_per_minute'] <= 6000))
            && (!isset($e['max_body_bytes']) || (is_int($e['max_body_bytes']) && $e['max_body_bytes'] > 0 && $e['max_body_bytes'] <= 1048576))
            && (!isset($e['cors_allowed_origins']) || $this->isValidCorsOriginsList($e['cors_allowed_origins']));
    }

    /**
     * Validate a `cors_allowed_origins` value for a public_routes entry.
     * Must be a list of explicit origin strings: `scheme://host[:port]`,
     * scheme limited to http/https, host limited to RFC-1123 LDH +
     * dots. No wildcard `*` (allow-list with a wildcard is not an
     * allow-list); no path component. Capped at 10 entries so a
     * misconfigured manifest can't generate a runaway nginx config.
     */
    private function isValidCorsOriginsList(mixed $value): bool
    {
        if (!is_array($value)) return false;
        if (count($value) === 0 || count($value) > 10) return false;
        foreach ($value as $origin) {
            if (!is_string($origin)) return false;
            if (preg_match('#^https?://[a-zA-Z0-9.-]+(:\d{1,5})?$#', $origin) !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * Read a plugin's manifest from disk. Cheap (small file), called from
     * the sandbox-side-effect path where we need the latest "sandboxed"
     * flag without depending on whether $this->metadata is populated
     * (sandboxed plugins don't get loaded into metadata's full shape).
     *
     * @return array<string, mixed>|null
     */
    private function readManifestFromDisk(string $name): ?array
    {
        $path = $this->pluginDir . '/' . $name . '/plugin.json';
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Boot-time reconcile of sandbox resources. Mirrors
     * reconcileIsolation() — runs through every enabled sandboxed
     * plugin on disk, ensures its Unix user + FPM pool config are
     * present, and drops stranded resources for plugins that were
     * uninstalled / disabled outside the normal lifecycle.
     *
     * Idempotent. Safe to call on every boot. Failures during the
     * pass are logged per-plugin but never throw — a single broken
     * plugin must not block the whole reconcile.
     *
     * @return array{
     *     applied: list<string>,
     *     dropped: list<string>,
     *     errors:  list<array{plugin_id:string, message:string}>
     * }
     */
    public function reconcileSandbox(): array
    {
        $report = ['applied' => [], 'dropped' => [], 'skipped' => [], 'errors' => []];
        if ($this->userService === null
            || $this->poolService === null
            || $this->nginxConfigService === null
        ) {
            return $report;
        }

        // Cold-boot race guard. Application::__construct runs in every
        // PHP entrypoint — GUI workers, CLI calls, AND each of the 4
        // message processors as they spawn. On a fresh container boot
        // the processors all bootstrap roughly simultaneously, each
        // calls reconcileSandbox, and each sees "pool not yet on disk"
        // (because the first one's apply-pool hasn't completed the
        // supervisor round-trip yet). Result: 4 apply-pool requests
        // for the same plugin, all firing before the first one settled.
        //
        // A non-blocking flock serialises this. The first caller
        // acquires the lock and does the work; the rest immediately
        // skip with skipped=[lock_held]. Their isPoolUpToDate check
        // would have short-circuited a moment later anyway once the
        // first finished, so functionally nothing is lost.
        $lockPath = '/tmp/eiou-sandbox-reconcile.lock';
        $lockFp = @fopen($lockPath, 'c');
        if ($lockFp === false) {
            // Can't open the lock — fall back to the unlocked path.
            // Rare; the lock is just an optimization, the actual
            // safety properties (idempotent apply, isPoolUpToDate)
            // are unchanged whether or not we serialize.
        } elseif (!@flock($lockFp, LOCK_EX | LOCK_NB)) {
            // Another process holds the reconcile lock right now.
            // Skip silently; the winner will get the plugins into
            // the correct state.
            fclose($lockFp);
            $report['skipped'][] = '__lock_held';
            return $report;
        }

        try {
            $result = $this->doReconcileSandbox($report);
        } finally {
            if (is_resource($lockFp)) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
        }
        return $result;
    }

    /**
     * The actual reconcile body, factored out of reconcileSandbox so the
     * flock wrapper above can guard a single execution per boot. Don't
     * call this directly — go through reconcileSandbox().
     *
     * @param array{applied:list<string>, dropped:list<string>, skipped:list<string>, errors:list<array{plugin_id:string, message:string}>} $report
     */
    private function doReconcileSandbox(array $report): array
    {
        $entries = $this->collectSandboxedRouteEntries();
        $snippet = $this->nginxConfigService->renderSnippet($entries);
        $zones   = $this->nginxConfigService->renderZones($entries);

        // Forward-pass: ensure each enabled-sandboxed plugin has its
        // user + pool. Short-circuit on plugins where on-disk state
        // already matches what we'd produce — without this every
        // Application::__construct (and there are many: GUI page, CLI
        // invocation, health probe) re-applies every sandboxed plugin
        // and the supervisor log fills with "apply-pool complete"
        // lines. The check itself reads a few small files (pool
        // config, nginx snippet, tokens index, dispatcher path) so
        // it's much cheaper than a supervisor round-trip.
        foreach ($entries as $entry) {
            $pluginId = $entry['plugin_id'];
            try {
                if (!$this->userService->ensureUser($pluginId)) {
                    $report['errors'][] = ['plugin_id' => $pluginId, 'message' => 'ensureUser failed'];
                    continue;
                }
                if ($this->poolService->isPoolUpToDate($pluginId, $entry['system_user'], $snippet)) {
                    $report['skipped'][] = $pluginId;
                    continue;
                }
                if (!$this->poolService->applyPool($pluginId, $entry['system_user'], $snippet, false, $zones)) {
                    $report['errors'][] = ['plugin_id' => $pluginId, 'message' => 'applyPool failed'];
                    continue;
                }
                $report['applied'][] = $pluginId;
            } catch (Throwable $e) {
                $report['errors'][] = ['plugin_id' => $pluginId, 'message' => $e->getMessage()];
            }
        }

        // Reverse pass for stranded users/pools is intentionally NOT
        // implemented here — it requires enumerating /etc/php/<ver>/fpm/pool.d/
        // and /etc/passwd to find eiou-p-* entries that have no matching
        // plugin. Both are root-only reads, so when added the reverse
        // pass will live in the supervisor poller, not here.
        return $report;
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

            // Export credentials to the on-disk file siblings can mount.
            // Best-effort: a failure here logs but does NOT abort the
            // enable. The plugin still functions inside the eIOU
            // container; only the sibling-container surface is degraded.
            if ($this->credentialsExportService !== null) {
                $this->credentialsExportService->export($name, $plaintext);
            }
        } else {
            // Leave credentials + user + tables in place. Disable is
            // meant to be cheaply reversible; uninstall is the path that
            // drops everything, and it goes through a separate method.
            $this->dbUserService->revoke($name);

            // Remove the sibling-mountable credentials file. The MySQL
            // REVOKE above already cuts off any sibling that was
            // authenticated, but deleting the file makes the disabled
            // state visible on disk and prevents a stale file from
            // confusing a fresh sibling deploy.
            if ($this->credentialsExportService !== null) {
                $this->credentialsExportService->revoke($name);
            }
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
                    // Re-export the sibling-mountable credentials file
                    // so a volume rebuild / manual delete self-heals on
                    // the next boot. Failure logs but doesn't change
                    // the result code — MySQL state is what reconcile's
                    // status reflects.
                    if ($this->credentialsExportService !== null) {
                        $this->credentialsExportService->export($pluginId, $plaintext);
                    }
                    $results[$pluginId] = 'granted';
                } else {
                    // For disabled plugins that *have* credentials, make
                    // sure privileges are revoked — self-heals cases where
                    // the row says disabled but a grant leaked through a
                    // partial-failure in setEnabled(false) from before the
                    // DDL-first ordering was added.
                    if ($this->credentialService->exists($pluginId)) {
                        $this->dbUserService->revoke($pluginId);
                        // Also clear any stale credentials file from an
                        // earlier enabled state so the on-disk view stays
                        // consistent with "disabled".
                        if ($this->credentialsExportService !== null) {
                            $this->credentialsExportService->revoke($pluginId);
                        }
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
     * See docs/PLUGINS.md (Database Isolation)for rationale.
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
     * See docs/PLUGINS.md (Database Isolation).
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
        // PluginLoader is consumed by both the operator CLI (typically
        // root via `docker exec`) and the www-data FPM pool. Without an
        // explicit chgrp, a root-written file ends up root:root 0640 —
        // the wallet pool then can't read its own state, and every
        // plugin enabled via CLI looks disabled until something
        // www-data-owned re-writes the file. @ swallows EPERM in the
        // www-data writer case (where the file is already
        // www-data-owned and no chown is needed). Same shape as
        // PluginGatewayTokenService::writeIndex.
        @chown($tmp, 'root');
        @chgrp($tmp, 'www-data');
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
        // broken until its first query. See docs/PLUGINS.md (Database Isolation).
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

        // Verify the plugin signature when a verifier is wired. The result
        // is always captured in metadata (so the plugin list surfaces it);
        // enforcement is policy-driven: in 'require' mode a failure blocks
        // load, in 'warn' mode it's logged but the plugin proceeds, in
        // 'off' mode verification is skipped entirely.
        $sigResult = ['status' => 'disabled']; // no verifier wired
        if ($this->sigVerifier !== null && $this->sigMode !== PluginSignatureVerifier::MODE_OFF) {
            $sigResult = $this->sigVerifier->verify($pluginPath);
            if ($sigResult['status'] !== 'ok') {
                $logCtx = [
                    'name' => $name,
                    'status' => $sigResult['status'],
                    'key_fingerprint' => $sigResult['key_fingerprint'] ?? null,
                    'error' => $sigResult['error'] ?? null,
                ];
                if ($this->sigMode === PluginSignatureVerifier::MODE_REQUIRE) {
                    $this->logger->warning('PluginLoader: signature verification failed; refusing to load', $logCtx);
                    // Record as failed so the plugin list surfaces it
                    // — "disappeared entirely" would be confusing.
                    $this->metadata[$name] = [
                        'version' => $manifest['version'],
                        'description' => $manifest['description'] ?? '',
                        'status' => 'failed',
                        'enabled' => (bool) ($state[$name]['enabled'] ?? false),
                        'error' => 'signature: ' . $sigResult['status']
                            . (isset($sigResult['error']) ? ' (' . $sigResult['error'] . ')' : ''),
                        'database' => $dbConfig,
                        'signature' => $sigResult,
                    ];
                    return;
                }
                $this->logger->info('PluginLoader: signature verification failed (warn mode)', $logCtx);
            }
        }

        // Default: DISABLED. A newly discovered plugin is inert until the
        // user explicitly enables it via the GUI (or by editing plugins.json
        // and restarting). This is a safety stance — a malicious or merely
        // buggy plugin dropped into the plugin directory cannot crash the
        // node on its first boot, because it never runs without consent.
        $enabled = (bool) ($state[$name]['enabled'] ?? false);

        // Parse the "sandboxed" flag. Sandboxing is now MANDATORY —
        // any plugin without `"sandboxed": true` is refused load. A
        // malicious in-process plugin could read the master key and
        // decrypt the seed phrase, so the in-process path is no longer
        // offered at all.
        $sandboxed = !empty($manifest['sandboxed']);

        if (!$enabled) {
            $this->metadata[$name] = [
                'version' => $manifest['version'],
                'description' => $manifest['description'] ?? '',
                'status' => 'disabled',
                'enabled' => false,
                'database' => $dbConfig,
                'signature' => $sigResult,
                'sandboxed' => $sandboxed,
            ];
            return;
        }

        // Enabled but NOT sandboxed → refuse to load. Plugin's files
        // stay on disk, listAllPlugins() surfaces a clear status
        // explaining why, but register()/boot() never run and the
        // autoloader never registers. Operator has to migrate the
        // plugin's manifest to "sandboxed": true (see docs/PLUGINS.md,
        // Sandboxing section) before it does anything.
        if (!$sandboxed) {
            $this->logger->warning(
                'PluginLoader: refusing to load non-sandboxed plugin',
                [
                    'name' => $name,
                    'reason' => 'Sandboxing is mandatory. Set "sandboxed": true in plugin.json.',
                ]
            );
            $this->metadata[$name] = [
                'version' => $manifest['version'],
                'description' => $manifest['description'] ?? '',
                'status' => 'legacy_unsupported',
                'enabled' => true,
                'database' => $dbConfig,
                'signature' => $sigResult,
                'sandboxed' => false,
                'error' => 'Plugin must declare "sandboxed": true. In-process plugins are not supported.',
            ];
            return;
        }

        // Sandboxed plugins NEVER load in-process. Their code runs only
        // in their own FPM pool, reached over IPC via __dispatch.php.
        // PluginLoader keeps a metadata entry so listAllPlugins() can
        // surface them, but does NOT register their autoloader, instantiate
        // their entry class, or run register()/boot(). This is the whole
        // point of sandboxing — the plugin's code never touches the wallet's
        // address space.
        $this->metadata[$name] = [
            'version' => $manifest['version'],
            'description' => $manifest['description'] ?? '',
            'status' => 'sandboxed',
            'enabled' => true,
            'database' => $dbConfig,
            'signature' => $sigResult,
            'sandboxed' => true,
        ];

        // The legacy in-process path (autoload registration + entry-class
        // instantiation + register()/boot() lifecycle) used to live here.
        // It was removed when sandboxing became mandatory — every load
        // path now either returns early (disabled / legacy_unsupported)
        // or stops at the sandboxed-metadata write above. The
        // registerPsr4() / instantiation helpers stayed in this file for
        // tests and for the `plugins` property that's still populated by
        // tests; production never reaches them.
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
