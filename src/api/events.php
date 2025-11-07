<?php
/**
 * Server-Sent Events (SSE) Endpoint
 *
 * Copyright 2025
 * Provides real-time updates for wallet balance, transactions, and status changes.
 *
 * This endpoint uses Server-Sent Events to push updates to connected clients.
 * Compatible with Tor Browser and privacy-focused environments.
 *
 * Event Types:
 * - balance_update: Balance has changed
 * - transaction_new: New transaction detected
 * - transaction_update: Transaction status changed
 * - status_change: Container/service status changed
 * - heartbeat: Keep-alive signal (every 30s)
 *
 * @package API
 */

// Prevent PHP from buffering output
if (ob_get_level()) ob_end_clean();

// Set SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Disable output buffering
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);

// Flush output immediately
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

// Load required dependencies
require_once '/etc/eiou/src/core/UserContext.php';
require_once '/etc/eiou/src/services/ServiceContainer.php';

// Get service container
$container = ServiceContainer::getInstance();

// Get current user
$userContext = $container->getCurrentUser();
$homeDirectory = $userContext->getHomeDirectory();

// Event state file path (tracks last sent events to avoid duplicates)
$stateFile = $homeDirectory . '/.eiou/event-state.json';

// Initialize event state
$eventState = [];
if (file_exists($stateFile)) {
    $eventState = json_decode(file_get_contents($stateFile), true) ?? [];
}

/**
 * Send SSE event to client
 *
 * @param string $eventType Event type
 * @param array $data Event data
 * @param string|null $id Event ID (for event tracking)
 */
