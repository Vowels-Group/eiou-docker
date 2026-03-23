#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Routing Test ############################
# Tests multi-hop message routing and relay fee calculation
#
# Verifies:
# - Messages route through intermediate nodes correctly
# - Relay fees are calculated and applied properly
# - Routing paths match expected topology
#
# Prerequisites:
# - Containers must be running with routingTests array defined
# - Contacts must be established (run addContactsTest first)
# - Network topology must support multi-hop routes
####################################################################

# Test multi-hop message routing and relay fees
echo -e "\nTesting multi-hop routing and relay fees..."

testname="routingTest"
totaltests=0
passed=0
failure=0

# Function to check if containers are indirectly connected (multi-hop)
needsRouting() {
    local from=$1
    local to=$2
    # Check if direct connection exists
    if [[ "${containersLinks[$from,$to]}" ]]; then
        echo "false"
    else
        echo "true"
    fi
}

# Test 1: Verify routing paths for non-adjacent containers
echo -e "\n[Routing Path Verification]"

for routingPair in "${!routingTests[@]}"; do
    containerPair=(${routingPair//,/ })
    sender="${containerPair[0]}"
    receiver="${containerPair[1]}"
    expectedPath="${routingTests[$routingPair]}"

    # Check if both containers exist in current topology
    if [[ "${containerAddresses[$sender]}" ]] && [[ "${containerAddresses[$receiver]}" ]]; then
        totaltests=$(( totaltests + 1 ))

        echo -e "\n\t-> Testing route from ${sender} to ${receiver}"
        echo -e "\t   Expected path through: ${expectedPath}"

        # Get initial balance of intermediate nodes to check relay fees
        intermediates=(${expectedPath//,/ })
        declare -A initialRelayBalances
        for relay in "${intermediates[@]}"; do
            
            initialRelayBalances[$relay]=$(docker exec ${relay} php -r "
                require_once('${BOOTSTRAP_PATH}');
                \$balance = \Eiou\Core\Application::getInstance()->services->getRepositoryFactory()->get(\Eiou\Database\BalanceRepository::class)->getUserBalanceCurrency('USD');
                echo \$balance->toMajorUnits() ?: '0';
            " 2>/dev/null || echo "0")
        done

        # Send test message requiring routing
        testAmount="3"
        routingResult=$(docker exec ${sender} eiou send ${containerAddresses[${receiver}]} ${testAmount} USD 2>&1)

        # Wait for routing with polling - check first relay node for balance change
        echo -e "\t   Waiting for routing (timeout: 20s)..."
        if [[ "${intermediates[0]}" ]]; then
            firstRelay="${intermediates[0]}"
            relay_balance_cmd="php -r \"
                require_once('${BOOTSTRAP_PATH}');
                \\\$balance = \Eiou\Core\Application::getInstance()->services->getRepositoryFactory()->get(\Eiou\Database\BalanceRepository::class)->getUserBalanceCurrency('USD');
                echo \\\$balance->toMajorUnits() ?: '0';
            \""
            wait_for_balance_change "$firstRelay" "${initialRelayBalances[$firstRelay]}" "$relay_balance_cmd" 20 "relay fee" > /dev/null 2>&1 || true
        fi

        # Check relay nodes received fees
        relayFeesDetected=0
        for relay in "${intermediates[@]}"; do
            newRelayBalance=$(docker exec ${relay} php -r "
                require_once('${BOOTSTRAP_PATH}');
                \$balance = \Eiou\Core\Application::getInstance()->services->getRepositoryFactory()->get(\Eiou\Database\BalanceRepository::class)->getUserBalanceCurrency('USD');
                echo \$balance->toMajorUnits() ?: '0';
            " 2>/dev/null || echo "0")

            balanceDiff=$(awk "BEGIN {print $newRelayBalance - ${initialRelayBalances[$relay]}}")
            balanceIncreased=$(awk "BEGIN {print ($balanceDiff > 0) ? 1 : 0}")

            if [[ "$balanceIncreased" -eq 1 ]]; then
                echo -e "\t   Relay ${relay} earned fee: ${balanceDiff} USD"
                relayFeesDetected=$(( relayFeesDetected + 1 ))
            fi
        done

        # Retry if no relay fees detected - wait for daemon processing
        if [[ $relayFeesDetected -eq 0 ]]; then
            echo -e "\t   No relay fees detected, waiting for daemon processing..."
            sleep 10

            # Re-check relay fees after retry
            for relay in "${intermediates[@]}"; do
                newRelayBalance=$(docker exec ${relay} php -r "
                    require_once('${BOOTSTRAP_PATH}');
                    \$balance = \Eiou\Core\Application::getInstance()->services->getRepositoryFactory()->get(\Eiou\Database\BalanceRepository::class)->getUserBalanceCurrency('USD');
                    echo \$balance->toMajorUnits() ?: '0';
                " 2>/dev/null || echo "0")

                balanceDiff=$(awk "BEGIN {print $newRelayBalance - ${initialRelayBalances[$relay]}}")
                balanceIncreased=$(awk "BEGIN {print ($balanceDiff > 0) ? 1 : 0}")

                if [[ "$balanceIncreased" -eq 1 ]]; then
                    echo -e "\t   Relay ${relay} earned fee: ${balanceDiff} USD (after retry)"
                    relayFeesDetected=$(( relayFeesDetected + 1 ))
                fi
            done
        fi

        # Check if routing succeeded
        if [[ "$routingResult" =~ "Searching for route via P2P network" ]] && [[ $relayFeesDetected -gt 0 ]]; then
            printf "\t   Routing %s -> %s ${GREEN}PASSED${NC} (%d relays detected fees)\n" ${sender} ${receiver} ${relayFeesDetected}
            passed=$(( passed + 1 ))
        else
            printf "\t   Routing %s -> %s ${RED}FAILED${NC}\n" ${sender} ${receiver}
            printf "\t   Result: %s\n" "${routingResult:0:100}"
            failure=$(( failure + 1 ))
        fi
    fi
done

# Test 2: Verify transaction types in database
echo -e "\n[Transaction Type Verification]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking transaction types for ${container}"

    # Query transaction history and check for relay transactions
    transactionTypes=$(docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$results = \Eiou\Core\Application::getInstance()->services->getRepositoryFactory()->get(\Eiou\Database\TransactionStatisticsRepository::class)->getTypeStatistics();
        foreach (\$results as \$row) {
            echo \$row['type'] . ': ' . \$row['count'] . ', ';
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$transactionTypes" != "ERROR" ]] && [[ "$transactionTypes" != "" ]]; then
        printf "\tTransaction types for %s: %s\n" ${container} "${transactionTypes}"

        # Check if we have different transaction types (send/receive/relay)
        if [[ "$transactionTypes" =~ "sent" ]] || [[ "$transactionTypes" =~ "received" ]] || [[ "$transactionTypes" =~ "relay" ]]; then
            printf "\t   Transaction type tracking ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   Transaction type tracking ${RED}FAILED${NC} (no typed transactions found)\n"
            failure=$(( failure + 1 ))
        fi
    else
        printf "\t   Transaction type query for %s ${RED}FAILED${NC}\n" ${container}
        failure=$(( failure + 1 ))
    fi
done

# Test 3: End-to-end delivery verification
echo -e "\n[End-to-End Delivery Test]"

# Test longest path possible in the topology
if [[ "${containers[0]}" ]] && [[ "${containers[-1]}" ]]; then
    firstContainer="${containers[0]}"
    lastContainer="${containers[-1]}"

    if [[ "$firstContainer" != "$lastContainer" ]]; then
        totaltests=$(( totaltests + 1 ))

        echo -e "\n\t-> Testing end-to-end: ${firstContainer} to ${lastContainer}"

        # Get initial message count or balance
        initialState=$(docker exec ${lastContainer} php -r "
            require_once('${BOOTSTRAP_PATH}');
            echo \Eiou\Core\Application::getInstance()->services->getRepositoryFactory()->get(\Eiou\Database\TransactionStatisticsRepository::class)->getCountByType('received');
        " 2>/dev/null || echo "0")

        # Send end-to-end message
        e2eAmount="8"
        e2eResult=$(docker exec ${firstContainer} eiou send ${containerAddresses[${lastContainer}]} ${e2eAmount} USD 2>&1)

        # Wait for multi-hop routing with polling
        echo -e "\t   Waiting for multi-hop routing (timeout: 30s)..."
        tx_count_cmd="php -r \"
            require_once('${BOOTSTRAP_PATH}');
            echo \Eiou\Core\Application::getInstance()->services->getRepositoryFactory()->get(\Eiou\Database\TransactionStatisticsRepository::class)->getCountByType('received');
        \""
        wait_for_condition "[ \"\$(docker exec ${lastContainer} sh -c '$tx_count_cmd' 2>/dev/null)\" -gt \"$initialState\" ]" 30 1 "multi-hop delivery"

        # Check if message arrived
        finalState=$(docker exec ${lastContainer} php -r "
            require_once('${BOOTSTRAP_PATH}');
            echo \Eiou\Core\Application::getInstance()->services->getRepositoryFactory()->get(\Eiou\Database\TransactionStatisticsRepository::class)->getCountByType('received');
        " 2>/dev/null || echo "0")

        stateIncreased=$(awk "BEGIN {print ($finalState > $initialState) ? 1 : 0}")

        # Retry if delivery not detected - wait for daemon processing
        if [[ "$stateIncreased" -eq 0 ]]; then
            echo -e "\t   Delivery not detected, waiting for daemon processing..."
            sleep 10

            # Re-check delivery after retry
            finalState=$(docker exec ${lastContainer} php -r "
                require_once('${BOOTSTRAP_PATH}');
                echo \Eiou\Core\Application::getInstance()->services->getRepositoryFactory()->get(\Eiou\Database\TransactionStatisticsRepository::class)->getCountByType('received');
            " 2>/dev/null || echo "0")
            stateIncreased=$(awk "BEGIN {print ($finalState > $initialState) ? 1 : 0}")
        fi

        if [[ "$stateIncreased" -eq 1 ]]; then
            printf "\t   End-to-end delivery ${GREEN}PASSED${NC} (${firstContainer} -> ${lastContainer})\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   End-to-end delivery ${RED}FAILED${NC}\n"
            printf "\t   Result: %s\n" "${e2eResult:0:100}"
            failure=$(( failure + 1 ))
        fi
    fi
fi

succesrate "${totaltests}" "${passed}" "${failure}" "'routing and relay'"