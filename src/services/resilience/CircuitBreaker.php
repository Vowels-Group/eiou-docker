<?php
/**
 * Circuit Breaker Pattern Implementation
 *
 * Prevents cascading failures by temporarily blocking calls to failing services.
 * Implements the three states: CLOSED, OPEN, and HALF_OPEN with automatic recovery.
 *
 * @package Services\Resilience
 * @since 1.0.0
 */

require_once dirname(__DIR__, 2) . '/logging/SecureLogger.php';

class CircuitBreaker {
    /**
     * Circuit breaker states
     */
    const STATE_CLOSED = 'closed';
    const STATE_OPEN = 'open';
    const STATE_HALF_OPEN = 'half_open';

    /**
     * @var string Service identifier
     */
    private string $serviceName;

    /**
     * @var string Current state
     */
    private string $state = self::STATE_CLOSED;

    /**
     * @var int Failure count
     */
    private int $failureCount = 0;

    /**
     * @var int Success count (for half-open state)
     */
    private int $successCount = 0;

    /**
     * @var int Failure threshold before opening
     */
    private int $failureThreshold;

    /**
     * @var int Success threshold to close from half-open
     */
    private int $successThreshold;

    /**
     * @var int Timeout in seconds before attempting half-open
     */
    private int $timeout;

    /**
     * @var float|null Timestamp when circuit was opened
     */
    private ?float $openedAt = null;

    /**
     * @var float|null Last failure timestamp
     */
    private ?float $lastFailureTime = null;

    /**
     * @var string|null Last failure reason
     */
    private ?string $lastFailureReason = null;

    /**
     * @var array Statistics
     */
    private array $stats = [
        'total_calls' => 0,
        'successful_calls' => 0,
        'failed_calls' => 0,
        'rejected_calls' => 0,
        'state_transitions' => 0,
        'time_open_total' => 0,
        'time_half_open_total' => 0,
        'last_state_change' => null
    ];

    /**
     * @var array State change listeners
     */
    private array $stateChangeListeners = [];

    /**
     * @var float Time window for failure counting (seconds)
     */
    private float $failureWindow = 60.0;

    /**
     * @var array Failure timestamps for sliding window
     */
    private array $failureTimestamps = [];

    /**
     * Constructor
     *
     * @param string $serviceName Service identifier
     * @param int $failureThreshold Number of failures before opening
     * @param int $timeout Seconds to wait before attempting half-open
     * @param int $successThreshold Successes needed to close from half-open
     */
    public function __construct(
        string $serviceName,
        int $failureThreshold = 5,
        int $timeout = 60,
        int $successThreshold = 3
    ) {
        $this->serviceName = $serviceName;
        $this->failureThreshold = max(1, $failureThreshold);
        $this->timeout = max(1, $timeout);
        $this->successThreshold = max(1, $successThreshold);
        $this->stats['last_state_change'] = microtime(true);
    }

    /**
     * Execute a callable through the circuit breaker
     *
     * @param callable $callback Function to execute
     * @return mixed Result of the callback
     * @throws RuntimeException If circuit is open
     * @throws Exception If callback fails
     */
    public function call(callable $callback) {
        $this->stats['total_calls']++;

        // Check if we should transition states
        $this->checkStateTransition();

        // Handle based on current state
        switch ($this->state) {
            case self::STATE_OPEN:
                $this->stats['rejected_calls']++;
                $this->logStateRejection();
                throw new RuntimeException(
                    "Circuit breaker is OPEN for service '{$this->serviceName}'. Service unavailable.",
                    503
                );

            case self::STATE_HALF_OPEN:
                return $this->executeInHalfOpen($callback);

            case self::STATE_CLOSED:
            default:
                return $this->executeInClosed($callback);
        }
    }

    /**
     * Execute callback in closed state
     *
     * @param callable $callback Function to execute
     * @return mixed Result of the callback
     * @throws Exception If callback fails
     */
    private function executeInClosed(callable $callback) {
        try {
            $result = $callback();
            $this->recordSuccess();
            return $result;
        } catch (\Exception $e) {
            $this->recordFailure($e->getMessage());
            throw $e;
        }
    }

