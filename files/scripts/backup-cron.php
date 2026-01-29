#!/usr/bin/env php
<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Automated Database Backup Cron Script
 *
 * Run via cron to perform scheduled backups with cleanup.
 * Usage: php /etc/eiou/scripts/backup-cron.php
 *
 * Exit Codes:
 *   0 - Success
 *   1 - Backup failed
 *   2 - Backup disabled
 *   3 - Initialization failed
 */

// Change to the eiou directory
chdir('/etc/eiou');

// Load application bootstrap (PSR-4 autoloader)
require_once '/etc/eiou/src/bootstrap.php';

use Eiou\Core\Application;
use Eiou\Utils\SecureLogger;

try {
    $app = Application::getInstance();

    // Check if auto backup is enabled
    $backupService = $app->services->getBackupService();

    if (!$backupService->isAutoBackupEnabled()) {
        SecureLogger::info("Cron backup skipped - automatic backups disabled");
        exit(2);
    }

    // Create backup
    SecureLogger::info("Cron backup starting");
    $result = $backupService->createBackup();

    if (!$result['success']) {
        SecureLogger::error("Cron backup failed", ['error' => $result['error'] ?? 'Unknown error']);
        exit(1);
    }

    SecureLogger::info("Cron backup created successfully", [
        'filename' => $result['filename'],
        'size' => $result['size']
    ]);

    // Cleanup old backups (keep only 3 most recent)
    $cleanupResult = $backupService->cleanupOldBackups();

    if ($cleanupResult['deleted_count'] > 0) {
        SecureLogger::info("Old backups cleaned up", [
            'deleted_count' => $cleanupResult['deleted_count'],
            'deleted_files' => $cleanupResult['deleted_files']
        ]);
    }

    exit(0);

} catch (Exception $e) {
    SecureLogger::error("Cron backup exception", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    exit(3);
}
