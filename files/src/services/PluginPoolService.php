<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use InvalidArgumentException;

/**
 * PluginPoolService
 *
 * Renders the PHP-FPM pool configuration that runs a sandboxed plugin
 * as its own Unix user (created by PluginUserService) and pins
 * filesystem access to the plugin's directory + a scratch dir. With
 * this in place, a malicious plugin's PHP code cannot:
 *
 *   - Read /etc/eiou/config/.master.key (EACCES at the kernel layer
 *     because the plugin worker runs as eiou-p-<hash>, not www-data)
 *   - Read /etc/eiou/config/userconfig.json (same)
 *   - Open any path outside /etc/eiou/plugins/<id>/ + scratch dir
 *     (open_basedir refuses with "Operation not permitted")
 *   - Shell out via exec/shell_exec/passthru/proc_open/popen/system
 *     (disable_functions)
 *   - Stream-include arbitrary URLs (allow_url_fopen / include = 0)
 *
 * Pool files live in /etc/php/&lt;ver&gt;/fpm/pool.d/eiou-plugin-&lt;hash&gt;.conf and
 * require root to write. PHP-FPM workers run as www-data, so the
 * service hands rendered content + the target path to the supervisor
 * via the routing-apply request file (mirrors PluginUserService). The
 * supervisor in startup.sh writes the file, runs `nginx -t`, then
 * SIGHUP nginx + SIGUSR2 PHP-FPM, and returns a structured result.
 *
 * Phase 2 of plugin sandboxing — see docs/PLUGIN_SANDBOXING.md. Not
 * yet wired into PluginLoader's enable/disable lifecycle; a plugin
 * has to opt in via "sandboxed": true in its manifest (Phase 2 follow-
 * up) for a pool to be generated.
 */
class PluginPoolService
{
    /**
     * Where FPM looks for pool fragments. The PHP minor-version
     * directory has to match what's installed in the image, so we
     * resolve it at runtime via glob() rather than hard-pinning a
     * version that drifts with the base image. The first matching
     * `/etc/php/*\/fpm/pool.d/` wins; multiple installed versions
     * would be the operator's problem to disambiguate.
     */
    public static function poolDir(): string
    {
        $matches = glob('/etc/php/*/fpm/pool.d', GLOB_ONLYDIR) ?: [];
        return $matches[0] ?? '/etc/php/8.3/fpm/pool.d';
    }

    /** Where nginx reads the per-plugin location snippet. */
    public const NGINX_SNIPPET_PATH = '/etc/nginx/snippets/eiou-plugins.conf';

    /** Per-plugin writable scratch dir, included in open_basedir. */
    public const SCRATCH_ROOT = '/var/lib/eiou/plugin-scratch';

    /** Plugin source root (read-only from the plugin's perspective). */
    public const PLUGIN_ROOT = '/etc/eiou/plugins';

    /**
     * Where the canonical __dispatch.php template lives in the image.
     * Installed into each sandboxed plugin's dir at enable time so the
     * plugin always has a working dispatcher even before its author
     * provides a real one. Defaults to the source path used inside the
     * container; tests override via constructor.
     */
    public const DEFAULT_DISPATCHER_TEMPLATE = '/app/eiou/src/templates/plugin-dispatch-template.php';

    /**
     * disable_functions list. Each entry is a function the plugin's
     * worker must NOT have access to. exec/system family blocks shell-
     * out; assert/eval blocks late-binding code execution. pcntl_exec
     * is the fork-and-execve path; allow_url_fopen / include are set
     * separately via php_admin_value below.
     *
     * Kept deliberately conservative — plugins that need a banned
     * function should make a specific case in code review.
     */
    public const DISABLED_FUNCTIONS = [
        'exec', 'shell_exec', 'passthru', 'proc_open', 'popen', 'system',
        'pcntl_exec', 'assert', 'eval',
    ];

    private ?Logger $logger;
    private string $dispatcherTemplate;
    private string $pluginRoot;
    private PluginGatewayTokenService $tokenService;

    /** @var callable(string $action, array $payload): array{status:string, error?:string} */
    private $actionExecutor;

