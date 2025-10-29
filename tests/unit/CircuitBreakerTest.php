<?php
/**
 * Unit tests for CircuitBreaker class
 *
 * @package Tests\Unit
 */

require_once dirname(__DIR__, 2) . '/src/services/resilience/CircuitBreaker.php';

class CircuitBreakerTest {
    private CircuitBreaker $circuitBreaker;
    private int $testsPassed = 0;
    private int $testsFailed = 0;

    /**
     * Run all tests
     */
    public function runAll(): void {
        echo "Running CircuitBreaker Tests...\n";
        echo "================================\n\n";

        $this->testInitialState();
        $this->testSuccessfulCalls();
        $this->testFailureThreshold();
        $this->testOpenState();
        $this->testHalfOpenState();
        $this->testStateTransitions();
        $this->testManualControls();
        $this->testStatistics();
        $this->testConfigUpdate();
        $this->testStateChangeListeners();

        $this->printSummary();
    }

    /**
     * Test initial state is closed
     */
    private function testInitialState(): void {
        echo "Test: Initial State\n";

        $this->circuitBreaker = new CircuitBreaker('test_service');

        $this->assert($this->circuitBreaker->isClosed(), "Circuit should be CLOSED initially");
        $this->assert(!$this->circuitBreaker->isOpen(), "Circuit should not be OPEN initially");
        $this->assert(!$this->circuitBreaker->isHalfOpen(), "Circuit should not be HALF_OPEN initially");

        echo "✓ Initial state tests passed\n\n";
    }

    /**
     * Test successful calls don't open circuit
     */
    private function testSuccessfulCalls(): void {
        echo "Test: Successful Calls\n";

        $this->circuitBreaker = new CircuitBreaker('test_service', 3);

        // Make successful calls
        for ($i = 0; $i < 5; $i++) {
            $result = $this->circuitBreaker->call(function() use ($i) {
                return "success_$i";
            });

            $this->assert($result === "success_$i", "Call $i should return expected result");
        }

        $this->assert($this->circuitBreaker->isClosed(), "Circuit should remain CLOSED after successful calls");

        echo "✓ Successful calls tests passed\n\n";
    }

