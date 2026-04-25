<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\DatabaseContext;
use Eiou\Utils\Logger;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Opens PDO connections authenticated as a plugin's MySQL user — the
 * isolated user created by `PluginDbUserService`. Plugins never receive
 * the root/app PDO; they go through `ServiceContainer::getPluginPdo()`
 * which delegates here.
 *
 * Connections are cached per-plugin for the lifetime of the factory
 * instance (which matches the lifetime of the HTTP request / CLI
 * invocation / daemon tick). Within a request, repeated calls return
 * the same PDO.
 *
 * Extracted into its own class (rather than living inside ServiceContainer)
 * so unit tests can mock the `open()` seam without spinning up a real
 * MySQL — integration of the real-connection path stays covered by the
 * existing Docker test suite which has MySQL available.
 *
 * See docs/PLUGINS.md (Database Isolation).
 */
class PluginPdoFactory
{
    /**
     * Same plugin-id pattern the rest of the isolation code enforces.
     * Defensive: a call site that forgot to validate would otherwise
     * be able to inject MySQL username fragments.
     */
    public const PLUGIN_ID_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/';

    /** @var array<string, PDO> Per-plugin PDO cache, keyed by plugin id */
    private array $cache = [];

    private PluginCredentialService $credentials;
    private ?Logger $logger;

    public function __construct(
        PluginCredentialService $credentials,
        ?Logger $logger = null
    ) {
        $this->credentials = $credentials;
        $this->logger = $logger;
    }

    /**
     * Return a PDO connection authenticated as this plugin's user.
     *
     * @throws InvalidArgumentException Plugin id fails validation
     * @throws RuntimeException Credentials missing, or connection failed
     */
    public function getFor(string $pluginId): PDO
    {
        $this->validatePluginId($pluginId);

        if (isset($this->cache[$pluginId])) {
            return $this->cache[$pluginId];
        }

        $plaintext = $this->credentials->getPlaintext($pluginId);
        if ($plaintext === null) {
            throw new RuntimeException(
                "No credentials stored for plugin '{$pluginId}'; the plugin must be enabled first"
            );
        }

        $username = 'plugin_' . str_replace('-', '_', $pluginId);
        $pdo = $this->open($username, $plaintext);
        $this->cache[$pluginId] = $pdo;
        return $pdo;
    }

    /**
     * True if the factory has already opened + cached a PDO for this
     * plugin in the current request. Useful for tests and for deciding
     * whether a rotate() call needs to purge the cache.
     */
    public function isCached(string $pluginId): bool
    {
        return isset($this->cache[$pluginId]);
    }

    /**
     * Drop the cached connection for one plugin (used after a credential
     * rotation so the next getFor() call reauthenticates with the new
     * password). Safe to call if nothing was cached.
     */
    public function purge(string $pluginId): void
    {
        unset($this->cache[$pluginId]);
    }

    /**
     * Open a fresh PDO connection against the eIOU MySQL database as the
     * given plugin user. Protected so tests can substitute an in-memory
     * stub — unit tests don't have MariaDB available.
     *
     * Uses the same host/database the root PDO uses (via DatabaseContext)
     * so plugin connections land on the same server the rest of the app
     * already talks to. Only the username + password differ.
     */
    protected function open(string $username, string $password): PDO
    {
        $ctx = DatabaseContext::getInstance();
        if (!$ctx || !$ctx->isInitialized()) {
            throw new RuntimeException(
                'DatabaseContext not initialized — cannot open plugin PDO'
            );
        }
        $dbHost = $ctx->getDbHost();
        $dbName = $ctx->getDbName();
        if (!$dbHost || !$dbName) {
            throw new RuntimeException('Missing DB host or name in DatabaseContext');
        }

        try {
            $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
            return new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        } catch (PDOException $e) {
            // Don't leak the password through the exception chain — the
            // PDOException message contains the full DSN + username, but
            // not the password. Still, re-wrap to a generic message.
            if ($this->logger !== null) {
                $this->logger->error('plugin_pdo_connect_failed', [
                    'username' => $username,
                    'error' => $e->getMessage(),
                ]);
            }
            throw new RuntimeException(
                "Plugin PDO connection failed: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function validatePluginId(string $pluginId): void
    {
        if (!preg_match(self::PLUGIN_ID_PATTERN, $pluginId)) {
            throw new InvalidArgumentException(
                "Invalid plugin id '{$pluginId}': must be kebab-case, 1-64 chars"
            );
        }
    }
}
