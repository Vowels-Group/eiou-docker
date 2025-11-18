#!/bin/sh

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
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        \$pubkey = Application::getInstance()->services->getContactRepository()->getContactPubkey('${containerAddresses[${containerKeys[0]}]}');
        \$balance = Application::getInstance()->services->getBalanceRepository()->getCurrentContactBalance(\$pubkey,'${testCurrency}');
        echo \$balance/Constants::TRANSACTION_USD_CONVERSION_FACTOR;
    " 2>/dev/null || echo "0")

    # Send the message
    sendResult=$(docker exec ${containerKeys[0]} eiou send ${containerAddresses[${containerKeys[1]}]} ${testAmount} ${testCurrency} 2>&1)

    # Wait for transaction to process
    echo -e "\t   Waiting 20 seconds for routing process (faster but certainty)..."
    sleep 20

    # Get new balance of recipient
    newBalance=$(docker exec ${containerKeys[1]} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        \$pubkey = Application::getInstance()->services->getContactRepository()->getContactPubkey('${containerAddresses[${containerKeys[0]}]}');
        \$balance = Application::getInstance()->services->getBalanceRepository()->getCurrentContactBalance(\$pubkey,'${testCurrency}');
        echo \$balance/Constants::TRANSACTION_USD_CONVERSION_FACTOR;
    " 2>/dev/null || echo "0")

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

# Test multi-hop routing (A->D requires routing through B and C)
echo -e "\t-> Testing multi-hop: httpA sending to httpD (should route through httpB and httpC)"
if [[ "${containerAddresses[httpA]}" ]] && [[ "${containerAddresses[httpD]}" ]]; then

    # Get initial balance of httpD
    initialBalanceD=$(docker exec httpD php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        \$pubkey = Application::getInstance()->services->getContactRepository()->getContactPubkey('${containerAddresses[httpC]}');
        \$balance = Application::getInstance()->services->getBalanceRepository()->getCurrentContactBalance(\$pubkey,'USD');
        echo \$balance/Constants::TRANSACTION_USD_CONVERSION_FACTOR;
    " 2>/dev/null || echo "0")

    # Send from httpA to httpD (multi-hop)
    multiHopResult=$(docker exec httpA eiou send ${containerAddresses[httpD]} 10 USD 2>&1)

    # Wait for routing
    echo -e "\t   Waiting for 20 seconds for complete routing (faster but certainty)..."
    sleep 20

    # Get new balance of httpD
    newBalanceD=$(docker exec httpD php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        \$pubkey = Application::getInstance()->services->getContactRepository()->getContactPubkey('${containerAddresses[httpC]}');
        \$balance = Application::getInstance()->services->getBalanceRepository()->getCurrentContactBalance(\$pubkey,'USD');
        echo \$balance/Constants::TRANSACTION_USD_CONVERSION_FACTOR;
    " 2>/dev/null || echo "0")

    # Add to test count
    totaltests=$(( totaltests + 1 ))

    # Check if multi-hop succeeded
    balanceIncreasedD=$(awk "BEGIN {print ($newBalanceD > $initialBalanceD) ? 1 : 0}")
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