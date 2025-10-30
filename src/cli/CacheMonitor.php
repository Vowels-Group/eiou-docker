<?php
/**
 * Cache Monitor CLI
 *
 * Command-line tool for monitoring and managing the Docker cache system.
 * Provides real-time statistics, performance metrics, and cache management.
 *
 * @package CLI
 * @copyright 2025
 */

require_once '/etc/eiou/src/cache/DockerCache.php';

class CacheMonitor {
    /**
     * @var DockerCache Cache instance
     */
    private DockerCache $cache;

    /**
     * @var bool Enable colored output
     */
    private bool $useColors = true;

    /**
     * Constructor
     */
    public function __construct() {
        $this->cache = DockerCache::getInstance();

        // Detect if terminal supports colors
        $this->useColors = (PHP_SAPI === 'cli' && posix_isatty(STDOUT));
    }

    /**
     * Execute cache monitor command
     *
     * @param array $args Command arguments
     * @return void
     */
    public function execute(array $args): void {
        $command = $args[0] ?? 'stats';

        switch ($command) {
            case 'stats':
                $this->displayStatistics();
                break;

            case 'monitor':
                $this->liveMonitor();
                break;

            case 'clear':
                $this->clearCache($args[1] ?? null);
                break;

            case 'export':
                $this->exportCache();
                break;

            case 'types':
                $this->displayCacheTypes();
                break;

            case 'performance':
                $this->displayPerformanceMetrics();
                break;

            case 'warmup':
                $this->warmupCache();
                break;

            case 'test':
                $this->runCacheTest();
                break;

            case 'help':
            default:
                $this->displayHelp();
                break;
        }
    }

    /**
     * Display cache statistics
     *
     * @return void
     */
    private function displayStatistics(): void {
        $stats = $this->cache->getStats();

        $this->printHeader("Docker Cache Statistics");

        echo $this->colorize("General Information:", 'cyan', true) . "\n";
        echo "  Total Entries:       " . $this->colorize($stats['total_entries'], 'white') . "\n";
        echo "  Memory Usage:        " . $this->formatBytes($stats['memory_usage']) . "\n";
        echo "  Memory Peak:         " . $this->formatBytes($stats['memory_peak']) . "\n";
        echo "  Uptime:              " . $this->formatDuration($stats['uptime']) . "\n";

        echo "\n" . $this->colorize("Performance Metrics:", 'cyan', true) . "\n";
        echo "  Cache Hits:          " . $this->colorize($stats['hits'], 'green') . "\n";
        echo "  Cache Misses:        " . $this->colorize($stats['misses'], 'red') . "\n";
        echo "  Hit Rate:            " . $this->formatHitRate($stats['hit_rate']) . "\n";
        echo "  Total Sets:          " . $this->colorize($stats['sets'], 'white') . "\n";
        echo "  Total Deletes:       " . $this->colorize($stats['deletes'], 'white') . "\n";
        echo "  Invalidations:       " . $this->colorize($stats['invalidations'], 'yellow') . "\n";
        echo "  Batch Operations:    " . $this->colorize($stats['batch_operations'], 'white') . "\n";

        if ($stats['total_entries'] > 0) {
            echo "  Avg Entry Size:      " . $this->formatBytes($stats['avg_entry_size']) . "\n";
        }

        // Display type distribution if available
        if (!empty($stats['type_distribution'])) {
            echo "\n" . $this->colorize("Cache Type Distribution:", 'cyan', true) . "\n";

            foreach ($stats['type_distribution'] as $type => $count) {
                $percentage = round(($count / $stats['total_entries']) * 100, 1);
                $bar = $this->createProgressBar($percentage, 30);
                echo sprintf("  %-20s %s %3d (%5.1f%%)\n",
                    $type . ":",
                    $bar,
                    $count,
                    $percentage
                );
            }
        }

        echo "\n";
    }

