#!/bin/bash
# Test runner script for eIOU application

echo "========================================="
echo "      eIOU Test Suite Runner"
echo "========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test counters
TOTAL=0
PASSED=0
FAILED=0

# Function to run a test file
run_test() {
    local test_file=$1
    local test_name=$(basename $test_file .php)

    echo -n "Running $test_name... "
    TOTAL=$((TOTAL + 1))

    # Run the test and capture output
    output=$(php $test_file 2>&1)
    exit_code=$?

    if [ $exit_code -eq 0 ]; then
        echo -e "${GREEN}✓ PASSED${NC}"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}✗ FAILED${NC}"
        FAILED=$((FAILED + 1))
        echo "$output" | tail -n 10
        echo ""
    fi
}

# Unit Tests
echo -e "${YELLOW}Running Unit Tests${NC}"
echo "-----------------"
for test in tests/Unit/*.php; do
    if [ -f "$test" ]; then
        run_test "$test"
    fi
done
echo ""

# Integration Tests
echo -e "${YELLOW}Running Integration Tests${NC}"
echo "------------------------"
for test in tests/Integration/*.php; do
    if [ -f "$test" ]; then
        run_test "$test"
    fi
done
echo ""

# Security Tests
echo -e "${YELLOW}Running Security Tests${NC}"
echo "---------------------"
for test in tests/Security/*.php; do
    if [ -f "$test" ]; then
        run_test "$test"
    fi
done
echo ""

# Summary
echo "========================================="
echo "            Test Summary"
echo "========================================="
echo -e "Total Tests:  $TOTAL"
echo -e "Passed:       ${GREEN}$PASSED${NC}"
echo -e "Failed:       ${RED}$FAILED${NC}"

# Calculate coverage percentage
if [ $TOTAL -gt 0 ]; then
    COVERAGE=$((PASSED * 100 / TOTAL))
    echo -e "Success Rate: $COVERAGE%"
else
    echo "No tests found!"
fi

echo "========================================="

# Exit with appropriate code
if [ $FAILED -gt 0 ]; then
    exit 1
else
    exit 0
fi