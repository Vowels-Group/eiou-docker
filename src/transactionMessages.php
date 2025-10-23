<?php
# Copyright 2025

/**
 * Transaction Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the TransactionMessageProcessor.
 *
 */

$app = Application::getInstance();
$processor = $app->getTransactionMessageProcessor();
$processor->run();