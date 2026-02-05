<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Utils;

use Eiou\Contracts\DebugServiceInterface;
use Eiou\Contracts\LoggerInterface;
use Eiou\Core\Constants;

/**
 * Unified Logger Facade
 *
 * Provides a single entry point for application logging, routing messages to
 * the appropriate backend(s):
 *
 * - File logging (SecureLogger): Always active. Writes to /var/log/eiou/app.log
 *   with automatic sensitive data masking, log rotation, and pruning.
 *
 * - Debug panel logging (DebugService): Only active when APP_DEBUG is enabled
 *   AND the DebugService has been registered. Writes to the database for the
 *   GUI debug panel. This is automatically disabled in production.
 *
 * Usage:
 *
 *   // Static convenience methods (work from earliest startup)
 *   Logger::info('Transaction processed', ['txid' => $txid]);
 *   Logger::error('Connection failed', ['address' => $address]);
 *   Logger::logException($e, ['context' => 'sync']);
 *
 *   // Instance via DI (implements LoggerInterface for testability)
 *   public function __construct(LoggerInterface $logger) {
 *       $this->logger = $logger;
 *   }
 *   $this->logger->info('Processing complete');
 *
 * The Logger is available from the earliest point in startup, before the DI
 * container or database are initialized. Debug panel logging becomes available
 * after registerDebugService() is called during application wiring.
 *
 * @see LoggerInterface         The interface this class implements
 * @see SecureLogger            File-based logging backend (with sensitive data masking)
 * @see DebugServiceInterface   Database-backed debug logging backend
 */
class Logger implements LoggerInterface
{
    private static ?Logger $instance = null;
    private static ?DebugServiceInterface $debugService = null;

    /**
     * Initialize the file logging backend
     *
     * Call this early in startup, before any log messages are written.
     * This initializes SecureLogger with the configured log file and level.
     *
     * @param string|null $logFile Path to log file (default: Constants::LOG_FILE_APP)
     * @param string|null $level   Minimum log level (default: Constants::LOG_LEVEL)
     * @return void
     */
    public static function init(?string $logFile = null, ?string $level = null): void
    {
        SecureLogger::init(
            $logFile ?? Constants::LOG_FILE_APP,
            $level ?? Constants::LOG_LEVEL
        );
    }

    /**
     * Register the debug service for database/debug panel logging
     *
     * Call this after the DI container has wired up the DebugService.
     * When registered, log messages will also be written to the debug
     * database table (subject to the APP_DEBUG flag in DebugService).
     *
     * @param DebugServiceInterface $debugService The debug service instance
     * @return void
     */
    public static function registerDebugService(DebugServiceInterface $debugService): void
    {
        self::$debugService = $debugService;
    }

    /**
     * Get the singleton instance (for DI container registration)
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // =========================================================================
    // LoggerInterface instance methods (for DI injection and testability)
    // =========================================================================

    public function debug(string $message, array $context = []): void
    {
        self::doLog('DEBUG', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        self::doLog('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        self::doLog('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        self::doLog('ERROR', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        self::doLog('CRITICAL', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        self::doLog($level, $message, $context);
    }

    public function logException(\Throwable $e, array|string $contextOrLevel = 'ERROR', string $level = 'ERROR'): void
    {
        // Backward compatibility: second param can be a level string or context array
        if (is_string($contextOrLevel)) {
            $context = [];
            $level = $contextOrLevel;
        } else {
            $context = $contextOrLevel;
        }

        SecureLogger::logException($e, $context, $level);
        self::writeToDebugService(get_class($e) . ': ' . $e->getMessage(), $level);
    }

    // =========================================================================
    // Internal routing
    // =========================================================================

    /**
     * Route a log message to all active backends
     *
     * @param string $level   Log level
     * @param string $message Log message
     * @param array  $context Additional context
     * @return void
     */
    private static function doLog(string $level, string $message, array $context): void
    {
        // Always write to file (with sensitive data masking)
        SecureLogger::log($level, $message, $context);

        // Also write to debug panel database when available
        // (DebugService internally checks APP_DEBUG before writing to DB)
        self::writeToDebugService($message, $level);
    }

    /**
     * Forward a message to the debug service if registered
     *
     * DebugService::output() internally checks Constants::APP_DEBUG before
     * writing to the database, so this is a no-op in production.
     *
     * @param string $message Log message
     * @param string $level   Log level
     * @return void
     */
    private static function writeToDebugService(string $message, string $level): void
    {
        if (self::$debugService === null) {
            return;
        }

        try {
            self::$debugService->output($message, $level);
        } catch (\Throwable $e) {
            // Never let debug panel logging break the application.
            // The file log (SecureLogger) already captured the message.
        }
    }
}
