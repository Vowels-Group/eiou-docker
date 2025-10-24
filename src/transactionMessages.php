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

$app = Application::getInstance();

// Create and run the processor
$processor = $app->getTransactionMessageProcessor();
$processor->run();