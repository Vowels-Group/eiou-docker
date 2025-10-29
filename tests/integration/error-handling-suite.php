#!/usr/bin/env php
<?php
/**
 * Error Handling Components Integration Test Suite
 *
 * Tests the integration of ConnectionManager, CircuitBreaker, and ResilientP2pMessageProcessor
 *
 * @package Tests\Integration
 */

require_once dirname(__DIR__, 2) . '/src/services/database/ConnectionManager.php';
require_once dirname(__DIR__, 2) . '/src/services/resilience/CircuitBreaker.php';
require_once dirname(__DIR__, 2) . '/src/processors/ResilientP2pMessageProcessor.php';

class ErrorHandlingIntegrationTest {
    private int $testsPassed = 0;
    private int $testsFailed = 0;
    private array $errors = [];

    /**
     * Run all integration tests
     */
    public function runAll(): void {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║     Error Handling Components Integration Test Suite         ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";

        $this->testComponentsExist();
        $this->testConnectionManagerCircuitBreaker();
        $this->testResilientP2pProcessor();
        $this->testErrorRecoveryFlow();
        $this->testStatisticsIntegration();
        $this->testDegradedModeOperation();

        $this->printSummary();
    }

    /**
     * Test that all components exist and can be instantiated
     */
    private function testComponentsExist(): void {
        echo "▶ Testing Component Availability...\n";

        try {
            // Test ConnectionManager
            $connectionManager = ConnectionManager::getInstance();
            $this->assert($connectionManager instanceof ConnectionManager,
                "ConnectionManager should instantiate");

            // Test CircuitBreaker
            $circuitBreaker = new CircuitBreaker('test');
            $this->assert($circuitBreaker instanceof CircuitBreaker,
                "CircuitBreaker should instantiate");

            // Test ResilientP2pMessageProcessor
            $processor = new ResilientP2pMessageProcessor();
            $this->assert($processor instanceof ResilientP2pMessageProcessor,
                "ResilientP2pMessageProcessor should instantiate");
            $this->assert($processor instanceof P2pMessageProcessor,
                "ResilientP2pMessageProcessor should extend P2pMessageProcessor");

            echo "  ✓ All components instantiated successfully\n\n";
        } catch (Exception $e) {
            $this->recordError("Component instantiation failed: " . $e->getMessage());
        }
    }

    /**
     * Test ConnectionManager and CircuitBreaker integration
     */
    private function testConnectionManagerCircuitBreaker(): void {
        echo "▶ Testing ConnectionManager + CircuitBreaker Integration...\n";

        try {
            $manager = ConnectionManager::getInstance();

            // Get initial stats
            $stats = $manager->getStats();
            $this->assert(isset($stats['circuit_breaker_state']),
                "Stats should include circuit breaker state");

            // Test that circuit breaker is integrated
            $reflection = new ReflectionClass($manager);
            $cbProperty = $reflection->getProperty('circuitBreaker');
            $cbProperty->setAccessible(true);
            $circuitBreaker = $cbProperty->getValue($manager);

            $this->assert($circuitBreaker instanceof CircuitBreaker,
                "ConnectionManager should have CircuitBreaker instance");

            echo "  ✓ ConnectionManager properly integrated with CircuitBreaker\n\n";
        } catch (Exception $e) {
            $this->recordError("ConnectionManager/CircuitBreaker integration failed: " . $e->getMessage());
        }
    }

    /**
     * Test ResilientP2pMessageProcessor features
     */
    private function testResilientP2pProcessor(): void {
        echo "▶ Testing ResilientP2pMessageProcessor Features...\n";

        try {
            $processor = new ResilientP2pMessageProcessor();

            // Test hook registration
            $hookCalled = false;
            $processor->registerHook('beforeProcess', function() use (&$hookCalled) {
                $hookCalled = true;
            });

            // Test statistics
            $stats = $processor->getStats();
            $this->assert(is_array($stats), "Should return statistics array");
            $this->assert(isset($stats['messages_processed']), "Should track messages processed");
            $this->assert(isset($stats['circuit_breaker_state']), "Should include circuit breaker state");
            $this->assert(isset($stats['degraded_mode']), "Should track degraded mode");

            // Test reset functionality
            $processor->reset();
            $statsAfterReset = $processor->getStats();
            $this->assert($statsAfterReset['consecutive_failures'] === 0,
                "Reset should clear consecutive failures");

            echo "  ✓ ResilientP2pMessageProcessor features working\n\n";
        } catch (Exception $e) {
            $this->recordError("ResilientP2pMessageProcessor test failed: " . $e->getMessage());
        }
    }

    /**
     * Test error recovery flow
     */
    private function testErrorRecoveryFlow(): void {
        echo "▶ Testing Error Recovery Flow...\n";

        try {
            // Test CircuitBreaker recovery flow
            $cb = new CircuitBreaker('test', 2, 0, 1); // Quick timeout for testing

            // Trip the circuit
            for ($i = 0; $i < 2; $i++) {
                try {
                    $cb->call(function() {
                        throw new Exception("Test failure");
                    });
                } catch (Exception $e) {
                    // Expected
                }
            }

            $this->assert($cb->isOpen(), "Circuit should be open after failures");

            // Wait for timeout
            usleep(100000);

            // Should transition to half-open
            $this->assert($cb->isHalfOpen(), "Circuit should transition to half-open");

            // Successful call should close
            $cb->call(function() {
                return "success";
            });

            $this->assert($cb->isClosed(), "Circuit should close after successful recovery");

            echo "  ✓ Error recovery flow working correctly\n\n";
        } catch (Exception $e) {
            $this->recordError("Error recovery flow test failed: " . $e->getMessage());
        }
    }

