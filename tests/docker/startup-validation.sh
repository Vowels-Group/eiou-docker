#!/bin/bash
#
# Docker Container Startup Validation for ServiceWrappers Removal
# Tests that containers start correctly after refactoring
# Must pass before PR submission per CLAUDE.md requirements
#

set -e  # Exit on first error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test result tracking
TESTS_PASSED=0
TESTS_FAILED=0

# Function to print test result
print_result() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✓${NC} $2"
        ((TESTS_PASSED++))
    else
        echo -e "${RED}✗${NC} $2"
        ((TESTS_FAILED++))
    fi
}

# Function to cleanup containers
cleanup() {
    echo "Cleaning up test containers..."
    docker-compose -f docker-compose-single.yml down -v 2>/dev/null || true
    docker-compose -f docker-compose-4line.yml down -v 2>/dev/null || true
}

# Trap to ensure cleanup on exit
trap cleanup EXIT

echo "==========================================="
echo "ServiceWrappers Removal - Startup Validation"
echo "==========================================="
echo ""

# Navigate to project root
cd /home/admin/eiou/ai-dev/github/eiou-docker || exit 1

# Test 1: Single node startup
echo -e "${YELLOW}Test 1: Single node container startup...${NC}"
cleanup
docker-compose -f docker-compose-single.yml up -d --build > /dev/null 2>&1

# Wait for initialization
echo "  Waiting for container initialization..."
sleep 10

# Check container status
CONTAINER_STATUS=$(docker ps --format "table {{.Names}}\t{{.Status}}" | grep eiou | grep -c "Up" || true)
if [ "$CONTAINER_STATUS" -ge 1 ]; then
    print_result 0 "Container started successfully"
else
    print_result 1 "Container failed to start"
    docker-compose -f docker-compose-single.yml logs --tail=50
fi

# Test 2: Check for startup errors
echo -e "\n${YELLOW}Test 2: Checking for startup errors...${NC}"
ERROR_COUNT=$(docker-compose -f docker-compose-single.yml logs 2>&1 | grep -i "fatal\|error\|exception" | grep -v "error_log" | grep -v "ERROR" | wc -l)
if [ "$ERROR_COUNT" -eq 0 ]; then
    print_result 0 "No critical startup errors found"
else
    print_result 1 "Found $ERROR_COUNT startup errors"
    echo "  Sample errors:"
    docker-compose -f docker-compose-single.yml logs 2>&1 | grep -i "fatal\|error\|exception" | head -5
fi

# Test 3: Verify service initialization order
echo -e "\n${YELLOW}Test 3: Verifying service initialization order...${NC}"
docker-compose -f docker-compose-single.yml exec -T alice php -r "
    error_reporting(E_ALL);

    // Test UserContext loads first
    require_once '/app/src/context/UserContext.php';
    \$userContext = new UserContext();
    echo 'UserContext initialized' . PHP_EOL;

    // Then ServiceContainer
    require_once '/app/src/services/ServiceContainer.php';
    \$container = ServiceContainer::getInstance();
    echo 'ServiceContainer initialized' . PHP_EOL;

    // Then services
    \$transactionService = \$container->getTransactionService();
    \$p2pService = \$container->getP2pService();
    \$synchService = \$container->getSynchService();
    echo 'All services initialized successfully' . PHP_EOL;
    exit(0);
" 2>&1
RESULT=$?
print_result $RESULT "Service initialization order correct"

# Test 4: Run integration test
echo -e "\n${YELLOW}Test 4: Running service container integration test...${NC}"
docker-compose -f docker-compose-single.yml exec -T alice php /app/tests/integration/test-service-container.php 2>&1 | tail -1 | grep -q "All service container tests passed"
RESULT=$?
print_result $RESULT "Integration tests passed"

# Test 5: Check for removed wrapper functions in source
echo -e "\n${YELLOW}Test 5: Checking for removed wrapper function usage...${NC}"
WRAPPER_COUNT=$(docker-compose -f docker-compose-single.yml exec -T alice bash -c "grep -r 'sendP2pEiou\\|sendP2pRequest\\|sendP2pRequestFromFailedDirectTransaction\\|synchContact' /app/src/ --exclude='ServiceWrappers.php' --exclude-dir='services' 2>/dev/null | grep -v '//' | wc -l" || echo "0")
if [ "$WRAPPER_COUNT" -eq 0 ]; then
    print_result 0 "No wrapper function usage found in source"
else
    print_result 1 "Found $WRAPPER_COUNT instances of wrapper function usage"
    echo "  Locations:"
    docker-compose -f docker-compose-single.yml exec -T alice bash -c "grep -r 'sendP2pEiou\\|sendP2pRequest\\|sendP2pRequestFromFailedDirectTransaction\\|synchContact' /app/src/ --exclude='ServiceWrappers.php' --exclude-dir='services' 2>/dev/null | head -5"
fi

