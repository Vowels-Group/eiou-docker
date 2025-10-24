<?php
# Copyright 2025

/**
 * Transaction Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the TransactionMessageProcessor.
 *
 */
require_once(__DIR__ . "/config.php");
require_once(__DIR__ . "/functions.php");
require_once(__DIR__ . "/src/processors/TransactionMessageProcessor.php");

// Create PDO connection (required for services)
$pdo = createPDOConnection();

// Create and run the processor
$processor = new TransactionMessageProcessor();
$processor->run();