#!/bin/bash
#
# End-to-End Integration Tests for GUI Modernization
# Tests complete user workflows, real-time updates, error recovery
#
# Usage: ./test-gui-flow.sh
#
# Requirements:
# - Docker container running (docker-compose-single.yml)
# - curl, jq installed

set -e

# Configuration
DOCKER_COMPOSE_FILE="docker-compose-single.yml"
DOCKER_SERVICE="alice"
BASE_URL="http://localhost:8080"
TEST_START_TIME=$(date +%s)

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test counters
TESTS_PASSED=0
TESTS_FAILED=0
TESTS_SKIPPED=0

# Print functions
print_header() {
    echo ""
    echo "=========================================="
    echo "$1"
    echo "=========================================="
    echo ""
}

print_test() {
    echo -e "${YELLOW}Test $1:${NC} $2"
}

print_pass() {
    echo -e "  ${GREEN}✅ PASS${NC}: $1"
    ((TESTS_PASSED++))
}

print_fail() {
    echo -e "  ${RED}❌ FAIL${NC}: $1"
    ((TESTS_FAILED++))
}

print_skip() {
    echo -e "  ${YELLOW}⏭️  SKIP${NC}: $1"
    ((TESTS_SKIPPED++))
}

# Utility functions
check_docker_running() {
    if ! docker ps --filter "name=$DOCKER_SERVICE" --format "{{.Names}}" | grep -q "$DOCKER_SERVICE"; then
        return 1
    fi
    return 0
}

http_get() {
    local path=$1
    local timeout=${2:-10}
    curl -s -w "\n%{http_code}\n%{time_total}" --max-time "$timeout" "${BASE_URL}${path}" 2>/dev/null
}

docker_exec() {
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T "$DOCKER_SERVICE" "$@"
}

extract_http_code() {
    echo "$1" | tail -n 2 | head -n 1
}

extract_response_time() {
    echo "$1" | tail -n 1
}

extract_body() {
    echo "$1" | head -n -2
}

# Test Suite
print_header "EIOU GUI INTEGRATION TESTS"
echo "Start time: $(date)"
echo "Base URL: $BASE_URL"
echo "Docker service: $DOCKER_SERVICE"

# Test 1: Docker container is running
print_test 1 "Docker container status"
if check_docker_running; then
    print_pass "Docker container is running"
else
    print_fail "Docker container not running"
    echo "Please start the container: docker compose -f $DOCKER_COMPOSE_FILE up -d"
    exit 1
fi

# Test 2: Homepage loads successfully
print_test 2 "Homepage load"
RESPONSE=$(http_get "/" 10)
HTTP_CODE=$(extract_http_code "$RESPONSE")
RESPONSE_TIME=$(extract_response_time "$RESPONSE")

if [ "$HTTP_CODE" = "200" ]; then
    print_pass "Homepage loaded (HTTP $HTTP_CODE, ${RESPONSE_TIME}s)"
else
    print_fail "Homepage failed (HTTP $HTTP_CODE)"
fi

# Test 3: Page load time is under threshold
print_test 3 "Page load performance"
MAX_LOAD_TIME=0.5

if (( $(echo "$RESPONSE_TIME < $MAX_LOAD_TIME" | bc -l) )); then
    print_pass "Load time ${RESPONSE_TIME}s < ${MAX_LOAD_TIME}s"
else
    print_fail "Load time ${RESPONSE_TIME}s > ${MAX_LOAD_TIME}s"
fi

# Test 4: JavaScript files load correctly
print_test 4 "JavaScript assets"
SCRIPT_RESPONSE=$(http_get "/src/gui/assets/js/toast.js" 5)
SCRIPT_CODE=$(extract_http_code "$SCRIPT_RESPONSE")

if [ "$SCRIPT_CODE" = "200" ]; then
    print_pass "JavaScript files accessible"
else
    print_fail "JavaScript files not found (HTTP $SCRIPT_CODE)"
fi

# Test 5: CSS files load correctly
print_test 5 "CSS assets"
CSS_RESPONSE=$(http_get "/src/gui/assets/css/page.css" 5)
CSS_CODE=$(extract_http_code "$CSS_RESPONSE")

if [ "$CSS_CODE" = "200" ]; then
    print_pass "CSS files accessible"
else
    print_fail "CSS files not found (HTTP $CSS_CODE)"
fi

# Test 6: Container logs have no errors
print_test 6 "Container error check"
ERROR_COUNT=$(docker compose -f "$DOCKER_COMPOSE_FILE" logs --tail=100 2>&1 | grep -i "error\|fatal\|exception" | grep -v "error_reporting\|error_log" | wc -l)

if [ "$ERROR_COUNT" -eq 0 ]; then
    print_pass "No errors in container logs"
else
    print_fail "Found $ERROR_COUNT errors in logs"
fi

# Test 7: PHP processes running
print_test 7 "PHP processes"
if docker_exec ps aux | grep -q "php"; then
    print_pass "PHP processes running"
else
    print_fail "No PHP processes found"
fi

# Test 8: Database connectivity
print_test 8 "Database connectivity"
DB_TEST=$(docker_exec php -r "try { new PDO('mysql:host=mysql;dbname=eiou', 'eiou', 'password'); echo 'OK'; } catch (Exception \$e) { echo 'FAIL'; }" 2>/dev/null)

if [ "$DB_TEST" = "OK" ]; then
    print_pass "Database connection successful"
else
    print_fail "Database connection failed"
fi

# Test 9: Multiple concurrent requests
print_test 9 "Concurrent request handling"
START_TIME=$(date +%s.%N)
for i in {1..10}; do
    curl -s "${BASE_URL}/" > /dev/null &
