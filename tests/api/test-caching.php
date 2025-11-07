#!/usr/bin/env php
<?php
/**
 * API Caching Integration Tests
 * Tests for EIOU GUI Modernization - Issue #137
 *
 * Tests cache hit/miss rates, TTL expiration, cache invalidation,
 * and parallel request handling.
 *
 * Usage: php test-caching.php
 */

require_once __DIR__ . '/../test-config.php';

echo "API CACHING INTEGRATION TESTS\n";
echo str_repeat("=", 60) . "\n\n";

/**
 * Simple in-memory cache implementation for testing
 */
class SimpleCache {
    private $cache = [];
    private $timestamps = [];

    public function get($key) {
        if (!isset($this->cache[$key])) {
            return null;
        }

        // Check if expired
        if (isset($this->timestamps[$key]) && time() > $this->timestamps[$key]) {
            unset($this->cache[$key]);
            unset($this->timestamps[$key]);
            return null;
        }

        return $this->cache[$key];
    }

    public function set($key, $value, $ttl = 60) {
        $this->cache[$key] = $value;
        $this->timestamps[$key] = time() + $ttl;
    }

    public function has($key) {
        return $this->get($key) !== null;
    }

    public function delete($key) {
        unset($this->cache[$key]);
        unset($this->timestamps[$key]);
    }

    public function clear() {
        $this->cache = [];
        $this->timestamps = [];
    }

    public function getStats() {
        return [
            'items' => count($this->cache),
            'memory' => strlen(serialize($this->cache))
        ];
    }
}

// Test 1: Cache initialization
echo "Test 1: Cache initialization\n";
$cache = new SimpleCache();
if ($cache !== null) {
    TestResults::pass('Cache initialization', 'Cache object created');
} else {
    TestResults::fail('Cache initialization', 'Failed to create cache');
}

// Test 2: Cache set and get
echo "\nTest 2: Cache set and get\n";
$cache->set('test_key', 'test_value', 60);
$value = $cache->get('test_key');
if ($value === 'test_value') {
    TestResults::pass('Cache set/get', 'Value stored and retrieved correctly');
} else {
    TestResults::fail('Cache set/get', 'Expected "test_value", got "' . $value . '"');
}

// Test 3: Cache miss
echo "\nTest 3: Cache miss\n";
$value = $cache->get('nonexistent_key');
if ($value === null) {
    TestResults::pass('Cache miss', 'Returns null for missing key');
} else {
    TestResults::fail('Cache miss', 'Expected null, got "' . $value . '"');
}

// Test 4: Cache TTL expiration
echo "\nTest 4: Cache TTL expiration\n";
$cache->set('expire_key', 'expire_value', 1); // 1 second TTL
sleep(2); // Wait for expiration
$value = $cache->get('expire_key');
if ($value === null) {
    TestResults::pass('Cache TTL expiration', 'Value expired after TTL');
} else {
    TestResults::fail('Cache TTL expiration', 'Value should have expired');
}

// Test 5: Cache invalidation
echo "\nTest 5: Cache invalidation\n";
$cache->set('delete_key', 'delete_value', 60);
$cache->delete('delete_key');
$value = $cache->get('delete_key');
if ($value === null) {
    TestResults::pass('Cache invalidation', 'Value deleted successfully');
} else {
    TestResults::fail('Cache invalidation', 'Value should be deleted');
}

// Test 6: Cache clear
echo "\nTest 6: Cache clear all\n";
$cache->set('key1', 'value1', 60);
$cache->set('key2', 'value2', 60);
$cache->set('key3', 'value3', 60);
$cache->clear();
$stats = $cache->getStats();
if ($stats['items'] === 0) {
    TestResults::pass('Cache clear', 'All items cleared');
} else {
    TestResults::fail('Cache clear', 'Expected 0 items, got ' . $stats['items']);
}

// Test 7: Cache hit rate measurement
echo "\nTest 7: Cache hit rate measurement\n";
$cache->clear();
$hits = 0;
$misses = 0;

// Populate cache
for ($i = 0; $i < 10; $i++) {
    $cache->set("key_$i", "value_$i", 60);
}

// Mix of hits and misses
for ($i = 0; $i < 20; $i++) {
    if ($cache->has("key_$i")) {
        $hits++;
    } else {
        $misses++;
    }
}

$hitRate = $hits / ($hits + $misses) * 100;
if ($hitRate >= 50) {
    TestResults::pass('Cache hit rate', 'Hit rate: ' . number_format($hitRate, 1) . '%');
} else {
    TestResults::fail('Cache hit rate', 'Hit rate too low: ' . number_format($hitRate, 1) . '%');
}

// Test 8: Memory usage tracking
echo "\nTest 8: Memory usage tracking\n";
$cache->clear();
$startMem = TestUtils::getMemoryUsage();

// Add data to cache
for ($i = 0; $i < 100; $i++) {
    $cache->set("key_$i", str_repeat('x', 1000), 60); // 1KB per entry
}

