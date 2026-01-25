<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../utils/SecureLogger.php';
require_once __DIR__ . '/../contracts/BackupServiceInterface.php';
require_once __DIR__ . '/../security/KeyEncryption.php';
require_once __DIR__ . '/../core/Constants.php';

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
        try {
            // Generate filename
            $timestamp = date('Ymd_His');
            $filename = $customFilename
                ? preg_replace('/[^a-zA-Z0-9_-]/', '', $customFilename) . Constants::BACKUP_FILE_EXTENSION
                : "backup_{$timestamp}" . Constants::BACKUP_FILE_EXTENSION;

            $filepath = $this->backupDirectory . '/' . $filename;

            // Get database credentials from environment
            $dbHost = getenv('MYSQL_HOST') ?: 'localhost';
            $dbName = getenv('MYSQL_DATABASE') ?: 'eiou';
            $dbUser = getenv('MYSQL_USER') ?: 'eiou';
            $dbPass = getenv('MYSQL_PASSWORD') ?: '';

            // Execute mysqldump using MYSQL_PWD env var for password (safest method)
            $originalPwd = getenv('MYSQL_PWD');
            putenv("MYSQL_PWD=" . $dbPass);

            $cmd = sprintf(
                'mysqldump --single-transaction --routines --triggers --quick -h %s -u %s %s 2>&1',
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbName)
            );

            $sqlDump = shell_exec($cmd);

            // Clear the password from environment
            if ($originalPwd !== false) {
                putenv("MYSQL_PWD=" . $originalPwd);
            } else {
                putenv("MYSQL_PWD");
            }

            // Better error diagnostics
            if (empty($sqlDump)) {
                return [
                    'success' => false,
                    'error' => 'mysqldump returned empty result',
                    'debug' => [
                        'host' => $dbHost,
                        'database' => $dbName,
                        'user' => $dbUser,
                        'password_set' => !empty($dbPass)
                    ]
                ];
            }

            // Check if output is an error message rather than SQL
            if (strpos($sqlDump, 'CREATE TABLE') === false) {
                // Truncate potential error message for logging
                $errorPreview = substr(trim($sqlDump), 0, 500);
                return [
                    'success' => false,
                    'error' => 'mysqldump failed: ' . $errorPreview,
                    'debug' => [
                        'host' => $dbHost,
                        'database' => $dbName,
                        'user' => $dbUser,
                        'password_set' => !empty($dbPass)
                    ]
                ];
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
                return ['success' => false, 'error' => 'Failed to write backup file'];
            }

            chmod($filepath, 0600);

            SecureLogger::info("Backup created", ['filename' => $filename, 'size' => filesize($filepath)]);

            return [
                'success' => true,
                'filename' => $filename,
                'size' => filesize($filepath),
                'path' => $filepath
            ];

        } catch (Exception $e) {
            SecureLogger::error("Backup creation failed", ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function restoreBackup(string $filename, bool $confirmOverwrite = false): array
    {
        if (!$confirmOverwrite) {
            return ['success' => false, 'error' => 'Must confirm overwrite to restore backup'];
        }

        try {
            $filepath = $this->backupDirectory . '/' . $filename;

            if (!file_exists($filepath)) {
                return ['success' => false, 'error' => 'Backup file not found'];
            }

            // Read and parse backup file
            $jsonData = file_get_contents($filepath);
            $backupData = json_decode($jsonData, true);

            if (!$backupData || !isset($backupData['encrypted'])) {
                return ['success' => false, 'error' => 'Invalid backup file format'];
            }

            // Decrypt the SQL dump
            $sqlDump = KeyEncryption::decrypt($backupData['encrypted']);

            // Get database credentials
            $dbHost = getenv('MYSQL_HOST') ?: 'localhost';
            $dbName = getenv('MYSQL_DATABASE') ?: 'eiou';
            $dbUser = getenv('MYSQL_USER') ?: 'eiou';
            $dbPass = getenv('MYSQL_PASSWORD') ?: '';

            // Write SQL to temp file for mysql import
            $tempFile = tempnam('/tmp', 'eiou_restore_');
            file_put_contents($tempFile, $sqlDump);
            KeyEncryption::secureClear($sqlDump);

            // Execute mysql import using MYSQL_PWD env var for password (safest method)
            $originalPwd = getenv('MYSQL_PWD');
            putenv("MYSQL_PWD=" . $dbPass);

            $cmd = sprintf(
                'mysql -h %s -u %s %s < %s 2>&1',
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbName),
                escapeshellarg($tempFile)
            );

            $output = shell_exec($cmd);

            // Clear the password from environment
            if ($originalPwd !== false) {
                putenv("MYSQL_PWD=" . $originalPwd);
            } else {
                putenv("MYSQL_PWD");
            }

            unlink($tempFile);

            // Check for errors (mysql doesn't always return proper exit codes via shell_exec)
            if ($output && preg_match('/ERROR/i', $output)) {
                SecureLogger::error("Backup restore failed", ['output' => $output]);
                return ['success' => false, 'error' => 'MySQL restore failed: ' . $output];
            }

            SecureLogger::info("Backup restored", ['filename' => $filename]);

            return [
                'success' => true,
                'filename' => $filename,
                'restored_at' => date('c')
            ];

        } catch (Exception $e) {
            SecureLogger::error("Backup restore failed", ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
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
            return ['success' => false, 'error' => 'Backup file not found'];
        }

        if (unlink($filepath)) {
            SecureLogger::info("Backup deleted", ['filename' => $filename]);
            return ['success' => true, 'filename' => $filename];
        }

        return ['success' => false, 'error' => 'Failed to delete backup file'];
    }

    public function verifyBackup(string $filename): array
    {
        try {
            $filepath = $this->backupDirectory . '/' . $filename;

            if (!file_exists($filepath)) {
                return ['success' => false, 'valid' => false, 'error' => 'Backup file not found'];
            }

            $jsonData = file_get_contents($filepath);
            $backupData = json_decode($jsonData, true);

            if (!$backupData || !isset($backupData['encrypted'])) {
                return ['success' => false, 'valid' => false, 'error' => 'Invalid backup file format'];
            }

            // Try to decrypt
            $sqlDump = KeyEncryption::decrypt($backupData['encrypted']);

            // Verify it contains SQL
            $valid = strpos($sqlDump, 'CREATE TABLE') !== false || strpos($sqlDump, 'INSERT INTO') !== false;

            KeyEncryption::secureClear($sqlDump);

            return [
                'success' => true,
                'valid' => $valid,
                'version' => $backupData['version'] ?? 'unknown',
                'created_at' => $backupData['created_at'] ?? 'unknown'
            ];

        } catch (Exception $e) {
            return ['success' => false, 'valid' => false, 'error' => $e->getMessage()];
        }
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
        try {
            // Update in-memory setting
            $this->currentUser->set('autoBackupEnabled', $enabled);

            // Persist to config file
            $configFile = '/etc/eiou/defaultconfig.json';
            $config = [];
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true) ?? [];
            }
            $config['autoBackupEnabled'] = $enabled;
            if (file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX) === false) {
                throw new Exception('Failed to write configuration file');
            }

            SecureLogger::info("Auto backup " . ($enabled ? 'enabled' : 'disabled'));

            return ['success' => true, 'enabled' => $enabled];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
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

    public function handleBackupCommand(array $argv, $output): void
    {
        // argv format: ['eiou', 'backup', 'subcommand', 'arg1', 'arg2', ...]
        $subcommand = $argv[2] ?? 'help';

        switch ($subcommand) {
            case 'create':
                $customName = $argv[3] ?? null;
                $result = $this->createBackup($customName);
                if ($result['success']) {
                    $output->success(
                        "Backup created: {$result['filename']} ({$this->formatBytes($result['size'])})",
                        [
                            'filename' => $result['filename'],
                            'size' => $result['size'],
                            'size_human' => $this->formatBytes($result['size'])
                        ],
                        'Backup created successfully'
                    );
                } else {
                    $output->error($result['error']);
                }
                break;

            case 'restore':
                $filename = $argv[3] ?? null;
                $confirm = ($argv[4] ?? '') === '--confirm';

                if (!$filename) {
                    $output->error('Usage: backup restore <filename> --confirm');
                    return;
                }

                if (!$confirm) {
                    $output->info(
                        'WARNING: This will overwrite all current database data! Add --confirm to proceed.',
                        ['requires_confirmation' => true]
                    );
                    return;
                }

                $result = $this->restoreBackup($filename, true);
                if ($result['success']) {
                    $output->success('Backup restored successfully', $result);
                } else {
                    $output->error($result['error']);
                }
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
                    $output->error('Usage: backup delete <filename>');
                    return;
                }
                $result = $this->deleteBackup($filename);
                if ($result['success']) {
                    $output->success("Backup deleted: {$filename}", $result);
                } else {
                    $output->error($result['error']);
                }
                break;

            case 'verify':
                $filename = $argv[3] ?? null;
                if (!$filename) {
                    $output->error('Usage: backup verify <filename>');
                    return;
                }
                $result = $this->verifyBackup($filename);
                if ($result['success'] && $result['valid']) {
                    $output->success("Backup verified: {$filename} is valid", $result);
                } elseif ($result['success'] && !$result['valid']) {
                    $output->info("Backup verification failed: {$filename} appears corrupted", $result);
                } else {
                    $output->error($result['error'] ?? 'Verification failed');
                }
                break;

            case 'enable':
                $result = $this->setAutoBackupEnabled(true);
                if ($result['success']) {
                    $output->success('Automatic backups enabled', $result);
                } else {
                    $output->error($result['error']);
                }
                break;

            case 'disable':
                $result = $this->setAutoBackupEnabled(false);
                if ($result['success']) {
                    $output->success('Automatic backups disabled', $result);
                } else {
                    $output->error($result['error']);
                }
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
}
