<?php
# Copyright 2025

/**
 * Cleanup Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the CleanupMessageProcessor.
 *
 */

$app = Application::getInstance();
$processor = $app->getCleanupMessageProcessor();
$processor->run();