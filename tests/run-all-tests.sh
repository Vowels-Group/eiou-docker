#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC

# Automated test runner for EIOU Docker test suite
# Runs all tests in correct dependency order without user interaction
#
# Usage: ./run-all-tests.sh <build_name> [mode] [subset]
#
# Arguments:
#   build_name  - The topology to test (http4, http10, http13)
#   mode        - Transport mode: http, https, or tor (default: http)
#   subset      - Test subset to run (default: all)
#
# Examples:
#   ./run-all-tests.sh http4              # Run all tests with HTTP mode
#   ./run-all-tests.sh http4 https        # Run all tests with HTTPS mode
#   ./run-all-tests.sh http4 http quick   # Run quick validation tests
#   ./run-all-tests.sh http4 http contacts # Run contact-related tests
#
# Mode descriptions:
#   http  - Test containers with http:// addresses
#   https - Test containers with https:// addresses (SSL enabled)
#   tor   - Test containers with .onion addresses (Tor network)
#
# Test subsets:
#   all          - Run all tests (default)
#   quick        - Fast validation: hostname, contacts, basic messaging
#   contacts     - Contact management: add, list, ping tests
#   transactions - Transaction tests: send, balance, recovery
#   messaging    - Message delivery and routing tests
#   api          - API endpoints and CLI command tests
#   sync         - Chain synchronization tests
#   connections  - SSL certificates and Tor connectivity tests
#   system       - System tests: shutdown, lockfiles, seedphrase
#   performance  - Performance baseline benchmarks
#
# Environment Variables (for WSL2/slow environments):
#   EIOU_INIT_TIMEOUT  - Container initialization wait in seconds (default: 120)

set -e  # Exit on error

# Load base configuration
. './baseconfig/config.sh'

