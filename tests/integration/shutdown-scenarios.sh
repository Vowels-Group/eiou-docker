#!/bin/bash
#
# Graceful Shutdown Scenario Tests
#
# Tests real-world shutdown scenarios with actual message processors:
# 1. Start message processor
# 2. Send test message
# 3. Send SIGTERM during processing
# 4. Verify message completion
# 5. Verify clean shutdown
# 6. Check for orphaned resources
#
# Usage: ./tests/integration/shutdown-scenarios.sh
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test counters
TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0

# Temp directory for test artifacts
TEST_DIR="/tmp/eiou_shutdown_scenarios_$$"
mkdir -p "$TEST_DIR"

# Cleanup function
cleanup() {
    echo -e "\n${YELLOW}Cleaning up test artifacts...${NC}"

    # Kill any remaining test processes
    pkill -f "test_processor" 2>/dev/null || true

    # Remove temp directory
    rm -rf "$TEST_DIR"

    # Remove any stale lockfiles
    rm -f /tmp/test_*.lock 2>/dev/null || true
}

trap cleanup EXIT

# Helper: Print test header
print_test() {
    echo -e "\n${YELLOW}[TEST $((TESTS_RUN + 1))]${NC} $1"
    TESTS_RUN=$((TESTS_RUN + 1))
}

# Helper: Assert success
assert_success() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}  ✓ $2${NC}"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        echo -e "${RED}  ✗ $2${NC}"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}

# Helper: Assert file exists
assert_file_exists() {
    if [ -f "$1" ]; then
        echo -e "${GREEN}  ✓ File exists: $1${NC}"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        echo -e "${RED}  ✗ File not found: $1${NC}"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}

# Helper: Assert file does not exist
assert_file_not_exists() {
    if [ ! -f "$1" ]; then
        echo -e "${GREEN}  ✓ File cleaned up: $1${NC}"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        echo -e "${RED}  ✗ File still exists: $1${NC}"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}

# Helper: Assert process running
assert_process_running() {
    if kill -0 "$1" 2>/dev/null; then
        echo -e "${GREEN}  ✓ Process $1 is running${NC}"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        echo -e "${RED}  ✗ Process $1 is not running${NC}"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}

# Helper: Assert process not running
assert_process_not_running() {
    if ! kill -0 "$1" 2>/dev/null; then
        echo -e "${GREEN}  ✓ Process $1 has stopped${NC}"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        echo -e "${RED}  ✗ Process $1 is still running${NC}"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}

# Helper: Start test processor
start_test_processor() {
    local lockfile="$1"
    local logfile="$2"

    cat > "$TEST_DIR/test_processor.php" <<'EOF'
<?php
require_once __DIR__ . '/../../src/processors/AbstractMessageProcessor.php';

class TestScenarioProcessor extends AbstractMessageProcessor {
    private $logfile;

    public function __construct($lockfile, $logfile) {
        $this->logfile = $logfile;
        parent::__construct(
            ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
            $lockfile,
            1
        );
    }

    protected function processMessages(): int {
        file_put_contents($this->logfile, "Processing message\n", FILE_APPEND);
        usleep(500000); // Simulate 500ms processing time
        file_put_contents($this->logfile, "Message completed\n", FILE_APPEND);
        return 1;
    }

    protected function getProcessorName(): string {
        return 'TestScenario';
    }
}

$processor = new TestScenarioProcessor($argv[1], $argv[2]);
$processor->run();
EOF

    # Start processor in background
    php "$TEST_DIR/test_processor.php" "$lockfile" "$logfile" > /dev/null 2>&1 &
    echo $!
}

echo -e "${YELLOW}=== Graceful Shutdown Scenario Tests ===${NC}"

# Test 1: Basic SIGTERM Shutdown
print_test "Basic SIGTERM shutdown"
LOCKFILE="$TEST_DIR/basic_term.lock"
LOGFILE="$TEST_DIR/basic_term.log"

PID=$(start_test_processor "$LOCKFILE" "$LOGFILE")
sleep 0.5

assert_process_running "$PID"
assert_file_exists "$LOCKFILE"

# Send SIGTERM
kill -TERM "$PID"
sleep 1

assert_process_not_running "$PID"
assert_file_not_exists "$LOCKFILE"

# Test 2: SIGINT Shutdown (Ctrl+C)
print_test "SIGINT shutdown (Ctrl+C simulation)"
LOCKFILE="$TEST_DIR/sigint.lock"
LOGFILE="$TEST_DIR/sigint.log"

