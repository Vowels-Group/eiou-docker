<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\BackupServiceInterface;
use Eiou\Security\KeyEncryption;
use Eiou\Core\Constants;
use Eiou\Core\UserContext;
use Eiou\Core\ErrorCodes;
use Eiou\Exceptions\FatalServiceException;
use Eiou\Exceptions\RecoverableServiceException;
use Eiou\Exceptions\ValidationServiceException;
use PDO;
use DateTime;
use Exception;

/**
 * Backup Service
 *
 * Handles MariaDB database backup and restore operations.
 * Backups are encrypted using AES-256-GCM with the master key.
 */
class BackupService implements BackupServiceInterface
{
    private UserContext $currentUser;
    private PDO $pdo;
    private string $backupDirectory;

    public function __construct(UserContext $currentUser, PDO $pdo)
    {
        $this->currentUser = $currentUser;
        $this->pdo = $pdo;
        $this->backupDirectory = Constants::BACKUP_DIRECTORY;
        $this->ensureBackupDirectory();
    }

    private function ensureBackupDirectory(): void
    {
        if (!is_dir($this->backupDirectory)) {
            mkdir($this->backupDirectory, 0700, true);
            chown($this->backupDirectory, 'www-data');
        }
    }

    public function createBackup(?string $customFilename = null): array
    {
        // Generate filename
        $timestamp = date('Ymd_His');
        $filename = $customFilename
            ? preg_replace('/[^a-zA-Z0-9_-]/', '', $customFilename) . Constants::BACKUP_FILE_EXTENSION
            : "backup_{$timestamp}" . Constants::BACKUP_FILE_EXTENSION;

        $filepath = $this->backupDirectory . '/' . $filename;

        // Get database credentials from config file
        $dbConfig = $this->getDatabaseCredentials();
        if (!$dbConfig) {
            throw new FatalServiceException(
                'Database configuration not found',
                ErrorCodes::DB_CONFIG_NOT_FOUND,
                ['path' => '/etc/eiou/config/dbconfig.json']
            );
        }
        $dbHost = $dbConfig['dbHost'];
        $dbName = $dbConfig['dbName'];
        $dbUser = $dbConfig['dbUser'];
        $dbPass = $dbConfig['dbPass'];

        // Create temporary MySQL config file for secure password passing
        $tempCnf = tempnam('/tmp', 'mysql_');
        chmod($tempCnf, 0600);
        file_put_contents($tempCnf, "[client]\nuser={$dbUser}\npassword={$dbPass}\nhost={$dbHost}\n");

        $cmd = sprintf(
            'mysqldump --defaults-extra-file=%s --single-transaction --routines --triggers --quick %s 2>&1',
            escapeshellarg($tempCnf),
            escapeshellarg($dbName)
        );

        $sqlDump = shell_exec($cmd);

        // Securely remove temp config file
        unlink($tempCnf);

        // Better error diagnostics
        if (empty($sqlDump)) {
            throw new FatalServiceException(
                'mysqldump returned empty result',
                ErrorCodes::MYSQLDUMP_FAILED,
                [
                    'host' => $dbHost,
                    'database' => $dbName,
                    'user' => $dbUser,
                    'password_set' => !empty($dbPass)
                ]
            );
        }

        // Check if output is an error message rather than SQL
        if (strpos($sqlDump, 'CREATE TABLE') === false) {
            // Truncate potential error message for logging
            $errorPreview = substr(trim($sqlDump), 0, 500);
            throw new FatalServiceException(
                'mysqldump failed: ' . $errorPreview,
                ErrorCodes::MYSQLDUMP_FAILED,
                [
                    'host' => $dbHost,
                    'database' => $dbName,
                    'user' => $dbUser,
                    'password_set' => !empty($dbPass)
                ]
            );
        }

        // Encrypt the SQL dump
        $encrypted = KeyEncryption::encrypt($sqlDump);

        // Clear sensitive data
        KeyEncryption::secureClear($sqlDump);

        // Create backup file with metadata
        $backupData = [
            'version' => '1.0',
            'created_at' => date('c'),
            'hostname' => $this->currentUser->getHttpAddress() ?? $this->currentUser->getTorAddress() ?? 'unknown',
            'database' => $dbName,
            'encrypted' => $encrypted
        ];

        $jsonData = json_encode($backupData, JSON_PRETTY_PRINT);

        if (file_put_contents($filepath, $jsonData, LOCK_EX) === false) {
            throw new FatalServiceException(
                'Failed to write backup file',
                ErrorCodes::BACKUP_FAILED,
                ['filepath' => $filepath]
            );
        }

        chmod($filepath, 0600);

        Logger::getInstance()->info("Backup created", ['filename' => $filename, 'size' => filesize($filepath)]);

        return [
            'success' => true,
            'filename' => $filename,
            'size' => filesize($filepath),
            'path' => $filepath
        ];
    }

