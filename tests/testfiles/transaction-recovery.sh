#!/bin/bash
# Copyright 2025 Adrien Hubert (adrien@eiou.org)
#
# Transaction Recovery Test Suite
# Tests the atomic transaction claiming and recovery mechanisms
#
# This script runs two types of tests:
# Part 1: Static checks (file existence, method definitions)
# Part 2: Functional tests (actual database operations, crash simulation)
#
# Test scenarios covered:
# - Atomic claiming prevents duplicate processing
# - Crash recovery resets stuck transactions
# - Max retry exceeded marks for manual review
# - Concurrent claim attempts are rejected
# - Full transaction flow: pending → sending → sent

# Initialize test counters
totaltests=0
passed=0
failure=0

printf "Starting Transaction Recovery Tests...\n"
printf "\n================================================================\n"
printf "  PART 1: Static Verification Tests\n"
printf "================================================================\n"

# Test 1: Verify SENDING status exists in Constants
printf "\n[Test 1] Checking SENDING status in Constants.php...\n"
totaltests=$((totaltests + 1))
if grep -q "STATUS_SENDING = 'sending'" /etc/eiou/src/core/Constants.php 2>/dev/null; then
    printf "${GREEN}${TICK} SENDING status constant exists${NC}\n"
    passed=$((passed + 1))
else
    printf "${RED}${CROSS} SENDING status constant not found${NC}\n"
    failure=$((failure + 1))
fi

# Test 2: Verify recovery constants exist
printf "\n[Test 2] Checking recovery configuration constants...\n"
totaltests=$((totaltests + 1))
if grep -q "RECOVERY_SENDING_TIMEOUT_SECONDS" /etc/eiou/src/core/Constants.php 2>/dev/null && \
   grep -q "RECOVERY_MAX_RETRY_COUNT" /etc/eiou/src/core/Constants.php 2>/dev/null; then
    printf "${GREEN}${TICK} Recovery configuration constants exist${NC}\n"
    passed=$((passed + 1))
else
    printf "${RED}${CROSS} Recovery configuration constants not found${NC}\n"
    failure=$((failure + 1))
fi

# Test 3: Verify TransactionRecoveryService exists
printf "\n[Test 3] Checking TransactionRecoveryService exists...\n"
totaltests=$((totaltests + 1))
if [ -f "/etc/eiou/src/services/TransactionRecoveryService.php" ]; then
    printf "${GREEN}${TICK} TransactionRecoveryService.php exists${NC}\n"
    passed=$((passed + 1))
else
    printf "${RED}${CROSS} TransactionRecoveryService.php not found${NC}\n"
    failure=$((failure + 1))
fi

# Test 4: Verify claimPendingTransaction method exists
printf "\n[Test 4] Checking claimPendingTransaction method...\n"
totaltests=$((totaltests + 1))
if grep -q "function claimPendingTransaction" /etc/eiou/src/database/TransactionRepository.php 2>/dev/null; then
    printf "${GREEN}${TICK} claimPendingTransaction method exists${NC}\n"
    passed=$((passed + 1))
else
    printf "${RED}${CROSS} claimPendingTransaction method not found${NC}\n"
    failure=$((failure + 1))
fi

# Test 5: Verify getStuckSendingTransactions method exists
printf "\n[Test 5] Checking getStuckSendingTransactions method...\n"
totaltests=$((totaltests + 1))
if grep -q "function getStuckSendingTransactions" /etc/eiou/src/database/TransactionRepository.php 2>/dev/null; then
    printf "${GREEN}${TICK} getStuckSendingTransactions method exists${NC}\n"
    passed=$((passed + 1))
else
    printf "${RED}${CROSS} getStuckSendingTransactions method not found${NC}\n"
    failure=$((failure + 1))
fi

# Test 6: Verify recoverStuckTransaction method exists
printf "\n[Test 6] Checking recoverStuckTransaction method...\n"
totaltests=$((totaltests + 1))
if grep -q "function recoverStuckTransaction" /etc/eiou/src/database/TransactionRepository.php 2>/dev/null; then
    printf "${GREEN}${TICK} recoverStuckTransaction method exists${NC}\n"
    passed=$((passed + 1))
else
    printf "${RED}${CROSS} recoverStuckTransaction method not found${NC}\n"
    failure=$((failure + 1))
fi

# Test 7: Verify markAsSent method exists
printf "\n[Test 7] Checking markAsSent method...\n"
totaltests=$((totaltests + 1))
if grep -q "function markAsSent" /etc/eiou/src/database/TransactionRepository.php 2>/dev/null; then
    printf "${GREEN}${TICK} markAsSent method exists${NC}\n"
    passed=$((passed + 1))
else
    printf "${RED}${CROSS} markAsSent method not found${NC}\n"
    failure=$((failure + 1))
fi

# Test 8: Verify database schema includes new columns
printf "\n[Test 8] Checking database schema for new columns...\n"
totaltests=$((totaltests + 1))
if grep -q "sending_started_at" /etc/eiou/src/database/DatabaseSchema.php 2>/dev/null && \
   grep -q "recovery_count" /etc/eiou/src/database/DatabaseSchema.php 2>/dev/null && \
   grep -q "needs_manual_review" /etc/eiou/src/database/DatabaseSchema.php 2>/dev/null; then
    printf "${GREEN}${TICK} New recovery columns in schema${NC}\n"
    passed=$((passed + 1))
