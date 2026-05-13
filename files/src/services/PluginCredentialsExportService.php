<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\DatabaseContext;
use Eiou\Utils\Logger;
use InvalidArgumentException;

/**
 * PluginCredentialsExportService
 *
 * Writes per-plugin MySQL credentials to an on-disk file that operator-
 * deployed sibling containers can mount read-only. Solves the
 * cross-container-state problem for plugins that need a surface outside
 * the eIOU container (e.g. a customer-facing JSON-RPC listener owned by
 * the operator) while still using the plugin's isolated MySQL user.
 *
 * Without this export, plugin DB credentials live encrypted in the
 * `plugin_credentials` table and are only decryptable inside the wallet
 * pool. A sibling container that wants to share state with the plugin
 * has no way to authenticate as the plugin's MySQL user.
 *
 * File contract:
 *
 *   Path:   /etc/eiou/credentials/plugin-<id>.json
 *   Mode:   0640
 *   Owner:  root:www-data
 *   Body:   {
 *             "host":      string,    // as the wallet sees it; operator
 *                                     // may need to override for sibling
 *                                     // network reachability
 *             "port":      3306,
 *             "database":  string,
 *             "username":  "plugin_<snake_id>",
 *             "password":  string,    // plaintext, just-generated
 *             "issued_at": ISO-8601 UTC
 *           }
 *
 * Trust model:
 *
 *   - The wallet pool (www-data) can read every plugin's credential
 *     file. The wallet already has root-equivalent DB access, so this
 *     doesn't expand its blast radius.
 *
 *   - Plugin FPM pools (eiou-p-<hash> users) CANNOT read this file —
 *     they're not in the www-data group, and their open_basedir
 *     doesn't admit /etc/eiou/credentials/ anyway. So one plugin
 *     cannot read another plugin's credentials.
 *
 *   - Operator-deployed sibling containers mount a specific file
 *     (e.g. only `/etc/eiou/credentials/plugin-<my-id>.json`) into
 *     the sibling, with sibling-side UID arrangements that put the
 *     sibling process in the host's www-data group (or any equivalent
 *     mapping). The host's responsibility ends at "produce a 0640
 *     root:www-data file"; everything beyond is operator policy.
 *
 * Lifecycle integration (driven by PluginLoader):
 *
 *   - On plugin enable: export after credential generate/decrypt.
 *   - On plugin disable: revoke (delete file). Disable means "stop
 *     serving"; even though the MySQL REVOKE already locks the user
 *     out, removing the file is defence in depth + signals state.
 *   - On boot reconcile: re-export for every enabled plugin
 *     (self-heals after volume recreation, manual file delete, etc.).
 *   - On uninstall: revoke alongside credential row delete.
 *
 * Privileged file write goes through the supervisor request-file
 * protocol (same pattern as PluginUserService / PluginPoolService) —
 * PHP-FPM cannot write to /etc/eiou/* directly. The supervisor's
 * plugin_credentials_poller in startup.sh handles the actual write
 * with the correct owner, group, and mode.
 */
class PluginCredentialsExportService
{
    /** Where credential files live on disk. Hard-pinned in the
     *  supervisor's path validator too — don't change one without
     *  the other.
     */
    public const CREDENTIALS_DIR = '/etc/eiou/credentials';

    /** Synchronous-RPC settling time. File writes are fast; if the
     *  supervisor is wedged the operator wants to see that loudly
     *  rather than blocking enable/disable for tens of seconds.
     */
    public const RESULT_TIMEOUT_SECONDS = 5;

    private const REQUEST_DIR = '/tmp';
    private const REQUEST_PREFIX = 'eiou-creds-req-';
    private const RESULT_PREFIX = 'eiou-creds-res-';

    private const ALLOWED_ACTIONS = ['apply-credentials', 'drop-credentials'];

    /** Matches PluginCredentialService::PLUGIN_ID_PATTERN — duplicated
     *  here so this service doesn't import that class just to use a
     *  constant. The two must stay in sync; the supervisor also
     *  validates this shape independently as defence in depth.
     */
    public const PLUGIN_ID_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/';

    private ?Logger $logger;

    /** @var callable(string $action, array $payload): array{status:string, error?:string} */
    private $actionExecutor;

    /** @var callable():array{host:?string, name:?string} */
    private $databaseContextReader;

    /**
     * @param Logger|null $logger
     * @param callable|null $actionExecutor Test seam — accepts (action,
     *        payload), returns ['status' => 'ok'|'failed', 'error' => '...'].
     *        Defaults to the request-file protocol against the in-container
     *        supervisor.
     * @param callable|null $databaseContextReader Test seam — returns
     *        ['host' => ..., 'name' => ...]. Defaults to reading from
     *        the live DatabaseContext singleton.
     */
    public function __construct(
        ?Logger $logger = null,
        ?callable $actionExecutor = null,
        ?callable $databaseContextReader = null
    ) {
        $this->logger = $logger;
        $this->actionExecutor = $actionExecutor ?? function (string $action, array $payload): array {
            return $this->executeViaRequestFile($action, $payload);
        };
        $this->databaseContextReader = $databaseContextReader ?? function (): array {
            $ctx = DatabaseContext::getInstance();
            if ($ctx === null || !$ctx->isInitialized()) {
                return ['host' => null, 'name' => null];
            }
            return ['host' => $ctx->getDbHost(), 'name' => $ctx->getDbName()];
        };
    }