    public function __construct(
        ?Logger $logger = null,
        ?callable $actionExecutor = null,
        ?string $dispatcherTemplate = null,
        ?string $pluginRoot = null,
        ?PluginGatewayTokenService $tokenService = null
    ) {
        $this->logger = $logger;
        $this->dispatcherTemplate = $dispatcherTemplate ?? self::DEFAULT_DISPATCHER_TEMPLATE;
        $this->pluginRoot = rtrim($pluginRoot ?? self::PLUGIN_ROOT, '/');
        $this->tokenService = $tokenService
            ?? new PluginGatewayTokenService(null, $this->pluginRoot, $logger);
        $this->actionExecutor = $actionExecutor ?? function (string $action, array $payload): array {
            return $this->executeViaRequestFile($action, $payload);
        };
    }

    /**
     * Render the FPM pool configuration text for one plugin. Public so
     * tests can assert exact content without going through the supervisor.
     */
    public function renderPoolConfig(string $pluginId, string $systemUser): string
    {
        $this->validatePluginId($pluginId);
        $this->validateSystemUser($systemUser);

        $disabled = implode(',', self::DISABLED_FUNCTIONS);
        $basedir = self::PLUGIN_ROOT . '/' . $pluginId . '/:'
                 . self::SCRATCH_ROOT . '/' . $systemUser . '/:'
                 . '/tmp/';

        // pm.max_children kept low — plugins are slow-path observers,
        // not request-serving microservices. If a plugin proves to
        // need more concurrency, it can override via a manifest field
        // in a follow-up. request_terminate_timeout caps a runaway
        // plugin's request to 30s so it cannot wedge a worker forever.
        return <<<EOT
[eiou-plugin-{$pluginId}]
; Auto-generated by Eiou\\Services\\PluginPoolService. Do not edit by
; hand — PluginPoolService::ensurePool() overwrites this file on every
; plugin enable. Manual changes are lost on the next reload.

user = {$systemUser}
group = {$systemUser}
listen = /run/php/eiou-plugin-{$systemUser}.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 10s

request_terminate_timeout = 30s
request_slowlog_timeout = 5s
slowlog = /var/log/php-fpm-{$systemUser}-slow.log

php_admin_value[open_basedir]      = {$basedir}
php_admin_value[disable_functions] = {$disabled}
php_admin_value[allow_url_fopen]   = 0
php_admin_value[allow_url_include] = 0

EOT;
    }

    /**
     * Path the pool config lives at on disk. Stable function of the
     * plugin id so the supervisor and reconcile() can find each other.
     */
    public function poolPath(string $pluginId): string
    {
        $this->validatePluginId($pluginId);
        return self::poolDir() . '/eiou-plugin-' . $pluginId . '.conf';
    }

    /**
     * Apply a pool + nginx snippet for a sandboxed plugin.
     *
     * The caller passes:
     *   - $pluginId    — the plugin id whose pool is being applied
     *   - $systemUser  — the eiou-p-<hash> user (from PluginUserService)
     *   - $nginxSnippet — the FULL nginx routing snippet (covering EVERY
     *                     sandboxed plugin, not just this one) so the
     *                     supervisor can write it atomically without
     *                     needing to know who else is enabled.
     *
     * Returns true if the supervisor accepted the request and reloads
     * succeeded; false otherwise (including nginx -t failure). On false,
     * NO files were changed — the supervisor refuses to reload when its
     * config-test step fails.
     */
    /**
     * Check whether the on-disk state matches what applyPool would
     * produce. Used by reconcileSandbox to short-circuit the supervisor
     * round-trip when nothing has diverged — without this, every
     * Application::__construct re-applies every sandboxed plugin and
     * the supervisor log fills with "apply-pool complete" lines.
     *
     * Returns true iff all of these are present + current:
     *   - Pool config at poolPath() byte-matches the expected render
     *   - nginx snippet contains the plugin's location block
     *   - Token exists in the central tokens index
     *   - Dispatcher file is in the plugin's dir
     *
     * Scratch dir + per-plugin token file aren't checked here — they're
     * root-owned and www-data can't stat them (without traversal
     * permission), so we trust the supervisor's idempotent ensure
     * if we get as far as calling applyPool.
     */
    public function isPoolUpToDate(
        string $pluginId,
        string $systemUser,
        string $nginxSnippet
    ): bool {
        $this->validatePluginId($pluginId);
        $this->validateSystemUser($systemUser);

        // Pool config file must exist and match exactly. Mismatch
        // means either the supervisor never wrote it, or a manual
        // edit drifted from the rendered output — either way, apply.
        $poolPath = $this->poolPath($pluginId);
        if (!is_file($poolPath) || !is_readable($poolPath)) {
            return false;
        }
        $expected = $this->renderPoolConfig($pluginId, $systemUser);
        if ((string) @file_get_contents($poolPath) !== $expected) {
            return false;
        }

        // nginx snippet contains the plugin's location block (the
        // snippet is a concatenation; we just check substring inclusion
        // rather than byte-equality so a sibling-plugin's apply doesn't
        // invalidate this check).
        $snippetOnDisk = is_readable(self::NGINX_SNIPPET_PATH)
            ? (string) @file_get_contents(self::NGINX_SNIPPET_PATH)
            : '';
        if (strpos($snippetOnDisk, '/gui/plugin/' . $pluginId . '/') === false) {
            return false;
        }

        // Token must be registered in the central index (mode 600 www-data,
        // we can read it). Per-plugin token file is plugin-owned and not
        // readable; the index is authoritative for "has a token".
        if (!$this->tokenServiceHasToken($pluginId)) {
            return false;
        }

        // Dispatcher must be installed. If a plugin shipped its own
        // __dispatch.php, we're done. If we'd install the template,
        // it should already be there.
        $dispatcher = $this->pluginRoot . '/' . $pluginId . '/__dispatch.php';
        if (!is_file($dispatcher)) {
            return false;
        }

        return true;
    }

