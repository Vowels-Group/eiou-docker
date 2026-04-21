#!/usr/bin/env php
<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Transaction Archival Cron Script
 *
 * Moves completed `transactions` rows older than
 * `transactionsArchiveRetentionDays` (default 180) into
 * `transactions_archive`. Archival is gated per bilateral pair on a
 * gap-free chain-integrity check — pairs with a detected gap are
 * skipped, not archived, so the gap stays inspectable. See
 * TransactionArchivalService for the full invariant.
 *
 * Usage:
 *   php /app/eiou/scripts/transaction-archive-cron.php            # normal run
 *   php /app/eiou/scripts/transaction-archive-cron.php --dry-run  # count only
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
    $service = $app->services->getTransactionArchivalService();
} catch (Throwable $e) {
    fwrite(STDERR, "Initialization failed: " . $e->getMessage() . "\n");
    exit(3);
}

try {
    $result = $service->run($dryRun);
    Logger::getInstance()->info('Transaction archival cron finished', $result);
    echo json_encode($result) . "\n";
    exit(0);
} catch (Throwable $e) {
    Logger::getInstance()->error('Transaction archival cron exception', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    fwrite(STDERR, "Archival failed: " . $e->getMessage() . "\n");
    exit(1);
}
