<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Utils\Logger;
use PDO;
use PDOException;
use RuntimeException;

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

            // Create database if it doesn't exist
            $rootConn->exec("CREATE DATABASE IF NOT EXISTS `$dbName`");

            // Create user with limited privileges (if not exists)
            // Note: If user already exists, this will fail but that's OK - we'll catch it
            try {
                $rootConn->exec("CREATE USER '$dbUser'@'$dbHost' IDENTIFIED BY '$dbPass'");
                $rootConn->exec("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'$dbHost'");
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
                $dbConn->exec(getDebugTableSchema());
                $dbConn->exec(getContactsTableSchema());
                $dbConn->exec(getAddressTableSchema());
                $dbConn->exec(getBalancesTableSchema());
                $dbConn->exec(getTransactionsTableSchema());
                $dbConn->exec(getP2pTableSchema());
                $dbConn->exec(getRp2pTableSchema());
                $dbConn->exec(getApiKeysTableSchema());
                $dbConn->exec(getApiRequestLogTableSchema());
                $dbConn->exec(getMessageDeliveryTableSchema());
                $dbConn->exec(getDeadLetterQueueTableSchema());
                $dbConn->exec(getDeliveryMetricsTableSchema());
                $dbConn->exec(getRateLimitsTableSchema());
                $dbConn->exec(getHeldTransactionsTableSchema());
                $dbConn->exec(getChainDropProposalsTableSchema());
                $dbConn->exec(getRp2pCandidatesTableSchema());
                $dbConn->exec(getP2pSendersTableSchema());
                $dbConn->exec(getP2pRelayedContactsTableSchema());
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

            // Overwrite database configuration to the config file
             $dbConfig = [
                'dbHost' => addslashes($dbHost), // Database Host
                'dbName' => addslashes($dbName), // Database Name
                'dbUser' => addslashes($dbUser), // Database User
                'dbPass' => addslashes($dbPass)  // Database password
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
   
        // Write the default configuration
        if($dbConfig !== []){
            file_put_contents('/etc/eiou/config/dbconfig.json', json_encode($dbConfig), LOCK_EX);
        }
    }
}

/**
 * Run database migrations to add new tables to existing databases
 * This function is idempotent - safe to run multiple times
 *
 * @param PDO $pdo Database connection
 * @return array Migration results
 */
function runMigrations(PDO $pdo): array {

    $results = [];

    // List of migration tables to create (added after initial release)
    $migrations = [
        'chain_drop_proposals' => 'getChainDropProposalsTableSchema',
        'rp2p_candidates' => 'getRp2pCandidatesTableSchema',
        'p2p_senders' => 'getP2pSendersTableSchema',
        'p2p_relayed_contacts' => 'getP2pRelayedContactsTableSchema',
    ];

    foreach ($migrations as $tableName => $schemaFunction) {
        try {
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

    return $results;
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
        'p2p' => [
            'fast' => 'TINYINT(1) DEFAULT 1 AFTER description',
            'contacts_sent_count' => 'INT DEFAULT 0 AFTER fast',
            'contacts_responded_count' => 'INT DEFAULT 0 AFTER contacts_sent_count',
            'hop_wait' => 'INT DEFAULT 0 AFTER contacts_responded_count',
            'contacts_relayed_count' => 'INT DEFAULT 0 AFTER hop_wait',
            'contacts_relayed_responded_count' => 'INT DEFAULT 0 AFTER contacts_relayed_count',
        ],
    ];

    // List of columns to DROP: [tableName => [columnName, ...]]
    $columnsToDrop = [];

    // Add new columns
    foreach ($columnsToAdd as $tableName => $columns) {
        foreach ($columns as $columnName => $columnDefinition) {
            try {
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
    $enumUpdates = [];

    foreach ($enumUpdates as $tableName => $columns) {
        foreach ($columns as $columnName => $newEnumDef) {
            try {
                // Check current ENUM values
                $stmt = $pdo->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
                $columnInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($columnInfo) {
                    $currentType = $columnInfo['Type'];
                    // Check if 'sending' is already in the enum
                    if (strpos($currentType, "'sending'") === false) {
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

    // Add missing indexes
    $indexesToAdd = [];

    foreach ($indexesToAdd as $tableName => $indexes) {
        foreach ($indexes as $indexName => $columnSpec) {
            try {
                // Check if index exists
                $stmt = $pdo->query("SHOW INDEX FROM `$tableName` WHERE Key_name = '$indexName'");
                if ($stmt->rowCount() === 0) {
                    // Handle composite indexes (columns separated by comma)
                    $columns = array_map('trim', explode(',', $columnSpec));
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