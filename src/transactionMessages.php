<?php
# Copyright 2025

// Processing transaction messages with adaptive polling
require_once(__DIR__ . "/config.php");
require_once(__DIR__ . "/functions.php");
require_once(__DIR__ . "/src/services/ServiceContainer.php");
require_once(__DIR__ . "/src/utils/AdaptivePoller.php");

// Load polling configuration
$pollerConfig = [
    'min_interval_ms' => getenv('TRANSACTION_MIN_INTERVAL_MS') ?: 100,
    'max_interval_ms' => getenv('TRANSACTION_MAX_INTERVAL_MS') ?: 5000,
    'idle_interval_ms' => getenv('TRANSACTION_IDLE_INTERVAL_MS') ?: 2000,
    'adaptive' => getenv('TRANSACTION_ADAPTIVE_POLLING') !== 'false',
];

$lockfile = '/tmp/transactionmessages_lock.pid';

// Ensure only one instance runs
checkSingleInstance($lockfile);

// Create PDO connection
$pdo = createPDOConnection();

$transactionService = ServiceContainer::getInstance()->getTransactionService();

// Initialize adaptive poller
$poller = new AdaptivePoller($pollerConfig);
$totalProcessed = 0;
$lastLogTime = time();

echo "[" . date('Y-m-d H:i:s') . "] Transaction processor started with adaptive polling\n";

while (TRUE) {
    // Process pending transactions and track if we had work
    $before = microtime(true);
    $processed = $transactionService->processPendingTransactions();
    $hadWork = $processed > 0;

    if ($hadWork) {
        $totalProcessed += $processed;
    }

    // Log statistics every minute
    if (time() - $lastLogTime >= 60) {
        $stats = $poller->getStats();
        echo "[" . date('Y-m-d H:i:s') . "] Processed: $totalProcessed, ";
        echo "Interval: {$stats['current_interval_ms']}ms, ";
        echo "Empty cycles: {$stats['consecutive_empty']}\n";
        $lastLogTime = time();
        $totalProcessed = 0;
    }

    // Use adaptive polling instead of fixed 500ms
    $poller->wait(0, $hadWork);
}