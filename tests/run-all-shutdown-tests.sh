#!/bin/bash
#
# Master Test Runner for Graceful Shutdown Test Suite
#
# Runs all shutdown-related tests:
# - Unit tests (SignalHandlerTest.php)
# - Integration tests (graceful-shutdown-test.php)
# - Shell scenarios (shutdown-scenarios.sh)
# - Recovery tests (unclean-shutdown-test.php)
#
# Usage: ./tests/run-all-shutdown-tests.sh
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."

# Results tracking
TOTAL_SUITES=0
PASSED_SUITES=0
FAILED_SUITES=0

echo -e "${BLUE}╔═══════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║   Graceful Shutdown Test Suite - Full Run        ║${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════╝${NC}"
echo ""

# Helper: Run test suite
run_test_suite() {
    local name="$1"
    local command="$2"

    TOTAL_SUITES=$((TOTAL_SUITES + 1))

    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}Running: $name${NC}"
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""

    if eval "$command"; then
        echo ""
        echo -e "${GREEN}✓ $name - PASSED${NC}"
        PASSED_SUITES=$((PASSED_SUITES + 1))
        return 0
    else
        echo ""
        echo -e "${RED}✗ $name - FAILED${NC}"
        FAILED_SUITES=$((FAILED_SUITES + 1))
        return 1
    fi
}

# Start test execution
echo -e "${BLUE}Starting test execution at $(date)${NC}"
echo ""

# Test 1: Unit Tests
run_test_suite \
    "Unit Tests - Signal Handler" \
    "php tests/unit/SignalHandlerTest.php"

echo ""

# Test 2: Integration Tests
run_test_suite \
    "Integration Tests - Graceful Shutdown" \
    "php tests/integration/graceful-shutdown-test.php"

echo ""

# Test 3: Shell Scenarios
run_test_suite \
    "Shell Integration - Shutdown Scenarios" \
    "./tests/integration/shutdown-scenarios.sh"

echo ""

# Test 4: Recovery Tests
run_test_suite \
    "Recovery Tests - Unclean Shutdown" \
    "php tests/recovery/unclean-shutdown-test.php"

# Print final summary
echo ""
echo -e "${BLUE}╔═══════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║              Final Test Summary                   ║${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "Total Test Suites: $TOTAL_SUITES"
echo -e "${GREEN}Passed: $PASSED_SUITES${NC}"
echo -e "${RED}Failed: $FAILED_SUITES${NC}"
echo ""

if [ $FAILED_SUITES -eq 0 ]; then
    echo -e "${GREEN}╔═══════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║          ALL TESTS PASSED SUCCESSFULLY!           ║${NC}"
    echo -e "${GREEN}╚═══════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${GREEN}Test Coverage:${NC}"
    echo -e "  ✓ 30 Unit tests (signal handling)"
    echo -e "  ✓ 25 Integration tests (graceful shutdown)"
    echo -e "  ✓ 11 Shell scenarios (36+ assertions)"
    echo -e "  ✓ 25 Recovery tests (crash recovery)"
    echo -e "  ${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "  ${GREEN}Total: 91+ test cases${NC}"
    echo ""
    exit 0
else
    echo -e "${RED}╔═══════════════════════════════════════════════════╗${NC}"
    echo -e "${RED}║        SOME TESTS FAILED - REVIEW NEEDED          ║${NC}"
    echo -e "${RED}╚═══════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${YELLOW}Please review the failed test output above.${NC}"
    echo ""
    exit 1
fi
