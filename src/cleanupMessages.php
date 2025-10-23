<?php
# Copyright 2025

/**
 * Cleanup Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the CleanupMessageProcessor.
 * Maintains backwards compatibility with existing deployment scripts.
 *
 * Issue #106: Refactored to use CleanupMessageProcessor class
 */

require_once(__DIR__ . "/config.php");
require_once(__DIR__ . "/functions.php");
require_once(__DIR__ . "/src/processors/CleanupMessageProcessor.php");

// Create PDO connection (required for services)
$pdo = createPDOConnection();

// Create and run the processor
$processor = new CleanupMessageProcessor();
$processor->run();