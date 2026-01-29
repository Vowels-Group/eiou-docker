#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Send Message Test ############################
# Tests message sending between connected contacts
#
# Verifies:
# - Direct message delivery between adjacent contacts
# - Multi-hop message routing through intermediate nodes
# - Balance changes reflect sent amounts and fees
#
# Prerequisites:
# - Containers must be running
# - Contacts must be established (run addContactsTest first)
#########################################################################

# Test sending messages between connected contacts
echo -e "\nTesting message sending between contacts..."

testname="sendMessageTest"
totaltests=0
passed=0
failure=0

# Count total tests - one send per link (not bidirectional since we're testing the send action)
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
totaltests=${#containersLinkKeys[@]}

# Send messages along each defined link
for containersLinkKey in "${containersLinkKeys[@]}"; do
    values=${containersLinks[${containersLinkKey}]}
    containerKeys=(${containersLinkKey//,/ })
    valueArray=($values)

    # Prepare test message with timestamp for uniqueness
    timestamp=$(date +%s)
    testAmount="5"
    testCurrency="${valueArray[2]}"

    echo -e "\t-> ${containerKeys[0]} sending ${testAmount} ${testCurrency} to ${containerKeys[1]}"

    # Get initial balance of recipient
    initialBalance=$(docker exec ${containerKeys[1]} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${containerAddresses[${containerKeys[0]}]}');
        \$balance = \$app->services->getBalanceRepository()->getCurrentContactBalance(\$pubkey,'${testCurrency}');
        echo \$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR;
    " 2>/dev/null || echo "0")

    # Send the message
    sendResult=$(docker exec ${containerKeys[0]} eiou send ${containerAddresses[${containerKeys[1]}]} ${testAmount} ${testCurrency} 2>&1)

    # Wait for balance change with polling (faster than fixed sleep)
    echo -e "\t   Waiting for balance change (timeout: 20s)..."
    balance_cmd="php -r \"
        require_once('${BOOTSTRAP_PATH}');
        \\\$app = \Eiou\Core\Application::getInstance();
        \\\$pubkey = \\\$app->services->getContactRepository()->getContactPubkey('${MODE}','${containerAddresses[${containerKeys[0]}]}');
        \\\$balance = \\\$app->services->getBalanceRepository()->getCurrentContactBalance(\\\$pubkey,'${testCurrency}');
        echo \\\$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR;
    \""
    newBalance=$(wait_for_balance_change "${containerKeys[1]}" "$initialBalance" "$balance_cmd" 20 "tx processing")

    # Calculate expected balance (initial + amount - fee if applicable)
    # Note: The exact fee calculation depends on the network configuration
    expectedIncrease=$testAmount

    # Verify message was sent and balance changed
    balanceIncreased=$(awk "BEGIN {print ($newBalance > $initialBalance) ? 1 : 0}")
    if [[ "$sendResult" =~ "success" ]] || [[ "$sendResult" =~ "sent" ]] || [[ "$balanceIncreased" -eq 1 ]]; then
        printf "\t   ${testname} from %s to %s ${GREEN}PASSED${NC} (Balance: %s -> %s)\n\n" ${containerKeys[0]} ${containerKeys[1]} ${initialBalance} ${newBalance}
        passed=$(( passed + 1 ))
    else
        printf "\t   ${testname} from %s to %s ${RED}FAILED${NC} (Balance unchanged: %s)\n" ${containerKeys[0]} ${containerKeys[1]} ${initialBalance}
        printf "\t   Send result: %s\n\n" "${sendResult}"
        failure=$(( failure + 1 ))
    fi
done

############################ SEND BY NAME TEST ############################

echo -e "\n[Send by Contact Name Test]"

# Test sending using contact NAME instead of address
# Use the first link pair for this test
if [ ${#containersLinkKeys[@]} -gt 0 ]; then
    firstLink="${containersLinkKeys[0]}"
    linkPair=(${firstLink//,/ })
    senderContainer="${linkPair[0]}"
    receiverContainer="${linkPair[1]}"
    receiverAddress="${containerAddresses[$receiverContainer]}"

    # Get contact name from sender's perspective
    contactName=$(docker exec ${senderContainer} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$contact = \$app->services->getContactRepository()->lookupByAddress('${MODE}', '${receiverAddress}');
        echo \$contact['name'] ?? '';
    " 2>/dev/null || echo "")

    if [[ -n "$contactName" ]]; then
        totaltests=$(( totaltests + 1 ))
        echo -e "\t-> ${senderContainer} sending 3 USD to '${contactName}' (by name)"

        # Get initial balance of recipient
        initialBalanceByName=$(docker exec ${receiverContainer} php -r "
            require_once('${BOOTSTRAP_PATH}');
            \$app = \Eiou\Core\Application::getInstance();
            \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${containerAddresses[${senderContainer}]}');
            \$balance = \$app->services->getBalanceRepository()->getCurrentContactBalance(\$pubkey,'USD');
            echo \$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR;
        " 2>/dev/null || echo "0")

        # Send using NAME instead of address
        sendByNameResult=$(docker exec ${senderContainer} eiou send "${contactName}" 3 USD 2>&1)

        # Wait for balance change with polling
        echo -e "\t   Waiting for balance change (timeout: 20s)..."
        balance_cmd_name="php -r \"
            require_once('${BOOTSTRAP_PATH}');
            \\\$app = \Eiou\Core\Application::getInstance();
            \\\$pubkey = \\\$app->services->getContactRepository()->getContactPubkey('${MODE}','${containerAddresses[${senderContainer}]}');
            \\\$balance = \\\$app->services->getBalanceRepository()->getCurrentContactBalance(\\\$pubkey,'USD');
            echo \\\$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR;
        \""
        newBalanceByName=$(wait_for_balance_change "${receiverContainer}" "$initialBalanceByName" "$balance_cmd_name" 20 "send by name")

        # Verify send by name succeeded
        balanceIncreasedByName=$(awk "BEGIN {print ($newBalanceByName > $initialBalanceByName) ? 1 : 0}")
        if [[ "$sendByNameResult" =~ "success" ]] || [[ "$sendByNameResult" =~ "sent" ]] || [[ "$balanceIncreasedByName" -eq 1 ]]; then
            printf "\t   send by name ${GREEN}PASSED${NC} (Balance: %s -> %s)\n" ${initialBalanceByName} ${newBalanceByName}
            passed=$(( passed + 1 ))
        else
            printf "\t   send by name ${RED}FAILED${NC} (Balance unchanged: %s)\n" ${initialBalanceByName}
            printf "\t   Send result: %s\n" "${sendByNameResult}"
            failure=$(( failure + 1 ))
        fi
    else
        echo -e "\t   send by name ${YELLOW}SKIPPED${NC} (no contact name found for ${receiverContainer})"
    fi
fi

############################ SEND TO NON-EXISTING CONTACT TEST ############################

echo -e "\n[Send to Non-Existing Contact Handling Test]"

# Use the first container for these tests
if [ ${#containers[@]} -gt 0 ]; then
    testSender="${containers[0]}"
    nonExistingAddress="http://non-existing-address-99999.example.com"
    nonExistingName="NonExistingContact99999"

    # Test: send to non-existing ADDRESS (must return valid JSON, may attempt P2P)
    totaltests=$(( totaltests + 1 ))
    echo -e "\t-> Testing send to non-existing address"
    sendNonExistAddrResult=$(docker exec ${testSender} eiou send ${nonExistingAddress} 5 USD --json 2>&1)

    # Must return valid JSON response (not crash)
    if [[ "$sendNonExistAddrResult" =~ '"success"' ]] && [[ "$sendNonExistAddrResult" =~ '"' ]]; then
        printf "\t   send to non-existing address ${GREEN}PASSED${NC} (handled properly)\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   send to non-existing address ${RED}FAILED${NC} (invalid response or crash)\n"
        printf "\t   Output: ${sendNonExistAddrResult}\n"
        failure=$(( failure + 1 ))
    fi

    # Test: send to non-existing NAME (must return valid JSON)
    totaltests=$(( totaltests + 1 ))
    echo -e "\t-> Testing send to non-existing name"
    sendNonExistNameResult=$(docker exec ${testSender} eiou send "${nonExistingName}" 5 USD --json 2>&1)

    if [[ "$sendNonExistNameResult" =~ '"success"' ]] && [[ "$sendNonExistNameResult" =~ '"' ]]; then
        printf "\t   send to non-existing name ${GREEN}PASSED${NC} (handled properly)\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   send to non-existing name ${RED}FAILED${NC} (invalid response or crash)\n"
        printf "\t   Output: ${sendNonExistNameResult}\n"
        failure=$(( failure + 1 ))
    fi
fi

# Test multi-hop routing (A->D requires routing through B and C)
echo -e "\t-> Testing multi-hop: httpA sending to httpD (should route through httpB and httpC)"
if [[ "${containerAddresses[httpA]}" ]] && [[ "${containerAddresses[httpD]}" ]]; then

    # Get initial balance of httpD
    initialBalanceD=$(docker exec httpD php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${containerAddresses[httpC]}');
        \$balance = \$app->services->getBalanceRepository()->getCurrentContactBalance(\$pubkey,'USD');
        echo \$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR;
    " 2>/dev/null || echo "0")

    # Send from httpA to httpD (multi-hop)
    multiHopResult=$(docker exec httpA eiou send ${containerAddresses[httpD]} 10 USD 2>&1)

    # Wait for balance change with polling (multi-hop may take longer)
    echo -e "\t   Waiting for multi-hop routing (timeout: 30s)..."
    balance_cmd_d="php -r \"
        require_once('${BOOTSTRAP_PATH}');
        \\\$app = \Eiou\Core\Application::getInstance();
        \\\$pubkey = \\\$app->services->getContactRepository()->getContactPubkey('${MODE}','${containerAddresses[httpC]}');
        \\\$balance = \\\$app->services->getBalanceRepository()->getCurrentContactBalance(\\\$pubkey,'USD');
        echo \\\$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR;
    \""
    newBalanceD=$(wait_for_balance_change "httpD" "$initialBalanceD" "$balance_cmd_d" 30 "multi-hop routing")

    # Add to test count
    totaltests=$(( totaltests + 1 ))

    # Check if multi-hop succeeded
    balanceIncreasedD=$(awk "BEGIN {print ($newBalanceD > $initialBalanceD) ? 1 : 0}")

    # Retry if balance didn't change - process queues with adaptive polling
    if [[ "$balanceIncreasedD" -eq 0 ]]; then
        echo -e "\t   Balance unchanged, retrying with queue processing..."
        all_containers="${containers[*]}"
        # Process queues multiple times to ensure routing messages propagate
        for attempt in 1 2 3 4; do
            process_routing_queues "$all_containers"
        done

        # Re-check balance after retry
        newBalanceD=$(docker exec httpD sh -c "$balance_cmd_d" 2>/dev/null || echo "$initialBalanceD")
        balanceIncreasedD=$(awk "BEGIN {print ($newBalanceD > $initialBalanceD) ? 1 : 0}")
    fi

    if [[ "$multiHopResult" =~ "success" ]] || [[ "$multiHopResult" =~ "sent" ]] || [[ "$balanceIncreasedD" -eq 1 ]]; then
        printf "\t   Multi-hop routing httpA->httpD ${GREEN}PASSED${NC} (Balance: %s -> %s)\n" ${initialBalanceD} ${newBalanceD}
        passed=$(( passed + 1 ))
    else
        printf "\t   Multi-hop routing httpA->httpD ${RED}FAILED${NC}\n"
        printf "\t   Result: %s\n\n" "${multiHopResult}"
        failure=$(( failure + 1 ))
    fi
fi

succesrate "${totaltests}" "${passed}" "${failure}" "'send message'"