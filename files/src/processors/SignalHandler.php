<?php
/**
 * Signal Handler for Graceful Shutdown
 *
 * Implements POSIX signal handling for graceful termination of background processes.
 * Ensures proper cleanup of resources and prevents data loss during shutdown.
 *
 * Features:
 * - SIGTERM/SIGINT handling (graceful shutdown)
 * - SIGHUP handling (reload configuration)
 * - SIGQUIT handling (quit with diagnostic info)
 * - Cleanup callbacks for resource release
 * - Timeout protection (force kill after 30s)
 *
 * Copyright 2025
 */

class SignalHandler {
    /**
     * @var bool Running flag
     */
    private static bool $running = true;

    /**
     * @var array Cleanup callbacks
     */
    private static array $cleanupCallbacks = [];

    /**
     * @var bool Signal handlers registered
     */
    private static bool $registered = false;

    /**
     * @var int Shutdown start time
     */
    private static ?int $shutdownStartTime = null;

    /**
     * @var int Maximum shutdown time (seconds)
     */
    private const MAX_SHUTDOWN_TIME = 30;

    /**
     * Register signal handlers
     *
     * @return void
     */
    public static function register(): void {
        if (self::$registered) {
            return; // Already registered
        }

        // Check if pcntl extension is available
        if (!function_exists('pcntl_signal')) {
            error_log('PCNTL extension not available - signal handling disabled');
            return;
        }

        // Register signal handlers
        pcntl_signal(SIGTERM, [self::class, 'handleShutdown']);
        pcntl_signal(SIGINT, [self::class, 'handleShutdown']);
        pcntl_signal(SIGHUP, [self::class, 'handleReload']);
        pcntl_signal(SIGQUIT, [self::class, 'handleQuit']);

        self::$registered = true;
        error_log('Signal handlers registered successfully');
    }

    /**
     * Check if should continue running
     *
     * @return bool True if should continue
     */
    public static function shouldRun(): bool {
        // Process pending signals
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        // Check shutdown timeout
        if (self::$shutdownStartTime !== null) {
            $elapsed = time() - self::$shutdownStartTime;
            if ($elapsed > self::MAX_SHUTDOWN_TIME) {
                error_log('Shutdown timeout exceeded - forcing exit');
                exit(1);
            }
        }

        return self::$running;
    }

    /**
     * Handle shutdown signals (SIGTERM, SIGINT)
     *
     * @param int $signal Signal number
     * @return void
     */
    public static function handleShutdown(int $signal): void {
        $signalName = match($signal) {
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
            default => "Signal $signal"
        };

        error_log("Received $signalName - initiating graceful shutdown");

        // Set running flag to false
        self::$running = false;
        self::$shutdownStartTime = time();

        // Execute cleanup callbacks
        self::executeCleanup();

        // Log shutdown completion
        error_log('Graceful shutdown completed');

        exit(0);
    }

    /**
     * Handle reload signal (SIGHUP)
     *
     * @param int $signal Signal number
     * @return void
     */
    public static function handleReload(int $signal): void {
        error_log('Received SIGHUP - reloading configuration');

        // Trigger configuration reload
        // This can be customized by specific processors
        if (method_exists(get_called_class(), 'reloadConfiguration')) {
            static::reloadConfiguration();
        }
    }

    /**
     * Handle quit signal (SIGQUIT)
     *
     * @param int $signal Signal number
     * @return void
     */
    public static function handleQuit(int $signal): void {
        error_log('Received SIGQUIT - quit with diagnostic info');

        // Output diagnostic information
        self::printDiagnostics();

        // Execute cleanup
        self::executeCleanup();

        exit(0);
    }

    /**
     * Register cleanup callback
     *
     * @param callable $callback Cleanup function
     * @param string $description Callback description
     * @return void
     */
    public static function registerCleanup(callable $callback, string $description = ''): void {
        self::$cleanupCallbacks[] = [
            'callback' => $callback,
            'description' => $description
        ];
    }

    /**
     * Execute all cleanup callbacks
     *
     * @return void
     */
    private static function executeCleanup(): void {
        error_log('Executing cleanup callbacks (' . count(self::$cleanupCallbacks) . ' total)');

        foreach (self::$cleanupCallbacks as $cleanup) {
            try {
                $desc = $cleanup['description'] ?: 'unnamed callback';
                error_log("Executing cleanup: $desc");

                call_user_func($cleanup['callback']);

                error_log("Cleanup completed: $desc");
            } catch (Exception $e) {
                error_log("Cleanup error: " . $e->getMessage());
            }
        }
    }

    /**
     * Print diagnostic information
     *
     * @return void
     */
    private static function printDiagnostics(): void {
        echo "\n=== PROCESS DIAGNOSTICS ===\n";
        echo "PID: " . getmypid() . "\n";
        echo "Running: " . (self::$running ? 'YES' : 'NO') . "\n";
        echo "Cleanup callbacks: " . count(self::$cleanupCallbacks) . "\n";

        if (function_exists('memory_get_usage')) {
            echo "Memory usage: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB\n";
            echo "Peak memory: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB\n";
        }

        echo "=========================\n\n";
    }

    /**
     * Reset handler state (for testing)
     *
     * @return void
     */
    public static function reset(): void {
        self::$running = true;
        self::$cleanupCallbacks = [];
        self::$registered = false;
        self::$shutdownStartTime = null;
    }
}