    /**
     * Live monitoring mode
     *
     * @return void
     */
    private function liveMonitor(): void {
        // Clear screen
        system('clear');

        echo $this->colorize("Docker Cache Live Monitor", 'cyan', true) . "\n";
        echo $this->colorize("Press Ctrl+C to exit", 'yellow') . "\n\n";

        $previousHits = 0;
        $previousMisses = 0;
        $previousSets = 0;

        while (true) {
            $stats = $this->cache->getStats();

            // Calculate rates
            $hitRate = $previousHits > 0 ? $stats['hits'] - $previousHits : 0;
            $missRate = $previousMisses > 0 ? $stats['misses'] - $previousMisses : 0;
            $setRate = $previousSets > 0 ? $stats['sets'] - $previousSets : 0;

            // Move cursor to position
            echo "\033[4;0H"; // Move to line 4

            // Clear and redraw stats
            echo "\033[K" . $this->colorize("Performance", 'white', true) . "\n";
            echo "\033[K  Hit Rate:     " . $this->formatHitRate($stats['hit_rate']) .
                 "  (+" . $hitRate . "/s)\n";
            echo "\033[K  Total Hits:   " . $this->colorize($stats['hits'], 'green') . "\n";
            echo "\033[K  Total Misses: " . $this->colorize($stats['misses'], 'red') . "\n";
            echo "\033[K\n";

            echo "\033[K" . $this->colorize("Memory", 'white', true) . "\n";
            echo "\033[K  Usage:        " . $this->formatBytes($stats['memory_usage']) . "\n";
            echo "\033[K  Entries:      " . $stats['total_entries'] . "\n";
            echo "\033[K  Avg Size:     " . $this->formatBytes($stats['avg_entry_size'] ?? 0) . "\n";
            echo "\033[K\n";

            echo "\033[K" . $this->colorize("Operations/sec", 'white', true) . "\n";
            echo "\033[K  Hits:         " . $this->colorize("+" . $hitRate, 'green') . "\n";
            echo "\033[K  Misses:       " . $this->colorize("+" . $missRate, 'red') . "\n";
            echo "\033[K  Sets:         " . $this->colorize("+" . $setRate, 'blue') . "\n";

            // Store current values for next iteration
            $previousHits = $stats['hits'];
            $previousMisses = $stats['misses'];
            $previousSets = $stats['sets'];

            sleep(1);
        }
    }

    /**
     * Clear cache
     *
     * @param string|null $type Type to clear or null for all
     * @return void
     */
    private function clearCache(?string $type = null): void {
        if ($type) {
            $count = $this->cache->invalidateByType($type);
            echo $this->colorize("✓", 'green') . " Cleared $count entries of type '$type'\n";
        } else {
            // Confirm before clearing all
            echo $this->colorize("WARNING:", 'red', true) .
                 " This will clear ALL cache entries. Continue? (y/N): ";

            $confirm = trim(fgets(STDIN));
            if (strtolower($confirm) === 'y') {
                $this->cache->clear();
                echo $this->colorize("✓", 'green') . " All cache entries cleared\n";
            } else {
                echo "Operation cancelled\n";
            }
        }
    }

