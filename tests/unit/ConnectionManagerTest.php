<?php
/**
 * Unit tests for ConnectionManager class
 *
 * @package Tests\Unit
 */

require_once dirname(__DIR__, 2) . '/src/services/database/ConnectionManager.php';
require_once dirname(__DIR__, 2) . '/src/core/DatabaseContext.php';

class ConnectionManagerTest {
    private int $testsPassed = 0;
    private int $testsFailed = 0;
    private array $originalDatabase;

    /**
     * Run all tests
     */
    public function runAll(): void {
        echo "Running ConnectionManager Tests...\n";
        echo "==================================\n\n";

        // Store original database config
        global $database;
        $this->originalDatabase = $database ?? [];

        $this->testSingletonPattern();
        $this->testConnectionRetry();
        $this->testCircuitBreakerIntegration();
        $this->testConnectionValidation();
        $this->testStatistics();
        $this->testRetryConfiguration();
        $this->testExecuteWithRetry();
        $this->testConnectionPooling();

        // Restore original database config
        $database = $this->originalDatabase;

        $this->printSummary();
    }

    /**
     * Test singleton pattern
     */
    private function testSingletonPattern(): void {
        echo "Test: Singleton Pattern\n";

        $instance1 = ConnectionManager::getInstance();
        $instance2 = ConnectionManager::getInstance();

        $this->assert($instance1 === $instance2, "Should return same instance");
        $this->assert($instance1 instanceof ConnectionManager, "Should be ConnectionManager instance");

        echo "✓ Singleton pattern tests passed\n\n";
    }

    /**
     * Test connection retry logic
     */
    private function testConnectionRetry(): void {
        echo "Test: Connection Retry Logic\n";

        // Mock a failing then succeeding database
        $this->setupMockDatabase(false);

        $manager = ConnectionManager::getInstance();
        $manager->setRetryConfig(2, 100, 1.5); // Quick retries for testing

        try {
            // This should fail since we have invalid config
            $connection = $manager->getConnection(true);
            $this->fail("Should have thrown exception with invalid config");
        } catch (RuntimeException $e) {
            $this->assert(strpos($e->getMessage(), "Failed to establish database connection") !== false,
                "Should get connection failure message");
        }

        echo "✓ Connection retry tests passed\n\n";
    }

    /**
     * Test circuit breaker integration
     */
    private function testCircuitBreakerIntegration(): void {
        echo "Test: Circuit Breaker Integration\n";

        // Reset the singleton for clean state
        $reflection = new ReflectionClass('ConnectionManager');
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);

        $this->setupMockDatabase(false);
        $manager = ConnectionManager::getInstance();

        // Make multiple failing attempts to trip circuit breaker
        $exceptions = 0;
        for ($i = 0; $i < 6; $i++) {
            try {
                $manager->getConnection(true);
            } catch (RuntimeException $e) {
                $exceptions++;
            }
        }

        $this->assert($exceptions >= 5, "Should have multiple failures");

        // Next attempt should fail immediately due to open circuit
        try {
            $manager->getConnection(true);
            $this->fail("Circuit breaker should be open");
        } catch (RuntimeException $e) {
            $this->assert(strpos($e->getMessage(), "circuit breaker is open") !== false,
                "Should get circuit breaker open message");
        }

