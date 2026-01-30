<?php
/**
 * PHPUnit Bootstrap File
 *
 * This file is loaded before PHPUnit runs tests.
 * It sets up the autoloader and any required test environment configuration.
 */

// Ensure we're in the correct directory for relative paths
$projectRoot = dirname(__DIR__);
$filesDir = $projectRoot . '/files';

// Load Composer autoloader
$autoloadPath = $filesDir . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die(
        "Composer autoloader not found at: {$autoloadPath}\n" .
        "Run 'composer install' in the files/ directory first.\n"
    );
}
require_once $autoloadPath;

// Set up test environment
define('EIOU_TEST_MODE', true);
define('EIOU_TEST_ROOT', __DIR__);
define('EIOU_PROJECT_ROOT', $projectRoot);
define('EIOU_FILES_ROOT', $filesDir);

// Suppress output during tests unless explicitly enabled
if (!defined('EIOU_TEST_VERBOSE')) {
    define('EIOU_TEST_VERBOSE', false);
}

// Mock the output function if it's used in tested code
if (!function_exists('output')) {
    function output($message, $level = 'INFO') {
        if (defined('EIOU_TEST_VERBOSE') && EIOU_TEST_VERBOSE) {
            echo "[TEST] [{$level}] {$message}\n";
        }
    }
}
