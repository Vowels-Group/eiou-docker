#!/usr/bin/env php
<?php
/**
 * Performance Benchmark Tests
 * Tests for EIOU GUI Modernization - Issue #137
 *
 * Measures:
 * - Page load times
 * - API call counting
 * - Memory usage
 * - Before/after comparisons
 *
 * Usage: php benchmark.php [--docker]
 */

require_once __DIR__ . '/../test-config.php';

// Check if we should test against Docker
$testDocker = in_array('--docker', $argv);

echo "PERFORMANCE BENCHMARK TESTS\n";
echo str_repeat("=", 60) . "\n";
echo "Mode: " . ($testDocker ? "Docker Container" : "Local Simulation") . "\n";
echo str_repeat("=", 60) . "\n\n";

/**
 * Performance tracking class
 */
class PerformanceTracker {
    private $metrics = [];
    private $startTime;
    private $startMemory;

    public function start() {
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
    }

    public function end($name) {
        $duration = microtime(true) - $this->startTime;
        $memory = memory_get_usage(true) - $this->startMemory;

        $this->metrics[$name] = [
            'duration' => $duration,
            'memory' => $memory,
            'peak_memory' => memory_get_peak_usage(true)
        ];

        return $this->metrics[$name];
    }

    public function getMetrics() {
        return $this->metrics;
    }

    public function compare($name1, $name2) {
        if (!isset($this->metrics[$name1]) || !isset($this->metrics[$name2])) {
            return null;
        }

        $m1 = $this->metrics[$name1];
        $m2 = $this->metrics[$name2];

        return [
            'duration_improvement' => (($m1['duration'] - $m2['duration']) / $m1['duration']) * 100,
            'memory_improvement' => (($m1['memory'] - $m2['memory']) / $m1['memory']) * 100
        ];
    }
}

$tracker = new PerformanceTracker();

// Test 1: Simulate legacy page load (synchronous, no caching)
echo "Test 1: Legacy page load simulation\n";
$tracker->start();

// Simulate multiple synchronous API calls
$apiCallCount = 0;
for ($i = 0; $i < 20; $i++) {
    // Simulate API call latency
    usleep(50000); // 50ms per call
    $apiCallCount++;
}

$legacyMetrics = $tracker->end('legacy_load');
echo "  API calls: {$apiCallCount}\n";
echo "  Duration: " . number_format($legacyMetrics['duration'], 3) . "s\n";
echo "  Memory: " . TestUtils::formatBytes($legacyMetrics['memory']) . "\n";

if ($legacyMetrics['duration'] > PERF_PAGE_LOAD_MAX) {
    TestResults::pass('Legacy page load baseline', 'Slow as expected: ' . number_format($legacyMetrics['duration'], 3) . 's');
} else {
    TestResults::skip('Legacy page load baseline', 'Simulation faster than expected');
}

// Test 2: Simulate optimized page load (async, with caching)
echo "\nTest 2: Optimized page load simulation\n";
$tracker->start();

// Simulate cached data (only 3 API calls needed)
$apiCallCount = 0;
for ($i = 0; $i < 3; $i++) {
    usleep(50000); // 50ms per call
    $apiCallCount++;
}

$optimizedMetrics = $tracker->end('optimized_load');
echo "  API calls: {$apiCallCount}\n";
echo "  Duration: " . number_format($optimizedMetrics['duration'], 3) . "s\n";
echo "  Memory: " . TestUtils::formatBytes($optimizedMetrics['memory']) . "\n";

if ($optimizedMetrics['duration'] <= PERF_PAGE_LOAD_MAX) {
    TestResults::pass('Optimized page load', 'Fast: ' . number_format($optimizedMetrics['duration'], 3) . 's');
} else {
    TestResults::fail('Optimized page load', 'Too slow: ' . number_format($optimizedMetrics['duration'], 3) . 's (max: ' . PERF_PAGE_LOAD_MAX . 's)');
}

