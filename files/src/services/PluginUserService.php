<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use InvalidArgumentException;
use RuntimeException;

/**
 * PluginUserService
 *
 * Manages the Linux system-user lifecycle for sandboxed plugins. Each
 * enabled plugin is paired with its own UID (`eiou-p-<8-hex>`) so that
 * when Phase 2 spins up a per-plugin PHP-FPM pool, the worker process
 * cannot read `/etc/eiou/config/.master.key` or `userconfig.json` — the
 * kernel blocks the read with EACCES, regardless of what `open_basedir`
 * or `disable_functions` the pool config sets.
 *
 * Operations:
 *
 *   ensureUser(pluginId)   — idempotent useradd
 *   dropUser(pluginId)     — idempotent userdel
 *   systemUsername(id)     — derive the system username from the plugin id
 *   userExists(systemUser) — check via posix_getpwnam
 *   reconcile(installed)   — self-heal against the on-disk plugin list
 *
 * useradd/userdel require root. PHP-FPM workers run as www-data and cannot
 * call them directly. The service writes a request marker into
 * /tmp/eiou-pluser-req-<id>.json and a root-side poller in startup.sh
 * picks it up, runs the command, and writes the result to
 * /tmp/eiou-pluser-res-<id>.json. www-data polls for the result with a
 * short timeout. Same one-way-privilege pattern as RestartRequestService.
 *
 * Phase 1 of plugin sandboxing — see docs/PLUGIN_SANDBOXING.md. This
 * service is foundational and NOT yet wired into PluginLoader's
 * enable/disable lifecycle; that comes in Phase 2 along with the FPM
 * pool generator.
 */
class PluginUserService
{
    /**
     * Plugin id regex. Same shape PluginInstallService enforces; pinned
     * here so a manifest that smuggled an unsafe id past install time
     * still can't make it into a system command.
     */
    public const PLUGIN_ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /**
     * System-username prefix. Kept short so the resulting username fits
     * inside Linux's LOGIN_NAME_MAX (32 chars on most systems) once the
     * 8-char hash is appended: "eiou-p-" (7) + hash (8) = 15 chars.
     */
    public const SYSTEM_USER_PREFIX = 'eiou-p-';

    /**
     * Length of the hex hash appended to SYSTEM_USER_PREFIX. 8 hex chars
     * = 32 bits. Collision risk against a few hundred plugins is
     * negligible (birthday bound ~65k). Re-evaluating only matters once
     * we approach that many distinct enabled plugins on a single node.
     */
    public const HASH_HEX_LEN = 8;

    /**
     * Synchronous-RPC settling time. The supervisor polls the request
     * directory at ~100 ms; doubling that gives the supervisor a clean
     * window to pick up the request, run useradd/userdel, and write the
     * result file before this side gives up. Failed RPCs return false
     * and the operator can retry; useradd is fast (<200 ms cold).
     */
    public const RESULT_TIMEOUT_SECONDS = 5;

    private const REQUEST_DIR = '/tmp';
    private const REQUEST_PREFIX = 'eiou-pluser-req-';
    private const RESULT_PREFIX = 'eiou-pluser-res-';

    private const ALLOWED_ACTIONS = ['create', 'remove'];

    private ?Logger $logger;

    /** @var callable(string $action, string $systemUser): array{status:string, error?:string} */
    private $actionExecutor;

    /** @var callable(string $systemUser): bool */
    private $userExistsCheck;

    /**
     * @param Logger|null $logger
     * @param callable|null $actionExecutor Test seam — accepts (action, systemUser),
     *        returns ['status' => 'ok'|'failed', 'error' => '...']. Defaults
     *        to the request-file protocol against the in-container supervisor.
     * @param callable|null $userExistsCheck Test seam for posix_getpwnam();
     *        returns true if the username exists on this system.
     */
    public function __construct(
        ?Logger $logger = null,
        ?callable $actionExecutor = null,
        ?callable $userExistsCheck = null
    ) {
        $this->logger = $logger;
        $this->actionExecutor = $actionExecutor ?? function (string $action, string $systemUser): array {
            return $this->executeViaRequestFile($action, $systemUser);
        };
        $this->userExistsCheck = $userExistsCheck ?? function (string $systemUser): bool {
            // posix_getpwnam returns false when the user doesn't exist. Cast
            // protects against environments where the posix extension is
            // missing — we'd rather fail safe ("doesn't exist") than throw.
            if (!function_exists('posix_getpwnam')) {
                return false;
            }
            return posix_getpwnam($systemUser) !== false;
        };
    }

