<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

use Eiou\Cli\CliOutputManager;

/**
 * Backup Service Interface
 *
 * Defines the contract for MariaDB database backup and restore operations.
 * Backups are encrypted using AES-256-GCM with the master key for security.
 */
interface BackupServiceInterface
{
    /**
     * Create a full database backup
     *
     * Exports the MariaDB database using mysqldump, encrypts the backup
     * using AES-256-GCM with the master key, and saves to the backup directory.
     *
     * @param string|null $customFilename Optional custom filename (without extension)
     * @return array Result with 'success', 'filename', 'size', 'error'
     */
    public function createBackup(?string $customFilename = null): array;

    /**
     * Create a backup of archive tables only (currently just payment_requests_archive).
     *
     * Separate from createBackup() so operators/jobs can snapshot cold data
     * independently of the daily live backup cadence. Live backups exclude
     * archive tables, so the two methods are complementary — together they
     * cover the whole database.
     *
     * @param string|null $customFilename Optional custom filename (without extension)
     * @return array Result with 'success', 'filename', 'size', 'path'
     */
    public function createArchiveBackup(?string $customFilename = null): array;

    /**
     * Restore database from a backup file
     *
     * Decrypts the backup file and restores to MariaDB.
     * WARNING: This will overwrite all current database data.
     *
     * @param string $filename Backup filename to restore
     * @param bool $confirmOverwrite Must be true to proceed with restore
     * @return array Result with 'success', 'filename', 'error'
     */
    public function restoreBackup(string $filename, bool $confirmOverwrite = false): array;

    /**
     * List all available backup files
     *
     * @return array Array of backup metadata: filename, size, created_at
     */
    public function listBackups(): array;

    /**
     * Delete a backup file
     *
     * @param string $filename Backup filename to delete
     * @return array Result with 'success', 'filename', 'error'
     */
    public function deleteBackup(string $filename): array;

    /**
     * Verify backup integrity
     *
     * Checks if backup can be decrypted and contains valid SQL.
     *
     * @param string $filename Backup filename to verify
     * @return array Result with 'success', 'valid', 'error'
     */
    public function verifyBackup(string $filename): array;

    /**
     * Check if automated backups are enabled
     *
     * @return bool True if automatic backups are enabled
     */
    public function isAutoBackupEnabled(): bool;

    /**
     * Enable or disable automated backups
     *
     * @param bool $enabled True to enable, false to disable
     * @return array Result with 'success', 'enabled', 'error'
     */
    public function setAutoBackupEnabled(bool $enabled): array;

    /**
     * Get backup status information
     *
     * @return array Status info: enabled, backup_count, last_backup, next_scheduled
     */
    public function getBackupStatus(): array;

    /**
     * Cleanup old backups based on retention policy (keep 3 most recent)
     *
     * @return array Result with 'success', 'deleted_count', 'deleted_files'
     */
    public function cleanupOldBackups(): array;

    /**
     * Handle CLI backup command
     *
     * @param array $args Command arguments
     * @param CliOutputManager $output Output manager
     * @return void
     */
    public function handleCommand(array $args, $output): void;

    /**
     * Search all backups for a specific transaction by txid
     *
     * Iterates through backups (newest first), decrypts each, and searches
     * the mysqldump SQL for the transaction row matching the given txid.
     *
     * @param string $txid The transaction ID to search for
     * @return array|null Result with 'found', 'filename', 'sql_insert', 'backup_created_at', or null if not found
     */
    public function searchTransactionInBackups(string $txid): ?array;

    /**
     * Restore a single transaction from backup by txid
     *
     * Searches all backups for the transaction and inserts it into the live database.
     * Uses INSERT IGNORE to prevent duplicate key errors.
     *
     * @param string $txid The transaction ID to restore
     * @return array Result with 'success', 'filename', 'restored_txid', 'backup_created_at', 'error'
     */
    public function restoreTransactionFromBackup(string $txid): array;
}
