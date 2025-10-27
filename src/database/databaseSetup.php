<?php
# Copyright 2025

function freshInstall(){
    // Check if the configuration file exists
    if (!file_exists('/etc/eiou/dbconfig.php')) {
        // Create the directory if it doesn't exist
        if (!file_exists('/etc/eiou')) {
            mkdir('/etc/eiou', 0755, true);
        }
        
        // Create a default configuration file
        $dbConfig = "<?php\n";
       
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

            // Create database
            $rootConn->exec("CREATE DATABASE `$dbName`");

            // Create user with limited privileges
            $rootConn->exec("CREATE USER '$dbUser'@'$dbHost' IDENTIFIED BY '$dbPass'");
            $rootConn->exec("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'$dbHost'");
            $rootConn->exec("FLUSH PRIVILEGES");

            // Connect to new database and create tables
            $dbConn = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
            $dbConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $dbConn->exec(getDebugTableSchema());
            $dbConn->exec(getContactsTableSchema());
            $dbConn->exec(getTransactionsTableSchema());
            $dbConn->exec(getP2pTableSchema());
            $dbConn->exec(getRp2pTableSchema());

            // Append database configuration to the config file
            $dbConfig .= "\$database['dbHost'] = '$dbHost';\n";
            $dbConfig .= "\$database['dbName'] = '$dbName';\n";
            $dbConfig .= "\$database['dbUser'] = '$dbUser';\n";
            $dbConfig .= "\$database['dbPass'] = '$dbPass';\n";

        } catch (PDOException $e) {
            // Handle database error
            error_log("Database setup error: " . $e->getMessage());
            echo "An error occurred during database setup. Please check the error log for details.\n";
            echo "Database setup error: " . $e->getMessage();
            exit(1);
        }
   
        // Write the default configuration
        file_put_contents('/etc/eiou/dbconfig.php', $dbConfig, LOCK_EX);
    }
}