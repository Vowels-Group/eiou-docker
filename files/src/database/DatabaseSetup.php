<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Utils\Logger;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Defense-in-depth identifier guard for the SHOW / ALTER DDL string-concat
 * sites in this file.
 *
 * Background. The migration runner can't use PDO placeholders for table /
 * column / index names (MySQL forbids it for DDL), so it interpolates
 * directly into the SQL. Today the interpolated values come exclusively
 * from hardcoded migration arrays at the top of each function — operator-
 * vetted code, never user input — and the AUDIT_SECURITY pass classified
 * the apparent SQLi as informational-only for that reason.
 *
 * This guard makes the "no user input" property structural rather than
 * a code-review-only invariant: every identifier must match
 * `[A-Za-z_][A-Za-z0-9_]*` (MySQL's unquoted-identifier shape) and stay
 * within MySQL's 64-char limit. If a future code change ever lets an
 * external value reach one of those arrays, the migration aborts with
 * a loud `InvalidArgumentException` instead of silently executing
 * attacker-shaped DDL.
 *
 * @throws InvalidArgumentException when the identifier fails the shape check
 */
function assertSafeMigrationIdentifier(string $identifier, string $context): void
{
    if ($identifier === '' || strlen($identifier) > 64) {
        throw new InvalidArgumentException(
            "migration identifier ({$context}) length out of range: '{$identifier}'"
        );
    }
    if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $identifier)) {
        throw new InvalidArgumentException(
            "migration identifier ({$context}) contains disallowed characters: '{$identifier}'"
        );
    }
}