    /**
     * Compute the deterministic system username for a plugin id. Stable
     * across boots so reconcile() can compare on-disk plugins to existing
     * system users without a side table.
     */
    public function systemUsername(string $pluginId): string
    {
        $this->validatePluginId($pluginId);
        $hash = substr(hash('sha256', $pluginId), 0, self::HASH_HEX_LEN);
        return self::SYSTEM_USER_PREFIX . $hash;
    }

    /**
     * True if the system user for a plugin id exists on this host.
     */
    public function userExists(string $pluginId): bool
    {
        return ($this->userExistsCheck)($this->systemUsername($pluginId));
    }

    /**
     * Ensure the system user for the given plugin exists.
     *
     * Idempotent — returns true if the user already exists; runs useradd
     * via the supervisor if not. Failures (the supervisor rejected the
     * request, the request timed out, etc.) return false; the caller
     * decides what to do (Phase 2 will refuse to enable the plugin).
     */
    public function ensureUser(string $pluginId): bool
    {
        $systemUser = $this->systemUsername($pluginId);
        if (($this->userExistsCheck)($systemUser)) {
            return true;
        }
        $result = ($this->actionExecutor)('create', $systemUser);
        $ok = ($result['status'] ?? '') === 'ok';
        $this->log($ok ? 'info' : 'warning', 'plugin_user_ensure', [
            'plugin' => $pluginId,
            'system_user' => $systemUser,
            'result' => $result,
        ]);
        return $ok;
    }

    /**
     * Remove the system user for the given plugin.
     *
     * Idempotent — returns true if the user is already absent; runs
     * userdel via the supervisor otherwise. The caller is responsible
     * for re-chowning any plugin-owned files first; this method does
     * NOT touch the filesystem.
     */
    public function dropUser(string $pluginId): bool
    {
        $systemUser = $this->systemUsername($pluginId);
        if (!($this->userExistsCheck)($systemUser)) {
            return true;
        }
        $result = ($this->actionExecutor)('remove', $systemUser);
        $ok = ($result['status'] ?? '') === 'ok';
        $this->log($ok ? 'info' : 'warning', 'plugin_user_drop', [
            'plugin' => $pluginId,
            'system_user' => $systemUser,
            'result' => $result,
        ]);
        return $ok;
    }

    /**
     * Self-heal against the on-disk plugin list.
     *
     * For each id in $installedPluginIds: ensure the system user exists.
     * For any eiou-p-* user on the system NOT corresponding to one of
     * those ids: drop it. Returns a structured report suitable for
     * logging or surfacing in the GUI.
     *
     * The "stranded user" pass exists for the same reason
     * PluginDbUserService has one — a partial-failure during uninstall
     * could leave a system user behind without a plugin, and the next
     * boot should clean it up rather than accumulate cruft.
     *
     * NOTE: this method does NOT enumerate /etc/passwd directly. The
     * caller passes in the list of system users currently present (via
     * `getent passwd | grep ^eiou-p-`), keeping the syscall surface
     * mockable. The supervisor side of the protocol exposes a third
     * action `list` for callers that want this list — Phase 2 plumbing.
     *
     * @param list<string> $installedPluginIds
     * @param list<string> $existingPluginUsers list of eiou-p-* usernames currently on the system
     * @return array{
     *     created: list<string>,
     *     dropped: list<string>,
     *     errors:  list<array{plugin_id?:string, system_user?:string, message:string}>
     * }
     */
    public function reconcile(array $installedPluginIds, array $existingPluginUsers): array
    {
        $report = ['created' => [], 'dropped' => [], 'errors' => []];

        // Pass 1: create users for plugins that need one.
        $expectedUsers = [];
        foreach ($installedPluginIds as $pluginId) {
            try {
                $this->validatePluginId($pluginId);
                $systemUser = $this->systemUsername($pluginId);
                $expectedUsers[$systemUser] = $pluginId;
                if (($this->userExistsCheck)($systemUser)) {
                    continue;
                }
                if ($this->ensureUser($pluginId)) {
                    $report['created'][] = $systemUser;
                } else {
                    $report['errors'][] = [
                        'plugin_id' => $pluginId,
                        'system_user' => $systemUser,
                        'message' => 'ensureUser returned false',
                    ];
                }
            } catch (InvalidArgumentException $e) {
                $report['errors'][] = [
                    'plugin_id' => $pluginId,
                    'message' => $e->getMessage(),
                ];
            }
        }

        // Pass 2: drop stranded eiou-p-* users.
        foreach ($existingPluginUsers as $existing) {
            if (!$this->looksLikePluginUsername($existing)) {
                // Defence-in-depth: refuse to act on anything outside the
                // eiou-p- prefix. Even if the caller passed us "root" by
                // accident, we will not touch it.
                continue;
            }
            if (array_key_exists($existing, $expectedUsers)) {
                continue;
            }
            // Need a synthetic plugin-id-shaped string so the call goes
            // through; we already know the username we want to drop, so
            // we use a thin "drop by username" path via the executor.
            $result = ($this->actionExecutor)('remove', $existing);
            if (($result['status'] ?? '') === 'ok') {
                $report['dropped'][] = $existing;
            } else {
                $report['errors'][] = [
                    'system_user' => $existing,
                    'message' => 'drop failed: ' . ($result['error'] ?? 'unknown'),
                ];
            }
        }

        return $report;
    }