    public function restoreBackup(string $filename, bool $confirmOverwrite = false): array
    {
        if (!$confirmOverwrite) {
            throw new ValidationServiceException(
                'Must confirm overwrite to restore backup',
                ErrorCodes::RESTORE_CONFIRM_REQUIRED,
                'confirmOverwrite'
            );
        }

        $filepath = $this->backupDirectory . '/' . $filename;

        if (!file_exists($filepath)) {
            throw new ValidationServiceException(
                'Backup file not found',
                ErrorCodes::BACKUP_NOT_FOUND,
                'filename',
                null,
                ['filename' => $filename, 'path' => $filepath]
            );
        }

        // Read and parse backup file
        $jsonData = file_get_contents($filepath);
        $backupData = json_decode($jsonData, true);

        if (!$backupData || !isset($backupData['encrypted'])) {
            throw new ValidationServiceException(
                'Invalid backup file format',
                ErrorCodes::BACKUP_INVALID,
                'filename',
                null,
                ['filename' => $filename]
            );
        }

        // Decrypt the SQL dump
        try {
            $sqlDump = KeyEncryption::decrypt($backupData['encrypted']);
        } catch (Exception $e) {
            throw new FatalServiceException(
                'Failed to decrypt backup: ' . $e->getMessage(),
                ErrorCodes::BACKUP_DECRYPT_FAILED,
                ['filename' => $filename],
                null,
                $e
            );
        }

        // Get database credentials from config file
        $dbConfig = $this->getDatabaseCredentials();
        if (!$dbConfig) {
            throw new FatalServiceException(
                'Database configuration not found',
                ErrorCodes::DB_CONFIG_NOT_FOUND,
                ['path' => '/etc/eiou/config/dbconfig.json']
            );
        }
        $dbHost = $dbConfig['dbHost'];
        $dbName = $dbConfig['dbName'];
        $dbUser = $dbConfig['dbUser'];
        $dbPass = $dbConfig['dbPass'];

        // Write SQL to temp file for mysql import
        $tempFile = tempnam('/tmp', 'eiou_restore_');
        file_put_contents($tempFile, $sqlDump);
        KeyEncryption::secureClear($sqlDump);

        // Create temporary MySQL config file for secure password passing
        $tempCnf = tempnam('/tmp', 'mysql_');
        chmod($tempCnf, 0600);
        file_put_contents($tempCnf, "[client]\nuser={$dbUser}\npassword={$dbPass}\nhost={$dbHost}\n");

        $cmd = sprintf(
            'mysql --defaults-extra-file=%s %s < %s 2>&1',
            escapeshellarg($tempCnf),
            escapeshellarg($dbName),
            escapeshellarg($tempFile)
        );

        $output = shell_exec($cmd);

        // Securely remove temp config file
        unlink($tempCnf);

        unlink($tempFile);

        // Check for errors (mysql doesn't always return proper exit codes via shell_exec)
        if ($output && preg_match('/ERROR/i', $output)) {
            Logger::getInstance()->error("Backup restore failed", ['output' => $output]);
            throw new FatalServiceException(
                'MySQL restore failed: ' . $output,
                ErrorCodes::RESTORE_FAILED,
                ['filename' => $filename, 'mysql_output' => $output]
            );
        }

        Logger::getInstance()->info("Backup restored", ['filename' => $filename]);

        return [
            'success' => true,
            'filename' => $filename,
            'restored_at' => date('c')
        ];
    }

