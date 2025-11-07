<?php
/**
 * Example: Integrating ShutdownCoordinator with EIOU Application
 *
 * This example demonstrates how to integrate the ShutdownCoordinator
 * with the existing Application class and message processors.
 *
 * Usage:
 *   php examples/shutdown-integration-example.php
 */

require_once dirname(__DIR__) . '/src/core/Application.php';
require_once dirname(__DIR__) . '/src/processors/ShutdownIntegration.php';
require_once dirname(__DIR__) . '/src/services/ShutdownCoordinator.php';

/**
 * Example 1: Basic Integration with Application
 */
function example1_basicIntegration(): void {
    echo "=== Example 1: Basic Integration ===\n\n";

    // Get application instance
    $app = Application::getInstance();

    // Create shutdown integration
    $shutdownIntegration = new ShutdownIntegration();

    // Integrate with application (registers all resources automatically)
    $shutdownIntegration->integrateWithApplication($app);

    // Set up progress reporting
    $shutdownIntegration->getCoordinator()->setProgressCallback(function($progress) {
        echo sprintf(
            "[%s] Phase: %-12s | %s (%.2fs)\n",
            date('H:i:s'),
            $progress['phase'],
            $progress['message'],
            $progress['elapsed']
        );
    });

    echo "Shutdown integration configured successfully.\n";
    echo "Resources registered:\n";
    echo "  - Database connections\n";
    echo "  - Message processors\n";
    echo "  - Processor lockfiles\n\n";

    // Simulate shutdown
    echo "Simulating graceful shutdown in 3 seconds...\n";
    sleep(3);

    // Initiate shutdown
    $success = $shutdownIntegration->initiateShutdown();

    // Display statistics
    $stats = $shutdownIntegration->getShutdownStats();
    echo "\nShutdown Statistics:\n";
    echo "  Shutdown Status: " . ($success ? "SUCCESS" : "FAILED") . "\n";
    echo "  Messages Completed: {$stats['messages_completed']}\n";
    echo "  Messages Abandoned: {$stats['messages_abandoned']}\n";
    echo "  Connections Closed: {$stats['connections_closed']}\n";
    echo "  Locks Released: {$stats['locks_released']}\n";
    echo "  Files Cleaned: {$stats['files_cleaned']}\n";
    echo "  Errors: " . count($stats['errors']) . "\n\n";
}

/**
 * Example 2: Custom Message Processor with Shutdown Support
 */
function example2_customProcessor(): void {
    echo "=== Example 2: Custom Message Processor ===\n\n";

    // Custom processor class
    class ExampleMessageProcessor {
        private ShutdownIntegration $shutdownIntegration;
        private array $messageQueue = [];
        private bool $running = true;

        public function __construct() {
            $this->shutdownIntegration = new ShutdownIntegration();

            // Add some test messages to queue
            for ($i = 1; $i <= 5; $i++) {
                $this->messageQueue[] = [
                    'id' => "msg-{$i}",
                    'data' => "Test message {$i}",
                    'timestamp' => microtime(true),
                ];
            }
        }

        public function run(): void {
            echo "Starting message processor...\n";

            while ($this->running && !empty($this->messageQueue)) {
                // Check if should stop processing
                if ($this->shutdownIntegration->shouldStopProcessing()) {
                    echo "  Shutdown requested, stopping new message processing\n";
                    break;
                }

                // Get next message
                $message = array_shift($this->messageQueue);
                $messageId = $message['id'];

                // Track message start
                $this->shutdownIntegration->trackMessageStart(
                    $messageId,
                    'example-processor',
                    $message
                );

                echo "  Processing: {$messageId}\n";

                // Simulate processing time
                usleep(500000); // 500ms

                // Track message completion
                $this->shutdownIntegration->trackMessageComplete($messageId);

                echo "  Completed: {$messageId}\n";
            }

            echo "Message processor stopped.\n";
            echo "Remaining in queue: " . count($this->messageQueue) . "\n\n";
        }

        public function stopAcceptingMessages(): void {
            $this->running = false;
        }

        public function shutdown(): void {
            echo "  Processor shutdown() called\n";
        }

        public function getShutdownIntegration(): ShutdownIntegration {
            return $this->shutdownIntegration;
        }
    }

    // Create and run processor
    $processor = new ExampleMessageProcessor();

    // Register processor with shutdown coordinator
    $shutdownIntegration = $processor->getShutdownIntegration();
    $shutdownIntegration->getCoordinator()->registerProcessor('example', $processor);

    // Set up progress callback
    $shutdownIntegration->getCoordinator()->setProgressCallback(function($progress) {
        echo "  [SHUTDOWN] {$progress['message']}\n";
    });

    // Start processing in background (simulated)
    echo "Processing messages...\n";

    // Process 2 messages
    for ($i = 0; $i < 2; $i++) {
        $processor->run();
    }

    // Simulate shutdown signal after 2 messages
    echo "\nReceived shutdown signal...\n";
    $shutdownIntegration->initiateShutdown();

    // Show statistics
    $stats = $shutdownIntegration->getShutdownStats();
    echo "\nStatistics:\n";
    echo "  Messages Completed: {$stats['messages_completed']}\n";
    echo "  In-flight at shutdown: {$shutdownIntegration->getCoordinator()->getInFlightCount()}\n\n";
}

