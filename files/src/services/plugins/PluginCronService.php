<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Plugins;

use Eiou\Utils\Logger;
use Throwable;

/**
 * PluginCronService
 *
 * Host-driven scheduler for sandboxed plugins. A plugin declares a list
 * of cron entries in its manifest:
 *
 *     "cron": [
 *       {"interval_minutes": 60,  "action": "flush-usage"},
 *       {"interval_minutes": 1440, "action": "daily-summary"}
 *     ]
 *
 * On each tick (driven by startup.sh's plugin_cron_poller at one-minute
 * cadence), this service:
 *
 *   1. Lists enabled + sandboxed plugins.
 *   2. For each plugin's cron entries, checks the state file to see when
 *      the (plugin, action) pair last fired.
 *   3. If `interval_minutes` has elapsed since that timestamp (or the
 *      pair has never fired), POSTs a `cron`-typed envelope to the
 *      plugin's __dispatch.php via PluginIpcForwarder::dispatchCron().
 *   4. Updates the state file with the new last-fired timestamp.
 *
 * Why interval_minutes rather than a full 5-field cron expression:
 *
 *   - The tick cadence is one minute. Sub-minute precision isn't
 *     possible regardless of the expression syntax used.
 *   - Cron expressions bring timezone + DST traps that don't pay off
 *     for the use cases this is for (periodic flushes, key TTL sweeps,
 *     usage rollups). Plugins that need "daily at 03:00 UTC" can fold
 *     the hour check into their handler since the dispatch envelope
 *     includes `scheduled_at`.
 *   - Simpler to validate at manifest-parse time, simpler to test,
 *     simpler to predict from an operator's POV.
 *
 * State file format:
 *
 *     {"<plugin-id>|<action>": <unix-ts>, ...}
 *
 * Written atomically (tmp + rename), same multi-writer-friendly
 * permissions as plugins.json so the file is readable by both the
 * wallet pool and the operator CLI.
 *
 * Per-(plugin, action) locking:
 *
 *   tick() uses `flock` with LOCK_EX|LOCK_NB on a lockfile under
 *   /tmp/eiou-plugin-cron-<sha-of-key>.lock around each fire. If a
 *   prior tick's invocation is still running (slow plugin handler,
 *   stalled IPC), the new tick skips with `skipped=[__lock_held]` and
 *   relies on the next minute's tick to retry once the lock is free.
 *   This prevents pile-up if a handler hangs.
 */
class PluginCronService
{
    public const DEFAULT_STATE_FILE = '/var/lib/eiou/plugin-cron-state.json';
    public const LOCK_DIR = '/tmp';
    public const LOCK_PREFIX = 'eiou-plugin-cron-';
    public const STATE_FILE_MODE = 0640;

    private PluginLoader $loader;
    private PluginIpcForwarder $forwarder;
    private ?Logger $logger;
    private string $stateFile;

    public function __construct(
        PluginLoader $loader,
        PluginIpcForwarder $forwarder,
        ?Logger $logger = null,
        ?string $stateFile = null
    ) {
        $this->loader = $loader;
        $this->forwarder = $forwarder;
        $this->logger = $logger;
        $this->stateFile = $stateFile ?? self::DEFAULT_STATE_FILE;
    }