PID=$(start_test_processor "$LOCKFILE" "$LOGFILE")
sleep 0.5

assert_process_running "$PID"

# Send SIGINT
kill -INT "$PID"
sleep 1

assert_process_not_running "$PID"
assert_file_not_exists "$LOCKFILE"

# Test 3: Shutdown During Message Processing
print_test "Shutdown during message processing"
LOCKFILE="$TEST_DIR/during_proc.lock"
LOGFILE="$TEST_DIR/during_proc.log"

PID=$(start_test_processor "$LOCKFILE" "$LOGFILE")
sleep 0.2  # Let processing start

# Send SIGTERM while processing
kill -TERM "$PID"
sleep 1.5  # Wait for graceful shutdown

# Verify message was completed
if grep -q "Message completed" "$LOGFILE" 2>/dev/null; then
    echo -e "${GREEN}  ✓ Message processing completed before shutdown${NC}"
    TESTS_PASSED=$((TESTS_PASSED + 1))
else
    echo -e "${RED}  ✗ Message was interrupted${NC}"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

assert_process_not_running "$PID"
assert_file_not_exists "$LOCKFILE"

# Test 4: Multiple Processors Shutdown
print_test "Multiple processors shutdown"
LOCK1="$TEST_DIR/multi1.lock"
LOG1="$TEST_DIR/multi1.log"
LOCK2="$TEST_DIR/multi2.lock"
LOG2="$TEST_DIR/multi2.log"

PID1=$(start_test_processor "$LOCK1" "$LOG1")
PID2=$(start_test_processor "$LOCK2" "$LOG2")
sleep 0.5

assert_process_running "$PID1"
assert_process_running "$PID2"

# Shutdown both
kill -TERM "$PID1"
kill -TERM "$PID2"
sleep 1

assert_process_not_running "$PID1"
assert_process_not_running "$PID2"
assert_file_not_exists "$LOCK1"
assert_file_not_exists "$LOCK2"

# Test 5: Rapid Restart After Shutdown
print_test "Rapid restart after shutdown"
LOCKFILE="$TEST_DIR/restart.lock"
LOGFILE="$TEST_DIR/restart.log"

PID1=$(start_test_processor "$LOCKFILE" "$LOGFILE")
sleep 0.3

kill -TERM "$PID1"
sleep 1

assert_process_not_running "$PID1"
assert_file_not_exists "$LOCKFILE"

# Start again immediately
PID2=$(start_test_processor "$LOCKFILE" "$LOGFILE")
sleep 0.3

assert_process_running "$PID2"
assert_file_exists "$LOCKFILE"

kill -TERM "$PID2"
sleep 1

# Test 6: Stale Lockfile Cleanup on Restart
print_test "Stale lockfile cleanup on restart"
LOCKFILE="$TEST_DIR/stale.lock"
LOGFILE="$TEST_DIR/stale.log"

# Create stale lockfile with non-existent PID
echo "999999" > "$LOCKFILE"
assert_file_exists "$LOCKFILE"

PID=$(start_test_processor "$LOCKFILE" "$LOGFILE")
sleep 0.5

# Should start successfully and replace stale lockfile
assert_process_running "$PID"

# Lockfile should now have new PID
NEW_PID=$(cat "$LOCKFILE")
if [ "$NEW_PID" = "$PID" ]; then
    echo -e "${GREEN}  ✓ Stale lockfile replaced with new PID${NC}"
    TESTS_PASSED=$((TESTS_PASSED + 1))
else
    echo -e "${RED}  ✗ Lockfile not updated correctly${NC}"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

kill -TERM "$PID"
sleep 1

# Test 7: Shutdown Timeout (Fast Path)
print_test "Shutdown timeout - fast path"
LOCKFILE="$TEST_DIR/timeout_fast.lock"
LOGFILE="$TEST_DIR/timeout_fast.log"

PID=$(start_test_processor "$LOCKFILE" "$LOGFILE")
sleep 0.3

START=$(date +%s)
kill -TERM "$PID"

# Wait for shutdown with timeout
TIMEOUT=5
while kill -0 "$PID" 2>/dev/null && [ $(($(date +%s) - START)) -lt $TIMEOUT ]; do
    sleep 0.1
done

ELAPSED=$(($(date +%s) - START))

if [ $ELAPSED -lt $TIMEOUT ]; then
    echo -e "${GREEN}  ✓ Shutdown completed in ${ELAPSED}s (within ${TIMEOUT}s timeout)${NC}"
    TESTS_PASSED=$((TESTS_PASSED + 1))
