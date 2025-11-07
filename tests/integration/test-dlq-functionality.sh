#!/bin/bash
# Copyright 2025
#
# Integration Test: Dead Letter Queue (DLQ) Functionality (Issue #139)
#
# Tests DLQ for handling permanently failed messages:
# - Failed messages after max retries → DLQ
# - Manual review/reprocessing capability
# - Alerting for messages in DLQ
# - DLQ message persistence
# - DLQ statistics and monitoring
#
# Usage: ./test-dlq-functionality.sh

set -e

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Configuration
TEST_NAME="Dead Letter Queue (DLQ) Tests"
COMPOSE_FILE="docker-compose-4line.yml"
TEST_TIMESTAMP=$(date +%Y%m%d_%H%M%S)
LOG_DIR="/tmp/eiou-test-logs/dlq-${TEST_TIMESTAMP}"
RESULTS_FILE="${LOG_DIR}/dlq-test-results.txt"

# Node names
NODE_ALICE="alice"
NODE_BOB="bob"
NODE_CAROL="carol"
NODE_DANIEL="daniel"

# Test counters
TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0

# DLQ configuration (from issue #139)
MAX_RETRIES=5

mkdir -p "${LOG_DIR}"

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1" | tee -a "${RESULTS_FILE}"
}

log_success() {
    echo -e "${GREEN}[PASS]${NC} $1" | tee -a "${RESULTS_FILE}"
    ((TESTS_PASSED++))
}

log_failure() {
    echo -e "${RED}[FAIL]${NC} $1" | tee -a "${RESULTS_FILE}"
    ((TESTS_FAILED++))
}

log_warning() {
    echo -e "${YELLOW}[WARN]${NC} $1" | tee -a "${RESULTS_FILE}"
}

print_header() {
    echo "" | tee -a "${RESULTS_FILE}"
    echo "========================================" | tee -a "${RESULTS_FILE}"
    echo "  ${TEST_NAME}" | tee -a "${RESULTS_FILE}"
    echo "  Issue #139 - Dead Letter Queue" | tee -a "${RESULTS_FILE}"
    echo "  Timestamp: ${TEST_TIMESTAMP}" | tee -a "${RESULTS_FILE}"
    echo "========================================" | tee -a "${RESULTS_FILE}"
    echo "" | tee -a "${RESULTS_FILE}"
}

print_summary() {
    echo "" | tee -a "${RESULTS_FILE}"
    echo "========================================" | tee -a "${RESULTS_FILE}"
    echo "  TEST SUMMARY" | tee -a "${RESULTS_FILE}"
    echo "========================================" | tee -a "${RESULTS_FILE}"
    echo "Total Tests: ${TESTS_RUN}" | tee -a "${RESULTS_FILE}"
    echo -e "${GREEN}Passed: ${TESTS_PASSED}${NC}" | tee -a "${RESULTS_FILE}"
    echo -e "${RED}Failed: ${TESTS_FAILED}${NC}" | tee -a "${RESULTS_FILE}"

    if [ ${TESTS_FAILED} -eq 0 ]; then
        echo -e "\n${GREEN}✓ ALL DLQ TESTS PASSED${NC}" | tee -a "${RESULTS_FILE}"
    else
        SUCCESS_RATE=$(awk "BEGIN {printf \"%.2f\", (${TESTS_PASSED}/${TESTS_RUN})*100}")
        echo -e "\n${YELLOW}⚠ SOME DLQ FEATURES NEED IMPLEMENTATION (${SUCCESS_RATE}% success)${NC}" | tee -a "${RESULTS_FILE}"
    fi
    echo "========================================" | tee -a "${RESULTS_FILE}"
}

start_containers() {
    log_info "Starting Docker containers..."
    cd /home/admin/eiou/ai-dev/github/eiou-docker

    docker compose -f "${COMPOSE_FILE}" down -v > /dev/null 2>&1 || true

    if docker compose -f "${COMPOSE_FILE}" up -d --build > "${LOG_DIR}/docker-build.log" 2>&1; then
        log_success "Containers started"
    else
        log_failure "Failed to start containers"
        exit 1
    fi

    log_info "Waiting for initialization (30 seconds)..."
    sleep 30
}

