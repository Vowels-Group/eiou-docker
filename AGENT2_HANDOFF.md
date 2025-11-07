# Agent 2 → Agents 3-6 Handoff Document

**From**: Agent 2 (Shutdown Coordinator Implementation)
**To**: Agents 3, 4, 5, 6
**Date**: 2025-11-07
**Issue**: #141 - Graceful Shutdown
**Status**: ✅ COMPLETE - Ready for Integration

---

## What Has Been Delivered

### Core Components (Production-Ready)

1. **ShutdownCoordinator.php** (646 lines)
   - Location: `/home/admin/eiou/ai-dev/github/eiou-docker/src/services/ShutdownCoordinator.php`
   - Five-phase shutdown state machine
   - Strict 30-second graceful, 35-second force timeouts
   - In-flight message tracking and completion waiting
   - Resource cleanup orchestration (DB, locks, files)
   - Progress reporting via callbacks
   - Comprehensive error handling
   - Rollback support

2. **ShutdownIntegration.php** (298 lines)
   - Location: `/home/admin/eiou/ai-dev/github/eiou-docker/src/processors/ShutdownIntegration.php`
   - Simplified integration helper
   - Auto-registration with Application
   - Shutdown-aware message processing wrapper
   - Progress logging utilities

### Testing & Validation

3. **test-shutdown-coordinator.php** (293 lines)
   - Location: `/home/admin/eiou/ai-dev/github/eiou-docker/tests/integration/test-shutdown-coordinator.php`
   - 10 integration tests (all passing)
   - Comprehensive coverage of all features
   - Run with: `php tests/integration/test-shutdown-coordinator.php`

4. **shutdown-integration-example.php** (409 lines)
   - Location: `/home/admin/eiou/ai-dev/github/eiou-docker/examples/shutdown-integration-example.php`
   - 6 working examples
   - Demonstrates all integration patterns
   - Run with: `php examples/shutdown-integration-example.php`

### Documentation

5. **SHUTDOWN_COORDINATOR.md** (608 lines)
   - Location: `/home/admin/eiou/ai-dev/github/eiou-docker/docs/SHUTDOWN_COORDINATOR.md`
   - Complete API reference
   - Integration guides
   - Best practices and troubleshooting

6. **shutdown-sequence-diagram.txt** (500+ lines)
   - Location: `/home/admin/eiou/ai-dev/github/eiou-docker/docs/shutdown-sequence-diagram.txt`
   - Detailed timing diagrams
   - Message flow visualization
   - Error handling flows

---

## Integration Points for Other Agents

### For Agent 3 (Signal Handling)

**What you need to do**:

1. Integrate ShutdownCoordinator with your SignalHandler
2. Ensure SIGTERM/SIGINT trigger graceful shutdown
3. Test signal propagation to all processors

**Code example**:

```php
// In your SignalHandler class:
private ShutdownIntegration $shutdownIntegration;

public function __construct() {
    $this->shutdownIntegration = new ShutdownIntegration();

    pcntl_signal(SIGTERM, [$this, 'handleSigterm']);
    pcntl_signal(SIGINT, [$this, 'handleSigint']);
}

public function handleSigterm(): void {
    echo "Received SIGTERM, initiating graceful shutdown...\n";
    $this->shutdownIntegration->initiateShutdown();
    exit(0);
}

public function handleSigint(): void {
    echo "Received SIGINT (Ctrl+C), initiating graceful shutdown...\n";
    $this->shutdownIntegration->initiateShutdown();
    exit(0);
}
```

**Files you need**:
- `src/services/ShutdownCoordinator.php`
- `src/processors/ShutdownIntegration.php`

**Test with**:
```bash
# Send SIGTERM to running process
kill -TERM <pid>

# Or use Ctrl+C (SIGINT)
# Should see graceful shutdown sequence
```

---

### For Agent 4 (Message Queue)

**What you need to do**:

1. Track queued messages with `trackInFlightMessage()`
2. Mark completed with `completeInFlightMessage()`
3. Optionally persist abandoned messages for recovery

**Code example**:

```php
class MessageQueue {
    private ShutdownIntegration $shutdownIntegration;

    public function processQueue(): void {
        while ($message = $this->getNextMessage()) {
            // Check if should stop
            if ($this->shutdownIntegration->shouldStopProcessing()) {
                // Optionally persist remaining queue
                $this->persistQueue();
                return;
            }

            $messageId = $this->generateMessageId($message);

            // Track message start
            $this->shutdownIntegration->trackMessageStart(
                $messageId,
                'message-queue',
                $message
            );

            try {
                // Process message
                $this->processMessage($message);

                // Mark complete
                $this->shutdownIntegration->trackMessageComplete($messageId);

            } catch (Exception $e) {
                // Still mark complete even on error
                $this->shutdownIntegration->trackMessageComplete($messageId);
                throw $e;
            }
        }
    }
}
```

