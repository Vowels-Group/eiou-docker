<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * PluginDbUserService
 *
 * Manages MySQL user lifecycle for plugin database isolation:
 *
 *   ensureUser()       — idempotent CREATE USER + IDENTIFIED BY (first
 *                        time, and again on every boot for self-healing)
 *   grant()            — idempotent GRANT on plugin_<id>_% with resource
 *                        caps from manifest db_limits
 *   revoke()           — REVOKE ALL PRIVILEGES (on plugin disable)
 *   dropUser()         — DROP USER (on uninstall, after tables are gone)
 *
 * Every operation runs as the root/app DB user because user-management
 * DDL needs CREATE USER / GRANT / REVOKE / DROP USER privileges which the
 * plugin users themselves will never hold.
 *
 * Host-binding is ALWAYS `localhost`. Never `%`. A plugin's user exists on
 * exactly one hostname so a network compromise can't reach it via remote
 * MySQL auth.
 *
 * See docs/PLUGIN_ISOLATION.md §2, §3, §5.
 */
class PluginDbUserService
{
    /**
     * Same plugin-id regex the rest of the plugin code uses. Applied
     * defensively here too — the identifier flows directly into the
     * MySQL username, and an unchecked value would let a malformed
     * manifest poison a GRANT statement.
     */
    public const PLUGIN_ID_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/';

    /**
     * Match the DDL max MAX_QUERIES-per-hour etc. to one scheme. Keys
     * here must match PluginLoader::DEFAULT_DB_LIMITS so a missing key
     * in the caller's array can be safely filled from defaults.
     */
    private const LIMIT_KEY_TO_SQL = [
        'max_queries_per_hour'     => 'MAX_QUERIES_PER_HOUR',
        'max_updates_per_hour'     => 'MAX_UPDATES_PER_HOUR',
        'max_connections_per_hour' => 'MAX_CONNECTIONS_PER_HOUR',
        'max_user_connections'     => 'MAX_USER_CONNECTIONS',
    ];

    /**
     * Privileges granted on the plugin's own namespace. Deliberately
     * excludes REFERENCES (so plugins cannot foreign-key into core
     * tables) and GRANT OPTION (so a plugin cannot grant its own
     * privileges to some other user it creates).
     */
    private const PLUGIN_PRIVILEGES = 'CREATE, ALTER, DROP, INDEX, SELECT, INSERT, UPDATE, DELETE';

    private PDO $rootPdo;
    private ?Logger $logger;

    public function __construct(PDO $rootPdo, ?Logger $logger = null)
    {
        $this->rootPdo = $rootPdo;
        $this->logger = $logger;
    }

    /**
     * Idempotently ensure a MySQL user exists for this plugin with the
     * given password and resource limits. Safe to call on every boot.
     *
     * The password is applied unconditionally (via the `CREATE USER ...
     * IDENTIFIED BY` path and/or an `ALTER USER IDENTIFIED BY` follow-up)
     * so a rotation done in the credential layer propagates to MySQL on
     * the next call even if the OS-level user row already exists.
     *
     * @param string $pluginId  Plugin id — matches the manifest `name`.
     * @param string $password  Plaintext password (comes from the
     *                          credential service; never logged).
     * @param array<string, int> $limits Map matching PluginLoader::DEFAULT_DB_LIMITS.
     *                          Missing keys fall back to the defaults in
     *                          PluginLoader; stray keys are ignored.
     */
    public function ensureUser(string $pluginId, string $password, array $limits): void
    {
        $this->validatePluginId($pluginId);
        $merged = $this->mergeLimitDefaults($limits);

        $username = $this->mysqlUsernameFor($pluginId);
        $limitClause = $this->buildLimitClause($merged);

        // CREATE USER IF NOT EXISTS is idempotent — a second call on an
        // existing row is a no-op (no WARNING raised for IF NOT EXISTS).
        // It does NOT update the password or limits of an existing row,
        // so we follow up with an unconditional ALTER USER — that path
        // IS the one that propagates rotation + limit changes.
        $quotedPw = $this->quoteStringLiteral($password);
        $create = sprintf(
            "CREATE USER IF NOT EXISTS %s IDENTIFIED BY %s WITH %s",
            $username,
            $quotedPw,
            $limitClause
        );
        $alter = sprintf(
            "ALTER USER %s IDENTIFIED BY %s WITH %s",
            $username,
            $quotedPw,
            $limitClause
        );

        $this->exec($create, 'create_user', $pluginId);
        $this->exec($alter, 'alter_user', $pluginId);

        $this->log('info', 'plugin_db_user_ensured', [
            'plugin_id' => $pluginId,
            'limits' => $merged,
        ]);
    }