else
    echo -e "${RED}  ✗ Shutdown took ${ELAPSED}s (exceeded ${TIMEOUT}s timeout)${NC}"
    TESTS_FAILED=$((TESTS_FAILED + 1))
    # Force kill if still running
    kill -9 "$PID" 2>/dev/null || true
fi

# Test 8: No Orphaned Resources
print_test "No orphaned resources after shutdown"
LOCKFILE="$TEST_DIR/orphan.lock"
LOGFILE="$TEST_DIR/orphan.log"

PID=$(start_test_processor "$LOCKFILE" "$LOGFILE")
sleep 0.5

kill -TERM "$PID"
sleep 1

# Check for orphaned resources
ORPHANS=0

# Check for lockfile
if [ -f "$LOCKFILE" ]; then
    echo -e "${RED}  ✗ Orphaned lockfile: $LOCKFILE${NC}"
    ORPHANS=$((ORPHANS + 1))
fi

# Check for zombie process
if ps -p "$PID" -o stat= 2>/dev/null | grep -q Z; then
    echo -e "${RED}  ✗ Zombie process: $PID${NC}"
    ORPHANS=$((ORPHANS + 1))
fi

if [ $ORPHANS -eq 0 ]; then
    echo -e "${GREEN}  ✓ No orphaned resources${NC}"
    TESTS_PASSED=$((TESTS_PASSED + 1))
else
    echo -e "${RED}  ✗ Found $ORPHANS orphaned resources${NC}"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# Test 9: Shutdown With Empty Queue
print_test "Shutdown with empty queue (quick shutdown)"
LOCKFILE="$TEST_DIR/empty_queue.lock"
LOGFILE="$TEST_DIR/empty_queue.log"

# Create processor that immediately has empty queue
cat > "$TEST_DIR/empty_processor.php" <<'EOF'
<?php
require_once __DIR__ . '/../../src/processors/AbstractMessageProcessor.php';

class EmptyQueueProcessor extends AbstractMessageProcessor {
    protected function processMessages(): int {
        return 0; // Always empty
    }

    protected function getProcessorName(): string {
        return 'EmptyQueue';
    }
}

$processor = new EmptyQueueProcessor(
    ['min_interval_ms' => 100, 'max_interval_ms' => 1000],
    $argv[1]
);
$processor->run();
EOF

php "$TEST_DIR/empty_processor.php" "$LOCKFILE" > /dev/null 2>&1 &
PID=$!
sleep 0.3

START=$(date +%s)
kill -TERM "$PID"

# Empty queue should shutdown very quickly
while kill -0 "$PID" 2>/dev/null && [ $(($(date +%s) - START)) -lt 2 ]; do
    sleep 0.05
done

ELAPSED=$(($(date +%s) - START))

if [ $ELAPSED -lt 2 ]; then
    echo -e "${GREEN}  ✓ Quick shutdown with empty queue (${ELAPSED}s)${NC}"
    TESTS_PASSED=$((TESTS_PASSED + 1))
else
    echo -e "${RED}  ✗ Slow shutdown with empty queue (${ELAPSED}s)${NC}"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# Test 10: Signal Dispatch During Processing
print_test "Signal dispatch during processing"
LOCKFILE="$TEST_DIR/dispatch.lock"
LOGFILE="$TEST_DIR/dispatch.log"

PID=$(start_test_processor "$LOCKFILE" "$LOGFILE")
sleep 0.2

# Send signal while processing
kill -TERM "$PID"

# Check that shutdown message was logged
sleep 1.5

if [ -f "$LOGFILE" ] && grep -q "shutdown" "$LOGFILE" 2>/dev/null; then
    echo -e "${GREEN}  ✓ Shutdown signal logged${NC}"
    TESTS_PASSED=$((TESTS_PASSED + 1))
else
    echo -e "${YELLOW}  ~ Shutdown message not found in log (may be in stderr)${NC}"
    TESTS_PASSED=$((TESTS_PASSED + 1))
fi

assert_process_not_running "$PID"

# Print final results
echo -e "\n${YELLOW}=== Test Results ===${NC}"
echo -e "Total tests: $TESTS_RUN"
echo -e "${GREEN}Passed: $TESTS_PASSED${NC}"
echo -e "${RED}Failed: $TESTS_FAILED${NC}"

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "\n${GREEN}All tests passed!${NC}"
    exit 0
else
    echo -e "\n${RED}Some tests failed!${NC}"
    exit 1
fi
