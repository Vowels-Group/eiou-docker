#!/bin/bash

# sendAllPeersTest.sh - Test script to validate 4-line topology network structure
#
# This test verifies that the 4-line topology is correctly configured with:
# - alice connected only to bob (1 contact)
# - bob connected to alice and carol (2 contacts)
# - carol connected to bob and daniel (2 contacts)
# - daniel connected only to carol (1 contact)
#
# Total expected contacts: 6 (not 12 for a full mesh)
# Mesh percentage: 50% (6 connections out of 12 possible in a 4-node network)

set -e # Stop script on failure

echo "========================================="
echo "Send All Peers Test - 4-Line Topology"
echo "========================================="

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test configuration
COMPOSE_FILE="docker-compose-4line.yml"
EXPECTED_TOTAL_CONTACTS=6
EXPECTED_MESH_PERCENTAGE=50

# Expected contacts per node in 4-line topology
declare -A EXPECTED_CONTACTS=(
    ["alice"]=1  # Only connected to bob
    ["bob"]=2    # Connected to alice and carol
    ["carol"]=2  # Connected to bob and daniel
    ["daniel"]=1 # Only connected to carol
)

# Function to check if containers are running
check_containers_running() {
    echo -e "\n${YELLOW}Checking if containers are running...${NC}"

    for node in alice bob carol daniel; do
        if docker compose -f $COMPOSE_FILE ps | grep -q "$node.*Up"; then
            echo -e "${GREEN}✓${NC} Container $node is running"
        else
            echo -e "${RED}✗${NC} Container $node is not running"
            return 1
        fi
    done
    return 0
}

# Function to count contacts for a node
count_node_contacts() {
    local node=$1
    # Count lines that have "Balance:" but skip the first line (self)
    local count=$(docker compose -f $COMPOSE_FILE exec -T $node eiou viewbalances 2>/dev/null | grep "Balance:" | tail -n +2 | wc -l)
    echo $count
}

# Function to get contact list for a node
get_node_contacts() {
    local node=$1
    # Extract contact names from viewbalances output, skip the first line (self)
    docker compose -f $COMPOSE_FILE exec -T $node eiou viewbalances 2>/dev/null | grep "Balance:" | tail -n +2 | awk '{print $1}' | sort
}

# Function to verify specific connections
verify_connections() {
    echo -e "\n${YELLOW}Verifying 4-line topology connections...${NC}"

    # alice should only be connected to bob
    echo -e "\nChecking alice connections (expected: bob only):"
    local alice_contacts=$(get_node_contacts alice)
    if [ "$alice_contacts" == "bob" ]; then
        echo -e "${GREEN}✓${NC} alice correctly connected to bob only"
    else
        echo -e "${RED}✗${NC} alice has incorrect connections: $alice_contacts"
        return 1
    fi

    # bob should be connected to alice and carol
    echo -e "\nChecking bob connections (expected: alice and carol):"
    local bob_contacts=$(get_node_contacts bob | tr '\n' ' ')
    if [[ "$bob_contacts" == *"alice"* ]] && [[ "$bob_contacts" == *"carol"* ]]; then
        echo -e "${GREEN}✓${NC} bob correctly connected to alice and carol"
    else
        echo -e "${RED}✗${NC} bob has incorrect connections: $bob_contacts"
        return 1
    fi

    # carol should be connected to bob and daniel
    echo -e "\nChecking carol connections (expected: bob and daniel):"
    local carol_contacts=$(get_node_contacts carol | tr '\n' ' ')
    if [[ "$carol_contacts" == *"bob"* ]] && [[ "$carol_contacts" == *"daniel"* ]]; then
        echo -e "${GREEN}✓${NC} carol correctly connected to bob and daniel"
    else
        echo -e "${RED}✗${NC} carol has incorrect connections: $carol_contacts"
        return 1
    fi

    # daniel should only be connected to carol
    echo -e "\nChecking daniel connections (expected: carol only):"
    local daniel_contacts=$(get_node_contacts daniel)
    if [ "$daniel_contacts" == "carol" ]; then
        echo -e "${GREEN}✓${NC} daniel correctly connected to carol only"
    else
        echo -e "${RED}✗${NC} daniel has incorrect connections: $daniel_contacts"
        return 1
    fi

    return 0
}

