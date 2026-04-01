<?php
# Copyright 2025-2026 Vowels Group, LLC
#
# Update Check Cron Script
#
# Checks Docker Hub for newer image versions and caches the result.
# Runs daily via cron. Respects the updateCheckEnabled user setting.
#
# Exit codes:
#   0 = success (check completed or skipped)
#   1 = error

require_once '/app/eiou/vendor/autoload.php';

use Eiou\Core\UserContext;
use Eiou\Services\UpdateCheckService;

try {
    // Check if update checks are enabled
    $user = UserContext::getInstance();
    if (!$user->getUpdateCheckEnabled()) {
        echo "Update check disabled by user setting\n";
        exit(0);
    }

    $result = UpdateCheckService::check(true);

    if ($result === null) {
        echo "Update check failed\n";
        exit(0); // Non-fatal — will retry next cron run
    }

    if ($result['available']) {
        echo "Update available: " . $result['latest_version'] . " (current: " . $result['current_version'] . ")\n";
    } else {
        echo "Up to date: " . $result['current_version'] . "\n";
    }

} catch (Exception $e) {
    fwrite(STDERR, "Update check error: " . $e->getMessage() . "\n");
    exit(1);
}
