<?php
# Copyright 2025

/**
 * P2P Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the P2pMessageProcessor.
 * Includes security initialization for message processing.
 *
 */

require_once(__DIR__ . "/functions.php");

// Initialize security components for P2P message processing
require_once __DIR__ . '/security_init.php';

$app = Application::getInstance();

// Create and run the processor
$processor = $app->getP2pMessageProcessor();
$processor->run();