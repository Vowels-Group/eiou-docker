<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Transaction Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the TransactionMessageProcessor.
 * Includes security initialization for message processing.
 *
 */

require_once(dirname(__DIR__) . "/Functions.php");

use Eiou\Core\Application;

// Initialize security components for message processing
// Note: For CLI processors, we skip session management but keep logging
require_once dirname(__DIR__) . '/SecurityInit.php';

$app = Application::getInstance();

// Create and run the processor
$processor = $app->getTransactionMessageProcessor();
$processor->run();