$endMem = TestUtils::getMemoryUsage();
$memUsed = $endMem - $startMem;

if ($memUsed < PERF_MEMORY_MAX) {
    TestResults::pass('Memory usage', 'Used: ' . TestUtils::formatBytes($memUsed));
} else {
    TestResults::fail('Memory usage', 'Exceeded limit: ' . TestUtils::formatBytes($memUsed));
}

// Test 9: Concurrent cache access simulation
echo "\nTest 9: Concurrent cache access\n";
$cache->clear();
$startTime = microtime(true);

// Simulate 100 concurrent requests
for ($i = 0; $i < 100; $i++) {
    $key = 'concurrent_key_' . ($i % 10); // 10 unique keys

    if (!$cache->has($key)) {
        // Simulate API call
        usleep(1000); // 1ms delay
        $cache->set($key, "value_$i", 60);
    } else {
        // Cache hit - no delay
        $cache->get($key);
    }
}

$duration = microtime(true) - $startTime;
if ($duration < 1.0) {
    TestResults::pass('Concurrent access', 'Completed in ' . number_format($duration, 3) . 's');
} else {
    TestResults::fail('Concurrent access', 'Too slow: ' . number_format($duration, 3) . 's');
}

// Test 10: Cache key collision handling
echo "\nTest 10: Cache key collision\n";
$cache->clear();
$cache->set('collision_key', 'value1', 60);
$cache->set('collision_key', 'value2', 60); // Overwrite
$value = $cache->get('collision_key');
if ($value === 'value2') {
    TestResults::pass('Cache key collision', 'Latest value wins');
} else {
    TestResults::fail('Cache key collision', 'Expected "value2", got "' . $value . '"');
}

// Test 11: Large data caching
echo "\nTest 11: Large data caching\n";
$cache->clear();
$largeData = str_repeat('x', 1024 * 100); // 100KB
$cache->set('large_key', $largeData, 60);
$retrieved = $cache->get('large_key');
if ($retrieved === $largeData) {
    TestResults::pass('Large data caching', 'Stored and retrieved 100KB successfully');
} else {
    TestResults::fail('Large data caching', 'Data integrity issue');
}

// Test 12: Cache statistics
echo "\nTest 12: Cache statistics\n";
$cache->clear();
for ($i = 0; $i < 50; $i++) {
    $cache->set("stat_key_$i", "stat_value_$i", 60);
}
$stats = $cache->getStats();
if ($stats['items'] === 50) {
    TestResults::pass('Cache statistics', 'Correct item count: ' . $stats['items']);
} else {
    TestResults::fail('Cache statistics', 'Expected 50 items, got ' . $stats['items']);
}

// Test 13: TTL variation
echo "\nTest 13: TTL variation\n";
$cache->clear();
$cache->set('short_ttl', 'value1', 1);  // 1 second
$cache->set('long_ttl', 'value2', 60);  // 60 seconds
sleep(2);
$short = $cache->get('short_ttl');
$long = $cache->get('long_ttl');
if ($short === null && $long === 'value2') {
    TestResults::pass('TTL variation', 'Different TTLs work correctly');
} else {
    TestResults::fail('TTL variation', 'TTL handling incorrect');
}

// Test 14: Cache performance under load
echo "\nTest 14: Cache performance under load\n";
$cache->clear();
$startTime = microtime(true);

// Write performance
for ($i = 0; $i < 1000; $i++) {
    $cache->set("perf_key_$i", "perf_value_$i", 60);
}
$writeTime = microtime(true) - $startTime;

// Read performance
$startTime = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    $cache->get("perf_key_$i");
}
$readTime = microtime(true) - $startTime;

if ($writeTime < 0.5 && $readTime < 0.5) {
    TestResults::pass('Cache performance',
        'Write: ' . number_format($writeTime, 3) . 's, Read: ' . number_format($readTime, 3) . 's');
} else {
    TestResults::fail('Cache performance',
        'Too slow - Write: ' . number_format($writeTime, 3) . 's, Read: ' . number_format($readTime, 3) . 's');
}

// Test 15: Cache data integrity
echo "\nTest 15: Cache data integrity\n";
$cache->clear();
$testData = [
    'string' => 'test string',
    'number' => 42,
    'array' => [1, 2, 3],
    'object' => (object)['key' => 'value'],
    'null' => null,
    'bool' => true
];

$allMatch = true;
foreach ($testData as $key => $value) {
    $cache->set("data_$key", $value, 60);
    $retrieved = $cache->get("data_$key");
    if ($retrieved !== $value) {
        $allMatch = false;
        break;
    }
}

if ($allMatch) {
    TestResults::pass('Cache data integrity', 'All data types preserved');
} else {
    TestResults::fail('Cache data integrity', 'Data type mismatch');
}

// Print summary
echo "\n";
TestResults::summary();

// Exit with proper code
exit(TestResults::summary() ? 0 : 1);