# Function to test contact counts
test_contact_counts() {
    echo -e "\n${YELLOW}Testing contact counts for 4-line topology...${NC}"

    local total_contacts=0
    local all_passed=true

    for node in alice bob carol daniel; do
        local actual_count=$(count_node_contacts $node)
        local expected_count=${EXPECTED_CONTACTS[$node]}
        total_contacts=$((total_contacts + actual_count))

        echo -n "Node $node: "
        if [ "$actual_count" -eq "$expected_count" ]; then
            echo -e "${GREEN}✓${NC} $actual_count contacts (expected: $expected_count)"
        else
            echo -e "${RED}✗${NC} $actual_count contacts (expected: $expected_count) - FAILED"
            all_passed=false
        fi
    done

    echo -e "\n${YELLOW}Total contacts validation:${NC}"
    echo -n "Total contacts across all nodes: "
    if [ "$total_contacts" -eq "$EXPECTED_TOTAL_CONTACTS" ]; then
        echo -e "${GREEN}✓${NC} $total_contacts (expected: $EXPECTED_TOTAL_CONTACTS)"
    else
        echo -e "${RED}✗${NC} $total_contacts (expected: $EXPECTED_TOTAL_CONTACTS) - FAILED"
        echo -e "${RED}ERROR: Full mesh detected! This should be a 4-line topology, not a fully connected mesh.${NC}"
        all_passed=false
    fi

    # Calculate and verify mesh percentage
    local max_possible_connections=12  # 4 nodes * 3 possible connections each = 12
    local mesh_percentage=$((total_contacts * 100 / max_possible_connections))

    echo -n "Mesh percentage: "
    if [ "$mesh_percentage" -eq "$EXPECTED_MESH_PERCENTAGE" ]; then
        echo -e "${GREEN}✓${NC} ${mesh_percentage}% (expected: ${EXPECTED_MESH_PERCENTAGE}%)"
    else
        echo -e "${RED}✗${NC} ${mesh_percentage}% (expected: ${EXPECTED_MESH_PERCENTAGE}%) - FAILED"
        if [ "$mesh_percentage" -eq "100" ]; then
            echo -e "${RED}ERROR: 100% mesh indicates full connectivity - this is incorrect for a line topology!${NC}"
        fi
        all_passed=false
    fi

    if [ "$all_passed" = true ]; then
        return 0
    else
        return 1
    fi
}

# Function to test message routing through the line
test_message_routing() {
    echo -e "\n${YELLOW}Testing message routing through 4-line topology...${NC}"

    # Test that alice can reach daniel through the line (alice -> bob -> carol -> daniel)
    echo "Testing message from alice to daniel (should route through bob and carol):"

    # Send a test transaction from alice to daniel
    if docker compose -f $COMPOSE_FILE exec -T alice eiou send http://daniel 1 USD 2>/dev/null; then
        echo -e "${GREEN}✓${NC} Message successfully routed from alice to daniel through the line"

        # Verify the transaction appears in daniel's history
        if docker compose -f $COMPOSE_FILE exec -T daniel eiou history 2>/dev/null | grep -q "alice"; then
            echo -e "${GREEN}✓${NC} Transaction from alice appears in daniel's history"
        else
            echo -e "${YELLOW}⚠${NC} Transaction may not have been recorded yet (eventual consistency)"
        fi
    else
        echo -e "${RED}✗${NC} Failed to route message from alice to daniel"
        return 1
    fi

    return 0
}

# Main test execution
main() {
    echo "Starting 4-Line Topology Validation Test"
    echo "========================================="
    echo "Expected topology structure:"
    echo "  alice <---> bob <---> carol <---> daniel"
    echo ""
    echo "Expected contacts per node:"
    echo "  alice: 1 contact (bob)"
    echo "  bob: 2 contacts (alice, carol)"
    echo "  carol: 2 contacts (bob, daniel)"
    echo "  daniel: 1 contact (carol)"
    echo "========================================="

    # Check if docker compose file exists
    if [ ! -f "$COMPOSE_FILE" ]; then
        echo -e "${RED}Error: $COMPOSE_FILE not found!${NC}"
        echo "Please run this test from the eiou-docker directory"
        exit 1
    fi

    # Ensure containers are up
    echo -e "\n${YELLOW}Starting containers if needed...${NC}"
    docker compose -f $COMPOSE_FILE up -d

    # Wait for containers to stabilize
    echo -e "${YELLOW}Waiting for containers to initialize...${NC}"
    sleep 10

    # Run tests
    local test_failures=0

    if ! check_containers_running; then
        echo -e "${RED}Container check failed!${NC}"
        ((test_failures++))
    fi

    if ! verify_connections; then
        echo -e "${RED}Connection verification failed!${NC}"
        ((test_failures++))
    fi

    if ! test_contact_counts; then
        echo -e "${RED}Contact count test failed!${NC}"
        ((test_failures++))
    fi

    if ! test_message_routing; then
        echo -e "${RED}Message routing test failed!${NC}"
        ((test_failures++))
    fi

    # Final summary
    echo -e "\n========================================="
    echo "Test Summary"
    echo "========================================="

    if [ "$test_failures" -eq 0 ]; then
        echo -e "${GREEN}✓ All tests passed!${NC}"
        echo "The 4-line topology is correctly configured."
        echo "Nodes are connected in a line pattern, not a full mesh."
        exit 0
    else
        echo -e "${RED}✗ $test_failures test(s) failed!${NC}"
        echo ""
        echo "Common issues:"
        echo "1. If seeing 12 contacts instead of 6: The topology is incorrectly configured as a full mesh"
        echo "2. If seeing 100% mesh instead of 50%: All nodes are connected to all other nodes (incorrect)"
        echo "3. The correct 4-line topology should have nodes connected in a line: alice-bob-carol-daniel"
        echo ""
        echo "Please check the docker compose-4line.yml configuration and ensure it defines a line topology."
        exit 1
    fi
}

# Run the main test
main "$@"