    /**
     * Execute callback in half-open state
     *
     * @param callable $callback Function to execute
     * @return mixed Result of the callback
     * @throws Exception If callback fails
     */
    private function executeInHalfOpen(callable $callback) {
        try {
            $result = $callback();
            $this->recordSuccess();

            // Check if we should close the circuit
            if ($this->successCount >= $this->successThreshold) {
                $this->transitionTo(self::STATE_CLOSED);
            }

            return $result;
        } catch (\Exception $e) {
            $this->recordFailure($e->getMessage());

            // Single failure in half-open immediately reopens
            $this->transitionTo(self::STATE_OPEN);

            throw $e;
        }
    }

    /**
     * Record a successful call
     */
    private function recordSuccess(): void {
        $this->stats['successful_calls']++;

        if ($this->state === self::STATE_HALF_OPEN) {
            $this->successCount++;
            SecureLogger::debug("Circuit breaker success in HALF_OPEN", [
                'service' => $this->serviceName,
                'success_count' => $this->successCount,
                'threshold' => $this->successThreshold
            ]);
        }
    }

    /**
     * Record a failed call
     *
     * @param string $reason Failure reason
     */
    private function recordFailure(string $reason): void {
        $this->stats['failed_calls']++;
        $this->lastFailureTime = microtime(true);
        $this->lastFailureReason = $reason;

        // Add to sliding window
        $this->failureTimestamps[] = $this->lastFailureTime;
        $this->cleanFailureWindow();

        if ($this->state === self::STATE_CLOSED) {
            $this->failureCount = count($this->failureTimestamps);

            SecureLogger::warning("Circuit breaker failure recorded", [
                'service' => $this->serviceName,
                'failure_count' => $this->failureCount,
                'threshold' => $this->failureThreshold,
                'reason' => substr($reason, 0, 200)
            ]);

            // Check if we should open the circuit
            if ($this->failureCount >= $this->failureThreshold) {
                $this->transitionTo(self::STATE_OPEN);
            }
        }
    }

    /**
     * Clean old failures from sliding window
     */
    private function cleanFailureWindow(): void {
        $cutoff = microtime(true) - $this->failureWindow;
        $this->failureTimestamps = array_filter(
            $this->failureTimestamps,
            function($timestamp) use ($cutoff) {
                return $timestamp > $cutoff;
            }
        );
        $this->failureTimestamps = array_values($this->failureTimestamps);
    }

    /**
     * Check if state should transition
     */
    private function checkStateTransition(): void {
        if ($this->state === self::STATE_OPEN && $this->shouldAttemptHalfOpen()) {
            $this->transitionTo(self::STATE_HALF_OPEN);
        }
    }

    /**
     * Check if circuit should attempt half-open
     *
     * @return bool True if timeout has elapsed
     */
    private function shouldAttemptHalfOpen(): bool {
        if ($this->openedAt === null) {
            return false;
        }

        $elapsed = microtime(true) - $this->openedAt;
        return $elapsed >= $this->timeout;
    }

    /**
     * Transition to a new state
     *
     * @param string $newState New state
     */
    private function transitionTo(string $newState): void {
        $oldState = $this->state;

        if ($oldState === $newState) {
            return;
        }

        // Update time tracking
        $now = microtime(true);
        $timeInState = $now - $this->stats['last_state_change'];

        if ($oldState === self::STATE_OPEN) {
            $this->stats['time_open_total'] += $timeInState;
        } elseif ($oldState === self::STATE_HALF_OPEN) {
            $this->stats['time_half_open_total'] += $timeInState;
        }

        // Perform state transition
        $this->state = $newState;
        $this->stats['state_transitions']++;
        $this->stats['last_state_change'] = $now;

        // Reset counters based on new state
        switch ($newState) {
            case self::STATE_OPEN:
                $this->openedAt = $now;
                $this->successCount = 0;
                break;

            case self::STATE_HALF_OPEN:
                $this->successCount = 0;
                $this->failureCount = 0;
                break;

            case self::STATE_CLOSED:
                $this->failureCount = 0;
                $this->successCount = 0;
                $this->openedAt = null;
                $this->failureTimestamps = [];
                break;
        }

        // Log state change
        SecureLogger::info("Circuit breaker state transition", [
            'service' => $this->serviceName,
            'from' => $oldState,
            'to' => $newState,
            'time_in_state' => round($timeInState, 2)
        ]);

        // Notify listeners
        $this->notifyStateChange($oldState, $newState);
    }

