<?php
# Copyright 2025

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
            require_once '/etc/eiou/src/database/databaseSchema.php';
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
                if (class_exists('SecureLogger')) {
                    SecureLogger::warning("Database user creation failed (user might already exist)", [
                        'error' => $userExists->getMessage()
                    ]);
                } else {
                    error_log("Database user creation failed (user might already exist): " . $userExists->getMessage());
                }
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
                $dbConn->exec(getTransactionsTableSchema());
                $dbConn->exec(getP2pTableSchema());
                $dbConn->exec(getRp2pTableSchema());
            } catch (PDOException $tableError) {
                if (class_exists('SecureLogger')) {
                    SecureLogger::error("Table creation failed", [
                        'error' => $tableError->getMessage()
                    ]);
                } else {
                    error_log("Table creation failed: " . $tableError->getMessage());
                }
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
            // Handle database error - use SecureLogger if available, otherwise error_log
            if (class_exists('SecureLogger')) {
                SecureLogger::critical("Database setup error", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            } else {
                error_log("Database setup error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            }

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