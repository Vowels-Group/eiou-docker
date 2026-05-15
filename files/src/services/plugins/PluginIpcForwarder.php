<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Plugins;

use Eiou\Events\EventDispatcher;
use Eiou\Utils\Logger;
use Throwable;

/**
 * PluginIpcForwarder
 *
 * Bridges in-process firing of events / filters / render hooks into
 * outbound HTTP POSTs to sandboxed plugins' __dispatch.php.
 *
 * Sandboxed plugins never load in-process — their `subscribes_to`,
 * `filter_hooks`, and `render_hooks` declarations in plugin.json
 * become the only contract. At Application boot, this service walks
 * the listAllPlugins() output, finds every sandboxed-and-enabled
 * plugin, and registers core-side closures that:
 *
 *   - For events: subscribe to EventDispatcher; on dispatch, POST the
 *     event data to the plugin's dispatcher with type "event".
 *   - For filters: register on Hooks::onFilter; on doFilter, POST the
 *     current value to the plugin, parse the returned `result` as the
 *     replacement value.
 *   - For render hooks: register on Hooks::onRender; on doRender, POST
 *     to the plugin, append the returned `result` HTML to the render
 *     output.
 *
 * All forwarder calls are synchronous with a 500ms per-plugin timeout.
 * A slow / dead plugin logs a warning and the chain continues — the
 * wallet must not be hostage to a misbehaving plugin.
 *
 * Logs the plugin emitted (in the response's `_log` array) are
 * forwarded into core's Logger with `{plugin: <id>}` context so they
 * appear in the wallet's central log under the plugin's name.
 *
 * See docs/PLUGINS.md (Sandboxing).
 */
class PluginIpcForwarder
{
    /**
     * Per-call IPC timeout in milliseconds, indexed by envelope type.
     *
     * User-blocking surfaces (event/filter/render/action/api/cli) stay
     * at 500ms — the GUI render or REST response is parked on the
     * plugin's reply, so a slow plugin must not block a real user.
     *
     * `cron` is the exception. Cron handlers run on a timer, off the
     * user's request path, and frequently do real work (drain a queue,
     * walk a small recordset, hit a sidecar) that won't realistically
     * finish in half a second. The earlier 500ms cap forced plugins to
     * narrow each tick to a couple of events and the host's loopback
     * curl declared `plugin_ipc_transport_failed` while the plugin's
     * PHP-FPM worker kept running to its own 30s
     * `request_terminate_timeout` — confusing logs around what was
     * actually successful work. 5s aligns with the cron cadence (≥60s
     * between ticks) and stays well under the FPM worker's own ceiling.
     *
     * Plugins can request a tighter or looser per-entry cap by declaring
     * `timeout_ms` on the relevant manifest entry (currently honoured
     * for `cron_actions`); the value is clamped to MAX_TIMEOUT_MS so a
     * misbehaving manifest can't pin a worker indefinitely.
     */
    public const TIMEOUT_BY_TYPE_MS = [
        'cron'      => 5000,
        'lifecycle' => 5000,
        'event'     => 500,
        'filter'    => 500,
        'render'    => 500,
        'action'    => 500,
        'api'       => 500,
        'cli'       => 500,
    ];

    /** Fallback when an envelope's type isn't in TIMEOUT_BY_TYPE_MS. */
    public const DEFAULT_TIMEOUT_MS = 500;

    /**
     * Ceiling on any manifest-supplied timeout override. Higher values
     * are silently clamped — a plugin manifest can't unilaterally
     * extend a worker's request budget beyond the FPM
     * `request_terminate_timeout` boundary (30s in PluginPoolService's
     * pool render). 25s leaves a 5s gap so the host always times out
     * first and logs the failure under our control.
     */
    public const MAX_TIMEOUT_MS = 25000;

    /**
     * Where to reach plugin dispatchers. The wallet's nginx routes
     * /gui/plugin/<id>/__dispatch to each plugin's FPM socket; we
     * always call through nginx so the routing config stays the
     * source of truth.
     */
    public const DEFAULT_DISPATCH_BASE = 'http://127.0.0.1';

    private PluginLoader $loader;
    private ?Logger $logger;
    private string $dispatchBase;
    /** Manifest-supplied per-entry timeout overrides, keyed by "plugin|type|name". */
    private array $timeoutOverrides = [];