    /**
     * Test statistics integration across components
     */
    private function testStatisticsIntegration(): void {
        echo "▶ Testing Statistics Integration...\n";

        try {
            $manager = ConnectionManager::getInstance();
            $processor = new ResilientP2pMessageProcessor();

            // Get stats from both components
            $managerStats = $manager->getStats();
            $processorStats = $processor->getStats();

            // Verify stats structure
            $this->assert(isset($managerStats['total_connections']),
                "ConnectionManager should track total connections");
            $this->assert(isset($managerStats['circuit_breaker_state']),
                "ConnectionManager should include circuit breaker state");

            $this->assert(isset($processorStats['messages_processed']),
                "Processor should track messages processed");
            $this->assert(isset($processorStats['connection_stats']),
                "Processor should include connection stats");

            // Verify nested stats
            $this->assert(is_array($processorStats['circuit_breaker_stats']),
                "Processor should include circuit breaker stats");
            $this->assert(is_array($processorStats['connection_stats']),
                "Processor should include connection manager stats");

            echo "  ✓ Statistics properly integrated across components\n\n";
        } catch (Exception $e) {
            $this->recordError("Statistics integration test failed: " . $e->getMessage());
        }
    }

    /**
     * Test degraded mode operation
     */
    private function testDegradedModeOperation(): void {
        echo "▶ Testing Degraded Mode Operation...\n";

        try {
            $processor = new ResilientP2pMessageProcessor();

            // Check initial state
            $stats = $processor->getStats();
            $this->assert($stats['degraded_mode'] === false,
                "Should not start in degraded mode");

            // Test that degraded mode can be tracked
            $reflection = new ReflectionClass($processor);
            $degradedProp = $reflection->getProperty('degradedMode');
            $degradedProp->setAccessible(true);

            $this->assert($degradedProp->getValue($processor) === false,
                "Degraded mode should be false initially");

            // Test configuration exists
            $maxFailuresProp = $reflection->getProperty('maxConsecutiveFailures');
            $maxFailuresProp->setAccessible(true);
            $maxFailures = $maxFailuresProp->getValue($processor);

            $this->assert($maxFailures > 0,
                "Should have max consecutive failures configured");

            echo "  ✓ Degraded mode operation configured correctly\n\n";
        } catch (Exception $e) {
            $this->recordError("Degraded mode test failed: " . $e->getMessage());
        }
    }

    /**
     * Assert helper
     */
    private function assert(bool $condition, string $message): void {
        if (!$condition) {
            $this->testsFailed++;
            $this->recordError($message);
            throw new AssertionError($message);
        }
        $this->testsPassed++;
    }

    /**
     * Record an error
     */
    private function recordError(string $error): void {
        $this->errors[] = $error;
        echo "  ✗ $error\n";
    }

    /**
     * Print test summary
     */
    private function printSummary(): void {
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║                      Test Summary                            ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";

        $total = $this->testsPassed + $this->testsFailed;
        $percentage = $total > 0 ? round(($this->testsPassed / $total) * 100, 1) : 0;

        printf("║  Total Tests:     %-43d║\n", $total);
        printf("║  Tests Passed:    %-43d║\n", $this->testsPassed);
        printf("║  Tests Failed:    %-43d║\n", $this->testsFailed);
        printf("║  Success Rate:    %-42s║\n", "{$percentage}%");

        echo "╚══════════════════════════════════════════════════════════════╝\n\n";

        if ($this->testsFailed === 0) {
            echo "🎉 SUCCESS: All integration tests passed!\n\n";
            echo "The error handling components are properly integrated and working.\n";
            echo "Components tested:\n";
            echo "  • ConnectionManager with retry logic and circuit breaker\n";
            echo "  • CircuitBreaker with state management\n";
            echo "  • ResilientP2pMessageProcessor with recovery mechanisms\n";
            echo "  • Statistics integration across all components\n";
        } else {
            echo "⚠️  WARNING: Some tests failed.\n\n";
            echo "Errors encountered:\n";
            foreach ($this->errors as $error) {
                echo "  • $error\n";
            }
            echo "\nPlease review the failed tests and fix any issues.\n";
        }

        echo "\n";
    }
}

// Run the test suite
echo "Starting Error Handling Integration Tests...\n";

$testSuite = new ErrorHandlingIntegrationTest();
$testSuite->runAll();

// Also run individual unit tests if they exist
echo "\n";
echo "════════════════════════════════════════════════════════════════\n";
echo "Running Individual Unit Tests...\n";
echo "════════════════════════════════════════════════════════════════\n\n";

// Run CircuitBreaker tests
if (file_exists(__DIR__ . '/../unit/CircuitBreakerTest.php')) {
    require_once __DIR__ . '/../unit/CircuitBreakerTest.php';
    $cbTest = new CircuitBreakerTest();
    $cbTest->runAll();
}

// Run ConnectionManager tests
if (file_exists(__DIR__ . '/../unit/ConnectionManagerTest.php')) {
    echo "\n";
    require_once __DIR__ . '/../unit/ConnectionManagerTest.php';
    $cmTest = new ConnectionManagerTest();
    $cmTest->runAll();
}

echo "\n";
echo "════════════════════════════════════════════════════════════════\n";
echo "All tests completed.\n";
echo "════════════════════════════════════════════════════════════════\n";