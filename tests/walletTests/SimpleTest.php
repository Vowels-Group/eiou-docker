<?php
/**
 * Simple testing framework for eIOU project
 * Lightweight alternative to PHPUnit that doesn't require external dependencies
 */

class SimpleTest {
    private static $tests = [];
    private static $passed = 0;
    private static $failed = 0;
    private static $currentTest = '';

    /**
     * Register a test
     */
    public static function test($name, $callback) {
        self::$tests[$name] = $callback;
    }

    /**
     * Assert that a condition is true
     */
    public static function assertTrue($condition, $message = '') {
        if (!$condition) {
            throw new Exception($message ?: "Assertion failed");
        }
    }

    /**
     * Assert that two values are equal
     */
    public static function assertEquals($expected, $actual, $message = '') {
        if ($expected !== $actual) {
            $msg = $message ?: "Expected: " . var_export($expected, true) .
                   " but got: " . var_export($actual, true);
            throw new Exception($msg);
        }
    }

    /**
     * Assert that a value is null
     */
    public static function assertNull($value, $message = '') {
        if ($value !== null) {
            throw new Exception($message ?: "Expected null but got: " . var_export($value, true));
        }
    }

    /**
     * Assert that a value is not null
     */
    public static function assertNotNull($value, $message = '') {
        if ($value === null) {
            throw new Exception($message ?: "Expected non-null value but got null");
        }
    }

    /**
     * Assert that an array contains a key
     */
    public static function assertArrayHasKey($key, $array, $message = '') {
        if (!is_array($array) || !array_key_exists($key, $array)) {
            throw new Exception($message ?: "Array does not have key: $key");
        }
    }

    /**
     * Assert that a string contains a substring
     */
    public static function assertStringContains($needle, $haystack, $message = '') {
        if (strpos($haystack, $needle) === false) {
            throw new Exception($message ?: "String does not contain: $needle");
        }
    }

    /**
     * Assert that a string does not contain a substring
     */
    public static function assertStringNotContains($needle, $haystack, $message = '') {
        if (strpos($haystack, $needle) !== false) {
            throw new Exception($message ?: "String should not contain: $needle");
        }
    }

    /**
     * Run all registered tests
     */
    public static function run() {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "Running Tests\n";
        echo str_repeat('=', 60) . "\n\n";

        foreach (self::$tests as $name => $callback) {
            self::$currentTest = $name;
            echo "Running: $name\n";

            try {
                $callback();
                self::$passed++;
                echo "  ✓ PASSED\n";
            } catch (Exception $e) {
                self::$failed++;
                echo "  ✗ FAILED: " . $e->getMessage() . "\n";
                echo "    at " . $e->getFile() . ":" . $e->getLine() . "\n";
            }
            echo "\n";
        }

        // Print summary
        echo str_repeat('=', 60) . "\n";
        echo "Test Results\n";
        echo str_repeat('=', 60) . "\n";
        echo "Passed: " . self::$passed . "\n";
        echo "Failed: " . self::$failed . "\n";
        echo "Total:  " . (self::$passed + self::$failed) . "\n";

        if (self::$failed > 0) {
            echo "\n❌ TESTS FAILED\n";
            exit(1);
        } else {
            echo "\n✅ ALL TESTS PASSED\n";
            exit(0);
        }
    }

    /**
     * Mock a function for testing
     */
    public static function mockFunction($functionName, $returnValue) {
        // Simple function mocking - would need eval() for full implementation
        // For now, just return a mock object
        return new class($functionName, $returnValue) {
            private $name;
            private $value;
            private $calls = [];

            public function __construct($name, $value) {
                $this->name = $name;
                $this->value = $value;
            }

            public function __invoke(...$args) {
                $this->calls[] = $args;
                return $this->value;
            }

            public function getCalls() {
                return $this->calls;
            }

            public function wasCalled() {
                return count($this->calls) > 0;
            }

            public function wasCalledWith(...$args) {
                foreach ($this->calls as $call) {
                    if ($call === $args) {
                        return true;
                    }
                }
                return false;
            }
        };
    }
}

// Helper function to run a test file
function runTestFile($file) {
    require_once $file;
    SimpleTest::run();
}