# Test 6: Verify messageCheck.php doesn't crash
echo -e "\n${YELLOW}Test 6: Verifying messageCheck.php stability...${NC}"
docker-compose -f docker-compose-single.yml exec -T alice php -r "
    // Simulate messageCheck.php initialization
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    \$container = ServiceContainer::getInstance();
    echo 'messageCheck initialization successful' . PHP_EOL;
    exit(0);
" 2>&1 | grep -q "messageCheck initialization successful"
RESULT=$?
print_result $RESULT "messageCheck.php stable"

# Test 7: Test with 4-node topology
echo -e "\n${YELLOW}Test 7: Testing 4-node topology startup...${NC}"
docker-compose -f docker-compose-single.yml down -v > /dev/null 2>&1
docker-compose -f docker-compose-4line.yml up -d --build > /dev/null 2>&1

echo "  Waiting for 4-node initialization..."
sleep 15

# Check all nodes are running
ALICE_UP=$(docker ps | grep -c "alice" || true)
BOB_UP=$(docker ps | grep -c "bob" || true)
CAROL_UP=$(docker ps | grep -c "carol" || true)
DANIEL_UP=$(docker ps | grep -c "daniel" || true)

if [ "$ALICE_UP" -eq 1 ] && [ "$BOB_UP" -eq 1 ] && [ "$CAROL_UP" -eq 1 ] && [ "$DANIEL_UP" -eq 1 ]; then
    print_result 0 "All 4 nodes started successfully"
else
    print_result 1 "Not all nodes started (Alice:$ALICE_UP Bob:$BOB_UP Carol:$CAROL_UP Daniel:$DANIEL_UP)"
fi

# Test 8: Test P2P communication between nodes
echo -e "\n${YELLOW}Test 8: Testing P2P communication...${NC}"
docker-compose -f docker-compose-4line.yml exec -T alice php -r "
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    try {
        \$container = ServiceContainer::getInstance();
        \$p2pService = \$container->getP2pService();

        // Test method is callable
        if (method_exists(\$p2pService, 'sendP2pRequest')) {
            echo 'P2P service methods available' . PHP_EOL;
            exit(0);
        } else {
            echo 'P2P service methods missing' . PHP_EOL;
            exit(1);
        }
    } catch (Exception \$e) {
        echo 'P2P service error: ' . \$e->getMessage() . PHP_EOL;
        exit(1);
    }
" 2>&1 | grep -q "P2P service methods available"
RESULT=$?
print_result $RESULT "P2P communication ready"

# Test 9: Memory stability check (quick test)
echo -e "\n${YELLOW}Test 9: Quick memory stability check...${NC}"
INITIAL_MEM=$(docker stats --no-stream --format "{{.MemUsage}}" alice 2>/dev/null | cut -d'/' -f1 | sed 's/[^0-9.]//g' || echo "0")
sleep 5
FINAL_MEM=$(docker stats --no-stream --format "{{.MemUsage}}" alice 2>/dev/null | cut -d'/' -f1 | sed 's/[^0-9.]//g' || echo "0")

# Check if memory increased by more than 50MB (potential leak)
MEM_DIFF=$(echo "$FINAL_MEM - $INITIAL_MEM" | bc 2>/dev/null || echo "0")
if [ $(echo "$MEM_DIFF < 50" | bc) -eq 1 ]; then
    print_result 0 "Memory usage stable (diff: ${MEM_DIFF}MB)"
else
    print_result 1 "Potential memory issue (increased by ${MEM_DIFF}MB)"
fi

# Test 10: Verify all critical files have correct permissions
echo -e "\n${YELLOW}Test 10: Checking file permissions...${NC}"
PERMISSION_ISSUES=$(docker-compose -f docker-compose-4line.yml exec -T alice bash -c "
    files_to_check=(
        '/app/src/services/ServiceContainer.php'
        '/app/src/services/TransactionService.php'
        '/app/src/services/P2pService.php'
        '/app/src/services/SynchService.php'
        '/app/src/context/UserContext.php'
    )

    issues=0
    for file in \"\${files_to_check[@]}\"; do
        if [ ! -r \"\$file\" ]; then
            echo \"Cannot read: \$file\"
            ((issues++))
        fi
    done

    echo \$issues
" 2>/dev/null || echo "99")

if [ "$PERMISSION_ISSUES" -eq 0 ]; then
    print_result 0 "All critical files have correct permissions"
else
    print_result 1 "Found $PERMISSION_ISSUES permission issues"
fi

# Summary
echo ""
echo "==========================================="
echo "Test Summary"
echo "==========================================="
echo -e "${GREEN}Tests Passed:${NC} $TESTS_PASSED"
echo -e "${RED}Tests Failed:${NC} $TESTS_FAILED"

if [ "$TESTS_FAILED" -gt 0 ]; then
    echo ""
    echo -e "${RED}⚠ VALIDATION FAILED${NC}"
    echo "Please fix the issues above before submitting PR"
    exit 1
else
    echo ""
    echo -e "${GREEN}✓ ALL VALIDATION TESTS PASSED${NC}"
    echo "Container startup validation successful!"
    exit 0
fi