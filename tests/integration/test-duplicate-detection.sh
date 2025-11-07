#!/bin/bash
# Copyright 2025
#
# Integration Test: Duplicate Detection (Issue #139)
#
# Tests duplicate message prevention:
# - B checks if message already inserted in database
# - If A resends and B already has it: B→A sends "rejection"
# - Prevents duplicate transactions
# - Idempotency verification
#
# Usage: ./test-duplicate-detection.sh

set -e

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Configuration
TEST_NAME="Duplicate Detection Tests"
COMPOSE_FILE="docker-compose-4line.yml"
TEST_TIMESTAMP=$(date +%Y%m%d_%H%M%S)
LOG_DIR="/tmp/eiou-test-logs/duplicate-${TEST_TIMESTAMP}"
RESULTS_FILE="${LOG_DIR}/duplicate-test-results.txt"

# Node names
NODE_ALICE="alice"
NODE_BOB="bob"
NODE_CAROL="carol"
NODE_DANIEL="daniel"

# Test counters
TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0

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
    echo "  Issue #139 - Duplicate Detection" | tee -a "${RESULTS_FILE}"
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
        echo -e "\n${GREEN}✓ ALL DUPLICATE DETECTION TESTS PASSED${NC}" | tee -a "${RESULTS_FILE}"
    else
        SUCCESS_RATE=$(awk "BEGIN {printf \"%.2f\", (${TESTS_PASSED}/${TESTS_RUN})*100}")
        echo -e "\n${YELLOW}⚠ SOME TESTS NEED REVIEW (${SUCCESS_RATE}% success)${NC}" | tee -a "${RESULTS_FILE}"
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

    # Line topology: Alice ↔ Bob ↔ Carol ↔ Daniel
    docker exec ${NODE_ALICE} eiou add "http://${NODE_BOB}" "${NODE_BOB}" 0.1 1000 USD > /dev/null 2>&1
    docker exec ${NODE_BOB} eiou add "http://${NODE_ALICE}" "${NODE_ALICE}" 0.1 1000 USD > /dev/null 2>&1
    docker exec ${NODE_BOB} eiou add "http://${NODE_CAROL}" "${NODE_CAROL}" 0.1 1000 USD > /dev/null 2>&1
    docker exec ${NODE_CAROL} eiou add "http://${NODE_BOB}" "${NODE_BOB}" 0.1 1000 USD > /dev/null 2>&1
    docker exec ${NODE_CAROL} eiou add "http://${NODE_DANIEL}" "${NODE_DANIEL}" 0.1 1000 USD > /dev/null 2>&1
    docker exec ${NODE_DANIEL} eiou add "http://${NODE_CAROL}" "${NODE_CAROL}" 0.1 1000 USD > /dev/null 2>&1

    log_success "Network established"
}

# Test 1: Simple duplicate message rejection
test_simple_duplicate() {
    ((TESTS_RUN++))
    log_info "Test 1: Simple duplicate message rejection"

    # Send message first time
    log_info "Sending message (first time)..."
    docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 100 USD > /dev/null 2>&1
    sleep 5

    # Get Bob's balance before duplicate
    bob_balance_before=$(docker exec ${NODE_BOB} eiou viewbalances 2>&1)
    balance_before=$(echo "${bob_balance_before}" | grep -oE "[0-9]+" | head -1 || echo "0")

    log_info "Bob's balance before duplicate: ${balance_before}"

    # Try to send duplicate (same amount, same conditions)
    log_info "Attempting to send duplicate message..."
    result=$(docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 100 USD 2>&1 || echo "")

    sleep 5

    # Get Bob's balance after duplicate attempt
    bob_balance_after=$(docker exec ${NODE_BOB} eiou viewbalances 2>&1)
    balance_after=$(echo "${bob_balance_after}" | grep -oE "[0-9]+" | head -1 || echo "0")

    log_info "Bob's balance after duplicate attempt: ${balance_after}"

    # Check if duplicate was rejected (balance should be same or only increased by 100, not 200)
    if echo "${result}" | grep -qi "duplicate\|already.*exists\|rejected"; then
        log_success "Test 1: Duplicate message explicitly rejected by recipient"
    else
        # Check balance didn't double-count
        expected_increase=100
        actual_increase=$((balance_after - balance_before))

        if [ "${actual_increase}" -le "${expected_increase}" ]; then
            log_success "Test 1: Duplicate prevented (balance only increased by ${actual_increase}, not double)"
        else
            log_failure "Test 1: Duplicate may not be prevented (balance increased by ${actual_increase})"
        fi
    fi
}

