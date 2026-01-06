#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

# Multi-Round Sync Cycle Test
# This test verifies that held transactions with re-signed previous_txid work correctly
# across multiple sync cycles. It tests the scenario where:
# 1. Transactions are sent and deleted from sender
# 2. New transaction triggers resync and held transaction flow
# 3. This cycle is repeated multiple times in both directions
# 4. Chain integrity is verified at each stage

echo -e "\nTesting multi-round sync cycle functionality..."

testname="multiRoundSyncCycleTest"
totaltests=0
passed=0
failure=0

# Use first two connected containers for testing
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
testPair="${containersLinkKeys[0]}"

if [[ -z "$testPair" ]]; then
    echo -e "${YELLOW}Warning: No container links defined, skipping multi-round sync cycle test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'multi-round sync cycle'"
    exit 0
fi

containerKeys=(${testPair//,/ })
containerA="${containerKeys[0]}"
containerB="${containerKeys[1]}"

# Get addresses from containerAddresses (populated by hostnameTest/torAddressTest)
addressA="${containerAddresses[${containerA}]}"
addressB="${containerAddresses[${containerB}]}"

echo -e "\n[Multi-Round Sync Cycle Test Setup]"
echo -e "\t   Container A: ${containerA} (${addressA})"
echo -e "\t   Container B: ${containerB} (${addressB})"

if [[ -z "$addressA" ]] || [[ -z "$addressB" ]]; then
    echo -e "${YELLOW}Warning: Container addresses not populated, skipping test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'multi-round sync cycle'"
    exit 0
fi

############################ Setup: Ensure Contacts Exist ############################

echo -e "\n[Setup: Ensuring contacts exist]"

# Add contacts if they don't exist
docker exec ${containerA} eiou add ${addressB} ${containerB} 0 0 USD 2>&1 || true
docker exec ${containerB} eiou add ${addressA} ${containerA} 0 0 USD 2>&1 || true

sleep 2

############################ Get Public Keys ############################

echo -e "\n[Setup: Getting container public keys]"

# Get B's public key from A's contact list
pubkeyInfoB=$(docker exec ${containerA} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${addressB}');
    if (\$pubkey) {
        echo base64_encode(\$pubkey) . '|' . hash('sha256', \$pubkey);
    } else {
        echo 'ERROR|ERROR';
    }
" 2>/dev/null || echo "ERROR|ERROR")

pubkeyB64_B=$(echo "$pubkeyInfoB" | cut -d'|' -f1)
pubkeyHash_B=$(echo "$pubkeyInfoB" | cut -d'|' -f2)

# Get A's public key from B's contact list
pubkeyInfoA=$(docker exec ${containerB} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${addressA}');
    if (\$pubkey) {
        echo base64_encode(\$pubkey) . '|' . hash('sha256', \$pubkey);
    } else {
        echo 'ERROR|ERROR';
    }
" 2>/dev/null || echo "ERROR|ERROR")

pubkeyB64_A=$(echo "$pubkeyInfoA" | cut -d'|' -f1)
pubkeyHash_A=$(echo "$pubkeyInfoA" | cut -d'|' -f2)

echo -e "\t   Container A pubkey hash: ${pubkeyHash_A:0:20}..."
echo -e "\t   Container B pubkey hash: ${pubkeyHash_B:0:20}..."

if [[ "$pubkeyHash_A" == "ERROR" ]] || [[ "$pubkeyHash_B" == "ERROR" ]] || [[ -z "$pubkeyHash_A" ]] || [[ -z "$pubkeyHash_B" ]]; then
    echo -e "${YELLOW}Warning: Could not retrieve public keys, skipping test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'multi-round sync cycle'"
    exit 0
fi

############################ Helper Functions ############################

# Function to get transaction count between two containers
get_tx_count() {
    local container=$1
    local pubkey_hash=$2
    docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE
            (sender_public_key_hash = '${pubkey_hash}' OR receiver_public_key_hash = '${pubkey_hash}')
            AND memo = 'standard'\")->fetchColumn();
        echo \$count;
    " 2>/dev/null || echo "0"
}

# Function to delete all transactions between two containers
delete_all_tx() {
    local container=$1
    local pubkey_hash=$2
    docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE
            (sender_public_key_hash = '${pubkey_hash}' OR receiver_public_key_hash = '${pubkey_hash}')
            AND memo = 'standard'\");
        echo \$deleted;
    " 2>/dev/null || echo "0"
}

# Function to get transaction chain info (txids and previous_txids)
get_chain_info() {
    local container=$1
    local pubkey_hash=$2
    docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$stmt = \$pdo->query(\"SELECT txid, previous_txid, status FROM transactions
            WHERE (sender_public_key_hash = '${pubkey_hash}' OR receiver_public_key_hash = '${pubkey_hash}')
            AND memo = 'standard'
            ORDER BY COALESCE(time, 0) ASC, timestamp ASC\");
        \$txs = \$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach (\$txs as \$tx) {
            echo substr(\$tx['txid'], 0, 8) . '->' . (isset(\$tx['previous_txid']) ? substr(\$tx['previous_txid'], 0, 8) : 'NULL') . ':' . \$tx['status'] . '|';
        }
    " 2>/dev/null || echo "ERROR"
}

# Function to verify chain integrity (each tx's previous_txid should exist or be null)
verify_chain_integrity() {
    local container=$1
    local pubkey_hash=$2
    docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$stmt = \$pdo->query(\"SELECT txid, previous_txid FROM transactions
            WHERE (sender_public_key_hash = '${pubkey_hash}' OR receiver_public_key_hash = '${pubkey_hash}')
            AND memo = 'standard'\");
        \$txs = \$stmt->fetchAll(PDO::FETCH_ASSOC);

        \$txids = array_column(\$txs, 'txid');
        \$issues = [];

        foreach (\$txs as \$tx) {
            if (\$tx['previous_txid'] !== null && !in_array(\$tx['previous_txid'], \$txids)) {
                // Check if previous_txid points to an existing transaction
                \$exists = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE txid = '\" . \$tx['previous_txid'] . \"'\")->fetchColumn();
                if (\$exists == 0) {
                    \$issues[] = substr(\$tx['txid'], 0, 8) . ' points to missing ' . substr(\$tx['previous_txid'], 0, 8);
                }
            }
        }

        if (empty(\$issues)) {
            echo 'VALID:' . count(\$txs);
        } else {
            echo 'INVALID:' . implode(',', \$issues);
        }
    " 2>/dev/null || echo "ERROR"
}

# Unique timestamp for this test run
timestamp=$(date +%s%N)
NUM_CYCLES=3

echo -e "\n============================================"
echo -e "Starting ${NUM_CYCLES} sync cycles test"
echo -e "============================================"

############################ Initial Transactions: A -> B ############################

echo -e "\n[Initial: Send 2 transactions from A to B]"

docker exec ${containerA} eiou send ${addressB} 1 USD "cycle-init-1-${timestamp}" 2>&1 > /dev/null
sleep 1
# Process pending transactions
docker exec ${containerA} eiou out 2>&1 > /dev/null
sleep 2

docker exec ${containerA} eiou send ${addressB} 2 USD "cycle-init-2-${timestamp}" 2>&1 > /dev/null
sleep 1
# Process pending transactions
docker exec ${containerA} eiou out 2>&1 > /dev/null
sleep 2

# Wait for B to receive and process
docker exec ${containerB} eiou in 2>&1 > /dev/null
sleep 2

initialCountA=$(get_tx_count ${containerA} ${pubkeyHash_B})
initialCountB=$(get_tx_count ${containerB} ${pubkeyHash_A})
echo -e "\t   A has ${initialCountA} transactions, B has ${initialCountB} transactions"

# Verify both sides have the transactions before proceeding
if [[ "$initialCountA" -lt 2 ]] || [[ "$initialCountB" -lt 2 ]]; then
    echo -e "\t   ${YELLOW}Warning: Initial transactions not fully synced${NC}"
    echo -e "\t   Waiting additional time..."
    sleep 5
    docker exec ${containerA} eiou out 2>&1 > /dev/null
    docker exec ${containerB} eiou in 2>&1 > /dev/null
    sleep 3
    initialCountA=$(get_tx_count ${containerA} ${pubkeyHash_B})
    initialCountB=$(get_tx_count ${containerB} ${pubkeyHash_A})
    echo -e "\t   After retry: A has ${initialCountA}, B has ${initialCountB}"
fi

############################ CYCLE LOOP ############################

for cycle in $(seq 1 ${NUM_CYCLES}); do
    echo -e "\n============================================"
    echo -e "CYCLE ${cycle}/${NUM_CYCLES}"
    echo -e "============================================"

    ############################ Step 1: Delete transactions from A ############################

    echo -e "\n[Cycle ${cycle} - Step 1: Delete all transactions from A]"
    totaltests=$(( totaltests + 1 ))

    deletedA=$(delete_all_tx ${containerA} ${pubkeyHash_B})
    countAfterDeleteA=$(get_tx_count ${containerA} ${pubkeyHash_B})

    echo -e "\t   Deleted ${deletedA} transactions from A"
    echo -e "\t   A now has ${countAfterDeleteA} transactions"

    if [[ "$countAfterDeleteA" == "0" ]]; then
        printf "\t   Delete from A ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Delete from A ${RED}FAILED${NC} (still has: %s)\n" "${countAfterDeleteA}"
        failure=$(( failure + 1 ))
    fi

    ############################ Step 2: Send new transaction from A to B (triggers resync) ############################

    echo -e "\n[Cycle ${cycle} - Step 2: Send new transaction A->B (triggers resync)]"
    totaltests=$(( totaltests + 1 ))

    # This should trigger invalid_previous_txid, then sync, then resume held transaction
    docker exec ${containerA} eiou send ${addressB} $(( cycle * 10 )) USD "cycle-${cycle}-atob-${timestamp}" 2>&1 > /dev/null

    # Wait for initial send attempt
    sleep 2

    # Process pending transactions (this triggers sync when rejected)
    docker exec ${containerA} eiou out 2>&1 > /dev/null
    sleep 3

    # Process again for held transactions after sync
    docker exec ${containerA} eiou out 2>&1 > /dev/null
    sleep 2

    # Let B process incoming
    docker exec ${containerB} eiou in 2>&1 > /dev/null
    sleep 2

    countAfterSendA=$(get_tx_count ${containerA} ${pubkeyHash_B})
    countAfterSendB=$(get_tx_count ${containerB} ${pubkeyHash_A})

    echo -e "\t   After A->B send: A has ${countAfterSendA}, B has ${countAfterSendB}"

    # A should have recovered transactions via sync + new one
    if [[ "$countAfterSendA" -ge 2 ]]; then
        printf "\t   A->B transaction and sync ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   A->B transaction ${RED}FAILED${NC} (A has only: %s)\n" "${countAfterSendA}"
        failure=$(( failure + 1 ))
    fi

    ############################ Step 3: Verify chain integrity on A ############################

    echo -e "\n[Cycle ${cycle} - Step 3: Verify chain integrity on A]"
    totaltests=$(( totaltests + 1 ))

    chainIntegrityA=$(verify_chain_integrity ${containerA} ${pubkeyHash_B})

    if [[ "$chainIntegrityA" == VALID:* ]]; then
        txCount=$(echo "$chainIntegrityA" | cut -d':' -f2)
        printf "\t   Chain integrity on A ${GREEN}PASSED${NC} (%s transactions)\n" "${txCount}"
        passed=$(( passed + 1 ))
    else
        printf "\t   Chain integrity on A ${RED}FAILED${NC} (%s)\n" "${chainIntegrityA}"
        failure=$(( failure + 1 ))
    fi

    # Show chain for debugging
    chainInfoA=$(get_chain_info ${containerA} ${pubkeyHash_B})
    echo -e "\t   Chain on A: ${chainInfoA}"

    ############################ Step 4: Delete transactions from B ############################

    echo -e "\n[Cycle ${cycle} - Step 4: Delete all transactions from B]"
    totaltests=$(( totaltests + 1 ))

    deletedB=$(delete_all_tx ${containerB} ${pubkeyHash_A})
    countAfterDeleteB=$(get_tx_count ${containerB} ${pubkeyHash_A})

    echo -e "\t   Deleted ${deletedB} transactions from B"
    echo -e "\t   B now has ${countAfterDeleteB} transactions"

    if [[ "$countAfterDeleteB" == "0" ]]; then
        printf "\t   Delete from B ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Delete from B ${RED}FAILED${NC} (still has: %s)\n" "${countAfterDeleteB}"
        failure=$(( failure + 1 ))
    fi

    ############################ Step 5: Send new transaction from B to A (triggers resync) ############################

    echo -e "\n[Cycle ${cycle} - Step 5: Send new transaction B->A (triggers resync)]"
    totaltests=$(( totaltests + 1 ))

    # This should trigger invalid_previous_txid, then sync, then resume held transaction
    docker exec ${containerB} eiou send ${addressA} $(( cycle * 10 + 5 )) USD "cycle-${cycle}-btoa-${timestamp}" 2>&1 > /dev/null

    # Wait for initial send attempt
    sleep 2

    # Process pending transactions (this triggers sync when rejected)
    docker exec ${containerB} eiou out 2>&1 > /dev/null
    sleep 3

    # Process again for held transactions after sync
    docker exec ${containerB} eiou out 2>&1 > /dev/null
    sleep 2

    # Let A process incoming
    docker exec ${containerA} eiou in 2>&1 > /dev/null
    sleep 2

    countAfterSendB2=$(get_tx_count ${containerB} ${pubkeyHash_A})
    countAfterSendA2=$(get_tx_count ${containerA} ${pubkeyHash_B})

    echo -e "\t   After B->A send: B has ${countAfterSendB2}, A has ${countAfterSendA2}"

    # B should have recovered transactions via sync + new one
    if [[ "$countAfterSendB2" -ge 2 ]]; then
        printf "\t   B->A transaction and sync ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   B->A transaction ${RED}FAILED${NC} (B has only: %s)\n" "${countAfterSendB2}"
        failure=$(( failure + 1 ))
    fi

    ############################ Step 6: Verify chain integrity on B ############################

    echo -e "\n[Cycle ${cycle} - Step 6: Verify chain integrity on B]"
    totaltests=$(( totaltests + 1 ))

    chainIntegrityB=$(verify_chain_integrity ${containerB} ${pubkeyHash_A})

    if [[ "$chainIntegrityB" == VALID:* ]]; then
        txCount=$(echo "$chainIntegrityB" | cut -d':' -f2)
        printf "\t   Chain integrity on B ${GREEN}PASSED${NC} (%s transactions)\n" "${txCount}"
        passed=$(( passed + 1 ))
    else
        printf "\t   Chain integrity on B ${RED}FAILED${NC} (%s)\n" "${chainIntegrityB}"
        failure=$(( failure + 1 ))
    fi

    # Show chain for debugging
    chainInfoB=$(get_chain_info ${containerB} ${pubkeyHash_A})
    echo -e "\t   Chain on B: ${chainInfoB}"

    ############################ Step 7: Cross-verify transaction counts match ############################

    echo -e "\n[Cycle ${cycle} - Step 7: Cross-verify transaction counts]"
    totaltests=$(( totaltests + 1 ))

    finalCountA=$(get_tx_count ${containerA} ${pubkeyHash_B})
    finalCountB=$(get_tx_count ${containerB} ${pubkeyHash_A})

    echo -e "\t   Final counts: A has ${finalCountA}, B has ${finalCountB}"

    # Both should have the same number of transactions
    if [[ "$finalCountA" == "$finalCountB" ]]; then
        printf "\t   Transaction counts match ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Transaction counts ${RED}MISMATCH${NC} (A: %s, B: %s)\n" "${finalCountA}" "${finalCountB}"
        failure=$(( failure + 1 ))
    fi

done

############################ Final Verification ############################

echo -e "\n============================================"
echo -e "FINAL VERIFICATION"
echo -e "============================================"

############################ Final: Verify total accumulated transactions ############################

echo -e "\n[Final: Verify accumulated transactions]"
totaltests=$(( totaltests + 1 ))

finalTxCountA=$(get_tx_count ${containerA} ${pubkeyHash_B})
finalTxCountB=$(get_tx_count ${containerB} ${pubkeyHash_A})

# After N cycles: initial 2 + N A->B + N B->A = 2 + 2N transactions expected
expectedMin=$(( 2 + NUM_CYCLES * 2 ))

echo -e "\t   Expected minimum: ${expectedMin} transactions"
echo -e "\t   A has: ${finalTxCountA} transactions"
echo -e "\t   B has: ${finalTxCountB} transactions"

if [[ "$finalTxCountA" -ge "$expectedMin" ]] && [[ "$finalTxCountB" -ge "$expectedMin" ]]; then
    printf "\t   Accumulated transactions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Accumulated transactions ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ Final: Full chain integrity check ############################

echo -e "\n[Final: Full chain integrity verification]"
totaltests=$(( totaltests + 1 ))

finalIntegrityA=$(verify_chain_integrity ${containerA} ${pubkeyHash_B})
finalIntegrityB=$(verify_chain_integrity ${containerB} ${pubkeyHash_A})

echo -e "\t   A chain status: ${finalIntegrityA}"
echo -e "\t   B chain status: ${finalIntegrityB}"

if [[ "$finalIntegrityA" == VALID:* ]] && [[ "$finalIntegrityB" == VALID:* ]]; then
    printf "\t   Final chain integrity ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Final chain integrity ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ Final: Show complete chains ############################

echo -e "\n[Final: Complete transaction chains]"

echo -e "\t   Container A chain:"
finalChainA=$(get_chain_info ${containerA} ${pubkeyHash_B})
echo -e "\t   ${finalChainA}"

echo -e "\t   Container B chain:"
finalChainB=$(get_chain_info ${containerB} ${pubkeyHash_A})
echo -e "\t   ${finalChainB}"

############################ Summary ############################

succesrate "${totaltests}" "${passed}" "${failure}" "'multi-round sync cycle'"
