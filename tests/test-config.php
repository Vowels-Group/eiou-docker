<?php
/**
 * Test Configuration for EIOU GUI Modernization Test Suite
 *
 * This file contains shared configuration and utilities for all test suites.
 * Use this for setting up test environments, database connections, and common helpers.
 */

// Test environment configuration
define('TEST_MODE', true);
define('TEST_START_TIME', microtime(true));

// Docker container configuration
define('DOCKER_COMPOSE_FILE', 'docker-compose-single.yml');
define('DOCKER_SERVICE_NAME', 'alice');
define('DOCKER_BASE_URL', 'http://localhost:8080');

// Performance thresholds (from Issue #137)
define('PERF_PAGE_LOAD_MAX', 0.5);        // Max 0.5s page load
define('PERF_API_CALLS_MAX', 3);          // Max 3 API calls per page
define('PERF_MEMORY_MAX', 80 * 1024 * 1024); // Max 80MB memory
define('PERF_SSE_LATENCY_MAX', 2.0);      // Max 2s SSE latency

// Test result tracking
class TestResults {
    private static $tests = [];
    private static $passed = 0;
    private static $failed = 0;
    private static $skipped = 0;

    public static function pass($testName, $message = '') {
        self::$passed++;
        self::$tests[] = [
            'status' => 'PASS',
            'name' => $testName,
            'message' => $message,
            'time' => microtime(true) - TEST_START_TIME
        ];
        echo "✅ PASS: {$testName}" . ($message ? " - {$message}" : "") . "\n";
    }

    public static function fail($testName, $message = '') {
        self::$failed++;
        self::$tests[] = [
            'status' => 'FAIL',
            'name' => $testName,
            'message' => $message,
            'time' => microtime(true) - TEST_START_TIME
        ];
        echo "❌ FAIL: {$testName}" . ($message ? " - {$message}" : "") . "\n";
    }

    public static function skip($testName, $reason = '') {
        self::$skipped++;
        self::$tests[] = [
            'status' => 'SKIP',
            'name' => $testName,
            'message' => $reason,
            'time' => microtime(true) - TEST_START_TIME
        ];
        echo "⏭️  SKIP: {$testName}" . ($reason ? " - {$reason}" : "") . "\n";
    }

    public static function summary() {
        $total = self::$passed + self::$failed + self::$skipped;
        $duration = microtime(true) - TEST_START_TIME;

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "TEST SUMMARY\n";
        echo str_repeat("=", 60) . "\n";
        echo "Total:   {$total}\n";
        echo "Passed:  " . self::$passed . " ✅\n";
        echo "Failed:  " . self::$failed . " ❌\n";
        echo "Skipped: " . self::$skipped . " ⏭️\n";
        echo "Duration: " . number_format($duration, 3) . "s\n";
        echo str_repeat("=", 60) . "\n";

        return self::$failed === 0;
    }

    public static function getTests() {
        return self::$tests;
    }

    public static function saveToFile($filename) {
        $data = [
            'summary' => [
                'total' => self::$passed + self::$failed + self::$skipped,
                'passed' => self::$passed,
                'failed' => self::$failed,
                'skipped' => self::$skipped,
                'duration' => microtime(true) - TEST_START_TIME
            ],
            'tests' => self::$tests,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));
    }
}

// Test utilities
class TestUtils {
    /**
     * Check if Docker container is running
     */
    public static function isDockerRunning() {
        $output = [];
        exec('docker ps --filter "name=' . DOCKER_SERVICE_NAME . '" --format "{{.Names}}"', $output);
        return in_array(DOCKER_SERVICE_NAME, $output);
    }

    /**
     * Execute command in Docker container
     */
    public static function dockerExec($command) {
        $cmd = 'docker compose -f ' . DOCKER_COMPOSE_FILE . ' exec -T ' . DOCKER_SERVICE_NAME . ' ' . $command;
        exec($cmd, $output, $returnCode);
        return [
            'output' => implode("\n", $output),
            'code' => $returnCode,
            'success' => $returnCode === 0
        ];
    }

    /**
     * Make HTTP request to Docker container
     */
    public static function httpRequest($path, $method = 'GET', $data = null) {
        $url = DOCKER_BASE_URL . $path;
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        $startTime = microtime(true);
        $response = curl_exec($ch);
        $duration = microtime(true) - $startTime;
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        return [
            'body' => $response,
            'code' => $httpCode,
            'duration' => $duration,
            'error' => $error,
            'success' => $httpCode >= 200 && $httpCode < 300
        ];
    }

    /**
     * Measure memory usage
     */
    public static function getMemoryUsage() {
        return memory_get_usage(true);
    }

    /**
     * Measure peak memory usage
     */
    public static function getPeakMemoryUsage() {
        return memory_get_peak_usage(true);
    }

    /**
     * Format bytes to human readable
     */
    public static function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Wait for condition with timeout
     */
    public static function waitFor($condition, $timeout = 10, $interval = 0.5) {
        $start = microtime(true);
        while (microtime(true) - $start < $timeout) {
            if ($condition()) {
                return true;
            }
            usleep($interval * 1000000);
        }
        return false;
    }
}

// Auto-save results on script shutdown
register_shutdown_function(function() {
    TestResults::saveToFile(__DIR__ . '/test-results.json');
});

echo "Test configuration loaded\n";
echo "Docker service: " . DOCKER_SERVICE_NAME . "\n";
echo "Base URL: " . DOCKER_BASE_URL . "\n";
echo str_repeat("-", 60) . "\n\n";