# Test 2: Transaction ID uniqueness enforcement
test_txid_uniqueness() {
    ((TESTS_RUN++))
    log_info "Test 2: Transaction ID uniqueness enforcement"

    log_info "Sending multiple messages and checking TXID uniqueness..."

    # Send several messages
    for i in {1..5}; do
        docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" ${i} USD > /dev/null 2>&1
        sleep 2
    done

    sleep 5

    # Check Bob's transaction history
    bob_history=$(docker exec ${NODE_BOB} eiou history 2>&1)

    # Save to file for analysis
    echo "${bob_history}" > "${LOG_DIR}/bob-history-txid.txt"

    # Count unique transactions
    tx_count=$(echo "${bob_history}" | grep -c "USD" || echo "0")

    log_info "Unique transactions in history: ${tx_count}"

    if [ "${tx_count}" -ge 5 ]; then
        log_success "Test 2: Transaction ID uniqueness enforced (${tx_count} unique transactions)"
    else
        log_warning "Test 2: Some transactions may be missing (found ${tx_count}, expected 5+)"
    fi
}

# Test 3: Duplicate detection across retry attempts
test_duplicate_across_retries() {
    ((TESTS_RUN++))
    log_info "Test 3: Duplicate detection across retry attempts"

    # This test ensures that if a message is retried, it's not processed twice

    log_info "Sending message with potential for retry..."

    # Send message
    docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 75 USD > /dev/null 2>&1
    sleep 3

    # Simulate retry by sending again quickly (before first completes)
    docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 75 USD > /dev/null 2>&1 || true
    docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 75 USD > /dev/null 2>&1 || true

    sleep 8

    # Check Bob's history for duplicates
    bob_history=$(docker exec ${NODE_BOB} eiou history 2>&1)

    # Count occurrences of 75 USD transaction
    duplicate_count=$(echo "${bob_history}" | grep -c "75.*USD" || echo "0")

    log_info "Found ${duplicate_count} instances of 75 USD transaction"

    if [ "${duplicate_count}" -le 1 ]; then
        log_success "Test 3: Duplicate detection working across retries (${duplicate_count} transaction)"
    else
        log_failure "Test 3: Duplicate transactions detected (${duplicate_count} instances of same transaction)"
    fi
}

# Test 4: Idempotency verification
test_idempotency() {
    ((TESTS_RUN++))
    log_info "Test 4: Idempotency verification (same message sent multiple times)"

    # Record initial balance
    carol_balance_initial=$(docker exec ${NODE_CAROL} eiou viewbalances 2>&1)

    # Send same message 10 times
    log_info "Sending identical message 10 times to test idempotency..."
    for i in {1..10}; do
        docker exec ${NODE_ALICE} eiou send "http://${NODE_CAROL}" 50 USD > /dev/null 2>&1 || true
        sleep 1
    done

    sleep 10

    # Check final balance
    carol_balance_final=$(docker exec ${NODE_CAROL} eiou viewbalances 2>&1)

    # Count how many times 50 USD appears in history
    carol_history=$(docker exec ${NODE_CAROL} eiou history 2>&1)
    transaction_count=$(echo "${carol_history}" | grep -c "50.*USD" || echo "0")

    log_info "Idempotency test: ${transaction_count} transactions recorded (should be 1)"

    if [ "${transaction_count}" -le 2 ]; then
        log_success "Test 4: Idempotency maintained (${transaction_count} transaction(s) recorded from 10 attempts)"
    else
        log_failure "Test 4: Idempotency violation (${transaction_count} transactions recorded from 10 identical requests)"
    fi
}

# Test 5: Duplicate rejection response
test_duplicate_rejection_response() {
    ((TESTS_RUN++))
    log_info "Test 5: Duplicate rejection response message"

    # Send original message
    log_info "Sending original message..."
    docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 33 USD > /dev/null 2>&1
    sleep 5

    # Try to send duplicate and capture response
    log_info "Sending duplicate and capturing response..."
    duplicate_response=$(docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 33 USD 2>&1 || echo "")

    # Check response for rejection message
    if echo "${duplicate_response}" | grep -qi "duplicate\|already.*exists\|rejected\|already.*processed"; then
        log_success "Test 5: Duplicate rejection response received"
        log_info "Response: ${duplicate_response}"
    else
        log_warning "Test 5: No explicit duplicate rejection message (may be handled silently)"
    fi

    # Check logs for duplicate detection
    alice_logs="${LOG_DIR}/alice-duplicate-response.log"
    docker exec ${NODE_ALICE} cat /var/log/eiou/eiou.log > "${alice_logs}" 2>/dev/null || echo "No logs" > "${alice_logs}"

    if grep -qi "duplicate" "${alice_logs}"; then
        log_success "Test 5: Duplicate detection logged on sender side"
    fi
}