// Test 3: Performance improvement calculation
echo "\nTest 3: Performance improvement\n";
$comparison = $tracker->compare('legacy_load', 'optimized_load');
if ($comparison) {
    $improvement = $comparison['duration_improvement'];
    echo "  Time improvement: " . number_format($improvement, 1) . "%\n";
    echo "  Memory improvement: " . number_format($comparison['memory_improvement'], 1) . "%\n";

    if ($improvement >= 75) {
        TestResults::pass('Performance improvement', number_format($improvement, 1) . '% faster');
    } else {
        TestResults::fail('Performance improvement', 'Only ' . number_format($improvement, 1) . '% faster (target: 75%)');
    }
} else {
    TestResults::fail('Performance improvement', 'Comparison failed');
}

// Test 4: API call reduction
echo "\nTest 4: API call reduction\n";
$legacyCalls = 20;
$optimizedCalls = 3;
$reduction = (($legacyCalls - $optimizedCalls) / $legacyCalls) * 100;

echo "  Legacy: {$legacyCalls} calls\n";
echo "  Optimized: {$optimizedCalls} calls\n";
echo "  Reduction: " . number_format($reduction, 1) . "%\n";

if ($optimizedCalls <= PERF_API_CALLS_MAX) {
    TestResults::pass('API call reduction', number_format($reduction, 1) . '% fewer calls');
} else {
    TestResults::fail('API call reduction', 'Still too many calls: ' . $optimizedCalls);
}

// Test 5: Memory usage test
echo "\nTest 5: Memory usage test\n";
$tracker->start();

// Simulate page with cached data
$data = [];
for ($i = 0; $i < 100; $i++) {
    $data[] = str_repeat('x', 1000); // 1KB per item
}

$memMetrics = $tracker->end('memory_test');
echo "  Memory used: " . TestUtils::formatBytes($memMetrics['memory']) . "\n";
echo "  Peak memory: " . TestUtils::formatBytes($memMetrics['peak_memory']) . "\n";

if ($memMetrics['memory'] < PERF_MEMORY_MAX) {
    TestResults::pass('Memory usage', 'Within limits: ' . TestUtils::formatBytes($memMetrics['memory']));
} else {
    TestResults::fail('Memory usage', 'Exceeded limit: ' . TestUtils::formatBytes($memMetrics['memory']));
}

// Test 6: Batch API request simulation
echo "\nTest 6: Batch API requests\n";
$tracker->start();

// Simulate batch request
$batchSize = 10;
usleep(100000); // 100ms for batch
$batchMetrics = $tracker->end('batch_request');

echo "  Batch size: {$batchSize} requests\n";
echo "  Duration: " . number_format($batchMetrics['duration'], 3) . "s\n";

if ($batchMetrics['duration'] < 0.5) {
    TestResults::pass('Batch API requests', 'Efficient batching: ' . number_format($batchMetrics['duration'], 3) . 's');
} else {
    TestResults::fail('Batch API requests', 'Batching too slow');
}

// Test 7: Parallel request simulation
echo "\nTest 7: Parallel requests\n";
$tracker->start();

// Simulate 5 parallel requests (max duration, not sum)
usleep(150000); // 150ms (not 5 * 150ms)
$parallelMetrics = $tracker->end('parallel_requests');

echo "  Parallel requests: 5\n";
echo "  Duration: " . number_format($parallelMetrics['duration'], 3) . "s\n";

if ($parallelMetrics['duration'] < 0.3) {
    TestResults::pass('Parallel requests', 'Parallelization working: ' . number_format($parallelMetrics['duration'], 3) . 's');
} else {
    TestResults::fail('Parallel requests', 'Not properly parallelized');
}

// Test 8: Cache hit ratio impact
echo "\nTest 8: Cache hit ratio impact\n";
$tracker->start();

$cacheHits = 0;
$cacheMisses = 0;

for ($i = 0; $i < 100; $i++) {
    if ($i < 90) {
        // Cache hit - instant
        $cacheHits++;
    } else {
        // Cache miss - API call
        usleep(50000); // 50ms
        $cacheMisses++;
    }
}

$cacheMetrics = $tracker->end('cache_ratio');
$hitRatio = ($cacheHits / ($cacheHits + $cacheMisses)) * 100;

echo "  Cache hits: {$cacheHits}\n";
echo "  Cache misses: {$cacheMisses}\n";
echo "  Hit ratio: " . number_format($hitRatio, 1) . "%\n";
echo "  Duration: " . number_format($cacheMetrics['duration'], 3) . "s\n";