    /**
     * Read the central tokens index and check whether this plugin has
     * an entry. Cheap — file is small, owned by www-data so we can
     * just file_get_contents it.
     */
    private function tokenServiceHasToken(string $pluginId): bool
    {
        $path = \Eiou\Services\PluginGatewayTokenService::DEFAULT_TOKENS_PATH;
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (!is_array($decoded)) return false;
        foreach ($decoded as $token => $owner) {
            if ($owner === $pluginId) return true;
        }
        return false;
    }

    public function applyPool(
        string $pluginId,
        string $systemUser,
        string $nginxSnippet,
        bool $forceRotateToken = false
    ): bool {
        $this->validatePluginId($pluginId);
        $this->validateSystemUser($systemUser);

        // Install the Phase 3a dispatcher template into the plugin's
        // dir BEFORE asking the supervisor to bring the pool up. Once
        // FPM picks up the new pool, requests to it must land on a
        // valid __dispatch.php or they 404 — installing first means the
        // very first request after reload finds the file ready.
        //
        // www-data owns the plugin dir at this point so a direct PHP
        // write works without involving the supervisor. The file
        // becomes world-readable (mode 644) so the eiou-p-<hash> user
        // can read it via the open_basedir allow-list.
        if (!$this->installDispatcher($pluginId)) {
            return false;
        }

        // Phase 4: ensure a gateway token exists.
        //
        // Two callers, two behaviours:
        //
        //   reconcileSandbox → $forceRotateToken=false. Idempotent
        //     mint-if-missing. Reconcile fires on every Application
        //     bootstrap (every FPM worker spawn), so unconditional
        //     rotation would re-mint constantly and any plugin pool
        //     that cached the old token would lose its credential.
        //
        //   setEnabled(true) explicit toggle → $forceRotateToken=true.
        //     Operator-driven disable→enable cycle rotates the token
        //     so a previously-leaked token is invalidated. This is
        //     the security property the original design wanted.
        try {
            $tokenPath = $this->pluginRoot . '/' . $pluginId
                       . '/' . \Eiou\Services\PluginGatewayTokenService::PER_PLUGIN_TOKEN_FILENAME;
            if ($forceRotateToken || !is_file($tokenPath)) {
                $this->tokenService->rotate($pluginId);
            }
        } catch (Throwable $e) {
            $this->log('error', 'plugin_pool_token_mint_failed', [
                'plugin' => $pluginId, 'error' => $e->getMessage(),
            ]);
            return false;
        }

        $poolConfig = $this->renderPoolConfig($pluginId, $systemUser);

        $result = ($this->actionExecutor)('apply-pool', [
            'plugin_id'     => $pluginId,
            'system_user'   => $systemUser,
            'pool_path'     => $this->poolPath($pluginId),
            'pool_config'   => $poolConfig,
            'nginx_snippet' => $nginxSnippet,
        ]);

        $ok = ($result['status'] ?? '') === 'ok';
        $this->log($ok ? 'info' : 'warning', 'plugin_pool_apply', [
            'plugin' => $pluginId,
            'system_user' => $systemUser,
            'result' => $result,
        ]);
        return $ok;
    }

