<?php
# Copyright 2025 The Vowels Company

/**
 * Transaction Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the TransactionMessageProcessor.
 * Includes security initialization for message processing.
 *
 */

require_once(__DIR__ . "/Functions.php");

// Initialize security components for message processing
// Note: For CLI processors, we skip session management but keep logging
require_once __DIR__ . '/SecurityInit.php';

$app = Application::getInstance();

// Create and run the processor
$processor = $app->getTransactionMessageProcessor();
$processor->run();