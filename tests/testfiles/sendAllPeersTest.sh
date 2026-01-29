#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Send All Peers Test ############################
# Tests transaction sending to all connected peers in the network
#
# Verifies:
# - Transactions can be sent to all directly connected contacts
# - Bidirectional link establishment and verification
# - Full mesh connectivity where applicable
#
# Prerequisites:
# - Containers must be running
# - Contacts must be established (run addContactsTest first)
###########################################################################

# Test sending transactions to ALL connected peers (not just along defined links)
# This test sends from each container to all its contacts to verify full mesh capability
echo "Testing send to all peers functionality..."

testname="sendAllPeersTest"
totaltests=0
passed=0
failure=0

# First, build a map of all contacts for each container
declare -A containerContacts

echo "[Building contact map]"
for container in "${containers[@]}"; do
    echo "  -> Getting contacts for ${container}"    
    
    contacts=$(docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$contacts = \Eiou\Core\Application::getInstance()->services->getContactRepository()->getAllSingleAcceptedAddresses('${MODE}');
        echo implode(' ', \$contacts);
    " 2>/dev/null || echo "")

    # Get all accepted contacts for this container
   
    containerContacts[$container]="$contacts"

    # Count contacts for this container
    contactCount=$(echo "$contacts" | wc -w)
    printf "     Found %d contacts for %s\n" ${contactCount} ${container}
done

echo ""
echo "[Testing all peer combinations]"

# Test sending from each container to all its contacts
for sender in "${containers[@]}"; do
    contacts="${containerContacts[$sender]}"

    if [[ -z "$contacts" ]]; then
        echo "  -> ${sender} has no contacts, skipping"
        continue
    fi

    echo ""
    echo "  -> Testing sends from ${sender}:"

    # Send to each contact
    for contactAddress in $contacts; do
        totaltests=$(( totaltests + 1 ))

        # Find container name for this address (for logging)
        recipientName="unknown"
        for cont in "${containers[@]}"; do
            if [[ "${containerAddresses[$cont]}" == "$contactAddress" ]]; then
                recipientName="$cont"
                break
            fi
        done

        printf "     Sending 1 USD from %s to %s (%s)... " ${sender} ${recipientName} ${contactAddress}

        # Get initial balance of recipient
        initialBalance=$(docker exec ${sender} php -r "
            require_once('${BOOTSTRAP_PATH}');
            \$app = \Eiou\Core\Application::getInstance();
            \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${contactAddress}');
            \$balance = \$app->services->getBalanceRepository()->getCurrentContactBalance(\$pubkey,'USD');
            echo \$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR ?: '0';
        " 2>/dev/null || echo "0")

        # Send test amount
        testAmount="1"
        testCurrency="USD"
        sendResult=$(docker exec ${sender} eiou send ${contactAddress} ${testAmount} ${testCurrency} 2>&1)

        # Wait for transaction to process with polling
        balance_cmd="php -r \"
            require_once('${BOOTSTRAP_PATH}');
            \\\$app = \Eiou\Core\Application::getInstance();
            \\\$pubkey = \\\$app->services->getContactRepository()->getContactPubkey('${MODE}','${contactAddress}');
            \\\$balance = \\\$app->services->getBalanceRepository()->getCurrentContactBalance(\\\$pubkey,'USD');
            echo \\\$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR ?: '0';
        \""
        newBalance=$(wait_for_balance_change "${sender}" "$initialBalance" "$balance_cmd" 10 "tx processing")

        # Check if send succeeded (balance changed or success message)
        balanceChanged=$(awk "BEGIN {print ($newBalance != $initialBalance) ? 1 : 0}")

        if [[ "$sendResult" =~ "success" ]] || [[ "$sendResult" =~ "sent" ]] || [[ "$balanceChanged" -eq 1 ]]; then
            printf "${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            printf "${RED}FAILED${NC}\n"
            printf "       Error: %s\n" "${sendResult}"
            failure=$(( failure + 1 ))
        fi
    done
done

echo ""
echo "[Testing broadcast capability]"
# Test sending the same amount to all peers from one container
if [[ -n "${containers[0]}" ]]; then
    broadcaster="${containers[0]}"
    contacts="${containerContacts[$broadcaster]}"

    if [[ -n "$contacts" ]]; then
        echo "  -> Broadcasting from ${broadcaster} to all contacts"

        broadcastAmount="0.5"
        broadcastCurrency="USD"
        broadcastSuccess=0
        broadcastTotal=0

        for contactAddress in $contacts; do
            broadcastTotal=$(( broadcastTotal + 1 ))

            # Find recipient name
            recipientName="unknown"
            for cont in "${containers[@]}"; do
                if [[ "${containerAddresses[$cont]}" == "$contactAddress" ]]; then
                    recipientName="$cont"
                    break
                fi
            done

            printf "     Broadcasting %s %s to %s... " ${broadcastAmount} ${broadcastCurrency} ${recipientName}

            # Send broadcast amount
            broadcastResult=$(docker exec ${broadcaster} eiou send ${contactAddress} ${broadcastAmount} ${broadcastCurrency} 2>&1)

            if [[ "$broadcastResult" =~ "Searching for route via P2P network" ]] || [[ "$broadcastResult" =~ "Transaction sent successfully to" ]]; then
                printf "${GREEN}SENT${NC}\n"
                broadcastSuccess=$(( broadcastSuccess + 1 ))
            else
                printf "${RED}FAILED${NC}\n"
            fi
        done

        totaltests=$(( totaltests + 1 ))

        # Check broadcast success rate
        if [[ "$broadcastSuccess" -eq "$broadcastTotal" ]]; then
            printf "     Broadcast to all peers ${GREEN}PASSED${NC} (%d/%d successful)\n" ${broadcastSuccess} ${broadcastTotal}
            passed=$(( passed + 1 ))
        elif [[ "$broadcastSuccess" -gt 0 ]]; then
            printf "     Broadcast partially ${NC}completed${NC} (%d/%d successful)\n" ${broadcastSuccess} ${broadcastTotal}
            # Count as partial success
        else
            printf "     Broadcast ${RED}FAILED${NC} (0/%d successful)\n" ${broadcastTotal}
            failure=$(( failure + 1 ))
        fi
    fi
fi

echo ""
echo "[Testing mesh topology completeness]"

# Verify that all containers can reach all other containers (full mesh test)
meshTestPassed=0
meshTestTotal=0
meshContactsBuild="${#containersLinks[@]}"
totalContactsExpected=0
for sender in "${containers[@]}"; do
    contactsExpectedCount=${expectedContacts[${sender}]}
    totalContactsExpected=$(( totalContactsExpected + $contactsExpectedCount ))
    contactsFound=0
    for receiver in "${containers[@]}"; do
        if [[ "$sender" == "$receiver" ]]; then
            continue  # Skip self-sends
        fi

        meshTestTotal=$(( meshTestTotal + 1 ))

        # Check if receiver is in sender's contacts
        receiverAddress="${containerAddresses[$receiver]}"
       
        hasContact=$(docker exec ${sender} php -r "
            require_once('${BOOTSTRAP_PATH}');
            echo \Eiou\Core\Application::getInstance()->services->getContactRepository()->isAcceptedContactAddress('${MODE}','${receiverAddress}');
        " 2>/dev/null || echo "0")

        if [[ "$hasContact" -eq "1" ]]; then
            meshTestPassed=$(( meshTestPassed + 1 ))
            contactsFound=$(( contactsFound + 1))
        fi
    done
    
    if [[ "$contactsFound" -eq "$contactsExpectedCount" ]]; then
        printf "    ${GREEN}Found all expected contacts for ${sender}${NC}\n"  
    else 
        printf "    ${RED}Only found ${contactsFound}/$totalContactsExpected contacts for ${sender}${NC}\n"  
    fi
done

totaltests=$(( totaltests + 1 ))



printf "  Mesh connectivity of build: %d/%d connections exist " ${meshTestPassed} ${totalContactsExpected}
buildMeshPercentage=$(awk "BEGIN {printf \"%.1f\", ($meshTestPassed * 100.0 / $totalContactsExpected)}")
if [[ "$meshTestPassed" -eq "$totalContactsExpected" ]]; then
    printf "${GREEN}(100%% - Full Build mesh)${NC}\n"
    passed=$(( passed + 1 ))
elif [[ $(awk "BEGIN {print ($buildMeshPercentage >= 75) ? 1 : 0}") -eq 1 ]]; then
    printf "${NC}(%s%% - Partial Build mesh)${NC}\n" ${buildMeshPercentage}
    failure=$(( failure + 1 ))
else
    printf "${RED}(%s%% - Sparse Build connectivity)${NC}\n" ${buildMeshPercentage}
    failure=$(( failure + 1 ))
fi

# printf "  Full Mesh connectivity: %d/%d connections exist (informative, no failure) " ${meshTestPassed} ${meshTestTotal}
# fullMeshPercentage=$(awk "BEGIN {printf \"%.1f\", ($meshTestPassed * 100.0 / $meshTestTotal)}")

# if [[ "$meshTestPassed" -eq "$meshTestTotal" ]]; then
#     printf "${GREEN}(100%% - Full mesh)${NC}\n"
# elif [[ $(awk "BEGIN {print ($fullMeshPercentage >= 75) ? 1 : 0}") -eq 1 ]]; then
#     printf "${NC}(%s%% - Partial mesh)${NC}\n" ${fullMeshPercentage}
# else
#     printf "${RED}(%s%% - Sparse connectivity)${NC}\n" ${fullMeshPercentage}
# fi

succesrate "${totaltests}" "${passed}" "${failure}" "'sendAllPeersTest'"