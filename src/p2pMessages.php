<?php
# Copyright 2025

/**
 * P2P Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the P2pMessageProcessor.
 *
 */

$app = Application::getInstance();
$processor = $app->getP2pMessageProcessor();
$processor->run();