# Check command line argument
if [ $# -eq 0 ]; then
    echo "Usage: $0 <build_name> [mode] [subset]"
    echo ""
    echo "Available builds: http4, http10, http13"
    echo "Available modes:  http, https, tor (default: http)"
    echo "Available subsets: all, quick, contacts, transactions, messaging, api, sync, connections, system"
    exit 1
fi

BUILD_NAME="$1"
BUILD_FILE="./buildfiles/${BUILD_NAME}.sh"

# Set defaults
MODE="${2:-http}"
SUBSET="${3:-all}"

# Validate MODE is one of the allowed values
VALID_MODES="http https tor"
if ! echo "$VALID_MODES" | grep -qw "$MODE"; then
    printf "${RED}Error: Invalid mode '${MODE}'${NC}\n"
    echo "Available modes: http, https, tor"
    echo ""
    echo "Mode descriptions:"
    echo "  http  - Test containers with http:// addresses"
    echo "  https - Test containers with https:// addresses (SSL enabled)"
    echo "  tor   - Test containers with .onion addresses (Tor network)"
    exit 1
fi

# Display available subsets helper function
show_available_subsets() {
    printf "\n${YELLOW}Available test subsets:${NC}\n"
    echo ""
    printf "  ${GREEN}all${NC}          - Run all tests (default)\n"
    printf "  ${GREEN}quick${NC}        - Fast validation: hostname, contacts, basic messaging\n"
    printf "  ${GREEN}contacts${NC}     - Contact management: add, list, ping tests\n"
    printf "  ${GREEN}transactions${NC} - Transaction tests: send, balance, recovery\n"
    printf "  ${GREEN}messaging${NC}    - Message delivery and routing tests\n"
    printf "  ${GREEN}api${NC}          - API endpoints and CLI command tests\n"
    printf "  ${GREEN}sync${NC}         - Chain synchronization tests\n"
    printf "  ${GREEN}connections${NC}  - SSL certificates and Tor connectivity tests\n"
    printf "  ${GREEN}system${NC}       - System tests: shutdown, lockfiles, seedphrase\n"
    printf "  ${GREEN}performance${NC}  - Performance baseline benchmarks\n"
    echo ""
    printf "${YELLOW}Note:${NC} Some subsets require 'addContactsTest' to run first.\n"
    printf "      The runner automatically includes prerequisites when needed.\n"
    echo ""
}

# Validate SUBSET is one of the allowed values
VALID_SUBSETS="all quick contacts transactions messaging api sync connections system performance"
if ! echo "$VALID_SUBSETS" | grep -qw "$SUBSET"; then
    printf "${RED}Error: Invalid test subset '${SUBSET}'${NC}\n"
    show_available_subsets
    exit 1
fi

# Verify build file exists
if [ ! -f "$BUILD_FILE" ]; then
    printf "${RED}Error: Build file '${BUILD_FILE}' not found${NC}\n"
    echo "Available builds:"
    ls -1 ./buildfiles/*.sh 2>/dev/null | xargs -n1 basename | sed 's/\.sh$//' | sed 's/^/  - /'
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
printf "Build:  ${BUILD_NAME}\n"
printf "Mode:   ${MODE}\n"
printf "Subset: ${SUBSET}\n"
printf "Time:   $(date '+%Y-%m-%d %H:%M:%S')\n"
printf "\n"

# Disable contact status pinging during tests to prevent interference with sync tests
# The ping feature is tested separately in pingTestSuite.sh which re-enables it
export EIOU_CONTACT_STATUS_ENABLED=false
printf "${YELLOW}Note: Contact status pinging disabled during test suite${NC}\n"
printf "\n"

# Step 1: Build the topology
printf "${GREEN}[Step 1/3]${NC} Building topology '${BUILD_NAME}'...\n"
. "$BUILD_FILE"

if [ $? -ne 0 ]; then
    printf "${RED}${CROSS} Build failed!${NC}\n"
    exit 1
fi

printf "${GREEN}${CHECK} Build completed successfully${NC}\n"

# Wait for containers to be fully initialized
# WSL2 environments have slower I/O; use EIOU_INIT_TIMEOUT env var to override
MAX_WAIT=${EIOU_INIT_TIMEOUT:-120}  # Maximum wait time per container in seconds (matches EIOU_TOR_TIMEOUT in startup.sh)
printf "\n${GREEN}Waiting for containers to initialize (timeout: ${MAX_WAIT}s per container)...${NC}\n"

# Get list of eiou containers from docker
CONTAINER_LIST=$(docker ps --filter "ancestor=eiou/eiou" --format "{{.Names}}" 2>/dev/null)

if [ -z "$CONTAINER_LIST" ]; then
    printf "${RED}No eiou containers found!${NC}\n"
    exit 1
fi

for container in $CONTAINER_LIST; do
    printf "Checking ${container}... "
    elapsed=0
    hostname_ready=false
    tor_ready=false

    while [ $elapsed -lt $MAX_WAIT ]; do
        # Check if container is still running
        if ! docker ps | grep -q "$container"; then
            printf "${RED}Container stopped unexpectedly!${NC}\n"
            exit 1
        fi

        # Verify if userconfig.json exists, it's valid JSON and has required fields
        if [ "$MODE" == 'http' ] || [ "$MODE" == 'https' ]; then
            # First check hostname is set (use hostname_secure for https mode, hostname for http mode)
            if [ "$hostname_ready" != "true" ]; then
                if [ "$MODE" == 'https' ]; then
                    # HTTPS mode: check hostname_secure field
                    httpAddress=$(docker exec "$container" php -r '
                        if (file_exists("/etc/eiou/userconfig.json")) {
                            $json = json_decode(file_get_contents("/etc/eiou/userconfig.json"), true);
                            if (isset($json["hostname_secure"])){
                                echo $json["hostname_secure"];
                            }
                        }')
                    if [[ ! -z ${httpAddress} ]] && [[ ${httpAddress} == https://* ]]; then
                        hostname_ready=true
                    fi
                else
                    # HTTP mode: check hostname field
                    httpAddress=$(docker exec "$container" php -r '
                        if (file_exists("/etc/eiou/userconfig.json")) {
                            $json = json_decode(file_get_contents("/etc/eiou/userconfig.json"), true);
                            if (isset($json["hostname"])){
                                echo $json["hostname"];
                            }
                        }')
                    if [[ ! -z ${httpAddress} ]]; then
                        # HTTP mode accepts both http:// and https:// addresses for backward compatibility
                        if [[ ${httpAddress} == http://* ]] || [[ ${httpAddress} == https://* ]]; then
                            hostname_ready=true
                        fi
                    fi
                fi
            fi

            # Then check Tor connectivity (processors won't start until Tor is ready)
            if [ "$hostname_ready" == "true" ] && [ "$tor_ready" != "true" ]; then
                torAddress=$(docker exec "$container" php -r '
                    if (file_exists("/etc/eiou/userconfig.json")) {
                        $json = json_decode(file_get_contents("/etc/eiou/userconfig.json"), true);
                        if(isset($json["torAddress"])){
                            echo $json["torAddress"];
                        }
                    }')
                if [[ ! -z ${torAddress} ]]; then
                    # Verify actual TOR connectivity via SOCKS proxy
                    if docker exec "$container" curl --socks5-hostname 127.0.0.1:9050 \
                        --connect-timeout 5 \
                        --max-time 10 \
                        --silent \
                        --fail \
                        --output /dev/null \
                        "$torAddress" 2>/dev/null; then
                        tor_ready=true
                    fi
                fi
            fi

            # For HTTP/HTTPS modes: hostname is required, Tor is optional
            # Check if processors have started (indicates container is fully ready)
            if [ "$hostname_ready" == "true" ]; then
                processors_started=$(docker logs "$container" 2>&1 | grep -c "message processing started successfully" | tr -d '[:space:]' || echo "0")
                processors_started=${processors_started:-0}
                if [ "$processors_started" -ge 2 ] 2>/dev/null; then
                    if [ "$tor_ready" == "true" ]; then
                        if [ "$MODE" == 'https' ]; then
                            printf "${GREEN}Ready (HTTPS + Tor)${NC}\n"
                        else
                            printf "${GREEN}Ready (HTTP + Tor)${NC}\n"
                        fi
                    else
                        if [ "$MODE" == 'https' ]; then
                            printf "${YELLOW}Ready (HTTPS, Tor not verified)${NC}\n"
                        else
                            printf "${YELLOW}Ready (HTTP, Tor not verified)${NC}\n"
                        fi
                    fi
                    break
                fi
            fi
        elif [ "$MODE" == 'tor' ]; then
            torAddress=$(docker exec "$container" php -r '
                if (file_exists("/etc/eiou/userconfig.json")) {
                    $json = json_decode(file_get_contents("/etc/eiou/userconfig.json"), true);
                    if(isset($json["torAddress"])){
                        echo $json["torAddress"];
                    }
                }')
            if [[ ! -z ${torAddress} ]]; then
                # Verify actual TOR connectivity, not just presence of torAddress
                if docker exec "$container" curl --socks5-hostname 127.0.0.1:9050 \
                    --connect-timeout 5 \
                    --max-time 10 \
                    --silent \
                    --fail \
                    --output /dev/null \
                    "$torAddress" 2>/dev/null; then
                    printf "${GREEN}Ready (Tor connected)${NC}\n"
                    break
                fi
            fi
        fi

        sleep 1
        elapsed=$((elapsed + 1))
    done

    if [ $elapsed -ge $MAX_WAIT ]; then
        printf "${RED}Timeout waiting for initialization!${NC}\n"
        processors_started=$(docker logs "$container" 2>&1 | grep -c "message processing started successfully" | tr -d '[:space:]' || echo "0")
        processors_started=${processors_started:-0}
        printf "${YELLOW}Hostname ready: ${hostname_ready}, Tor ready: ${tor_ready}, Processors started: ${processors_started}${NC}\n"
        docker logs "$container" | tail -n 30
        exit 1
    fi
done

printf "${GREEN}${CHECK} All containers initialized successfully${NC}\n"
# Brief buffer time for message processors (using environment variable if set)
sleep ${TEST_POLL_INTERVAL:-1}

# Step 2: Run prerequisite test (hostnameTest (HTTP/HTTPS) or torAddressTest (TOR))
printf "\n${GREEN}[Step 2/3]${NC} Running prerequisite test...\n"
if [ "$MODE" == 'http' ] || [ "$MODE" == 'https' ]; then
    run_test "hostnameTest" "./tests/testfiles/hostnameTest.sh" "true"
elif [ "$MODE" == 'tor' ]; then
    run_test "torAddressTest" "./tests/testfiles/torAddressTest.sh" "true"
fi

# Step 3: Run all tests in dependency order
printf "\n${GREEN}[Step 3/3]${NC} Running test suite...\n"

# Define test subsets
# Each subset includes its tests and any required prerequisites
#
# Consolidated test suites:
# - torTestSuite: TOR address, service, permissions, and restart tests
# - syncTestSuite: Transaction chain sync, signature validation, and contact sync tests
# - transactionTestSuite: Transaction recording, inquiry, contact, and held transaction tests
# - seedphraseTestSuite: Seed phrase restore, secure display, and authcode restoration tests

# Full test order (all tests)
TESTS_ALL="
sslCertificateTest
torTestSuite
addContactsTest
sendMessageTest
balanceTest
sendAllPeersTest
routingTest
contactListTest
transactionTestSuite
negativeFinancialTest
messageDeliveryTest
curlErrorHandlingTest
cliCommandsTest
apiEndpointsTest
securityTestSuite
apiInputValidationTest
syncTestSuite
gracefulShutdownTest
transactionRecoveryTest
seedphraseTestSuite
backupTestSuite
processorLockfileTest
pingTestSuite
serviceInterfaceTest
serviceExceptionTest
performanceBaseline
"

# Quick validation (fast smoke tests)
TESTS_QUICK="
addContactsTest
sendMessageTest
balanceTest
"

# Contact management tests
TESTS_CONTACTS="
addContactsTest
contactListTest
pingTestSuite
"

# Transaction tests (requires contacts)
TESTS_TRANSACTIONS="
addContactsTest
balanceTest
transactionTestSuite
transactionRecoveryTest
negativeFinancialTest
"

# Messaging tests (requires contacts)
TESTS_MESSAGING="
addContactsTest
sendMessageTest
sendAllPeersTest
routingTest
messageDeliveryTest
"

# API and CLI tests (requires contacts for some endpoints)
TESTS_API="
addContactsTest
curlErrorHandlingTest
cliCommandsTest
apiEndpointsTest
securityTestSuite
apiInputValidationTest
serviceExceptionTest
"

# Sync tests (requires contacts and transactions)
TESTS_SYNC="
addContactsTest
sendMessageTest
syncTestSuite
"

# Connection tests (SSL/Tor)
TESTS_CONNECTIONS="
sslCertificateTest
torTestSuite
"

# System tests
TESTS_SYSTEM="
gracefulShutdownTest
seedphraseTestSuite
processorLockfileTest
serviceInterfaceTest
serviceExceptionTest
backupTestSuite
"

# Performance tests (requires contacts for transaction benchmarks)
TESTS_PERFORMANCE="
addContactsTest
performanceBaseline
"

# Select test order based on subset
case "$SUBSET" in
    all)
        TEST_ORDER="$TESTS_ALL"
        ;;
    quick)
        TEST_ORDER="$TESTS_QUICK"
        ;;
    contacts)
        TEST_ORDER="$TESTS_CONTACTS"
        ;;
    transactions)
        TEST_ORDER="$TESTS_TRANSACTIONS"
        ;;
    messaging)
        TEST_ORDER="$TESTS_MESSAGING"
        ;;
    api)
        TEST_ORDER="$TESTS_API"
        ;;
    sync)
        TEST_ORDER="$TESTS_SYNC"
        ;;
    connections)
        TEST_ORDER="$TESTS_CONNECTIONS"
        ;;
    system)
        TEST_ORDER="$TESTS_SYSTEM"
        ;;
    performance)
        TEST_ORDER="$TESTS_PERFORMANCE"
        ;;
esac

printf "${GREEN}Test subset: ${SUBSET}${NC}\n"
printf "Tests to run: $(echo $TEST_ORDER | wc -w | tr -d ' ')\n"

# Run each test in order
for test_name in $TEST_ORDER; do
    test_file="./tests/testfiles/${test_name}.sh"
    run_test "$test_name" "$test_file" "false"
done

# Cleaning up all made containers and volumes
printf "\n"
printf "================================================================\n"
printf "                    Cleaning up\n"
printf "================================================================\n"
printf "Removing existing test containers and associated volumes (if any)..."
for container in "${containers[@]}"; do
    remove_container_if_exists $container
done

remove_container_if_exists $restoreContainer
remove_container_if_exists $authcodeRestoreContainer
remove_container_if_exists "httpRestoreFileTest"
remove_container_if_exists "httpRestoreEnvTest"
printf "================================================================\n"

# Final summary
printf "\n"
printf "\n"
printf "================================================================\n"
printf "                    TEST SUITE SUMMARY\n"
printf "================================================================\n"
printf "Build:          ${BUILD_NAME}\n"
printf "Mode:           ${MODE}\n"
printf "Subset:         ${SUBSET}\n"
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
    printf "${RED}Failed Tests:${NC}\n"
    echo "$FAILED_TESTS" | tr ',' '\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -v '^$' | sed 's/^/  - /'
    printf "\n"
fi

# Determine exit code
if [ "$TOTAL_TESTS_FAILED" -eq 0 ]; then
    printf "\n${GREEN}${CHECK} All tests passed successfully!${NC}\n"
    exit 0
else
    printf "\n${RED}${CROSS} Test suite completed with failures${NC}\n"
    exit 1
fi

