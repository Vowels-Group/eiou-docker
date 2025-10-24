<?php
# Copyright 2025

/**
 * P2P Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the P2pMessageProcessor.
 *
 */

require_once(__DIR__ . "/config.php");
require_once(__DIR__ . "/functions.php");
require_once(__DIR__ . "/src/processors/P2pMessageProcessor.php");

// Create PDO connection (required for services)
$pdo = createPDOConnection();

// Create and run the processor
$processor = new P2pMessageProcessor();
$processor->run();