function freshInstall(){
    // Skip database setup in test mode
    if (defined('EIOU_TEST_MODE') && EIOU_TEST_MODE === true) {
        return;
    }

    // Check if the configuration file exists
    if (!file_exists('/etc/eiou/config/dbconfig.json')) {
        // Create the directory if it doesn't exist
        if (!file_exists('/etc/eiou')) {
            mkdir('/etc/eiou', 0755, true);
        }
        if (!file_exists('/etc/eiou/config')) {
            mkdir('/etc/eiou/config', 0755, true);
        }
        
        // Create a default configuration file
        $dbConfig = [];
       
        // Create MySQL user, database, and tables
        $dbHost = 'localhost';
        $dbRootUser = 'root';
        $dbRootPass = ''; // You may want to prompt for this or use a secure method

        // Connect as root to create database and user
        try {
            $rootConn = new PDO("mysql:host=$dbHost;unix_socket=/var/run/mysqld/mysqld.sock", $dbRootUser, $dbRootPass);         
            $rootConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Generate random database and username
            $dbName = 'eiou';
            $dbUser = 'eiou_user_' . bin2hex(random_bytes(8));
            $dbPass = bin2hex(random_bytes(16));

            // SECURITY NOTE (L-6): DDL statements (CREATE DATABASE, ALTER TABLE, SHOW COLUMNS)
            // cannot use PDO prepared statement placeholders. Values here are hardcoded in code,
            // not from user input, so direct interpolation is acceptable.
            $rootConn->exec("CREATE DATABASE IF NOT EXISTS `$dbName`");

            // Create user with limited privileges (if not exists)
            // Note: If user already exists, this will fail but that's OK - we'll catch it
            //
            // GRANT OPTION on eiou.* and CREATE USER ON *.* are required by the
            // plugin isolation feature: PluginDbUserService runs as this app
            // user to CREATE plugin_<id> users and GRANT them per-table
            // privileges on eiou.plugin_<id>_* tables. Without these two
            // privileges, every plugin enable fails with MySQL error 1227
            // (Access denied; you need the CREATE USER privilege).
            try {
                $rootConn->exec("CREATE USER '$dbUser'@'$dbHost' IDENTIFIED BY '$dbPass'");
                $rootConn->exec("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'$dbHost' WITH GRANT OPTION");
                $rootConn->exec("GRANT CREATE USER ON *.* TO '$dbUser'@'$dbHost'");
                $rootConn->exec("FLUSH PRIVILEGES");
            } catch (PDOException $userExists) {
                // User might already exist - try to use existing credentials
                // This happens if database exists but dbconfig.json was deleted
                Logger::getInstance()->warning("Database user creation failed (user might already exist)", [
                    'error' => $userExists->getMessage()
                ]);
                throw new RuntimeException(
                    "Database exists but cannot create user. Please restore dbconfig.json or drop the existing database.",
                    500,
                    $userExists
                );
            }

            // Connect to new database and create tables
            $dbConn = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
            $dbConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Create tables (wrapped in try-catch for better error handling)
            try {
                // Contacts & Network
                $dbConn->exec(getContactsTableSchema());
                $dbConn->exec(getAddressTableSchema());
                $dbConn->exec(getContactCreditTableSchema());
                $dbConn->exec(getContactCurrenciesTableSchema());
                $dbConn->exec(getBalancesTableSchema());

                // Transactions & Chain Integrity
                $dbConn->exec(getTransactionsTableSchema());
                $dbConn->exec(getTransactionsArchiveTableSchema());
                $dbConn->exec(getTransactionChainCheckpointsTableSchema());
                $dbConn->exec(getHeldTransactionsTableSchema());
                $dbConn->exec(getChainDropProposalsTableSchema());
                $dbConn->exec(getRememberTokensTableSchema());

                // P2P Routing
                $dbConn->exec(getP2pTableSchema());
                $dbConn->exec(getRp2pTableSchema());
                $dbConn->exec(getRp2pCandidatesTableSchema());
                $dbConn->exec(getP2pSendersTableSchema());
                $dbConn->exec(getP2pRelayedContactsTableSchema());

                // Capacity Reservations & Route Cancellations
                $dbConn->exec(getCapacityReservationsTableSchema());
                $dbConn->exec(getRouteCancellationsTableSchema());

                // Payment Requests
                $dbConn->exec(getPaymentRequestsTableSchema());
                $dbConn->exec(getPaymentRequestsArchiveTableSchema());

                // Message Delivery
                $dbConn->exec(getMessageDeliveryTableSchema());
                $dbConn->exec(getDeadLetterQueueTableSchema());
                $dbConn->exec(getDeliveryMetricsTableSchema());

                // API
                $dbConn->exec(getApiKeysTableSchema());
                $dbConn->exec(getApiRequestLogTableSchema());
                $dbConn->exec(getApiNoncesTableSchema());

                // Payback Methods (profile of settlement methods + v2 received cache)
                $dbConn->exec(getPaybackMethodsTableSchema());
                $dbConn->exec(getPaybackMethodsReceivedTableSchema());

                // Plugin isolation — per-plugin MySQL-user credentials (encrypted)
                $dbConn->exec(getPluginCredentialsTableSchema());

                // System & Security
                $dbConn->exec(getDebugTableSchema());
                $dbConn->exec(getRateLimitsTableSchema());
            } catch (PDOException $tableError) {
                Logger::getInstance()->error("Table creation failed", [
                    'error' => $tableError->getMessage()
                ]);
                throw new RuntimeException(
                    "Failed to create database tables: " . $tableError->getMessage(),
                    500,
                    $tableError
                );
            }

            // Write database configuration with plaintext password initially.
            // The password will be encrypted on the next Application boot via
            // migrateDbConfigEncryption() — this avoids master-key timing issues
            // during fresh install where the key may not yet be stable.
            $dbConfig = [
                'dbHost' => addslashes($dbHost),
                'dbName' => addslashes($dbName),
                'dbUser' => addslashes($dbUser),
                'dbPass' => addslashes($dbPass),
            ];


        } catch (PDOException $e) {
            // Handle database error
            Logger::getInstance()->logException($e, [], 'ERROR');

            // Throw exception to let ErrorHandler handle it
            throw new \RuntimeException(
                'Database setup failed. Please check error log for details.',
                500,
                $e
            );
        }

        // Write the default configuration with restrictive permissions
        if($dbConfig !== []){
            $configPath = '/etc/eiou/config/dbconfig.json';
            $oldUmask = umask(0027);
            file_put_contents($configPath, json_encode($dbConfig), LOCK_EX);
            umask($oldUmask);
            chmod($configPath, 0640);
            chgrp($configPath, 'www-data');
        }

        // Mark schema as current — fresh installs have all tables, no migrations needed
        file_put_contents('/etc/eiou/config/.schema_version', (string) \Eiou\Core\Constants::SCHEMA_VERSION);
    }
}

