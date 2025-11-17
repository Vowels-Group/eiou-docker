#!/bin/sh

# Test transaction history recording and querying
echo -e "\nTesting transaction history and records..."

testname="transactionHistoryTest"
totaltests=0
passed=0
failure=0

# Test 1: Verify transactions are recorded
echo -e "\n[Transaction Recording Verification]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking transaction history for ${container}"

    # Query transaction count and basic info
    transactionInfo=$(docker exec ${container} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');

        // Get total count
        \$total = ServiceContainer::getInstance()->getTransactionRepository()->getTotalCountTransactions();

        // Get count by type
        \$types = ServiceContainer::getInstance()->getTransactionRepository()->getTransactionsTypeStatistics();

        echo 'Total:' . \$total . ' ';
        foreach (\$types as \$type) {
            echo \$type['type'] . ':' . \$type['count'] . ' ';
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$transactionInfo" != "ERROR" ]] && [[ "$transactionInfo" != "" ]]; then
        printf "\tTransaction history for %s: %s\n" ${container} "${transactionInfo}"

        # Extract total count (basic parsing)
        if [[ "$transactionInfo" =~ Total:([0-9]+) ]]; then
            totalTx="${BASH_REMATCH[1]}"
            if [[ "$totalTx" -gt "0" ]]; then
                printf "\tTransaction recording ${GREEN}PASSED${NC} (%s transactions)\n" ${totalTx}
                passed=$(( passed + 1 ))
            else
                printf "\tTransaction recording ${RED}FAILED${NC} (no transactions found)\n"
                failure=$(( failure + 1 ))
            fi
        else
            printf "\tTransaction parsing ${RED}FAILED${NC}\n"
            failure=$(( failure + 1 ))
        fi
    else
        printf "\tTransaction query for %s ${RED}FAILED${NC}\n" ${container}
        failure=$(( failure + 1 ))
    fi
done

# Test 2: Verify transaction fields are complete
echo -e "\n[Transaction Field Completeness Test]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking transaction field completeness for ${container}"

    # Query a sample transaction to check fields
    fieldCheck=$(docker exec ${container} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        \$transactions = ServiceContainer::getInstance()->getTransactionRepository()->getTransactions(1);
        \$transaction = \$transactions[0];
        if (\$transaction) {
            \$requiredFields = ['id', 'tx_type', 'type', 'status', 'sender_address', 'sender_public_key', 'receiver_address', 'receiver_public_key', 'amount', 'currency', 'timestamp', 'memo'];
            \$missingFields = [];

            foreach (\$requiredFields as \$field) {
                if (empty(\$transaction[\$field]) && \$transaction[\$field] !== '0') {
                    \$missingFields[] = \$field;
                }
            }

            if (empty(\$missingFields)) {
                echo 'COMPLETE';
            } else {
                echo 'MISSING:' . implode(',', \$missingFields);
            }
        } else {
            echo 'NO_TRANSACTIONS';
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$fieldCheck" == "COMPLETE" ]]; then
        printf "\tTransaction fields ${GREEN}COMPLETE${NC}\n"
        passed=$(( passed + 1 ))
    elif [[ "$fieldCheck" == "NO_TRANSACTIONS" ]]; then
        printf "\tNo transactions to verify ${NC}(skipped)${NC}\n"
        # Don't count as failure if no transactions exist yet
    elif [[ "$fieldCheck" =~ ^MISSING: ]]; then
        printf "\tTransaction fields ${RED}INCOMPLETE${NC} (%s)\n" "${fieldCheck}"
        failure=$(( failure + 1 ))
    else
        printf "\tField check ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
done

# Test 3: Verify send/receive transaction pairs
echo -e "\n[Transaction Pair Verification]"

# For each completed send, there should be a corresponding receive
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
testPair="${containersLinkKeys[0]}"  # Test with first link

if [[ "$testPair" ]]; then
    containerKeys=(${testPair//,/ })
    sender="${containerKeys[0]}"
    receiver="${containerKeys[1]}"

    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Creating test transaction pair: ${sender} -> ${receiver}"

    # Record current transaction counts
    senderBefore=$(docker exec ${sender} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        echo ServiceContainer::getInstance()->getTransactionRepository()->getTransactionsSpecificTypeCount('sent');
    " 2>/dev/null || echo "0")



    receiverBefore=$(docker exec ${receiver} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        echo ServiceContainer::getInstance()->getTransactionRepository()->getTransactionsSpecificTypeCount('received');
    " 2>/dev/null || echo "0")

    # Send test transaction
    docker exec ${sender} eiou send ${containerAddresses[${receiver}]} 2 USD 2>&1 > /dev/null

    # Wait for processing
    echo -e "\t   Waiting for 15 seconds for complete routing (faster but certainty)..."
    sleep 15

    # Check new counts
    senderAfter=$(docker exec ${sender} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        echo ServiceContainer::getInstance()->getTransactionRepository()->getTransactionsSpecificTypeCount('sent');
    " 2>/dev/null || echo "0")

    receiverAfter=$(docker exec ${receiver} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        echo ServiceContainer::getInstance()->getTransactionRepository()->getTransactionsSpecificTypeCount('received');
    " 2>/dev/null || echo "0")

    senderDiff=$(( senderAfter - senderBefore ))
    receiverDiff=$(( receiverAfter - receiverBefore ))

    if [[ "$senderDiff" -eq "1" ]] && [[ "$receiverDiff" -eq "1" ]]; then
        printf "\tTransaction pair recording ${GREEN}PASSED${NC} (send+receive recorded)\n"
        passed=$(( passed + 1 ))
    else
        printf "\tTransaction pair recording ${RED}FAILED${NC} (Send diff: %s, Receive diff: %s)\n" ${senderDiff} ${receiverDiff}
        failure=$(( failure + 1 ))
    fi
fi

# Test 4: Query transaction history with filters
echo -e "\n[Transaction Query Filter Test]"

for container in "${containers[@]:0:2}"; do  # Test first 2 containers
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Testing filtered queries for ${container}"

    # Test querying by type
    queryResult=$(docker exec ${container} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        \$sends = ServiceContainer::getInstance()->getTransactionRepository()->getTransactionsSpecificTypeStatistics('sent');
        \$receives = ServiceContainer::getInstance()->getTransactionRepository()->getTransactionsSpecificTypeStatistics('received');;
        echo 'Sends:' . \$sends['count'] . '/' . (\$sends['total'] ?: '0') . ' ';
        echo 'Receives:' . \$receives['count'] . '/' . (\$receives['total'] ?: '0');
    " 2>/dev/null || echo "ERROR")

    if [[ "$queryResult" != "ERROR" ]] && [[ "$queryResult" != "" ]]; then
        printf "\tFiltered query results: %s\n" "${queryResult}"
        printf "\tTransaction filtering ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\tTransaction filtering ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
done

# Test 5: Transaction timestamp ordering
echo -e "\n[Transaction Timestamp Test]"

for container in "${containers[@]:0:2}"; do  # Test first 2 containers
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking timestamp ordering for ${container}"

    timestampCheck=$(docker exec ${container} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');

        \$timestamps = ServiceContainer::getInstance()->getTransactionRepository()->getTimestampsTransactions(5);

        if (count(\$timestamps) > 1) {
            \$ordered = true;
            for (\$i = 1; \$i < count(\$timestamps); \$i++) {
                if (\$timestamps[\$i-1] < \$timestamps[\$i]) {
                    \$ordered = false;
                    break;
                }
            }
            echo \$ordered ? 'ORDERED' : 'NOT_ORDERED';
        } else {
            echo 'INSUFFICIENT_DATA';
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$timestampCheck" == "ORDERED" ]]; then
        printf "\tTimestamp ordering ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    elif [[ "$timestampCheck" == "INSUFFICIENT_DATA" ]]; then
        printf "\tInsufficient data for timestamp test ${NC}(skipped)${NC}\n"
        # Don't count as failure
    else
        printf "\tTimestamp ordering ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
done

succesrate "${totaltests}" "${passed}" "${failure}" "'transaction history'"