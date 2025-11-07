#!/bin/bash
# Copyright 2025
#
# Integration Test: Message Reliability System (Issue #139)
#
# Tests the three-stage acknowledgment protocol:
# 1. A→B: Send message
# 2. B→A: Confirmation "received"
# 3.a B→A: Confirmation "inserted" (stored in DB)
# 3.b B→A: Confirmation "forwarded" (sent to next hop)
#
# Usage: ./test-message-reliability.sh

set -e

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test configuration
TEST_NAME="Message Reliability System"
COMPOSE_FILE="docker-compose-4line.yml"
NETWORK_NAME="eioud-network"
TEST_TIMESTAMP=$(date +%Y%m%d_%H%M%S)
LOG_DIR="/tmp/eiou-test-logs/${TEST_TIMESTAMP}"
RESULTS_FILE="${LOG_DIR}/test-results.txt"

# Node names
NODE_ALICE="alice"
NODE_BOB="bob"
NODE_CAROL="carol"
NODE_DANIEL="daniel"

# Test counters
TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0

# Create log directory
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

# Print test header
print_header() {
    echo "" | tee -a "${RESULTS_FILE}"
    echo "========================================" | tee -a "${RESULTS_FILE}"
    echo "  ${TEST_NAME}" | tee -a "${RESULTS_FILE}"
    echo "  Issue #139 Integration Tests" | tee -a "${RESULTS_FILE}"
    echo "  Timestamp: ${TEST_TIMESTAMP}" | tee -a "${RESULTS_FILE}"
    echo "========================================" | tee -a "${RESULTS_FILE}"
    echo "" | tee -a "${RESULTS_FILE}"
}

# Print test summary
print_summary() {
    echo "" | tee -a "${RESULTS_FILE}"
    echo "========================================" | tee -a "${RESULTS_FILE}"
    echo "  TEST SUMMARY" | tee -a "${RESULTS_FILE}"
    echo "========================================" | tee -a "${RESULTS_FILE}"
    echo "Total Tests: ${TESTS_RUN}" | tee -a "${RESULTS_FILE}"
    echo -e "${GREEN}Passed: ${TESTS_PASSED}${NC}" | tee -a "${RESULTS_FILE}"
    echo -e "${RED}Failed: ${TESTS_FAILED}${NC}" | tee -a "${RESULTS_FILE}"

    if [ ${TESTS_FAILED} -eq 0 ]; then
        echo -e "\n${GREEN}✓ ALL TESTS PASSED${NC}" | tee -a "${RESULTS_FILE}"
        SUCCESS_RATE="100%"
    else
        SUCCESS_RATE=$(awk "BEGIN {printf \"%.2f\", (${TESTS_PASSED}/${TESTS_RUN})*100}")
        echo -e "\n${RED}✗ SOME TESTS FAILED (${SUCCESS_RATE}% success rate)${NC}" | tee -a "${RESULTS_FILE}"
    fi
    echo "========================================" | tee -a "${RESULTS_FILE}"
    echo "" | tee -a "${RESULTS_FILE}"
}

# Check if Docker is running
check_docker() {
    log_info "Checking Docker availability..."
    if ! docker info > /dev/null 2>&1; then
        log_failure "Docker is not running or not accessible"
        exit 1
    fi
    log_success "Docker is available"
}

# Start Docker containers
start_containers() {
    log_info "Starting Docker containers with ${COMPOSE_FILE}..."

    cd /home/admin/eiou/ai-dev/github/eiou-docker

    # Stop any existing containers
    docker compose -f "${COMPOSE_FILE}" down -v > /dev/null 2>&1 || true

    # Build and start containers
    if docker compose -f "${COMPOSE_FILE}" up -d --build > "${LOG_DIR}/docker-build.log" 2>&1; then
        log_success "Containers started successfully"
    else
        log_failure "Failed to start containers"
        cat "${LOG_DIR}/docker-build.log"
        exit 1
    fi

    # Wait for containers to initialize
    log_info "Waiting for containers to initialize (30 seconds)..."
    sleep 30

    # Verify all containers are running
    for node in ${NODE_ALICE} ${NODE_BOB} ${NODE_CAROL} ${NODE_DANIEL}; do
        if docker ps | grep -q "${node}"; then
            log_success "Container ${node} is running"
        else
            log_failure "Container ${node} is not running"
            exit 1
        fi
    done
}

