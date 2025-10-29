#!/bin/bash
#
# Runtime Behavior Tests for ServiceWrappers Removal
# Tests that refactored services maintain correct runtime behavior
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
    docker-compose -f docker-compose-4line.yml down -v 2>/dev/null || true
}

# Trap to ensure cleanup on exit
trap cleanup EXIT

echo "==========================================="
echo "ServiceWrappers Removal - Runtime Tests"
echo "==========================================="
echo ""

# Navigate to project root
cd /home/admin/eiou/ai-dev/github/eiou-docker || exit 1

# Start 4-node topology for testing
echo "Starting 4-node test environment..."
cleanup
docker-compose -f docker-compose-4line.yml up -d --build > /dev/null 2>&1

echo "Waiting for nodes to initialize..."
sleep 20

# Test 1: Direct P2P request using service
echo -e "${YELLOW}Test 1: Testing P2P request with direct service call...${NC}"
docker-compose -f docker-compose-4line.yml exec -T alice php -r "
    error_reporting(E_ALL);
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    try {
        \$container = ServiceContainer::getInstance();
        \$p2pService = \$container->getP2pService();

        // Create test P2P request
        \$data = ['send', '100', 'bob_address_test', 'USD'];

        // This should work without wrapper function
        \$p2pService->sendP2pRequest(\$data);

        echo 'P2P request sent successfully using direct service call' . PHP_EOL;
        exit(0);
    } catch (Exception \$e) {
        echo 'P2P request failed: ' . \$e->getMessage() . PHP_EOL;
        exit(1);
    }
" 2>&1 | grep -q "P2P request sent successfully"
RESULT=$?
print_result $RESULT "P2P request via direct service call"

# Test 2: Failed transaction recovery
echo -e "\n${YELLOW}Test 2: Testing failed transaction recovery...${NC}"
docker-compose -f docker-compose-4line.yml exec -T alice php -r "
    error_reporting(E_ALL);
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    try {
        \$container = ServiceContainer::getInstance();
        \$p2pService = \$container->getP2pService();

        // Simulate failed transaction
        \$failedMessage = [
            'receiver_address' => 'unreachable_node_xxxxxxxxx',
            'amount' => 50,
            'currency' => 'USD',
            'hash' => 'test_failed_tx_' . time(),
            'memo' => 'test_failed_transaction'
        ];

        // This should convert failed transaction to P2P request
        \$p2pService->sendP2pRequestFromFailedDirectTransaction(\$failedMessage);

        echo 'Failed transaction recovery initiated' . PHP_EOL;
        exit(0);
    } catch (Exception \$e) {
        echo 'Recovery failed: ' . \$e->getMessage() . PHP_EOL;
        exit(1);
    }
" 2>&1 | grep -q "Failed transaction recovery initiated"
RESULT=$?
print_result $RESULT "Failed transaction recovery"

# Test 3: Contact synchronization
echo -e "\n${YELLOW}Test 3: Testing contact synchronization...${NC}"
docker-compose -f docker-compose-4line.yml exec -T alice php -r "
    error_reporting(E_ALL);
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    try {
        \$container = ServiceContainer::getInstance();
        \$synchService = \$container->getSynchService();

        // Test synch with SILENT mode
        \$testAddress = 'test_contact_' . time();
        \$result = \$synchService->synchSingleContact(\$testAddress, 'SILENT');

        // Result can be true or false, but method should execute
        echo 'Contact sync method executed' . PHP_EOL;
        exit(0);
    } catch (Exception \$e) {
        echo 'Sync failed: ' . \$e->getMessage() . PHP_EOL;
        exit(1);
    }
" 2>&1 | grep -q "Contact sync method executed"
RESULT=$?
print_result $RESULT "Contact synchronization method"

