<?php

# Copyright 2025-2026 Vowels Group, LLC
/**
 * Adaptive polling mechanism for background processors
 * Dynamically adjusts polling intervals based on system load and queue size
 */

class AdaptivePoller {
    private $minInterval;
    private $maxInterval;
    private $idleInterval;
    private $adaptive;
    private $currentInterval;
    private $consecutiveEmpty = 0;
    private $consecutiveSuccess = 0;
    private $lastQueueSize = 0;
    private $startTime;

    /**
     * Initialize adaptive poller with configuration
     *
     * @param array $config Configuration array with intervals
     */
    public function __construct(array $config) {
        $this->minInterval = $config['min_interval_ms'] ?? 100;
        $this->maxInterval = $config['max_interval_ms'] ?? 5000;
        $this->idleInterval = $config['idle_interval_ms'] ?? 2000;
        $this->adaptive = $config['adaptive'] ?? true;

        // Start with idle interval
        $this->currentInterval = $this->idleInterval;
        $this->startTime = microtime(true);
    }

    /**
     * Wait for the next polling cycle
     * Automatically adjusts the interval based on recent activity
     *
     * @param int $queueSize Current queue size
     * @param bool $hadWork Whether the last cycle processed any items
     */
    public function wait(int $queueSize = 0, bool $hadWork = false) {
        if (!$this->adaptive) {
            // Fixed interval mode - use idle interval
            usleep($this->idleInterval * 1000);
            return;
        }

        // Update statistics
        if ($hadWork) {
            $this->consecutiveEmpty = 0;
            $this->consecutiveSuccess++;
        } else {
            $this->consecutiveEmpty++;
            $this->consecutiveSuccess = 0;
        }
        $this->lastQueueSize = $queueSize;

        // Calculate new interval based on activity
        $this->currentInterval = $this->calculateInterval();

        // Apply the wait
        usleep($this->currentInterval * 1000);
    }

    /**
     * Calculate optimal polling interval based on current conditions
     *
     * @return int Interval in milliseconds
     */
    private function calculateInterval(): int {
        // If we've been empty for a while, increase interval
        if ($this->consecutiveEmpty > 10) {
            // Use idle interval when truly idle
            return $this->idleInterval;
        } elseif ($this->consecutiveEmpty > 5) {
            // Gradually increase interval when becoming idle
            $factor = min($this->consecutiveEmpty / 5, 3);
            return min($this->maxInterval, $this->currentInterval * $factor);
        }

        // If we have a large queue, use minimum interval
        if ($this->lastQueueSize > 50) {
            return $this->minInterval;
        }

        // If we have moderate queue, scale proportionally
        if ($this->lastQueueSize > 0) {
            $factor = max(Constants::ADAPTIVE_POLLING_MIN_FACTOR, 1 - ($this->lastQueueSize / Constants::ADAPTIVE_POLLING_QUEUE_DIVISOR));
            return max($this->minInterval, min($this->maxInterval, $this->idleInterval * $factor));
        }

        // If we just had success, stay responsive
        if ($this->consecutiveSuccess > 0) {
            return min($this->currentInterval * 1.2, $this->idleInterval);
        }

        // Default to idle interval
        return $this->idleInterval;
    }

    /**
     * Get current statistics for monitoring
     *
     * @return array Statistics array
     */
    public function getStats(): array {
        return [
            'current_interval_ms' => $this->currentInterval,
            'consecutive_empty' => $this->consecutiveEmpty,
            'consecutive_success' => $this->consecutiveSuccess,
            'last_queue_size' => $this->lastQueueSize,
            'runtime_seconds' => microtime(true) - $this->startTime,
            'adaptive_enabled' => $this->adaptive,
        ];
    }

    /**
     * Reset the poller to initial state
     */
    public function reset() {
        $this->currentInterval = $this->idleInterval;
        $this->consecutiveEmpty = 0;
        $this->consecutiveSuccess = 0;
        $this->lastQueueSize = 0;
        $this->startTime = microtime(true);
    }

    /**
     * Force a specific interval for the next cycle
     *
     * @param int $intervalMs Interval in milliseconds
     */
    public function forceInterval(int $intervalMs) {
        $this->currentInterval = max($this->minInterval, min($this->maxInterval, $intervalMs));
    }
}