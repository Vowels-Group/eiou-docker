<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Service Wrappers
 *
 * This file contains the remaining wrapper function that is extensively used
 * throughout the codebase. The output() function will be gradually migrated
 * in a separate refactoring effort.
 *
 * @package Services
 */

require_once __DIR__ . '/ServiceContainer.php';

// ============================================================================
// DEBUG SERVICE WRAPPERS
// ============================================================================

/**
 * Output any message to log (wrapper)
 *
 * This function is extensively used throughout the codebase and will be
 * migrated to direct service calls in a separate refactoring effort.
 *
 * @param string $message Message to output to user and/or log
 * @param string $echo 'ECHO' (to user & log) or 'SILENT' (only to log)
 * @return void
 */
function output($message,$echo = 'ECHO') {
    // Gracefully handle cases where Application is not yet initialized
    // This can happen during early startup or when code is run via CLI tests
    try {
        $app = Application::getInstance();
        if ($app && $app->services) {
            $service = $app->services->getDebugService();
            $service->output($message,$echo);
        }
    } catch (Throwable $e) {
        // Silently ignore if Application isn't available
        // The message is lost but execution continues
    }
}