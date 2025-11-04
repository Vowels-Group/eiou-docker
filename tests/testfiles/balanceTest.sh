#!/bin/sh

# Test balance checking and verification across all containers
echo -e "\nTesting balance queries and calculations..."

testname="balanceTest"
totaltests=0
passed=0
failure=0

# First, check that all containers can query their balances
echo -e "\n[Balance Query Test]"
for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking balance for ${container}"

    # Method 1: Using eiou command
    balanceOutput=$(docker exec ${container} eiou balance 2>&1)

    # Method 2: Direct PHP query for verification
    phpBalance=$(docker exec ${container} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        \$balances = ServiceContainer::getInstance()->getBalanceRepository()->getAllBalances();
        if (!empty(\$balances)) {
            foreach (\$balances as \$currency => \$amount) {
                echo \$currency . ':' . \$amount . ' ';
            }
        } else {
            echo 'NO_BALANCES';
        }
    " 2>/dev/null || echo "ERROR")

    # Check if balance command executed successfully
    if [[ ! "$balanceOutput" =~ "error" ]] && [[ ! "$balanceOutput" =~ "Error" ]] && [[ "$phpBalance" != "ERROR" ]]; then
        printf "Balance query for %s ${GREEN}PASSED${NC}\n" ${container}
        printf "\tBalances: %s\n" "${phpBalance}"
        passed=$(( passed + 1 ))
    else
        printf "Balance query for %s ${RED}FAILED${NC}\n" ${container}
        printf "\tOutput: %s\n" "${balanceOutput}"
        failure=$(( failure + 1 ))
    fi
done

# Test balance changes after transactions
echo -e "\n[Balance Change Verification Test]"
echo -e "\n\t-> Testing balance changes with controlled transaction"

# Pick first two connected containers for test
firstLink="${containersLinkKeys[0]}"
if [[ "$firstLink" ]]; then
    containerPair=(${firstLink//,/ })
    sender="${containerPair[0]}"
    receiver="${containerPair[1]}"

    # Get initial balances
    senderInitial=$(docker exec ${sender} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        \$balance = ServiceContainer::getInstance()->getBalanceRepository()->getBalance('USD');
        echo \$balance ?: '0';
    " 2>/dev/null || echo "0")

    receiverInitial=$(docker exec ${receiver} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        \$balance = ServiceContainer::getInstance()->getBalanceRepository()->getBalance('USD');
        echo \$balance ?: '0';
    " 2>/dev/null || echo "0")

    echo -e "\n\t  Initial balances:"
    echo -e "\t  ${sender}: ${senderInitial} USD"
    echo -e "\t  ${receiver}: ${receiverInitial} USD"

    # Send test amount
    testAmount="7"
    docker exec ${sender} eiou send ${containerAddresses[${receiver}]} ${testAmount} USD 2>&1 > /dev/null

    # Wait for transaction processing
    sleep 2

    # Get new balances
    senderFinal=$(docker exec ${sender} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        \$balance = ServiceContainer::getInstance()->getBalanceRepository()->getBalance('USD');
        echo \$balance ?: '0';
    " 2>/dev/null || echo "0")

    receiverFinal=$(docker exec ${receiver} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        \$balance = ServiceContainer::getInstance()->getBalanceRepository()->getBalance('USD');
        echo \$balance ?: '0';
    " 2>/dev/null || echo "0")

    echo -e "\n\t  Final balances:"
    echo -e "\t  ${sender}: ${senderFinal} USD"
    echo -e "\t  ${receiver}: ${receiverFinal} USD"

    totaltests=$(( totaltests + 1 ))

    # Verify sender's balance decreased and receiver's increased
    senderDiff=$(echo "$senderInitial - $senderFinal" | bc)
    receiverDiff=$(echo "$receiverFinal - $receiverInitial" | bc)

    if [[ $(echo "$senderDiff > 0" | bc) -eq 1 ]] && [[ $(echo "$receiverDiff > 0" | bc) -eq 1 ]]; then
        printf "Balance change verification ${GREEN}PASSED${NC}\n"
        printf "\t%s sent ~%s USD, %s received ~%s USD\n" ${sender} ${senderDiff} ${receiver} ${receiverDiff}
        passed=$(( passed + 1 ))
    else
        printf "Balance change verification ${RED}FAILED${NC}\n"
        printf "\tExpected balance changes not detected\n"
        failure=$(( failure + 1 ))
    fi
fi

# Test viewing all balances command
echo -e "\n[View All Balances Test]"
for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))

    viewBalancesOutput=$(docker exec ${container} eiou viewbalances 2>&1)

    if [[ ! "$viewBalancesOutput" =~ "error" ]] && [[ ! "$viewBalancesOutput" =~ "Error" ]]; then
        printf "viewbalances command for %s ${GREEN}PASSED${NC}\n" ${container}
        passed=$(( passed + 1 ))
    else
        printf "viewbalances command for %s ${RED}FAILED${NC}\n" ${container}
        failure=$(( failure + 1 ))
    fi
done

succesrate "${totaltests}" "${passed}" "${failure}" "'balance operations'"