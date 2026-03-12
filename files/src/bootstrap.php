<?php
/**
 * eIOU Bootstrap File
 *
 * This file MUST be included by ALL entry points before using any classes.
 * It initializes the Composer classmap autoloader for namespace support.
 *
 * Entry points that must include this:
 * - files/root/Functions.php
 * - files/root/api/Api.php
 * - files/root/processors/P2pMessages.php
 * - files/root/processors/TransactionMessages.php
 * - files/root/processors/CleanupMessages.php
 * - files/root/processors/ContactStatusMessages.php
 * - files/root/cli/Eiou.php
 * - files/scripts/backup-cron.php
 * - Any inline PHP in startup.sh
 */

// Prevent double-inclusion
if (defined('EIOU_BOOTSTRAP_LOADED')) {
    return;
}
define('EIOU_BOOTSTRAP_LOADED', true);

// Define base path constant for any legacy code
define('EIOU_BASE_PATH', dirname(__DIR__));

// Load Composer autoloader
$autoloadPath = EIOU_BASE_PATH . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    error_log("FATAL: Composer autoload not found at: $autoloadPath");
    error_log("Run 'composer install' in the /app/eiou directory");
    die("Autoloader not found. See error log.\n");
}

require_once $autoloadPath;