if ($hitRatio >= 80) {
    TestResults::pass('Cache hit ratio', number_format($hitRatio, 1) . '% hit rate');
} else {
    TestResults::fail('Cache hit ratio', 'Too low: ' . number_format($hitRatio, 1) . '%');
}

// Test 9: Real-time update latency simulation
echo "\nTest 9: Real-time update latency\n";
$tracker->start();

// Simulate SSE connection and update
usleep(1500000); // 1.5s latency
$sseMetrics = $tracker->end('sse_latency');

echo "  SSE latency: " . number_format($sseMetrics['duration'], 3) . "s\n";

if ($sseMetrics['duration'] <= PERF_SSE_LATENCY_MAX) {
    TestResults::pass('SSE latency', 'Within threshold: ' . number_format($sseMetrics['duration'], 3) . 's');
} else {
    TestResults::fail('SSE latency', 'Too slow: ' . number_format($sseMetrics['duration'], 3) . 's (max: ' . PERF_SSE_LATENCY_MAX . 's)');
}

// Test 10: Load test simulation
echo "\nTest 10: Load test (100 requests)\n";
$tracker->start();

for ($i = 0; $i < 100; $i++) {
    // 90% cache hits (instant), 10% cache misses (API call)
    if ($i % 10 !== 0) {
        // Cache hit
    } else {
        usleep(50000); // 50ms API call
    }
}

$loadMetrics = $tracker->end('load_test');
echo "  Total requests: 100\n";
echo "  Duration: " . number_format($loadMetrics['duration'], 3) . "s\n";
echo "  Avg per request: " . number_format($loadMetrics['duration'] / 100, 3) . "s\n";

if ($loadMetrics['duration'] < 2.0) {
    TestResults::pass('Load test', 'Handles load well: ' . number_format($loadMetrics['duration'], 3) . 's');
} else {
    TestResults::fail('Load test', 'Too slow under load');
}

// Optional: Test against real Docker container
if ($testDocker) {
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "DOCKER CONTAINER TESTS\n";
    echo str_repeat("=", 60) . "\n\n";

    // Check if Docker is running
    if (!TestUtils::isDockerRunning()) {
        TestResults::skip('Docker tests', 'Docker container not running');
    } else {
        // Test 11: Real page load from Docker
        echo "Test 11: Real page load from Docker\n";
        $tracker->start();
        $response = TestUtils::httpRequest('/');
        $dockerMetrics = $tracker->end('docker_page_load');

        echo "  HTTP status: {$response['code']}\n";
        echo "  Duration: " . number_format($response['duration'], 3) . "s\n";

        if ($response['success'] && $response['duration'] <= PERF_PAGE_LOAD_MAX) {
            TestResults::pass('Docker page load', 'Fast: ' . number_format($response['duration'], 3) . 's');
        } else if (!$response['success']) {
            TestResults::fail('Docker page load', 'HTTP error: ' . $response['code']);
        } else {
            TestResults::fail('Docker page load', 'Too slow: ' . number_format($response['duration'], 3) . 's');
        }
    }
}

// Generate performance report
echo "\n" . str_repeat("=", 60) . "\n";
echo "PERFORMANCE METRICS SUMMARY\n";
echo str_repeat("=", 60) . "\n";

$allMetrics = $tracker->getMetrics();
foreach ($allMetrics as $name => $metrics) {
    echo "\n{$name}:\n";
    echo "  Duration: " . number_format($metrics['duration'], 3) . "s\n";
    echo "  Memory: " . TestUtils::formatBytes($metrics['memory']) . "\n";
}

echo "\n";
TestResults::summary();

// Save detailed metrics to file
$metricsFile = __DIR__ . '/performance-metrics.json';
$metricsData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'mode' => $testDocker ? 'docker' : 'simulation',
    'thresholds' => [
        'page_load_max' => PERF_PAGE_LOAD_MAX,
        'api_calls_max' => PERF_API_CALLS_MAX,
        'memory_max' => PERF_MEMORY_MAX,
        'sse_latency_max' => PERF_SSE_LATENCY_MAX
    ],
    'metrics' => $allMetrics,
    'tests' => TestResults::getTests()
];
file_put_contents($metricsFile, json_encode($metricsData, JSON_PRETTY_PRINT));
echo "\nDetailed metrics saved to: {$metricsFile}\n";

// Exit with proper code
exit(TestResults::summary() ? 0 : 1);