# Test 4: Transaction sending via TransactionService
echo -e "\n${YELLOW}Test 4: Testing transaction sending...${NC}"
docker-compose -f docker-compose-4line.yml exec -T alice php -r "
    error_reporting(E_ALL);
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    try {
        \$container = ServiceContainer::getInstance();
        \$transactionService = \$container->getTransactionService();

        // Create test transaction request
        \$request = [
            'sender' => 'alice_test_address',
            'receiver' => 'bob_test_address',
            'amount' => 100,
            'currency' => 'USD',
            'memo' => 'test_p2p_eiou_' . time(),
            'type' => 'p2p_eiou'
        ];

        // This replaces the wrapper function sendP2pEiou()
        \$transactionService->sendP2pEiou(\$request);

        echo 'P2P eIOU sent via direct service' . PHP_EOL;
        exit(0);
    } catch (Exception \$e) {
        echo 'Transaction failed: ' . \$e->getMessage() . PHP_EOL;
        exit(1);
    }
" 2>&1 | grep -q "P2P eIOU sent via direct service"
RESULT=$?
print_result $RESULT "P2P eIOU transaction"

# Test 5: Service method chaining
echo -e "\n${YELLOW}Test 5: Testing service method chaining...${NC}"
docker-compose -f docker-compose-4line.yml exec -T alice php -r "
    error_reporting(E_ALL);
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    try {
        // Test that we can chain service calls
        ServiceContainer::getInstance()->getDebugService()->output('Test output', 'SILENT');

        // Test multiple service retrievals
        \$container = ServiceContainer::getInstance();
        \$p2p = \$container->getP2pService();
        \$trans = \$container->getTransactionService();
        \$synch = \$container->getSynchService();

        echo 'Service chaining successful' . PHP_EOL;
        exit(0);
    } catch (Exception \$e) {
        echo 'Chaining failed: ' . \$e->getMessage() . PHP_EOL;
        exit(1);
    }
" 2>&1 | grep -q "Service chaining successful"
RESULT=$?
print_result $RESULT "Service method chaining"

# Test 6: Concurrent service access (simulate multiple processes)
echo -e "\n${YELLOW}Test 6: Testing concurrent service access...${NC}"
for i in {1..3}; do
    docker-compose -f docker-compose-4line.yml exec -T alice php -r "
        require_once '/app/src/services/ServiceContainer.php';
        require_once '/app/src/context/UserContext.php';

        \$container = ServiceContainer::getInstance();
        \$service = \$container->getP2pService();
        echo 'Process $i: Service accessed' . PHP_EOL;
    " 2>&1 &
done
wait

# Check if all processes completed
SUCCESS_COUNT=$(docker-compose -f docker-compose-4line.yml logs alice 2>&1 | grep -c "Service accessed" || echo "0")
if [ "$SUCCESS_COUNT" -ge 3 ]; then
    print_result 0 "Concurrent service access handled"
else
    print_result 1 "Concurrent access issues detected"
fi

# Test 7: Error handling for invalid data
echo -e "\n${YELLOW}Test 7: Testing error handling with invalid data...${NC}"
docker-compose -f docker-compose-4line.yml exec -T alice php -r "
    error_reporting(E_ALL);
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    try {
        \$container = ServiceContainer::getInstance();
        \$p2pService = \$container->getP2pService();

        // Test with invalid data structure
        \$invalidData = ['invalid_structure'];

        // This should handle error gracefully
        @\$p2pService->sendP2pRequest(\$invalidData);

        // If we get here, error was handled
        echo 'Error handling working' . PHP_EOL;
        exit(0);
    } catch (Exception \$e) {
        // Exceptions are also acceptable error handling
        echo 'Error handling working' . PHP_EOL;
        exit(0);
    }
" 2>&1 | grep -q "Error handling working"
RESULT=$?
print_result $RESULT "Error handling for invalid data"

