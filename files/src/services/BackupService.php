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

            // Execute mysqldump
            $cmd = sprintf(
                'mysqldump --single-transaction --routines --triggers --quick -h%s -u%s -p%s %s 2>&1',
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName)
            );

            $sqlDump = shell_exec($cmd);

            if (empty($sqlDump) || strpos($sqlDump, 'CREATE TABLE') === false) {
                return ['success' => false, 'error' => 'mysqldump failed or returned empty result'];
            }

            // Encrypt the SQL dump
            $encrypted = KeyEncryption::encrypt($sqlDump);

            // Clear sensitive data
            KeyEncryption::secureClear($sqlDump);

            // Create backup file with metadata
            $backupData = [
                'version' => '1.0',
                'created_at' => date('c'),
                'hostname' => $this->currentUser->getHostname() ?? 'unknown',
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

            // Execute mysql import
            $cmd = sprintf(
                'mysql -h%s -u%s -p%s %s < %s 2>&1',
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName),
                escapeshellarg($tempFile)
            );

            $output = shell_exec($cmd);
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
            $this->currentUser->set('autoBackupEnabled', $enabled);
            $this->currentUser->saveConfig();

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

    public function handleBackupCommand(array $args, $output): void
    {
        $subcommand = $args[0] ?? 'help';

        switch ($subcommand) {
            case 'create':
                $customName = $args[1] ?? null;
                $result = $this->createBackup($customName);
                if ($result['success']) {
                    $output->output([
                        'status' => 'success',
                        'message' => 'Backup created successfully',
                        'filename' => $result['filename'],
                        'size' => $result['size']
                    ]);
                } else {
                    $output->output(['status' => 'error', 'message' => $result['error']]);
                }
                break;

            case 'restore':
                $filename = $args[1] ?? null;
                $confirm = ($args[2] ?? '') === '--confirm';

                if (!$filename) {
                    $output->output(['status' => 'error', 'message' => 'Usage: backup restore <filename> --confirm']);
                    return;
                }

                if (!$confirm) {
                    $output->output([
                        'status' => 'warning',
                        'message' => 'WARNING: This will overwrite all current database data! Add --confirm to proceed.'
                    ]);
                    return;
                }

                $result = $this->restoreBackup($filename, true);
                $output->output($result['success']
                    ? ['status' => 'success', 'message' => 'Backup restored successfully']
                    : ['status' => 'error', 'message' => $result['error']]);
                break;

            case 'list':
                $backups = $this->listBackups();
                $output->output([
                    'status' => 'success',
                    'count' => count($backups),
                    'backups' => $backups
                ]);
                break;

            case 'delete':
                $filename = $args[1] ?? null;
                if (!$filename) {
                    $output->output(['status' => 'error', 'message' => 'Usage: backup delete <filename>']);
                    return;
                }
                $result = $this->deleteBackup($filename);
                $output->output($result['success']
                    ? ['status' => 'success', 'message' => 'Backup deleted']
                    : ['status' => 'error', 'message' => $result['error']]);
                break;

            case 'verify':
                $filename = $args[1] ?? null;
                if (!$filename) {
                    $output->output(['status' => 'error', 'message' => 'Usage: backup verify <filename>']);
                    return;
                }
                $result = $this->verifyBackup($filename);
                $output->output($result);
                break;

            case 'enable':
                $result = $this->setAutoBackupEnabled(true);
                $output->output($result['success']
                    ? ['status' => 'success', 'message' => 'Automatic backups enabled']
                    : ['status' => 'error', 'message' => $result['error']]);
                break;

            case 'disable':
                $result = $this->setAutoBackupEnabled(false);
                $output->output($result['success']
                    ? ['status' => 'success', 'message' => 'Automatic backups disabled']
                    : ['status' => 'error', 'message' => $result['error']]);
                break;

            case 'status':
                $output->output($this->getBackupStatus());
                break;

            case 'cleanup':
                $result = $this->cleanupOldBackups();
                $output->output([
                    'status' => 'success',
                    'message' => "Cleaned up {$result['deleted_count']} old backup(s)",
                    'deleted_files' => $result['deleted_files']
                ]);
                break;

            case 'help':
            default:
                $output->output([
                    'status' => 'help',
                    'commands' => [
                        'backup create [name]' => 'Create a new backup (optional custom name)',
                        'backup restore <file> --confirm' => 'Restore from backup (requires --confirm)',
                        'backup list' => 'List all backups',
                        'backup delete <file>' => 'Delete a backup',
                        'backup verify <file>' => 'Verify backup integrity',
                        'backup enable' => 'Enable automatic daily backups',
                        'backup disable' => 'Disable automatic daily backups',
                        'backup status' => 'Show backup status and settings',
                        'backup cleanup' => 'Remove old backups (keep 3 most recent)'
                    ]
                ]);
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
