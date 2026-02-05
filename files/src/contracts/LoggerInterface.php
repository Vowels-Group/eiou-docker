<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Logger Interface
 *
 * Defines the contract for application logging. Inspired by PSR-3 LoggerInterface
 * with additional methods for exception logging.
 *
 * Two logging backends exist in the application:
 * - File logging (SecureLogger): Always active, writes to /var/log/eiou/app.log
 *   with automatic sensitive data masking. Used for operational logging.
 * - Database logging (DebugService): Only active when APP_DEBUG is enabled.
 *   Writes to the debug table for the GUI debug panel. Used for development.
 *
 * Implementations should route to the appropriate backend(s) based on context.
 *
 * @see \Eiou\Utils\Logger          Unified facade implementing this interface
 * @see \Eiou\Utils\SecureLogger    File-based logging backend
 * @see \Eiou\Services\DebugService Database-backed debug logging backend
 */
interface LoggerInterface
{
    /**
     * Log a message at the DEBUG level
     *
     * Detailed diagnostic information for development.
     * Filtered out in production when LOG_LEVEL is INFO or higher.
     *
     * @param string $message Log message
     * @param array  $context Additional context data (sensitive values are masked automatically)
     * @return void
     */
    public function debug(string $message, array $context = []): void;

    /**
     * Log a message at the INFO level
     *
     * Interesting events (user login, transaction processed, sync completed).
     *
     * @param string $message Log message
     * @param array  $context Additional context data
     * @return void
     */
    public function info(string $message, array $context = []): void;

    /**
     * Log a message at the WARNING level
     *
     * Exceptional occurrences that are not errors (deprecated API usage,
     * poor use of an API, undesirable things that are not necessarily wrong).
     *
     * @param string $message Log message
     * @param array  $context Additional context data
     * @return void
     */
    public function warning(string $message, array $context = []): void;

    /**
     * Log a message at the ERROR level
     *
     * Runtime errors that do not require immediate action but should be
     * logged and monitored (failed API calls, missing data, timeouts).
     *
     * @param string $message Log message
     * @param array  $context Additional context data
     * @return void
     */
    public function error(string $message, array $context = []): void;

    /**
     * Log a message at the CRITICAL level
     *
     * Critical conditions (application component unavailable, unexpected exception,
     * database connection failure, data corruption).
     *
     * @param string $message Log message
     * @param array  $context Additional context data
     * @return void
     */
    public function critical(string $message, array $context = []): void;

    /**
     * Log a message at the given level
     *
     * @param string $level   Log level (DEBUG, INFO, WARNING, ERROR, CRITICAL)
     * @param string $message Log message
     * @param array  $context Additional context data
     * @return void
     */
    public function log(string $level, string $message, array $context = []): void;

    /**
     * Log an exception with stack trace
     *
     * Logs the exception class, message, file, line, and masked stack trace.
     *
     * @param \Throwable $e       The exception to log
     * @param array      $context Additional context data (default: [])
     * @param string     $level   Log level (default: ERROR)
     * @return void
     */
    public function logException(\Throwable $e, array $context = [], string $level = 'ERROR'): void;
}
