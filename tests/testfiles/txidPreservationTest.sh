#!/bin/sh
# Copyright 2025 The Vowels Company

# Test txid preservation through transaction chains
# Verifies that outgoing txid to other contact is the same as their incoming txid
# Issue #320: Make outgoing txid to other contact the same as their incoming txid

echo -e "\nTesting txid preservation through transaction chains..."

testname="txidPreservationTest"
totaltests=0
passed=0
failure=0

# Helper function to get transaction txid from database
getTxidForTransaction() {
    local container=$1
    local sender_addr=$2
    local receiver_addr=$3
    local amount=$4

    docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$transRepo = \$app->services->getTransactionRepository();

        // Get recent transactions matching the criteria
        \$transactions = \$transRepo->getTransactions(50);

        // Find transaction matching sender, receiver, and amount
        \$amountCents = (int)($amount * Constants::TRANSACTION_USD_CONVERSION_FACTOR);
        foreach (\$transactions as \$tx) {
            if (\$tx['sender_address'] === '$sender_addr' &&
                \$tx['receiver_address'] === '$receiver_addr' &&
                abs(\$tx['amount'] - \$amountCents) < 10) {  // Allow small rounding difference
                echo \$tx['txid'];
                exit(0);
            }
        }
        echo 'NOT_FOUND';
    " 2>/dev/null || echo "ERROR"
}

# Helper function to wait for transaction to appear in database
waitForTransaction() {
    local container=$1
    local sender_addr=$2
    local receiver_addr=$3
    local amount=$4
    local timeout=15
    local elapsed=0

    while [ $elapsed -lt $timeout ]; do
        txid=$(getTxidForTransaction "$container" "$sender_addr" "$receiver_addr" "$amount")
        if [ "$txid" != "NOT_FOUND" ] && [ "$txid" != "ERROR" ] && [ -n "$txid" ]; then
            echo "$txid"
            return 0
        fi
        sleep 1
        elapsed=$((elapsed + 1))
    done

    echo "TIMEOUT"
    return 1
}

# Test 1: Direct transaction txid preservation (A -> B)
echo -e "\n[Test 1: Direct Transaction Txid Preservation (A -> B)]"