    /**
     * Copy the dispatcher template into the plugin's directory. Idempotent:
     * re-copies on every applyPool() call so an upgrade to the template
     * file in the image propagates to every enabled sandboxed plugin on
     * its next reconcile.
     *
     * Test seam: pass a custom $dispatcherTemplate to the constructor.
     * Returns false (and logs) if the template can't be read or the
     * target write fails — the caller treats this like any other
     * pool-apply failure.
     */
    /**
     * Compare the plugin's bundled dispatcher version against the
     * template's. Logs a deprecation warning when the plugin is
     * behind. Never overwrites — plugin authors own their __dispatch.
     *
     * Equal or higher: silent. Higher means the plugin author shipped
     * against a future core; their problem if/when we ship the
     * matching contract delta.
     */
    private function warnIfDispatcherStale(string $pluginId, string $targetPath): void
    {
        $bundled = $this->readDispatcherVersion($targetPath);
        $current = $this->readDispatcherVersion($this->dispatcherTemplate);
        if ($bundled === null || $current === null) {
            // One side doesn't declare a version. Pre-Phase-3a
            // dispatchers won't have the constant; treat as version 0
            // (oldest possible) and warn.
            $bundled ??= 0;
            $current ??= 0;
        }
        if ($bundled < $current) {
            $this->log('warning', 'plugin_dispatcher_version_stale', [
                'plugin' => $pluginId,
                'bundled_version' => $bundled,
                'current_version' => $current,
                'remediation' => 'Plugin author should refresh __dispatch.php from the latest template. '
                               . 'Until then, the plugin runs against an older wire contract; '
                               . 'behaviour may diverge from core.',
            ]);
        }
    }