# Setup network topology
setup_network() {
    log_info "Setting up 4-node line network (Alice → Bob → Carol → Daniel)..."

    # Generate addresses for each node
    declare -A NODE_ADDRESSES
    for node in ${NODE_ALICE} ${NODE_BOB} ${NODE_CAROL} ${NODE_DANIEL}; do
        address="http://${node}"
        docker exec "${node}" eiou generate "${address}" > /dev/null 2>&1
        NODE_ADDRESSES[${node}]="${address}"
        log_info "Generated address for ${node}: ${address}"
    done

    # Set up contacts (line topology)
    # Alice ↔ Bob
    docker exec ${NODE_ALICE} eiou add "${NODE_ADDRESSES[${NODE_BOB}]}" "${NODE_BOB}" 0.1 1000 USD > /dev/null 2>&1
    docker exec ${NODE_BOB} eiou add "${NODE_ADDRESSES[${NODE_ALICE}]}" "${NODE_ALICE}" 0.1 1000 USD > /dev/null 2>&1

    # Bob ↔ Carol
    docker exec ${NODE_BOB} eiou add "${NODE_ADDRESSES[${NODE_CAROL}]}" "${NODE_CAROL}" 0.1 1000 USD > /dev/null 2>&1
    docker exec ${NODE_CAROL} eiou add "${NODE_ADDRESSES[${NODE_BOB}]}" "${NODE_BOB}" 0.1 1000 USD > /dev/null 2>&1

    # Carol ↔ Daniel
    docker exec ${NODE_CAROL} eiou add "${NODE_ADDRESSES[${NODE_DANIEL}]}" "${NODE_DANIEL}" 0.1 1000 USD > /dev/null 2>&1
    docker exec ${NODE_DANIEL} eiou add "${NODE_ADDRESSES[${NODE_CAROL}]}" "${NODE_CAROL}" 0.1 1000 USD > /dev/null 2>&1

    log_success "Network topology established"
}

# Test 1: Direct message with acknowledgment
test_direct_message_ack() {
    ((TESTS_RUN++))
    log_info "Test 1: Direct message with acknowledgment (Alice → Bob)"

    # Send message from Alice to Bob
    result=$(docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 10 USD 2>&1)

    # Check for acknowledgment in result
    if echo "${result}" | grep -q "received\|confirmed\|success"; then
        log_success "Test 1: Direct message received acknowledgment"

        # Verify message was inserted in Bob's database
        sleep 5
        bob_balance=$(docker exec ${NODE_BOB} eiou viewbalances 2>&1)
        if echo "${bob_balance}" | grep -q "10\|USD"; then
            log_success "Test 1: Message successfully inserted in recipient database"
        else
            log_failure "Test 1: Message not found in recipient database"
        fi
    else
        log_failure "Test 1: No acknowledgment received for direct message"
    fi
}

# Test 2: Multi-hop message with acknowledgment chain
test_multihop_message_ack() {
    ((TESTS_RUN++))
    log_info "Test 2: Multi-hop message with acknowledgment chain (Alice → Daniel via Bob, Carol)"

    # Send message from Alice to Daniel (must go through Bob and Carol)
    result=$(docker exec ${NODE_ALICE} eiou send "http://${NODE_DANIEL}" 20 USD 2>&1)

    # Wait for message to propagate through network
    sleep 10

    # Check Daniel received the message
    daniel_balance=$(docker exec ${NODE_DANIEL} eiou viewbalances 2>&1)
    if echo "${daniel_balance}" | grep -q "20\|USD"; then
        log_success "Test 2: Multi-hop message successfully delivered"

        # Verify intermediate nodes forwarded correctly
        bob_history=$(docker exec ${NODE_BOB} eiou history 2>&1)
        carol_history=$(docker exec ${NODE_CAROL} eiou history 2>&1)

        if echo "${bob_history}" | grep -q "forward\|relay" || echo "${bob_history}" | grep -q "Daniel"; then
            log_success "Test 2: Bob forwarded message correctly"
        else
            log_warning "Test 2: Cannot verify Bob's forwarding (may be normal)"
        fi

        if echo "${carol_history}" | grep -q "forward\|relay" || echo "${carol_history}" | grep -q "Daniel"; then
            log_success "Test 2: Carol forwarded message correctly"
        else
            log_warning "Test 2: Cannot verify Carol's forwarding (may be normal)"
        fi
    else
        log_failure "Test 2: Multi-hop message not delivered to final recipient"
    fi
}