    /**
     * Test failure threshold opens circuit
     */
    private function testFailureThreshold(): void {
        echo "Test: Failure Threshold\n";

        $this->circuitBreaker = new CircuitBreaker('test_service', 3, 1);

        // Make failing calls
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->circuitBreaker->call(function() {
                    throw new Exception("Test failure");
                });
                $this->fail("Call should have thrown exception");
            } catch (Exception $e) {
                $this->assert($e->getMessage() === "Test failure", "Exception message should match");
            }
        }

        $this->assert($this->circuitBreaker->isOpen(), "Circuit should be OPEN after reaching failure threshold");

        echo "✓ Failure threshold tests passed\n\n";
    }

    /**
     * Test open state rejects calls
     */
    private function testOpenState(): void {
        echo "Test: Open State Behavior\n";

        $this->circuitBreaker = new CircuitBreaker('test_service', 1, 60);

        // Trip the circuit
        try {
            $this->circuitBreaker->call(function() {
                throw new Exception("Trip circuit");
            });
        } catch (Exception $e) {
            // Expected
        }

        $this->assert($this->circuitBreaker->isOpen(), "Circuit should be OPEN");

        // Try to make a call when open
        try {
            $this->circuitBreaker->call(function() {
                return "should not execute";
            });
            $this->fail("Open circuit should reject calls");
        } catch (RuntimeException $e) {
            $this->assert(strpos($e->getMessage(), "Circuit breaker is OPEN") !== false, "Should get circuit open exception");
        }

        echo "✓ Open state tests passed\n\n";
    }

    /**
     * Test half-open state behavior
     */
    private function testHalfOpenState(): void {
        echo "Test: Half-Open State\n";

        $this->circuitBreaker = new CircuitBreaker('test_service', 1, 0, 2); // 0 second timeout for testing

        // Trip the circuit
        try {
            $this->circuitBreaker->call(function() {
                throw new Exception("Trip");
            });
        } catch (Exception $e) {
            // Expected
        }

        // Wait a moment to allow transition to half-open
        usleep(100000); // 0.1 seconds

        // First successful call in half-open
        $result = $this->circuitBreaker->call(function() {
            return "success1";
        });
        $this->assert($result === "success1", "First half-open call should succeed");
        $this->assert($this->circuitBreaker->isHalfOpen(), "Should still be HALF_OPEN after one success");

        // Second successful call should close circuit
        $result = $this->circuitBreaker->call(function() {
            return "success2";
        });
        $this->assert($result === "success2", "Second half-open call should succeed");
        $this->assert($this->circuitBreaker->isClosed(), "Should be CLOSED after success threshold");

        echo "✓ Half-open state tests passed\n\n";
    }

    /**
     * Test state transitions
     */
    private function testStateTransitions(): void {
        echo "Test: State Transitions\n";

        $this->circuitBreaker = new CircuitBreaker('test_service', 2, 0, 1);

        // CLOSED -> OPEN
        $this->assert($this->circuitBreaker->getState() === CircuitBreaker::STATE_CLOSED, "Should start CLOSED");

        for ($i = 0; $i < 2; $i++) {
            try {
                $this->circuitBreaker->call(function() {
                    throw new Exception("Fail");
                });
            } catch (Exception $e) {
                // Expected
            }
        }

        $this->assert($this->circuitBreaker->getState() === CircuitBreaker::STATE_OPEN, "Should transition to OPEN");

        // OPEN -> HALF_OPEN (after timeout)
        usleep(100000);
        $this->assert($this->circuitBreaker->getState() === CircuitBreaker::STATE_HALF_OPEN, "Should transition to HALF_OPEN");

        // HALF_OPEN -> CLOSED
        $this->circuitBreaker->call(function() {
            return "success";
        });
        $this->assert($this->circuitBreaker->getState() === CircuitBreaker::STATE_CLOSED, "Should transition to CLOSED");

        echo "✓ State transition tests passed\n\n";
    }

    /**
     * Test manual controls
     */
    private function testManualControls(): void {
        echo "Test: Manual Controls\n";

        $this->circuitBreaker = new CircuitBreaker('test_service');

        // Manual trip
        $this->circuitBreaker->trip("Manual test");
        $this->assert($this->circuitBreaker->isOpen(), "Manual trip should open circuit");

        // Manual reset
        $this->circuitBreaker->reset();
        $this->assert($this->circuitBreaker->isClosed(), "Manual reset should close circuit");

        echo "✓ Manual control tests passed\n\n";
    }

    /**
     * Test statistics tracking
     */
    private function testStatistics(): void {
        echo "Test: Statistics\n";

        $this->circuitBreaker = new CircuitBreaker('test_service', 5);

        // Make some calls
        for ($i = 0; $i < 3; $i++) {
            $this->circuitBreaker->call(function() {
                return "success";
            });
        }

        for ($i = 0; $i < 2; $i++) {
            try {
                $this->circuitBreaker->call(function() {
                    throw new Exception("Fail");
                });
            } catch (Exception $e) {
                // Expected
            }
        }

        $stats = $this->circuitBreaker->getStats();

        $this->assert($stats['total_calls'] === 5, "Should track total calls");
        $this->assert($stats['successful_calls'] === 3, "Should track successful calls");
        $this->assert($stats['failed_calls'] === 2, "Should track failed calls");
        $this->assert($stats['current_state'] === CircuitBreaker::STATE_CLOSED, "Should include current state");

        echo "✓ Statistics tests passed\n\n";
    }

    /**
     * Test configuration update
     */
    private function testConfigUpdate(): void {
        echo "Test: Configuration Update\n";

        $this->circuitBreaker = new CircuitBreaker('test_service', 5, 60, 3);

        // Update configuration
        $this->circuitBreaker->updateConfig(2, 30, 1);

        // Test new failure threshold
        for ($i = 0; $i < 2; $i++) {
            try {
                $this->circuitBreaker->call(function() {
                    throw new Exception("Fail");
                });
            } catch (Exception $e) {
                // Expected
            }
        }

        $this->assert($this->circuitBreaker->isOpen(), "Should open with new threshold");

        echo "✓ Configuration update tests passed\n\n";
    }

    /**
     * Test state change listeners
     */
    private function testStateChangeListeners(): void {
        echo "Test: State Change Listeners\n";

        $this->circuitBreaker = new CircuitBreaker('test_service', 1);

        $stateChanges = [];
        $this->circuitBreaker->addStateChangeListener(function($service, $oldState, $newState) use (&$stateChanges) {
            $stateChanges[] = [
                'service' => $service,
                'from' => $oldState,
                'to' => $newState
            ];
        });

        // Trigger state change
        try {
            $this->circuitBreaker->call(function() {
                throw new Exception("Fail");
            });
        } catch (Exception $e) {
            // Expected
        }

        $this->assert(count($stateChanges) === 1, "Should notify listener of state change");
        $this->assert($stateChanges[0]['from'] === CircuitBreaker::STATE_CLOSED, "Should track old state");
        $this->assert($stateChanges[0]['to'] === CircuitBreaker::STATE_OPEN, "Should track new state");

        echo "✓ State change listener tests passed\n\n";
    }

    /**
     * Assert helper
     */
    private function assert(bool $condition, string $message): void {
        if (!$condition) {
            $this->testsFailed++;
            throw new AssertionError("Assertion failed: $message");
        }
        $this->testsPassed++;
    }

    /**
     * Fail helper
     */
    private function fail(string $message): void {
        $this->testsFailed++;
        throw new AssertionError("Test failed: $message");
    }

    /**
     * Print test summary
     */
    private function printSummary(): void {
        echo "================================\n";
        echo "Test Summary:\n";
        echo "  Passed: {$this->testsPassed}\n";
        echo "  Failed: {$this->testsFailed}\n";

        if ($this->testsFailed === 0) {
            echo "\n✓ All CircuitBreaker tests passed!\n";
        } else {
            echo "\n✗ Some tests failed.\n";
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    $tester = new CircuitBreakerTest();
    $tester->runAll();
}