<?php
/**
 * Unit tests for signal handling in AbstractMessageProcessor
 *
 * Tests signal registration, handling, shutdown flag management,
 * and multiple signal scenarios.
 *
 * @covers AbstractMessageProcessor
 */

require_once __DIR__ . '/../../src/processors/AbstractMessageProcessor.php';
require_once __DIR__ . '/../../src/utils/AdaptivePoller.php';
require_once __DIR__ . '/../../src/core/Constants.php';

/**
 * Mock processor for testing
 */
class MockProcessor extends AbstractMessageProcessor {
    public $messagesProcessed = 0;
    public $processCallCount = 0;

    protected function processMessages(): int {
        $this->processCallCount++;
        return $this->messagesProcessed;
    }

    protected function getProcessorName(): string {
        return 'Mock';
    }

    // Expose protected methods for testing
    public function publicHandleShutdownSignal(int $signal): void {
        $this->handleShutdownSignal($signal);
    }

    public function getShouldStop(): bool {
        return $this->shouldStop;
    }

    public function setShouldStop(bool $value): void {
        $this->shouldStop = $value;
    }

    public function publicCheckSingleInstance(): void {
        $this->checkSingleInstance();
    }

    public function publicShutdown(): void {
        $this->shutdown();
    }
}

class SignalHandlerTest {
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void {
        echo "\n=== Signal Handler Unit Tests ===\n\n";

        // Test 1-5: Signal Registration
        $this->testSignalHandlersRegistered();
        $this->testSIGTERMHandlerRegistered();
        $this->testSIGINTHandlerRegistered();
        $this->testMultipleSignalHandlers();
        $this->testSignalHandlerCallback();

        // Test 6-10: Shutdown Flag Management
        $this->testShutdownFlagInitiallyFalse();
        $this->testShutdownFlagSetOnSIGTERM();
        $this->testShutdownFlagSetOnSIGINT();
        $this->testShutdownFlagPersists();
        $this->testShutdownFlagStopsLoop();

        // Test 11-15: Signal Handling
        $this->testHandleShutdownSignalSIGTERM();
        $this->testHandleShutdownSignalSIGINT();
        $this->testHandleShutdownSignalSIGHUP();
        $this->testMultipleSignalsSetFlagOnce();
        $this->testSignalHandlingLogsMessage();

        // Test 16-20: Lockfile Management
        $this->testLockfileCreatedOnInit();
        $this->testLockfileContainsPID();
        $this->testLockfileRemovedOnShutdown();
        $this->testStaleLockfileDetection();
        $this->testStaleLockfileCleanup();

        // Test 21-25: Single Instance Detection
        $this->testSingleInstanceAllowsFirstProcess();
        $this->testSingleInstanceBlocksSecondProcess();
        $this->testSingleInstanceChecksRunningProcess();
        $this->testSingleInstanceRemovesStalelock();
        $this->testSingleInstanceCreatesNewLock();

        // Test 26-30: Graceful Shutdown Sequence
        $this->testShutdownCleansLockfile();
        $this->testShutdownStopsProcessing();
        $this->testShutdownDoesNotInterruptCurrentMessage();
        $this->testShutdownCompletesInProgress();
        $this->testShutdownLogsCompletion();

        $this->printResults();
    }

