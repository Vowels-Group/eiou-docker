<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Contact Status Message Processor Entry Point
 *
 * Thin wrapper that bootstraps and runs the ContactStatusProcessor.
 * Includes security initialization for message processing.
 *
 * This processor periodically pings accepted contacts to check their
 * online status and validate transaction chains.
 */

require_once(__DIR__ . "/Functions.php");

use Eiou\Core\Application;
use Eiou\Core\Constants;

// Initialize security components for contact status processing
require_once __DIR__ . '/SecurityInit.php';

// Check if contact status feature is enabled before starting
if (!Constants::isContactStatusEnabled()) {
    echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] Contact status polling is disabled in constants, exiting.\n";
    exit(0);
}

$app = Application::getInstance();

// Create and run the processor
$processor = $app->getContactStatusProcessor();
$processor->run();