    /**
     * Test-seam constructor timeout. Null means "use TIMEOUT_BY_TYPE_MS
     * per envelope"; a non-null value forces every dispatch to use that
     * value regardless of envelope type, preserving the pre-refactor
     * constructor contract for tests that pinned timeoutMs directly.
     */
    private ?int $timeoutMsOverride;

    /** @var callable(string $url, string $body, int $timeoutMs): array{ok:bool, status:int, body:?array, error?:string} */
    private $httpClient;

    public function __construct(
        PluginLoader $loader,
        ?Logger $logger = null,
        ?callable $httpClient = null,
        ?string $dispatchBase = null,
        ?int $timeoutMs = null
    ) {
        $this->loader = $loader;
        $this->logger = $logger;
        $this->dispatchBase = rtrim($dispatchBase ?? self::DEFAULT_DISPATCH_BASE, '/');
        $this->timeoutMsOverride = $timeoutMs;
        $this->httpClient = $httpClient ?? function (string $url, string $body, int $timeoutMs): array {
            return $this->curlDefault($url, $body, $timeoutMs);
        };
    }

    /**
     * Resolve the IPC timeout for one dispatch.
     *
     * Precedence (highest first):
     *   1. Constructor override (`$timeoutMs` arg) — wins for test seams
     *      that already pin a value.
     *   2. Per-entry manifest override registered via
     *      `setEntryTimeout()` — currently sourced from each plugin
     *      manifest's `cron_actions[*].timeout_ms`.
     *   3. Envelope type default from TIMEOUT_BY_TYPE_MS.
     *   4. DEFAULT_TIMEOUT_MS.
     */
    private function resolveTimeoutMs(string $pluginId, array $envelope): int
    {
        if ($this->timeoutMsOverride !== null) {
            return max(1, $this->timeoutMsOverride);
        }
        $type = (string) ($envelope['type'] ?? '');
        $name = (string) ($envelope['name'] ?? '');
        $key = $pluginId . '|' . $type . '|' . $name;
        if (isset($this->timeoutOverrides[$key])) {
            $clamped = max(1, min($this->timeoutOverrides[$key], self::MAX_TIMEOUT_MS));
            return $clamped;
        }
        return self::TIMEOUT_BY_TYPE_MS[$type] ?? self::DEFAULT_TIMEOUT_MS;
    }

    /**
     * Register a per-entry timeout override from a manifest field.
     * Called by the registration loops in `registerAll()` when a
     * plugin's `cron_actions` entry (or other future hook) declares
     * `timeout_ms`. Public so PluginCronService can poke an override
     * in for cron entries it manages outside of registerAll.
     *
     * Values are clamped to MAX_TIMEOUT_MS at resolve time, not here —
     * keep the recorded value verbatim so the next reconcile can pick
     * up a manifest change without restarting the host.
     */
    public function setEntryTimeout(string $pluginId, string $type, string $name, int $timeoutMs): void
    {
        if ($timeoutMs < 1) return;
        $this->timeoutOverrides[$pluginId . '|' . $type . '|' . $name] = $timeoutMs;
    }