/**
 * Run database migrations to add new tables to existing databases
 *
 * Migrations only run when the stored schema version (in /etc/eiou/config/.schema_version)
 * is behind Constants::SCHEMA_VERSION. After successful completion the version file is
 * updated so subsequent requests skip all migration queries entirely.
 *
 * To add a new migration:
 *   1. Add your migration code to this function or runColumnMigrations()
 *   2. Bump Constants::SCHEMA_VERSION in src/core/Constants.php
 *
 * @param PDO $pdo Database connection
 * @return array Migration results (empty if skipped)
 */
function runMigrations(PDO $pdo): array {

    $schemaVersion = \Eiou\Core\Constants::SCHEMA_VERSION;
    $versionFile = '/etc/eiou/config/.schema_version';
    $currentVersion = file_exists($versionFile) ? (int) trim(file_get_contents($versionFile)) : 0;

    if ($currentVersion >= $schemaVersion) {
        return ['_status' => 'up_to_date'];
    }

    // Serialize concurrent migrations across PIDs with a MySQL advisory
    // lock. Without this, multiple workers booting simultaneously each see
    // the old version, all run table/column/index migrations in parallel,
    // and the losers crash on 1061 "Duplicate key name" / 1050 "Table
    // already exists". This is pre-ServiceContainer territory so we issue
    // GET_LOCK directly instead of going through DatabaseLockingService.
    $lockName = 'eiou_schema_migration';
    $lockTimeout = 60;
    $lockAcquired = false;
    try {
        $stmt = $pdo->prepare("SELECT GET_LOCK(:name, :timeout) AS acquired");
        $stmt->execute(['name' => $lockName, 'timeout' => $lockTimeout]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $acquired = $row['acquired'] ?? null;
        $lockAcquired = ($acquired === 1 || $acquired === '1');
    } catch (PDOException $e) {
        if (class_exists('Eiou\\Utils\\Logger')) {
            Logger::getInstance()->error('Schema migration lock acquisition failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    if (!$lockAcquired) {
        // Another PID may have completed migrations while we waited; if
        // the version file is now current, treat that as success.
        $currentVersion = file_exists($versionFile) ? (int) trim(file_get_contents($versionFile)) : 0;
        if ($currentVersion >= $schemaVersion) {
            return ['_status' => 'up_to_date'];
        }
        return ['_status' => 'error: could not acquire schema migration lock'];
    }

    try {
        // Re-read inside the lock — the holder before us may have just
        // bumped the version, in which case we have nothing to do.
        $currentVersion = file_exists($versionFile) ? (int) trim(file_get_contents($versionFile)) : 0;
        if ($currentVersion >= $schemaVersion) {
            return ['_status' => 'up_to_date'];
        }

        $results = [];

        // List of migration tables to create (added after initial release)
        // Use fully-qualified names since dynamic calls don't use namespace resolution
        $migrations = [
            'payment_requests'               => 'Eiou\Database\getPaymentRequestsTableSchema',
            'payment_requests_archive'       => 'Eiou\Database\getPaymentRequestsArchiveTableSchema',
            'remember_tokens'                => 'Eiou\Database\getRememberTokensTableSchema',
            'transactions_archive'           => 'Eiou\Database\getTransactionsArchiveTableSchema',
            'transaction_chain_checkpoints'  => 'Eiou\Database\getTransactionChainCheckpointsTableSchema',
            'payback_methods'                => 'Eiou\Database\getPaybackMethodsTableSchema',
            'payback_methods_received'       => 'Eiou\Database\getPaybackMethodsReceivedTableSchema',
            'plugin_credentials'             => 'Eiou\Database\getPluginCredentialsTableSchema',
        ];

        foreach ($migrations as $tableName => $schemaFunction) {
            try {
                assertSafeMigrationIdentifier($tableName, 'migrations.tableName');
                // Check if table exists
                $stmt = $pdo->query("SHOW TABLES LIKE '$tableName'");
                if ($stmt->rowCount() === 0) {
                    // Table doesn't exist, create it
                    $pdo->exec($schemaFunction());
                    $results[$tableName] = 'created';
                } else {
                    $results[$tableName] = 'exists';
                }
            } catch (PDOException $e) {
                $results[$tableName] = 'error: ' . $e->getMessage();
                if (class_exists('Eiou\\Utils\\Logger')) {
                    Logger::getInstance()->error("Migration failed for table $tableName", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // Run column migrations
        $columnResults = runColumnMigrations($pdo);
        $results = array_merge($results, $columnResults);

        // Check if any migration errored — only update version if all succeeded
        $hasErrors = false;
        foreach ($results as $status) {
            if (is_string($status) && strpos($status, 'error:') === 0) {
                $hasErrors = true;
                break;
            }
        }

        if (!$hasErrors) {
            file_put_contents($versionFile, (string) $schemaVersion);
        }

        return $results;
    } finally {
        try {
            $rs = $pdo->prepare("SELECT RELEASE_LOCK(:name)");
            $rs->execute(['name' => $lockName]);
        } catch (PDOException $e) {
            if (class_exists('Eiou\\Utils\\Logger')) {
                Logger::getInstance()->warning('Schema migration lock release failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

/**
 * Run column migrations for existing tables
 * This function is idempotent - safe to run multiple times
 *
 * @param PDO $pdo Database connection
 * @return array Migration results
 */
function runColumnMigrations(PDO $pdo): array {
    $results = [];

    // List of columns to ADD: [tableName => [columnName => columnDefinition]]
    $columnsToAdd = [
        'contacts' => [
            'remote_version' => "VARCHAR(32) DEFAULT NULL COMMENT 'Remote node self-reported APP_VERSION' AFTER valid_chain",
        ],
    ];

    // List of columns to DROP: [tableName => [columnName, ...]]
    $columnsToDrop = [
        'contacts' => ['currency', 'fee_percent', 'credit_limit'],
    ];

    // Add new columns
    foreach ($columnsToAdd as $tableName => $columns) {
        foreach ($columns as $columnName => $columnDefinition) {
            try {
                assertSafeMigrationIdentifier($tableName, 'columnsToAdd.tableName');
                assertSafeMigrationIdentifier($columnName, 'columnsToAdd.columnName');
                // Use query() instead of prepare() - SHOW COLUMNS doesn't support placeholders in MariaDB
                // Column names come from our own code, not user input, so direct interpolation is safe
                $stmt = $pdo->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");

                if ($stmt->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE `$tableName` ADD COLUMN `$columnName` $columnDefinition");
                    $results["{$tableName}.{$columnName}"] = 'added';
                } else {
                    $results["{$tableName}.{$columnName}"] = 'exists';
                }
            } catch (PDOException $e) {
                $results["{$tableName}.{$columnName}"] = 'error: ' . $e->getMessage();
                if (class_exists('Eiou\\Utils\\Logger')) {
                    Logger::getInstance()->error("Column migration failed for {$tableName}.{$columnName}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    // Drop deprecated columns
    foreach ($columnsToDrop as $tableName => $columns) {
        foreach ($columns as $columnName) {
            try {
                assertSafeMigrationIdentifier($tableName, 'columnsToDrop.tableName');
                assertSafeMigrationIdentifier($columnName, 'columnsToDrop.columnName');
                // Use query() instead of prepare() - SHOW COLUMNS doesn't support placeholders in MariaDB
                $stmt = $pdo->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");

                if ($stmt->rowCount() > 0) {
                    $pdo->exec("ALTER TABLE `$tableName` DROP COLUMN `$columnName`");
                    $results["{$tableName}.{$columnName}"] = 'dropped';
                } else {
                    $results["{$tableName}.{$columnName}"] = 'already_dropped';
                }
            } catch (PDOException $e) {
                $results["{$tableName}.{$columnName}"] = 'error: ' . $e->getMessage();
                if (class_exists('Eiou\\Utils\\Logger')) {
                    Logger::getInstance()->error("Column drop failed for {$tableName}.{$columnName}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    // Update ENUM columns to add new values
    $enumUpdates = [
        'message_delivery' => [
            'message_type' => "ENUM('transaction', 'p2p', 'rp2p', 'contact', 'payment_request', 'payback_method', 'route_cancel') NOT NULL",
        ],
        'dead_letter_queue' => [
            'message_type' => "ENUM('transaction', 'p2p', 'rp2p', 'contact', 'payment_request', 'payback_method', 'route_cancel') NOT NULL",
        ],
        'delivery_metrics' => [
            'message_type' => "ENUM('transaction', 'p2p', 'rp2p', 'contact', 'all', 'payment_request', 'payback_method', 'route_cancel') NOT NULL",
        ],
    ];

    foreach ($enumUpdates as $tableName => $columns) {
        foreach ($columns as $columnName => $newEnumDef) {
            try {
                assertSafeMigrationIdentifier($tableName, 'enumUpdates.tableName');
                assertSafeMigrationIdentifier($columnName, 'enumUpdates.columnName');
                // Check current ENUM values
                $stmt = $pdo->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
                $columnInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($columnInfo) {
                    $currentType = $columnInfo['Type'];
                    // Check if new ENUM values are already present by comparing definitions
                    // Extract values from new definition to check against current type
                    preg_match_all("/'/", $newEnumDef, $newMatches);
                    $needsUpdate = false;
                    preg_match_all("/'([^']+)'/", $newEnumDef, $newValues);
                    foreach ($newValues[1] ?? [] as $val) {
                        if (strpos($currentType, "'$val'") === false) {
                            $needsUpdate = true;
                            break;
                        }
                    }
                    if ($needsUpdate) {
                        $pdo->exec("ALTER TABLE `$tableName` MODIFY COLUMN `$columnName` $newEnumDef");
                        $results["{$tableName}.{$columnName}_enum"] = 'updated';
                    } else {
                        $results["{$tableName}.{$columnName}_enum"] = 'already_updated';
                    }
                }
            } catch (PDOException $e) {
                $results["{$tableName}.{$columnName}_enum"] = 'error: ' . $e->getMessage();
                if (class_exists('Eiou\\Utils\\Logger')) {
                    Logger::getInstance()->error("ENUM update failed for {$tableName}.{$columnName}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }


    // Column type changes: tighten loose types on existing tables.
    // Pattern: [tableName => [columnName => ['from' => regex, 'to' => new MySQL definition]]]
    // The `from` regex matches against `SHOW COLUMNS` "Type"; only run
    // the MODIFY if the current type still matches. Idempotent.
    $columnTypeChanges = [
        // addresses.pubkey_hash and balances.pubkey_hash were TEXT but
        // every value is a SHA-256 hex digest (exactly 64 chars). The
        // contacts / contact_credit / contact_currencies tables already
        // store pubkey_hash as VARCHAR(64). Aligning the two TEXT
        // columns avoids implicit type coercion on the heavily-used
        // `addresses a JOIN contacts c ON a.pubkey_hash = c.pubkey_hash`
        // and lets the existing pubkey indexes work as full-key
        // equality indexes instead of TEXT prefix indexes.
        'addresses' => [
            'pubkey_hash' => ['from' => '/^text$/i', 'to' => 'VARCHAR(64) NOT NULL'],
        ],
        'balances' => [
            'pubkey_hash' => ['from' => '/^text$/i', 'to' => 'VARCHAR(64) NOT NULL'],
        ],
    ];

    foreach ($columnTypeChanges as $tableName => $columns) {
        foreach ($columns as $columnName => $spec) {
            try {
                assertSafeMigrationIdentifier($tableName, 'columnTypeChanges.tableName');
                assertSafeMigrationIdentifier($columnName, 'columnTypeChanges.columnName');
                $stmt = $pdo->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
                $columnInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$columnInfo) {
                    $results["{$tableName}.{$columnName}_type"] = 'missing_column';
                    continue;
                }

                if (!preg_match($spec['from'], $columnInfo['Type'])) {
                    $results["{$tableName}.{$columnName}_type"] = 'already_updated';
                    continue;
                }

                // Defensive guard: refuse to truncate. If any existing
                // value is longer than the target VARCHAR length, log
                // and skip — operator can investigate (this should
                // never happen for SHA-256 hashes but the cost of the
                // check is one cheap query).
                if (preg_match('/VARCHAR\((\d+)\)/i', $spec['to'], $m)) {
                    $maxLen = (int) $m[1];
                    $check = $pdo->query(
                        "SELECT MAX(CHAR_LENGTH(`$columnName`)) AS max_len FROM `$tableName`"
                    );
                    $row = $check->fetch(PDO::FETCH_ASSOC);
                    $observedMax = (int) ($row['max_len'] ?? 0);
                    if ($observedMax > $maxLen) {
                        $results["{$tableName}.{$columnName}_type"] =
                            "skipped: existing value length $observedMax exceeds target $maxLen";
                        if (class_exists('Eiou\\Utils\\Logger')) {
                            Logger::getInstance()->error(
                                "Column type migration skipped (would truncate)",
                                [
                                    'table' => $tableName,
                                    'column' => $columnName,
                                    'observed_max_length' => $observedMax,
                                    'target_definition' => $spec['to'],
                                ]
                            );
                        }
                        continue;
                    }
                }

                $pdo->exec("ALTER TABLE `$tableName` MODIFY COLUMN `$columnName` {$spec['to']}");
                $results["{$tableName}.{$columnName}_type"] = 'updated';
            } catch (PDOException $e) {
                $results["{$tableName}.{$columnName}_type"] = 'error: ' . $e->getMessage();
                if (class_exists('Eiou\\Utils\\Logger')) {
                    Logger::getInstance()->error("Column type migration failed for {$tableName}.{$columnName}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }


    // Add missing indexes
    $indexesToAdd = [
        // dead_letter_queue.message_id was previously unindexed, so the
        // duplicate-detection lookup in DeadLetterQueueRepository::add
        // (`WHERE message_id = ? AND status IN ('pending','retrying')`)
        // ran a full-table scan every time. Composite (message_id,
        // status) covers both the equality and the status filter.
        'dead_letter_queue' => [
            'idx_dlq_message_status' => 'message_id, status',
        ],
        // contact_currencies pending-currency lookups in
        // ContactCurrencyRepository previously hit the pubkey_hash-only
        // index and filtered by status post-scan. Composite covers
        // both columns so multi-currency accept/decline flows skip
        // the row-walk.
        'contact_currencies' => [
            'idx_cc_hash_status' => 'pubkey_hash, status',
        ],
    ];

    foreach ($indexesToAdd as $tableName => $indexes) {
        foreach ($indexes as $indexName => $columnSpec) {
            try {
                assertSafeMigrationIdentifier($tableName, 'indexesToAdd.tableName');
                assertSafeMigrationIdentifier($indexName, 'indexesToAdd.indexName');
                // Check if index exists
                $stmt = $pdo->query("SHOW INDEX FROM `$tableName` WHERE Key_name = '$indexName'");
                if ($stmt->rowCount() === 0) {
                    // Handle composite indexes (columns separated by comma)
                    $columns = array_map('trim', explode(',', $columnSpec));
                    foreach ($columns as $col) {
                        assertSafeMigrationIdentifier($col, 'indexesToAdd.columnSpec');
                    }
                    $columnList = '`' . implode('`, `', $columns) . '`';
                    $pdo->exec("ALTER TABLE `$tableName` ADD INDEX `$indexName` ($columnList)");
                    $results["{$tableName}.{$indexName}"] = 'index_created';
                } else {
                    $results["{$tableName}.{$indexName}"] = 'index_exists';
                }
            } catch (PDOException $e) {
                $results["{$tableName}.{$indexName}"] = 'index_error: ' . $e->getMessage();
                if (class_exists('Eiou\\Utils\\Logger')) {
                    Logger::getInstance()->error("Index creation failed for {$tableName}.{$indexName}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    return $results;
}