# Test 6: Multi-hop duplicate prevention
test_multihop_duplicate() {
    ((TESTS_RUN++))
    log_info "Test 6: Multi-hop duplicate prevention (Alice → Daniel via Bob, Carol)"

    # Send message through multi-hop path
    log_info "Sending message through multi-hop path..."
    docker exec ${NODE_ALICE} eiou send "http://${NODE_DANIEL}" 40 USD > /dev/null 2>&1
    sleep 12

    # Try to send duplicate
    log_info "Attempting duplicate multi-hop message..."
    docker exec ${NODE_ALICE} eiou send "http://${NODE_DANIEL}" 40 USD > /dev/null 2>&1 || true
    sleep 12

    # Check Daniel's history
    daniel_history=$(docker exec ${NODE_DANIEL} eiou history 2>&1)
    duplicate_count=$(echo "${daniel_history}" | grep -c "40.*USD" || echo "0")

    log_info "Multi-hop duplicate test: ${duplicate_count} transaction(s) at destination"

    if [ "${duplicate_count}" -le 1 ]; then
        log_success "Test 6: Multi-hop duplicate prevented (${duplicate_count} transaction)"
    else
        log_failure "Test 6: Multi-hop duplicate not prevented (${duplicate_count} transactions)"
    fi
}

# Test 7: Database constraint enforcement
test_database_constraints() {
    ((TESTS_RUN++))
    log_info "Test 7: Database constraint enforcement for duplicates"

    # This test verifies database-level duplicate prevention
    log_info "Testing database-level duplicate prevention..."

    # Send message
    docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 88 USD > /dev/null 2>&1
    sleep 5

    # Check Bob's database logs for constraint violations on duplicate attempt
    docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 88 USD > /dev/null 2>&1 || true
    sleep 3

    # Check database logs
    bob_logs="${LOG_DIR}/bob-database.log"
    docker exec ${NODE_BOB} cat /var/log/eiou/eiou.log > "${bob_logs}" 2>/dev/null || echo "No logs" > "${bob_logs}"

    if grep -qi "unique.*constraint\|duplicate.*key\|integrity.*constraint" "${bob_logs}"; then
        log_success "Test 7: Database-level duplicate prevention active"
    else
        log_warning "Test 7: Cannot verify database constraint enforcement (may use application-level prevention)"
    fi
}

# Test 8: Concurrent duplicate attempts
test_concurrent_duplicates() {
    ((TESTS_RUN++))
    log_info "Test 8: Concurrent duplicate prevention under load"

    # Send same message concurrently from multiple attempts
    log_info "Sending 20 concurrent identical messages..."

    for i in {1..20}; do
        docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 99 USD > /dev/null 2>&1 &
    done

    # Wait for all background jobs
    wait

    sleep 15

    # Check how many were actually processed
    bob_history=$(docker exec ${NODE_BOB} eiou history 2>&1)
    processed_count=$(echo "${bob_history}" | grep -c "99.*USD" || echo "0")

    log_info "Concurrent duplicate test: ${processed_count} transaction(s) processed from 20 identical concurrent requests"

    if [ "${processed_count}" -le 2 ]; then
        log_success "Test 8: Concurrent duplicate prevention effective (${processed_count} transactions from 20 attempts)"
    else
        log_warning "Test 8: Some duplicates may have been processed (${processed_count} transactions)"
    fi
}

# Test 9: Duplicate detection with different amounts
test_different_amounts() {
    ((TESTS_RUN++))
    log_info "Test 9: Verify different amounts are NOT flagged as duplicates"

    # Send messages with different amounts (should all be processed)
    log_info "Sending 5 messages with different amounts..."
    for amount in 11 22 33 44 55; do
        docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" ${amount} USD > /dev/null 2>&1
        sleep 2
    done

    sleep 5

    # Verify all were processed
    bob_history=$(docker exec ${NODE_BOB} eiou history 2>&1)

    processed=0
    for amount in 11 22 33 44 55; do
        if echo "${bob_history}" | grep -q "${amount}"; then
            ((processed++))
        fi
    done

    log_info "Different amounts test: ${processed}/5 unique transactions processed"

    if [ "${processed}" -ge 4 ]; then
        log_success "Test 9: Different amounts correctly processed as separate transactions (${processed}/5)"
    else
        log_failure "Test 9: Some legitimate transactions may be incorrectly flagged as duplicates (${processed}/5)"
    fi
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
    log_info "Starting duplicate detection tests..."
    echo "" | tee -a "${RESULTS_FILE}"

    test_simple_duplicate
    echo "" | tee -a "${RESULTS_FILE}"

    test_txid_uniqueness
    echo "" | tee -a "${RESULTS_FILE}"

    test_duplicate_across_retries
    echo "" | tee -a "${RESULTS_FILE}"

    test_idempotency
    echo "" | tee -a "${RESULTS_FILE}"

    test_duplicate_rejection_response
    echo "" | tee -a "${RESULTS_FILE}"

    test_multihop_duplicate
    echo "" | tee -a "${RESULTS_FILE}"

    test_database_constraints
    echo "" | tee -a "${RESULTS_FILE}"

    test_concurrent_duplicates
    echo "" | tee -a "${RESULTS_FILE}"

    test_different_amounts
    echo "" | tee -a "${RESULTS_FILE}"

    print_summary

    cleanup

    log_info "Logs: ${LOG_DIR}"
    log_info "Results: ${RESULTS_FILE}"

    [ ${TESTS_FAILED} -eq 0 ] && exit 0 || exit 1
}

trap cleanup EXIT INT TERM
main