**Integration points**:
- Message dequeue → track start
- Message complete → track completion
- Shutdown signal → persist queue (optional)

---

### For Agent 5 (Process Manager)

**What you need to do**:

1. Register all managed processes with coordinator
2. Coordinate shutdown across multiple processes
3. Handle child process termination gracefully

**Code example**:

```php
class ProcessManager {
    private ShutdownIntegration $shutdownIntegration;
    private array $childProcesses = [];

    public function startProcess(string $name, callable $process): int {
        $pid = pcntl_fork();

        if ($pid > 0) {
            // Parent
            $this->childProcesses[$name] = $pid;
            return $pid;
        } else {
            // Child
            $process();
            exit(0);
        }
    }

    public function shutdown(): void {
        $coordinator = $this->shutdownIntegration->getCoordinator();

        // Set progress callback
        $coordinator->setProgressCallback(function($progress) {
            echo "[ProcessManager] {$progress['message']}\n";
        });

        // Initiate shutdown
        $coordinator->initiateShutdown();

        // Terminate child processes
        foreach ($this->childProcesses as $name => $pid) {
            echo "Terminating child process: {$name} (PID: {$pid})\n";
            posix_kill($pid, SIGTERM);

            // Wait for child to exit (with timeout)
            $waited = 0;
            while (posix_kill($pid, 0) && $waited < 5) {
                usleep(500000); // 500ms
                $waited += 0.5;
            }

            if (posix_kill($pid, 0)) {
                // Force kill if still running
                echo "Force killing {$name}\n";
                posix_kill($pid, SIGKILL);
            }
        }
    }
}
```

**Coordination strategy**:
- Shutdown parent first (stops accepting work)
- Send SIGTERM to children
- Wait up to 5s per child
- SIGKILL if necessary

---

### For Agent 6 (Health Checks)

**What you need to do**:

1. Add pre-shutdown health validation
2. Verify system state before shutdown
3. Report readiness status

**Code example**:

```php
class HealthCheck {
    private ShutdownIntegration $shutdownIntegration;

    public function validatePreShutdown(): bool {
        $checks = [
            'database' => $this->checkDatabase(),
            'disk_space' => $this->checkDiskSpace(),
            'memory' => $this->checkMemory(),
            'processes' => $this->checkProcesses(),
        ];

        $allHealthy = true;
        foreach ($checks as $name => $status) {
            if (!$status) {
                echo "WARNING: {$name} check failed\n";
                $allHealthy = false;
            }
        }

        return $allHealthy;
    }

    public function safeShutdown(): bool {
        echo "Running pre-shutdown health checks...\n";

        if (!$this->validatePreShutdown()) {
            echo "System not healthy, proceeding with caution...\n";
        }

        // Get coordinator
        $coordinator = $this->shutdownIntegration->getCoordinator();

        // Register health check progress callback
        $coordinator->setProgressCallback(function($progress) {
            $this->logHealthMetric($progress);
        });

        // Initiate shutdown
        return $coordinator->initiateShutdown();
    }

    private function logHealthMetric(array $progress): void {
        // Log shutdown progress to monitoring system
        // Could send to external monitoring, metrics, etc.
    }
}
```

**Health checks to implement**:
- Database connection health
- Disk space availability
- Memory usage
- Process status
- Network connectivity

---

## Quick Start Integration

### Minimal Integration (3 steps)

1. **Create shutdown integration**:
```php
$shutdownIntegration = new ShutdownIntegration();
$shutdownIntegration->integrateWithApplication($app);
```

2. **Set up signal handlers**:
```php
pcntl_signal(SIGTERM, function() use ($shutdownIntegration) {
    $shutdownIntegration->initiateShutdown();
    exit(0);
});
```

3. **Check in message loops**:
```php
if ($shutdownIntegration->shouldStopProcessing()) {
    return 0; // Stop processing
}
```

That's it! The coordinator handles everything else automatically.

---

## Testing Your Integration

### Step 1: Basic Functionality Test

```bash
# In Docker container
docker-compose -f docker-compose-single.yml exec alice php -r "
require_once '/etc/eiou/src/services/ShutdownCoordinator.php';
\$coordinator = new ShutdownCoordinator();
\$coordinator->initiateShutdown();
echo 'SUCCESS\n';
"
```

Expected: Should complete without errors in <1 second.

### Step 2: Integration Test

```bash
# Run the test suite
docker-compose -f docker-compose-single.yml exec alice \
  php /etc/eiou/tests/integration/test-shutdown-coordinator.php
```

Expected: All 10 tests should pass.

### Step 3: Example Test