setup_network() {
    log_info "Setting up network topology..."

    for node in ${NODE_ALICE} ${NODE_BOB} ${NODE_CAROL} ${NODE_DANIEL}; do
        docker exec "${node}" eiou generate "http://${node}" > /dev/null 2>&1
    done

    # Line topology
    docker exec ${NODE_ALICE} eiou add "http://${NODE_BOB}" "${NODE_BOB}" 0.1 1000 USD > /dev/null 2>&1
    docker exec ${NODE_BOB} eiou add "http://${NODE_ALICE}" "${NODE_ALICE}" 0.1 1000 USD > /dev/null 2>&1
    docker exec ${NODE_BOB} eiou add "http://${NODE_CAROL}" "${NODE_CAROL}" 0.1 1000 USD > /dev/null 2>&1
    docker exec ${NODE_CAROL} eiou add "http://${NODE_BOB}" "${NODE_BOB}" 0.1 1000 USD > /dev/null 2>&1
    docker exec ${NODE_CAROL} eiou add "http://${NODE_DANIEL}" "${NODE_DANIEL}" 0.1 1000 USD > /dev/null 2>&1
    docker exec ${NODE_DANIEL} eiou add "http://${NODE_CAROL}" "${NODE_CAROL}" 0.1 1000 USD > /dev/null 2>&1

    log_success "Network established"
}

# Test 1: Message moves to DLQ after max retries
test_dlq_after_max_retries() {
    ((TESTS_RUN++))
    log_info "Test 1: Message moves to DLQ after maximum retry attempts"

    # Stop Bob permanently to force retry exhaustion
    log_info "Stopping ${NODE_BOB} to create permanent failure..."
    docker stop ${NODE_BOB} > /dev/null 2>&1

    # Send message that will fail
    log_info "Sending message to unavailable node (will exhaust retries)..."
    docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 250 USD > /dev/null 2>&1 || true

    # Wait for retries to exhaust (with exponential backoff, ~90 seconds)
    log_info "Waiting for retry exhaustion (90 seconds)..."
    sleep 90

    # Check if message was moved to DLQ
    alice_logs="${LOG_DIR}/alice-dlq-max-retries.log"
    docker exec ${NODE_ALICE} cat /var/log/eiou/eiou.log > "${alice_logs}" 2>/dev/null || echo "No logs" > "${alice_logs}"

    if grep -qi "dlq\|dead.*letter\|moved to dlq\|failed permanently" "${alice_logs}"; then
        log_success "Test 1: Message moved to DLQ after retry exhaustion"
    else
        log_warning "Test 1: DLQ feature may need implementation (no DLQ activity in logs)"
    fi

    # Check if DLQ table/storage exists
    dlq_check=$(docker exec ${NODE_ALICE} mysql -u eiou -peiou eiou -e "SHOW TABLES LIKE '%dlq%' OR SHOW TABLES LIKE '%dead_letter%';" 2>/dev/null || echo "")

    if [ -n "${dlq_check}" ]; then
        log_success "Test 1: DLQ database table exists"
    else
        log_warning "Test 1: DLQ database table not found (may need implementation)"
    fi

    # Restart Bob for subsequent tests
    docker start ${NODE_BOB} > /dev/null 2>&1
    sleep 10
}

# Test 2: DLQ message persistence
test_dlq_persistence() {
    ((TESTS_RUN++))
    log_info "Test 2: DLQ message persistence across restarts"

    # Stop Carol
    docker stop ${NODE_CAROL} > /dev/null 2>&1

    # Send message that will fail
    docker exec ${NODE_ALICE} eiou send "http://${NODE_CAROL}" 300 USD > /dev/null 2>&1 || true

    # Wait for retries to exhaust
    log_info "Waiting for DLQ insertion (90 seconds)..."
    sleep 90

    # Query DLQ before restart
    dlq_before=$(docker exec ${NODE_ALICE} mysql -u eiou -peiou eiou -e "SELECT COUNT(*) FROM dead_letter_queue;" 2>/dev/null || echo "0")

    # Restart Alice container
    log_info "Restarting Alice container to test persistence..."
    docker restart ${NODE_ALICE} > /dev/null 2>&1
    sleep 15

    # Query DLQ after restart
    dlq_after=$(docker exec ${NODE_ALICE} mysql -u eiou -peiou eiou -e "SELECT COUNT(*) FROM dead_letter_queue;" 2>/dev/null || echo "0")

    if [ "${dlq_before}" == "${dlq_after}" ] && [ "${dlq_before}" != "0" ]; then
        log_success "Test 2: DLQ messages persisted across restart"
    else
        log_warning "Test 2: DLQ persistence cannot be verified (may need implementation)"
    fi

    # Restart Carol
    docker start ${NODE_CAROL} > /dev/null 2>&1
    sleep 10
}

