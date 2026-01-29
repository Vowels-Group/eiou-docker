<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Application Bootstrap File
 *
 * Central entry point that initializes the PSR-4 autoloader and loads
 * the output() wrapper function (the only non-namespaced function remaining).
 *
 * All classes are now autoloaded via Composer PSR-4 autoloading.
 * The output() function in ServiceWrappers.php remains as a global function
 * for backward compatibility and is scheduled for removal in future refactoring.
 */

// Load PSR-4 autoloader
require_once __DIR__ . '/src/bootstrap.php';

// Load the global output() wrapper function (non-namespaced, for backward compatibility)
// This is scheduled for removal in future refactoring
require_once __DIR__ . '/src/services/ServiceWrappers.php';
