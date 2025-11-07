<?php

namespace EIOU\Services\Cleanup;

use PDO;
use Exception;
use EIOU\Services\Logging\Logger;

/**
 * RecoveryManager - Handles recovery from unclean shutdowns
 *
 * This class detects unclean shutdowns on startup and performs
 * recovery operations to restore system consistency.
 */
class RecoveryManager
{
    private Logger $logger;
    private PDO $pdo;
    private string $dataDir;
    private string $lockDir;
    private string $recoveryLogPath;
    private array $recoveryStats = [
        'unclean_shutdown_detected' => false,
        'orphaned_resources' => [],
        'recovered_messages' => 0,
        'cleaned_locks' => 0,
        'errors' => []
    ];

    // Recovery timeout in seconds
    private const RECOVERY_TIMEOUT = 60;
    private const LOCK_STALE_THRESHOLD = 300; // 5 minutes
    private const SHUTDOWN_MARKER_FILE = '/tmp/eiou_shutdown_marker';

    public function __construct(
        Logger $logger,
        PDO $pdo,
        string $dataDir = '/app/data',
        string $lockDir = '/tmp/eiou_locks'
    ) {
        $this->logger = $logger;
        $this->pdo = $pdo;
        $this->dataDir = $dataDir;
        $this->lockDir = $lockDir;
        $this->recoveryLogPath = $dataDir . '/recovery.log';

        // Ensure directories exist
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0755, true);
        }
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    /**
     * Perform startup recovery check and operations
     */
    public function performStartupRecovery(): array
    {
        $this->logger->info("Starting recovery manager startup check");
        $startTime = microtime(true);

        try {
            // Step 1: Detect if previous shutdown was unclean
            $uncleanShutdown = $this->detectUncleanShutdown();
            $this->recoveryStats['unclean_shutdown_detected'] = $uncleanShutdown;

            if ($uncleanShutdown) {
                $this->logger->warning("Unclean shutdown detected - initiating recovery procedures");
                $this->logRecoveryEvent("Unclean shutdown detected");

                // Step 2: Recover orphaned resources
                $this->recoverOrphanedResources();

                // Step 3: Restore interrupted message processing
                $this->restoreInterruptedMessages();

                // Step 4: Clean up stale locks
                $this->cleanStaleLocks();

                // Step 5: Verify database integrity
                $this->verifyDatabaseIntegrity();

                // Step 6: Clean up temporary files
                $this->cleanupOrphanedTempFiles();

                $this->logger->info("Recovery procedures completed successfully");
                $this->logRecoveryEvent("Recovery completed successfully");
            } else {
                $this->logger->info("Clean startup detected - no recovery needed");
            }

            // Mark this as a clean startup
            $this->markCleanStartup();

            $duration = microtime(true) - $startTime;
            $this->recoveryStats['duration'] = $duration;
            $this->recoveryStats['success'] = true;

            $this->logger->info(sprintf(
                "Startup recovery check completed in %.2f seconds",
                $duration
            ));

        } catch (Exception $e) {
            $this->logger->error("Error during startup recovery: " . $e->getMessage());
            $this->recoveryStats['errors'][] = [
                'type' => 'recovery_failed',
                'error' => $e->getMessage()
            ];
            $this->recoveryStats['success'] = false;
        }

        return $this->recoveryStats;
    }

    /**
     * Detect if previous shutdown was unclean
     */
    private function detectUncleanShutdown(): bool
    {
        // Check for shutdown marker file
        // If it exists, the last shutdown was unclean
        if (file_exists(self::SHUTDOWN_MARKER_FILE)) {
            $markerAge = time() - filemtime(self::SHUTDOWN_MARKER_FILE);
            $this->logger->warning("Found stale shutdown marker (age: {$markerAge}s)");
            return true;
        }

        // Check for stale lock files
        if ($this->hasStaleLocks()) {
            $this->logger->warning("Found stale lock files");
            return true;
        }

        // Check for interrupted message processing
        if ($this->hasInterruptedMessages()) {
            $this->logger->warning("Found interrupted message processing");
            return true;
        }

        // Check process registry for orphaned entries
        if ($this->hasOrphanedProcessEntries()) {
            $this->logger->warning("Found orphaned process entries");
            return true;
        }

        return false;
    }

    /**
     * Check for stale lock files
     */
    private function hasStaleLocks(): bool
    {
        if (!is_dir($this->lockDir)) {
            return false;
        }

        $staleLocks = [];
        $currentTime = time();

        foreach (glob($this->lockDir . '/*.lock') as $lockFile) {
            $lockAge = $currentTime - filemtime($lockFile);

            if ($lockAge > self::LOCK_STALE_THRESHOLD) {
                // Check if process is still running
                $pid = @file_get_contents($lockFile);
                if ($pid && !$this->isProcessRunning($pid)) {
                    $staleLocks[] = $lockFile;
                }
            }
        }

        return !empty($staleLocks);
    }

    /**
     * Check if a process is still running
     */
    private function isProcessRunning($pid): bool
    {
        if (!$pid || !is_numeric($pid)) {
            return false;
        }

        // Use posix_kill with signal 0 to check if process exists
        return @posix_kill(intval($pid), 0);
    }

    /**
     * Check for interrupted message processing
     */
    private function hasInterruptedMessages(): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count
                FROM messages
                WHERE status = 'processing'
                AND updated_at < datetime('now', '-5 minutes')
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result['count'] > 0;
        } catch (Exception $e) {
            $this->logger->warning("Could not check interrupted messages: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check for orphaned process entries
     */
    private function hasOrphanedProcessEntries(): bool
    {
        try {
            // Check if process_registry table exists
            $stmt = $this->pdo->query("
                SELECT name FROM sqlite_master
                WHERE type='table' AND name='process_registry'
            ");

            if (!$stmt->fetch()) {
                return false; // Table doesn't exist yet
            }

            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count
                FROM process_registry
                WHERE status = 'running'
                AND updated_at < datetime('now', '-5 minutes')
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result['count'] > 0;
        } catch (Exception $e) {
            $this->logger->warning("Could not check orphaned processes: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Recover orphaned resources
     */
    private function recoverOrphanedResources(): void
    {
        $this->logger->info("Recovering orphaned resources");

        try {
            // Check if process_registry table exists
            $stmt = $this->pdo->query("
                SELECT name FROM sqlite_master
                WHERE type='table' AND name='process_registry'
            ");

            if (!$stmt->fetch()) {
                $this->logger->info("Process registry table doesn't exist yet, skipping");
                return;
            }

            // Find orphaned processes
            $stmt = $this->pdo->prepare("
                SELECT * FROM process_registry
                WHERE status = 'running'
                AND updated_at < datetime('now', '-5 minutes')
            ");
            $stmt->execute();
            $orphanedProcesses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($orphanedProcesses as $process) {
                $pid = $process['pid'];

                if (!$this->isProcessRunning($pid)) {
                    $this->logger->info("Marking orphaned process as terminated: PID $pid");

                    $updateStmt = $this->pdo->prepare("
                        UPDATE process_registry
                        SET status = 'terminated',
                            updated_at = datetime('now'),
                            notes = 'Recovered from unclean shutdown'
                        WHERE pid = ?
                    ");
                    $updateStmt->execute([$pid]);

                    $this->recoveryStats['orphaned_resources'][] = [
                        'type' => 'process',
                        'pid' => $pid,
                        'name' => $process['name'] ?? 'unknown'
                    ];
                }
            }

        } catch (Exception $e) {
            $this->logger->error("Error recovering orphaned resources: " . $e->getMessage());
            $this->recoveryStats['errors'][] = [
                'type' => 'orphaned_resources',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Restore interrupted message processing
     */
    private function restoreInterruptedMessages(): void
    {
        $this->logger->info("Restoring interrupted message processing");

        try {
            // Find messages stuck in processing state
            $stmt = $this->pdo->prepare("
                SELECT * FROM messages
                WHERE status = 'processing'
                AND updated_at < datetime('now', '-5 minutes')
            ");
            $stmt->execute();
            $interruptedMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($interruptedMessages as $message) {
                $messageId = $message['id'];

                $this->logger->info("Resetting interrupted message: $messageId");

                // Reset to pending for retry
                $updateStmt = $this->pdo->prepare("
                    UPDATE messages
                    SET status = 'pending',
                        updated_at = datetime('now'),
                        retry_count = COALESCE(retry_count, 0) + 1
                    WHERE id = ?
                ");
                $updateStmt->execute([$messageId]);

                $this->recoveryStats['recovered_messages']++;
            }

            if ($this->recoveryStats['recovered_messages'] > 0) {
                $this->logger->info("Recovered {$this->recoveryStats['recovered_messages']} interrupted messages");
            }

        } catch (Exception $e) {
            $this->logger->error("Error restoring interrupted messages: " . $e->getMessage());
            $this->recoveryStats['errors'][] = [
                'type' => 'message_recovery',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Clean up stale locks
     */
    private function cleanStaleLocks(): void
    {
        $this->logger->info("Cleaning stale lock files");

        if (!is_dir($this->lockDir)) {
            return;
        }

        $currentTime = time();
        $cleanedLocks = 0;

        foreach (glob($this->lockDir . '/*.lock') as $lockFile) {
            try {
                $lockAge = $currentTime - filemtime($lockFile);

                if ($lockAge > self::LOCK_STALE_THRESHOLD) {
                    // Check if process is still running
                    $pid = @file_get_contents($lockFile);

                    if (!$pid || !$this->isProcessRunning($pid)) {
                        if (@unlink($lockFile)) {
                            $this->logger->info("Removed stale lock: $lockFile (PID: $pid, age: {$lockAge}s)");
                            $cleanedLocks++;
                        }
                    }
                }
            } catch (Exception $e) {
                $this->logger->warning("Error cleaning lock $lockFile: " . $e->getMessage());
            }
        }

        $this->recoveryStats['cleaned_locks'] = $cleanedLocks;

        if ($cleanedLocks > 0) {
            $this->logger->info("Cleaned $cleanedLocks stale lock files");
        }
    }

    /**
     * Verify database integrity
     */
    private function verifyDatabaseIntegrity(): void
    {
        $this->logger->info("Verifying database integrity");

        try {
            // Run SQLite integrity check
            $stmt = $this->pdo->query("PRAGMA integrity_check");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result && isset($result['integrity_check'])) {
                if ($result['integrity_check'] === 'ok') {
                    $this->logger->info("Database integrity check passed");
                } else {
                    $this->logger->error("Database integrity check failed: " . $result['integrity_check']);
                    $this->recoveryStats['errors'][] = [
                        'type' => 'database_integrity',
                        'error' => $result['integrity_check']
                    ];
                }
            }

            // Check for database corruption
            $stmt = $this->pdo->query("PRAGMA quick_check");
            $quickCheck = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($quickCheck && isset($quickCheck['quick_check'])) {
                if ($quickCheck['quick_check'] === 'ok') {
                    $this->logger->info("Database quick check passed");
                } else {
                    $this->logger->warning("Database quick check issues: " . $quickCheck['quick_check']);
                }
            }

        } catch (Exception $e) {
            $this->logger->error("Error verifying database integrity: " . $e->getMessage());
            $this->recoveryStats['errors'][] = [
                'type' => 'database_check',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Clean up orphaned temporary files
     */
    private function cleanupOrphanedTempFiles(): void
    {
        $this->logger->info("Cleaning orphaned temporary files");

        $tempDir = sys_get_temp_dir();
        $pattern = $tempDir . '/eiou_*';
        $currentPid = getmypid();
        $cleaned = 0;

        foreach (glob($pattern) as $file) {
            try {
                // Extract PID from filename (format: eiou_{pid}_*)
                if (preg_match('/eiou_(\d+)_/', basename($file), $matches)) {
                    $filePid = $matches[1];

                    // Don't delete files from current process
                    if ($filePid == $currentPid) {
                        continue;
                    }

                    // Check if process is still running
                    if (!$this->isProcessRunning($filePid)) {
                        if (@unlink($file)) {
                            $this->logger->debug("Removed orphaned temp file: $file (PID: $filePid)");
                            $cleaned++;
                        }
                    }
                }
            } catch (Exception $e) {
                $this->logger->warning("Error cleaning temp file $file: " . $e->getMessage());
            }
        }

        if ($cleaned > 0) {
            $this->logger->info("Cleaned $cleaned orphaned temporary files");
        }
    }

    /**
     * Mark clean startup by creating shutdown marker
     */
    private function markCleanStartup(): void
    {
        try {
            file_put_contents(self::SHUTDOWN_MARKER_FILE, getmypid());
            $this->logger->debug("Created shutdown marker for clean tracking");
        } catch (Exception $e) {
            $this->logger->warning("Could not create shutdown marker: " . $e->getMessage());
        }
    }

    /**
     * Mark clean shutdown by removing shutdown marker
     */
    public function markCleanShutdown(): void
    {
        try {
            if (file_exists(self::SHUTDOWN_MARKER_FILE)) {
                @unlink(self::SHUTDOWN_MARKER_FILE);
                $this->logger->debug("Removed shutdown marker for clean shutdown");
            }
        } catch (Exception $e) {
            $this->logger->warning("Could not remove shutdown marker: " . $e->getMessage());
        }
    }

    /**
     * Log recovery event to recovery log file
     */
    private function logRecoveryEvent(string $message): void
    {
        try {
            $timestamp = date('Y-m-d H:i:s');
            $logEntry = "[$timestamp] $message\n";
            file_put_contents($this->recoveryLogPath, $logEntry, FILE_APPEND);
        } catch (Exception $e) {
            $this->logger->warning("Could not write to recovery log: " . $e->getMessage());
        }
    }

    /**
     * Get recovery statistics
     */
    public function getRecoveryStats(): array
    {
        return $this->recoveryStats;
    }

    /**
     * Get recovery log contents
     */
    public function getRecoveryLog(int $lines = 50): string
    {
        if (!file_exists($this->recoveryLogPath)) {
            return "No recovery log found\n";
        }

        try {
            $logContent = file_get_contents($this->recoveryLogPath);
            $logLines = explode("\n", trim($logContent));

            // Return last N lines
            $recentLines = array_slice($logLines, -$lines);
            return implode("\n", $recentLines);
        } catch (Exception $e) {
            return "Error reading recovery log: " . $e->getMessage();
        }
    }
}