    public function listBackups(): array
    {
        $backups = [];
        $files = glob($this->backupDirectory . '/*' . Constants::BACKUP_FILE_EXTENSION);

        if ($files) {
            foreach ($files as $filepath) {
                $filename = basename($filepath);
                $backups[] = [
                    'filename' => $filename,
                    'size' => filesize($filepath),
                    'size_human' => $this->formatBytes(filesize($filepath)),
                    'created_at' => date('c', filemtime($filepath))
                ];
            }

            // Sort by creation time, newest first
            usort($backups, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
        }

        return $backups;
    }

    public function deleteBackup(string $filename): array
    {
        $filepath = $this->backupDirectory . '/' . $filename;

        if (!file_exists($filepath)) {
            throw new ValidationServiceException(
                'Backup file not found',
                ErrorCodes::BACKUP_NOT_FOUND,
                'filename',
                null,
                ['filename' => $filename, 'path' => $filepath]
            );
        }

        if (unlink($filepath)) {
            Logger::getInstance()->info("Backup deleted", ['filename' => $filename]);
            return ['success' => true, 'filename' => $filename];
        }

        throw new FatalServiceException(
            'Failed to delete backup file',
            ErrorCodes::DELETE_FAILED,
            ['filename' => $filename, 'path' => $filepath]
        );
    }

    public function verifyBackup(string $filename): array
    {
        $filepath = $this->backupDirectory . '/' . $filename;

        if (!file_exists($filepath)) {
            throw new ValidationServiceException(
                'Backup file not found',
                ErrorCodes::BACKUP_NOT_FOUND,
                'filename',
                null,
                ['filename' => $filename, 'path' => $filepath]
            );
        }

        $jsonData = file_get_contents($filepath);
        $backupData = json_decode($jsonData, true);

        if (!$backupData || !isset($backupData['encrypted'])) {
            throw new ValidationServiceException(
                'Invalid backup file format',
                ErrorCodes::BACKUP_INVALID,
                'filename',
                null,
                ['filename' => $filename]
            );
        }

        // Try to decrypt
        try {
            $sqlDump = KeyEncryption::decrypt($backupData['encrypted']);
        } catch (Exception $e) {
            throw new FatalServiceException(
                'Failed to decrypt backup: ' . $e->getMessage(),
                ErrorCodes::BACKUP_DECRYPT_FAILED,
                ['filename' => $filename],
                null,
                $e
            );
        }

        // Verify it contains SQL
        $valid = strpos($sqlDump, 'CREATE TABLE') !== false || strpos($sqlDump, 'INSERT INTO') !== false;

        KeyEncryption::secureClear($sqlDump);

        return [
            'success' => true,
            'valid' => $valid,
            'version' => $backupData['version'] ?? 'unknown',
            'created_at' => $backupData['created_at'] ?? 'unknown'
        ];
    }

    public function isAutoBackupEnabled(): bool
    {
        $setting = $this->currentUser->get('autoBackupEnabled');
        if ($setting !== null) {
            return (bool) $setting;
        }
        return Constants::isAutoBackupEnabled();
    }

    public function setAutoBackupEnabled(bool $enabled): array
    {
        // Update in-memory setting
        $this->currentUser->set('autoBackupEnabled', $enabled);

        // Persist to config file
        $configFile = '/etc/eiou/config/defaultconfig.json';
        $config = [];
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true) ?? [];
        }
        $config['autoBackupEnabled'] = $enabled;
        if (file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new FatalServiceException(
                'Failed to write configuration file',
                ErrorCodes::UPDATE_FAILED,
                ['path' => $configFile]
            );
        }

        Logger::getInstance()->info("Auto backup " . ($enabled ? 'enabled' : 'disabled'));