    /**
     * Apply (or re-apply) the standard plugin GRANT on plugin_<id>_%.
     * Idempotent — re-running replaces whatever is there. Called on every
     * boot so operator-tuned limits in plugins.json take effect without
     * a manual ALTER USER.
     *
     * @param string $pluginId
     * @param list<string> $ownedTables Explicit table list from the manifest.
     *                                  Not used by the GRANT itself (the grant
     *                                  is on the prefix pattern), but passed
     *                                  so a future refinement can narrow the
     *                                  grant to specific tables if desired.
     */
    public function grant(string $pluginId, array $ownedTables = []): void
    {
        $this->validatePluginId($pluginId);

        $username = $this->mysqlUsernameFor($pluginId);
        $pattern = $this->grantPatternFor($pluginId);

        // The grant is a single statement; `_` in the pattern is a MySQL
        // LIKE metacharacter, but that's exactly what we want here —
        // any table starting with `plugin_<snake_id>_` matches.
        $sql = sprintf(
            "GRANT %s ON %s TO %s",
            self::PLUGIN_PRIVILEGES,
            $pattern,
            $username
        );
        $this->exec($sql, 'grant', $pluginId);

        $this->log('info', 'plugin_db_grant_applied', [
            'plugin_id' => $pluginId,
            'pattern' => $pattern,
            'owned_tables_declared' => count($ownedTables),
        ]);
    }

    /**
     * REVOKE ALL PRIVILEGES from the plugin user — used when the plugin
     * is disabled. The MySQL user stays in place (re-enable is a single
     * grant() call away), but until re-enabled every query from that
     * user errors out at the privilege check.
     *
     * Idempotent: revoking an already-empty grant raises a MySQL warning
     * but not an error, and we swallow warnings here.
     */
    public function revoke(string $pluginId): void
    {
        $this->validatePluginId($pluginId);

        $username = $this->mysqlUsernameFor($pluginId);
        $pattern = $this->grantPatternFor($pluginId);

        $sql = sprintf(
            "REVOKE ALL PRIVILEGES ON %s FROM %s",
            $pattern,
            $username
        );
        $this->exec($sql, 'revoke', $pluginId, /*tolerateErrors*/ true);

        $this->log('info', 'plugin_db_grant_revoked', [
            'plugin_id' => $pluginId,
            'pattern' => $pattern,
        ]);
    }

    /**
     * DROP USER IF EXISTS. Used at uninstall, after the plugin's tables
     * have been dropped. Idempotent.
     */
    public function dropUser(string $pluginId): void
    {
        $this->validatePluginId($pluginId);

        $username = $this->mysqlUsernameFor($pluginId);
        $sql = sprintf("DROP USER IF EXISTS %s", $username);
        $this->exec($sql, 'drop_user', $pluginId);

        $this->log('info', 'plugin_db_user_dropped', ['plugin_id' => $pluginId]);
    }

    /**
     * True if the MySQL user row exists. Cheap — used by the boot-time
     * reconciler to decide whether to run `CREATE USER` vs treat the row
     * as self-healing-reapply. Not used as a security boundary.
     */
    public function userExists(string $pluginId): bool
    {
        $this->validatePluginId($pluginId);
        $snake = $this->snakeCasePluginId($pluginId);
        $stmt = $this->rootPdo->prepare(
            "SELECT 1 FROM mysql.user WHERE User = :user AND Host = 'localhost' LIMIT 1"
        );
        $stmt->execute([':user' => 'plugin_' . $snake]);
        return $stmt->fetchColumn() !== false;
    }

    // =========================================================================
    // Identifier helpers
    // =========================================================================