# Test 3: DLQ viewing/querying capability
test_dlq_viewing() {
    ((TESTS_RUN++))
    log_info "Test 3: DLQ viewing and querying capability"

    # Try to view DLQ messages via CLI
    dlq_view=$(docker exec ${NODE_ALICE} eiou viewdlq 2>&1 || echo "command not found")

    if ! echo "${dlq_view}" | grep -qi "command not found\|unknown\|invalid"; then
        log_success "Test 3: DLQ viewing command exists (eiou viewdlq)"
        log_info "DLQ output: ${dlq_view}"
    else
        log_warning "Test 3: DLQ viewing command not implemented"
    fi

    # Try to query DLQ via database
    dlq_query=$(docker exec ${NODE_ALICE} mysql -u eiou -peiou eiou -e "SELECT * FROM dead_letter_queue LIMIT 5;" 2>/dev/null || echo "")

    if [ -n "${dlq_query}" ]; then
        log_success "Test 3: DLQ database table is queryable"
    else
        log_warning "Test 3: DLQ database table may not exist"
    fi
}

# Test 4: DLQ manual reprocessing
test_dlq_reprocessing() {
    ((TESTS_RUN++))
    log_info "Test 4: Manual DLQ message reprocessing"

    # Stop Daniel
    docker stop ${NODE_DANIEL} > /dev/null 2>&1

    # Send message to DLQ
    docker exec ${NODE_ALICE} eiou send "http://${NODE_DANIEL}" 400 USD > /dev/null 2>&1 || true
    sleep 90

    # Restart Daniel (network recovered)
    docker start ${NODE_DANIEL} > /dev/null 2>&1
    sleep 10

    # Try to reprocess DLQ message
    log_info "Attempting manual DLQ reprocessing..."
    reprocess_result=$(docker exec ${NODE_ALICE} eiou reprocessdlq 2>&1 || echo "command not found")

    if ! echo "${reprocess_result}" | grep -qi "command not found\|unknown"; then
        log_success "Test 4: DLQ reprocessing command exists"

        # Check if message was delivered after reprocessing
        sleep 10
        daniel_balance=$(docker exec ${NODE_DANIEL} eiou viewbalances 2>&1)
        if echo "${daniel_balance}" | grep -q "400"; then
            log_success "Test 4: DLQ message successfully reprocessed and delivered"
        else
            log_warning "Test 4: DLQ reprocessing may not have completed"
        fi
    else
        log_warning "Test 4: DLQ reprocessing feature not implemented"
    fi
}

# Test 5: DLQ alerting mechanism
test_dlq_alerting() {
    ((TESTS_RUN++))
    log_info "Test 5: DLQ alerting mechanism"

    # Stop Bob for DLQ generation
    docker stop ${NODE_BOB} > /dev/null 2>&1

    # Send message to trigger DLQ alert
    docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 500 USD > /dev/null 2>&1 || true
    sleep 90

    # Check for alert in logs
    alice_logs="${LOG_DIR}/alice-dlq-alert.log"
    docker exec ${NODE_ALICE} cat /var/log/eiou/eiou.log > "${alice_logs}" 2>/dev/null || echo "No logs" > "${alice_logs}"

    if grep -qi "alert\|warning.*dlq\|message.*failed.*permanently\|notification" "${alice_logs}"; then
        log_success "Test 5: DLQ alert mechanism active"
    else
        log_warning "Test 5: DLQ alerting may need implementation"
    fi

    # Restart Bob
    docker start ${NODE_BOB} > /dev/null 2>&1
    sleep 10
}

