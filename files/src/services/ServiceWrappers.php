<?php
# Copyright 2025

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
    $service = Application::getInstance()->services->getDebugService();
    $service->output($message,$echo);
}