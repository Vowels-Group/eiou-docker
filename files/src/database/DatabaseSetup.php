<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

require_once __DIR__ . '/../utils/SecureLogger.php';

function freshInstall(){
    // Check if the configuration file exists
    if (!file_exists('/etc/eiou/dbconfig.json')) {
        // Create the directory if it doesn't exist
        if (!file_exists('/etc/eiou')) {
            mkdir('/etc/eiou', 0755, true);
        }
        
        // Create a default configuration file
        $dbConfig = [];
       
        // Create MySQL user, database, and tables
        $dbHost = 'localhost';
        $dbRootUser = 'root';
        $dbRootPass = ''; // You may want to prompt for this or use a secure method

        // Connect as root to create database and user
        try {
            require_once '/etc/eiou/src/database/DatabaseSchema.php';
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
                SecureLogger::warning("Database user creation failed (user might already exist)", [
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
            } catch (PDOException $tableError) {
                SecureLogger::error("Table creation failed", [
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
            SecureLogger::logException($e, 'ERROR');

            // Throw exception to let ErrorHandler handle it
            throw new \RuntimeException(
                'Database setup failed. Please check error log for details.',
                500,
                $e
            );
        }
   
        // Write the default configuration
        if($dbConfig !== []){
            file_put_contents('/etc/eiou/dbconfig.json', json_encode($dbConfig), LOCK_EX);
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
    require_once '/etc/eiou/src/database/DatabaseSchema.php';

    $results = [];

    // List of migration tables to create (added after initial release)
    $migrations = [];

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
            if (class_exists('SecureLogger')) {
                SecureLogger::error("Migration failed for table $tableName", [
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
        'transactions' => [
            'time' => 'BIGINT NULL AFTER signature_nonce'
        ]
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
                if (class_exists('SecureLogger')) {
                    SecureLogger::error("Column migration failed for {$tableName}.{$columnName}", [
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
                if (class_exists('SecureLogger')) {
                    SecureLogger::error("Column drop failed for {$tableName}.{$columnName}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    // Add missing indexes
    $indexesToAdd = [];

    foreach ($indexesToAdd as $tableName => $indexes) {
        foreach ($indexes as $indexName => $columnName) {
            try {
                // Check if index exists
                $stmt = $pdo->query("SHOW INDEX FROM `$tableName` WHERE Key_name = '$indexName'");
                if ($stmt->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE `$tableName` ADD INDEX `$indexName` (`$columnName`)");
                    $results["{$tableName}.{$indexName}"] = 'index_created';
                } else {
                    $results["{$tableName}.{$indexName}"] = 'index_exists';
                }
            } catch (PDOException $e) {
                $results["{$tableName}.{$indexName}"] = 'index_error: ' . $e->getMessage();
                if (class_exists('SecureLogger')) {
                    SecureLogger::error("Index creation failed for {$tableName}.{$indexName}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    return $results;
}