        echo "✓ Circuit breaker integration tests passed\n\n";
    }

    /**
     * Test connection validation
     */
    private function testConnectionValidation(): void {
        echo "Test: Connection Validation\n";

        // This test requires actual database or mock PDO
        // For now, we'll test the structure

        $manager = ConnectionManager::getInstance();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('isConnectionValid');

        $this->assert($method !== null, "Should have isConnectionValid method");
        $this->assert($method->isPrivate(), "isConnectionValid should be private");

        echo "✓ Connection validation structure tests passed\n\n";
    }

    /**
     * Test statistics tracking
     */
    private function testStatistics(): void {
        echo "Test: Statistics Tracking\n";

        // Reset singleton
        $reflection = new ReflectionClass('ConnectionManager');
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);

        $manager = ConnectionManager::getInstance();
        $stats = $manager->getStats();

        $this->assert(is_array($stats), "Should return statistics array");
        $this->assert(isset($stats['total_connections']), "Should track total connections");
        $this->assert(isset($stats['successful_connections']), "Should track successful connections");
        $this->assert(isset($stats['failed_connections']), "Should track failed connections");
        $this->assert(isset($stats['circuit_breaker_state']), "Should include circuit breaker state");

        echo "✓ Statistics tests passed\n\n";
    }

    /**
     * Test retry configuration
     */
    private function testRetryConfiguration(): void {
        echo "Test: Retry Configuration\n";

        $manager = ConnectionManager::getInstance();

        // Test setting valid configuration
        $manager->setRetryConfig(5, 500, 2.5);

        $reflection = new ReflectionClass($manager);

        $maxRetry = $reflection->getProperty('maxRetryAttempts');
        $maxRetry->setAccessible(true);
        $this->assert($maxRetry->getValue($manager) === 5, "Should set max retry attempts");

        $baseDelay = $reflection->getProperty('baseRetryDelayMs');
        $baseDelay->setAccessible(true);
        $this->assert($baseDelay->getValue($manager) === 500, "Should set base delay");

        $backoff = $reflection->getProperty('backoffMultiplier');
        $backoff->setAccessible(true);
        $this->assert($backoff->getValue($manager) === 2.5, "Should set backoff multiplier");

        // Test boundary validation
        $manager->setRetryConfig(15, 15000, 5.0); // Beyond limits

        $this->assert($maxRetry->getValue($manager) === 10, "Should cap max attempts at 10");
        $this->assert($baseDelay->getValue($manager) === 10000, "Should cap delay at 10000ms");
        $this->assert($backoff->getValue($manager) === 3.0, "Should cap multiplier at 3.0");

        echo "✓ Retry configuration tests passed\n\n";
    }

    /**
     * Test executeWithRetry method
     */
    private function testExecuteWithRetry(): void {
        echo "Test: Execute With Retry\n";

        $manager = ConnectionManager::getInstance();

        // Test successful execution
        $attemptCount = 0;
        $result = $manager->executeWithRetry(function($conn) use (&$attemptCount) {
            $attemptCount++;
            if ($attemptCount < 2) {
                throw new PDOException("Simulated failure");
            }
            return "success";
        }, 3);

        $this->assert($result === "success", "Should return callback result");
        $this->assert($attemptCount === 2, "Should retry on failure");

        echo "✓ Execute with retry tests passed\n\n";
    }

    /**
     * Test connection pooling
     */
    private function testConnectionPooling(): void {
        echo "Test: Connection Pooling\n";

        $manager = ConnectionManager::getInstance();

        // Enable pooling
        $manager->setPooling(true, 3);

        $reflection = new ReflectionClass($manager);
        $usePooling = $reflection->getProperty('usePooling');
        $usePooling->setAccessible(true);
        $this->assert($usePooling->getValue($manager) === true, "Should enable pooling");

        $maxSize = $reflection->getProperty('maxPoolSize');
        $maxSize->setAccessible(true);
        $this->assert($maxSize->getValue($manager) === 3, "Should set max pool size");

        // Disable pooling
        $manager->setPooling(false);
        $this->assert($usePooling->getValue($manager) === false, "Should disable pooling");

        echo "✓ Connection pooling tests passed\n\n";
    }

    /**
     * Setup mock database configuration
     */
    private function setupMockDatabase(bool $valid): void {
        global $database;

        if ($valid) {
            $database = [
                'dbHost' => 'localhost',
                'dbName' => 'test_db',
                'dbUser' => 'test_user',
                'dbPass' => 'test_pass'
            ];
        } else {
            $database = [
                'dbHost' => null,
                'dbName' => null,
                'dbUser' => null,
                'dbPass' => null
            ];
        }
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
        echo "==================================\n";
        echo "Test Summary:\n";
        echo "  Passed: {$this->testsPassed}\n";
        echo "  Failed: {$this->testsFailed}\n";

        if ($this->testsFailed === 0) {
            echo "\n✓ All ConnectionManager tests passed!\n";
        } else {
            echo "\n✗ Some tests failed.\n";
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    $tester = new ConnectionManagerTest();
    $tester->runAll();
}