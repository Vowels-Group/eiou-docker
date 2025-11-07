<?php

namespace EIOU\Services\Cleanup;

use PDO;
use Exception;
use EIOU\Services\Logging\Logger;

/**
 * ResourceCleaner - Handles cleanup of system resources during shutdown
 *
 * This class manages the orderly cleanup of all system resources
 * following the critical sequence to prevent data loss and corruption.
 */
class ResourceCleaner
{
    private Logger $logger;
    private array $activeConnections = [];
    private array $fileHandles = [];
    private array $lockFiles = [];
    private array $tempFiles = [];
    private array $buffers = [];
    private bool $cleanupInProgress = false;
    private array $cleanupStats = [
        'connections_closed' => 0,
        'files_closed' => 0,
        'locks_released' => 0,
        'temp_files_deleted' => 0,
        'buffers_flushed' => 0,
        'errors' => []
    ];

    // Cleanup timeout in seconds
    private const CLEANUP_TIMEOUT = 30;
    private const TRANSACTION_WAIT_TIMEOUT = 10;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Register a database connection for cleanup
     */
    public function registerConnection(string $id, PDO $connection): void
    {
        $this->activeConnections[$id] = [
            'connection' => $connection,
            'registered_at' => time()
        ];
    }

    /**
     * Register a file handle for cleanup
     */
    public function registerFileHandle(string $path, $handle): void
    {
        $this->fileHandles[$path] = [
            'handle' => $handle,
            'opened_at' => time()
        ];
    }

    /**
     * Register a lock file for cleanup
     */
    public function registerLockFile(string $path): void
    {
        $this->lockFiles[$path] = [
            'created_at' => time(),
            'pid' => getmypid()
        ];
    }

    /**
     * Register a temporary file for cleanup
     */
    public function registerTempFile(string $path): void
    {
        $this->tempFiles[$path] = [
            'created_at' => time()
        ];
    }

    /**
     * Register a buffer for flushing
     */
    public function registerBuffer(string $id, callable $flushCallback): void
    {
        $this->buffers[$id] = [
            'callback' => $flushCallback,
            'registered_at' => time()
        ];
    }

    /**
     * Execute full cleanup sequence following critical order
     */
    public function executeCleanup(): array
    {
        if ($this->cleanupInProgress) {
            $this->logger->warning("Cleanup already in progress, skipping duplicate request");
            return $this->cleanupStats;
        }

        $this->cleanupInProgress = true;
        $startTime = microtime(true);

        $this->logger->info("Starting resource cleanup sequence");

        try {
            // Step 1: Complete pending transactions
            $this->completePendingTransactions();

            // Step 2: Flush write buffers to disk
            $this->flushBuffers();

            // Step 3: Release file locks
            $this->releaseFileLocks();

            // Step 4: Close database connections
            $this->closeDatabaseConnections();

            // Step 5: Clear temporary files
            $this->clearTemporaryFiles();

            // Step 6: Close file handles (after temp files to avoid issues)
            $this->closeFileHandles();

            // Step 7: Memory cleanup
            $this->performMemoryCleanup();

            $duration = microtime(true) - $startTime;
            $this->logger->info(sprintf(
                "Resource cleanup completed in %.2f seconds",
                $duration
            ));

            $this->cleanupStats['duration'] = $duration;
            $this->cleanupStats['success'] = empty($this->cleanupStats['errors']);

        } catch (Exception $e) {
            $this->logger->error("Critical error during cleanup: " . $e->getMessage());
            $this->cleanupStats['errors'][] = [
                'type' => 'critical',
                'message' => $e->getMessage()
            ];
        } finally {
            $this->cleanupInProgress = false;
        }

        return $this->cleanupStats;
    }