/**
 * Example 3: Shutdown with Database Cleanup
 */
function example3_databaseCleanup(): void {
    echo "=== Example 3: Database Cleanup ===\n\n";

    // Create shutdown coordinator
    $coordinator = new ShutdownCoordinator();

    // Create test database connection (SQLite in-memory)
    try {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create test table
        $pdo->exec('CREATE TABLE test_messages (id INTEGER PRIMARY KEY, content TEXT)');
        $pdo->exec("INSERT INTO test_messages (content) VALUES ('test message 1')");
        $pdo->exec("INSERT INTO test_messages (content) VALUES ('test message 2')");

        echo "Created test database with 2 records\n";

        // Register database connection
        $coordinator->registerDatabaseConnection('test_db', $pdo);
        echo "Registered database connection for cleanup\n\n";

        // Verify database is accessible
        $stmt = $pdo->query('SELECT COUNT(*) FROM test_messages');
        $count = $stmt->fetchColumn();
        echo "Database accessible: {$count} records found\n\n";

        // Set up progress callback
        $coordinator->setProgressCallback(function($progress) {
            echo "  [{$progress['phase']}] {$progress['message']}\n";
        });

        // Initiate shutdown
        echo "Initiating shutdown...\n";
        $success = $coordinator->initiateShutdown();

        echo "\nShutdown completed: " . ($success ? "SUCCESS" : "FAILED") . "\n";

        // Show statistics
        $stats = $coordinator->getStats();
        echo "Database connections closed: {$stats['connections_closed']}\n\n";

    } catch (PDOException $e) {
        echo "Database error: " . $e->getMessage() . "\n\n";
    }
}

/**
 * Example 4: File Lock and Temp File Cleanup
 */
function example4_fileCleanup(): void {
    echo "=== Example 4: File Cleanup ===\n\n";

    $coordinator = new ShutdownCoordinator();

    // Create test lock file
    $lockFile = '/tmp/example-shutdown-lock-' . uniqid();
    file_put_contents($lockFile, getmypid());
    echo "Created lock file: {$lockFile}\n";

    // Create test temp file
    $tempFile = '/tmp/example-shutdown-temp-' . uniqid() . '.tmp';
    file_put_contents($tempFile, 'temporary data for testing');
    echo "Created temp file: {$tempFile}\n\n";

    // Register for cleanup
    $coordinator->registerFileLock($lockFile);
    $coordinator->registerTempFile($tempFile);
    echo "Registered files for cleanup\n\n";

    // Set progress callback
    $coordinator->setProgressCallback(function($progress) {
        echo "  [{$progress['phase']}] {$progress['message']}\n";
    });

    // Verify files exist before shutdown
    echo "Before shutdown:\n";
    echo "  Lock file exists: " . (file_exists($lockFile) ? "YES" : "NO") . "\n";
    echo "  Temp file exists: " . (file_exists($tempFile) ? "YES" : "NO") . "\n\n";

    // Initiate shutdown
    echo "Initiating shutdown...\n";
    $coordinator->initiateShutdown();

    // Verify files cleaned up
    echo "\nAfter shutdown:\n";
    echo "  Lock file exists: " . (file_exists($lockFile) ? "YES" : "NO") . "\n";
    echo "  Temp file exists: " . (file_exists($tempFile) ? "YES" : "NO") . "\n\n";

    // Show statistics
    $stats = $coordinator->getStats();
    echo "Cleanup Statistics:\n";
    echo "  Locks released: {$stats['locks_released']}\n";
    echo "  Files cleaned: {$stats['files_cleaned']}\n\n";
}