    /**
     * Log rejection due to open circuit
     */
    private function logStateRejection(): void {
        $timeOpen = $this->openedAt ? microtime(true) - $this->openedAt : 0;

        SecureLogger::warning("Circuit breaker rejected call", [
            'service' => $this->serviceName,
            'time_open' => round($timeOpen, 2),
            'timeout' => $this->timeout,
            'last_failure' => $this->lastFailureReason
        ]);
    }

    /**
     * Get current state
     *
     * @return string Current state
     */
    public function getState(): string {
        $this->checkStateTransition();
        return $this->state;
    }

    /**
     * Check if circuit is open
     *
     * @return bool True if open
     */
    public function isOpen(): bool {
        return $this->getState() === self::STATE_OPEN;
    }

    /**
     * Check if circuit is closed
     *
     * @return bool True if closed
     */
    public function isClosed(): bool {
        return $this->getState() === self::STATE_CLOSED;
    }

    /**
     * Check if circuit is half-open
     *
     * @return bool True if half-open
     */
    public function isHalfOpen(): bool {
        return $this->getState() === self::STATE_HALF_OPEN;
    }

    /**
     * Manually reset the circuit breaker
     */
    public function reset(): void {
        SecureLogger::info("Circuit breaker manually reset", [
            'service' => $this->serviceName,
            'previous_state' => $this->state
        ]);

        $this->transitionTo(self::STATE_CLOSED);
    }

    /**
     * Manually trip the circuit breaker
     *
     * @param string $reason Reason for manual trip
     */
    public function trip(string $reason = 'Manual trip'): void {
        SecureLogger::warning("Circuit breaker manually tripped", [
            'service' => $this->serviceName,
            'previous_state' => $this->state,
            'reason' => $reason
        ]);

        $this->lastFailureReason = $reason;
        $this->lastFailureTime = microtime(true);
        $this->transitionTo(self::STATE_OPEN);
    }

    /**
     * Add a state change listener
     *
     * @param callable $listener Listener function(string $service, string $oldState, string $newState)
     */
    public function addStateChangeListener(callable $listener): void {
        $this->stateChangeListeners[] = $listener;
    }

    /**
     * Notify state change listeners
     *
     * @param string $oldState Previous state
     * @param string $newState New state
     */
    private function notifyStateChange(string $oldState, string $newState): void {
        foreach ($this->stateChangeListeners as $listener) {
            try {
                $listener($this->serviceName, $oldState, $newState);
            } catch (\Exception $e) {
                SecureLogger::error("State change listener failed", [
                    'service' => $this->serviceName,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Get circuit breaker statistics
     *
     * @return array Statistics
     */
    public function getStats(): array {
        return array_merge($this->stats, [
            'current_state' => $this->state,
            'failure_count' => $this->failureCount,
            'success_count' => $this->successCount,
            'failure_threshold' => $this->failureThreshold,
            'success_threshold' => $this->successThreshold,
            'timeout' => $this->timeout,
            'last_failure_time' => $this->lastFailureTime,
            'last_failure_reason' => $this->lastFailureReason,
            'time_until_half_open' => $this->getTimeUntilHalfOpen()
        ]);
    }

    /**
     * Get time remaining until half-open attempt
     *
     * @return float|null Seconds until half-open, or null if not applicable
     */
    public function getTimeUntilHalfOpen(): ?float {
        if ($this->state !== self::STATE_OPEN || $this->openedAt === null) {
            return null;
        }

        $elapsed = microtime(true) - $this->openedAt;
        $remaining = $this->timeout - $elapsed;

        return max(0, $remaining);
    }

    /**
     * Update configuration
     *
     * @param int $failureThreshold New failure threshold
     * @param int $timeout New timeout in seconds
     * @param int $successThreshold New success threshold
     */
    public function updateConfig(
        int $failureThreshold = null,
        int $timeout = null,
        int $successThreshold = null
    ): void {
        if ($failureThreshold !== null) {
            $this->failureThreshold = max(1, $failureThreshold);
        }

        if ($timeout !== null) {
            $this->timeout = max(1, $timeout);
        }

        if ($successThreshold !== null) {
            $this->successThreshold = max(1, $successThreshold);
        }

        SecureLogger::info("Circuit breaker configuration updated", [
            'service' => $this->serviceName,
            'failure_threshold' => $this->failureThreshold,
            'timeout' => $this->timeout,
            'success_threshold' => $this->successThreshold
        ]);
    }
}