    /**
     * One tick. Iterate enabled + sandboxed plugins; for each cron
     * entry whose interval has elapsed since its last fire, dispatch a
     * cron envelope and update state. Returns a per-tick report so
     * operator tooling can surface activity.
     *
     * @param int|null $nowTs Inject the current timestamp for tests.
     * @return array{
     *   fired: list<array{plugin:string, action:string, interval:int}>,
     *   skipped: list<array{plugin:string, action:string, reason:string}>,
     *   errors: list<array{plugin:string, action:string, reason:string}>
     * }
     */
    public function tick(?int $nowTs = null): array
    {
        $now = $nowTs ?? time();
        $state = $this->readState();
        $report = ['fired' => [], 'skipped' => [], 'errors' => []];

        foreach ($this->loader->listAllPlugins() as $row) {
            if (empty($row['enabled']) || empty($row['sandboxed'])) {
                continue;
            }
            $pluginId = $row['name'];
            $entries = $row['cron'] ?? [];
            if (!is_array($entries) || $entries === []) {
                continue;
            }

            foreach ($entries as $entry) {
                $action = (string) ($entry['action'] ?? '');
                $interval = (int) ($entry['interval_minutes'] ?? 0);
                if ($action === '' || $interval < 1) {
                    // Manifest validator should have caught these
                    // already; defence-in-depth skip.
                    $report['errors'][] = ['plugin' => $pluginId, 'action' => $action, 'reason' => 'invalid_entry'];
                    continue;
                }

                // Per-entry timeout override (optional). Plugins that
                // need to do more than fits in the default 5s cron
                // budget can declare `timeout_ms` on the manifest
                // entry; PluginIpcForwarder clamps the value to
                // MAX_TIMEOUT_MS at resolve time. Re-registering on
                // every tick is cheap and means a manifest edit takes
                // effect on the next iteration without a host restart.
                $perEntryTimeout = isset($entry['timeout_ms']) ? (int) $entry['timeout_ms'] : 0;
                if ($perEntryTimeout > 0) {
                    $this->forwarder->setEntryTimeout($pluginId, 'cron', $action, $perEntryTimeout);
                }

                $key = $this->stateKey($pluginId, $action);
                $lastFired = $state[$key] ?? 0;
                $elapsedMinutes = ($now - $lastFired) / 60;

                if ($lastFired !== 0 && $elapsedMinutes < $interval) {
                    $report['skipped'][] = [
                        'plugin' => $pluginId, 'action' => $action,
                        'reason' => 'not_due',
                    ];
                    continue;
                }

                // Per-entry lock — prevents a stalled previous tick
                // from racing the new one. flock release is
                // automatic on script exit, but we close explicitly
                // for clarity.
                $lockPath = self::LOCK_DIR . '/' . self::LOCK_PREFIX
                          . substr(hash('sha256', $pluginId . '|' . $action), 0, 16) . '.lock';
                $lockHandle = @fopen($lockPath, 'c');
                if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
                    if ($lockHandle !== false) fclose($lockHandle);
                    $report['skipped'][] = [
                        'plugin' => $pluginId, 'action' => $action,
                        'reason' => 'lock_held',
                    ];
                    continue;
                }

                try {
                    $response = $this->forwarder->dispatchCron($pluginId, $action, $now, $interval);
                    if ($response === null) {
                        $report['errors'][] = [
                            'plugin' => $pluginId, 'action' => $action,
                            'reason' => 'dispatch_failed',
                        ];
                        // Don't advance the last-fired window on
                        // transport failure — next tick retries.
                    } else {
                        $state[$key] = $now;
                        $report['fired'][] = [
                            'plugin' => $pluginId, 'action' => $action,
                            'interval' => $interval,
                        ];
                    }
                } catch (Throwable $e) {
                    $report['errors'][] = [
                        'plugin' => $pluginId, 'action' => $action,
                        'reason' => 'threw: ' . $e->getMessage(),
                    ];
                    $this->log('error', 'plugin_cron_dispatch_threw', [
                        'plugin' => $pluginId, 'action' => $action,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    flock($lockHandle, LOCK_UN);
                    fclose($lockHandle);
                }
            }
        }

        // Prune state entries whose (plugin, action) pair no longer
        // appears in any manifest (the plugin was uninstalled or the
        // entry was removed). Otherwise the state file grows monotonically.
        $state = $this->pruneState($state);

        if ($state !== []) {
            $this->writeState($state);
        }

        return $report;
    }

    private function stateKey(string $pluginId, string $action): string
    {
        return $pluginId . '|' . $action;
    }

    /**
     * @return array<string, int>
     */
    private function readState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }
        $raw = @file_get_contents($this->stateFile);
        if ($raw === false) {
            $this->log('warning', 'plugin_cron_state_unreadable', ['path' => $this->stateFile]);
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $ts) {
            if (is_string($key) && is_int($ts)) {
                $out[$key] = $ts;
            }
        }
        return $out;
    }

    /**
     * @param array<string, int> $state
     */
    private function writeState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->log('warning', 'plugin_cron_state_dir_unwritable', ['dir' => $dir]);
            return;
        }
        $tmp = $this->stateFile . '.tmp.' . getmypid();
        $encoded = json_encode($state, JSON_PRETTY_PRINT);
        if ($encoded === false) {
            $this->log('error', 'plugin_cron_state_encode_failed', []);
            return;
        }
        if (@file_put_contents($tmp, $encoded) === false) {
            $this->log('warning', 'plugin_cron_state_write_failed', ['path' => $this->stateFile]);
            return;
        }
        @chmod($tmp, self::STATE_FILE_MODE);
        // Same multi-writer rationale as plugins.json — the wallet pool
        // (www-data) reads/writes this file under HTTP, the operator CLI
        // (root) writes it via `eiou plugin cron-tick` from the
        // startup.sh poller. www-data must be able to read either.
        @chown($tmp, 'root');
        @chgrp($tmp, 'www-data');
        if (!@rename($tmp, $this->stateFile)) {
            @unlink($tmp);
            $this->log('warning', 'plugin_cron_state_rename_failed', ['path' => $this->stateFile]);
        }
    }

    /**
     * Drop state entries whose (plugin, action) pair is no longer
     * declared. Keeps the state file small over the lifetime of a
     * node that installs, removes, and reinstalls plugins.
     *
     * @param array<string, int> $state
     * @return array<string, int>
     */
    private function pruneState(array $state): array
    {
        $valid = [];
        foreach ($this->loader->listAllPlugins() as $row) {
            $pluginId = $row['name'];
            foreach (($row['cron'] ?? []) as $entry) {
                $action = (string) ($entry['action'] ?? '');
                if ($action !== '') {
                    $valid[$this->stateKey($pluginId, $action)] = true;
                }
            }
        }
        return array_intersect_key($state, $valid);
    }

    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger === null) return;
        try {
            $this->logger->{$level}($message, $context);
        } catch (Throwable $e) {
            // never let logging take down the tick
        }
    }
}
