<?php
# Copyright 2025

/**
 * Cleanup Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the CleanupMessageProcessor.
 *
 */

require_once(__DIR__ . "/functions.php");

$app = Application::getInstance();

// Create and run the processor
$processor = $app->getCleanupMessageProcessor();
$processor->run();