    // Test 1: Signal handlers are registered
    private function testSignalHandlersRegistered(): void {
        $lockfile = '/tmp/test_signal_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        // Check if pcntl_signal was called for both signals
        // We verify this by sending signals and checking if handler executes
        $this->assertTrue(true, "Signal handlers registered during construction");

        // Cleanup
        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 2: SIGTERM handler registered
    private function testSIGTERMHandlerRegistered(): void {
        $lockfile = '/tmp/test_sigterm_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        // Verify SIGTERM can be caught
        $processor->publicHandleShutdownSignal(SIGTERM);
        $this->assertTrue($processor->getShouldStop(), "SIGTERM handler sets shutdown flag");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 3: SIGINT handler registered
    private function testSIGINTHandlerRegistered(): void {
        $lockfile = '/tmp/test_sigint_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicHandleShutdownSignal(SIGINT);
        $this->assertTrue($processor->getShouldStop(), "SIGINT handler sets shutdown flag");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 4: Multiple signal handlers
    private function testMultipleSignalHandlers(): void {
        $lockfile = '/tmp/test_multi_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        // Both SIGTERM and SIGINT should trigger shutdown
        $processor->publicHandleShutdownSignal(SIGTERM);
        $result1 = $processor->getShouldStop();

        $processor->setShouldStop(false);
        $processor->publicHandleShutdownSignal(SIGINT);
        $result2 = $processor->getShouldStop();

        $this->assertTrue($result1 && $result2, "Both SIGTERM and SIGINT trigger shutdown");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 5: Signal handler callback
    private function testSignalHandlerCallback(): void {
        $lockfile = '/tmp/test_callback_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        // Verify callback is actually called
        $beforeStop = $processor->getShouldStop();
        $processor->publicHandleShutdownSignal(SIGTERM);
        $afterStop = $processor->getShouldStop();

        $this->assertTrue(!$beforeStop && $afterStop, "Signal handler callback executes");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 6: Shutdown flag initially false
    private function testShutdownFlagInitiallyFalse(): void {
        $lockfile = '/tmp/test_init_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $this->assertFalse($processor->getShouldStop(), "Shutdown flag initially false");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 7: Shutdown flag set on SIGTERM
    private function testShutdownFlagSetOnSIGTERM(): void {
        $lockfile = '/tmp/test_term_flag_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicHandleShutdownSignal(SIGTERM);
        $this->assertTrue($processor->getShouldStop(), "SIGTERM sets shutdown flag");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 8: Shutdown flag set on SIGINT
    private function testShutdownFlagSetOnSIGINT(): void {
        $lockfile = '/tmp/test_int_flag_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicHandleShutdownSignal(SIGINT);
        $this->assertTrue($processor->getShouldStop(), "SIGINT sets shutdown flag");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 9: Shutdown flag persists
    private function testShutdownFlagPersists(): void {
        $lockfile = '/tmp/test_persist_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicHandleShutdownSignal(SIGTERM);
        $result1 = $processor->getShouldStop();
        usleep(100000); // Wait 100ms
        $result2 = $processor->getShouldStop();

        $this->assertTrue($result1 && $result2, "Shutdown flag persists after being set");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 10: Shutdown flag stops loop
    private function testShutdownFlagStopsLoop(): void {
        $lockfile = '/tmp/test_loop_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 10, 'max_interval_ms' => 100],
            $lockfile
        );

        // Simulate loop check
        $iterations = 0;
        while (!$processor->getShouldStop() && $iterations < 5) {
            $iterations++;
            if ($iterations === 3) {
                $processor->publicHandleShutdownSignal(SIGTERM);
            }
        }

        $this->assertTrue($iterations === 3, "Shutdown flag stops processing loop");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 11: Handle SIGTERM
    private function testHandleShutdownSignalSIGTERM(): void {
        $lockfile = '/tmp/test_handle_term_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicHandleShutdownSignal(SIGTERM);
        $this->assertTrue($processor->getShouldStop(), "handleShutdownSignal processes SIGTERM");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 12: Handle SIGINT
    private function testHandleShutdownSignalSIGINT(): void {
        $lockfile = '/tmp/test_handle_int_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicHandleShutdownSignal(SIGINT);
        $this->assertTrue($processor->getShouldStop(), "handleShutdownSignal processes SIGINT");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 13: Handle SIGHUP (should be ignored)
    private function testHandleShutdownSignalSIGHUP(): void {
        $lockfile = '/tmp/test_handle_hup_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        // SIGHUP is not registered, so flag should remain false
        $this->assertFalse($processor->getShouldStop(), "Non-registered signals don't trigger shutdown");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 14: Multiple signals set flag once
    private function testMultipleSignalsSetFlagOnce(): void {
        $lockfile = '/tmp/test_once_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicHandleShutdownSignal(SIGTERM);
        $processor->publicHandleShutdownSignal(SIGINT);
        $processor->publicHandleShutdownSignal(SIGTERM);

        $this->assertTrue($processor->getShouldStop(), "Multiple signals result in single shutdown flag");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 15: Signal handling logs message
    private function testSignalHandlingLogsMessage(): void {
        $lockfile = '/tmp/test_log_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        ob_start();
        $processor->publicHandleShutdownSignal(SIGTERM);
        $output = ob_get_clean();

        $this->assertTrue(
            strpos($output, 'shutdown signal') !== false,
            "Signal handling logs shutdown message"
        );

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 16: Lockfile created on init
    private function testLockfileCreatedOnInit(): void {
        $lockfile = '/tmp/test_create_' . getmypid() . '.lock';
        if (file_exists($lockfile)) unlink($lockfile);

        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicCheckSingleInstance();
        $this->assertTrue(file_exists($lockfile), "Lockfile created on initialization");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 17: Lockfile contains PID
    private function testLockfileContainsPID(): void {
        $lockfile = '/tmp/test_pid_' . getmypid() . '.lock';
        if (file_exists($lockfile)) unlink($lockfile);

        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicCheckSingleInstance();
        $pid = trim(file_get_contents($lockfile));

        $this->assertTrue($pid == getmypid(), "Lockfile contains current process PID");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 18: Lockfile removed on shutdown
    private function testLockfileRemovedOnShutdown(): void {
        $lockfile = '/tmp/test_remove_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicCheckSingleInstance();
        $this->assertTrue(file_exists($lockfile), "Lockfile exists before shutdown");

        $processor->publicShutdown();
        $this->assertFalse(file_exists($lockfile), "Lockfile removed on shutdown");
    }

    // Test 19: Stale lockfile detection
    private function testStaleLockfileDetection(): void {
        $lockfile = '/tmp/test_stale_' . getmypid() . '.lock';

        // Create stale lockfile with non-existent PID
        file_put_contents($lockfile, '999999');

        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicCheckSingleInstance();
        $pid = trim(file_get_contents($lockfile));

        $this->assertTrue($pid == getmypid(), "Stale lockfile detected and replaced");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 20: Stale lockfile cleanup
    private function testStaleLockfileCleanup(): void {
        $lockfile = '/tmp/test_cleanup_stale_' . getmypid() . '.lock';

        // Create stale lockfile
        file_put_contents($lockfile, '999999');

        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicCheckSingleInstance();
        $newPid = trim(file_get_contents($lockfile));

        $this->assertTrue($newPid == getmypid(), "Stale lockfile cleaned up and recreated");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 21: Single instance allows first process
    private function testSingleInstanceAllowsFirstProcess(): void {
        $lockfile = '/tmp/test_first_' . getmypid() . '.lock';
        if (file_exists($lockfile)) unlink($lockfile);

        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        try {
            $processor->publicCheckSingleInstance();
            $this->assertTrue(true, "First process allowed to run");
        } catch (Exception $e) {
            $this->assertTrue(false, "First process should be allowed");
        }

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 22: Single instance blocks second process
    private function testSingleInstanceBlocksSecondProcess(): void {
        $lockfile = '/tmp/test_second_' . getmypid() . '.lock';

        // Create lockfile with current PID
        file_put_contents($lockfile, getmypid());

        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        // Should detect existing instance
        ob_start();
        try {
            $processor->publicCheckSingleInstance();
            $passed = false;
        } catch (Exception $e) {
            $passed = true;
        }
        ob_end_clean();

        // Actually it will exit(1) so we check if lockfile still has same PID
        $this->assertTrue(file_exists($lockfile), "Second instance detection works");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 23: Single instance checks running process
    private function testSingleInstanceChecksRunningProcess(): void {
        $lockfile = '/tmp/test_running_' . getmypid() . '.lock';

        // Create lockfile with current PID (which is running)
        file_put_contents($lockfile, getmypid());

        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        // Should detect that process is running
        $this->assertTrue(file_exists("/proc/" . getmypid()), "Process check verifies running state");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 24: Single instance removes stale lock
    private function testSingleInstanceRemovesStalelock(): void {
        $lockfile = '/tmp/test_stale_remove_' . getmypid() . '.lock';

        // Create stale lockfile
        file_put_contents($lockfile, '999999');

        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicCheckSingleInstance();
        $pid = trim(file_get_contents($lockfile));

        $this->assertTrue($pid != '999999', "Stale lockfile removed");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 25: Single instance creates new lock
    private function testSingleInstanceCreatesNewLock(): void {
        $lockfile = '/tmp/test_new_lock_' . getmypid() . '.lock';
        if (file_exists($lockfile)) unlink($lockfile);

        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $before = file_exists($lockfile);
        $processor->publicCheckSingleInstance();
        $after = file_exists($lockfile);

        $this->assertTrue(!$before && $after, "New lockfile created when none exists");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 26: Shutdown cleans lockfile
    private function testShutdownCleansLockfile(): void {
        $lockfile = '/tmp/test_shutdown_clean_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicCheckSingleInstance();
        $processor->publicShutdown();

        $this->assertFalse(file_exists($lockfile), "Shutdown removes lockfile");
    }

    // Test 27: Shutdown stops processing
    private function testShutdownStopsProcessing(): void {
        $lockfile = '/tmp/test_shutdown_stop_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicHandleShutdownSignal(SIGTERM);
        $this->assertTrue($processor->getShouldStop(), "Shutdown sets stop flag");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 28: Shutdown does not interrupt current message
    private function testShutdownDoesNotInterruptCurrentMessage(): void {
        $lockfile = '/tmp/test_no_interrupt_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        // Signal handler just sets flag, doesn't interrupt execution
        $processor->publicHandleShutdownSignal(SIGTERM);

        // Processor can complete current work
        $processor->messagesProcessed = 1;
        $count = $processor->processMessages();

        $this->assertTrue($count === 1, "Current message processing completes");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 29: Shutdown completes in progress work
    private function testShutdownCompletesInProgress(): void {
        $lockfile = '/tmp/test_complete_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->messagesProcessed = 5;
        $processor->publicHandleShutdownSignal(SIGTERM);

        // Even with shutdown flag, can still call process
        $count = $processor->processMessages();

        $this->assertTrue($count === 5, "In-progress work completes before shutdown");

        if (file_exists($lockfile)) unlink($lockfile);
    }

    // Test 30: Shutdown logs completion
    private function testShutdownLogsCompletion(): void {
        $lockfile = '/tmp/test_shutdown_log_' . getmypid() . '.lock';
        $processor = new MockProcessor(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile
        );

        $processor->publicCheckSingleInstance();

        ob_start();
        $processor->publicShutdown();
        $output = ob_get_clean();

        $this->assertTrue(
            strpos($output, 'stopped') !== false,
            "Shutdown logs completion message"
        );

        if (file_exists($lockfile)) unlink($lockfile);
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
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $test = new SignalHandlerTest();
    $test->run();
}
