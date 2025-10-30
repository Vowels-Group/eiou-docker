#!/usr/bin/env php
<?php
/**
 * Real-time Cache Hit/Miss Rate Monitor
 *
 * Monitors cache performance in real-time and provides live statistics
 * about cache hit rates, miss rates, and performance improvements.
 *
 * Usage: php cache_monitor.php [interval_seconds]
 */

require_once __DIR__ . '/../../src/services/DockerCache.php';

// Configuration
$config = [
    'update_interval' => (int)($argv[1] ?? 1),  // Update interval in seconds
    'history_size' => 60,                       // Number of historical data points to keep
    'alert_thresholds' => [
        'low_hit_rate' => 0.3,                  // Alert if hit rate drops below 30%
        'high_miss_count' => 100,                // Alert if miss count exceeds 100/interval
        'memory_limit' => 100 * 1024 * 1024,    // Alert if cache memory exceeds 100MB
    ],
    'display_mode' => 'dashboard',              // dashboard, json, or csv
];

class CacheMonitor {
    private DockerCache $cache;
    private array $config;
    private array $history = [];
    private array $baseline = [];
    private int $startTime;
    private array $lastStats = [];

    public function __construct(array $config) {
        $this->config = $config;
        $this->cache = DockerCache::getInstance();
        $this->startTime = time();

        // Load baseline performance if available
        $this->loadBaseline();

        // Clear screen for dashboard mode
        if ($this->config['display_mode'] === 'dashboard') {
            $this->clearScreen();
        }
    }

    /**
     * Start monitoring loop
     */
    public function start(): void {
        $this->log("Starting cache monitor...");

        // Set up signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleShutdown']);
            pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
        }

