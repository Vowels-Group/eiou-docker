<?php
# Copyright 2025

/**
 * P2P Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the P2pMessageProcessor.
 *
 */

require_once(__DIR__ . "/functions.php");

$app = Application::getInstance();

// Create and run the processor
$processor = $app->getP2pMessageProcessor();
$processor->run();