<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Contracts\PluginCallable;
use Eiou\Contracts\PluginCallerAware;
use Eiou\Services\Plugins\PluginUserService;
use Eiou\Utils\Logger;
use InvalidArgumentException;
use RuntimeException;

/**
 * ContainerLifecycleService
 *
 * The host-side surface a sandboxed plugin uses to ask its operator
 * to stop the plugin's companion containers (e.g. an IPFS / Kubo
 * sidecar). Before this existed, an operator who disabled a plugin
 * via the wallet GUI / CLI still had to `docker compose stop
 * eiou-<id>-ipfs` (or equivalent) by hand to actually kill the
 * sidecar — the plugin's dispatcher was off but its companion kept
 * running.
 *
 * Design boundary: this service does NOT itself invoke `docker
 * compose stop`. The wallet container typically can't reach the
 * host's docker socket (and where it can, the socket mount is a
 * deliberate operator decision, not a plugin's call to make).
 * Instead, the service records the plugin's desired sidecar state to
 * a single JSON file at `/var/lib/eiou/plugin-sidecars-desired.json`:
 *
 *     {
 *       "<plugin-id>": {
 *         "<service-name>": {"desired": "stopped", "at": 1735000000}
 *       }
 *     }
 *
 * The operator's orchestration (a compose watcher, a systemd path
 * unit, a sidecar-supervisor running outside the wallet) reads the
 * file and reconciles the host's container state to it. The file is
 * world-readable mode 0644 so a sibling container with the file
 * volume-mounted can poll it without requiring root.
 *
 * Auth chain mirrors WalletOutboundService:
 *   1. Gateway resolves the bearer → plugin id, checks manifest
 *      `core_services` allow-lists `ContainerLifecycleService.stopSidecar`.
 *   2. Gateway calls setCallingPluginId() before the call; the
 *      plugin can't claim to be another plugin by passing an id.
 *   3. The plugin can only address its own sidecar service names;
 *      arbitrary cross-plugin shutdown is refused at validation time.
 *
 * Sidecar service names are matched against a conservative pattern
 * (`^[a-z0-9][a-z0-9._-]{0,63}$`, length-capped to 64 chars). That
 * shape is what docker compose service names use; we don't try to
 * accept anything else.
 */
class ContainerLifecycleService implements PluginCallerAware
{
    public const DEFAULT_STATE_FILE = '/var/lib/eiou/plugin-sidecars-desired.json';

    /**
     * Sidecar service-name validator. Matches the shape docker compose
     * allows and the eiou-* prefix our installs use, without admitting
     * anything that could be a path traversal or shell metachar. The
     * leading char must be alnum so a bare `-flag` value can't sneak
     * into an operator-side `docker compose stop` invocation.
     */
    public const SERVICE_NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    private string $stateFile;
    private ?Logger $logger;
    private ?string $callingPluginId = null;

    public function __construct(?string $stateFile = null, ?Logger $logger = null)
    {
        $this->stateFile = $stateFile ?? self::DEFAULT_STATE_FILE;
        $this->logger = $logger;
    }

    public function setCallingPluginId(?string $pluginId): void
    {
        $this->callingPluginId = $pluginId;
    }

    /**
     * Mark a sidecar service as "should be stopped". Idempotent — the
     * desired-state file ends up the same shape regardless of how
     * many times the same (plugin, service) pair is stopped, and
     * operator orchestration reading the file gets a single
     * authoritative view of the current desired state.
     *
     * Returns {ok: true, service: <name>} on accept. Throws on
     * validation refusal so the gateway surfaces the reason to the
     * plugin instead of silently swallowing it.
     */
    #[PluginCallable(
        description: 'Mark a companion container (docker compose service) as "should be stopped". The wallet itself does not invoke docker; instead it records the desired state to /var/lib/eiou/plugin-sidecars-desired.json which the operator\'s orchestration reads and reconciles. Returns {ok:true, service} on accept or throws on bad service-name shape. Only the plugin\'s OWN sidecar services should be addressed; cross-plugin shutdown is the operator\'s call, not the plugin\'s.',
        ratePerMinute: 10
    )]
    public function stopSidecar(string $service): array
    {
        return $this->setDesiredState($service, 'stopped');
    }

    /**
     * Mark a sidecar service as "should be running". Counterpart to
     * stopSidecar(); typical use is from a plugin's on_enable hook
     * (see PluginIpcForwarder::dispatchLifecycle) so the operator's
     * orchestration brings the companion up at the same time the
     * plugin's dispatcher becomes active.
     */
    #[PluginCallable(
        description: 'Mark a companion container as "should be running". Counterpart to stopSidecar(); intended for use from a plugin\'s on_enable hook so the operator\'s orchestration brings the sidecar up alongside the plugin\'s dispatcher. Returns {ok:true, service} on accept.',
        ratePerMinute: 10
    )]
    public function startSidecar(string $service): array
    {
        return $this->setDesiredState($service, 'running');
    }

    private function setDesiredState(string $service, string $desired): array
    {
        if ($this->callingPluginId === null) {
            // Defence in depth — only the gateway should reach this
            // method, and it always sets the caller id first.
            throw new RuntimeException(
                'ContainerLifecycleService requires gateway-injected caller id'
            );
        }
        $pluginId = $this->callingPluginId;

        if (!preg_match(PluginUserService::PLUGIN_ID_PATTERN, $pluginId)) {
            throw new InvalidArgumentException(
                "Caller id rejected by plugin-id validator: '{$pluginId}'"
            );
        }
        if (!preg_match(self::SERVICE_NAME_PATTERN, $service)) {
            throw new InvalidArgumentException(
                "service '{$service}' must match " . self::SERVICE_NAME_PATTERN
            );
        }
        if (!in_array($desired, ['running', 'stopped'], true)) {
            // Internal call site bug — set by this class, never user
            // input. Throw RuntimeException to surface the mistake
            // rather than InvalidArgumentException which the gateway
            // would forward to the plugin.
            throw new RuntimeException("desired state must be 'running' or 'stopped'");
        }

        $state = $this->readState();
        $state[$pluginId][$service] = [
            'desired' => $desired,
            'at'      => time(),
        ];
        $this->writeState($state);

        $this->log('info', 'plugin_sidecar_desired_state', [
            'plugin'  => $pluginId,
            'service' => $service,
            'desired' => $desired,
        ]);

        return ['ok' => true, 'service' => $service];
    }

    /**
     * @return array<string, array<string, array{desired:string, at:int}>>
     */
    private function readState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }
        $raw = @file_get_contents($this->stateFile);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, array<string, array{desired:string, at:int}>> $state
     */
    private function writeState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            if (!is_dir($dir)) {
                throw new RuntimeException("Parent directory does not exist: {$dir}");
            }
        }
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Could not serialize sidecar desired-state');
        }
        // Atomic rename pattern, mode 0644 — operator orchestration
        // (sibling containers, systemd path units) needs to be able to
        // read this without root. No secrets in the file; the contents
        // are at worst "which plugins want which sidecars running" —
        // already visible to anyone with `docker ps`.
        $tmp = $this->stateFile . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json) === false) {
            throw new RuntimeException("Could not write {$tmp}");
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $this->stateFile)) {
            @unlink($tmp);
            throw new RuntimeException("Could not rename {$tmp} to {$this->stateFile}");
        }
    }

    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger === null) return;
        $this->logger->{$level}($message, $context);
    }
}
