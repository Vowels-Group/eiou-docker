#!/usr/bin/env php
<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Payment Request Archival Cron Script
 *
 * Moves resolved payment_requests rows older than
 * `paymentRequestsArchiveRetentionDays` (default 180) into
 * `payment_requests_archive`. Pending rows are never touched.
 *
 * Usage:
 *   php /app/eiou/scripts/payment-request-archive-cron.php            # normal run
 *   php /app/eiou/scripts/payment-request-archive-cron.php --dry-run  # count only
 *
 * Exit codes:
 *   0  - Success (including no-op when nothing is eligible)
 *   1  - Archival failed
 *   3  - Initialization failed
 */

chdir('/app/eiou');
require_once '/app/eiou/src/bootstrap.php';

use Eiou\Core\Application;
use Eiou\Utils\Logger;

$dryRun = in_array('--dry-run', $argv ?? [], true);

try {
    $app = Application::getInstance();
    $service = $app->services->getPaymentRequestArchivalService();
} catch (Throwable $e) {
    fwrite(STDERR, "Initialization failed: " . $e->getMessage() . "\n");
    exit(3);
}

try {
    $result = $service->run($dryRun);
    Logger::getInstance()->info('Payment request archival cron finished', $result);
    echo json_encode($result) . "\n";
    exit(0);
} catch (Throwable $e) {
    Logger::getInstance()->error('Payment request archival cron exception', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    fwrite(STDERR, "Archival failed: " . $e->getMessage() . "\n");
    exit(1);
}
