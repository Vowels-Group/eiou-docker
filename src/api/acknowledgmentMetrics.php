#!/usr/bin/env php
<?php
# Copyright 2025

/**
 * Acknowledgment Metrics API Endpoint
 *
 * Provides monitoring metrics for the 3-stage acknowledgment protocol.
 * Returns JSON data about delivery success rates, retry statistics, and DLQ status.
 *
 * Issue: #139 - Transaction Reliability & Message Handling System
 *
 * Usage: Access via HTTP or command line
 *   HTTP: curl http://localhost/api/acknowledgmentMetrics.php
 *   CLI: php /etc/eiou/src/api/acknowledgmentMetrics.php
 *
 * @package API
 */

// Set JSON response header if accessed via HTTP
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
}

// Bootstrap the application
require_once '/etc/eiou/src/core/bootstrap.php';

try {
    // Get service container
    $container = ServiceContainer::getInstance();
    $ackService = $container->getAcknowledgmentService();
    $ackRepository = $container->getAcknowledgmentRepository();

    // Collect metrics
    $metrics = [
        'timestamp' => date('Y-m-d H:i:s'),
        'uptime' => getUptime(),

        // Delivery statistics (last 24 hours)
        'delivery_24h' => $ackService->getDeliveryStats(24),

        // Delivery statistics (last 1 hour)
        'delivery_1h' => $ackService->getDeliveryStats(1),

        // Retry statistics
        'retry_stats_24h' => $ackService->getRetryStats(24),
        'retry_stats_1h' => $ackService->getRetryStats(1),

        // Failure rates
        'failure_rate_24h' => round($ackService->getFailureRate(24), 2),
        'failure_rate_1h' => round($ackService->getFailureRate(1), 2),

        // Dead letter queue
        'dead_letter_queue' => [
            'count' => count($ackService->getDeadLetterMessages()),
            'recent' => array_slice($ackService->getDeadLetterMessages(10), 0, 10)
        ],

        // Stage distribution
        'stage_distribution' => $ackRepository->getStatsByStage(),

        // System health
        'health' => calculateHealthScore($ackService)
    ];

    // Output JSON
    echo json_encode($metrics, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    $error = [
        'error' => true,
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];

    echo json_encode($error, JSON_PRETTY_PRINT);
    exit(1);
}

/**
 * Get system uptime
 *
 * @return string|null Uptime string or null if unavailable
 */
function getUptime(): ?string {
    if (file_exists('/proc/uptime')) {
        $uptime = file_get_contents('/proc/uptime');
        $seconds = (int) explode(' ', $uptime)[0];

        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return sprintf('%dd %dh %dm', $days, $hours, $minutes);
    }

    return null;
}

/**
 * Calculate overall system health score
 *
 * @param AcknowledgmentService $ackService ACK service instance
 * @return array Health metrics
 */
function calculateHealthScore(AcknowledgmentService $ackService): array {
    $deliveryStats = $ackService->getDeliveryStats(1);
    $failureRate = $ackService->getFailureRate(1);
    $dlqCount = count($ackService->getDeadLetterMessages());

    // Calculate health score (0-100)
    $score = 100;

    // Deduct points for failures
    if ($failureRate > 1.0) {
        $score -= min(50, $failureRate * 10); // Max 50 points deduction
    }

    // Deduct points for DLQ buildup
    if ($dlqCount > 10) {
        $score -= min(30, ($dlqCount - 10) * 2); // Max 30 points deduction
    }

    // Deduct points for low confirmation rate
    if (isset($deliveryStats['success_rate']) && $deliveryStats['success_rate'] < 95) {
        $score -= (95 - $deliveryStats['success_rate']);
    }

    $score = max(0, $score); // Floor at 0

    // Determine status
    if ($score >= 90) {
        $status = 'healthy';
    } elseif ($score >= 70) {
        $status = 'degraded';
    } elseif ($score >= 50) {
        $status = 'warning';
    } else {
        $status = 'critical';
    }

    return [
        'score' => round($score, 1),
        'status' => $status,
        'checks' => [
            'failure_rate_ok' => $failureRate < 1.0,
            'dlq_size_ok' => $dlqCount < 10,
            'confirmation_rate_ok' => ($deliveryStats['success_rate'] ?? 0) >= 95
        ]
    ];
}
