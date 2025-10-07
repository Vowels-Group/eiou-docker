#!/bin/bash
# Comprehensive Test Runner for eIOU Refactored Code
# Runs all unit tests for Repositories and Services

set -e  # Exit on first failure

echo "╔══════════════════════════════════════════════════════════════════╗"
echo "║         eIOU Comprehensive Test Suite                            ║"
echo "║         Repository & Service Unit Tests                          ║"
echo "╚══════════════════════════════════════════════════════════════════╝"
echo ""

TESTS_PASSED=0
TESTS_FAILED=0

# Function to run a test file
run_test() {
    local test_file="$1"
    local test_name="$2"

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "Running: $test_name"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    if php "$test_file"; then
        ((TESTS_PASSED++))
        echo "✅ $test_name PASSED"
    else
        ((TESTS_FAILED++))
        echo "❌ $test_name FAILED"
    fi
    echo ""
}

# Repository Tests
echo "═══════════════════════════════════════════════════════════════════"
echo "  REPOSITORY UNIT TESTS"
echo "═══════════════════════════════════════════════════════════════════"
echo ""

run_test "tests/unit/repositories/ContactRepositoryTest.php" "ContactRepository (21 tests)"
run_test "tests/unit/repositories/TransactionRepositoryTest.php" "TransactionRepository (21 tests)"
run_test "tests/unit/repositories/P2pRepositoryTest.php" "P2pRepository (14 tests)"

# Service Tests
echo "═══════════════════════════════════════════════════════════════════"
echo "  SERVICE UNIT TESTS"
echo "═══════════════════════════════════════════════════════════════════"
echo ""

run_test "tests/unit/services/WalletServiceTest.php" "WalletService (15 tests)"

# Final Summary
echo "╔══════════════════════════════════════════════════════════════════╗"
echo "║                    FINAL TEST SUMMARY                            ║"
echo "╚══════════════════════════════════════════════════════════════════╝"
echo ""
echo "Test Suites Passed: $TESTS_PASSED"
echo "Test Suites Failed: $TESTS_FAILED"
echo ""

if [ $TESTS_FAILED -eq 0 ]; then
    echo "✅ ✅ ✅  ALL TEST SUITES PASSED  ✅ ✅ ✅"
    echo ""
    echo "Total Coverage: 71 unit tests across 4 test suites"
    echo "  - ContactRepository: 21 tests"
    echo "  - TransactionRepository: 21 tests"
    echo "  - P2pRepository: 14 tests"
    echo "  - WalletService: 15 tests"
    echo ""
    exit 0
else
    echo "❌ ❌ ❌  SOME TESTS FAILED  ❌ ❌ ❌"
    echo ""
    exit 1
fi
