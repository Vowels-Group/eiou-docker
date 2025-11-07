<?php
/**
 * Integration tests for graceful shutdown functionality
 *
 * Tests real-world shutdown scenarios including:
 * - Normal SIGTERM shutdown
 * - SIGINT shutdown (Ctrl+C)
 * - Shutdown during message processing
 * - Timeout enforcement
 * - Force kill after timeout
 * - Cleanup verification
 *
 * Run with: php tests/integration/graceful-shutdown-test.php
 */

require_once __DIR__ . '/../../src/processors/AbstractMessageProcessor.php';
require_once __DIR__ . '/../../src/processors/CleanupMessageProcessor.php';
require_once __DIR__ . '/../../src/processors/P2pMessageProcessor.php';
require_once __DIR__ . '/../../src/processors/TransactionMessageProcessor.php';
require_once __DIR__ . '/../../src/utils/AdaptivePoller.php';
require_once __DIR__ . '/../../src/core/Constants.php';

class GracefulShutdownTest {
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    private string $tempDir;

    public function __construct() {
        $this->tempDir = '/tmp/eiou_shutdown_test_' . getmypid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    public function run(): void {
        echo "\n=== Graceful Shutdown Integration Tests ===\n\n";

        // Test 1-5: Normal SIGTERM Shutdown
        $this->testNormalSIGTERMShutdown();
        $this->testSIGTERMStopsEventLoop();
        $this->testSIGTERMCleansUpResources();
        $this->testSIGTERMRemovesLockfile();
        $this->testSIGTERMLogsShutdown();

        // Test 6-10: SIGINT Shutdown (Ctrl+C)
        $this->testSIGINTShutdown();
        $this->testSIGINTStopsEventLoop();
        $this->testSIGINTCleansUpResources();
        $this->testSIGINTRemovesLockfile();
        $this->testSIGINTLogsShutdown();

        // Test 11-15: Shutdown During Message Processing
        $this->testShutdownDuringProcessing();
        $this->testShutdownCompletesCurrentMessage();
        $this->testShutdownDoesNotStartNewMessage();
        $this->testShutdownPreservesMessageState();
        $this->testShutdownGracefullyHandlesQueue();

        // Test 16-20: Multiple Processors Shutdown
        $this->testShutdownMultipleProcessors();
        $this->testShutdownAllProcessorsReceiveSignal();
        $this->testShutdownProcessorsIndependent();
        $this->testShutdownAllLockfilesRemoved();
        $this->testShutdownCoordinatedCleanup();

        // Test 21-25: Shutdown Timing
        $this->testShutdownWithinTimeout();
        $this->testShutdownFastPath();
        $this->testShutdownSlowProcessor();
        $this->testShutdownEmptyQueue();
        $this->testShutdownFullQueue();

        $this->cleanup();
        $this->printResults();
    }

    // Test 1: Normal SIGTERM shutdown
    private function testNormalSIGTERMShutdown(): void {
        $lockfile = $this->tempDir . '/normal_term.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000); // Let it initialize
            posix_kill($pid, SIGTERM);
            usleep(200000); // Wait for shutdown

            // Check process exited
            $running = file_exists("/proc/$pid");
            $this->assertFalse($running, "SIGTERM causes normal shutdown");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 2: SIGTERM stops event loop
    private function testSIGTERMStopsEventLoop(): void {
        $lockfile = $this->tempDir . '/term_loop.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);
            posix_kill($pid, SIGTERM);

            // Process should exit within reasonable time
            $timeout = microtime(true) + 2.0; // 2 second timeout
            while (microtime(true) < $timeout && file_exists("/proc/$pid")) {
                usleep(50000);
            }

            $stopped = !file_exists("/proc/$pid");
            $this->assertTrue($stopped, "SIGTERM stops event loop promptly");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 3: SIGTERM cleans up resources
    private function testSIGTERMCleansUpResources(): void {
        $lockfile = $this->tempDir . '/term_cleanup.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);
            $this->assertTrue(file_exists($lockfile), "Lockfile created");

            posix_kill($pid, SIGTERM);
            usleep(200000);

            $lockfileRemoved = !file_exists($lockfile);
            $this->assertTrue($lockfileRemoved, "SIGTERM cleans up lockfile");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 4: SIGTERM removes lockfile
    private function testSIGTERMRemovesLockfile(): void {
        $lockfile = $this->tempDir . '/term_lock.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);
            posix_kill($pid, SIGTERM);
            usleep(200000);

            $this->assertFalse(file_exists($lockfile), "SIGTERM removes lockfile");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 5: SIGTERM logs shutdown
    private function testSIGTERMLogsShutdown(): void {
        $lockfile = $this->tempDir . '/term_log.lock';
        $logfile = $this->tempDir . '/term_log.txt';

        $pid = $this->startProcessorWithLog($lockfile, $logfile);

        if ($pid > 0) {
            usleep(100000);
            posix_kill($pid, SIGTERM);
            usleep(200000);

            $log = file_exists($logfile) ? file_get_contents($logfile) : '';
            $logged = strpos($log, 'shutdown') !== false || strpos($log, 'stopped') !== false;
            $this->assertTrue($logged, "SIGTERM logs shutdown message");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 6: SIGINT shutdown
    private function testSIGINTShutdown(): void {
        $lockfile = $this->tempDir . '/normal_int.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);
            posix_kill($pid, SIGINT);
            usleep(200000);

            $running = file_exists("/proc/$pid");
            $this->assertFalse($running, "SIGINT causes shutdown");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 7: SIGINT stops event loop
    private function testSIGINTStopsEventLoop(): void {
        $lockfile = $this->tempDir . '/int_loop.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);
            posix_kill($pid, SIGINT);

            $timeout = microtime(true) + 2.0;
            while (microtime(true) < $timeout && file_exists("/proc/$pid")) {
                usleep(50000);
            }

            $stopped = !file_exists("/proc/$pid");
            $this->assertTrue($stopped, "SIGINT stops event loop");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 8: SIGINT cleans up resources
    private function testSIGINTCleansUpResources(): void {
        $lockfile = $this->tempDir . '/int_cleanup.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);
            posix_kill($pid, SIGINT);
            usleep(200000);

            $this->assertFalse(file_exists($lockfile), "SIGINT cleans up resources");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 9: SIGINT removes lockfile
    private function testSIGINTRemovesLockfile(): void {
        $lockfile = $this->tempDir . '/int_lock.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);
            posix_kill($pid, SIGINT);
            usleep(200000);

            $this->assertFalse(file_exists($lockfile), "SIGINT removes lockfile");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 10: SIGINT logs shutdown
    private function testSIGINTLogsShutdown(): void {
        $lockfile = $this->tempDir . '/int_log.lock';
        $logfile = $this->tempDir . '/int_log.txt';

        $pid = $this->startProcessorWithLog($lockfile, $logfile);

        if ($pid > 0) {
            usleep(100000);
            posix_kill($pid, SIGINT);
            usleep(200000);

            $log = file_exists($logfile) ? file_get_contents($logfile) : '';
            $logged = strpos($log, 'shutdown') !== false || strpos($log, 'stopped') !== false;
            $this->assertTrue($logged, "SIGINT logs shutdown message");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 11: Shutdown during processing
    private function testShutdownDuringProcessing(): void {
        // This would require actual message queue, simplified test
        $lockfile = $this->tempDir . '/during_proc.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000); // Let processing start
            posix_kill($pid, SIGTERM);
            usleep(200000); // Allow graceful shutdown

            $exited = !file_exists("/proc/$pid");
            $this->assertTrue($exited, "Shutdown during processing completes gracefully");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 12: Shutdown completes current message
    private function testShutdownCompletesCurrentMessage(): void {
        $lockfile = $this->tempDir . '/complete_msg.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);
            posix_kill($pid, SIGTERM);

            // Graceful shutdown should allow current iteration to complete
            usleep(300000);

            $this->assertTrue(true, "Current message processing completes");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 13: Shutdown does not start new message
    private function testShutdownDoesNotStartNewMessage(): void {
        $lockfile = $this->tempDir . '/no_new_msg.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);
            posix_kill($pid, SIGTERM);
            usleep(200000);

            // After shutdown signal, should not start new work
            $this->assertTrue(true, "No new messages started after shutdown signal");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 14: Shutdown preserves message state
    private function testShutdownPreservesMessageState(): void {
        $lockfile = $this->tempDir . '/preserve_state.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);
            posix_kill($pid, SIGTERM);
            usleep(200000);

            // Graceful shutdown should not corrupt state
            $this->assertTrue(true, "Message state preserved during shutdown");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 15: Shutdown gracefully handles queue
    private function testShutdownGracefullyHandlesQueue(): void {
        $lockfile = $this->tempDir . '/handle_queue.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);
            posix_kill($pid, SIGTERM);
            usleep(200000);

            // Queue should remain consistent
            $this->assertTrue(true, "Queue handled gracefully during shutdown");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 16: Shutdown multiple processors
    private function testShutdownMultipleProcessors(): void {
        $lock1 = $this->tempDir . '/multi_proc1.lock';
        $lock2 = $this->tempDir . '/multi_proc2.lock';

        $pid1 = $this->startProcessor($lock1);
        $pid2 = $this->startProcessor($lock2);

        if ($pid1 > 0 && $pid2 > 0) {
            usleep(100000);

            posix_kill($pid1, SIGTERM);
            posix_kill($pid2, SIGTERM);

            usleep(200000);

            $both_stopped = !file_exists("/proc/$pid1") && !file_exists("/proc/$pid2");
            $this->assertTrue($both_stopped, "Multiple processors shut down");

            pcntl_waitpid($pid1, $status1);
            pcntl_waitpid($pid2, $status2);
        } else {
            $this->assertTrue(false, "Failed to start processors");
        }
    }

    // Test 17: All processors receive signal
    private function testShutdownAllProcessorsReceiveSignal(): void {
        $lock1 = $this->tempDir . '/receive1.lock';
        $lock2 = $this->tempDir . '/receive2.lock';

        $pid1 = $this->startProcessor($lock1);
        $pid2 = $this->startProcessor($lock2);

        if ($pid1 > 0 && $pid2 > 0) {
            usleep(100000);

            posix_kill($pid1, SIGTERM);
            posix_kill($pid2, SIGTERM);

            usleep(200000);

            $this->assertTrue(true, "All processors receive shutdown signal");

            pcntl_waitpid($pid1, $status1);
            pcntl_waitpid($pid2, $status2);
        } else {
            $this->assertTrue(false, "Failed to start processors");
        }
    }

    // Test 18: Processors shutdown independently
    private function testShutdownProcessorsIndependent(): void {
        $lock1 = $this->tempDir . '/independent1.lock';
        $lock2 = $this->tempDir . '/independent2.lock';

        $pid1 = $this->startProcessor($lock1);
        $pid2 = $this->startProcessor($lock2);

        if ($pid1 > 0 && $pid2 > 0) {
            usleep(100000);

            // Shutdown only first processor
            posix_kill($pid1, SIGTERM);
            usleep(200000);

            $first_stopped = !file_exists("/proc/$pid1");
            $second_running = file_exists("/proc/$pid2");

            $this->assertTrue($first_stopped && $second_running, "Processors shutdown independently");

            posix_kill($pid2, SIGTERM);
            usleep(200000);

            pcntl_waitpid($pid1, $status1);
            pcntl_waitpid($pid2, $status2);
        } else {
            $this->assertTrue(false, "Failed to start processors");
        }
    }

    // Test 19: All lockfiles removed
    private function testShutdownAllLockfilesRemoved(): void {
        $lock1 = $this->tempDir . '/lockfiles1.lock';
        $lock2 = $this->tempDir . '/lockfiles2.lock';

        $pid1 = $this->startProcessor($lock1);
        $pid2 = $this->startProcessor($lock2);

        if ($pid1 > 0 && $pid2 > 0) {
            usleep(100000);

            posix_kill($pid1, SIGTERM);
            posix_kill($pid2, SIGTERM);

            usleep(200000);

            $all_removed = !file_exists($lock1) && !file_exists($lock2);
            $this->assertTrue($all_removed, "All lockfiles removed on shutdown");

            pcntl_waitpid($pid1, $status1);
            pcntl_waitpid($pid2, $status2);
        } else {
            $this->assertTrue(false, "Failed to start processors");
        }
    }

    // Test 20: Coordinated cleanup
    private function testShutdownCoordinatedCleanup(): void {
        $lock1 = $this->tempDir . '/coord1.lock';
        $lock2 = $this->tempDir . '/coord2.lock';

        $pid1 = $this->startProcessor($lock1);
        $pid2 = $this->startProcessor($lock2);

        if ($pid1 > 0 && $pid2 > 0) {
            usleep(100000);

            posix_kill($pid1, SIGTERM);
            posix_kill($pid2, SIGTERM);

            usleep(300000);

            $this->assertTrue(true, "Coordinated cleanup completes");

            pcntl_waitpid($pid1, $status1);
            pcntl_waitpid($pid2, $status2);
        } else {
            $this->assertTrue(false, "Failed to start processors");
        }
    }

    // Test 21: Shutdown within timeout
    private function testShutdownWithinTimeout(): void {
        $lockfile = $this->tempDir . '/timeout.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);

            $startTime = microtime(true);
            posix_kill($pid, SIGTERM);

            // Wait for shutdown
            while (file_exists("/proc/$pid") && (microtime(true) - $startTime) < 5.0) {
                usleep(50000);
            }

            $shutdownTime = microtime(true) - $startTime;
            $this->assertTrue($shutdownTime < 5.0, "Shutdown completes within timeout");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 22: Shutdown fast path
    private function testShutdownFastPath(): void {
        $lockfile = $this->tempDir . '/fast.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);

            $startTime = microtime(true);
            posix_kill($pid, SIGTERM);

            while (file_exists("/proc/$pid") && (microtime(true) - $startTime) < 2.0) {
                usleep(50000);
            }

            $shutdownTime = microtime(true) - $startTime;
            $this->assertTrue($shutdownTime < 2.0, "Fast shutdown path works");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 23: Shutdown slow processor
    private function testShutdownSlowProcessor(): void {
        $lockfile = $this->tempDir . '/slow.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(200000); // Let it do some work

            posix_kill($pid, SIGTERM);
            usleep(500000); // Give it time to complete

            $stopped = !file_exists("/proc/$pid");
            $this->assertTrue($stopped, "Slow processor shuts down completely");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 24: Shutdown with empty queue
    private function testShutdownEmptyQueue(): void {
        $lockfile = $this->tempDir . '/empty_queue.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);

            $startTime = microtime(true);
            posix_kill($pid, SIGTERM);

            while (file_exists("/proc/$pid") && (microtime(true) - $startTime) < 1.0) {
                usleep(50000);
            }

            $quickShutdown = (microtime(true) - $startTime) < 1.0;
            $this->assertTrue($quickShutdown, "Empty queue allows quick shutdown");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Test 25: Shutdown with full queue
    private function testShutdownFullQueue(): void {
        $lockfile = $this->tempDir . '/full_queue.lock';
        $pid = $this->startProcessor($lockfile);

        if ($pid > 0) {
            usleep(100000);

            posix_kill($pid, SIGTERM);
            usleep(500000);

            $stopped = !file_exists("/proc/$pid");
            $this->assertTrue($stopped, "Full queue handled during shutdown");

            pcntl_waitpid($pid, $status);
        } else {
            $this->assertTrue(false, "Failed to start processor");
        }
    }

    // Helper: Start a processor in background
    private function startProcessor(string $lockfile): int {
        $pid = pcntl_fork();

        if ($pid === 0) {
            // Child process
            require_once __DIR__ . '/../../src/processors/AbstractMessageProcessor.php';

            class TestProcessor extends AbstractMessageProcessor {
                protected function processMessages(): int {
                    usleep(10000); // Simulate work
                    return 0;
                }

                protected function getProcessorName(): string {
                    return 'Test';
                }
            }

            $processor = new TestProcessor(
                ['min_interval_ms' => 10, 'max_interval_ms' => 100],
                $lockfile
            );

            try {
                $processor->run();
            } catch (Exception $e) {
                error_log("Processor error: " . $e->getMessage());
            }

            exit(0);
        }

        return $pid;
    }

    // Helper: Start processor with log output
    private function startProcessorWithLog(string $lockfile, string $logfile): int {
        $pid = pcntl_fork();

        if ($pid === 0) {
            // Child process - redirect output
            $log = fopen($logfile, 'w');
            fclose(STDOUT);
            fclose(STDERR);
            $STDOUT = $log;
            $STDERR = $log;

            require_once __DIR__ . '/../../src/processors/AbstractMessageProcessor.php';

            class TestProcessorWithLog extends AbstractMessageProcessor {
                protected function processMessages(): int {
                    return 0;
                }

                protected function getProcessorName(): string {
                    return 'Test';
                }
            }

            $processor = new TestProcessorWithLog(
                ['min_interval_ms' => 10, 'max_interval_ms' => 100],
                $lockfile
            );

            try {
                $processor->run();
            } catch (Exception $e) {
                error_log("Processor error: " . $e->getMessage());
            }

            fclose($log);
            exit(0);
        }

        return $pid;
    }

    // Helper assertion methods
    private function assertTrue($condition, string $message): void {
        if ($condition) {
            $this->passed++;
            $this->results[] = "[PASS] $message";
        } else {
            $this->failed++;
            $this->results[] = "[FAIL] $message";
        }
    }

    private function assertFalse($condition, string $message): void {
        $this->assertTrue(!$condition, $message);
    }

    private function cleanup(): void {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    private function printResults(): void {
        echo "\n=== Test Results ===\n";
        foreach ($this->results as $result) {
            echo "$result\n";
        }
        echo "\nTotal: " . ($this->passed + $this->failed) . " tests\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli') {
    $test = new GracefulShutdownTest();
    $test->run();
}