    /**
     * Register forwarder closures on EventDispatcher + Hooks for every
     * sandboxed plugin's declared surfaces. Idempotent — call once at
     * boot after sandbox reconcile.
     *
     * Also wires declarative-only surfaces (gui_assets) directly into
     * the relevant registries. Those don't need IPC — they're just
     * "register-this-file-at-this-path" metadata read at boot.
     *
     * @return array{
     *     events:  list<array{plugin:string, event:string}>,
     *     filters: list<array{plugin:string, hook:string}>,
     *     renders: list<array{plugin:string, hook:string}>,
     *     assets:  list<array{plugin:string, type:string, path:string}>
     * }
     */
    public function registerAll(
        \Eiou\Services\Hooks $hooks,
        ?\Eiou\Services\Plugins\PluginAssetRegistry $assetRegistry = null,
        ?\Eiou\Services\TabRegistry $tabRegistry = null,
        ?\Eiou\Services\GuiActionRegistry $actionRegistry = null,
        ?\Eiou\Services\Plugins\PluginApiRegistry $apiRegistry = null,
        ?\Eiou\Services\Plugins\PluginCliRegistry $cliRegistry = null,
        ?\Eiou\Services\PaybackMethodTypeRegistry $paybackTypeRegistry = null,
        ?\Eiou\Services\PluginsTabPanelRegistry $pluginsTabPanelRegistry = null
    ): array {
        $report = [
            'events' => [], 'filters' => [], 'renders' => [],
            'assets' => [], 'plugin_tab_panels' => [], 'actions' => [],
            'api_routes' => [], 'cli_commands' => [],
            'payback_method_types' => [],
        ];
        foreach ($this->loader->listAllPlugins() as $row) {
            if (empty($row['enabled']) || empty($row['sandboxed'])) {
                continue;
            }
            $pluginId = (string) ($row['name'] ?? '');
            if ($pluginId === '') continue;

            foreach (($row['subscribes_to'] ?? []) as $event) {
                $this->registerEvent($pluginId, (string) $event);
                $report['events'][] = ['plugin' => $pluginId, 'event' => (string) $event];
            }
            foreach (($row['filter_hooks'] ?? []) as $hook) {
                $this->registerFilter($hooks, $pluginId, (string) $hook);
                $report['filters'][] = ['plugin' => $pluginId, 'hook' => (string) $hook];
            }
            foreach (($row['render_hooks'] ?? []) as $hook) {
                $this->registerRender($hooks, $pluginId, (string) $hook);
                $report['renders'][] = ['plugin' => $pluginId, 'hook' => (string) $hook];
            }
            // `tabs` is no longer the plugin-registration path — the
            // host owns a single Plugins tab and each plugin gets a
            // sub-panel inside it via `plugin_tab_panel`. Existing
            // manifests carrying `tabs` get a log warning so the
            // plugin author knows the path moved, but the entries
            // are not registered.
            if (!empty($row['tabs']) && is_array($row['tabs'])) {
                $this->log('warning', 'plugin_manifest_tabs_deprecated', [
                    'plugin' => $pluginId,
                    'count' => count($row['tabs']),
                    'replacement' => 'plugin_tab_panel',
                ]);
            }

            // plugin_tab_panel — single object {label, icon?, order?}
            // declaring this plugin's panel inside the host's Plugins
            // tab. Skip silently when the registry isn't wired so
            // test scaffolds that only care about events keep working.
            if ($pluginsTabPanelRegistry !== null) {
                $panel = $row['plugin_tab_panel'] ?? null;
                if (is_array($panel) && $this->registerPluginTabPanel($pluginsTabPanelRegistry, $pluginId, $panel)) {
                    $report['plugin_tab_panels'][] = [
                        'plugin' => $pluginId,
                        'label'  => (string) $panel['label'],
                    ];
                }
            }

            // GUI actions — POST handlers reachable via the wallet's
            // action dispatcher. The dispatcher's CSRF + tier checks
            // run in core (the tier is declared in the manifest); the
            // handler IPCs the validated request to the plugin and
            // writes the plugin's JSON result.
            if ($actionRegistry !== null) {
                foreach (($row['gui_actions'] ?? []) as $action) {
                    if (!is_array($action)) continue;
                    if ($this->registerAction($actionRegistry, $pluginId, $action)) {
                        $report['actions'][] = [
                            'plugin' => $pluginId, 'name' => (string) $action['name'],
                        ];
                    }
                }
            }

            // REST routes — forwarder turns `/api/v1/plugins/<id>/<action>`
            // hits into IPC POSTs to the plugin's __dispatch. The
            // PluginApiRegistry plumbing already validates the action
            // shape and dispatches; we just register handlers.
            if ($apiRegistry !== null) {
                foreach (($row['api_routes'] ?? []) as $route) {
                    if (!is_array($route)) continue;
                    if ($this->registerApiRoute($apiRegistry, $pluginId, $route)) {
                        $report['api_routes'][] = [
                            'plugin' => $pluginId,
                            'method' => (string) $route['method'],
                            'action' => (string) $route['action'],
                        ];
                    }
                }
            }

            // CLI commands — same shape, registered into PluginCliRegistry.
            // The eiou binary's dispatcher looks them up and the
            // handler IPCs into the plugin's pool.
            if ($cliRegistry !== null) {
                foreach (($row['cli_commands'] ?? []) as $cmd) {
                    if (!is_array($cmd)) continue;
                    if ($this->registerCliCommand($cliRegistry, $pluginId, $cmd)) {
                        $report['cli_commands'][] = [
                            'plugin' => $pluginId,
                            'name'   => (string) $cmd['name'],
                        ];
                    }
                }
            }

            // Payback-method rail types — manifest declares the static
            // catalog row, we instantiate an IpcPaybackMethodTypeProxy
            // and hand it to PaybackMethodTypeRegistry. The proxy's
            // dynamic methods (validate/mask/defaultPrecision) IPC into
            // the plugin's dispatcher on each call.
            if ($paybackTypeRegistry !== null) {
                foreach (($row['payback_method_types'] ?? []) as $entry) {
                    if (!is_array($entry)) continue;
                    if ($this->registerPaybackMethodType($paybackTypeRegistry, $pluginId, $entry)) {
                        $report['payback_method_types'][] = [
                            'plugin' => $pluginId,
                            'id'     => (string) $entry['id'],
                        ];
                    }
                }
            }

            // gui_assets are pure declarative — no IPC. Skip when the
            // asset registry isn't wired (test scaffolds) but keep the
            // rest of the registrations functional.
            if ($assetRegistry !== null) {
                foreach (($row['gui_assets'] ?? []) as $asset) {
                    if (!is_array($asset)) continue;
                    $type = (string) ($asset['type'] ?? '');
                    $path = (string) ($asset['path'] ?? '');
                    if ($path === '') continue;
                    $opts = is_array($asset['opts'] ?? null) ? $asset['opts'] : [];
                    $ok = false;
                    if ($type === 'css') {
                        $ok = $assetRegistry->enqueueStyle($pluginId, $path, $opts);
                    } elseif ($type === 'js') {
                        $ok = $assetRegistry->enqueueScript($pluginId, $path, $opts);
                    }
                    if ($ok) {
                        $report['assets'][] = [
                            'plugin' => $pluginId, 'type' => $type, 'path' => $path,
                        ];
                    } else {
                        $this->log('warning', 'plugin_ipc_asset_enqueue_failed', [
                            'plugin' => $pluginId, 'type' => $type, 'path' => $path,
                        ]);
                    }
                }
            }
        }
        return $report;
    }