for linkPair in "${!containersLinks[@]}"; do
    containerPair=(${linkPair//,/ })
    sender="${containerPair[0]}"
    receiver="${containerPair[1]}"

    # Only test first direct link to avoid too many tests
    if [[ -z "$firstDirectTestDone" ]]; then
        firstDirectTestDone="true"
        totaltests=$(( totaltests + 1 ))

        senderAddr="${containerAddresses[$sender]}"
        receiverAddr="${containerAddresses[$receiver]}"
        testAmount="5.00"

        echo -e "\n\t-> Testing direct transaction: ${sender} -> ${receiver}"
        echo -e "\t   Amount: ${testAmount} USD"

        # Send transaction
        sendResult=$(docker exec ${sender} eiou send ${receiverAddr} ${testAmount} USD "Direct txid test" 2>&1)

        # Wait for transaction to appear in both databases
        echo -e "\t   Waiting for transaction to be recorded..."
        senderTxid=$(waitForTransaction "$sender" "$senderAddr" "$receiverAddr" "$testAmount")
        receiverTxid=$(waitForTransaction "$receiver" "$senderAddr" "$receiverAddr" "$testAmount")

        echo -e "\t   Sender's outgoing txid:   ${senderTxid}"
        echo -e "\t   Receiver's incoming txid: ${receiverTxid}"

        # Verify txids match
        if [ "$senderTxid" = "$receiverTxid" ] && [ "$senderTxid" != "TIMEOUT" ] && [ "$senderTxid" != "NOT_FOUND" ] && [ -n "$senderTxid" ]; then
            printf "\t   Direct txid preservation ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   Direct txid preservation ${RED}FAILED${NC}\n"
            printf "\t   Expected txids to match, got sender='%s' receiver='%s'\n" "$senderTxid" "$receiverTxid"
            failure=$(( failure + 1 ))
        fi
    fi
done

# Test 2: P2P forwarded transaction txid preservation (A -> B -> C)
echo -e "\n[Test 2: P2P Forwarded Transaction Txid Preservation (A -> B -> C)]"

# Find a routing scenario that requires exactly one hop
for routingPair in "${!routingTests[@]}"; do
    containerPair=(${routingPair//,/ })
    sender="${containerPair[0]}"
    finalReceiver="${containerPair[1]}"
    expectedPath="${routingTests[$routingPair]}"

    # Only test scenarios with exactly one intermediate node
    intermediates=(${expectedPath//,/ })
    if [[ ${#intermediates[@]} -eq 1 ]] && [[ -z "$firstP2pTestDone" ]]; then
        firstP2pTestDone="true"
        totaltests=$(( totaltests + 1 ))

        relay="${intermediates[0]}"
        senderAddr="${containerAddresses[$sender]}"
        relayAddr="${containerAddresses[$relay]}"
        finalReceiverAddr="${containerAddresses[$finalReceiver]}"
        testAmount="7.00"

        echo -e "\n\t-> Testing P2P transaction: ${sender} -> ${relay} -> ${finalReceiver}"
        echo -e "\t   Amount: ${testAmount} USD"

        # Send transaction
        sendResult=$(docker exec ${sender} eiou send ${finalReceiverAddr} ${testAmount} USD "P2P txid test" 2>&1)

        # Wait for transactions to propagate
        echo -e "\t   Waiting for P2P routing to complete..."
        sleep 10

        # Get txid from sender's perspective (A -> B leg)
        senderToRelayTxid=$(getTxidForTransaction "$sender" "$senderAddr" "$relayAddr" "$testAmount")
        relayIncomingTxid=$(getTxidForTransaction "$relay" "$senderAddr" "$relayAddr" "$testAmount")

        # Get txid from relay's perspective (B -> C leg)
        # Note: Amount will be slightly less due to fee
        relayToFinalTxid=$(docker exec ${relay} php -r "
            require_once('${REL_APPLICATION}');
            \$app = Application::getInstance();
            \$transRepo = \$app->services->getTransactionRepository();
            \$transactions = \$transRepo->getTransactions(50);
            foreach (\$transactions as \$tx) {
                if (\$tx['sender_address'] === '$relayAddr' &&
                    \$tx['receiver_address'] === '$finalReceiverAddr' &&
                    \$tx['tx_type'] === 'p2p') {
                    echo \$tx['txid'];
                    exit(0);
                }
            }
            echo 'NOT_FOUND';
        " 2>/dev/null || echo "ERROR")

        finalReceiverIncomingTxid=$(docker exec ${finalReceiver} php -r "
            require_once('${REL_APPLICATION}');
            \$app = Application::getInstance();
            \$transRepo = \$app->services->getTransactionRepository();
            \$transactions = \$transRepo->getTransactions(50);
            foreach (\$transactions as \$tx) {
                if (\$tx['sender_address'] === '$relayAddr' &&
                    \$tx['receiver_address'] === '$finalReceiverAddr' &&
                    \$tx['tx_type'] === 'p2p') {
                    echo \$tx['txid'];
                    exit(0);
                }
            }
            echo 'NOT_FOUND';
        " 2>/dev/null || echo "ERROR")

        echo -e "\t   A's outgoing txid (A->B):  ${senderToRelayTxid}"
        echo -e "\t   B's incoming txid (A->B):  ${relayIncomingTxid}"
        echo -e "\t   B's outgoing txid (B->C):  ${relayToFinalTxid}"
        echo -e "\t   C's incoming txid (B->C):  ${finalReceiverIncomingTxid}"

        # Verify all txids match (chain preservation)
        allMatch=1
        if [ "$senderToRelayTxid" != "$relayIncomingTxid" ]; then
            allMatch=0
        fi
        if [ "$relayToFinalTxid" != "$finalReceiverIncomingTxid" ]; then
            allMatch=0
        fi
        # CRITICAL: Verify the entire chain uses the same txid
        if [ "$senderToRelayTxid" != "$relayToFinalTxid" ]; then
            allMatch=0
        fi

        if [ $allMatch -eq 1 ] && [ "$senderToRelayTxid" != "NOT_FOUND" ] && [ "$senderToRelayTxid" != "ERROR" ] && [ -n "$senderToRelayTxid" ]; then
            printf "\t   P2P txid chain preservation ${GREEN}PASSED${NC}\n"
            printf "\t   All segments use same txid: %s\n" "$senderToRelayTxid"
            passed=$(( passed + 1 ))
        else
            printf "\t   P2P txid chain preservation ${RED}FAILED${NC}\n"
            printf "\t   Expected all txids to match\n"
            failure=$(( failure + 1 ))
        fi
    fi
done

# Test 3: Multi-hop transaction txid preservation (A -> B -> C -> D)
echo -e "\n[Test 3: Multi-hop Transaction Txid Preservation (A -> B -> C -> D)]"

# Find a routing scenario with exactly two intermediate nodes
for routingPair in "${!routingTests[@]}"; do
    containerPair=(${routingPair//,/ })
    sender="${containerPair[0]}"
    finalReceiver="${containerPair[1]}"
    expectedPath="${routingTests[$routingPair]}"

    # Only test scenarios with exactly two intermediate nodes
    intermediates=(${expectedPath//,/ })
    if [[ ${#intermediates[@]} -eq 2 ]] && [[ -z "$firstMultiHopTestDone" ]]; then
        firstMultiHopTestDone="true"
        totaltests=$(( totaltests + 1 ))

        relay1="${intermediates[0]}"
        relay2="${intermediates[1]}"
        senderAddr="${containerAddresses[$sender]}"
        relay1Addr="${containerAddresses[$relay1]}"
        relay2Addr="${containerAddresses[$relay2]}"
        finalReceiverAddr="${containerAddresses[$finalReceiver]}"
        testAmount="9.00"

        echo -e "\n\t-> Testing multi-hop transaction: ${sender} -> ${relay1} -> ${relay2} -> ${finalReceiver}"
        echo -e "\t   Amount: ${testAmount} USD"

        # Send transaction
        sendResult=$(docker exec ${sender} eiou send ${finalReceiverAddr} ${testAmount} USD "Multi-hop txid test" 2>&1)

        # Wait for transactions to propagate through the chain
        echo -e "\t   Waiting for multi-hop routing to complete..."
        sleep 15

        # Get txid from each hop
        hop1_sender_txid=$(getTxidForTransaction "$sender" "$senderAddr" "$relay1Addr" "$testAmount")
        hop1_receiver_txid=$(getTxidForTransaction "$relay1" "$senderAddr" "$relay1Addr" "$testAmount")

        hop2_sender_txid=$(docker exec ${relay1} php -r "
            require_once('${REL_APPLICATION}');
            \$app = Application::getInstance();
            \$transRepo = \$app->services->getTransactionRepository();
            \$transactions = \$transRepo->getTransactions(50);
            foreach (\$transactions as \$tx) {
                if (\$tx['sender_address'] === '$relay1Addr' &&
                    \$tx['receiver_address'] === '$relay2Addr') {
                    echo \$tx['txid'];
                    exit(0);
                }
            }
            echo 'NOT_FOUND';
        " 2>/dev/null || echo "ERROR")

        hop2_receiver_txid=$(docker exec ${relay2} php -r "
            require_once('${REL_APPLICATION}');
            \$app = Application::getInstance();
            \$transRepo = \$app->services->getTransactionRepository();
            \$transactions = \$transRepo->getTransactions(50);
            foreach (\$transactions as \$tx) {
                if (\$tx['sender_address'] === '$relay1Addr' &&
                    \$tx['receiver_address'] === '$relay2Addr') {
                    echo \$tx['txid'];
                    exit(0);
                }
            }
            echo 'NOT_FOUND';
        " 2>/dev/null || echo "ERROR")

        hop3_sender_txid=$(docker exec ${relay2} php -r "
            require_once('${REL_APPLICATION}');
            \$app = Application::getInstance();
            \$transRepo = \$app->services->getTransactionRepository();
            \$transactions = \$transRepo->getTransactions(50);
            foreach (\$transactions as \$tx) {
                if (\$tx['sender_address'] === '$relay2Addr' &&
                    \$tx['receiver_address'] === '$finalReceiverAddr') {
                    echo \$tx['txid'];
                    exit(0);
                }
            }
            echo 'NOT_FOUND';
        " 2>/dev/null || echo "ERROR")

        hop3_receiver_txid=$(docker exec ${finalReceiver} php -r "
            require_once('${REL_APPLICATION}');
            \$app = Application::getInstance();
            \$transRepo = \$app->services->getTransactionRepository();
            \$transactions = \$transRepo->getTransactions(50);
            foreach (\$transactions as \$tx) {
                if (\$tx['sender_address'] === '$relay2Addr' &&
                    \$tx['receiver_address'] === '$finalReceiverAddr') {
                    echo \$tx['txid'];
                    exit(0);
                }
            }
            echo 'NOT_FOUND';
        " 2>/dev/null || echo "ERROR")

        echo -e "\t   Hop 1 (A->B): sender=${hop1_sender_txid:0:8}... receiver=${hop1_receiver_txid:0:8}..."
        echo -e "\t   Hop 2 (B->C): sender=${hop2_sender_txid:0:8}... receiver=${hop2_receiver_txid:0:8}..."
        echo -e "\t   Hop 3 (C->D): sender=${hop3_sender_txid:0:8}... receiver=${hop3_receiver_txid:0:8}..."

        # Verify all txids in the chain match
        allMatch=1
        if [ "$hop1_sender_txid" != "$hop1_receiver_txid" ] || \
           [ "$hop2_sender_txid" != "$hop2_receiver_txid" ] || \
           [ "$hop3_sender_txid" != "$hop3_receiver_txid" ] || \
           [ "$hop1_sender_txid" != "$hop2_sender_txid" ] || \
           [ "$hop2_sender_txid" != "$hop3_sender_txid" ]; then
            allMatch=0
        fi

        if [ $allMatch -eq 1 ] && [ "$hop1_sender_txid" != "NOT_FOUND" ] && [ "$hop1_sender_txid" != "ERROR" ] && [ -n "$hop1_sender_txid" ]; then
            printf "\t   Multi-hop txid chain preservation ${GREEN}PASSED${NC}\n"
            printf "\t   All 3 hops use same txid: %s\n" "${hop1_sender_txid:0:16}..."
            passed=$(( passed + 1 ))
        else
            printf "\t   Multi-hop txid chain preservation ${RED}FAILED${NC}\n"
            printf "\t   Expected all txids to match across all hops\n"
            failure=$(( failure + 1 ))
        fi
    fi
done

# Print summary
echo -e "\n[${testname} Summary]"
printf "Total tests: %d\n" ${totaltests}
printf "${GREEN}Passed: %d${NC}\n" ${passed}
printf "${RED}Failed: %d${NC}\n" ${failure}

exit ${failure}
