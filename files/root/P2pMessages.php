<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * P2P Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the P2pMessageProcessor.
 * Includes security initialization for message processing.
 *
 */

require_once(__DIR__ . "/Functions.php");

// Initialize security components for P2P message processing
require_once __DIR__ . '/SecurityInit.php';

$app = Application::getInstance();

// Create and run the processor
$processor = $app->getP2pMessageProcessor();
$processor->run();