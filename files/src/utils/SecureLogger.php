<?php

# Copyright 2025 The Vowels Company
/**
 * Secure logging wrapper that masks sensitive data
 * Prevents sensitive information from being logged
 */

// Require Constants for DISPLAY_DATE_FORMAT
require_once __DIR__ . '/../core/Constants.php';

class SecureLogger {
    private static $sensitivePatterns = [
        '/authcode=[^&\s]+/i' => 'authcode=***MASKED***',
        '/password["\']?\s*[:=]\s*["\']?[^"\',\s]+/i' => 'password=***MASKED***',
        '/private[_\s]?key["\']?\s*[:=]\s*["\']?[^"\',\s]+/i' => 'private_key=***MASKED***',
        '/secret["\']?\s*[:=]\s*["\']?[^"\',\s]+/i' => 'secret=***MASKED***',
        '/token["\']?\s*[:=]\s*["\']?[^"\',\s]+/i' => 'token=***MASKED***',
        '/api[_\s]?key["\']?\s*[:=]\s*["\']?[^"\',\s]+/i' => 'api_key=***MASKED***',
        '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***',
        '/\b(?:\d{4}[\s\-]?){3}\d{4}\b/' => '***CREDIT_CARD***',
        '/\b\d{3}-\d{2}-\d{4}\b/' => '***SSN***',
    ];

    private static $logFile = null;
    private static $logLevel = 'INFO';
    private static $levels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];

    /**
     * Initialize logger with file path and level
     *
     * @param string $logFile Path to log file
     * @param string $level Log level (DEBUG, INFO, WARNING, ERROR, CRITICAL)
     */
    public static function init($logFile = null, $level = 'INFO') {
        self::$logFile = $logFile ?: '/var/log/eiou/app.log';
        self::$logLevel = $level;

        // Create log directory if it doesn't exist
        $dir = dirname(self::$logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * Log a message with sensitive data masking
     *
     * @param string $level Log level
     * @param string $message Message to log
     * @param array $context Additional context
     */
    public static function log($level, $message, $context = []) {
        // Check if we should log this level
        if (!isset(self::$levels[$level]) || self::$levels[$level] < self::$levels[self::$logLevel]) {
            return;
        }

        // Mask sensitive data in message
        $maskedMessage = self::maskSensitive($message);

        // Mask sensitive data in context
        $maskedContext = self::maskContext($context);

        // Format log entry with proper microtime
        // Note: date() always returns '000000' for microseconds (PHP limitation)
        // Using microtime() string format to avoid float precision issues
        $datetime = DateTime::createFromFormat('0.u00 U', microtime());
        $timestamp = $datetime->format(Constants::DISPLAY_DATE_FORMAT);
        $pid = getmypid();
        $logEntry = "[$timestamp] [$level] [PID:$pid] $maskedMessage";

        if (!empty($maskedContext)) {
            $logEntry .= " | Context: " . json_encode($maskedContext);
        }

        $logEntry .= PHP_EOL;

        // Write to file
        if (self::$logFile) {
            @file_put_contents(self::$logFile, $logEntry, FILE_APPEND | LOCK_EX);

            // Prune old entries occasionally (1 in 10 chance to avoid performance overhead)
            if (rand(1, 10) === 1) {
                self::pruneLogFile(Constants::LOG_MAX_ENTRIES);
            }
        }

        // Note: Removed error_log() call for ERROR/CRITICAL to prevent terminal output.
        // All logs are written to the log file only.
    }

    /**
     * Mask sensitive data in a string
     *
     * @param string $string String to mask
     * @return string Masked string
     */
    private static function maskSensitive($string) {
        foreach (self::$sensitivePatterns as $pattern => $replacement) {
            $string = preg_replace($pattern, $replacement, $string);
        }
        return $string;
    }

    /**
     * Mask sensitive data in context array
     *
     * @param array $context Context array
     * @return array Masked context
     */
    private static function maskContext($context) {
        $sensitiveKeys = ['password', 'passwd', 'pwd', 'secret', 'token', 'key', 'auth', 'private', 'credential'];

        array_walk_recursive($context, function(&$value, $key) use ($sensitiveKeys) {
            $lowerKey = strtolower($key);
            foreach ($sensitiveKeys as $sensitive) {
                if (strpos($lowerKey, $sensitive) !== false) {
                    $value = '***MASKED***';
                    return;
                }
            }
            // Also mask sensitive patterns in values
            if (is_string($value)) {
                $value = self::maskSensitive($value);
            }
        });

        return $context;
    }

    /**
     * Convenience methods for different log levels
     */
    public static function debug($message, $context = []) {
        self::log('DEBUG', $message, $context);
    }

    public static function info($message, $context = []) {
        self::log('INFO', $message, $context);
    }

    public static function warning($message, $context = []) {
        self::log('WARNING', $message, $context);
    }

    public static function error($message, $context = []) {
        self::log('ERROR', $message, $context);
    }

    public static function critical($message, $context = []) {
        self::log('CRITICAL', $message, $context);
    }

    /**
     * Log an exception with stack trace (masked)
     *
     * @param Exception $e Exception to log
     * @param array|string $contextOrLevel Additional context array OR log level string
     * @param string $level Log level (only used if second param is context array)
     */
    public static function logException($e, $contextOrLevel = 'ERROR', $level = 'ERROR') {
        // Handle backward compatibility - if second param is an array, treat it as context
        if (is_array($contextOrLevel)) {
            $additionalContext = $contextOrLevel;
            $logLevel = $level;
        } else {
            $additionalContext = [];
            $logLevel = $contextOrLevel;
        }

        $message = get_class($e) . ': ' . $e->getMessage();
        $context = array_merge([
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => self::maskSensitive($e->getTraceAsString())
        ], $additionalContext);
        self::log($logLevel, $message, $context);
    }

    /**
     * Rotate log files if they get too large
     */
    public static function rotate() {
        if (!self::$logFile || !file_exists(self::$logFile)) {
            return;
        }

        $maxSize = 10 * 1024 * 1024; // 10MB
        if (filesize(self::$logFile) > $maxSize) {
            $backupFile = self::$logFile . '.' . date('Y-m-d-His');
            rename(self::$logFile, $backupFile);

            // Compress old log
            if (function_exists('gzopen')) {
                $gz = gzopen($backupFile . '.gz', 'w9');
                gzwrite($gz, file_get_contents($backupFile));
                gzclose($gz);
                unlink($backupFile);
            }
        }
    }

    /**
     * Prune log file to keep only the latest N entries
     * Similar to DebugRepository::pruneOldEntries() for database logs
     *
     * @param int|null $keepLines Number of log entries to keep (default Constants::LOG_MAX_ENTRIES)
     */
    public static function pruneLogFile(?int $keepLines = null): void {
        if (!self::$logFile || !file_exists(self::$logFile)) {
            return;
        }

        $keepLines = $keepLines ?? Constants::LOG_MAX_ENTRIES;

        $lines = @file(self::$logFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false || count($lines) <= $keepLines) {
            return;
        }

        // Keep only the last N lines
        $linesToKeep = array_slice($lines, -$keepLines);
        @file_put_contents(self::$logFile, implode(PHP_EOL, $linesToKeep) . PHP_EOL, LOCK_EX);
    }
}