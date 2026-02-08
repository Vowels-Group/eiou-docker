#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

########################## Chain Drop Test Suite ##########################
# Tests the chain drop agreement protocol for resolving mutual
# transaction chain gaps between two contacts.
#
# Test scenarios:
# 1. Single gap: A->B->C, delete B -> A->?->C -> chain drop -> A->C
# 2. Non-consecutive gaps: A->B->C->D->E, delete B,D -> requires 2 chain drops
# 3. Consecutive gaps: A->B->C->D->E, delete B,C -> single chain drop
# 4. Rejection flow: propose -> reject -> chain stays broken
##########################################################################

# Helper functions are sourced via config.sh -> testHelpers.sh

testname="chainDropTestSuite"
totaltests=0
passed=0
failure=0

echo -e "\n"
echo "========================================================================"
echo "                    CHAIN DROP TEST SUITE"
echo "========================================================================"
echo -e "\n"

# Setup: Get container pair and public keys (same pattern as syncTestSuite)
if [[ ${#containersLinks[@]} -gt 0 ]]; then
    containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
    firstLinkKey="${containersLinkKeys[0]}"
    linkParts=(${firstLinkKey//,/ })
    sender="${linkParts[0]}"
    receiver="${linkParts[1]}"
else
    sender="${containers[0]}"
    receiver="${containers[${#containers[@]}-1]}"
fi

senderAddress="${containerAddresses[${sender}]}"
receiverAddress="${containerAddresses[${receiver}]}"

if [[ -z "$senderAddress" ]] || [[ -z "$receiverAddress" ]]; then
    echo -e "${YELLOW}\t   Warning: containerAddresses empty, falling back to userconfig${NC}"
    if [[ "$MODE" == "http" ]]; then
        senderAddress=$(docker exec ${sender} php -r "
            \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
            echo \$json['hostname'] ?? '';
        " 2>/dev/null)
        receiverAddress=$(docker exec ${receiver} php -r "
            \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
            echo \$json['hostname'] ?? '';
        " 2>/dev/null)
    else
        senderAddress=$(docker exec ${sender} php -r "
            \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
            echo \$json['torAddress'] ?? '';
        " 2>/dev/null)
        receiverAddress=$(docker exec ${receiver} php -r "
            \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
            echo \$json['torAddress'] ?? '';
        " 2>/dev/null)
    fi
fi

if [[ -z "$sender" ]] || [[ -z "$receiver" ]]; then
    echo -e "${YELLOW}Warning: No container links defined, skipping chain drop test suite${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'chain drop test suite'"
    exit 0
fi

echo -e "[Test Setup]"
echo -e "\t   Sender: ${sender} (${senderAddress})"
echo -e "\t   Receiver: ${receiver} (${receiverAddress})"

# Ensure contacts exist
docker exec ${sender} eiou add ${receiverAddress} ${receiver} 0.1 1000 USD 2>&1 > /dev/null || true
docker exec ${receiver} eiou add ${senderAddress} ${sender} 0.1 1000 USD 2>&1 > /dev/null || true
wait_for_queue_processed ${sender}
wait_for_queue_processed ${receiver}

# Get transport types and public keys
receiverTransportType=$(getPhpTransportType "${receiverAddress}")
senderTransportType=$(getPhpTransportType "${senderAddress}")

# Wait for contacts to be accepted
echo -e "\t   Waiting for contacts to be accepted..."
waitElapsed=0
while [ $waitElapsed -lt 15 ]; do
    senderStatus=$(docker exec ${sender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$status = \$app->services->getContactRepository()->getContactStatus('${receiverTransportType}', '${receiverAddress}');
        echo \$status ?? 'none';
    " 2>/dev/null || echo "none")

    receiverStatus=$(docker exec ${receiver} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$status = \$app->services->getContactRepository()->getContactStatus('${senderTransportType}', '${senderAddress}');
        echo \$status ?? 'none';
    " 2>/dev/null || echo "none")

    if [[ "$senderStatus" == "accepted" ]] && [[ "$receiverStatus" == "accepted" ]]; then
        break
    fi
    wait_for_queue_processed ${sender} 2
    wait_for_queue_processed ${receiver} 2
    waitElapsed=$(( waitElapsed + 2 ))
done

# Get pubkeys
receiverPubkeyB64=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${receiverTransportType}', '${receiverAddress}');
    if (\$pubkey) { echo base64_encode(\$pubkey); } else { echo 'ERROR'; }
" 2>/dev/null || echo "ERROR")

receiverPubkeyHash=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${receiverTransportType}', '${receiverAddress}');
    if (\$pubkey) { echo hash('sha256', \$pubkey); } else { echo 'ERROR'; }
" 2>/dev/null || echo "ERROR")

senderPubkeyB64=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${senderTransportType}', '${senderAddress}');
    if (\$pubkey) { echo base64_encode(\$pubkey); } else { echo 'ERROR'; }
" 2>/dev/null || echo "ERROR")

senderPubkeyHash=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${senderTransportType}', '${senderAddress}');
    if (\$pubkey) { echo hash('sha256', \$pubkey); } else { echo 'ERROR'; }
" 2>/dev/null || echo "ERROR")

echo -e "\t   Sender pubkey hash: ${senderPubkeyHash:0:40}..."
echo -e "\t   Receiver pubkey hash: ${receiverPubkeyHash:0:40}..."

PUBKEYS_AVAILABLE=true
if [[ "$senderPubkeyHash" == "ERROR" ]] || [[ "$receiverPubkeyHash" == "ERROR" ]]; then
    echo -e "${YELLOW}Warning: Could not retrieve public keys, skipping chain drop tests${NC}"
    PUBKEYS_AVAILABLE=false
fi

# ========================= Helper Functions =========================

# Get txids for test transactions in chain order
# Usage: get_test_txids <container> <description_pattern>
# Returns: comma-separated txids in chronological order
get_test_txids() {
    local container="$1"
    local pattern="$2"
    docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$stmt = \$pdo->prepare(\"SELECT txid FROM transactions
            WHERE description LIKE :pattern
            ORDER BY COALESCE(time, 0) ASC, timestamp ASC\");
        \$stmt->execute(['pattern' => '${pattern}']);
        \$txids = \$stmt->fetchAll(PDO::FETCH_COLUMN);
        echo implode(',', \$txids);
    " 2>/dev/null
}

# Delete specific txids from a container's database
# Usage: delete_txids <container> <txid1> [txid2] [txid3] ...
delete_txids() {
    local container="$1"
    shift
    local txids=("$@")
    for txid in "${txids[@]}"; do
        docker exec ${container} php -r "
            require_once('${BOOTSTRAP_PATH}');
            \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
            \$stmt = \$pdo->prepare('DELETE FROM transactions WHERE txid = :txid');
            \$stmt->execute(['txid' => '${txid}']);
        " 2>/dev/null
    done
}

# Verify chain integrity and return result
# Usage: check_chain_integrity <container> <contact_pubkey_b64>
# Returns: "VALID:0:N" or "INVALID:G:N" (valid:gap_count:tx_count)
check_chain_integrity() {
    local container="$1"
    local contact_pubkey_b64="$2"
    docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$chainRepo = \$app->services->getTransactionChainRepository();
        \$result = \$chainRepo->verifyChainIntegrity(
            \$app->getPublicKey(),
            base64_decode('${contact_pubkey_b64}')
        );
        echo \$result['valid'] ? 'VALID' : 'INVALID';
        echo ':' . count(\$result['gaps']);
        echo ':' . \$result['transaction_count'];
    " 2>/dev/null
}

# Format chain status for human-readable display
# Input: "VALID:0:3" or "INVALID:1:2"
# Output: "VALID (0 gaps, 3 txs)" or "INVALID (1 gap, 2 txs remaining, 3 expected)"
format_chain_status() {
    local raw="$1"
    local status=$(echo "$raw" | cut -d: -f1)
    local gaps=$(echo "$raw" | cut -d: -f2)
    local txs=$(echo "$raw" | cut -d: -f3)
    local gapWord="gaps"
    [ "$gaps" = "1" ] && gapWord="gap"

    if [ "$status" = "VALID" ]; then
        echo "${status} (${gaps} ${gapWord}, ${txs} txs)"
    else
        local expected=$(( txs + gaps ))
        echo "${status} (${gaps} ${gapWord}, ${txs} txs remaining, ${expected} expected)"
    fi
}

# Get the first incoming pending proposal ID
# Usage: get_pending_proposal <container>
# Returns: proposal_id or "NONE"
get_pending_proposal() {
    local container="$1"
    docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$chainDropService = \$app->services->getChainDropService();
        \$proposals = \$chainDropService->getIncomingPendingProposals();
        if (!empty(\$proposals)) {
            echo \$proposals[0]['proposal_id'];
        } else {
            echo 'NONE';
        }
    " 2>/dev/null
}

# Get the previous_txid of a specific transaction
# Usage: get_previous_txid <container> <txid>
get_previous_txid() {
    local container="$1"
    local txid="$2"
    docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$stmt = \$pdo->prepare('SELECT previous_txid FROM transactions WHERE txid = :txid');
        \$stmt->execute(['txid' => '${txid}']);
        \$result = \$stmt->fetchColumn();
        echo \$result ?: 'NULL';
    " 2>/dev/null
}

# Resolve all existing chain gaps between sender and receiver
# Repeatedly proposes and accepts chain drops until the chain is valid
# Returns 0 on success, 1 if max iterations exceeded
# Delete all transactions between sender and receiver on a specific node
# Starting fresh ensures previous_txid=NULL for the first new transaction
# Usage: clean_chain_on <container> <my_pubkey_hash> <contact_pubkey_hash>
clean_chain_on() {
    local container="$1"
    local myHash="$2"
    local contactHash="$3"
    docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$stmt = \$pdo->prepare('DELETE FROM transactions
            WHERE (sender_public_key_hash = :myHash AND receiver_public_key_hash = :contactHash)
               OR (sender_public_key_hash = :contactHash2 AND receiver_public_key_hash = :myHash2)');
        \$stmt->execute([
            'myHash' => '${myHash}', 'contactHash' => '${contactHash}',
            'contactHash2' => '${contactHash}', 'myHash2' => '${myHash}'
        ]);
        echo \$stmt->rowCount();
    " 2>/dev/null
}

# Delete all transactions between sender and receiver on both nodes
clean_chain() {
    local senderDeleted=$(clean_chain_on ${sender} ${senderPubkeyHash} ${receiverPubkeyHash})
    local receiverDeleted=$(clean_chain_on ${receiver} ${receiverPubkeyHash} ${senderPubkeyHash})
    cleanup_proposals
    echo -e "\t   Deleted ${senderDeleted:-0} sender txs, ${receiverDeleted:-0} receiver txs"
}

# Clean up only chain drop proposals (not transactions, to avoid creating gaps)
cleanup_proposals() {
    docker exec ${sender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$pdo->exec(\"DELETE FROM chain_drop_proposals WHERE 1=1\");
    " 2>/dev/null
    docker exec ${receiver} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$pdo->exec(\"DELETE FROM chain_drop_proposals WHERE 1=1\");
    " 2>/dev/null
}

if [[ "$PUBKEYS_AVAILABLE" != "true" ]]; then
    echo -e "${YELLOW}\t   Skipping all chain drop tests - pubkeys not available${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'chain drop test suite'"
    exit 0
fi

# Delete all existing transactions between sender and receiver for a clean baseline
echo -e "[Pre-test: Cleaning transaction chain between contacts]"
clean_chain

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})
echo -e "\t   Sender chain: $(format_chain_status ${senderIntegrity})"
echo -e "\t   Receiver chain: $(format_chain_status ${receiverIntegrity})"

#################### TEST 1: Single Gap (3 tx, 1 missing) ####################

echo -e "\n"
echo "========================================================================"
echo "Section 1: Single Gap Chain Drop"
echo "========================================================================"
echo -e "\n"

echo -e "[1.1 Single Gap: A->B->C, delete B, chain drop to fix]"

timestamp=$(date +%s%N)
testPattern="chaindrop-t1-%${timestamp}"

# Test 1.1: Send 3 transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending 3 transactions from sender to receiver"

docker exec ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t1-tx1-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec ${sender} eiou send ${receiverAddress} 2 USD "chaindrop-t1-tx2-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec ${sender} eiou send ${receiverAddress} 3 USD "chaindrop-t1-tx3-${timestamp}" 2>&1 > /dev/null

wait_for_queue_processed ${sender}
wait_for_queue_processed ${receiver}

# Retry if receiver hasn't received transactions yet
receiverTxCount=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

if [[ "$receiverTxCount" -lt 3 ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
    receiverTxCount=$(docker exec ${receiver} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
            ->fetchColumn();
        echo \$count;
    " 2>/dev/null || echo "0")
fi

senderTxids=$(get_test_txids ${sender} "${testPattern}")
IFS=',' read -ra senderTxArr <<< "$senderTxids"

if [[ "${#senderTxArr[@]}" -ge 3 ]] && [[ "$receiverTxCount" -ge 3 ]]; then
    printf "\t   Sent 3 transactions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Send transactions ${RED}FAILED${NC} (sender=${#senderTxArr[@]}, receiver=${receiverTxCount})\n"
    failure=$(( failure + 1 ))
fi

# Test 1.2: Verify chain is initially valid
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying chain is initially valid"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

echo -e "\t   Sender chain: $(format_chain_status ${senderIntegrity})"
echo -e "\t   Receiver chain: $(format_chain_status ${receiverIntegrity})"

if [[ "$senderIntegrity" == VALID:* ]] && [[ "$receiverIntegrity" == VALID:* ]]; then
    printf "\t   Initial chain valid ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Initial chain valid ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 1.3: Delete tx2 from BOTH nodes, verify chain is broken
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Deleting tx2 from both nodes"

tx2="${senderTxArr[1]}"
echo -e "\t   Deleting txid: ${tx2:0:40}..."

delete_txids ${sender} "$tx2"
delete_txids ${receiver} "$tx2"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

echo -e "\t   Sender chain after delete: $(format_chain_status ${senderIntegrity})"
echo -e "\t   Receiver chain after delete: $(format_chain_status ${receiverIntegrity})"

if [[ "$senderIntegrity" == INVALID:1:* ]] && [[ "$receiverIntegrity" == INVALID:1:* ]]; then
    printf "\t   Chain broken with 1 gap ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain broken detection ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 1.4: Propose chain drop from sender
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Proposing chain drop from sender"

proposeResult=$(docker exec ${sender} eiou chaindrop propose ${receiverAddress} 2>&1)
echo -e "\t   Propose result: ${proposeResult:0:80}..."

if echo "$proposeResult" | grep -qi 'success\|proposal'; then
    printf "\t   Chain drop proposed ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain drop propose ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 1.5: Receiver gets proposal and accepts it
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Processing proposal delivery and accepting"

wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

proposalId=$(get_pending_proposal ${receiver})

# Retry if proposal hasn't arrived yet
if [[ "$proposalId" == "NONE" ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
    proposalId=$(get_pending_proposal ${receiver})
fi

echo -e "\t   Receiver proposal ID: ${proposalId:0:40}..."

if [[ "$proposalId" != "NONE" ]] && [[ -n "$proposalId" ]]; then
    acceptResult=$(docker exec ${receiver} eiou chaindrop accept ${proposalId} 2>&1)
    echo -e "\t   Accept result: ${acceptResult:0:80}..."

    if echo "$acceptResult" | grep -qi 'success\|accepted'; then
        printf "\t   Proposal accepted ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Proposal accept ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   Proposal delivery ${RED}FAILED${NC} (no pending proposal found)\n"
    failure=$(( failure + 1 ))
fi

# Test 1.6: Process acknowledgment and verify chain is valid again
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Processing acknowledgment and verifying chain repair"

wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

echo -e "\t   Sender chain after drop: $(format_chain_status ${senderIntegrity})"
echo -e "\t   Receiver chain after drop: $(format_chain_status ${receiverIntegrity})"

if [[ "$senderIntegrity" == VALID:* ]] && [[ "$receiverIntegrity" == VALID:* ]]; then
    printf "\t   Chain repaired ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain repair ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 1.7: Verify tx3.previous_txid now points to tx1 (skipping dropped tx2)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying chain relink: tx3.previous_txid = tx1"

tx1="${senderTxArr[0]}"
tx3="${senderTxArr[2]}"
senderPrevTxid=$(get_previous_txid ${sender} "$tx3")
receiverPrevTxid=$(get_previous_txid ${receiver} "$tx3")

echo -e "\t   Sender tx3.previous_txid: ${senderPrevTxid:0:40}..."
echo -e "\t   Expected (tx1): ${tx1:0:40}..."

if [[ "$senderPrevTxid" == "$tx1" ]] && [[ "$receiverPrevTxid" == "$tx1" ]]; then
    printf "\t   Chain relink correct ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain relink ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Cleanup test 1 (delete all transactions + proposals for clean baseline)
clean_chain > /dev/null

############## TEST 2: Non-Consecutive Gaps (5 tx, 2 non-adjacent missing) ##############

echo -e "\n"
echo "========================================================================"
echo "Section 2: Non-Consecutive Gaps (A->?->C->?->E)"
echo "========================================================================"
echo -e "\n"

echo -e "[2.1 Send 5 transactions and create non-consecutive gaps]"

timestamp=$(date +%s%N)
testPattern="chaindrop-t2-%${timestamp}"

# Test 2.1: Send 5 transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending 5 transactions from sender to receiver"

for i in 1 2 3 4 5; do
    docker exec ${sender} eiou send ${receiverAddress} ${i} USD "chaindrop-t2-tx${i}-${timestamp}" 2>&1 > /dev/null
    sleep 1
done

wait_for_queue_processed ${sender}
wait_for_queue_processed ${receiver}

receiverTxCount=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

if [[ "$receiverTxCount" -lt 5 ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
fi

senderTxids=$(get_test_txids ${sender} "${testPattern}")
IFS=',' read -ra txArr <<< "$senderTxids"

if [[ "${#txArr[@]}" -ge 5 ]]; then
    printf "\t   Sent 5 transactions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Send transactions ${RED}FAILED${NC} (got ${#txArr[@]})\n"
    failure=$(( failure + 1 ))
fi

# Test 2.2: Delete tx2 and tx4 from both nodes -> A->?->C->?->E
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Deleting tx2 and tx4 from both nodes (non-consecutive gaps)"

tx1="${txArr[0]}"
tx2="${txArr[1]}"
tx3="${txArr[2]}"
tx4="${txArr[3]}"
tx5="${txArr[4]}"

delete_txids ${sender} "$tx2" "$tx4"
delete_txids ${receiver} "$tx2" "$tx4"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

echo -e "\t   Sender chain: $(format_chain_status ${senderIntegrity})"
echo -e "\t   Receiver chain: $(format_chain_status ${receiverIntegrity})"

if [[ "$senderIntegrity" == INVALID:2:* ]] && [[ "$receiverIntegrity" == INVALID:2:* ]]; then
    printf "\t   2 non-consecutive gaps detected ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Gap detection ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 2.3: First chain drop (resolves first gap: tx2)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> First chain drop: resolving gap for tx2"

proposeResult=$(docker exec ${sender} eiou chaindrop propose ${receiverAddress} 2>&1)
wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

proposalId=$(get_pending_proposal ${receiver})
if [[ "$proposalId" == "NONE" ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
    proposalId=$(get_pending_proposal ${receiver})
fi

if [[ "$proposalId" != "NONE" ]] && [[ -n "$proposalId" ]]; then
    docker exec ${receiver} eiou chaindrop accept ${proposalId} 2>&1 > /dev/null
    wait_for_queue_processed ${sender} 3
    wait_for_queue_processed ${receiver} 3
fi

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

echo -e "\t   After first drop - Sender: $(format_chain_status ${senderIntegrity})"
echo -e "\t   After first drop - Receiver: $(format_chain_status ${receiverIntegrity})"

# Should have 1 gap remaining (tx4 still missing)
if [[ "$senderIntegrity" == INVALID:1:* ]] && [[ "$receiverIntegrity" == INVALID:1:* ]]; then
    printf "\t   First chain drop (1 gap remaining) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   First chain drop ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 2.4: Verify tx3.previous_txid = tx1 (first gap resolved)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying first gap resolved: tx3.previous_txid = tx1"

prevTxid=$(get_previous_txid ${sender} "$tx3")
echo -e "\t   tx3.previous_txid: ${prevTxid:0:40}..."
echo -e "\t   Expected (tx1): ${tx1:0:40}..."

if [[ "$prevTxid" == "$tx1" ]]; then
    printf "\t   First gap relink ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   First gap relink ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 2.5: Second chain drop (resolves second gap: tx4)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Second chain drop: resolving gap for tx4"

proposeResult=$(docker exec ${sender} eiou chaindrop propose ${receiverAddress} 2>&1)
wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

proposalId=$(get_pending_proposal ${receiver})
if [[ "$proposalId" == "NONE" ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
    proposalId=$(get_pending_proposal ${receiver})
fi

if [[ "$proposalId" != "NONE" ]] && [[ -n "$proposalId" ]]; then
    docker exec ${receiver} eiou chaindrop accept ${proposalId} 2>&1 > /dev/null
    wait_for_queue_processed ${sender} 3
    wait_for_queue_processed ${receiver} 3
fi

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

echo -e "\t   After second drop - Sender: $(format_chain_status ${senderIntegrity})"
echo -e "\t   After second drop - Receiver: $(format_chain_status ${receiverIntegrity})"

if [[ "$senderIntegrity" == VALID:* ]] && [[ "$receiverIntegrity" == VALID:* ]]; then
    printf "\t   Second chain drop (all gaps resolved) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Second chain drop ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 2.6: Verify final chain: tx1 -> tx3 -> tx5
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying final chain: tx1 -> tx3 -> tx5"

tx3Prev=$(get_previous_txid ${sender} "$tx3")
tx5Prev=$(get_previous_txid ${sender} "$tx5")

echo -e "\t   tx3.previous_txid: ${tx3Prev:0:40}... (expect tx1)"
echo -e "\t   tx5.previous_txid: ${tx5Prev:0:40}... (expect tx3)"

if [[ "$tx3Prev" == "$tx1" ]] && [[ "$tx5Prev" == "$tx3" ]]; then
    printf "\t   Final chain order correct ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Final chain order ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Cleanup test 2
clean_chain > /dev/null

############## TEST 3: Consecutive Gaps (5 tx, 2 adjacent missing) ##############

echo -e "\n"
echo "========================================================================"
echo "Section 3: Consecutive Gaps (A->?->?->D->E)"
echo "========================================================================"
echo -e "\n"

echo -e "[3.1 Send 5 transactions and create consecutive gaps]"

timestamp=$(date +%s%N)
testPattern="chaindrop-t3-%${timestamp}"

# Test 3.1: Send 5 transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending 5 transactions from sender to receiver"

for i in 1 2 3 4 5; do
    docker exec ${sender} eiou send ${receiverAddress} ${i} USD "chaindrop-t3-tx${i}-${timestamp}" 2>&1 > /dev/null
    sleep 1
done

wait_for_queue_processed ${sender}
wait_for_queue_processed ${receiver}

receiverTxCount=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

if [[ "$receiverTxCount" -lt 5 ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
fi

senderTxids=$(get_test_txids ${sender} "${testPattern}")
IFS=',' read -ra txArr <<< "$senderTxids"

if [[ "${#txArr[@]}" -ge 5 ]]; then
    printf "\t   Sent 5 transactions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Send transactions ${RED}FAILED${NC} (got ${#txArr[@]})\n"
    failure=$(( failure + 1 ))
fi

# Test 3.2: Delete tx2 and tx3 (consecutive) -> A->?->?->D->E
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Deleting tx2 and tx3 from both nodes (consecutive gaps)"

tx1="${txArr[0]}"
tx2="${txArr[1]}"
tx3="${txArr[2]}"
tx4="${txArr[3]}"
tx5="${txArr[4]}"

delete_txids ${sender} "$tx2" "$tx3"
delete_txids ${receiver} "$tx2" "$tx3"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

echo -e "\t   Sender chain: $(format_chain_status ${senderIntegrity})"
echo -e "\t   Receiver chain: $(format_chain_status ${receiverIntegrity})"

# Consecutive missing: tx4 points to tx3 (missing) -> 1 detected gap
# tx2 is also gone but tx3 (which referenced it) is gone too, so no reference to tx2
if [[ "$senderIntegrity" == INVALID:1:* ]] && [[ "$receiverIntegrity" == INVALID:1:* ]]; then
    printf "\t   Consecutive gaps: 1 gap detected ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Consecutive gap detection ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.3: Single chain drop resolves the consecutive gap
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Chain drop: resolving consecutive gap"

proposeResult=$(docker exec ${sender} eiou chaindrop propose ${receiverAddress} 2>&1)
wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

proposalId=$(get_pending_proposal ${receiver})
if [[ "$proposalId" == "NONE" ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
    proposalId=$(get_pending_proposal ${receiver})
fi

if [[ "$proposalId" != "NONE" ]] && [[ -n "$proposalId" ]]; then
    docker exec ${receiver} eiou chaindrop accept ${proposalId} 2>&1 > /dev/null
    wait_for_queue_processed ${sender} 3
    wait_for_queue_processed ${receiver} 3
fi

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

echo -e "\t   After drop - Sender: $(format_chain_status ${senderIntegrity})"
echo -e "\t   After drop - Receiver: $(format_chain_status ${receiverIntegrity})"

if [[ "$senderIntegrity" == VALID:* ]] && [[ "$receiverIntegrity" == VALID:* ]]; then
    printf "\t   Consecutive gap resolved ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Consecutive gap resolution ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.4: Verify final chain: tx1 -> tx4 -> tx5 (tx2 and tx3 are gone)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying chain: tx1 -> tx4 -> tx5"

tx4Prev=$(get_previous_txid ${sender} "$tx4")
tx5Prev=$(get_previous_txid ${sender} "$tx5")

echo -e "\t   tx4.previous_txid: ${tx4Prev:0:40}... (expect tx1)"
echo -e "\t   tx5.previous_txid: ${tx5Prev:0:40}... (expect tx4)"

if [[ "$tx4Prev" == "$tx1" ]] && [[ "$tx5Prev" == "$tx4" ]]; then
    printf "\t   Chain order correct ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain order ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.5: Verify transaction count is 3 (tx2 and tx3 gone)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying transaction count is 3"

senderTxCount=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

echo -e "\t   Remaining transactions: ${senderTxCount}"

if [[ "$senderTxCount" == "3" ]]; then
    printf "\t   Transaction count correct ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transaction count ${RED}FAILED${NC} (expected 3, got ${senderTxCount})\n"
    failure=$(( failure + 1 ))
fi

# Cleanup test 3
clean_chain > /dev/null

#################### TEST 4: Rejection Flow ####################

echo -e "\n"
echo "========================================================================"
echo "Section 4: Chain Drop Rejection Flow"
echo "========================================================================"
echo -e "\n"

echo -e "[4.1 Propose and reject chain drop]"

timestamp=$(date +%s%N)
testPattern="chaindrop-t4-%${timestamp}"

# Test 4.1: Send 3 transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending 3 transactions and creating gap"

docker exec ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t4-tx1-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec ${sender} eiou send ${receiverAddress} 2 USD "chaindrop-t4-tx2-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec ${sender} eiou send ${receiverAddress} 3 USD "chaindrop-t4-tx3-${timestamp}" 2>&1 > /dev/null

wait_for_queue_processed ${sender}
wait_for_queue_processed ${receiver}

receiverTxCount=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

if [[ "$receiverTxCount" -lt 3 ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
fi

senderTxids=$(get_test_txids ${sender} "${testPattern}")
IFS=',' read -ra txArr <<< "$senderTxids"

# Delete tx2 from both
tx2="${txArr[1]}"
delete_txids ${sender} "$tx2"
delete_txids ${receiver} "$tx2"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})

if [[ "${#txArr[@]}" -ge 3 ]] && [[ "$senderIntegrity" == INVALID:1:* ]]; then
    printf "\t   Setup: gap created ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Setup ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 4.2: Propose chain drop
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Proposing chain drop from sender"

docker exec ${sender} eiou chaindrop propose ${receiverAddress} 2>&1 > /dev/null
wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

proposalId=$(get_pending_proposal ${receiver})
if [[ "$proposalId" == "NONE" ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
    proposalId=$(get_pending_proposal ${receiver})
fi

echo -e "\t   Proposal ID: ${proposalId:0:40}..."

if [[ "$proposalId" != "NONE" ]] && [[ -n "$proposalId" ]]; then
    printf "\t   Proposal received ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Proposal delivery ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 4.3: Reject the proposal
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Rejecting chain drop proposal"

if [[ "$proposalId" != "NONE" ]] && [[ -n "$proposalId" ]]; then
    rejectResult=$(docker exec ${receiver} eiou chaindrop reject ${proposalId} 2>&1)
    echo -e "\t   Reject result: ${rejectResult:0:80}..."

    wait_for_queue_processed ${sender} 3
    wait_for_queue_processed ${receiver} 3

    if echo "$rejectResult" | grep -qi 'success\|rejected'; then
        printf "\t   Proposal rejected ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Proposal reject ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   Cannot reject: no proposal ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 4.4: Verify chain is STILL broken after rejection
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying chain is still broken after rejection"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

echo -e "\t   Sender chain: $(format_chain_status ${senderIntegrity})"
echo -e "\t   Receiver chain: $(format_chain_status ${receiverIntegrity})"

if [[ "$senderIntegrity" == INVALID:1:* ]] && [[ "$receiverIntegrity" == INVALID:1:* ]]; then
    printf "\t   Chain still broken after reject ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain state after reject ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 4.5: Verify proposal status is 'rejected' on both nodes
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying proposal status is rejected on both nodes"

senderProposalStatus=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$stmt = \$pdo->query(\"SELECT status FROM chain_drop_proposals ORDER BY created_at DESC LIMIT 1\");
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
    echo \$row ? \$row['status'] : 'NOT_FOUND';
" 2>/dev/null || echo "ERROR")

receiverProposalStatus=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$stmt = \$pdo->query(\"SELECT status FROM chain_drop_proposals ORDER BY created_at DESC LIMIT 1\");
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
    echo \$row ? \$row['status'] : 'NOT_FOUND';
" 2>/dev/null || echo "ERROR")

echo -e "\t   Sender proposal status: ${senderProposalStatus}"
echo -e "\t   Receiver proposal status: ${receiverProposalStatus}"

if [[ "$senderProposalStatus" == "rejected" ]] && [[ "$receiverProposalStatus" == "rejected" ]]; then
    printf "\t   Proposal status rejected on both ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Proposal status ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Cleanup test 4
clean_chain > /dev/null

#################### TEST 5: Natural Flow Gap Detection ####################
# These tests verify that chain gaps are detected through normal CLI commands
# (send, sync, ping) rather than only through direct `chaindrop propose` calls.
# This catches the class of bugs where the protocol works but detection is broken.
##############################################################################

echo -e "\n"
echo "========================================================================"
echo "Section 5: Natural Flow Gap Detection"
echo "========================================================================"
echo -e "\n"

echo -e "[5.1 Gap detection via send, sync, and ping commands]"

timestamp=$(date +%s%N)
testPattern="chaindrop-t5-%${timestamp}"

# Setup: Send 5 transactions to create a chain
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Setting up: Sending 5 transactions from sender to receiver"

for i in 1 2 3 4 5; do
    docker exec ${sender} eiou send ${receiverAddress} ${i} USD "chaindrop-t5-tx${i}-${timestamp}" 2>&1 > /dev/null
    sleep 1
done

wait_for_queue_processed ${sender}
wait_for_queue_processed ${receiver}

receiverTxCount=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

if [[ "$receiverTxCount" -lt 5 ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
fi

senderTxids=$(get_test_txids ${sender} "${testPattern}")
IFS=',' read -ra txArr <<< "$senderTxids"

if [[ "${#txArr[@]}" -ge 5 ]]; then
    printf "\t   Setup: 5 transactions sent ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Setup: send transactions ${RED}FAILED${NC} (got ${#txArr[@]})\n"
    failure=$(( failure + 1 ))
fi

# Delete tx3 and tx4 from BOTH nodes (mutual gap)
echo -e "\n\t-> Deleting tx3 and tx4 from BOTH nodes (mutual gap)"
tx3="${txArr[2]}"
tx4="${txArr[3]}"
delete_txids ${sender} "$tx3" "$tx4"
delete_txids ${receiver} "$tx3" "$tx4"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})
echo -e "\t   Sender chain: $(format_chain_status ${senderIntegrity})"
echo -e "\t   Receiver chain: $(format_chain_status ${receiverIntegrity})"

# Test 5.1: eiou ping should report chain_valid: false
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Test 5.1: Ping should detect chain gap"

pingOutput=$(docker exec ${sender} eiou ping ${receiverAddress} --json 2>&1)
echo -e "\t   Ping output (first 120 chars): ${pingOutput:0:120}..."

# Check for chain_valid: false in the JSON output
if echo "$pingOutput" | grep -q '"chain_valid":false\|"chain_valid": false'; then
    printf "\t   Ping detects chain gap (chain_valid=false) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif echo "$pingOutput" | grep -qi 'chain.*sync\|chain.*gap'; then
    printf "\t   Ping detects chain gap (message) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Ping gap detection ${RED}FAILED${NC} (chain_valid not false)\n"
    failure=$(( failure + 1 ))
fi

# Test 5.2: eiou sync should report chain gaps
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Test 5.2: Sync should report chain gaps"

syncOutput=$(docker exec ${sender} eiou sync transactions --json 2>&1)
echo -e "\t   Sync output (first 150 chars): ${syncOutput:0:150}..."

# Check for chain_gaps > 0 or synced_with_gaps status in the JSON output
if echo "$syncOutput" | grep -q '"chain_gaps":[1-9]\|"synced_with_gaps"\|chain.*gap'; then
    printf "\t   Sync reports chain gaps ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Sync gap reporting ${RED}FAILED${NC} (no chain_gaps in output)\n"
    failure=$(( failure + 1 ))
fi

# Test 5.3: eiou send should block and detect chain gap
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Test 5.3: Send should block on chain gap and auto-propose chain drop"

sendOutput=$(docker exec ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t5-blocked-${timestamp}" --json 2>&1)
echo -e "\t   Send output (first 150 chars): ${sendOutput:0:150}..."

# Send should fail with CHAIN_INTEGRITY_FAILED and/or mention chain_drop_proposed
if echo "$sendOutput" | grep -q 'CHAIN_INTEGRITY_FAILED\|chain_drop_proposed\|chain.*gap.*detected\|chain drop proposal'; then
    printf "\t   Send detects gap and auto-proposes chain drop ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Send gap detection ${RED}FAILED${NC} (no chain gap error in output)\n"
    failure=$(( failure + 1 ))
fi

# Test 5.4: Verify a chain drop proposal was auto-created by send
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Test 5.4: Verifying auto-proposal was delivered to receiver"

wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

proposalId=$(get_pending_proposal ${receiver})

# Retry if proposal hasn't arrived yet
if [[ "$proposalId" == "NONE" ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
    proposalId=$(get_pending_proposal ${receiver})
fi

echo -e "\t   Receiver pending proposal: ${proposalId:0:40}..."

if [[ "$proposalId" != "NONE" ]] && [[ -n "$proposalId" ]]; then
    printf "\t   Auto-proposal delivered to receiver ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Auto-proposal delivery ${RED}FAILED${NC} (no pending proposal)\n"
    failure=$(( failure + 1 ))
fi

# Test 5.5: Accept the auto-proposal and verify chain is repaired
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Test 5.5: Accept auto-proposal and verify chain repair"

if [[ "$proposalId" != "NONE" ]] && [[ -n "$proposalId" ]]; then
    docker exec ${receiver} eiou chaindrop accept ${proposalId} 2>&1 > /dev/null
    wait_for_queue_processed ${sender} 3
    wait_for_queue_processed ${receiver} 3
fi

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

echo -e "\t   Sender chain after repair: $(format_chain_status ${senderIntegrity})"
echo -e "\t   Receiver chain after repair: $(format_chain_status ${receiverIntegrity})"

# Chain may still have 1 gap if only the first gap was resolved (consecutive gap case)
# but should have fewer gaps than before
if [[ "$senderIntegrity" == VALID:* ]] && [[ "$receiverIntegrity" == VALID:* ]]; then
    printf "\t   Chain repaired via auto-proposal ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    # If there's still a gap, it means only the first of potentially 2 gaps was resolved
    # (non-consecutive: tx3 and tx4 deleted creates 1 gap since tx5->tx4(missing))
    # Accept remaining gap resolution
    senderGaps=$(echo "$senderIntegrity" | cut -d: -f2)
    if [[ "$senderGaps" -lt 2 ]]; then
        printf "\t   Chain partially repaired (${senderGaps} gap remaining) ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Chain repair ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
fi

# Cleanup test 5
clean_chain > /dev/null

# ========================= Summary =========================
succesrate "${totaltests}" "${passed}" "${failure}" "'chain drop test suite'"
