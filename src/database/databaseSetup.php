<?php
# Copyright 2025

function freshInstall(){
    // Check if the configuration file exists
    if (!file_exists('/etc/eiou/config.php')) {
        // Create the directory if it doesn't exist
        if (!file_exists('/etc/eiou')) {
            mkdir('/etc/eiou', 0755, true);
        }
        
        // Create a default configuration file
        $defaultConfig = "<?php\n";
        $defaultConfig .= "\$user['defaultFee'] = 0.1; // Default transaction fee in percent\n";
        $defaultConfig .= "\$user['defaultCurrency'] = 'USD'; // Default currency\n";
        $defaultConfig .= "\$user['localhostOnly'] = true; // Network connection limited to localhost only\n";
        $defaultConfig .= "\$user['maxFee'] = 5; // Maximum total fee for a transaction in percent\n";
        $defaultConfig .= "\$user['maxP2pLevel'] = 6; // Default maximum level for Peer to Peer propagation\n";
        $defaultConfig .= "\$user['p2pExpiration'] = 300; // Default expiration time for Peer to Peer requests in seconds\n";
        $defaultConfig .= "\$user['debug'] = true; // Enable debug mode\n";
        $defaultConfig .= "\$user['maxOutput'] = 5; // Maximum lines of output for multi-line output\n";

        // Create MySQL user, database, and tables
        $dbHost = 'localhost';
        $dbRootUser = 'root';
        $dbRootPass = ''; // You may want to prompt for this or use a secure method

        // Connect as root to create database and user
        try {
            $rootConn = new PDO("mysql:host=$dbHost;unix_socket=/var/run/mysqld/mysqld.sock", $dbRootUser, $dbRootPass);         $rootConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
            $defaultConfig .= "\$user['dbHost'] = '$dbHost';\n";
            $defaultConfig .= "\$user['dbName'] = '$dbName';\n";
            $defaultConfig .= "\$user['dbUser'] = '$dbUser';\n";
            $defaultConfig .= "\$user['dbPass'] = '$dbPass';\n";

        } catch (PDOException $e) {
            // Handle database error
            error_log("Database setup error: " . $e->getMessage());
            echo "An error occurred during database setup. Please check the error log for details.\n";
            echo "Database setup error: " . $e->getMessage();
            exit(1);
        }

        
        // Write the default configuration
        file_put_contents('/etc/eiou/config.php', $defaultConfig, LOCK_EX);
        // Retrieve the Tor hidden service hostname
        $torAddress = trim(file_get_contents('/var/lib/tor/hidden_service/hostname'));        
        // Append the Tor address to the config file
        file_put_contents('/etc/eiou/config.php', "\n" . '$user["torAddress"]="' . addslashes($torAddress) . '";' . "\n", FILE_APPEND | LOCK_EX);
        
        //echo "Created default configuration file at /etc/eiou/\n";
    }
}