    private function registerEvent(string $pluginId, string $event): void
    {
        EventDispatcher::getInstance()->subscribe(
            $event,
            function (array $data) use ($pluginId, $event): void {
                $this->dispatch($pluginId, [
                    'type' => 'event',
                    'name' => $event,
                    'context' => ['data' => $data],
                ]);
            }
        );
    }

    private function registerFilter(\Eiou\Services\Hooks $hooks, string $pluginId, string $hook): void
    {
        $hooks->onFilter(
            $hook,
            function ($value) use ($pluginId, $hook) {
                $response = $this->dispatch($pluginId, [
                    'type' => 'filter',
                    'name' => $hook,
                    'context' => ['value' => $value],
                ]);
                // Filters MUST return a value. If the plugin failed,
                // pass the input through unchanged so the filter chain
                // is never "lost" — better degraded than broken.
                if ($response === null || !array_key_exists('result', $response)) {
                    return $value;
                }
                return $response['result'];
            }
        );
    }

    private function registerRender(\Eiou\Services\Hooks $hooks, string $pluginId, string $hook): void
    {
        $hooks->onRender(
            $hook,
            function () use ($pluginId, $hook): string {
                $response = $this->dispatch($pluginId, [
                    'type' => 'render',
                    'name' => $hook,
                    'context' => [],
                ]);
                if ($response === null || !isset($response['result'])) {
                    return '';
                }
                $result = $response['result'];
                return is_string($result) ? $result : '';
            }
        );
    }

