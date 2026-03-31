<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Security;

use PDO;
use RuntimeException;

/**
 * MariaDB Transparent Data Encryption (TDE)
 *
 * Encrypts all InnoDB/Aria tables and redo logs at rest using MariaDB's
 * built-in file_key_management plugin. The encryption key is derived from
 * the master encryption key and stored only in /dev/shm (RAM-backed).
 *
 * This ensures that the raw MySQL data files on the Docker volume are
 * encrypted, even if the host server has access to the volume filesystem.
 *
 * The TDE key file format is:
 *   1;<hex-encoded 256-bit key>
 *
 * Where "1" is the key identifier referenced by MariaDB's encryption config.
 */
class MariaDbEncryption
{
    /**
     * TDE key file in RAM-backed filesystem
     */
    private const TDE_KEY_FILE = '/dev/shm/.mariadb-encryption-key';

    /**
     * MariaDB encryption config file
     */
    private const ENCRYPTION_CONFIG = '/etc/mysql/conf.d/encryption.cnf';

    /**
     * HMAC context for TDE key derivation
     */
    private const HMAC_CONTEXT = 'eiou-mariadb-tde';

    /**
     * Marker file indicating TDE has been initialized for this database.
     * Stored on the persistent config volume so it survives restarts.
     */
    private const TDE_INITIALIZED_MARKER = '/etc/eiou/config/.tde_initialized';

    /**
     * Set up MariaDB TDE key file and configuration.
     *
     * Must be called after the master key is available (in /dev/shm or on
     * the config volume) and BEFORE MariaDB starts on subsequent boots.
     *
     * On first boot, MariaDB is already running when the master key is created,
     * so this is called post-wallet-generation and MariaDB must be restarted.
     *
     * @throws RuntimeException If master key is not available
     */
    public static function setupKeyFile(): void
    {
        // Read the master key
        $masterKeyPath = VolumeEncryption::getMasterKeyPath();
        if (!file_exists($masterKeyPath)) {
            throw new RuntimeException(
                'Cannot set up MariaDB TDE: master key not available'
            );
        }

        $masterKey = file_get_contents($masterKeyPath);
        if ($masterKey === false || strlen($masterKey) !== 32) {
            throw new RuntimeException('Cannot set up MariaDB TDE: master key corrupted');
        }

        // Derive TDE key from master key using HMAC-SHA256
        $tdeKey = hash_hmac('sha256', $masterKey, self::HMAC_CONTEXT);
        KeyEncryption::secureClear($masterKey);

        // Write key file in MariaDB's expected format: key_id;hex_key
        $keyFileContent = "1;$tdeKey";
        KeyEncryption::secureClear($tdeKey);

        $oldUmask = umask(0077);
        $result = file_put_contents(self::TDE_KEY_FILE, $keyFileContent, LOCK_EX);
        umask($oldUmask);

        if ($result === false) {
            throw new RuntimeException('Failed to write MariaDB TDE key file');
        }

        chmod(self::TDE_KEY_FILE, 0640);
        if (posix_getuid() === 0) {
            chown(self::TDE_KEY_FILE, 'mysql');
            chgrp(self::TDE_KEY_FILE, 'mysql');
        }

        KeyEncryption::secureClear($keyFileContent);
    }

    /**
     * Write the MariaDB encryption configuration file.
     *
     * This is persistent — it tells MariaDB to load the file_key_management
     * plugin and where to find the key file. The key file itself is in /dev/shm
     * and must be recreated on every boot.
     */
    public static function writeConfig(): void
    {
        $config = "[mysqld]\n"
            . "# Transparent Data Encryption (TDE) — encrypts all tables at rest\n"
            . "plugin_load_add = file_key_management\n"
            . "file_key_management_filename = " . self::TDE_KEY_FILE . "\n"
            . "innodb_encrypt_tables = ON\n"
            . "innodb_encrypt_log = ON\n"
            . "innodb_encryption_threads = 4\n"
            . "encrypt_tmp_disk_tables = ON\n"
            . "encrypt_tmp_files = ON\n"
            . "encrypt_binlog = ON\n"
            . "aria_encrypt_tables = ON\n";

        $result = file_put_contents(self::ENCRYPTION_CONFIG, $config, LOCK_EX);
        if ($result === false) {
            throw new RuntimeException('Failed to write MariaDB encryption configuration');
        }

        chmod(self::ENCRYPTION_CONFIG, 0644);
    }

    /**
     * Check if TDE has been initialized for the existing database.
     *
     * @return bool True if TDE tables exist and are encrypted
     */
    public static function isInitialized(): bool
    {
        return file_exists(self::TDE_INITIALIZED_MARKER);
    }

    /**
     * Encrypt all existing tables in the database.
     *
     * Called on first TDE setup when the database already has unencrypted tables.
     * Alters each user table to enable encryption. System tables are handled by
     * MariaDB's innodb_encrypt_tables=ON setting.
     *
     * @param PDO $pdo Database connection
     * @throws RuntimeException If table encryption fails
     */
    public static function encryptExistingTables(PDO $pdo): void
    {
        $dbName = 'eiou';

        // Get all InnoDB and Aria tables
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = ? AND ENGINE IN ('InnoDB', 'Aria')"
        );
        $stmt->execute([$dbName]);
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tables as $table) {
            $tableName = $table['TABLE_NAME'];
            // DDL cannot use prepared statements — table name is from information_schema, not user input
            $pdo->exec("ALTER TABLE `$dbName`.`$tableName` ENCRYPTED=YES ENCRYPTION_KEY_ID=1");
        }

        // Mark TDE as initialized
        file_put_contents(self::TDE_INITIALIZED_MARKER, date('c'), LOCK_EX);
        chmod(self::TDE_INITIALIZED_MARKER, 0600);
        if (posix_getuid() === 0) {
            chown(self::TDE_INITIALIZED_MARKER, 'www-data');
        }
    }

    /**
     * Check if the TDE key file exists in /dev/shm.
     *
     * @return bool True if the key file is ready
     */
    public static function isKeyFileReady(): bool
    {
        return file_exists(self::TDE_KEY_FILE);
    }

    /**
     * Get TDE status for diagnostics.
     *
     * @return array Status information
     */
    public static function getStatus(): array
    {
        return [
            'key_file_exists' => file_exists(self::TDE_KEY_FILE),
            'config_exists' => file_exists(self::ENCRYPTION_CONFIG),
            'initialized' => self::isInitialized(),
        ];
    }
}