    /**
     * Parse `const PLUGIN_DISPATCH_VERSION = N;` from a dispatcher
     * file without including it. Including would execute the file's
     * top-level side effects (json header, php://input read, etc.)
     * which is wrong for a passive version probe.
     *
     * @return int|null Returns the version if present, null if the
     *                  file doesn't declare one or can't be read.
     */
    private function readDispatcherVersion(string $path): ?int
    {
        if (!is_file($path)) return null;
        // Read just enough for the constant — it lives near the top of
        // the file by convention. 2 KiB covers the header docblock
        // plus the const line comfortably.
        $head = @file_get_contents($path, false, null, 0, 2048);
        if ($head === false) return null;
        if (preg_match('/const\s+PLUGIN_DISPATCH_VERSION\s*=\s*(\d+)\s*;/', $head, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function installDispatcher(string $pluginId): bool
    {
        $targetDir = $this->pluginRoot . '/' . $pluginId;
        if (!is_dir($targetDir)) {
            $this->log('error', 'plugin_pool_dispatcher_install_target_missing', [
                'plugin' => $pluginId,
                'target_dir' => $targetDir,
            ]);
            return false;
        }
        // If the plugin already ships its own __dispatch.php (as hello-eiou
        // does post-Phase-5), don't clobber it with the stub template.
        // Plugins migrating to sandboxed mode are expected to bundle a real
        // dispatcher that handles their declared events / filters /
        // renders. Only install the template when the plugin has nothing —
        // it gives operators a 501-returning placeholder rather than 404.
        $target = $targetDir . '/__dispatch.php';
        if (is_file($target)) {
            // Bundled dispatcher present — check version drift and warn
            // the operator if the plugin's dispatcher predates the
            // current wire contract. Operator can't fix this directly;
            // plugin author needs to refresh their dispatcher.
            $this->warnIfDispatcherStale($pluginId, $target);
            return true;
        }
        if (!is_file($this->dispatcherTemplate) || !is_readable($this->dispatcherTemplate)) {
            $this->log('error', 'plugin_pool_dispatcher_install_template_missing', [
                'plugin' => $pluginId,
                'template' => $this->dispatcherTemplate,
            ]);
            return false;
        }
        $content = @file_get_contents($this->dispatcherTemplate);
        if ($content === false) {
            $this->log('error', 'plugin_pool_dispatcher_install_read_failed', [
                'plugin' => $pluginId,
                'template' => $this->dispatcherTemplate,
            ]);
            return false;
        }
        // Atomic write — never let a request hit a half-written dispatcher.
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content) === false) {
            $this->log('error', 'plugin_pool_dispatcher_install_write_failed', [
                'plugin' => $pluginId,
                'tmp' => $tmp,
            ]);
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            $this->log('error', 'plugin_pool_dispatcher_install_rename_failed', [
                'plugin' => $pluginId,
                'target' => $target,
            ]);
            return false;
        }
        return true;
    }

    /**
     * Remove a plugin's pool and refresh the nginx snippet (which now
     * omits this plugin). Supervisor handles the file deletion + reload
     * the same way applyPool does.
     */
    public function dropPool(string $pluginId, string $nginxSnippet): bool
    {
        $this->validatePluginId($pluginId);

        $result = ($this->actionExecutor)('drop-pool', [
            'plugin_id'     => $pluginId,
            'pool_path'     => $this->poolPath($pluginId),
            'nginx_snippet' => $nginxSnippet,
        ]);

        $ok = ($result['status'] ?? '') === 'ok';
        $this->log($ok ? 'info' : 'warning', 'plugin_pool_drop', [
            'plugin' => $pluginId,
            'result' => $result,
        ]);

        // Phase 4: revoke the gateway token. Revoke even when the pool
        // drop reported failure — keeping a stale token alive while
        // the rest of the plugin is dismantled would be the worse
        // failure mode. Idempotent; no-ops cleanly.
        try {
            $this->tokenService->revoke($pluginId);
        } catch (Throwable $e) {
            $this->log('warning', 'plugin_pool_token_revoke_failed', [
                'plugin' => $pluginId, 'error' => $e->getMessage(),
            ]);
        }

        return $ok;
    }

    private function validatePluginId(string $pluginId): void
    {
        if (!preg_match(PluginUserService::PLUGIN_ID_PATTERN, $pluginId)) {
            throw new InvalidArgumentException("Invalid plugin id: '{$pluginId}'");
        }
    }

    private function validateSystemUser(string $systemUser): void
    {
        $pattern = '/^' . preg_quote(PluginUserService::SYSTEM_USER_PREFIX, '/')
                 . '[a-f0-9]{' . PluginUserService::HASH_HEX_LEN . '}$/';
        if (!preg_match($pattern, $systemUser)) {
            throw new InvalidArgumentException("Invalid system_user: '{$systemUser}'");
        }
    }

    /**
     * Default request-file executor. Synchronous RPC against the
     * supervisor's plugin_routing_poller in startup.sh.
     *
     * @return array{status:string, error?:string}
     */
    private function executeViaRequestFile(string $action, array $payload): array
    {
        if (!in_array($action, ['apply-pool', 'drop-pool'], true)) {
            return ['status' => 'failed', 'error' => "unknown action: {$action}"];
        }

        $reqId = bin2hex(random_bytes(8));
        $reqPath = "/tmp/eiou-routing-req-{$reqId}.json";
        $resPath = "/tmp/eiou-routing-res-{$reqId}.json";

        // JSON_UNESCAPED_SLASHES so the supervisor's grep-based parser
        // (fallback when jq is unavailable in the image) doesn't see
        // backslash-slashed paths it then misinterprets as a path-traversal
        // attempt. The shape stays valid JSON; jq + json_decode are
        // indifferent.
        $body = json_encode(
            array_merge($payload, ['ts' => time(), 'action' => $action]),
            JSON_UNESCAPED_SLASHES
        );
        if ($body === false) {
            return ['status' => 'failed', 'error' => 'encode failed'];
        }

        if (@file_put_contents($reqPath, $body) === false) {
            return ['status' => 'failed', 'error' => "could not write request"];
        }
        @chmod($reqPath, 0644);

        // Generous timeout — `nginx -t` plus two reloads can take a
        // few seconds under load. The supervisor's own internal
        // timeout protects against hangs.
        $deadline = time() + 15;
        while (time() < $deadline) {
            if (is_file($resPath)) {
                $raw = @file_get_contents($resPath);
                @unlink($resPath);
                @unlink($reqPath);
                if ($raw === false) {
                    return ['status' => 'failed', 'error' => 'unreadable result'];
                }
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    return ['status' => 'failed', 'error' => 'malformed result'];
                }
                return [
                    'status' => (string) ($decoded['status'] ?? 'failed'),
                    'error'  => isset($decoded['error']) ? (string) $decoded['error'] : '',
                ];
            }
            usleep(200000); // 200 ms
        }

        @unlink($reqPath);
        return ['status' => 'failed', 'error' => 'timed out waiting for supervisor'];
    }

    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger === null) return;
        $this->logger->{$level}($message, $context);
    }
}