/**
 * Example 5: In-Flight Message Tracking
 */
function example5_inFlightTracking(): void {
    echo "=== Example 5: In-Flight Message Tracking ===\n\n";

    $coordinator = new ShutdownCoordinator();

    // Simulate starting message processing
    echo "Starting message processing...\n";

    $messages = [
        ['id' => 'msg-1', 'processor' => 'p2p', 'data' => 'P2P message 1'],
        ['id' => 'msg-2', 'processor' => 'transaction', 'data' => 'Transaction 1'],
        ['id' => 'msg-3', 'processor' => 'p2p', 'data' => 'P2P message 2'],
    ];

    // Track all messages as in-flight
    foreach ($messages as $msg) {
        $coordinator->trackInFlightMessage($msg['id'], $msg);
        echo "  Tracking: {$msg['id']} ({$msg['processor']})\n";
    }

    echo "\nIn-flight messages: {$coordinator->getInFlightCount()}\n\n";

    // Simulate completing some messages
    echo "Completing messages...\n";
    $coordinator->completeInFlightMessage('msg-1');
    echo "  Completed: msg-1\n";

    usleep(100000); // 100ms

    $coordinator->completeInFlightMessage('msg-2');
    echo "  Completed: msg-2\n";

    echo "\nIn-flight messages remaining: {$coordinator->getInFlightCount()}\n\n";

    // Set progress callback to show draining phase
    $coordinator->setProgressCallback(function($progress) {
        echo "  [{$progress['phase']}] {$progress['message']}\n";
    });

    // Start shutdown - should wait for msg-3
    echo "Initiating shutdown (msg-3 still in-flight)...\n";

    // Complete the last message after a delay (simulating slow processing)
    // In real usage, this would happen in the processor
    usleep(500000); // 500ms
    $coordinator->completeInFlightMessage('msg-3');
    echo "  Completed: msg-3 (during shutdown)\n\n";

    $coordinator->initiateShutdown();

    // Show statistics
    $stats = $coordinator->getStats();
    echo "\nShutdown Statistics:\n";
    echo "  Messages completed: {$stats['messages_completed']}\n";
    echo "  Messages abandoned: {$stats['messages_abandoned']}\n\n";
}

/**
 * Example 6: Progress Reporting with Custom Callback
 */
function example6_progressReporting(): void {
    echo "=== Example 6: Progress Reporting ===\n\n";

    $coordinator = new ShutdownCoordinator();

    // Create detailed progress callback
    $coordinator->setProgressCallback(function($progress) {
        $bar = str_repeat('=', min(50, (int)($progress['elapsed'] * 10)));
        echo sprintf(
            "[%s] %-12s | %-30s | %s\n",
            date('H:i:s.u'),
            strtoupper($progress['phase']),
            $progress['message'],
            $bar
        );

        // Show statistics during shutdown
        if (isset($progress['stats'])) {
            $s = $progress['stats'];
            if ($s['messages_completed'] > 0 || $s['messages_abandoned'] > 0) {
                echo "              Stats: Completed={$s['messages_completed']}, " .
                     "Abandoned={$s['messages_abandoned']}\n";
            }
        }
    });

    // Add some test resources
    $tempFile = '/tmp/example-progress-' . uniqid() . '.tmp';
    file_put_contents($tempFile, 'test');
    $coordinator->registerTempFile($tempFile);

    // Track a message
    $coordinator->trackInFlightMessage('test-msg', [
        'processor' => 'test',
        'data' => 'test message',
    ]);

    // Complete it quickly
    usleep(100000);
    $coordinator->completeInFlightMessage('test-msg');

    // Initiate shutdown
    echo "Starting shutdown with detailed progress reporting:\n\n";
    $coordinator->initiateShutdown();

    echo "\nProgress reporting complete.\n\n";
}

// Main execution
if (php_sapi_name() === 'cli') {
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  ShutdownCoordinator Integration Examples                 ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";

    // Run examples
    // Note: Example 1 requires full Application setup, so skip in standalone mode
    // example1_basicIntegration();

    example2_customProcessor();
    echo str_repeat("─", 60) . "\n\n";

    example3_databaseCleanup();
    echo str_repeat("─", 60) . "\n\n";

    example4_fileCleanup();
    echo str_repeat("─", 60) . "\n\n";

    example5_inFlightTracking();
    echo str_repeat("─", 60) . "\n\n";

    example6_progressReporting();

    echo "All examples completed successfully!\n\n";
}