    /**
     * Register a plugin REST endpoint that forwards into the plugin's
     * dispatcher. Auth + admin-scope checks already happen upstream in
     * ApiController; by the time we get here the request is allowed.
     */
    private function registerApiRoute(
        \Eiou\Services\Plugins\PluginApiRegistry $apiRegistry,
        string $pluginId,
        array $route
    ): bool {
        $method = (string) ($route['method'] ?? '');
        $action = (string) ($route['action'] ?? '');
        if ($method === '' || $action === '') return false;

        $handler = function (string $method, array $params, string $body) use ($pluginId, $action) {
            $response = $this->dispatch($pluginId, [
                'type' => 'rest',
                'name' => $action,
                'context' => [
                    'method' => $method,
                    'params' => $params,
                    'body' => $body,
                ],
            ]);
            // PluginApiRegistry::dispatch wraps any non-array return as
            // ['result' => …]. Return whatever the plugin emitted so
            // the registry's behaviour is preserved.
            if ($response === null) {
                return [
                    'success' => false,
                    'error' => 'plugin_unavailable',
                    'message' => 'Plugin did not respond to the REST dispatch',
                ];
            }
            return is_array($response['result'] ?? null)
                ? $response['result']
                : ['result' => $response['result'] ?? null];
        };

        try {
            $apiRegistry->register($pluginId, $method, $action, $handler);
        } catch (Throwable $e) {
            $this->log('warning', 'plugin_ipc_api_register_failed', [
                'plugin' => $pluginId,
                'method' => $method,
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
        return true;
    }

    /**
     * Register a plugin CLI subcommand that forwards argv into the
     * plugin's dispatcher. Stdout / stderr from the plugin's response
     * (in result.stdout / result.stderr) come back to the operator's
     * CliOutputManager.
     */
    private function registerCliCommand(
        \Eiou\Services\Plugins\PluginCliRegistry $cliRegistry,
        string $pluginId,
        array $cmd
    ): bool {
        $name = (string) ($cmd['name'] ?? '');
        if ($name === '') return false;

        $handler = function (array $argv, \Eiou\Cli\CliOutputManager $output) use ($pluginId, $name): void {
            $response = $this->dispatch($pluginId, [
                'type' => 'cli',
                'name' => $name,
                'context' => ['argv' => $argv],
            ]);
            if ($response === null) {
                $output->error(
                    "Plugin '{$pluginId}' did not respond to the CLI dispatch",
                    \Eiou\Core\ErrorCodes::GENERAL_ERROR,
                    502
                );
                return;
            }
            $result = is_array($response['result'] ?? null) ? $response['result'] : [];
            $stdout = (string) ($result['stdout'] ?? '');
            $exit   = (int) ($result['exit_code'] ?? 0);
            if ($stdout !== '') {
                $output->success($stdout, $result);
            } else {
                // No stdout but exit_code 0 — emit a JSON envelope so
                // --json callers still see a success shape.
                $output->success("OK", $result);
            }
            // Non-zero exit codes from the plugin propagate as error
            // output. We don't have a clean way to set process exit
            // code from inside the dispatch closure; the CliOutput
            // surface treats errors as 1.
            if ($exit !== 0) {
                $stderr = (string) ($result['stderr'] ?? "Plugin exited with code {$exit}");
                $output->error($stderr, $result['error_code'] ?? 'plugin_error', $exit);
            }
        };

        try {
            $cliRegistry->register($name, $handler);
        } catch (Throwable $e) {
            $this->log('warning', 'plugin_ipc_cli_register_failed', [
                'plugin' => $pluginId,
                'name'   => $name,
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
        return true;
    }

    /**
     * Register a GUI action whose handler IPCs to the plugin's
     * dispatcher. CSRF + auth-tier validation stays in core's
     * dispatcher (the tier comes from the manifest); the handler only
     * runs after those gates pass.
     */
    private function registerAction(
        \Eiou\Services\GuiActionRegistry $actionRegistry,
        string $pluginId,
        array $action
    ): bool {
        $name = (string) ($action['name'] ?? '');
        if ($name === '') return false;
        $tier = (string) ($action['tier'] ?? \Eiou\Services\GuiActionRegistry::TIER_CSRF);
        $handler = function (array $request) use ($pluginId, $name): void {
            // The wallet's action dispatcher hands us $_POST. Forward
            // it to the plugin's __dispatch and write whatever JSON
            // the plugin produces. respond() in the plugin emits its
            // own envelope inside the _log + result fields; we just
            // mirror that on the response wire.
            $response = $this->dispatch($pluginId, [
                'type' => 'action',
                'name' => $name,
                'context' => ['post' => $request],
            ]);
            header('Content-Type: application/json');
            if ($response === null) {
                http_response_code(502);
                echo json_encode([
                    'success' => false,
                    'error' => 'plugin_unavailable',
                    'message' => 'Plugin did not respond to the action dispatch',
                ]);
                return;
            }
            $result = $response['result'] ?? null;
            // Plugin authors typically return a structured array;
            // emit it as the JSON envelope. A non-array result is
            // wrapped so the client always sees a JSON object.
            echo json_encode(is_array($result) ? $result : ['result' => $result]);
        };
        return $actionRegistry->register($name, $handler, $tier, $pluginId);
    }

    /**
     * Register the plugin's panel under the host's Plugins tab. The
     * render closure IPCs into the plugin's __dispatch with
     * type=render, name=plugin_tab_panel — note the fixed name (no
     * per-tab suffix), since each plugin has at most one panel.
     *
     * Returns true if the registry accepted the entry.
     */
    private function registerPluginTabPanel(
        \Eiou\Services\PluginsTabPanelRegistry $registry,
        string $pluginId,
        array $panel
    ): bool {
        $label = isset($panel['label']) && is_string($panel['label']) ? $panel['label'] : '';
        if ($label === '') {
            return false;
        }
        $entry = [
            'plugin_id' => $pluginId,
            'label'     => $label,
            'render'    => function () use ($pluginId): string {
                $response = $this->dispatch($pluginId, [
                    'type'    => 'render',
                    'name'    => 'plugin_tab_panel',
                    'context' => [],
                ]);
                if ($response === null || !isset($response['result'])) {
                    return '';
                }
                $result = $response['result'];
                return is_string($result) ? $result : '';
            },
        ];
        if (isset($panel['icon']) && is_string($panel['icon']) && $panel['icon'] !== '') {
            $entry['icon'] = $panel['icon'];
        }
        if (isset($panel['order']) && is_int($panel['order'])) {
            $entry['order'] = $panel['order'];
        }
        return $registry->register($entry);
    }

    /**
     * Register an IpcPaybackMethodTypeProxy for a plugin's manifest
     * payback_method_types entry. The proxy is stateless past
     * construction — every dynamic method call IPCs into the plugin's
     * pool — so two plugins each registering their own rail type
     * incur no coordination cost.
     */
    private function registerPaybackMethodType(
        \Eiou\Services\PaybackMethodTypeRegistry $registry,
        string $pluginId,
        array $entry
    ): bool {
        $typeId  = (string) ($entry['id'] ?? '');
        $catalog = $entry['catalog'] ?? null;
        if ($typeId === '' || !is_array($catalog)) return false;

        $proxy = new \Eiou\Services\Proxies\IpcPaybackMethodTypeProxy(
            $pluginId,
            $typeId,
            $catalog,
            fn(string $p, array $e): ?array => $this->dispatch($p, $e)
        );

        try {
            $registry->register($proxy);
            return true;
        } catch (Throwable $e) {
            // Collision with a core id, malformed id (despite the
            // loader's filter — defence in depth), or duplicate
            // registration with another plugin's type. Log + skip
            // this entry; sibling registrations on the same plugin
            // continue.
            $this->log('warning', 'plugin_ipc_payback_method_register_failed', [
                'plugin'  => $pluginId,
                'type_id' => $typeId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Dispatch one HTTP call to a plugin's __dispatch.php. Aggregates
     * logs from the response's _log into core's Logger. Returns the
     * parsed body or null on failure.
     *
     * @param array{type:string, name:string, context:array} $envelope
     * @return array<string, mixed>|null
     */
    /**
     * Fire a cron-typed envelope at a plugin's __dispatch.php.
     *
     * Mirrors the event/action dispatch path but is invoked on a
     * timer by PluginCronService rather than off an EventDispatcher
     * subscription. Exposed as a public entrypoint so the cron service
     * can reuse this forwarder's loopback HTTP plumbing (timeouts,
     * log forwarding, error logging) without duplicating it.
     *
     * The envelope shape:
     *     {type: "cron", name: <action>, context: {scheduled_at, interval_minutes}}
     *
     * Returns the decoded plugin response or null on any transport or
     * handler failure. Caller decides whether a null result advances
     * the next-fire window or retries on the next tick.
     */
    public function dispatchCron(string $pluginId, string $action, int $scheduledAt, int $intervalMinutes): ?array
    {
        return $this->dispatch($pluginId, [
            'type' => 'cron',
            'name' => $action,
            'context' => [
                'scheduled_at'     => $scheduledAt,
                'interval_minutes' => $intervalMinutes,
            ],
        ]);
    }

    /**
     * Fire a one-shot lifecycle dispatch — the replacement for the
     * pre-sandbox `boot()` method.
     *
     * Sandboxed plugins don't load in-process, so the old "run setup
     * on first include" path is gone. Operators expect equivalent
     * behaviour: after `eiou plugin enable <id>`, the plugin should
     * get one chance to wire up sidecars, verify providers, prime
     * caches, etc. PluginLoader::setEnabled() calls this through a
     * lifecycle-dispatcher callback after a successful enable, with
     * event="on_enable". Disable is intentionally NOT mirrored — a
     * plugin's pool is dropped before we'd dispatch to it, so the
     * post-disable hook would never reach a live worker. Cleanup
     * belongs in the plugin's `on_enable` initialisation register +
     * an explicit teardown action the operator can call.
     *
     * @param string $event "on_enable" or future lifecycle events.
     * @return array|null   Decoded plugin response or null on any
     *                      transport/handler failure. Caller decides
     *                      whether to surface the result; enablement
     *                      itself is already committed by this point.
     */
    public function dispatchLifecycle(string $pluginId, string $event): ?array
    {
        return $this->dispatch($pluginId, [
            'type' => 'lifecycle',
            'name' => $event,
            'context' => [
                'fired_at' => time(),
            ],
        ]);
    }

    private function dispatch(string $pluginId, array $envelope): ?array
    {
        $url = $this->dispatchBase . '/gui/plugin/' . $pluginId . '/__dispatch';
        $body = json_encode($envelope);
        if ($body === false) {
            $this->log('error', 'plugin_ipc_encode_failed', [
                'plugin' => $pluginId, 'type' => $envelope['type'], 'name' => $envelope['name'],
            ]);
            return null;
        }

        $timeoutMs = $this->resolveTimeoutMs($pluginId, $envelope);
        $result = ($this->httpClient)($url, $body, $timeoutMs);
        if (!$result['ok']) {
            $this->log('warning', 'plugin_ipc_transport_failed', [
                'plugin' => $pluginId,
                'type'   => $envelope['type'],
                'name'   => $envelope['name'],
                'status' => $result['status'] ?? 0,
                'error'  => $result['error'] ?? '',
            ]);
            return null;
        }

        $decoded = $result['body'];
        if (!is_array($decoded)) {
            $this->log('warning', 'plugin_ipc_bad_response', [
                'plugin' => $pluginId, 'name' => $envelope['name'],
            ]);
            return null;
        }

        // Forward plugin-emitted log entries into core's Logger.
        if (isset($decoded['_log']) && is_array($decoded['_log'])) {
            $this->writeForwardedLogs($pluginId, $decoded['_log']);
        }

        if (empty($decoded['ok'])) {
            $this->log('warning', 'plugin_ipc_handler_rejected', [
                'plugin' => $pluginId,
                'name'   => $envelope['name'],
                'error'  => $decoded['error'] ?? null,
            ]);
            return null;
        }

        return $decoded;
    }

    /**
     * @param list<array{level:string, message:string, context:array}> $entries
     */
    private function writeForwardedLogs(string $pluginId, array $entries): void
    {
        if ($this->logger === null) return;
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $level = (string) ($entry['level'] ?? 'info');
            $message = '[' . $pluginId . '] ' . (string) ($entry['message'] ?? '');
            $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
            $context['plugin'] = $pluginId;
            // Map plugin level names to Logger methods we know exist;
            // anything unrecognized falls back to info.
            $method = in_array($level, ['debug', 'info', 'warning', 'error'], true)
                ? $level : 'info';
            try {
                $this->logger->{$method}($message, $context);
            } catch (Throwable $e) {
                // Never let log forwarding fail the dispatch — drop quietly.
            }
        }
    }

    /**
     * @return array{ok:bool, status:int, body:?array, error?:string}
     */
    private function curlDefault(string $url, string $body, int $timeoutMs): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl_init failed'];
        }
        // Connect timeout caps at 1s — establishing a unix-socket-backed
        // loopback connection should never take real wall time. Past
        // that, the budget belongs to the plugin's handler.
        $connectMs = (int) max(50, min(1000, $timeoutMs / 2));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT_MS         => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS  => $connectMs,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $err];
        }
        $decoded = json_decode((string) $response, true);
        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => (int) $status,
            'body'   => is_array($decoded) ? $decoded : null,
        ];
    }

    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger === null) return;
        try {
            $this->logger->{$level}($message, $context);
        } catch (Throwable $e) {
            // ignore — logger failures must not break IPC
        }
    }
}