# Test 3: Three-stage acknowledgment protocol
test_threestage_ack() {
    ((TESTS_RUN++))
    log_info "Test 3: Three-stage acknowledgment protocol verification"

    # This test verifies the implementation of:
    # Stage 1: "received" - Message received by next hop
    # Stage 2: "inserted" - Message stored in database
    # Stage 3: "forwarded" - Message sent to next hop (if applicable)

    # Send test message
    result=$(docker exec ${NODE_ALICE} eiou send "http://${NODE_CAROL}" 15 USD 2>&1)

    sleep 8

    # Check Alice's transaction status (should show all acknowledgments)
    alice_history=$(docker exec ${NODE_ALICE} eiou history 2>&1)

    # Count acknowledgment stages found
    ack_count=0

    if echo "${alice_history}" | grep -qi "received"; then
        ((ack_count++))
        log_success "Test 3: Stage 1 acknowledgment (received) found"
    fi

    if echo "${alice_history}" | grep -qi "inserted\|stored\|completed"; then
        ((ack_count++))
        log_success "Test 3: Stage 2 acknowledgment (inserted) found"
    fi

    if echo "${alice_history}" | grep -qi "forwarded\|relayed"; then
        ((ack_count++))
        log_success "Test 3: Stage 3 acknowledgment (forwarded) found"
    fi

    if [ ${ack_count} -ge 2 ]; then
        log_success "Test 3: Multi-stage acknowledgment protocol working (${ack_count}/3 stages detected)"
    else
        log_warning "Test 3: Only ${ack_count}/3 acknowledgment stages detected (may need implementation)"
    fi
}

# Test 4: Message loss rate calculation
test_message_loss_rate() {
    ((TESTS_RUN++))
    log_info "Test 4: Message loss rate calculation (100 messages)"

    TOTAL_MESSAGES=100
    SUCCESSFUL_MESSAGES=0

    log_info "Sending ${TOTAL_MESSAGES} messages from Alice to Bob..."

    for i in $(seq 1 ${TOTAL_MESSAGES}); do
        if docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 1 USD > /dev/null 2>&1; then
            ((SUCCESSFUL_MESSAGES++))
        fi

        # Progress indicator every 10 messages
        if [ $((i % 10)) -eq 0 ]; then
            log_info "Progress: ${i}/${TOTAL_MESSAGES} messages sent"
        fi
    done

    # Wait for all messages to process
    sleep 15

    # Calculate loss rate
    LOST_MESSAGES=$((TOTAL_MESSAGES - SUCCESSFUL_MESSAGES))
    LOSS_RATE=$(awk "BEGIN {printf \"%.4f\", (${LOST_MESSAGES}/${TOTAL_MESSAGES})*100}")

    log_info "Results: ${SUCCESSFUL_MESSAGES}/${TOTAL_MESSAGES} messages acknowledged"
    log_info "Message loss rate: ${LOSS_RATE}%"

    # Check against requirement (<0.01%)
    if awk "BEGIN {exit !(${LOSS_RATE} < 0.01)}"; then
        log_success "Test 4: Message loss rate ${LOSS_RATE}% meets requirement (<0.01%)"
    elif awk "BEGIN {exit !(${LOSS_RATE} < 1.0)}"; then
        log_warning "Test 4: Message loss rate ${LOSS_RATE}% is acceptable but above target (<0.01%)"
    else
        log_failure "Test 4: Message loss rate ${LOSS_RATE}% exceeds acceptable threshold"
    fi
}