    /**
     * Plugin-id validator. Same regex the rest of the plugin code uses,
     * applied here too since the id flows into a username and would
     * otherwise be in scope for an injection in the supervisor's shell
     * command. The supervisor also re-validates — defence in depth.
     */
    private function validatePluginId(string $pluginId): void
    {
        if (!preg_match(self::PLUGIN_ID_PATTERN, $pluginId)) {
            throw new InvalidArgumentException(
                "Invalid plugin id: '{$pluginId}'"
            );
        }
    }

    /**
     * Username pattern check used by reconcile() before it considers
     * dropping a username. Rejects anything that isn't `eiou-p-<hex>`,
     * which keeps the reconcile pass from ever touching a real system
     * account even if its input is corrupted.
     */
    private function looksLikePluginUsername(string $username): bool
    {
        return (bool) preg_match(
            '/^' . preg_quote(self::SYSTEM_USER_PREFIX, '/') . '[a-f0-9]{' . self::HASH_HEX_LEN . '}$/',
            $username
        );
    }

    /**
     * Default executor — file-based synchronous RPC against the
     * supervisor poller in startup.sh.
     *
     *   1. Generate a request id and write
     *      /tmp/eiou-pluser-req-<reqId>.json with {action, system_user}.
     *   2. Poll /tmp/eiou-pluser-res-<reqId>.json (~100 ms) until either
     *      the supervisor writes a result or the timeout elapses.
     *   3. Read the result, delete both files, return the parsed status.
     *
     * Errors at every step degrade to ['status' => 'failed'] with a
     * descriptive `error` field — never throw, the caller decides what
     * to do.
     *
     * @return array{status:string, error?:string}
     */
    private function executeViaRequestFile(string $action, string $systemUser): array
    {
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            return ['status' => 'failed', 'error' => "unknown action: {$action}"];
        }
        if (!$this->looksLikePluginUsername($systemUser)) {
            return ['status' => 'failed', 'error' => "refused unsafe system_user shape"];
        }

        $reqId = bin2hex(random_bytes(8));
        $reqPath = self::REQUEST_DIR . '/' . self::REQUEST_PREFIX . $reqId . '.json';
        $resPath = self::REQUEST_DIR . '/' . self::RESULT_PREFIX . $reqId . '.json';

        // JSON_UNESCAPED_SLASHES so the supervisor's grep-based parser
        // (fallback when jq is missing) sees the raw values it expects.
        $payload = json_encode([
            'ts'          => time(),
            'action'      => $action,
            'system_user' => $systemUser,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return ['status' => 'failed', 'error' => 'failed to encode request'];
        }

        // World-readable so the supervisor (root) can read what www-data
        // wrote. Contents are non-sensitive metadata.
        if (@file_put_contents($reqPath, $payload) === false) {
            return ['status' => 'failed', 'error' => "could not write request: {$reqPath}"];
        }
        @chmod($reqPath, 0644);

        $deadline = time() + self::RESULT_TIMEOUT_SECONDS;
        while (time() < $deadline) {
            if (is_file($resPath)) {
                $raw = @file_get_contents($resPath);
                @unlink($resPath);
                @unlink($reqPath);
                if ($raw === false) {
                    return ['status' => 'failed', 'error' => 'result file unreadable'];
                }
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    return ['status' => 'failed', 'error' => 'result file not JSON'];
                }
                return [
                    'status' => (string) ($decoded['status'] ?? 'failed'),
                    'error'  => isset($decoded['error']) ? (string) $decoded['error'] : '',
                ];
            }
            usleep(100000); // 100 ms — matches supervisor poll interval
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