        while (true) {
            $this->collectMetrics();
            $this->displayMetrics();
            $this->checkAlerts();

            sleep($this->config['update_interval']);

            // Process signals if available
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Collect current cache metrics
     */
    private function collectMetrics(): void {
        $stats = $this->cache->getStatistics();

        // Calculate rates since last collection
        $deltaStats = [];
        if (!empty($this->lastStats)) {
            $deltaStats = [
                'gets_per_sec' => ($stats['gets'] - $this->lastStats['gets']) / $this->config['update_interval'],
                'sets_per_sec' => ($stats['sets'] - $this->lastStats['sets']) / $this->config['update_interval'],
                'hits_per_sec' => ($stats['hits'] - $this->lastStats['hits']) / $this->config['update_interval'],
                'misses_per_sec' => ($stats['misses'] - $this->lastStats['misses']) / $this->config['update_interval'],
            ];
        }

        // Add timestamp and calculated metrics
        $metrics = array_merge($stats, $deltaStats, [
            'timestamp' => time(),
            'memory_usage' => $this->getCacheMemoryUsage(),
            'uptime' => time() - $this->startTime,
        ]);

        // Add to history
        $this->history[] = $metrics;

        // Trim history to configured size
        if (count($this->history) > $this->config['history_size']) {
            array_shift($this->history);
        }

        $this->lastStats = $stats;
    }

    /**
     * Display metrics based on configured mode
     */
    private function displayMetrics(): void {
        switch ($this->config['display_mode']) {
            case 'dashboard':
                $this->displayDashboard();
                break;
            case 'json':
                $this->displayJson();
                break;
            case 'csv':
                $this->displayCsv();
                break;
        }
    }

    /**
     * Display interactive dashboard
     */
    private function displayDashboard(): void {
        $this->clearScreen();

        $current = end($this->history) ?: [];

        // Header
        echo "\033[1;36m";  // Cyan bold
        echo str_repeat("=", 80) . "\n";
        echo "                    DOCKER CACHE MONITOR DASHBOARD\n";
        echo str_repeat("=", 80) . "\n";
        echo "\033[0m";     // Reset

        // Current Statistics
        echo "\n\033[1;33m[ CURRENT STATISTICS ]\033[0m\n";
        echo str_repeat("-", 40) . "\n";

        $hitRate = $current['hit_rate'] ?? 0;
        $hitRateColor = $hitRate > 0.7 ? "\033[32m" : ($hitRate > 0.3 ? "\033[33m" : "\033[31m");

        printf("Cache Hit Rate:     %s%.1f%%\033[0m\n", $hitRateColor, $hitRate * 100);
        printf("Total Gets:         %d\n", $current['gets'] ?? 0);
        printf("Total Sets:         %d\n", $current['sets'] ?? 0);
        printf("Total Hits:         \033[32m%d\033[0m\n", $current['hits'] ?? 0);
        printf("Total Misses:       \033[31m%d\033[0m\n", $current['misses'] ?? 0);
        printf("Cache Size:         %d entries\n", $current['size'] ?? 0);
        printf("Memory Usage:       %.2f MB\n", ($current['memory_usage'] ?? 0) / 1024 / 1024);

        // Performance Rates
        echo "\n\033[1;33m[ PERFORMANCE RATES ]\033[0m\n";
        echo str_repeat("-", 40) . "\n";

        printf("Gets/sec:           %.2f\n", $current['gets_per_sec'] ?? 0);
        printf("Sets/sec:           %.2f\n", $current['sets_per_sec'] ?? 0);
        printf("Hits/sec:           \033[32m%.2f\033[0m\n", $current['hits_per_sec'] ?? 0);
        printf("Misses/sec:         \033[31m%.2f\033[0m\n", $current['misses_per_sec'] ?? 0);

        // Baseline Comparison
        if (!empty($this->baseline)) {
            echo "\n\033[1;33m[ BASELINE COMPARISON ]\033[0m\n";
            echo str_repeat("-", 40) . "\n";

            $avgCallTime = $this->calculateAverageCallTime();
            $baselineAvg = $this->baseline['avg_call_time_ms'] ?? 0;
            $improvement = $baselineAvg > 0 ? (($baselineAvg - $avgCallTime) / $baselineAvg) * 100 : 0;

            $improvementColor = $improvement > 0 ? "\033[32m" : "\033[31m";

            printf("Baseline Avg:       %.2f ms\n", $baselineAvg);
            printf("Current Avg:        %.2f ms\n", $avgCallTime);
            printf("Improvement:        %s%.1f%%\033[0m\n", $improvementColor, $improvement);
        }

        // Hit Rate Trend (last 10 data points)
        echo "\n\033[1;33m[ HIT RATE TREND ]\033[0m\n";
        echo str_repeat("-", 40) . "\n";

        $this->displayHitRateTrend();

        // Active Alerts
        $alerts = $this->getActiveAlerts();
        if (!empty($alerts)) {
            echo "\n\033[1;31m[ ACTIVE ALERTS ]\033[0m\n";
            echo str_repeat("-", 40) . "\n";

            foreach ($alerts as $alert) {
                echo "\033[31m⚠ $alert\033[0m\n";
            }
        }

        // Footer
        echo "\n" . str_repeat("=", 80) . "\n";
        printf("Uptime: %s | Update Interval: %ds | Press Ctrl+C to exit\n",
            $this->formatUptime($current['uptime'] ?? 0),
            $this->config['update_interval']
        );
    }

    /**
     * Display hit rate trend as ASCII graph
     */
    private function displayHitRateTrend(): void {
        $recentHistory = array_slice($this->history, -20);

        if (empty($recentHistory)) {
            echo "No data available yet.\n";
            return;
        }

        $maxHeight = 10;
        $values = array_column($recentHistory, 'hit_rate');

        // Create ASCII graph
        for ($row = $maxHeight; $row >= 0; $row--) {
            $threshold = $row / $maxHeight;

            if ($row === $maxHeight) {
                printf("%3d%% |", 100);
            } elseif ($row === $maxHeight / 2) {
                printf(" %2d%% |", 50);
            } elseif ($row === 0) {
                printf("  0%% |");
            } else {
                echo "     |";
            }

            foreach ($values as $value) {
                if ($value >= $threshold) {
                    echo "█";
                } else {
                    echo " ";
                }
            }

            echo "\n";
        }

        echo "     +" . str_repeat("-", count($values)) . "\n";
        echo "      ";

        // Time labels
        $step = max(1, floor(count($values) / 10));
        for ($i = 0; $i < count($values); $i++) {
            if ($i % $step === 0) {
                echo "↑";
            } else {
                echo " ";
            }
        }
        echo "\n      Time (← " . (count($values) * $this->config['update_interval']) . "s)\n";
    }

    /**
     * Display metrics as JSON
     */
    private function displayJson(): void {
        $current = end($this->history) ?: [];
        echo json_encode($current) . "\n";
    }

    /**
     * Display metrics as CSV
     */
    private function displayCsv(): void {
        static $headerPrinted = false;

        $current = end($this->history) ?: [];

        if (!$headerPrinted) {
            echo implode(',', array_keys($current)) . "\n";
            $headerPrinted = true;
        }

        echo implode(',', array_values($current)) . "\n";
    }

    /**
     * Check for alerts based on thresholds
     */
    private function checkAlerts(): void {
        $alerts = $this->getActiveAlerts();

        if (!empty($alerts) && $this->config['display_mode'] !== 'dashboard') {
            foreach ($alerts as $alert) {
                $this->log("ALERT: $alert", 'error');
            }
        }
    }

    /**
     * Get list of active alerts
     */
    private function getActiveAlerts(): array {
        $alerts = [];
        $current = end($this->history) ?: [];

        // Check hit rate
        if (($current['hit_rate'] ?? 0) < $this->config['alert_thresholds']['low_hit_rate']) {
            $alerts[] = sprintf("Low cache hit rate: %.1f%%", ($current['hit_rate'] ?? 0) * 100);
        }

        // Check miss count
        if (($current['misses_per_sec'] ?? 0) * $this->config['update_interval']
            > $this->config['alert_thresholds']['high_miss_count']) {
            $alerts[] = sprintf("High miss count: %.0f misses/interval",
                ($current['misses_per_sec'] ?? 0) * $this->config['update_interval']);
        }

        // Check memory usage
        if (($current['memory_usage'] ?? 0) > $this->config['alert_thresholds']['memory_limit']) {
            $alerts[] = sprintf("High memory usage: %.2f MB",
                ($current['memory_usage'] ?? 0) / 1024 / 1024);
        }

        return $alerts;
    }

    /**
     * Load baseline performance data
     */
    private function loadBaseline(): void {
        $baselineFile = __DIR__ . '/../../logs/performance_baseline.log';

        if (!file_exists($baselineFile)) {
            return;
        }

        $lines = file($baselineFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) {
            return;
        }

        // Get last baseline entry
        $lastLine = end($lines);
        $data = json_decode($lastLine, true);

        if (isset($data['results']['summary'])) {
            $this->baseline = $data['results']['summary'];
        }
    }

    /**
     * Calculate average call time from recent history
     */
    private function calculateAverageCallTime(): float {
        // Simulate based on hit rate (in real implementation, measure actual times)
        $current = end($this->history) ?: [];
        $hitRate = $current['hit_rate'] ?? 0;

        // Assume cache hits are 10x faster than misses
        $cacheHitTime = 1.0;  // ms
        $cacheMissTime = 10.0; // ms

        return ($hitRate * $cacheHitTime) + ((1 - $hitRate) * $cacheMissTime);
    }

    /**
     * Get cache memory usage
     */
    private function getCacheMemoryUsage(): int {
        // In real implementation, this would measure actual cache memory
        // For now, estimate based on cache size
        $stats = $this->cache->getStatistics();
        $avgEntrySize = 1024; // Assume 1KB average per entry

        return ($stats['size'] ?? 0) * $avgEntrySize;
    }

    /**
     * Format uptime for display
     */
    private function formatUptime(int $seconds): string {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;

        return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    }

    /**
     * Clear terminal screen
     */
    private function clearScreen(): void {
        if (PHP_OS_FAMILY === 'Windows') {
            system('cls');
        } else {
            system('clear');
        }
    }

    /**
     * Handle shutdown signal
     */
    public function handleShutdown(int $signal): void {
        $this->log("\nShutting down cache monitor...");

        // Save final statistics
        $this->saveStatistics();

        exit(0);
    }

    /**
     * Save statistics to file
     */
    private function saveStatistics(): void {
        $statsFile = __DIR__ . '/../../logs/cache_monitor_stats_' . date('Y-m-d_H-i-s') . '.json';

        $data = [
            'start_time' => date('Y-m-d H:i:s', $this->startTime),
            'end_time' => date('Y-m-d H:i:s'),
            'duration' => time() - $this->startTime,
            'final_stats' => end($this->history),
            'history' => $this->history,
            'baseline' => $this->baseline,
        ];

        $dir = dirname($statsFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($statsFile, json_encode($data, JSON_PRETTY_PRINT));
        $this->log("Statistics saved to: $statsFile");
    }

    /**
     * Log message
     */
    private function log(string $message, string $level = 'info'): void {
        $timestamp = date('Y-m-d H:i:s');

        switch ($level) {
            case 'error':
                $prefix = "\033[31m[ERROR]\033[0m";
                break;
            case 'warning':
                $prefix = "\033[33m[WARN]\033[0m";
                break;
            default:
                $prefix = "[INFO]";
        }

        echo "[$timestamp] $prefix $message\n";
    }
}

// Stub DockerCache class for testing (replace with actual implementation)
if (!class_exists('DockerCache')) {
    class DockerCache {
        private static ?DockerCache $instance = null;
        private array $stats = [
            'gets' => 0,
            'sets' => 0,
            'hits' => 0,
            'misses' => 0,
            'size' => 0,
        ];

        public static function getInstance(): DockerCache {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function getStatistics(): array {
            // Simulate cache activity
            $this->stats['gets'] += rand(5, 20);
            $this->stats['sets'] += rand(1, 5);
            $newHits = rand(3, 18);
            $this->stats['hits'] += $newHits;
            $this->stats['misses'] = $this->stats['gets'] - $this->stats['hits'];
            $this->stats['size'] = rand(50, 200);

            $hitRate = $this->stats['gets'] > 0
                ? $this->stats['hits'] / $this->stats['gets']
                : 0;

            return array_merge($this->stats, ['hit_rate' => $hitRate]);
        }
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    $monitor = new CacheMonitor($config);
    $monitor->start();
} else {
    echo "This script must be run from the command line.\n";
    exit(1);
}