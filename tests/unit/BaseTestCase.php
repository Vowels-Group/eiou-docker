<?php
/**
 * Base test case for EIOU unit tests
 *
 * Provides common testing utilities and helpers for all test classes.
 * Includes assertion helpers, mock creation, and test data generators.
 */

namespace Tests\Unit;

require_once __DIR__ . '/mocks/MockPDO.php';
require_once __DIR__ . '/mocks/MockUserContext.php';

use Tests\Unit\Mocks\MockPDO;
use Tests\Unit\Mocks\MockUserContext;

abstract class BaseTestCase
{
    protected array $testResults = [];
    protected int $assertionCount = 0;
    protected int $failureCount = 0;
    protected string $currentTest = '';

    /**
     * Run all tests in the class
     */
    public function run(): void
    {
        $methods = get_class_methods($this);
        $testMethods = array_filter($methods, fn($m) => str_starts_with($m, 'test'));

        echo "\n" . str_repeat('=', 70) . "\n";
        echo "Running " . get_class($this) . "\n";
        echo str_repeat('=', 70) . "\n\n";

        foreach ($testMethods as $method) {
            $this->currentTest = $method;
            $this->setUp();

            try {
                $this->$method();
                $this->testResults[$method] = 'PASS';
                echo "✓ {$method}\n";
            } catch (\Exception $e) {
                $this->testResults[$method] = 'FAIL';
                $this->failureCount++;
                echo "✗ {$method}\n";
                echo "  Error: {$e->getMessage()}\n";
                echo "  File: {$e->getFile()}:{$e->getLine()}\n";
            } finally {
                $this->tearDown();
            }
        }

        $this->printSummary();
    }

    /**
     * Setup before each test
     */
    protected function setUp(): void
    {
        // Override in child classes if needed
    }

    /**
     * Cleanup after each test
     */
    protected function tearDown(): void
    {
        // Override in child classes if needed
    }

    /**
     * Print test summary
     */
    protected function printSummary(): void
    {
        $total = count($this->testResults);
        $passed = count(array_filter($this->testResults, fn($r) => $r === 'PASS'));
        $failed = $this->failureCount;

        echo "\n" . str_repeat('-', 70) . "\n";
        echo "Tests: {$total}, Passed: {$passed}, Failed: {$failed}\n";
        echo "Assertions: {$this->assertionCount}\n";

        if ($failed === 0) {
            echo "✓ ALL TESTS PASSED\n";
        } else {
            echo "✗ SOME TESTS FAILED\n";
        }
        echo str_repeat('=', 70) . "\n\n";
    }

    // Assertion methods

    protected function assertTrue($condition, string $message = ''): void
    {
        $this->assertionCount++;
        if (!$condition) {
            throw new \Exception($message ?: 'Assertion failed: expected true, got false');
        }
    }

    protected function assertFalse($condition, string $message = ''): void
    {
        $this->assertionCount++;
        if ($condition) {
            throw new \Exception($message ?: 'Assertion failed: expected false, got true');
        }
    }

    protected function assertEquals($expected, $actual, string $message = ''): void
    {
        $this->assertionCount++;
        if ($expected !== $actual) {
            $exp = json_encode($expected);
            $act = json_encode($actual);
            throw new \Exception($message ?: "Assertion failed: expected {$exp}, got {$act}");
        }
    }

    protected function assertNotEquals($notExpected, $actual, string $message = ''): void
    {
        $this->assertionCount++;
        if ($notExpected === $actual) {
            $val = json_encode($actual);
            throw new \Exception($message ?: "Assertion failed: expected not {$val}, but got it");
        }
    }

    protected function assertNull($actual, string $message = ''): void
    {
        $this->assertionCount++;
        if ($actual !== null) {
            $act = json_encode($actual);
            throw new \Exception($message ?: "Assertion failed: expected null, got {$act}");
        }
    }

    protected function assertNotNull($actual, string $message = ''): void
    {
        $this->assertionCount++;
        if ($actual === null) {
            throw new \Exception($message ?: 'Assertion failed: expected non-null value, got null');
        }
    }

    protected function assertCount(int $expected, $array, string $message = ''): void
    {
        $this->assertionCount++;
        $actual = is_array($array) ? count($array) : (is_countable($array) ? count($array) : 0);
        if ($expected !== $actual) {
            throw new \Exception($message ?: "Assertion failed: expected count {$expected}, got {$actual}");
        }
    }

    protected function assertArrayHasKey($key, array $array, string $message = ''): void
    {
        $this->assertionCount++;
        if (!array_key_exists($key, $array)) {
            throw new \Exception($message ?: "Assertion failed: array does not have key '{$key}'");
        }
    }

    protected function assertContains($needle, $haystack, string $message = ''): void
    {
        $this->assertionCount++;
        if (is_array($haystack)) {
            if (!in_array($needle, $haystack, true)) {
                $val = json_encode($needle);
                throw new \Exception($message ?: "Assertion failed: array does not contain {$val}");
            }
        } elseif (is_string($haystack)) {
            if (strpos($haystack, $needle) === false) {
                throw new \Exception($message ?: "Assertion failed: string does not contain '{$needle}'");
            }
        } else {
            throw new \Exception('assertContains requires array or string haystack');
        }
    }

    protected function assertGreaterThan($expected, $actual, string $message = ''): void
    {
        $this->assertionCount++;
        if ($actual <= $expected) {
            throw new \Exception($message ?: "Assertion failed: expected {$actual} > {$expected}");
        }
    }

    protected function assertLessThan($expected, $actual, string $message = ''): void
    {
        $this->assertionCount++;
        if ($actual >= $expected) {
            throw new \Exception($message ?: "Assertion failed: expected {$actual} < {$expected}");
        }
    }

    protected function assertInstanceOf(string $expected, $actual, string $message = ''): void
    {
        $this->assertionCount++;
        if (!($actual instanceof $expected)) {
            $actualType = is_object($actual) ? get_class($actual) : gettype($actual);
            throw new \Exception($message ?: "Assertion failed: expected instance of {$expected}, got {$actualType}");
        }
    }

    protected function expectException(string $exceptionClass, callable $callback): void
    {
        $this->assertionCount++;
        try {
            $callback();
            throw new \Exception("Expected exception {$exceptionClass} was not thrown");
        } catch (\Exception $e) {
            if (!($e instanceof $exceptionClass)) {
                throw new \Exception("Expected {$exceptionClass}, got " . get_class($e) . ": {$e->getMessage()}");
            }
        }
    }

    // Mock factory methods

    protected function createMockPDO(): MockPDO
    {
        return new MockPDO();
    }

    protected function createMockUserContext(string $address = 'test-address'): MockUserContext
    {
        return new MockUserContext($address);
    }

    // Test data generators

    protected function generateMessageId(): string
    {
        return 'msg_' . bin2hex(random_bytes(16));
    }

    protected function generateAddress(): string
    {
        return 'addr_' . bin2hex(random_bytes(20));
    }

    protected function generateTimestamp(): int
    {
        return time();
    }

    protected function createTestMessage(array $overrides = []): array
    {
        return array_merge([
            'id' => $this->generateMessageId(),
            'from' => $this->generateAddress(),
            'to' => $this->generateAddress(),
            'amount' => rand(1, 1000),
            'currency' => 'USD',
            'timestamp' => $this->generateTimestamp(),
            'status' => 'pending'
        ], $overrides);
    }
}