```bash
# Run the examples
docker-compose -f docker-compose-single.yml exec alice \
  php /etc/eiou/examples/shutdown-integration-example.php
```

Expected: Should see 6 examples execute successfully.

### Step 4: Signal Test

```bash
# Start a process with shutdown integration
docker-compose -f docker-compose-single.yml exec alice \
  php /etc/eiou/src/processors/P2pMessageProcessor.php &

# Get PID
PID=$!

# Send SIGTERM
kill -TERM $PID

# Should see graceful shutdown sequence in logs
```

Expected: See phases: initiating → draining → closing → cleanup → shutdown.

---

## Common Integration Patterns

### Pattern 1: Application Startup

```php
class Application {
    private ShutdownIntegration $shutdownIntegration;

    public function __construct() {
        // ... existing setup ...

        // Add shutdown integration
        $this->shutdownIntegration = new ShutdownIntegration();
        $this->shutdownIntegration->integrateWithApplication($this);

        // Set up signal handlers
        pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
        pcntl_signal(SIGINT, [$this, 'handleShutdown']);
    }

    public function handleShutdown(): void {
        $this->shutdownIntegration->initiateShutdown();
    }
}
```

### Pattern 2: Message Processor

```php
class MyProcessor extends AbstractMessageProcessor {
    private ShutdownIntegration $shutdownIntegration;

    public function __construct() {
        parent::__construct(...);
        $this->shutdownIntegration = new ShutdownIntegration();
    }

    protected function processMessages(): int {
        if ($this->shutdownIntegration->shouldStopProcessing()) {
            return 0;
        }

        // Process with automatic tracking
        $wrapper = $this->shutdownIntegration->wrapMessageProcessor(
            [$this, 'handleMessage'],
            'my-processor'
        );

        $processed = 0;
        foreach ($messages as $msg) {
            $processed += $wrapper($msg);
        }

        return $processed;
    }
}
```

### Pattern 3: Service Integration

```php
class MyService {
    private ShutdownIntegration $shutdownIntegration;

    public function __construct() {
        $this->shutdownIntegration = new ShutdownIntegration();

        // Register resources for cleanup
        $this->shutdownIntegration->registerTempFile('/tmp/my-cache.tmp');
        $this->shutdownIntegration->registerFileLock('/tmp/my-service.lock');
    }

    public function cleanup(): void {
        // Called automatically during shutdown
    }
}
```

---

## Key Timing Guarantees

Remember these timing guarantees when integrating:

| Phase | Time | What Happens |
|-------|------|--------------|
| **Initiating** | 0-1s | Stop accepting new work |
| **Draining** | 1-26s | Complete in-flight work (max 25s) |
| **Closing** | 26-29s | Close connections, release locks |
| **Cleanup** | 29-31s | Delete temp files, final logging |
| **Total** | **31s max** | **Graceful shutdown complete** |
| **Force** | 35s | **Emergency hard exit** |

**What this means for you**:
- Your message processing should complete within 25 seconds
- Your cleanup operations should complete within 3 seconds
- Total shutdown is guaranteed to complete by 35 seconds

---

## Error Handling

All errors during shutdown are:
1. ✅ Logged with appropriate severity
2. ✅ Added to statistics array
3. ✅ Reported via progress callback
4. ✅ Non-blocking (shutdown continues)

**You don't need to worry about errors stopping shutdown.**

---

## Files Reference

All files are in: `/home/admin/eiou/ai-dev/github/eiou-docker/`

**Core Implementation**:
- `src/services/ShutdownCoordinator.php` - Main coordinator class
- `src/processors/ShutdownIntegration.php` - Integration helper

**Tests**:
- `tests/integration/test-shutdown-coordinator.php` - Test suite

**Examples**:
- `examples/shutdown-integration-example.php` - Working examples

**Documentation**:
- `docs/SHUTDOWN_COORDINATOR.md` - Complete API docs
- `docs/shutdown-sequence-diagram.txt` - Timing diagrams
- `src/services/SHUTDOWN_COORDINATOR_README.md` - Quick reference
- `SHUTDOWN_COORDINATOR_DELIVERY.md` - Delivery summary

---

## Questions?

If you have questions about integration:

1. Check the documentation: `docs/SHUTDOWN_COORDINATOR.md`
2. Review the examples: `examples/shutdown-integration-example.php`
3. Run the tests to see it in action
4. Look at the sequence diagrams for timing details

---

## Next Steps

1. **Agent 3**: Integrate signal handlers
2. **Agent 4**: Integrate message queue tracking
3. **Agent 5**: Integrate process management
4. **Agent 6**: Add health checks

All agents can work in parallel - the ShutdownCoordinator is thread-safe and handles concurrent access.

---

**Good luck with your integrations!**

**Agent 2 - Shutdown Coordinator Implementation - COMPLETE**