function sendSSEEvent(string $eventType, array $data, ?string $id = null): void {
    if ($id !== null) {
        echo "id: " . $id . "\n";
    }
    echo "event: " . $eventType . "\n";
    echo "data: " . json_encode($data) . "\n\n";

    // Flush output
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

/**
 * Get current balance from user wallet
 *
 * @param UserContext $userContext Current user
 * @return float Current balance
 */
function getCurrentBalance(UserContext $userContext): float {
    $walletFile = $userContext->getHomeDirectory() . '/.eiou/wallet.json';

    if (!file_exists($walletFile)) {
        return 0.0;
    }

    $wallet = json_decode(file_get_contents($walletFile), true);
    return (float)($wallet['balance'] ?? 0.0);
}

/**
 * Get recent transactions count (for detecting new transactions)
 *
 * @param ServiceContainer $container Service container
 * @return array Transaction summary
 */
function getTransactionSummary(ServiceContainer $container): array {
    try {
        $transactionRepo = $container->getTransactionRepository();
        $p2pRepo = $container->getP2pRepository();
        $rp2pRepo = $container->getRp2pRepository();

        // Get counts
        $transactionCount = $transactionRepo->getRecordCount();
        $p2pCount = $p2pRepo->getRecordCount();
        $rp2pCount = $rp2pRepo->getRecordCount();

        // Get most recent transaction timestamp
        $allTransactions = $transactionRepo->getAllRecords();
        $lastTransactionTime = 0;

        foreach ($allTransactions as $tx) {
            $txTime = strtotime($tx['timestamp'] ?? '0');
            if ($txTime > $lastTransactionTime) {
                $lastTransactionTime = $txTime;
            }
        }

        return [
            'total' => $transactionCount + $p2pCount + $rp2pCount,
            'transaction' => $transactionCount,
            'p2p' => $p2pCount,
            'rp2p' => $rp2pCount,
            'last_timestamp' => $lastTransactionTime
        ];
    } catch (Exception $e) {
        error_log("SSE: Error getting transaction summary: " . $e->getMessage());
        return [
            'total' => 0,
            'transaction' => 0,
            'p2p' => 0,
            'rp2p' => 0,
            'last_timestamp' => 0
        ];
    }
}

/**
 * Check for container status (Docker health)
 *
 * @return string Container status
 */
function getContainerStatus(): string {
    // Check if container is running by checking if we can read wallet files
    $homeDir = $_ENV['HOME'] ?? '/root';
    $walletDir = $homeDir . '/.eiou';

    if (!is_dir($walletDir)) {
        return 'initializing';
    }

    if (!is_readable($walletDir)) {
        return 'error';
    }

    return 'running';
}

// Main event loop
$startTime = time();
$maxDuration = 300; // Maximum connection duration: 5 minutes
$heartbeatInterval = 30; // Send heartbeat every 30 seconds
$checkInterval = 2; // Check for updates every 2 seconds
$lastHeartbeat = time();
$lastCheck = time();

// Send initial connection event
sendSSEEvent('connected', [
    'message' => 'SSE connection established',
    'timestamp' => time(),
    'user' => $userContext->getUserId()
], 'init');

// Initialize last known state
$lastBalance = getCurrentBalance($userContext);
$lastTransactionSummary = getTransactionSummary($container);
$lastStatus = getContainerStatus();

// Save initial state
$eventState['last_balance'] = $lastBalance;
$eventState['last_transaction_summary'] = $lastTransactionSummary;
$eventState['last_status'] = $lastStatus;
file_put_contents($stateFile, json_encode($eventState));

// Event loop
while (true) {
    $currentTime = time();

    // Check if connection should be closed
    if ($currentTime - $startTime > $maxDuration) {
        sendSSEEvent('reconnect', [
            'message' => 'Connection timeout, please reconnect',
            'timestamp' => $currentTime
        ], 'timeout');
        break;
    }

    // Check if client disconnected
    if (connection_aborted()) {
        break;
    }

    // Send heartbeat
    if ($currentTime - $lastHeartbeat >= $heartbeatInterval) {
        sendSSEEvent('heartbeat', [
            'timestamp' => $currentTime,
            'uptime' => $currentTime - $startTime
        ], 'heartbeat_' . $currentTime);
        $lastHeartbeat = $currentTime;
    }

    // Check for updates
    if ($currentTime - $lastCheck >= $checkInterval) {
        // Check balance
        $currentBalance = getCurrentBalance($userContext);
        if ($currentBalance !== $lastBalance) {
            sendSSEEvent('balance_update', [
                'old_balance' => $lastBalance,
                'new_balance' => $currentBalance,
                'change' => $currentBalance - $lastBalance,
                'timestamp' => $currentTime
            ], 'balance_' . $currentTime);

            $lastBalance = $currentBalance;
            $eventState['last_balance'] = $currentBalance;
        }

        // Check transactions
        $currentTransactionSummary = getTransactionSummary($container);
        if ($currentTransactionSummary['total'] !== $lastTransactionSummary['total']) {
            $newTransactions = $currentTransactionSummary['total'] - $lastTransactionSummary['total'];

            sendSSEEvent('transaction_new', [
                'count' => $newTransactions,
                'total' => $currentTransactionSummary['total'],
                'timestamp' => $currentTime
            ], 'transaction_' . $currentTime);

            $lastTransactionSummary = $currentTransactionSummary;
            $eventState['last_transaction_summary'] = $currentTransactionSummary;
        } elseif ($currentTransactionSummary['last_timestamp'] !== $lastTransactionSummary['last_timestamp']) {
            // Transaction was updated
            sendSSEEvent('transaction_update', [
                'message' => 'Transaction status changed',
                'timestamp' => $currentTime
            ], 'transaction_update_' . $currentTime);

            $lastTransactionSummary = $currentTransactionSummary;
            $eventState['last_transaction_summary'] = $currentTransactionSummary;
        }

        // Check container status
        $currentStatus = getContainerStatus();
        if ($currentStatus !== $lastStatus) {
            sendSSEEvent('status_change', [
                'old_status' => $lastStatus,
                'new_status' => $currentStatus,
                'timestamp' => $currentTime
            ], 'status_' . $currentTime);

            $lastStatus = $currentStatus;
            $eventState['last_status'] = $currentStatus;
        }

        // Save state
        file_put_contents($stateFile, json_encode($eventState));

        $lastCheck = $currentTime;
    }

    // Sleep for a short time to reduce CPU usage
    usleep(500000); // 0.5 seconds
}

// Cleanup
sendSSEEvent('close', [
    'message' => 'Connection closed',
    'timestamp' => time()
], 'close');
