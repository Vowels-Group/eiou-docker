#!/bin/bash
# Copyright 2025
# Log aggregator for test suite - collects and organizes test logs
# Source this file in test scripts for logging functionality

# Color codes
RED=${RED:-'\033[0;31m'}
GREEN=${GREEN:-'\033[0;32m'}
YELLOW=${YELLOW:-'\033[1;33m'}
NC=${NC:-'\033[0m'}

# Log directory (can be overridden)
LOG_DIR="${LOG_DIR:-/tmp/eiou-test-logs-$(date +%Y%m%d-%H%M%S)}"
TEST_RESULTS_FILE="${LOG_DIR}/test-results.jsonl"
TEST_REPORT_FILE="${LOG_DIR}/test-report.json"

# Test tracking
declare -A TEST_RESULTS
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0
TEST_START_TIME=$(date +%s)

# Initialize logging
init_logging() {
    mkdir -p "$LOG_DIR"
    echo "Log directory: $LOG_DIR"

    # Create test results file header
    echo "# EIOU Test Results - $(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$TEST_RESULTS_FILE"
}

# Log a test result
# Usage: log_test_result test_name status [duration_ms] [details]
log_test_result() {
    local test_name="$1"
    local status="$2"
    local duration="${3:-0}"
    local details="${4:-}"

    TOTAL_TESTS=$((TOTAL_TESTS + 1))

    if [[ "$status" == "PASSED" ]] || [[ "$status" == "passed" ]]; then
        PASSED_TESTS=$((PASSED_TESTS + 1))
    else
        FAILED_TESTS=$((FAILED_TESTS + 1))
    fi

    # Store in associative array
    TEST_RESULTS["$test_name"]="$status"

    # Write to JSONL file
    local json_details=""
    if [[ -n "$details" ]]; then
        json_details=", \"details\": \"$(echo "$details" | sed 's/"/\\"/g')\""
    fi

    cat >> "$TEST_RESULTS_FILE" << RESULT_EOF
{"timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)", "test": "$test_name", "status": "$status", "duration_ms": $duration$json_details}
RESULT_EOF
}

# Capture container logs
# Usage: capture_container_logs [container_name...]
capture_container_logs() {
    local containers=("$@")

    # If no containers specified, get all test containers
    if [[ ${#containers[@]} -eq 0 ]]; then
        mapfile -t containers < <(docker ps -a --filter "name=http" --format "{{.Names}}" 2>/dev/null)
    fi

    mkdir -p "$LOG_DIR/containers"

    for container in "${containers[@]}"; do
        if docker ps -a --format "{{.Names}}" | grep -q "^${container}$"; then
            # Container logs
            docker logs "$container" > "$LOG_DIR/containers/${container}.log" 2>&1 || true

            # PHP error log
            docker exec "$container" cat /var/log/php_errors.log \
                > "$LOG_DIR/containers/${container}-php-errors.log" 2>&1 || true

            # Apache error log
            docker exec "$container" cat /var/log/apache2/error.log \
                > "$LOG_DIR/containers/${container}-apache-errors.log" 2>&1 || true

            # Container state
            docker inspect "$container" \
                > "$LOG_DIR/containers/${container}-inspect.json" 2>&1 || true

            echo "  Captured logs for: $container"
        fi
    done
}

# Capture system state
capture_system_state() {
    mkdir -p "$LOG_DIR/system"

    # Docker info
    docker info > "$LOG_DIR/system/docker-info.txt" 2>&1 || true

    # Docker ps
    docker ps -a > "$LOG_DIR/system/docker-ps.txt" 2>&1 || true

    # Docker networks
    docker network ls > "$LOG_DIR/system/docker-networks.txt" 2>&1 || true

    # Docker volumes
    docker volume ls > "$LOG_DIR/system/docker-volumes.txt" 2>&1 || true

    # System resources
    free -h > "$LOG_DIR/system/memory.txt" 2>&1 || true
    df -h > "$LOG_DIR/system/disk.txt" 2>&1 || true
}

# Generate final test report
generate_test_report() {
    local build_name="${BUILD_NAME:-unknown}"
    local mode="${MODE:-unknown}"
    local end_time=$(date +%s)
    local total_duration=$((end_time - TEST_START_TIME))
    local success_rate=0

    if [[ $TOTAL_TESTS -gt 0 ]]; then
        success_rate=$(awk "BEGIN {printf \"%.1f\", ($PASSED_TESTS / $TOTAL_TESTS) * 100}")
    fi

    # Build failed tests array
    local failed_tests_json="[]"
    local failed_array=()
    for test in "${!TEST_RESULTS[@]}"; do
        if [[ "${TEST_RESULTS[$test]}" != "PASSED" ]] && [[ "${TEST_RESULTS[$test]}" != "passed" ]]; then
            failed_array+=("\"$test\"")
        fi
    done
    if [[ ${#failed_array[@]} -gt 0 ]]; then
        failed_tests_json="[$(IFS=,; echo "${failed_array[*]}")]"
    fi

    cat > "$TEST_REPORT_FILE" << REPORT_EOF
{
    "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "build_name": "$build_name",
    "mode": "$mode",
    "summary": {
        "total_tests": $TOTAL_TESTS,
        "passed": $PASSED_TESTS,
        "failed": $FAILED_TESTS,
        "success_rate": $success_rate,
        "duration_seconds": $total_duration
    },
    "failed_tests": $failed_tests_json,
    "log_directory": "$LOG_DIR"
}
REPORT_EOF

    echo ""
    echo "================================================================"
    echo "     TEST REPORT GENERATED"
    echo "================================================================"
    echo "  Build:          $build_name"
    echo "  Mode:           $mode"
    echo "  Total Tests:    $TOTAL_TESTS"
    echo -e "  ${GREEN}Passed:${NC}         $PASSED_TESTS"
    echo -e "  ${RED}Failed:${NC}         $FAILED_TESTS"
    echo "  Success Rate:   ${success_rate}%"
    echo "  Duration:       ${total_duration}s"
    echo "  Report:         $TEST_REPORT_FILE"
    echo "================================================================"
}

# Print test summary (for incremental display)
print_test_summary() {
    local test_file="$1"
    local passed="$2"
    local failed="$3"

    if [[ $failed -eq 0 ]]; then
        echo -e "${GREEN}✔ PASSED all '$test_file' tests!${NC}"
    else
        echo -e "${RED}✘ FAILED $failed '$test_file' tests${NC}"
    fi
}

# Clean up old log directories (keep last N)
cleanup_old_logs() {
    local keep_count="${1:-5}"
    local log_base="/tmp"

    # Find and remove old log directories
    ls -dt "$log_base"/eiou-test-logs-* 2>/dev/null | tail -n +$((keep_count + 1)) | while read dir; do
        echo "Removing old log directory: $dir"
        rm -rf "$dir"
    done
}

# Archive logs to a compressed file
archive_logs() {
    local archive_name="${1:-eiou-test-logs-$(date +%Y%m%d-%H%M%S).tar.gz}"

    if [[ -d "$LOG_DIR" ]]; then
        tar -czf "$archive_name" -C "$(dirname "$LOG_DIR")" "$(basename "$LOG_DIR")"
        echo "Logs archived to: $archive_name"
    fi
}

# Finalize logging (call at end of test suite)
finalize_logging() {
    capture_container_logs
    capture_system_state
    generate_test_report

    echo ""
    echo "Full logs available at: $LOG_DIR"
}

# Export functions
export -f log_test_result
export -f capture_container_logs
export -f capture_system_state
export LOG_DIR
export TEST_RESULTS_FILE