else
    printf "${RED}${CROSS} Recovery columns not found in schema${NC}\n"
    failure=$((failure + 1))
fi

# Test 9: Verify migration for new columns
printf "\n[Test 9] Checking migration for new columns...\n"
totaltests=$((totaltests + 1))
if grep -q "sending_started_at" /etc/eiou/src/database/DatabaseSetup.php 2>/dev/null && \
   grep -q "recovery_count" /etc/eiou/src/database/DatabaseSetup.php 2>/dev/null; then
    printf "${GREEN}${TICK} Migration includes new columns${NC}\n"
    passed=$((passed + 1))
else
    printf "${RED}${CROSS} Migration for new columns not found${NC}\n"
    failure=$((failure + 1))
fi

# Test 10: Verify processPendingTransactions uses atomic claiming
printf "\n[Test 10] Checking processPendingTransactions for atomic claiming...\n"
totaltests=$((totaltests + 1))
if grep -q "claimPendingTransaction" /etc/eiou/src/services/TransactionService.php 2>/dev/null; then
    printf "${GREEN}${TICK} processPendingTransactions uses atomic claiming${NC}\n"
    passed=$((passed + 1))
else
    printf "${RED}${CROSS} processPendingTransactions not using atomic claiming${NC}\n"
    failure=$((failure + 1))
fi

# Test 11: Verify recovery service is integrated into startup
printf "\n[Test 11] Checking startup integration...\n"
totaltests=$((totaltests + 1))
if grep -q "runTransactionRecovery" /etc/eiou/src/core/Application.php 2>/dev/null; then
    printf "${GREEN}${TICK} Transaction recovery integrated into startup${NC}\n"
    passed=$((passed + 1))
else
    printf "${RED}${CROSS} Transaction recovery not in startup sequence${NC}\n"
    failure=$((failure + 1))
fi

# Test 12: Verify ServiceContainer has getTransactionRecoveryService
printf "\n[Test 12] Checking ServiceContainer integration...\n"
totaltests=$((totaltests + 1))
if grep -q "getTransactionRecoveryService" /etc/eiou/src/services/ServiceContainer.php 2>/dev/null; then
    printf "${GREEN}${TICK} ServiceContainer has getTransactionRecoveryService${NC}\n"
    passed=$((passed + 1))
else
    printf "${RED}${CROSS} getTransactionRecoveryService not in ServiceContainer${NC}\n"
    failure=$((failure + 1))
fi

# Print Part 1 summary
printf "\n"
printf "================================================================\n"
printf "Part 1 Results: Static Verification\n"
printf "================================================================\n"
printf "Total:  %d tests\n" "$totaltests"
printf "${GREEN}Passed: %d${NC}\n" "$passed"
printf "${RED}Failed: %d${NC}\n" "$failure"
printf "================================================================\n"

# Store Part 1 results
PART1_TOTAL=$totaltests
PART1_PASSED=$passed
PART1_FAILED=$failure

# ============================================================================
# PART 2: Functional Tests (Database Operations)
# ============================================================================

printf "\n================================================================\n"
printf "  PART 2: Functional Tests (Database Operations)\n"
printf "================================================================\n"
printf "Running transaction flow scenarios with actual database...\n"

# Check if the functional test file exists
FUNCTIONAL_TEST="/etc/eiou/tests/testfiles/transaction-recovery-functional.php"
if [ ! -f "$FUNCTIONAL_TEST" ]; then
    # Try alternate location (when copied to container)
    FUNCTIONAL_TEST="/tmp/transaction-recovery-functional.php"
fi

if [ -f "$FUNCTIONAL_TEST" ]; then
    # Run the PHP functional tests
    php "$FUNCTIONAL_TEST"
    FUNCTIONAL_EXIT_CODE=$?

    if [ $FUNCTIONAL_EXIT_CODE -eq 0 ]; then
        printf "\n${GREEN}${TICK} All functional tests passed${NC}\n"
    else
        printf "\n${RED}${CROSS} Some functional tests failed${NC}\n"
        failure=$((failure + 1))
    fi
else
    printf "${YELLOW}Warning: Functional test file not found, skipping Part 2${NC}\n"
    printf "Expected at: $FUNCTIONAL_TEST\n"
fi

# ============================================================================
# FINAL SUMMARY
# ============================================================================

printf "\n"
printf "================================================================\n"
printf "  FINAL SUMMARY: Transaction Recovery Test Suite\n"
printf "================================================================\n"
printf "Part 1 (Static):     %d passed, %d failed\n" "$PART1_PASSED" "$PART1_FAILED"
if [ -f "$FUNCTIONAL_TEST" ]; then
    if [ $FUNCTIONAL_EXIT_CODE -eq 0 ]; then
        printf "Part 2 (Functional): ${GREEN}ALL PASSED${NC}\n"
    else
        printf "Part 2 (Functional): ${RED}SOME FAILED${NC}\n"
    fi
else
    printf "Part 2 (Functional): ${YELLOW}SKIPPED${NC}\n"
fi
printf "================================================================\n"

# Return appropriate exit code
if [ "$failure" -gt 0 ]; then
    exit 1
fi
if [ -n "$FUNCTIONAL_EXIT_CODE" ] && [ "$FUNCTIONAL_EXIT_CODE" -ne 0 ]; then
    exit 1
fi
exit 0