    /**
     * Return the quoted MySQL username form: 'plugin_<snake_id>'@'localhost'.
     * Accepts only the pre-validated plugin id; throws on unexpected input as
     * defence-in-depth.
     */
    public function mysqlUsernameFor(string $pluginId): string
    {
        $this->validatePluginId($pluginId);
        $snake = $this->snakeCasePluginId($pluginId);
        // Since snake only contains [a-z0-9_] by construction (from kebab
        // alnum input), hand-quoting is safe. We still route through
        // quoteStringLiteral for defence-in-depth.
        return $this->quoteIdent('plugin_' . $snake) . "@" . $this->quoteStringLiteral('localhost');
    }

    /**
     * Plugin's owned-table grant pattern, e.g. `eiou`.`plugin_my_plugin_%`.
     * Database name is `eiou` — matches Constants / dbconfig. The backtick
     * quoting is safe since the identifier is derived from the validated
     * plugin id.
     */
    public function grantPatternFor(string $pluginId): string
    {
        $this->validatePluginId($pluginId);
        $snake = $this->snakeCasePluginId($pluginId);
        return "`eiou`.`plugin_{$snake}_%`";
    }

    /**
     * kebab-case → snake_case for use inside MySQL identifiers.
     */
    private function snakeCasePluginId(string $pluginId): string
    {
        return str_replace('-', '_', $pluginId);
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
     * Produce a MySQL string literal with backslash + quote escaping for
     * password values. The output is the quoted literal itself (`'...'`),
     * ready to interpolate into a CREATE USER / ALTER USER statement.
     *
     * Real-world passwords going through this path come from
     * PluginCredentialService::generate() — which emits base64 output, so
     * no special chars appear. But since the literal is interpolated
     * rather than bound (the DDL grammar doesn't accept placeholders for
     * IDENTIFIED BY), defence-in-depth escaping is still worth doing.
     */
    private function quoteStringLiteral(string $raw): string
    {
        // Quote per MySQL's standard string literal escaping. No NUL bytes,
        // no bare backslash without escape, no single quote without escape.
        $escaped = strtr($raw, [
            "\\" => "\\\\",
            "'"  => "\\'",
            "\0" => "\\0",
            "\n" => "\\n",
            "\r" => "\\r",
        ]);
        return "'" . $escaped . "'";
    }

    /**
     * Backtick-quote an identifier. Backticks inside the identifier itself
     * would break out of the quoting, so they're doubled up. Since our
     * identifiers are derived from the validated plugin id (which matches
     * `[a-z][a-z0-9-]*` converted to `[a-z][a-z0-9_]*`), no backticks ever
     * appear — but the defence is cheap.
     */
    private function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Merge the partial limits the caller passed with the core defaults
     * from PluginLoader. Unknown keys are dropped; missing keys fall back
     * to the default.
     *
     * @param array<string, mixed> $supplied
     * @return array<string, int>
     */
    private function mergeLimitDefaults(array $supplied): array
    {
        $merged = PluginLoader::DEFAULT_DB_LIMITS;
        foreach (array_keys(self::LIMIT_KEY_TO_SQL) as $key) {
            if (isset($supplied[$key]) && is_int($supplied[$key]) && $supplied[$key] > 0) {
                $merged[$key] = $supplied[$key];
            }
        }
        return $merged;
    }

    private function buildLimitClause(array $limits): string
    {
        $parts = [];
        foreach (self::LIMIT_KEY_TO_SQL as $key => $sqlKey) {
            $parts[] = sprintf('%s %d', $sqlKey, (int) $limits[$key]);
        }
        return implode(' ', $parts);
    }

    /**
     * Run a DDL statement, logging failures. `$tolerateErrors` is used by
     * REVOKE where running against an already-empty grant is a no-op but
     * MySQL may raise a non-fatal warning.
     */
    private function exec(string $sql, string $op, string $pluginId, bool $tolerateErrors = false): void
    {
        try {
            $this->rootPdo->exec($sql);
        } catch (PDOException $e) {
            if ($tolerateErrors) {
                $this->log('debug', 'plugin_db_ddl_tolerated', [
                    'plugin_id' => $pluginId,
                    'op' => $op,
                    'error' => $e->getMessage(),
                ]);
                return;
            }
            $this->log('error', 'plugin_db_ddl_failed', [
                'plugin_id' => $pluginId,
                'op' => $op,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                "Plugin DB {$op} failed for '{$pluginId}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function log(string $level, string $event, array $ctx = []): void
    {
        if ($this->logger === null) {
            return;
        }
        $this->logger->$level($event, $ctx);
    }
}
