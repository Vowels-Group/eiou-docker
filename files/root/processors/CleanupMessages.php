<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Cleanup Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the CleanupMessageProcessor.
 * Includes security initialization for background tasks.
 *
 */

require_once(dirname(__DIR__) . "/Functions.php");

use Eiou\Core\Application;

// Initialize security components for cleanup processing
require_once dirname(__DIR__) . '/SecurityInit.php';

$app = Application::getInstance();

// Create and run the processor
$processor = $app->getCleanupMessageProcessor();
$processor->run();