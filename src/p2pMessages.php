<?php
# Copyright 2025

// Processing P2P messages with adaptive polling

// Bootstrap the application
require_once(__DIR__ . "/src/bootstrap.php");
$app = Application::getInstance();

require_once(__DIR__ . "/src/utils/AdaptivePoller.php");

// Load polling configuration
$constants = Constants::getInstance();
$pollerConfig = [
    'min_interval_ms' => $constants->get('P2P_MIN_INTERVAL_MS') ?: 100,
    'max_interval_ms' => $constants->get('P2P_MAX_INTERVAL_MS') ?: 5000,
    'idle_interval_ms' => $constants->get('P2P_IDLE_INTERVAL_MS') ?: 2000,
    'adaptive' => $constants->get('P2P_ADAPTIVE_POLLING') !== 'false',
];

$lockfile = '/tmp/p2pmessages_lock.pid';

// Ensure only one instance runs
checkSingleInstance($lockfile);

// Get PDO connection from Application
$pdo = $app->getDatabase();

$p2pService = ServiceContainer::getInstance()->getP2pService();

// Initialize adaptive poller
$poller = new AdaptivePoller($pollerConfig);
$totalProcessed = 0;
$lastLogTime = time();

echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] P2P processor started with adaptive polling\n";

while (TRUE) {
    // Process queued P2P messages and track if we had work
    $before = microtime(true);
    $processed = $p2pService->processQueuedP2pMessages();
    $hadWork = $processed > 0;

    if ($hadWork) {
        $totalProcessed += $processed;
    }

    // Log statistics every minute
    if (time() - $lastLogTime >= 60) {
        $stats = $poller->getStats();
        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] Processed: $totalProcessed, ";
        echo "Interval: {$stats['current_interval_ms']}ms, ";
        echo "Empty cycles: {$stats['consecutive_empty']}\n";
        $lastLogTime = time();
        $totalProcessed = 0;
    }

    // Use adaptive polling instead of fixed 500ms
    $poller->wait(0, $hadWork);
}