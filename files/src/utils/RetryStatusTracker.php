<?php
/**
 * Retry Status Tracker
 *
 * Tracks message delivery retry status using temp files for inter-process communication.
 * This allows the GUI to poll for retry status during long-running operations.
 *
 * Copyright 2025
 */

class RetryStatusTracker {
    /**
     * @var string Base directory for status files
     */
    private const STATUS_DIR = '/tmp/eiou_retry_status';

    /**
     * @var string|null Current request ID
     */
    private static ?string $currentRequestId = null;

    /**
     * Initialize the status tracker with a request ID
     *
     * @param string $requestId Unique request identifier
     */
    public static function init(string $requestId): void {
        self::$currentRequestId = $requestId;
        self::ensureStatusDir();
        self::writeStatus([
            'request_id' => $requestId,
            'status' => 'started',
            'attempt' => 0,
            'max_attempts' => 0,
            'message' => 'Initializing...',
            'timestamp' => time()
        ]);
    }

    /**
     * Update retry status
     *
     * @param int $attempt Current attempt number (1-based)
     * @param int $maxAttempts Maximum attempts allowed
     * @param string $status Current status (retrying, success, failed)
     * @param string $message Optional status message
     */
    public static function updateRetryStatus(
        int $attempt,
        int $maxAttempts,
        string $status = 'retrying',
        string $message = ''
    ): void {
        if (self::$currentRequestId === null) {
            return;
        }

        self::writeStatus([
            'request_id' => self::$currentRequestId,
            'status' => $status,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'message' => $message,
            'timestamp' => time()
        ]);
    }

    /**
     * Mark operation as complete
     *
     * @param bool $success Whether the operation succeeded
     * @param string $message Result message
     */
    public static function complete(bool $success, string $message = ''): void {
        if (self::$currentRequestId === null) {
            return;
        }

        self::writeStatus([
            'request_id' => self::$currentRequestId,
            'status' => $success ? 'success' : 'failed',
            'attempt' => 0,
            'max_attempts' => 0,
            'message' => $message,
            'complete' => true,
            'timestamp' => time()
        ]);
    }

    /**
     * Get status for a request ID
     *
     * @param string $requestId Request identifier
     * @return array|null Status data or null if not found
     */
    public static function getStatus(string $requestId): ?array {
        $statusFile = self::getStatusFile($requestId);
        if (!file_exists($statusFile)) {
            return null;
        }

        $content = @file_get_contents($statusFile);
        if ($content === false) {
            return null;
        }

        return json_decode($content, true);
    }

    /**
     * Clean up status file
     *
     * @param string $requestId Request identifier
     */
    public static function cleanup(string $requestId): void {
        $statusFile = self::getStatusFile($requestId);
        if (file_exists($statusFile)) {
            @unlink($statusFile);
        }
    }

    /**
     * Clean up old status files (older than 5 minutes)
     */
    public static function cleanupOld(): void {
        self::ensureStatusDir();
        $files = glob(self::STATUS_DIR . '/retry_*.json');
        $cutoff = time() - 300; // 5 minutes

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Get the current request ID
     *
     * @return string|null
     */
    public static function getCurrentRequestId(): ?string {
        return self::$currentRequestId;
    }

    /**
     * Ensure status directory exists
     */
    private static function ensureStatusDir(): void {
        if (!is_dir(self::STATUS_DIR)) {
            @mkdir(self::STATUS_DIR, 0755, true);
        }
    }

    /**
     * Get status file path
     *
     * @param string $requestId Request identifier
     * @return string File path
     */
    private static function getStatusFile(string $requestId): string {
        // Sanitize request ID to prevent path traversal
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $requestId);
        return self::STATUS_DIR . '/retry_' . $safeId . '.json';
    }

    /**
     * Write status to file
     *
     * @param array $data Status data
     */
    private static function writeStatus(array $data): void {
        $statusFile = self::getStatusFile(self::$currentRequestId);
        @file_put_contents($statusFile, json_encode($data), LOCK_EX);
    }
}
