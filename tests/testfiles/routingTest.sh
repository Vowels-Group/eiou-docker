#!/bin/sh

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
                require_once('./etc/eiou/src/core/Application.php');
                \$balance = Application::getInstance()->services->getBalanceRepository()->getUserBalanceCurrency('USD');
                echo \$balance/Constants::TRANSACTION_USD_CONVERSION_FACTOR ?: '0';
            " 2>/dev/null || echo "0")
        done

        # Send test message requiring routing
        testAmount="3"
        routingResult=$(docker exec ${sender} eiou send ${containerAddresses[${receiver}]} ${testAmount} USD 2>&1)

        # Wait for transaction to process
        echo -e "\t   Waiting 20 seconds for routing process (faster but certainty)..."
        sleep 20

        # Check relay nodes received fees
        relayFeesDetected=0
        for relay in "${intermediates[@]}"; do
            newRelayBalance=$(docker exec ${relay} php -r "
                require_once('./etc/eiou/src/core/Application.php');
                \$balance = Application::getInstance()->services->getBalanceRepository()->getUserBalanceCurrency('USD');
                echo \$balance/Constants::TRANSACTION_USD_CONVERSION_FACTOR ?: '0';
            " 2>/dev/null || echo "0")

            balanceDiff=$(awk "BEGIN {print $newRelayBalance - ${initialRelayBalances[$relay]}}")
            balanceIncreased=$(awk "BEGIN {print ($balanceDiff > 0) ? 1 : 0}")

            if [[ "$balanceIncreased" -eq 1 ]]; then
                echo -e "\t   Relay ${relay} earned fee: ${balanceDiff} USD"
                relayFeesDetected=$(( relayFeesDetected + 1 ))
            fi
        done

        # Check if routing succeeded
        if [[ "$routingResult" =~ "Sending" ]] && [[ $relayFeesDetected -gt 0 ]]; then
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
        require_once('./etc/eiou/src/core/Application.php');
        \$results = Application::getInstance()->services->getTransactionRepository()->getTransactionsTypeStatistics();
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
            require_once('./etc/eiou/src/core/Application.php');
            echo Application::getInstance()->services->getTransactionRepository()->getTransactionsSpecificTypeCount('received');
        " 2>/dev/null || echo "0")

        # Send end-to-end message
        e2eAmount="8"
        e2eResult=$(docker exec ${firstContainer} eiou send ${containerAddresses[${lastContainer}]} ${e2eAmount} USD 2>&1)

        # Wait for multi-hop routing
        echo -e "\t   Waiting 20 seconds for multi-hop routing process (faster but certainty)..."
        sleep 20

        # Check if message arrived
        finalState=$(docker exec ${lastContainer} php -r "
            require_once('./etc/eiou/src/core/Application.php');
            echo Application::getInstance()->services->getTransactionRepository()->getTransactionsSpecificTypeCount('received');
        " 2>/dev/null || echo "0")

        stateIncreased=$(awk "BEGIN {print ($finalState > $initialState) ? 1 : 0}")
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