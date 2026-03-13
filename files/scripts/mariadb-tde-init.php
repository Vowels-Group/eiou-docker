<?php
# Copyright 2025-2026 Vowels Group, LLC
#
# MariaDB TDE Initialization Script
#
# Actions:
#   setup-key      - Create TDE key file in /dev/shm only (pre-MariaDB-start)
#   setup          - Create key file + write encryption config (first-time setup)
#   encrypt-tables - Encrypt existing unencrypted tables (after MariaDB restart)
#
# On subsequent boots, only 'setup-key' is needed before MariaDB starts.
# On first boot, 'setup' writes the config and signals a MariaDB restart,
# then 'encrypt-tables' encrypts existing data.
#
# Exit codes:
#   0 = success (check stdout for "RESTART_REQUIRED" flag)
#   1 = error

require_once '/app/eiou/vendor/autoload.php';

use Eiou\Security\MariaDbEncryption;
use Eiou\Security\VolumeEncryption;

$action = $argv[1] ?? 'setup';

try {
    switch ($action) {
        case 'setup-key':
            // Create TDE key file only (for pre-MariaDB-start on subsequent boots)
            MariaDbEncryption::setupKeyFile();
            echo "MariaDB TDE: key file ready\n";
            break;

        case 'setup':
            // First-time setup: create key file + write persistent config
            MariaDbEncryption::setupKeyFile();
            MariaDbEncryption::writeConfig();
            echo "MariaDB TDE: configuration written\n";

            if (!MariaDbEncryption::isInitialized()) {
                echo "RESTART_REQUIRED\n";
            }
            break;

        case 'encrypt-tables':
            // Called after MariaDB restart to encrypt existing tables
            if (MariaDbEncryption::isInitialized()) {
                echo "MariaDB TDE: already initialized\n";
                break;
            }

            // Connect to database
            $configPath = '/etc/eiou/config/dbconfig.json';
            if (!file_exists($configPath)) {
                echo "MariaDB TDE: no database config yet, skipping\n";
                break;
            }

            $config = json_decode(file_get_contents($configPath), true);
            if (!is_array($config)) {
                throw new \RuntimeException('Invalid dbconfig.json');
            }

            // Get database password (may be encrypted)
            $dbPass = null;
            if (isset($config['dbPassEncrypted'])) {
                $dbPass = \Eiou\Security\KeyEncryption::decrypt($config['dbPassEncrypted']);
            } elseif (isset($config['dbPass'])) {
                $dbPass = $config['dbPass'];
            }

            if ($dbPass === null) {
                throw new \RuntimeException('Cannot read database password');
            }

            $pdo = new PDO(
                "mysql:host=" . ($config['dbHost'] ?? 'localhost')
                    . ";dbname=" . ($config['dbName'] ?? 'eiou')
                    . ";unix_socket=/var/run/mysqld/mysqld.sock",
                $config['dbUser'] ?? 'root',
                $dbPass
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            \Eiou\Security\KeyEncryption::secureClear($dbPass);

            MariaDbEncryption::encryptExistingTables($pdo);
            echo "MariaDB TDE: all tables encrypted\n";
            break;

        default:
            fwrite(STDERR, "Unknown action: $action\n");
            exit(1);
    }
} catch (Exception $e) {
    fwrite(STDERR, "MariaDB TDE error: " . $e->getMessage() . "\n");
    exit(1);
}