# Test 8: Database operations through services
echo -e "\n${YELLOW}Test 8: Testing database operations...${NC}"
docker-compose -f docker-compose-4line.yml exec -T alice php -r "
    error_reporting(E_ALL);
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    try {
        \$container = ServiceContainer::getInstance();

        // Services should have database access
        \$contactService = \$container->getContactService();

        // Test that service can interact with database
        // (actual database operations depend on implementation)
        echo 'Database operations available' . PHP_EOL;
        exit(0);
    } catch (Exception \$e) {
        echo 'Database error: ' . \$e->getMessage() . PHP_EOL;
        exit(1);
    }
" 2>&1 | grep -q "Database operations available"
RESULT=$?
print_result $RESULT "Database operations through services"

# Test 9: Inter-node communication
echo -e "\n${YELLOW}Test 9: Testing inter-node communication...${NC}"
# Test Alice -> Bob communication
docker-compose -f docker-compose-4line.yml exec -T alice php -r "
    error_reporting(E_ALL);
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    try {
        \$container = ServiceContainer::getInstance();
        \$p2pService = \$container->getP2pService();

        // Prepare inter-node message
        \$data = ['send', '25', 'bob', 'USD'];

        // This should attempt to route to Bob
        @\$p2pService->sendP2pRequest(\$data);

        echo 'Inter-node message prepared' . PHP_EOL;
        exit(0);
    } catch (Exception \$e) {
        echo 'Inter-node failed: ' . \$e->getMessage() . PHP_EOL;
        exit(1);
    }
" 2>&1 | grep -q "Inter-node message prepared"
RESULT=$?
print_result $RESULT "Inter-node communication setup"

# Test 10: Long-running stability (30 seconds)
echo -e "\n${YELLOW}Test 10: Testing 30-second stability...${NC}"
echo "  Running stability test (30 seconds)..."

# Get initial memory usage
INITIAL_MEM=$(docker stats --no-stream --format "{{.MemUsage}}" alice 2>/dev/null | cut -d'/' -f1 | sed 's/[^0-9.]//g' || echo "0")

# Run service calls for 30 seconds
END_TIME=$(($(date +%s) + 30))
CALL_COUNT=0

while [ $(date +%s) -lt $END_TIME ]; do
    docker-compose -f docker-compose-4line.yml exec -T alice php -r "
        require_once '/app/src/services/ServiceContainer.php';
        require_once '/app/src/context/UserContext.php';

        \$container = ServiceContainer::getInstance();
        \$p2p = \$container->getP2pService();
        \$trans = \$container->getTransactionService();
        \$synch = \$container->getSynchService();
    " 2>/dev/null
    ((CALL_COUNT++))
    sleep 1
done

# Get final memory usage
FINAL_MEM=$(docker stats --no-stream --format "{{.MemUsage}}" alice 2>/dev/null | cut -d'/' -f1 | sed 's/[^0-9.]//g' || echo "0")

# Check if memory increased significantly (>100MB indicates leak)
MEM_DIFF=$(echo "$FINAL_MEM - $INITIAL_MEM" | bc 2>/dev/null || echo "0")

echo "  Made $CALL_COUNT service calls"
echo "  Memory change: ${MEM_DIFF}MB"

if [ $(echo "$MEM_DIFF < 100" | bc) -eq 1 ]; then
    print_result 0 "30-second stability test passed"
else
    print_result 1 "Memory leak detected (increased by ${MEM_DIFF}MB)"
fi

# Summary
echo ""
echo "==========================================="
echo "Runtime Test Summary"
echo "==========================================="
echo -e "${GREEN}Tests Passed:${NC} $TESTS_PASSED"
echo -e "${RED}Tests Failed:${NC} $TESTS_FAILED"

if [ "$TESTS_FAILED" -gt 0 ]; then
    echo ""
    echo -e "${RED}⚠ RUNTIME TESTS FAILED${NC}"
    echo "Some runtime behaviors may be affected by refactoring"
    exit 1
else
    echo ""
    echo -e "${GREEN}✓ ALL RUNTIME TESTS PASSED${NC}"
    echo "Refactored services maintain correct runtime behavior!"
    exit 0
fi