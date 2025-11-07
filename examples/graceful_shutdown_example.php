#!/usr/bin/env php
<?php
# Copyright 2025

/**
 * Example: Graceful Shutdown with ProcessManager
 *
 * This example demonstrates how to use ProcessManager for graceful shutdown
 * handling in EIOU processes.
 *
 * Usage:
 *   php examples/graceful_shutdown_example.php
 *
 * Test shutdown:
 *   1. Start the process: php examples/graceful_shutdown_example.php &
 *   2. Get PID: echo $!
 *   3. Send signal: kill -TERM <pid>
 *   4. Observe graceful shutdown in logs
 *
 * Test force kill (timeout):
 *   1. Modify SHUTDOWN_TIMEOUT to 5 seconds
 *   2. Modify CLEANUP_DELAY to 10 seconds (exceeds timeout)
 *   3. Start and send SIGTERM
 *   4. Observe force kill after 5 seconds
 */

require_once '/etc/eiou/src/services/ProcessManager.php';
require_once '/etc/eiou/src/utils/SecureLogger.php';
require_once '/etc/eiou/src/core/Constants.php';

// Initialize logger
SecureLogger::init('/var/log/eiou/graceful_shutdown_example.log', 'DEBUG');

// Configuration
const PROCESS_NAME = 'Graceful Shutdown Example';
const LOCKFILE = '/tmp/graceful_shutdown_example.lock';
const SHUTDOWN_TIMEOUT = 30; // seconds
const WORK_INTERVAL_US = 1000000; // 1 second
const LOG_INTERVAL_SECONDS = 5;
const CLEANUP_DELAY = 2; // seconds (simulate cleanup work)

/**
 * Simulate doing work
 */
function doWork(int &$workCount): void {
    $workCount++;

    echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
    echo "Working... (iteration $workCount)\n";

    SecureLogger::debug("Work iteration completed", [
        'iteration' => $workCount
    ]);
}

/**
 * Main process
 */
function main(): void {
    echo "=== Graceful Shutdown Example ===\n";
    echo "PID: " . getmypid() . "\n";
    echo "Lockfile: " . LOCKFILE . "\n";
    echo "Shutdown timeout: " . SHUTDOWN_TIMEOUT . " seconds\n";
    echo "Press Ctrl+C or send SIGTERM to test graceful shutdown\n";
    echo "==================================\n\n";

    // Get ProcessManager instance
    $manager = ProcessManager::getInstance();

    // Configure the process
    $manager->setProcessName(PROCESS_NAME)
        ->setLockfile(LOCKFILE)
        ->setShutdownTimeout(SHUTDOWN_TIMEOUT);

    // Register cleanup handlers with different priorities

    // High priority (100) - Critical resource cleanup
    $manager->registerCleanupHandler(function() {
        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Cleanup Handler 1: Closing critical resources...\n";

        SecureLogger::info("Cleanup handler 1: Closing critical resources");

        // Simulate closing database connection
        sleep(CLEANUP_DELAY);

        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Cleanup Handler 1: Complete\n";

        SecureLogger::info("Cleanup handler 1: Completed");
    }, 100);

    // Medium priority (50) - Flush buffers
    $manager->registerCleanupHandler(function() {
        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Cleanup Handler 2: Flushing buffers...\n";

        SecureLogger::info("Cleanup handler 2: Flushing buffers");

        // Simulate flushing logs
        sleep(1);

        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Cleanup Handler 2: Complete\n";

        SecureLogger::info("Cleanup handler 2: Completed");
    }, 50);

    // Low priority (10) - Non-critical cleanup
    $manager->registerCleanupHandler(function() {
        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Cleanup Handler 3: Final cleanup...\n";

        SecureLogger::info("Cleanup handler 3: Final cleanup");

        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Cleanup Handler 3: Complete\n";

        SecureLogger::info("Cleanup handler 3: Completed");
    }, 10);

    // Start process lifecycle
    try {
        $manager->start();
    } catch (RuntimeException $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        SecureLogger::error("Failed to start process", [
            'error' => $e->getMessage()
        ]);
        exit(1);
    }

    echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
    echo "Process started successfully\n\n";

    // Main work loop
    $workCount = 0;
    $lastStatusLog = time();

    while ($manager->isRunning()) {
        // CRITICAL: Check for shutdown signals
        $manager->checkShutdown();

        // Do work
        doWork($workCount);

        // Log status periodically
        if (time() - $lastStatusLog >= LOG_INTERVAL_SECONDS) {
            $status = $manager->getStatus();

            echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
            echo "Status: stage={$status['stage']}, work_count=$workCount\n";

            SecureLogger::info("Process status", [
                'stage' => $status['stage'],
                'work_count' => $workCount,
                'is_running' => $status['is_running']
            ]);

            $lastStatusLog = time();
        }

        // Sleep between work iterations
        usleep(WORK_INTERVAL_US);
    }

    echo "\n[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
    echo "Main loop exited, performing shutdown...\n";

    // Graceful shutdown
    $manager->shutdown();

    echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
    echo "Process exited cleanly\n";
    echo "Total work iterations: $workCount\n";

    SecureLogger::info("Process exited", [
        'work_count' => $workCount,
        'exit_code' => 0
    ]);

    exit(0);
}

// Run main process
try {
    main();
} catch (Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";

    SecureLogger::critical("Fatal error in example process", [
        'exception' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);

    exit(1);
}