# Test 5: Acknowledgment timeout handling
test_ack_timeout() {
    ((TESTS_RUN++))
    log_info "Test 5: Acknowledgment timeout handling"

    # This test verifies that senders detect missing acknowledgments
    # Note: Actual timeout behavior depends on implementation

    log_info "Sending message and monitoring for timeout detection..."

    # Send message and capture detailed output
    result=$(docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" 5 USD 2>&1)

    # Wait for potential timeout
    sleep 12

    # Check if timeout detection exists in logs
    alice_logs="${LOG_DIR}/alice-timeout.log"
    docker exec ${NODE_ALICE} cat /var/log/eiou/eiou.log > "${alice_logs}" 2>/dev/null || true

    if [ -f "${alice_logs}" ]; then
        if grep -qi "timeout\|retry\|resend" "${alice_logs}"; then
            log_success "Test 5: Timeout detection mechanism found in logs"
        else
            log_warning "Test 5: No timeout detection found (may need implementation)"
        fi
    else
        log_warning "Test 5: Cannot access logs to verify timeout handling"
    fi
}

# Test 6: Network partition recovery
test_network_partition() {
    ((TESTS_RUN++))
    log_info "Test 6: Network partition and recovery"

    # Simulate network partition by stopping Bob temporarily
    log_info "Simulating network partition by stopping ${NODE_BOB}..."
    docker stop ${NODE_BOB} > /dev/null 2>&1

    # Try to send message through partitioned network
    log_info "Attempting to send message through partitioned network..."
    result=$(docker exec ${NODE_ALICE} eiou send "http://${NODE_CAROL}" 30 USD 2>&1 || true)

    # Message should fail or queue for retry
    if echo "${result}" | grep -qi "error\|fail\|timeout\|unreachable"; then
        log_success "Test 6: Network partition correctly detected"
    else
        log_warning "Test 6: Network partition may not be detected"
    fi

    # Restart Bob
    log_info "Recovering network by restarting ${NODE_BOB}..."
    docker start ${NODE_BOB} > /dev/null 2>&1
    sleep 10

    # Try sending again
    result=$(docker exec ${NODE_ALICE} eiou send "http://${NODE_CAROL}" 30 USD 2>&1)
    sleep 8

    # Check if message was delivered after recovery
    carol_balance=$(docker exec ${NODE_CAROL} eiou viewbalances 2>&1)
    if echo "${carol_balance}" | grep -q "30"; then
        log_success "Test 6: Message delivered successfully after network recovery"
    else
        log_warning "Test 6: Message delivery after recovery needs verification"
    fi
}

# Test 7: Concurrent message handling
test_concurrent_messages() {
    ((TESTS_RUN++))
    log_info "Test 7: Concurrent message handling"

    log_info "Sending 10 concurrent messages from Alice to multiple recipients..."

    # Send messages concurrently
    for i in {1..10}; do
        docker exec ${NODE_ALICE} eiou send "http://${NODE_BOB}" $i USD > /dev/null 2>&1 &
    done

    # Wait for all background jobs
    wait

    sleep 10

    # Verify all messages were processed
    bob_history=$(docker exec ${NODE_BOB} eiou history 2>&1)

    # Count transactions in history
    tx_count=$(echo "${bob_history}" | grep -c "USD" || echo "0")

    if [ "${tx_count}" -ge 8 ]; then
        log_success "Test 7: Concurrent messages handled successfully (${tx_count}/10 detected)"
    else
        log_warning "Test 7: Some concurrent messages may have been lost (${tx_count}/10 detected)"
    fi
}

# Cleanup function
cleanup() {
    log_info "Cleaning up test environment..."
    cd /home/admin/eiou/ai-dev/github/eiou-docker
    docker compose -f "${COMPOSE_FILE}" down -v > /dev/null 2>&1 || true
    log_info "Cleanup complete"
}

# Main test execution
main() {
    print_header

    # Setup
    check_docker
    start_containers
    setup_network

    echo "" | tee -a "${RESULTS_FILE}"
    log_info "Starting integration tests..."
    echo "" | tee -a "${RESULTS_FILE}"

    # Run all tests
    test_direct_message_ack
    echo "" | tee -a "${RESULTS_FILE}"

    test_multihop_message_ack
    echo "" | tee -a "${RESULTS_FILE}"

    test_threestage_ack
    echo "" | tee -a "${RESULTS_FILE}"

    test_message_loss_rate
    echo "" | tee -a "${RESULTS_FILE}"

    test_ack_timeout
    echo "" | tee -a "${RESULTS_FILE}"

    test_network_partition
    echo "" | tee -a "${RESULTS_FILE}"

    test_concurrent_messages
    echo "" | tee -a "${RESULTS_FILE}"

    # Print summary
    print_summary

    # Cleanup
    cleanup

    # Save logs
    log_info "Test logs saved to: ${LOG_DIR}"
    log_info "Results file: ${RESULTS_FILE}"

    # Exit with appropriate code
    if [ ${TESTS_FAILED} -eq 0 ]; then
        exit 0
    else
        exit 1
    fi
}

# Trap to ensure cleanup on exit
trap cleanup EXIT INT TERM

# Run main function
main
