#!/bin/bash

# Automated test runner for EIOU Docker test suite
# Runs all tests in correct dependency order without user interaction
# Usage: ./tests/run-all-tests.sh [build_name]
# Example: ./tests/run-all-tests.sh http4

set -e  # Exit on error

# Colors and symbols for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'
CHECK='\u2714'
CROSS='\u274c'

# Load base configuration
. './tests/baseconfig/config.sh'

# Check command line argument
if [ $# -eq 0 ]; then
    echo "Usage: $0 <build_name>"
    echo "Available builds: http4, http10, http13, http37"
    exit 1
fi

BUILD_NAME="$1"
BUILD_FILE="./tests/buildfiles/${BUILD_NAME}.sh"

# Verify build file exists
if [ ! -f "$BUILD_FILE" ]; then
    printf "${RED}Error: Build file '${BUILD_FILE}' not found${NC}\n"
    echo "Available builds:"
    ls -1 ./tests/buildfiles/*.sh 2>/dev/null | xargs -n1 basename | sed 's/\.sh$//' | sed 's/^/  - /'
    exit 1
fi

# Track overall test results
TOTAL_TESTS_RUN=0
TOTAL_TESTS_PASSED=0
TOTAL_TESTS_FAILED=0
FAILED_TESTS=""

# Function to run a test and track results
run_test() {
    local test_name="$1"
    local test_file="$2"
    local is_prerequisite="$3"

    if [ ! -f "$test_file" ]; then
        printf "${YELLOW}Warning: Test file '${test_file}' not found, skipping${NC}\n"
        return 1
    fi

    printf "\n"
    printf "================================================================\n"
    printf "Running: ${test_name}\n"
    printf "================================================================\n"

    # Run the test and capture its exit status
    set +e  # Temporarily allow errors
    . "$test_file"
    local test_exit_code=$?
    set -e

    # Track results (tests should set passed/failure variables)
    if [ -n "$totaltests" ]; then
        TOTAL_TESTS_RUN=$(( TOTAL_TESTS_RUN + totaltests ))
        TOTAL_TESTS_PASSED=$(( TOTAL_TESTS_PASSED + passed ))
        TOTAL_TESTS_FAILED=$(( TOTAL_TESTS_FAILED + failure ))

        if [ "$failure" -gt 0 ]; then
            FAILED_TESTS="${FAILED_TESTS}${test_name} (${failure} failures), "

            # If this is a prerequisite test and it failed, we should stop
            if [ "$is_prerequisite" = "true" ] && [ "$failure" -eq "$totaltests" ]; then
                printf "\n${RED}${CROSS} Critical prerequisite test '${test_name}' failed completely${NC}\n"
                printf "${RED}Cannot continue with remaining tests${NC}\n"
                exit 1
            fi
        fi
    fi

    return 0
}

# Start test execution
printf "\n"
printf "========================================\n"
printf "  EIOU Docker Automated Test Runner\n"
printf "========================================\n"
printf "Build: ${BUILD_NAME}\n"
printf "Time: $(date '+%Y-%m-%d %H:%M:%S')\n"
printf "\n"

# Step 1: Build the topology
printf "${GREEN}[Step 1/3]${NC} Building topology '${BUILD_NAME}'...\n"
. "$BUILD_FILE"

if [ $? -ne 0 ]; then
    printf "${RED}${CROSS} Build failed!${NC}\n"
    exit 1
fi

printf "${GREEN}${CHECK} Build completed successfully${NC}\n"
sleep 2

# Step 2: Run prerequisite test (generateTest)
printf "\n${GREEN}[Step 2/3]${NC} Running prerequisite test...\n"
run_test "generateTest" "./tests/testfiles/generateTest.sh" "true"

# Step 3: Run all tests in dependency order
printf "\n${GREEN}[Step 3/3]${NC} Running test suite...\n"

# Define test order (excluding generateTest as it's already run)
TEST_ORDER="
addContactsTest
sendMessageTest
balanceTest
sendAllPeersTest
routingTest
contactListTest
transactionHistoryTest
"

# Run each test in order
for test_name in $TEST_ORDER; do
    test_file="./tests/testfiles/${test_name}.sh"
    run_test "$test_name" "$test_file" "false"
done

# Final summary
printf "\n"
printf "================================================================\n"
printf "                    TEST SUITE SUMMARY\n"
printf "================================================================\n"
printf "Build:          ${BUILD_NAME}\n"
printf "Total Tests:    ${TOTAL_TESTS_RUN}\n"
printf "${GREEN}Passed:         ${TOTAL_TESTS_PASSED}${NC}\n"

if [ "$TOTAL_TESTS_FAILED" -gt 0 ]; then
    printf "${RED}Failed:         ${TOTAL_TESTS_FAILED}${NC}\n"
else
    printf "Failed:         0\n"
fi

# Calculate success rate
if [ "$TOTAL_TESTS_RUN" -gt 0 ]; then
    SUCCESS_RATE=$(awk "BEGIN {printf \"%.1f\", ($TOTAL_TESTS_PASSED * 100.0 / $TOTAL_TESTS_RUN)}")
    printf "Success Rate:   ${SUCCESS_RATE}%%\n"
fi

printf "================================================================\n"

# List failed tests if any
if [ -n "$FAILED_TESTS" ]; then
    printf "\n${RED}Failed Tests:${NC}\n"
    echo "$FAILED_TESTS" | tr ',' '\n' | sed 's/^/  - /' | grep -v '^  - $'
fi

# Determine exit code
if [ "$TOTAL_TESTS_FAILED" -eq 0 ]; then
    printf "\n${GREEN}${CHECK} All tests passed successfully!${NC}\n"
    exit 0
else
    printf "\n${RED}${CROSS} Test suite completed with failures${NC}\n"
    exit 1
fi
