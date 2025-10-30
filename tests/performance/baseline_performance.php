#!/usr/bin/env php
<?php
/**
 * Baseline Performance Measurement Script
 *
 * This script measures the baseline performance of Docker API calls
 * before caching implementation to establish a comparison baseline.
 *
 * Usage: php baseline_performance.php [single|4line|10line|cluster]
 */

// Configuration
$config = [
    'iterations' => 100,        // Number of test iterations
    'warmup_runs' => 10,        // Warmup runs before measurement
    'sleep_between' => 0.1,     // Sleep between calls (seconds)
    'output_format' => 'json',  // Output format: json, csv, or text
    'log_file' => __DIR__ . '/../../logs/performance_baseline.log'
];

// Docker commands to test
$dockerCommands = [
    'ps' => 'docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"',
    'stats' => 'docker stats --no-stream --format "json"',
    'inspect' => 'docker inspect {{container}}',
    'logs' => 'docker logs {{container}} --tail 10',
    'exec_balance' => 'docker exec {{container}} eiou balance',
    'exec_contacts' => 'docker exec {{container}} eiou contacts',
    'network_ls' => 'docker network ls --format "json"',
    'volume_ls' => 'docker volume ls --format "json"'
];

// Container configurations
$topologies = [
    'single' => ['alice'],
    '4line' => ['alice', 'bob', 'carol', 'daniel'],
    '10line' => ['alice', 'bob', 'carol', 'daniel', 'edward', 'frank', 'grace', 'henry', 'ivan', 'julia'],
    'cluster' => ['alice', 'bob', 'carol', 'daniel', 'edward', 'frank', 'grace', 'henry', 'ivan', 'julia', 'karl', 'larry', 'megan']
];

class PerformanceTester {
    private array $config;
    private array $results = [];
    private array $dockerCommands;
    private array $containers;
    private string $topology;