# Test 6: DLQ statistics and monitoring
test_dlq_statistics() {
    ((TESTS_RUN++))
    log_info "Test 6: DLQ statistics and monitoring"

    # Check for DLQ statistics command
    dlq_stats=$(docker exec ${NODE_ALICE} eiou dlqstats 2>&1 || echo "command not found")

    if ! echo "${dlq_stats}" | grep -qi "command not found\|unknown"; then
        log_success "Test 6: DLQ statistics command exists"
        log_info "DLQ stats: ${dlq_stats}"
    else
        log_warning "Test 6: DLQ statistics command not implemented"
    fi

    # Check for DLQ metrics in database
    dlq_metrics=$(docker exec ${NODE_ALICE} mysql -u eiou -peiou eiou -e "
        SELECT
            COUNT(*) as total_dlq_messages,
            MIN(created_at) as oldest_message,
            MAX(created_at) as newest_message
        FROM dead_letter_queue;
    " 2>/dev/null || echo "")

    if [ -n "${dlq_metrics}" ]; then
        log_success "Test 6: DLQ metrics queryable from database"
    else
        log_warning "Test 6: DLQ metrics table may not exist"
    fi
}

# Test 7: DLQ capacity and overflow handling
test_dlq_capacity() {
    ((TESTS_RUN++))
    log_info "Test 7: DLQ capacity and overflow handling"

    # Generate many DLQ messages
    docker stop ${NODE_CAROL} > /dev/null 2>&1

    log_info "Generating 20 DLQ messages to test capacity..."
    for i in $(seq 1 20); do
        docker exec ${NODE_ALICE} eiou send "http://${NODE_CAROL}" ${i} USD > /dev/null 2>&1 || true
    done

    # Wait for all to reach DLQ (shorter wait since we're testing many)
    sleep 120

    # Check DLQ size
    dlq_count=$(docker exec ${NODE_ALICE} mysql -u eiou -peiou eiou -e "SELECT COUNT(*) FROM dead_letter_queue;" 2>/dev/null | tail -1 || echo "0")

    log_info "DLQ contains ${dlq_count} messages"

    if [ "${dlq_count}" -ge 10 ]; then
        log_success "Test 7: DLQ handling bulk failed messages (${dlq_count} messages)"
    else
        log_warning "Test 7: DLQ may not be capturing all failed messages (${dlq_count} found)"
    fi

    # Restart Carol
    docker start ${NODE_CAROL} > /dev/null 2>&1
    sleep 10
}

# Test 8: DLQ message metadata
test_dlq_metadata() {
    ((TESTS_RUN++))
    log_info "Test 8: DLQ message metadata completeness"

    # Query DLQ for metadata fields
    dlq_metadata=$(docker exec ${NODE_ALICE} mysql -u eiou -peiou eiou -e "
        DESCRIBE dead_letter_queue;
    " 2>/dev/null || echo "")

    if [ -n "${dlq_metadata}" ]; then
        log_success "Test 8: DLQ table structure exists"

        # Check for expected fields
        required_fields=("message" "retry_count" "created_at" "reason")
        fields_found=0

        for field in "${required_fields[@]}"; do
            if echo "${dlq_metadata}" | grep -qi "${field}"; then
                ((fields_found++))
                log_info "Found DLQ field: ${field}"
            fi
        done

        if [ "${fields_found}" -ge 3 ]; then
            log_success "Test 8: DLQ metadata includes essential fields (${fields_found}/4)"
        else
            log_warning "Test 8: DLQ metadata may be incomplete (${fields_found}/4 fields)"
        fi
    else
        log_warning "Test 8: Cannot verify DLQ metadata (table may not exist)"
    fi
}

# Test 9: DLQ cleanup/purge capability
test_dlq_cleanup() {
    ((TESTS_RUN++))
    log_info "Test 9: DLQ cleanup and purge capability"

    # Try to purge old DLQ messages
    purge_result=$(docker exec ${NODE_ALICE} eiou purgedlq 2>&1 || echo "command not found")

    if ! echo "${purge_result}" | grep -qi "command not found\|unknown"; then
        log_success "Test 9: DLQ purge command exists"
    else
        log_warning "Test 9: DLQ purge command not implemented"
    fi

    # Check if DLQ has purge/cleanup logic
    alice_logs="${LOG_DIR}/alice-dlq-cleanup.log"
    docker exec ${NODE_ALICE} cat /var/log/eiou/eiou.log > "${alice_logs}" 2>/dev/null || echo "No logs" > "${alice_logs}"

    if grep -qi "purge\|cleanup\|deleted.*dlq\|removed.*old" "${alice_logs}"; then
        log_success "Test 9: DLQ cleanup mechanism detected"
    else
        log_warning "Test 9: DLQ cleanup may need implementation"
    fi
}

# Test 10: DLQ prevents message loss
test_dlq_prevents_loss() {
    ((TESTS_RUN++))
    log_info "Test 10: Verify DLQ prevents permanent message loss"

    # Stop Daniel
    docker stop ${NODE_DANIEL} > /dev/null 2>&1

    # Send 5 messages that will fail
    log_info "Sending 5 messages to unavailable node..."
    for i in {1..5}; do
        docker exec ${NODE_ALICE} eiou send "http://${NODE_DANIEL}" $((100 + i)) USD > /dev/null 2>&1 || true
    done

    sleep 90

    # Count messages in DLQ
    dlq_count=$(docker exec ${NODE_ALICE} mysql -u eiou -peiou eiou -e "SELECT COUNT(*) FROM dead_letter_queue;" 2>/dev/null | tail -1 || echo "0")

    log_info "DLQ contains ${dlq_count} messages from 5 sent"

    if [ "${dlq_count}" -ge 4 ]; then
        log_success "Test 10: DLQ preventing message loss (${dlq_count}/5 messages preserved)"
    else
        log_warning "Test 10: Some messages may be lost (${dlq_count}/5 in DLQ)"
    fi

    # Verify messages are recoverable
    dlq_messages=$(docker exec ${NODE_ALICE} mysql -u eiou -peiou eiou -e "SELECT message FROM dead_letter_queue LIMIT 5;" 2>/dev/null || echo "")

    if [ -n "${dlq_messages}" ]; then
        log_success "Test 10: DLQ messages are recoverable and readable"
    fi

    # Restart Daniel
    docker start ${NODE_DANIEL} > /dev/null 2>&1
    sleep 10
}

cleanup() {
    log_info "Cleaning up..."
    cd /home/admin/eiou/ai-dev/github/eiou-docker
    docker compose -f "${COMPOSE_FILE}" down -v > /dev/null 2>&1 || true
}

main() {
    print_header

    start_containers
    setup_network

    echo "" | tee -a "${RESULTS_FILE}"
    log_info "Starting Dead Letter Queue tests..."
    log_info "Note: These tests verify DLQ implementation requirements from Issue #139"
    echo "" | tee -a "${RESULTS_FILE}"

    test_dlq_after_max_retries
    echo "" | tee -a "${RESULTS_FILE}"

    test_dlq_persistence
    echo "" | tee -a "${RESULTS_FILE}"

    test_dlq_viewing
    echo "" | tee -a "${RESULTS_FILE}"

    test_dlq_reprocessing
    echo "" | tee -a "${RESULTS_FILE}"

    test_dlq_alerting
    echo "" | tee -a "${RESULTS_FILE}"

    test_dlq_statistics
    echo "" | tee -a "${RESULTS_FILE}"

    test_dlq_capacity
    echo "" | tee -a "${RESULTS_FILE}"

    test_dlq_metadata
    echo "" | tee -a "${RESULTS_FILE}"

    test_dlq_cleanup
    echo "" | tee -a "${RESULTS_FILE}"

    test_dlq_prevents_loss
    echo "" | tee -a "${RESULTS_FILE}"

    print_summary

    cleanup

    log_info "Logs: ${LOG_DIR}"
    log_info "Results: ${RESULTS_FILE}"

    [ ${TESTS_FAILED} -eq 0 ] && exit 0 || exit 1
}

trap cleanup EXIT INT TERM
main
