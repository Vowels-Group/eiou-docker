<?php
# Copyright 2025

// Processing cleanup messages with adaptive polling

// Bootstrap the application
require_once(__DIR__ . "/src/bootstrap.php");
$app = Application::getInstance();

require_once(__DIR__ . "/src/utils/AdaptivePoller.php");

// Load polling configuration - cleanup can run less frequently
$constants = Constants::getInstance();
$pollerConfig = [
    'min_interval_ms' => $constants->get('CLEANUP_MIN_INTERVAL_MS') ?: 1000,   // 1 second minimum
    'max_interval_ms' => $constants->get('CLEANUP_MAX_INTERVAL_MS') ?: 30000,  // 30 seconds maximum
    'idle_interval_ms' => $constants->get('CLEANUP_IDLE_INTERVAL_MS') ?: 10000, // 10 seconds when idle
    'adaptive' => $constants->get('CLEANUP_ADAPTIVE_POLLING') !== 'false',
];

$lockfile = '/tmp/cleanupmessages_lock.pid';

// Ensure only one instance runs
checkSingleInstance($lockfile);

// Get PDO connection from Application
$pdo = $app->getDatabase();

$cleanupService = ServiceContainer::getInstance()->getCleanupService();

// Initialize adaptive poller
$poller = new AdaptivePoller($pollerConfig);
$totalProcessed = 0;
$lastLogTime = time();

echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] Cleanup processor started with adaptive polling\n";

while (TRUE) {
    // Process cleanup messages and track if we had work
    $before = microtime(true);
    $processed = $cleanupService->processCleanupMessages();
    $hadWork = $processed > 0;

    if ($hadWork) {
        $totalProcessed += $processed;
    }

    // Log statistics every 5 minutes for cleanup (less frequent than others)
    if (time() - $lastLogTime >= 300) {
        $stats = $poller->getStats();
        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] Cleaned: $totalProcessed, ";
        echo "Interval: {$stats['current_interval_ms']}ms, ";
        echo "Empty cycles: {$stats['consecutive_empty']}\n";
        $lastLogTime = time();
        $totalProcessed = 0;
    }

    // Use adaptive polling instead of fixed 500ms
    $poller->wait(0, $hadWork);
}