    /**
     * Absolute path the credentials file lives at for a given plugin.
     * Public so callers (uninstall paths, supervisor-side tests) can
     * reason about lifecycle without re-deriving the path.
     */
    public function credentialsPath(string $pluginId): string
    {
        $this->validatePluginId($pluginId);
        return self::CREDENTIALS_DIR . '/plugin-' . $pluginId . '.json';
    }

    /**
     * Write the credentials file for a plugin. Idempotent — re-running
     * with the same plaintext overwrites the file atomically, which is
     * the path the boot reconciler relies on.
     *
     * Returns true on supervisor success. False on validation failure,
     * encode failure, or supervisor failure — caller logs the upstream
     * context. Never throws.
     */
    public function export(string $pluginId, string $plaintextPassword): bool
    {
        try {
            $this->validatePluginId($pluginId);
        } catch (InvalidArgumentException $e) {
            $this->log('warning', 'plugin_credentials_export_invalid_id', [
                'plugin_id' => $pluginId, 'error' => $e->getMessage(),
            ]);
            return false;
        }
        if ($plaintextPassword === '') {
            $this->log('warning', 'plugin_credentials_export_empty_password', [
                'plugin_id' => $pluginId,
            ]);
            return false;
        }

        $ctx = ($this->databaseContextReader)();
        $dbHost = is_string($ctx['host'] ?? null) && $ctx['host'] !== '' ? $ctx['host'] : '127.0.0.1';
        $dbName = is_string($ctx['name'] ?? null) && $ctx['name'] !== '' ? $ctx['name'] : null;
        if ($dbName === null) {
            $this->log('warning', 'plugin_credentials_export_missing_db_name', [
                'plugin_id' => $pluginId,
            ]);
            return false;
        }

        $body = [
            'host'      => $dbHost,
            'port'      => 3306,
            'database'  => $dbName,
            'username'  => 'plugin_' . str_replace('-', '_', $pluginId),
            'password'  => $plaintextPassword,
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $this->log('error', 'plugin_credentials_export_encode_failed', [
                'plugin_id' => $pluginId,
            ]);
            return false;
        }

        $result = ($this->actionExecutor)('apply-credentials', [
            'plugin_id'   => $pluginId,
            'target_path' => $this->credentialsPath($pluginId),
            'body'        => $encoded,
        ]);
        $ok = ($result['status'] ?? '') === 'ok';
        $this->log($ok ? 'info' : 'warning', 'plugin_credentials_export', [
            'plugin_id' => $pluginId,
            'status'    => $result['status'] ?? 'failed',
            'error'     => $result['error'] ?? '',
        ]);
        return $ok;
    }

    /**
     * Remove the credentials file for a plugin. Idempotent — removing
     * a file that doesn't exist returns true. Called on plugin disable
     * and on uninstall.
     */
    public function revoke(string $pluginId): bool
    {
        try {
            $this->validatePluginId($pluginId);
        } catch (InvalidArgumentException $e) {
            $this->log('warning', 'plugin_credentials_revoke_invalid_id', [
                'plugin_id' => $pluginId, 'error' => $e->getMessage(),
            ]);
            return false;
        }

        $result = ($this->actionExecutor)('drop-credentials', [
            'plugin_id'   => $pluginId,
            'target_path' => $this->credentialsPath($pluginId),
        ]);
        $ok = ($result['status'] ?? '') === 'ok';
        $this->log($ok ? 'info' : 'warning', 'plugin_credentials_revoke', [
            'plugin_id' => $pluginId,
            'status'    => $result['status'] ?? 'failed',
            'error'     => $result['error'] ?? '',
        ]);
        return $ok;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function validatePluginId(string $pluginId): void
    {
        if (!preg_match(self::PLUGIN_ID_PATTERN, $pluginId)) {
            throw new InvalidArgumentException(
                "Invalid plugin id '{$pluginId}': must be kebab-case, 1-64 chars"
            );
        }
    }

    /**
     * Default request-file executor. Synchronous RPC against the
     * supervisor's plugin_credentials_poller in startup.sh. Mirrors
     * PluginPoolService::executeViaRequestFile but writes the request
     * with mode 0600 (the body carries a plaintext password).
     *
     * @return array{status:string, error?:string}
     */
    private function executeViaRequestFile(string $action, array $payload): array
    {
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            return ['status' => 'failed', 'error' => "unknown action: {$action}"];
        }

        $reqId = bin2hex(random_bytes(8));
        $reqPath = self::REQUEST_DIR . '/' . self::REQUEST_PREFIX . $reqId . '.json';
        $resPath = self::REQUEST_DIR . '/' . self::RESULT_PREFIX . $reqId . '.json';

        $envelope = json_encode(
            array_merge($payload, ['ts' => time(), 'action' => $action]),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($envelope === false) {
            return ['status' => 'failed', 'error' => 'encode failed'];
        }

        // Create with restrictive mode BEFORE writing the body, so the
        // plaintext password never lives on disk under a wider mode
        // even momentarily. touch + chmod + write, in that order.
        if (@touch($reqPath) === false) {
            return ['status' => 'failed', 'error' => "could not create request file"];
        }
        @chmod($reqPath, 0600);
        if (@file_put_contents($reqPath, $envelope) === false) {
            @unlink($reqPath);
            return ['status' => 'failed', 'error' => "could not write request"];
        }

        $deadline = time() + self::RESULT_TIMEOUT_SECONDS;
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