        return ['success' => true, 'enabled' => $enabled];
    }

    public function getBackupStatus(): array
    {
        $backups = $this->listBackups();
        $lastBackup = !empty($backups) ? $backups[0] : null;

        return [
            'enabled' => $this->isAutoBackupEnabled(),
            'backup_count' => count($backups),
            'retention_count' => Constants::BACKUP_RETENTION_COUNT,
            'last_backup' => $lastBackup ? $lastBackup['created_at'] : null,
            'last_backup_file' => $lastBackup ? $lastBackup['filename'] : null,
            'backup_directory' => $this->backupDirectory,
            'next_scheduled' => $this->isAutoBackupEnabled()
                ? $this->getNextScheduledBackup()
                : null
        ];
    }

    public function cleanupOldBackups(): array
    {
        $backups = $this->listBackups();
        $retentionCount = Constants::BACKUP_RETENTION_COUNT;
        $deletedFiles = [];

        if (count($backups) > $retentionCount) {
            $toDelete = array_slice($backups, $retentionCount);

            foreach ($toDelete as $backup) {
                $result = $this->deleteBackup($backup['filename']);
                if ($result['success']) {
                    $deletedFiles[] = $backup['filename'];
                }
            }
        }

        return [
            'success' => true,
            'deleted_count' => count($deletedFiles),
            'deleted_files' => $deletedFiles
        ];
    }

    public function handleCommand(array $argv, $output): void
    {
        // argv format: ['eiou', 'backup', 'subcommand', 'arg1', 'arg2', ...]
        $subcommand = $argv[2] ?? 'help';

        switch ($subcommand) {
            case 'create':
                $customName = $argv[3] ?? null;
                // Let exceptions propagate - CLI entry point handles them
                $result = $this->createBackup($customName);
                $output->success(
                    "Backup created: {$result['filename']} ({$this->formatBytes($result['size'])})",
                    [
                        'filename' => $result['filename'],
                        'size' => $result['size'],
                        'size_human' => $this->formatBytes($result['size'])
                    ],
                    'Backup created successfully'
                );
                break;

            case 'restore':
                $filename = $argv[3] ?? null;
                $confirm = ($argv[4] ?? '') === '--confirm';

                if (!$filename) {
                    throw new ValidationServiceException(
                        'Usage: backup restore <filename> --confirm',
                        ErrorCodes::MISSING_ARGUMENT,
                        'filename'
                    );
                }

                if (!$confirm) {
                    $output->info(
                        'WARNING: This will overwrite all current database data! Add --confirm to proceed.',
                        ['requires_confirmation' => true]
                    );
                    return;
                }

                // Let exceptions propagate - CLI entry point handles them
                $result = $this->restoreBackup($filename, true);
                $output->success('Backup restored successfully', $result);
                break;

            case 'list':
                $backups = $this->listBackups();
                if (empty($backups)) {
                    $output->info('No backups found.', ['count' => 0, 'backups' => []]);
                } else {
                    $output->success(
                        "Found " . count($backups) . " backup(s):",
                        ['count' => count($backups), 'backups' => $backups],
                        'Backups listed'
                    );
                    if (!$output->isJsonMode()) {
                        foreach ($backups as $backup) {
                            echo "  {$backup['filename']} - {$backup['size_human']} - {$backup['created_at']}\n";
                        }
                    }
                }
                break;

            case 'delete':
                $filename = $argv[3] ?? null;
                if (!$filename) {
                    throw new ValidationServiceException(
                        'Usage: backup delete <filename>',
                        ErrorCodes::MISSING_ARGUMENT,
                        'filename'
                    );
                }
                // Let exceptions propagate - CLI entry point handles them
                $result = $this->deleteBackup($filename);
                $output->success("Backup deleted: {$filename}", $result);
                break;

            case 'verify':
                $filename = $argv[3] ?? null;
                if (!$filename) {
                    throw new ValidationServiceException(
                        'Usage: backup verify <filename>',
                        ErrorCodes::MISSING_ARGUMENT,
                        'filename'
                    );
                }
                // Let exceptions propagate - CLI entry point handles them
                $result = $this->verifyBackup($filename);
                if ($result['valid']) {
                    $output->success("Backup verified: {$filename} is valid", $result);
                } else {
                    $output->info("Backup verification failed: {$filename} appears corrupted", $result);
                }
                break;

            case 'enable':
                // Let exceptions propagate - CLI entry point handles them
                $result = $this->setAutoBackupEnabled(true);
                $output->success('Automatic backups enabled', $result);
                break;

            case 'disable':
                // Let exceptions propagate - CLI entry point handles them
                $result = $this->setAutoBackupEnabled(false);
                $output->success('Automatic backups disabled', $result);
                break;

            case 'status':
                $status = $this->getBackupStatus();
                $output->success(
                    "Backup Status:\n" .
                    "  Enabled: " . ($status['enabled'] ? 'Yes' : 'No') . "\n" .
                    "  Backup count: {$status['backup_count']}\n" .
                    "  Retention: {$status['retention_count']} backups\n" .
                    "  Last backup: " . ($status['last_backup'] ?? 'Never') . "\n" .
                    "  Next scheduled: " . ($status['next_scheduled'] ?? 'N/A'),
                    $status,
                    'Backup status retrieved'
                );
                break;

            case 'cleanup':
                $result = $this->cleanupOldBackups();
                $output->success(
                    "Cleaned up {$result['deleted_count']} old backup(s)",
                    $result
                );
                break;

            case 'help':
            default:
                $commands = [
                    'backup create [name]' => ['description' => 'Create a new backup (optional custom name)'],
                    'backup restore <file> --confirm' => ['description' => 'Restore from backup (requires --confirm)'],
                    'backup list' => ['description' => 'List all backups'],
                    'backup delete <file>' => ['description' => 'Delete a backup'],
                    'backup verify <file>' => ['description' => 'Verify backup integrity'],
                    'backup enable' => ['description' => 'Enable automatic daily backups'],
                    'backup disable' => ['description' => 'Disable automatic daily backups'],
                    'backup status' => ['description' => 'Show backup status and settings'],
                    'backup cleanup' => ['description' => 'Remove old backups (keep 3 most recent)']
                ];
                $output->help($commands);
                break;
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function getNextScheduledBackup(): string
    {
        $hour = Constants::BACKUP_CRON_HOUR;
        $minute = Constants::BACKUP_CRON_MINUTE;

        $next = new DateTime();
        $next->setTime($hour, $minute, 0);

        if ($next <= new DateTime()) {
            $next->modify('+1 day');
        }

        return $next->format('c');
    }

    private function getDatabaseCredentials(): ?array
    {
        $configFile = '/etc/eiou/config/dbconfig.json';
        if (!file_exists($configFile)) {
            Logger::getInstance()->error("Database config file not found", ['path' => $configFile]);
            return null;
        }

        $config = json_decode(file_get_contents($configFile), true);
        if (!$config || !isset($config['dbHost'], $config['dbName'], $config['dbUser'], $config['dbPass'])) {
            Logger::getInstance()->error("Invalid database config", ['path' => $configFile]);
            return null;
        }

        return $config;
    }
}
