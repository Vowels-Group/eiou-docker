<?php
# Copyright 2025

/**
 * Cleanup Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the CleanupMessageProcessor.
 * Includes security initialization for background tasks.
 * Supports graceful shutdown via SIGTERM/SIGINT signals.
 *
 */

require_once(__DIR__ . "/functions.php");

// Initialize security components for cleanup processing
require_once __DIR__ . '/security_init.php';

// Enable async signal handling (PHP 7.1+)
// This allows signal handlers to be called without explicit pcntl_signal_dispatch()
// but we still call it in the processor loop for compatibility
pcntl_async_signals(true);

$app = Application::getInstance();

// Create and run the processor
// The processor handles its own signal registration and graceful shutdown
$processor = $app->getCleanupMessageProcessor();
$processor->run();

// Exit cleanly after graceful shutdown
exit(0);