done
wait
END_TIME=$(date +%s.%N)
CONCURRENT_TIME=$(echo "$END_TIME - $START_TIME" | bc)

if (( $(echo "$CONCURRENT_TIME < 2.0" | bc -l) )); then
    print_pass "10 concurrent requests in ${CONCURRENT_TIME}s"
else
    print_fail "Concurrent requests too slow: ${CONCURRENT_TIME}s"
fi

# Test 10: Memory usage is reasonable
print_test 10 "Memory usage"
MEMORY_USAGE=$(docker stats "$DOCKER_SERVICE" --no-stream --format "{{.MemUsage}}" | awk '{print $1}')
MEMORY_NUM=$(echo "$MEMORY_USAGE" | sed 's/[^0-9.]//g')
MEMORY_UNIT=$(echo "$MEMORY_USAGE" | sed 's/[0-9.]//g')

# Convert to MB if needed
if [ "$MEMORY_UNIT" = "GiB" ]; then
    MEMORY_MB=$(echo "$MEMORY_NUM * 1024" | bc)
else
    MEMORY_MB=$MEMORY_NUM
fi

MAX_MEMORY_MB=100
if (( $(echo "$MEMORY_MB < $MAX_MEMORY_MB" | bc -l) )); then
    print_pass "Memory usage ${MEMORY_USAGE} < ${MAX_MEMORY_MB}MB"
else
    print_fail "Memory usage ${MEMORY_USAGE} > ${MAX_MEMORY_MB}MB"
fi

# Test 11: Container stability (uptime check)
print_test 11 "Container stability"
CONTAINER_STATUS=$(docker inspect -f '{{.State.Status}}' "$DOCKER_SERVICE" 2>/dev/null)

if [ "$CONTAINER_STATUS" = "running" ]; then
    print_pass "Container stable and running"
else
    print_fail "Container not running: $CONTAINER_STATUS"
fi

# Test 12: Response caching headers
print_test 12 "Caching headers"
CACHE_HEADERS=$(curl -I -s "${BASE_URL}/src/gui/assets/js/toast.js" | grep -i "cache-control\|expires\|etag")

if [ -n "$CACHE_HEADERS" ]; then
    print_pass "Caching headers present"
else
    print_skip "No caching headers (optional optimization)"
fi

# Test 13: Error page handling
print_test 13 "Error page handling"
ERROR_RESPONSE=$(http_get "/nonexistent-page" 5)
ERROR_CODE=$(extract_http_code "$ERROR_RESPONSE")

if [ "$ERROR_CODE" = "404" ]; then
    print_pass "404 error handled correctly"
else
    print_fail "Unexpected error code: $ERROR_CODE"
fi

# Test 14: Load test (100 requests)
print_test 14 "Load test (100 requests)"
START_TIME=$(date +%s.%N)
for i in {1..100}; do
    curl -s "${BASE_URL}/" > /dev/null
done
END_TIME=$(date +%s.%N)
LOAD_TIME=$(echo "$END_TIME - $START_TIME" | bc)
AVG_TIME=$(echo "$LOAD_TIME / 100" | bc -l)

if (( $(echo "$LOAD_TIME < 10.0" | bc -l) )); then
    print_pass "100 requests in ${LOAD_TIME}s (avg: $(printf '%.3f' "$AVG_TIME")s)"
else
    print_fail "Load test too slow: ${LOAD_TIME}s"
fi

# Test 15: Container restart recovery
print_test 15 "Container restart recovery"
if [ "${SKIP_RESTART_TEST:-0}" -eq 1 ]; then
    print_skip "Restart test skipped (set SKIP_RESTART_TEST=0 to enable)"
else
    # Restart container
    docker compose -f "$DOCKER_COMPOSE_FILE" restart "$DOCKER_SERVICE" > /dev/null 2>&1

    # Wait for container to be ready
    sleep 5

    # Check if it's running
    if check_docker_running; then
        # Try to load homepage
        RESTART_RESPONSE=$(http_get "/" 10)
        RESTART_CODE=$(extract_http_code "$RESTART_RESPONSE")

        if [ "$RESTART_CODE" = "200" ]; then
            print_pass "Container recovered after restart"
        else
            print_fail "Container not responding after restart"
        fi
    else
        print_fail "Container failed to restart"
    fi
fi

# Print summary
print_header "TEST SUMMARY"
TOTAL_TESTS=$((TESTS_PASSED + TESTS_FAILED + TESTS_SKIPPED))
TEST_END_TIME=$(date +%s)
DURATION=$((TEST_END_TIME - TEST_START_TIME))

echo "Total tests: $TOTAL_TESTS"
echo -e "Passed: ${GREEN}$TESTS_PASSED ✅${NC}"
echo -e "Failed: ${RED}$TESTS_FAILED ❌${NC}"
echo -e "Skipped: ${YELLOW}$TESTS_SKIPPED ⏭️${NC}"
echo "Duration: ${DURATION}s"
echo ""

# Save results to JSON
RESULTS_FILE="$(dirname "$0")/integration-test-results.json"
cat > "$RESULTS_FILE" << EOF
{
  "timestamp": "$(date -Iseconds)",
  "duration": $DURATION,
  "summary": {
    "total": $TOTAL_TESTS,
    "passed": $TESTS_PASSED,
    "failed": $TESTS_FAILED,
    "skipped": $TESTS_SKIPPED
  },
  "environment": {
    "base_url": "$BASE_URL",
    "docker_service": "$DOCKER_SERVICE",
    "compose_file": "$DOCKER_COMPOSE_FILE"
  }
}
EOF

echo "Results saved to: $RESULTS_FILE"

# Exit with appropriate code
if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed.${NC}"
    exit 1
fi