    public function __construct(array $config, array $dockerCommands, array $containers, string $topology) {
        $this->config = $config;
        $this->dockerCommands = $dockerCommands;
        $this->containers = $containers;
        $this->topology = $topology;

        // Ensure log directory exists
        $logDir = dirname($this->config['log_file']);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    /**
     * Run performance tests
     */
    public function run(): void {
        $this->log("Starting baseline performance measurement for topology: {$this->topology}");
        $this->log("Testing with " . count($this->containers) . " containers");
        $this->log("Iterations: {$this->config['iterations']}, Warmup: {$this->config['warmup_runs']}");

        // Verify Docker is running
        if (!$this->verifyDocker()) {
            $this->log("ERROR: Docker is not running or containers are not started");
            exit(1);
        }

        // Run warmup
        $this->log("\nRunning warmup ({$this->config['warmup_runs']} iterations)...");
        $this->runWarmup();

        // Run actual tests
        $this->log("\nRunning performance tests...");
        $startTime = microtime(true);

        foreach ($this->dockerCommands as $commandName => $command) {
            $this->log("Testing command: $commandName");
            $this->results[$commandName] = $this->testCommand($commandName, $command);
        }

        $totalTime = microtime(true) - $startTime;
        $this->results['total_test_time'] = $totalTime;

        // Analyze and output results
        $this->analyzeResults();
        $this->outputResults();
    }

    /**
     * Verify Docker is running and containers are available
     */
    private function verifyDocker(): bool {
        exec('docker ps 2>&1', $output, $returnCode);
        if ($returnCode !== 0) {
            return false;
        }

        // Check if expected containers are running
        $runningContainers = [];
        foreach ($output as $line) {
            foreach ($this->containers as $container) {
                if (strpos($line, $container) !== false) {
                    $runningContainers[] = $container;
                }
            }
        }

        if (count($runningContainers) === 0) {
            $this->log("WARNING: No expected containers are running");
            return false;
        }

        $this->log("Found " . count($runningContainers) . " running containers: " . implode(', ', $runningContainers));
        return true;
    }

    /**
     * Run warmup iterations
     */
    private function runWarmup(): void {
        for ($i = 0; $i < $this->config['warmup_runs']; $i++) {
            foreach ($this->dockerCommands as $command) {
                $testCommand = $this->prepareCommand($command);
                exec($testCommand . ' 2>&1', $output, $returnCode);
            }
            echo ".";
        }
        echo "\n";
    }

    /**
     * Test a single command
     */
    private function testCommand(string $name, string $command): array {
        $times = [];
        $errors = 0;
        $outputs = [];

        for ($i = 0; $i < $this->config['iterations']; $i++) {
            $testCommand = $this->prepareCommand($command);

            $startTime = microtime(true);
            exec($testCommand . ' 2>&1', $output, $returnCode);
            $endTime = microtime(true);

            $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

            if ($returnCode === 0) {
                $times[] = $executionTime;
                $outputs[] = count($output);
            } else {
                $errors++;
            }

            // Progress indicator
            if ($i % 10 === 0) {
                echo ".";
            }

            // Sleep between calls to avoid overwhelming the system
            if ($this->config['sleep_between'] > 0) {
                usleep($this->config['sleep_between'] * 1000000);
            }
        }

        echo " done\n";

        return [
            'command' => $name,
            'iterations' => count($times),
            'errors' => $errors,
            'min_time_ms' => $times ? min($times) : 0,
            'max_time_ms' => $times ? max($times) : 0,
            'avg_time_ms' => $times ? array_sum($times) / count($times) : 0,
            'median_time_ms' => $this->calculateMedian($times),
            'p95_time_ms' => $this->calculatePercentile($times, 95),
            'p99_time_ms' => $this->calculatePercentile($times, 99),
            'std_dev_ms' => $this->calculateStdDev($times),
            'total_time_ms' => array_sum($times),
            'avg_output_lines' => $outputs ? array_sum($outputs) / count($outputs) : 0
        ];
    }

    /**
     * Prepare command with container substitution
     */
    private function prepareCommand(string $command): string {
        // Replace {{container}} with first container in list
        if (strpos($command, '{{container}}') !== false) {
            $container = $this->containers[0];
            $command = str_replace('{{container}}', $container, $command);
        }
        return $command;
    }

    /**
     * Calculate median value
     */
    private function calculateMedian(array $values): float {
        if (empty($values)) return 0;

        sort($values);
        $count = count($values);
        $middle = floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        } else {
            return $values[$middle];
        }
    }

    /**
     * Calculate percentile value
     */
    private function calculatePercentile(array $values, int $percentile): float {
        if (empty($values)) return 0;

        sort($values);
        $index = ceil(($percentile / 100) * count($values)) - 1;
        return $values[$index] ?? 0;
    }

    /**
     * Calculate standard deviation
     */
    private function calculateStdDev(array $values): float {
        if (empty($values)) return 0;

        $mean = array_sum($values) / count($values);
        $variance = 0;

        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }

        $variance /= count($values);
        return sqrt($variance);
    }

    /**
     * Analyze results and generate summary
     */
    private function analyzeResults(): void {
        $totalApiCalls = 0;
        $totalTime = 0;

        foreach ($this->results as $key => $result) {
            if (is_array($result) && isset($result['iterations'])) {
                $totalApiCalls += $result['iterations'];
                $totalTime += $result['total_time_ms'];
            }
        }

        $this->results['summary'] = [
            'topology' => $this->topology,
            'total_api_calls' => $totalApiCalls,
            'total_time_ms' => $totalTime,
            'avg_call_time_ms' => $totalApiCalls > 0 ? $totalTime / $totalApiCalls : 0,
            'calls_per_second' => $totalTime > 0 ? ($totalApiCalls / ($totalTime / 1000)) : 0,
            'timestamp' => date('Y-m-d H:i:s'),
            'test_duration_seconds' => $this->results['total_test_time'] ?? 0
        ];
    }

    /**
     * Output results in specified format
     */
    private function outputResults(): void {
        switch ($this->config['output_format']) {
            case 'json':
                $this->outputJson();
                break;
            case 'csv':
                $this->outputCsv();
                break;
            default:
                $this->outputText();
        }

        // Also save to log file
        $this->saveToLog();
    }

    /**
     * Output results as JSON
     */
    private function outputJson(): void {
        echo json_encode($this->results, JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * Output results as CSV
     */
    private function outputCsv(): void {
        echo "Command,Iterations,Errors,Min(ms),Max(ms),Avg(ms),Median(ms),P95(ms),P99(ms),StdDev(ms)\n";

        foreach ($this->results as $key => $result) {
            if (is_array($result) && isset($result['command'])) {
                echo sprintf(
                    "%s,%d,%d,%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,%.2f\n",
                    $result['command'],
                    $result['iterations'],
                    $result['errors'],
                    $result['min_time_ms'],
                    $result['max_time_ms'],
                    $result['avg_time_ms'],
                    $result['median_time_ms'],
                    $result['p95_time_ms'],
                    $result['p99_time_ms'],
                    $result['std_dev_ms']
                );
            }
        }
    }

    /**
     * Output results as formatted text
     */
    private function outputText(): void {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "BASELINE PERFORMANCE RESULTS\n";
        echo str_repeat("=", 80) . "\n\n";

        echo "Topology: {$this->topology}\n";
        echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
        echo "Total Test Duration: " . sprintf("%.2f", $this->results['total_test_time'] ?? 0) . " seconds\n\n";

        echo str_repeat("-", 80) . "\n";
        echo sprintf("%-20s %10s %10s %10s %10s %10s\n",
            "Command", "Calls", "Avg(ms)", "P95(ms)", "P99(ms)", "Errors");
        echo str_repeat("-", 80) . "\n";

        foreach ($this->results as $key => $result) {
            if (is_array($result) && isset($result['command'])) {
                echo sprintf("%-20s %10d %10.2f %10.2f %10.2f %10d\n",
                    $result['command'],
                    $result['iterations'],
                    $result['avg_time_ms'],
                    $result['p95_time_ms'],
                    $result['p99_time_ms'],
                    $result['errors']
                );
            }
        }

        echo str_repeat("-", 80) . "\n\n";

        if (isset($this->results['summary'])) {
            $summary = $this->results['summary'];
            echo "SUMMARY:\n";
            echo "- Total API Calls: {$summary['total_api_calls']}\n";
            echo "- Total Time: " . sprintf("%.2f", $summary['total_time_ms'] / 1000) . " seconds\n";
            echo "- Average Call Time: " . sprintf("%.2f", $summary['avg_call_time_ms']) . " ms\n";
            echo "- Throughput: " . sprintf("%.2f", $summary['calls_per_second']) . " calls/second\n";
        }

        echo "\n" . str_repeat("=", 80) . "\n";
    }

    /**
     * Save results to log file
     */
    private function saveToLog(): void {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'topology' => $this->topology,
            'results' => $this->results
        ];

        $logEntry = json_encode($logData) . "\n";
        file_put_contents($this->config['log_file'], $logEntry, FILE_APPEND | LOCK_EX);

        $this->log("Results saved to: {$this->config['log_file']}");
    }

    /**
     * Log message to console
     */
    private function log(string $message): void {
        echo "[" . date('H:i:s') . "] $message\n";
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    $topology = $argv[1] ?? 'single';

    if (!isset($topologies[$topology])) {
        echo "Error: Invalid topology. Use one of: " . implode(', ', array_keys($topologies)) . "\n";
        exit(1);
    }

    $tester = new PerformanceTester(
        $config,
        $dockerCommands,
        $topologies[$topology],
        $topology
    );

    $tester->run();
} else {
    echo "This script must be run from the command line.\n";
    exit(1);
}