    /**
     * Export cache contents
     *
     * @return void
     */
    private function exportCache(): void {
        $data = $this->cache->export();
        $filename = 'cache_export_' . date('Y-m-d_H-i-s') . '.json';

        file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));

        echo $this->colorize("✓", 'green') .
             " Cache exported to " . $this->colorize($filename, 'cyan') . "\n";
        echo "  Total entries: " . count($data) . "\n";
        echo "  File size: " . $this->formatBytes(filesize($filename)) . "\n";
    }

    /**
     * Display cache types
     *
     * @return void
     */
    private function displayCacheTypes(): void {
        $this->printHeader("Available Cache Types");

        $types = [
            'container_list' => 'Container list (5s TTL)',
            'container_details' => 'Container details (10s TTL)',
            'wallet_balance' => 'Wallet balance (10s TTL)',
            'system_metrics' => 'System metrics (15s TTL)',
            'contact_data' => 'Contact data (30s TTL)',
            'transaction_history' => 'Transaction history (20s TTL)',
            'service_instance' => 'Service instances (60s TTL)',
            'user_settings' => 'User settings (120s TTL)',
            'public_keys' => 'Public keys (300s TTL)',
            'api_response' => 'API responses (variable TTL)'
        ];

        foreach ($types as $type => $description) {
            echo "  " . $this->colorize($type, 'cyan') . "\n";
            echo "    " . $description . "\n";
        }

        echo "\n";
    }

    /**
     * Display performance metrics
     *
     * @return void
     */
    private function displayPerformanceMetrics(): void {
        $stats = $this->cache->getStats();

        $this->printHeader("Cache Performance Analysis");

        // Calculate efficiency metrics
        $totalOps = $stats['hits'] + $stats['misses'];
        $efficiency = $totalOps > 0 ? ($stats['hits'] / $totalOps) : 0;
        $avgOpsPerMinute = $stats['uptime'] > 0 ?
            round($totalOps / ($stats['uptime'] / 60), 2) : 0;

        echo $this->colorize("Efficiency Metrics:", 'cyan', true) . "\n";
        echo "  Cache Efficiency:    " . $this->formatPercentage($efficiency * 100) . "\n";
        echo "  Ops/minute:          " . $avgOpsPerMinute . "\n";
        echo "  Memory Efficiency:   " .
             ($stats['total_entries'] > 0 ?
              $this->formatBytes($stats['memory_usage'] / $stats['total_entries']) . "/entry" :
              "N/A") . "\n";

        // Performance recommendations
        echo "\n" . $this->colorize("Performance Recommendations:", 'cyan', true) . "\n";

        if ($stats['hit_rate'] < 50) {
            echo "  " . $this->colorize("⚠", 'yellow') .
                 " Low hit rate detected. Consider:\n";
            echo "    - Increasing TTL values for frequently accessed data\n";
            echo "    - Pre-warming cache with common queries\n";
        }

        if ($stats['memory_peak'] > 40 * 1024 * 1024) { // 40MB
            echo "  " . $this->colorize("⚠", 'yellow') .
                 " High memory usage detected. Consider:\n";
            echo "    - Reducing TTL values\n";
            echo "    - Implementing more aggressive eviction\n";
        }

        if ($stats['invalidations'] > $stats['sets'] * 0.5) {
            echo "  " . $this->colorize("⚠", 'yellow') .
                 " High invalidation rate detected. Consider:\n";
            echo "    - Reviewing invalidation triggers\n";
            echo "    - Using more granular cache keys\n";
        }

        if ($stats['hit_rate'] >= 80) {
            echo "  " . $this->colorize("✓", 'green') .
                 " Excellent cache performance!\n";
        }

        echo "\n";
    }

    /**
     * Warmup cache with test data
     *
     * @return void
     */
    private function warmupCache(): void {
        echo "Warming up cache...\n";

        $testData = [
            'test_balance_user1' => [
                'value' => 1000.50,
                'ttl' => 10,
                'type' => 'wallet_balance'
            ],
            'test_contacts_all' => [
                'value' => ['contact1', 'contact2', 'contact3'],
                'ttl' => 30,
                'type' => 'contact_data'
            ],
            'test_tx_history' => [
                'value' => ['tx1' => 100, 'tx2' => 200],
                'ttl' => 20,
                'type' => 'transaction_history'
            ]
        ];

        $this->cache->warmup($testData);

        echo $this->colorize("✓", 'green') .
             " Cache warmed up with " . count($testData) . " test entries\n";
    }

    /**
     * Run cache performance test
     *
     * @return void
     */
    private function runCacheTest(): void {
        $this->printHeader("Cache Performance Test");

        echo "Running performance test...\n\n";

        $iterations = 10000;
        $testKey = 'perf_test_key';
        $testValue = str_repeat('test_data', 100); // ~900 bytes

        // Test write performance
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->set($testKey . $i, $testValue, 60);
        }
        $writeTime = microtime(true) - $startTime;

        // Test read performance (100% hit rate)
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->get($testKey . $i);
        }
        $readHitTime = microtime(true) - $startTime;

        // Test read performance (100% miss rate)
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->get('nonexistent_key_' . $i);
        }
        $readMissTime = microtime(true) - $startTime;

        // Clean up test data
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->delete($testKey . $i);
        }

        // Display results
        echo $this->colorize("Test Results:", 'cyan', true) . "\n";
        echo "  Iterations:          " . number_format($iterations) . "\n";
        echo "  Data size:           " . strlen($testValue) . " bytes\n\n";

        echo $this->colorize("Write Performance:", 'white', true) . "\n";
        echo "  Total time:          " . round($writeTime, 3) . " seconds\n";
        echo "  Writes/second:       " . number_format(round($iterations / $writeTime)) . "\n";
        echo "  Avg write time:      " . round(($writeTime / $iterations) * 1000, 3) . " ms\n\n";

        echo $this->colorize("Read Performance (hits):", 'white', true) . "\n";
        echo "  Total time:          " . round($readHitTime, 3) . " seconds\n";
        echo "  Reads/second:        " . number_format(round($iterations / $readHitTime)) . "\n";
        echo "  Avg read time:       " . round(($readHitTime / $iterations) * 1000, 3) . " ms\n\n";

        echo $this->colorize("Read Performance (misses):", 'white', true) . "\n";
        echo "  Total time:          " . round($readMissTime, 3) . " seconds\n";
        echo "  Reads/second:        " . number_format(round($iterations / $readMissTime)) . "\n";
        echo "  Avg read time:       " . round(($readMissTime / $iterations) * 1000, 3) . " ms\n\n";

        // Performance assessment
        $hitSpeedup = round($readMissTime / $readHitTime, 2);
        echo $this->colorize("Performance Analysis:", 'cyan', true) . "\n";
        echo "  Cache hit speedup:   " . $this->colorize($hitSpeedup . "x", 'green') . " faster\n";

        if ($hitSpeedup > 5) {
            echo "  " . $this->colorize("✓", 'green') . " Excellent cache performance!\n";
        } elseif ($hitSpeedup > 2) {
            echo "  " . $this->colorize("✓", 'green') . " Good cache performance\n";
        } else {
            echo "  " . $this->colorize("⚠", 'yellow') . " Cache performance could be improved\n";
        }

        echo "\n";
    }

    /**
     * Display help information
     *
     * @return void
     */
    private function displayHelp(): void {
        $this->printHeader("Docker Cache Monitor - Help");

        echo $this->colorize("Usage:", 'cyan', true) . "\n";
        echo "  php CacheMonitor.php [command] [options]\n\n";

        echo $this->colorize("Commands:", 'cyan', true) . "\n";

        $commands = [
            'stats' => 'Display cache statistics (default)',
            'monitor' => 'Live monitoring mode',
            'clear [type]' => 'Clear cache (optionally by type)',
            'export' => 'Export cache contents to JSON',
            'types' => 'List available cache types',
            'performance' => 'Display performance metrics',
            'warmup' => 'Warmup cache with test data',
            'test' => 'Run performance test',
            'help' => 'Display this help message'
        ];

        foreach ($commands as $cmd => $desc) {
            echo sprintf("  %-15s %s\n", $this->colorize($cmd, 'yellow'), $desc);
        }

        echo "\n" . $this->colorize("Examples:", 'cyan', true) . "\n";
        echo "  php CacheMonitor.php stats\n";
        echo "  php CacheMonitor.php clear wallet_balance\n";
        echo "  php CacheMonitor.php monitor\n";

        echo "\n";
    }

    /**
     * Print header
     *
     * @param string $title Header title
     * @return void
     */
    private function printHeader(string $title): void {
        $line = str_repeat("=", 60);
        echo "\n" . $this->colorize($line, 'cyan') . "\n";
        echo $this->colorize($title, 'white', true) . "\n";
        echo $this->colorize($line, 'cyan') . "\n\n";
    }

    /**
     * Format bytes to human readable
     *
     * @param int $bytes Bytes
     * @return string Formatted string
     */
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        $size = $bytes / pow(1024, $factor);

        $color = 'white';
        if ($factor >= 2) { // MB or larger
            $color = 'yellow';
        }

        return $this->colorize(sprintf("%.2f %s", $size, $units[$factor]), $color);
    }

    /**
     * Format duration
     *
     * @param int $seconds Duration in seconds
     * @return string Formatted duration
     */
    private function formatDuration(int $seconds): string {
        if ($seconds < 60) {
            return $seconds . " seconds";
        } elseif ($seconds < 3600) {
            return round($seconds / 60, 1) . " minutes";
        } elseif ($seconds < 86400) {
            return round($seconds / 3600, 1) . " hours";
        } else {
            return round($seconds / 86400, 1) . " days";
        }
    }

    /**
     * Format hit rate
     *
     * @param float $rate Hit rate percentage
     * @return string Formatted hit rate
     */
    private function formatHitRate(float $rate): string {
        $color = 'red';
        if ($rate >= 80) {
            $color = 'green';
        } elseif ($rate >= 50) {
            $color = 'yellow';
        }

        return $this->colorize(sprintf("%.1f%%", $rate), $color, true);
    }

    /**
     * Format percentage
     *
     * @param float $percentage Percentage value
     * @return string Formatted percentage
     */
    private function formatPercentage(float $percentage): string {
        $color = 'white';
        if ($percentage >= 80) {
            $color = 'green';
        } elseif ($percentage >= 50) {
            $color = 'yellow';
        } else {
            $color = 'red';
        }

        return $this->colorize(sprintf("%.1f%%", $percentage), $color);
    }

    /**
     * Create progress bar
     *
     * @param float $percentage Percentage complete
     * @param int $width Bar width
     * @return string Progress bar
     */
    private function createProgressBar(float $percentage, int $width = 20): string {
        $filled = (int) ($width * $percentage / 100);
        $empty = $width - $filled;

        $bar = $this->colorize(str_repeat("█", $filled), 'green');
        $bar .= $this->colorize(str_repeat("░", $empty), 'gray');

        return $bar;
    }

    /**
     * Colorize text for terminal output
     *
     * @param string $text Text to colorize
     * @param string $color Color name
     * @param bool $bold Use bold text
     * @return string Colorized text
     */
    private function colorize(string $text, string $color, bool $bold = false): string {
        if (!$this->useColors) {
            return $text;
        }

        $colors = [
            'black' => 30,
            'red' => 31,
            'green' => 32,
            'yellow' => 33,
            'blue' => 34,
            'magenta' => 35,
            'cyan' => 36,
            'white' => 37,
            'gray' => 90
        ];

        $colorCode = $colors[$color] ?? 37;
        $boldCode = $bold ? '1;' : '';

        return "\033[" . $boldCode . $colorCode . "m" . $text . "\033[0m";
    }
}

// Execute if run directly
if (PHP_SAPI === 'cli' && basename(__FILE__) === basename($argv[0])) {
    $monitor = new CacheMonitor();
    $args = array_slice($argv, 1);
    $monitor->execute($args);
}