#!/bin/bash
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

# Automated test runner for EIOU Docker test suite
# Runs all tests in correct dependency order without user interaction
#
# Usage: ./run-all-tests.sh <build_name> [mode]
#
# Arguments:
#   build_name  - The topology to test (http4, http10, http13)
#   mode        - Transport mode: http, https, or tor (default: http)
#
# Examples:
#   ./run-all-tests.sh http4           # Run with default mode (http)
#   ./run-all-tests.sh http4 https     # Run with HTTPS mode
#   ./run-all-tests.sh http4 http      # Run with HTTP mode
#   ./run-all-tests.sh http4 tor       # Run with TOR mode
#
# Mode descriptions:
#   http  - Test containers with http:// addresses
#   https - Test containers with https:// addresses (SSL enabled)
#   tor   - Test containers with .onion addresses (Tor network)
#
# Environment Variables (for WSL2/slow environments):
#   EIOU_INIT_TIMEOUT  - Container initialization wait in seconds (default: 90)

set -e  # Exit on error

# Load base configuration
. './baseconfig/config.sh'

# Check command line argument
if [ $# -eq 0 ]; then
    echo "Usage: $0 <build_name> <mode>"
    echo "Available builds: http4, http10, http13"
    echo "Available modes: tor, http, https"
    exit 1
fi

BUILD_NAME="$1"
BUILD_FILE="./buildfiles/${BUILD_NAME}.sh"
if [ $# -eq 1 ]; then
    MODE="http"
else
    MODE="$2"
fi

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
printf "Build: ${BUILD_NAME}\n"
printf "Mode:  ${MODE}\n"
printf "Time:  $(date '+%Y-%m-%d %H:%M:%S')\n"
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
printf "\n${GREEN}Waiting for containers to initialize...${NC}\n"
# WSL2 environments have slower I/O; use EIOU_INIT_TIMEOUT env var to override
MAX_WAIT=${EIOU_INIT_TIMEOUT:-90}  # Maximum wait time per container in seconds

# Get list of eioud containers from docker
CONTAINER_LIST=$(docker ps --filter "ancestor=eioud" --format "{{.Names}}" 2>/dev/null)

if [ -z "$CONTAINER_LIST" ]; then
    printf "${RED}No eioud containers found!${NC}\n"
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
            # First check hostname is set
            if [ "$hostname_ready" != "true" ]; then
                httpAddress=$(docker exec "$container" php -r '
                    if (file_exists("/etc/eiou/userconfig.json")) {
                        $json = json_decode(file_get_contents("/etc/eiou/userconfig.json"), true);
                        if (isset($json["hostname"])){
                            echo $json["hostname"];
                        }
                    }')
                if [[ ! -z ${httpAddress} ]]; then
                    # Validate protocol matches MODE for explicit protocol modes
                    if [ "$MODE" == 'https' ] && [[ ${httpAddress} == https://* ]]; then
                        hostname_ready=true
                    elif [ "$MODE" == 'http' ] && [[ ${httpAddress} == http://* ]]; then
                        hostname_ready=true
                    elif [ "$MODE" == 'http' ]; then
                        # For backward compatibility: http mode accepts https:// addresses
                        hostname_ready=true
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

            # Both hostname and Tor must be ready
            if [ "$hostname_ready" == "true" ] && [ "$tor_ready" == "true" ]; then
                if [ "$MODE" == 'https' ]; then
                    printf "${GREEN}Ready (HTTPS + Tor)${NC}\n"
                else
                    printf "${GREEN}Ready (HTTP + Tor)${NC}\n"
                fi
                break
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
        printf "${YELLOW}Hostname ready: ${hostname_ready}, Tor ready: ${tor_ready}${NC}\n"
        docker logs "$container" | tail -n 20
        exit 1
    fi
done

printf "${GREEN}${CHECK} All containers initialized successfully${NC}\n"
sleep 2  # Additional buffer time for message processors

# Step 2: Run prerequisite test (hostnameTest (HTTP/HTTPS) or torAddressTest (TOR))
printf "\n${GREEN}[Step 2/3]${NC} Running prerequisite test...\n"
if [ "$MODE" == 'http' ] || [ "$MODE" == 'https' ]; then
    run_test "hostnameTest" "./tests/testfiles/hostnameTest.sh" "true"
elif [ "$MODE" == 'tor' ]; then
    run_test "torAddressTest" "./tests/testfiles/torAddressTest.sh" "true"
fi

# Step 3: Run all tests in dependency order
printf "\n${GREEN}[Step 3/3]${NC} Running test suite...\n"

# Define test order (excluding hostnameTest/torAddressTest as it's already run)
# TOR-specific tests are included for both modes but will validate TOR functionality
#
# Consolidated test suites:
# - torTestSuite: TOR address, service, permissions, and restart tests
# - syncTestSuite: Transaction chain sync, signature validation, and contact sync tests
# - transactionTestSuite: Transaction recording, inquiry, contact, and held transaction tests
# - seedphraseTestSuite: Seed phrase restore, secure display, and authcode restoration tests
TEST_ORDER="
sslCertificateTest
torTestSuite
addContactsTest
sendMessageTest
balanceTest
sendAllPeersTest
routingTest
contactListTest
transactionTestSuite
messageDeliveryTest
curlErrorHandlingTest
cliCommandsTest
apiEndpointsTest
syncTestSuite
gracefulShutdownTest
transactionRecoveryTest
seedphraseTestSuite
processorLockfileTest
pingTestSuite
"

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

