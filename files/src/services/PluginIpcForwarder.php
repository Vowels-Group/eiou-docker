<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Events\EventDispatcher;
use Eiou\Utils\Logger;
use Throwable;

/**
 * PluginIpcForwarder
 *
 * Phase 5 bridge: turns in-process firing of events / filters / render
 * hooks into outbound HTTP POSTs to sandboxed plugins' __dispatch.php.
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
 * See docs/PLUGIN_SANDBOXING.md.
 */
class PluginIpcForwarder
{
    /** Per-call timeout in milliseconds (across connect + request). */
    public const DEFAULT_TIMEOUT_MS = 500;

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
    private int $timeoutMs;

    /** @var callable(string $url, string $body): array{ok:bool, status:int, body:?array, error?:string} */
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
        $this->timeoutMs = $timeoutMs ?? self::DEFAULT_TIMEOUT_MS;
        $this->httpClient = $httpClient ?? function (string $url, string $body): array {
            return $this->curlDefault($url, $body);
        };
    }

    /**
     * Register forwarder closures on EventDispatcher + Hooks for every
     * sandboxed plugin's declared surfaces. Idempotent — call once at
     * boot after sandbox reconcile.
     *
     * @return array{
     *     events:  list<array{plugin:string, event:string}>,
     *     filters: list<array{plugin:string, hook:string}>,
     *     renders: list<array{plugin:string, hook:string}>
     * }
     */
    public function registerAll(\Eiou\Services\Hooks $hooks): array
    {
        $report = ['events' => [], 'filters' => [], 'renders' => []];
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
     * Dispatch one HTTP call to a plugin's __dispatch.php. Aggregates
     * logs from the response's _log into core's Logger. Returns the
     * parsed body or null on failure.
     *
     * @param array{type:string, name:string, context:array} $envelope
     * @return array<string, mixed>|null
     */
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

        $result = ($this->httpClient)($url, $body);
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
    private function curlDefault(string $url, string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT_MS         => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS  => max(50, $this->timeoutMs / 2),
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