    /**
     * Complete pending database transactions
     */
    private function completePendingTransactions(): void
    {
        $this->logger->info("Completing pending transactions");
        $timeout = time() + self::TRANSACTION_WAIT_TIMEOUT;

        foreach ($this->activeConnections as $id => $connInfo) {
            try {
                $connection = $connInfo['connection'];

                if ($connection->inTransaction()) {
                    $this->logger->info("Waiting for transaction to complete: $id");

                    // Give transaction time to complete
                    $waited = 0;
                    while ($connection->inTransaction() && time() < $timeout) {
                        usleep(100000); // 100ms
                        $waited += 100;

                        if ($waited >= 1000) {
                            $this->logger->warning("Transaction still pending after {$waited}ms: $id");
                            $waited = 0;
                        }
                    }

                    // Force rollback if still in transaction
                    if ($connection->inTransaction()) {
                        $this->logger->warning("Force rolling back transaction: $id");
                        $connection->rollBack();
                    }
                }
            } catch (Exception $e) {
                $this->logger->error("Error handling transaction for $id: " . $e->getMessage());
                $this->cleanupStats['errors'][] = [
                    'type' => 'transaction',
                    'connection' => $id,
                    'error' => $e->getMessage()
                ];
            }
        }
    }

    /**
     * Flush all registered buffers to disk
     */
    private function flushBuffers(): void
    {
        $this->logger->info("Flushing write buffers");

        foreach ($this->buffers as $id => $bufferInfo) {
            try {
                $callback = $bufferInfo['callback'];
                $callback();
                $this->cleanupStats['buffers_flushed']++;
                $this->logger->debug("Flushed buffer: $id");
            } catch (Exception $e) {
                $this->logger->error("Error flushing buffer $id: " . $e->getMessage());
                $this->cleanupStats['errors'][] = [
                    'type' => 'buffer',
                    'buffer_id' => $id,
                    'error' => $e->getMessage()
                ];
            }
        }

        // Also flush PHP output buffers
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    /**
     * Release all file locks
     */
    private function releaseFileLocks(): void
    {
        $this->logger->info("Releasing file locks");

        foreach ($this->lockFiles as $path => $lockInfo) {
            try {
                if (file_exists($path)) {
                    // Check if lock is owned by this process
                    $lockPid = @file_get_contents($path);
                    if ($lockPid == $lockInfo['pid']) {
                        if (@unlink($path)) {
                            $this->cleanupStats['locks_released']++;
                            $this->logger->debug("Released lock: $path");
                        } else {
                            throw new Exception("Failed to delete lock file");
                        }
                    } else {
                        $this->logger->warning("Lock not owned by this process: $path (owner PID: $lockPid)");
                    }
                }
            } catch (Exception $e) {
                $this->logger->error("Error releasing lock $path: " . $e->getMessage());
                $this->cleanupStats['errors'][] = [
                    'type' => 'lock',
                    'file' => $path,
                    'error' => $e->getMessage()
                ];
            }
        }
    }

    /**
     * Close all database connections
     */
    private function closeDatabaseConnections(): void
    {
        $this->logger->info("Closing database connections");

        foreach ($this->activeConnections as $id => $connInfo) {
            try {
                // PDO connections close when object is destroyed
                // We null the reference to trigger destruction
                $this->activeConnections[$id]['connection'] = null;
                $this->cleanupStats['connections_closed']++;
                $this->logger->debug("Closed database connection: $id");
            } catch (Exception $e) {
                $this->logger->error("Error closing connection $id: " . $e->getMessage());
                $this->cleanupStats['errors'][] = [
                    'type' => 'connection',
                    'connection_id' => $id,
                    'error' => $e->getMessage()
                ];
            }
        }

        // Clear the array
        $this->activeConnections = [];
    }

    /**
     * Close all open file handles
     */
    private function closeFileHandles(): void
    {
        $this->logger->info("Closing file handles");

        foreach ($this->fileHandles as $path => $handleInfo) {
            try {
                $handle = $handleInfo['handle'];
                if (is_resource($handle)) {
                    // Flush any pending writes
                    @fflush($handle);

                    // Close the handle
                    if (@fclose($handle)) {
                        $this->cleanupStats['files_closed']++;
                        $this->logger->debug("Closed file handle: $path");
                    } else {
                        throw new Exception("Failed to close file handle");
                    }
                }
            } catch (Exception $e) {
                $this->logger->error("Error closing file $path: " . $e->getMessage());
                $this->cleanupStats['errors'][] = [
                    'type' => 'file_handle',
                    'file' => $path,
                    'error' => $e->getMessage()
                ];
            }
        }

        // Clear the array
        $this->fileHandles = [];
    }

    /**
     * Clear temporary files
     */
    private function clearTemporaryFiles(): void
    {
        $this->logger->info("Clearing temporary files");

        foreach ($this->tempFiles as $path => $fileInfo) {
            try {
                if (file_exists($path)) {
                    if (@unlink($path)) {
                        $this->cleanupStats['temp_files_deleted']++;
                        $this->logger->debug("Deleted temp file: $path");
                    } else {
                        throw new Exception("Failed to delete temp file");
                    }
                }
            } catch (Exception $e) {
                $this->logger->error("Error deleting temp file $path: " . $e->getMessage());
                $this->cleanupStats['errors'][] = [
                    'type' => 'temp_file',
                    'file' => $path,
                    'error' => $e->getMessage()
                ];
            }
        }

        // Also clean system temp directory for this process
        $this->cleanSystemTempFiles();
    }

    /**
     * Clean system temporary files created by this process
     */
    private function cleanSystemTempFiles(): void
    {
        $tempDir = sys_get_temp_dir();
        $pid = getmypid();
        $pattern = $tempDir . '/eiou_' . $pid . '_*';

        foreach (glob($pattern) as $file) {
            try {
                if (@unlink($file)) {
                    $this->cleanupStats['temp_files_deleted']++;
                    $this->logger->debug("Deleted system temp file: $file");
                }
            } catch (Exception $e) {
                $this->logger->warning("Could not delete system temp file $file: " . $e->getMessage());
            }
        }
    }

    /**
     * Perform memory cleanup
     */
    private function performMemoryCleanup(): void
    {
        $this->logger->info("Performing memory cleanup");

        $beforeMemory = memory_get_usage(true);

        // Clear internal arrays
        $this->activeConnections = [];
        $this->fileHandles = [];
        $this->lockFiles = [];
        $this->tempFiles = [];
        $this->buffers = [];

        // Force garbage collection
        gc_collect_cycles();

        $afterMemory = memory_get_usage(true);
        $freed = $beforeMemory - $afterMemory;

        if ($freed > 0) {
            $this->logger->info(sprintf(
                "Freed %.2f MB of memory",
                $freed / 1024 / 1024
            ));
        }

        $this->cleanupStats['memory_freed'] = $freed;
    }

    /**
     * Emergency cleanup - called on fatal errors
     */
    public function emergencyCleanup(): void
    {
        $this->logger->critical("Executing emergency cleanup");

        // Try to release critical resources only
        try {
            // Force close database connections
            foreach ($this->activeConnections as $id => $connInfo) {
                $this->activeConnections[$id]['connection'] = null;
            }

            // Release locks
            foreach ($this->lockFiles as $path => $lockInfo) {
                @unlink($path);
            }

            // Flush critical buffers
            foreach ($this->buffers as $id => $bufferInfo) {
                try {
                    $bufferInfo['callback']();
                } catch (Exception $e) {
                    // Ignore errors in emergency mode
                }
            }

        } catch (Exception $e) {
            // Log but don't throw in emergency mode
            $this->logger->critical("Emergency cleanup error: " . $e->getMessage());
        }
    }

    /**
     * Get cleanup statistics
     */
    public function getCleanupStats(): array
    {
        return $this->cleanupStats;
    }

    /**
     * Check if cleanup is in progress
     */
    public function isCleanupInProgress(): bool
    {
        return $this->cleanupInProgress;
    }
}