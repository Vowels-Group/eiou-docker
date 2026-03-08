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
            WHERE ((sender_public_key_hash = :myHash AND receiver_public_key_hash = :contactHash)
               OR (sender_public_key_hash = :contactHash2 AND receiver_public_key_hash = :myHash2))
               AND tx_type != :contactType');
        \$stmt->execute([
            'myHash' => '${myHash}', 'contactHash' => '${contactHash}',
            'contactHash2' => '${contactHash}', 'myHash2' => '${myHash}',
            'contactType' => 'contact'
        ]);
        echo \$stmt->rowCount();
    " 2>/dev/null
}

# Delete all transactions between sender and receiver on both nodes
clean_chain() {
    local senderDeleted=$(clean_chain_on ${sender} ${senderPubkeyHash} ${receiverPubkeyHash})
    local receiverDeleted=$(clean_chain_on ${receiver} ${receiverPubkeyHash} ${senderPubkeyHash})
    cleanup_proposals
    # Remove backups so proposeChainDrop doesn't short-circuit via backup recovery
    # (backup recovery returns early without sending a proposal to the contact)
    cleanup_backups ${sender}
    cleanup_backups ${receiver}
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
echo -e "\n[Pre-test: Cleaning transaction chain between contacts]"
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

docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t1-tx1-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 2 USD "chaindrop-t1-tx2-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 3 USD "chaindrop-t1-tx3-${timestamp}" 2>&1 > /dev/null

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
    docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} ${i} USD "chaindrop-t2-tx${i}-${timestamp}" 2>&1 > /dev/null
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

# Test 2.4: Verify tx3.previous_txid = tx1 (first gap resolved) on BOTH sides
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying first gap resolved: tx3.previous_txid = tx1 (both sides)"

senderPrevTx3=$(get_previous_txid ${sender} "$tx3")
receiverPrevTx3=$(get_previous_txid ${receiver} "$tx3")
echo -e "\t   Sender   tx3.previous_txid: ${senderPrevTx3:0:40}..."
echo -e "\t   Receiver tx3.previous_txid: ${receiverPrevTx3:0:40}..."
echo -e "\t   Expected (tx1):              ${tx1:0:40}..."

if [[ "$senderPrevTx3" == "$tx1" ]] && [[ "$receiverPrevTx3" == "$tx1" ]]; then
    printf "\t   First gap relink correct on both nodes ${GREEN}PASSED${NC}\n"
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

# Test 2.6: Verify final chain: tx1 -> tx3 -> tx5 on BOTH sides
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying final chain: tx1 -> tx3 -> tx5 (both sides)"

sTx3Prev=$(get_previous_txid ${sender} "$tx3")
sTx5Prev=$(get_previous_txid ${sender} "$tx5")
rTx3Prev=$(get_previous_txid ${receiver} "$tx3")
rTx5Prev=$(get_previous_txid ${receiver} "$tx5")

echo -e "\t   Sender   tx3.previous_txid: ${sTx3Prev:0:40}... (expect tx1)"
echo -e "\t   Sender   tx5.previous_txid: ${sTx5Prev:0:40}... (expect tx3)"
echo -e "\t   Receiver tx3.previous_txid: ${rTx3Prev:0:40}... (expect tx1)"
echo -e "\t   Receiver tx5.previous_txid: ${rTx5Prev:0:40}... (expect tx3)"

if [[ "$sTx3Prev" == "$tx1" ]] && [[ "$sTx5Prev" == "$tx3" ]] && [[ "$rTx3Prev" == "$tx1" ]] && [[ "$rTx5Prev" == "$tx3" ]]; then
    printf "\t   Final chain order correct on both nodes ${GREEN}PASSED${NC}\n"
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
    docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} ${i} USD "chaindrop-t3-tx${i}-${timestamp}" 2>&1 > /dev/null
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

# Test 3.4: Verify final chain: tx1 -> tx4 -> tx5 (tx2 and tx3 are gone) on BOTH sides
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying chain: tx1 -> tx4 -> tx5 (both sides)"

sTx4Prev=$(get_previous_txid ${sender} "$tx4")
sTx5Prev=$(get_previous_txid ${sender} "$tx5")
rTx4Prev=$(get_previous_txid ${receiver} "$tx4")
rTx5Prev=$(get_previous_txid ${receiver} "$tx5")

echo -e "\t   Sender   tx4.previous_txid: ${sTx4Prev:0:40}... (expect tx1)"
echo -e "\t   Sender   tx5.previous_txid: ${sTx5Prev:0:40}... (expect tx4)"
echo -e "\t   Receiver tx4.previous_txid: ${rTx4Prev:0:40}... (expect tx1)"
echo -e "\t   Receiver tx5.previous_txid: ${rTx5Prev:0:40}... (expect tx4)"

if [[ "$sTx4Prev" == "$tx1" ]] && [[ "$sTx5Prev" == "$tx4" ]] && [[ "$rTx4Prev" == "$tx1" ]] && [[ "$rTx5Prev" == "$tx4" ]]; then
    printf "\t   Chain order correct on both nodes ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain order ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.5: Verify transaction count is 3 on BOTH sides (tx2 and tx3 gone)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying transaction count is 3 (both sides)"

senderTxCount=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

receiverTxCount=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

echo -e "\t   Sender: ${senderTxCount} txs, Receiver: ${receiverTxCount} txs (expect 3/3)"

if [[ "$senderTxCount" == "3" ]] && [[ "$receiverTxCount" == "3" ]]; then
    printf "\t   Transaction count correct on both ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transaction count ${RED}FAILED${NC} (expected 3/3, got ${senderTxCount}/${receiverTxCount})\n"
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

docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t4-tx1-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 2 USD "chaindrop-t4-tx2-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 3 USD "chaindrop-t4-tx3-${timestamp}" 2>&1 > /dev/null

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
    docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} ${i} USD "chaindrop-t5-tx${i}-${timestamp}" 2>&1 > /dev/null
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

pingOutput=$(docker exec -e EIOU_TEST_MODE=true ${sender} eiou ping ${receiverAddress} --json 2>&1)
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

sendOutput=$(docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t5-blocked-${timestamp}" --json 2>&1)
echo -e "\t   Send output (first 150 chars): ${sendOutput:0:150}..."

# Send should fail with CHAIN_INTEGRITY_FAILED and/or mention chain_drop_proposed
if echo "$sendOutput" | grep -q 'CHAIN_INTEGRITY_FAILED\|chain_drop_proposed\|chain.*gap.*detected\|chain drop proposal'; then
    printf "\t   Send detects gap and auto-proposes chain drop ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Send gap detection ${RED}FAILED${NC} (no chain gap error in output)\n"
    failure=$(( failure + 1 ))
fi

# Cleanup detection-only tests before full flow tests
clean_chain > /dev/null

#################### TEST 6: Send-Triggered Full Flow (single gap) ####################
# Full end-to-end: send detects gap -> auto-proposes -> accept -> verify relink -> new send works
########################################################################################

echo -e "\n"
echo "========================================================================"
echo "Section 6: Send-Triggered Full Chain Drop Flow"
echo "========================================================================"
echo -e "\n"

echo -e "[6.1 Send detects single gap, auto-proposes, both agree, chain repaired]"

timestamp=$(date +%s%N)
testPattern="chaindrop-t6-%${timestamp}"

# Setup: Send 5 transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending 5 transactions to build chain"

for i in 1 2 3 4 5; do
    docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} ${i} USD "chaindrop-t6-tx${i}-${timestamp}" 2>&1 > /dev/null
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

tx1="${txArr[0]}"
tx2="${txArr[1]}"
tx3="${txArr[2]}"
tx4="${txArr[3]}"
tx5="${txArr[4]}"

# Create single gap: delete tx3 from both (tx4 -> tx3(missing) -> tx2)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Deleting tx3 from BOTH nodes (single mutual gap)"
delete_txids ${sender} "$tx3"
delete_txids ${receiver} "$tx3"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})
echo -e "\t   Sender chain: $(format_chain_status ${senderIntegrity})"
echo -e "\t   Receiver chain: $(format_chain_status ${receiverIntegrity})"

if [[ "$senderIntegrity" == INVALID:1:* ]] && [[ "$receiverIntegrity" == INVALID:1:* ]]; then
    printf "\t   Single gap created on both nodes ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Gap creation ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 6.1: Send triggers gap detection and auto-proposes chain drop
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Attempting send (should fail and auto-propose chain drop)"

sendOutput=$(docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t6-blocked-${timestamp}" --json 2>&1)
echo -e "\t   Send output (first 150 chars): ${sendOutput:0:150}..."

if echo "$sendOutput" | grep -q 'CHAIN_INTEGRITY_FAILED\|chain_drop_proposed\|chain.*gap.*detected\|chain drop proposal'; then
    printf "\t   Send detected gap and auto-proposed ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Send gap detection ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 6.2: Receiver gets the auto-proposal
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Waiting for auto-proposal delivery to receiver"

wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

proposalId=$(get_pending_proposal ${receiver})
if [[ "$proposalId" == "NONE" ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
    proposalId=$(get_pending_proposal ${receiver})
fi

echo -e "\t   Receiver proposal ID: ${proposalId:0:40}..."

if [[ "$proposalId" != "NONE" ]] && [[ -n "$proposalId" ]]; then
    printf "\t   Auto-proposal delivered ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Auto-proposal delivery ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 6.3: Receiver accepts the proposal
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Receiver accepts chain drop proposal"

if [[ "$proposalId" != "NONE" ]] && [[ -n "$proposalId" ]]; then
    acceptResult=$(docker exec ${receiver} eiou chaindrop accept ${proposalId} 2>&1)
    echo -e "\t   Accept result: ${acceptResult:0:80}..."

    wait_for_queue_processed ${sender} 3
    wait_for_queue_processed ${receiver} 3

    if echo "$acceptResult" | grep -qi 'success\|accepted'; then
        printf "\t   Proposal accepted ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Proposal accept ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   Cannot accept: no proposal ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 6.4: Chain is valid on BOTH nodes after drop
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying chain is valid on both nodes"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

echo -e "\t   Sender chain: $(format_chain_status ${senderIntegrity})"
echo -e "\t   Receiver chain: $(format_chain_status ${receiverIntegrity})"

if [[ "$senderIntegrity" == VALID:* ]] && [[ "$receiverIntegrity" == VALID:* ]]; then
    printf "\t   Chain valid on both nodes ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain validity ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 6.5: Verify chain relink - tx4.previous_txid should now point to tx2
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying chain relink: tx4.previous_txid = tx2 (skipping dropped tx3)"

senderPrev=$(get_previous_txid ${sender} "$tx4")
receiverPrev=$(get_previous_txid ${receiver} "$tx4")

echo -e "\t   Sender tx4.previous_txid:   ${senderPrev:0:40}..."
echo -e "\t   Receiver tx4.previous_txid: ${receiverPrev:0:40}..."
echo -e "\t   Expected (tx2):              ${tx2:0:40}..."

if [[ "$senderPrev" == "$tx2" ]] && [[ "$receiverPrev" == "$tx2" ]]; then
    printf "\t   Chain relink correct on both nodes ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain relink ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 6.6: Verify remaining chain order: tx1 -> tx2 -> tx4 -> tx5 (tx3 gone) on BOTH sides
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying full chain order: tx1 -> tx2 -> tx4 -> tx5 (both sides)"

sTx1Prev=$(get_previous_txid ${sender} "$tx1")
sTx2Prev=$(get_previous_txid ${sender} "$tx2")
sTx4Prev=$(get_previous_txid ${sender} "$tx4")
sTx5Prev=$(get_previous_txid ${sender} "$tx5")
rTx1Prev=$(get_previous_txid ${receiver} "$tx1")
rTx2Prev=$(get_previous_txid ${receiver} "$tx2")
rTx4Prev=$(get_previous_txid ${receiver} "$tx4")
rTx5Prev=$(get_previous_txid ${receiver} "$tx5")

echo -e "\t   Sender chain:"
echo -e "\t     tx1.previous_txid: ${sTx1Prev} (expect NULL)"
echo -e "\t     tx2.previous_txid: ${sTx2Prev:0:40}... (expect tx1)"
echo -e "\t     tx4.previous_txid: ${sTx4Prev:0:40}... (expect tx2)"
echo -e "\t     tx5.previous_txid: ${sTx5Prev:0:40}... (expect tx4)"
echo -e "\t   Receiver chain:"
echo -e "\t     tx1.previous_txid: ${rTx1Prev} (expect NULL)"
echo -e "\t     tx2.previous_txid: ${rTx2Prev:0:40}... (expect tx1)"
echo -e "\t     tx4.previous_txid: ${rTx4Prev:0:40}... (expect tx2)"
echo -e "\t     tx5.previous_txid: ${rTx5Prev:0:40}... (expect tx4)"

if [[ "$sTx1Prev" == "NULL" ]] && [[ "$sTx2Prev" == "$tx1" ]] && [[ "$sTx4Prev" == "$tx2" ]] && [[ "$sTx5Prev" == "$tx4" ]] && \
   [[ "$rTx1Prev" == "NULL" ]] && [[ "$rTx2Prev" == "$tx1" ]] && [[ "$rTx4Prev" == "$tx2" ]] && [[ "$rTx5Prev" == "$tx4" ]]; then
    printf "\t   Full chain order correct on both nodes ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Full chain order ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 6.7: Transaction count is 4 on both nodes (tx3 is gone)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying transaction count is 4 (tx3 dropped)"

senderCount=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

receiverCount=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

echo -e "\t   Sender: ${senderCount} txs, Receiver: ${receiverCount} txs"

if [[ "$senderCount" == "4" ]] && [[ "$receiverCount" == "4" ]]; then
    printf "\t   Transaction count correct on both ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transaction count ${RED}FAILED${NC} (expected 4/4)\n"
    failure=$(( failure + 1 ))
fi

# Test 6.8: New send succeeds after chain repair
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending new transaction after repair (should succeed)"

sendOutput=$(docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t6-post-repair-${timestamp}" --json 2>&1)
echo -e "\t   Send output (first 120 chars): ${sendOutput:0:120}..."

wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

# Verify send succeeded (no CHAIN_INTEGRITY_FAILED error)
if echo "$sendOutput" | grep -q 'CHAIN_INTEGRITY_FAILED'; then
    printf "\t   Post-repair send ${RED}FAILED${NC} (still blocked by chain gap)\n"
    failure=$(( failure + 1 ))
elif echo "$sendOutput" | grep -qi 'success\|queued\|sent'; then
    printf "\t   Post-repair send succeeded ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Post-repair send ${RED}FAILED${NC} (unexpected output)\n"
    failure=$(( failure + 1 ))
fi

# Test 6.9: Ping reports chain_valid: true after repair
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Ping after repair (should report chain_valid: true)"

wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

pingOutput=$(docker exec -e EIOU_TEST_MODE=true ${sender} eiou ping ${receiverAddress} --json 2>&1)
echo -e "\t   Ping output (first 120 chars): ${pingOutput:0:120}..."

if echo "$pingOutput" | grep -q '"chain_valid":true\|"chain_valid": true'; then
    printf "\t   Ping reports chain valid after repair ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif echo "$pingOutput" | grep -qi 'chain is valid'; then
    printf "\t   Ping reports chain valid (message) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Ping chain_valid after repair ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Cleanup test 6
clean_chain > /dev/null

#################### TEST 7: Ping-Triggered Full Flow (consecutive gaps) ####################
# Full end-to-end: ping detects gap -> manual propose -> accept -> verify relink -> new send works
# Uses consecutive gaps (tx2 + tx3 deleted) which result in a single chain drop
##############################################################################################

echo -e "\n"
echo "========================================================================"
echo "Section 7: Ping-Triggered Full Chain Drop Flow (consecutive gaps)"
echo "========================================================================"
echo -e "\n"

echo -e "[7.1 Ping detects consecutive gaps, manual propose, both agree, chain repaired]"

timestamp=$(date +%s%N)
testPattern="chaindrop-t7-%${timestamp}"

# Setup: Send 5 transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending 5 transactions to build chain"

for i in 1 2 3 4 5; do
    docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} ${i} USD "chaindrop-t7-tx${i}-${timestamp}" 2>&1 > /dev/null
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

tx1="${txArr[0]}"
tx2="${txArr[1]}"
tx3="${txArr[2]}"
tx4="${txArr[3]}"
tx5="${txArr[4]}"

# Create consecutive gap: delete tx2 + tx3 from both
# Chain becomes: tx1 -> ?(tx2 missing) -> ?(tx3 missing) -> tx4 -> tx5
# Since tx3 (which pointed to tx2) is also gone, only 1 gap detected: tx4 -> tx3(missing)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Deleting tx2 + tx3 from BOTH nodes (consecutive mutual gap)"
delete_txids ${sender} "$tx2" "$tx3"
delete_txids ${receiver} "$tx2" "$tx3"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
echo -e "\t   Sender chain: $(format_chain_status ${senderIntegrity})"

if [[ "$senderIntegrity" == INVALID:1:* ]]; then
    printf "\t   Consecutive gap created (1 detected gap) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Gap creation ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 7.1: Ping detects the gap
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Ping to detect chain gap"

pingOutput=$(docker exec -e EIOU_TEST_MODE=true ${sender} eiou ping ${receiverAddress} --json 2>&1)
echo -e "\t   Ping output (first 120 chars): ${pingOutput:0:120}..."

if echo "$pingOutput" | grep -q '"chain_valid":false\|"chain_valid": false' || echo "$pingOutput" | grep -qi 'chain.*sync\|chain.*gap'; then
    printf "\t   Ping detects gap ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Ping gap detection ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 7.2: Manual chain drop propose (triggered by user after seeing ping result)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Manual chain drop propose after ping detection"

proposeResult=$(docker exec ${sender} eiou chaindrop propose ${receiverAddress} 2>&1)
echo -e "\t   Propose result: ${proposeResult:0:80}..."

wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

if echo "$proposeResult" | grep -qi 'success\|proposal'; then
    printf "\t   Chain drop proposed ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain drop propose ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 7.3: Receiver accepts
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Receiver accepts proposal"

proposalId=$(get_pending_proposal ${receiver})
if [[ "$proposalId" == "NONE" ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
    proposalId=$(get_pending_proposal ${receiver})
fi

if [[ "$proposalId" != "NONE" ]] && [[ -n "$proposalId" ]]; then
    acceptResult=$(docker exec ${receiver} eiou chaindrop accept ${proposalId} 2>&1)
    echo -e "\t   Accept result: ${acceptResult:0:80}..."

    wait_for_queue_processed ${sender} 3
    wait_for_queue_processed ${receiver} 3

    if echo "$acceptResult" | grep -qi 'success\|accepted'; then
        printf "\t   Proposal accepted ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Proposal accept ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   No proposal to accept ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 7.4: Chain valid on both nodes
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying chain is valid on both nodes"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

echo -e "\t   Sender chain: $(format_chain_status ${senderIntegrity})"
echo -e "\t   Receiver chain: $(format_chain_status ${receiverIntegrity})"

if [[ "$senderIntegrity" == VALID:* ]] && [[ "$receiverIntegrity" == VALID:* ]]; then
    printf "\t   Chain valid on both ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain validity ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 7.5: Verify chain relink: tx4.previous_txid = tx1 (tx2 and tx3 both gone)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying relink: tx4.previous_txid = tx1 (skipping tx2 + tx3)"

senderPrev=$(get_previous_txid ${sender} "$tx4")
receiverPrev=$(get_previous_txid ${receiver} "$tx4")

echo -e "\t   Sender tx4.previous_txid:   ${senderPrev:0:40}..."
echo -e "\t   Receiver tx4.previous_txid: ${receiverPrev:0:40}..."
echo -e "\t   Expected (tx1):              ${tx1:0:40}..."

if [[ "$senderPrev" == "$tx1" ]] && [[ "$receiverPrev" == "$tx1" ]]; then
    printf "\t   Chain relink correct on both ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain relink ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 7.6: Verify full chain: tx1 -> tx4 -> tx5 and tx count = 3 on BOTH sides
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying full chain: tx1 -> tx4 -> tx5, count = 3 (both sides)"

sTx1Prev=$(get_previous_txid ${sender} "$tx1")
sTx5Prev=$(get_previous_txid ${sender} "$tx5")
rTx1Prev=$(get_previous_txid ${receiver} "$tx1")
rTx5Prev=$(get_previous_txid ${receiver} "$tx5")

senderCount=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

receiverCount=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

echo -e "\t   Sender chain:"
echo -e "\t     tx1.previous_txid: ${sTx1Prev} (expect NULL)"
echo -e "\t     tx5.previous_txid: ${sTx5Prev:0:40}... (expect tx4)"
echo -e "\t     Transaction count: ${senderCount} (expect 3)"
echo -e "\t   Receiver chain:"
echo -e "\t     tx1.previous_txid: ${rTx1Prev} (expect NULL)"
echo -e "\t     tx5.previous_txid: ${rTx5Prev:0:40}... (expect tx4)"
echo -e "\t     Transaction count: ${receiverCount} (expect 3)"

if [[ "$sTx1Prev" == "NULL" ]] && [[ "$sTx5Prev" == "$tx4" ]] && [[ "$senderCount" == "3" ]] && \
   [[ "$rTx1Prev" == "NULL" ]] && [[ "$rTx5Prev" == "$tx4" ]] && [[ "$receiverCount" == "3" ]]; then
    printf "\t   Full chain and count correct on both nodes ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Full chain/count ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 7.7: New send works after repair
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending new transaction after chain repair"

sendOutput=$(docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t7-post-repair-${timestamp}" --json 2>&1)
wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

echo -e "\t   Send output (first 120 chars): ${sendOutput:0:120}..."

if echo "$sendOutput" | grep -q 'CHAIN_INTEGRITY_FAILED'; then
    printf "\t   Post-repair send ${RED}FAILED${NC} (still blocked)\n"
    failure=$(( failure + 1 ))
elif echo "$sendOutput" | grep -qi 'success\|queued\|sent'; then
    printf "\t   Post-repair send succeeded ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Post-repair send ${RED}FAILED${NC} (unexpected output)\n"
    failure=$(( failure + 1 ))
fi

# Test 7.8: Ping reports chain valid after repair + new send
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Final ping to confirm chain validity"

pingOutput=$(docker exec -e EIOU_TEST_MODE=true ${sender} eiou ping ${receiverAddress} --json 2>&1)
echo -e "\t   Ping output (first 120 chars): ${pingOutput:0:120}..."

if echo "$pingOutput" | grep -q '"chain_valid":true\|"chain_valid": true' || echo "$pingOutput" | grep -qi 'chain is valid'; then
    printf "\t   Final ping: chain valid ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Final ping chain validity ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Cleanup test 7
clean_chain > /dev/null

#################### TEST 8: Sync-Triggered Full Flow (non-consecutive, 2 drops) ####################
# Full end-to-end: sync detects gaps -> two propose/accept rounds -> verify relink -> new send works
# Uses non-consecutive gaps (tx2 + tx4 deleted) requiring 2 sequential chain drops
######################################################################################################

echo -e "\n"
echo "========================================================================"
echo "Section 8: Sync-Triggered Full Chain Drop Flow (2 rounds)"
echo "========================================================================"
echo -e "\n"

echo -e "[8.1 Sync detects non-consecutive gaps, 2 rounds of propose/accept, chain repaired]"

timestamp=$(date +%s%N)
testPattern="chaindrop-t8-%${timestamp}"

# Setup: Send 6 transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending 6 transactions to build chain"

for i in 1 2 3 4 5 6; do
    docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} ${i} USD "chaindrop-t8-tx${i}-${timestamp}" 2>&1 > /dev/null
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

if [[ "$receiverTxCount" -lt 6 ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
fi

senderTxids=$(get_test_txids ${sender} "${testPattern}")
IFS=',' read -ra txArr <<< "$senderTxids"

if [[ "${#txArr[@]}" -ge 6 ]]; then
    printf "\t   Setup: 6 transactions sent ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Setup: send transactions ${RED}FAILED${NC} (got ${#txArr[@]})\n"
    failure=$(( failure + 1 ))
fi

tx1="${txArr[0]}"
tx2="${txArr[1]}"
tx3="${txArr[2]}"
tx4="${txArr[3]}"
tx5="${txArr[4]}"
tx6="${txArr[5]}"

# Create non-consecutive gaps: delete tx2 + tx4 from both
# Chain becomes: tx1 -> ?(tx2 missing) -> tx3 -> ?(tx4 missing) -> tx5 -> tx6
# Two gaps detected: tx3 -> tx2(missing) and tx5 -> tx4(missing)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Deleting tx2 + tx4 from BOTH nodes (non-consecutive gaps)"
delete_txids ${sender} "$tx2" "$tx4"
delete_txids ${receiver} "$tx2" "$tx4"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
echo -e "\t   Sender chain: $(format_chain_status ${senderIntegrity})"

if [[ "$senderIntegrity" == INVALID:2:* ]]; then
    printf "\t   2 non-consecutive gaps created ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Gap creation ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 8.1: Sync detects the gaps
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Running sync (should detect and report gaps)"

syncOutput=$(docker exec ${sender} eiou sync transactions --json 2>&1)
echo -e "\t   Sync output (first 150 chars): ${syncOutput:0:150}..."

if echo "$syncOutput" | grep -q '"chain_gaps":[1-9]\|"synced_with_gaps"\|chain.*gap'; then
    printf "\t   Sync detected gaps ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Sync gap detection ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 8.2: First chain drop round (resolves first gap)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Round 1: Propose and accept first chain drop"

proposeResult=$(docker exec ${sender} eiou chaindrop propose ${receiverAddress} 2>&1)
echo -e "\t   Propose result: ${proposeResult:0:80}..."

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
echo -e "\t   After round 1: $(format_chain_status ${senderIntegrity})"

if [[ "$senderIntegrity" == INVALID:1:* ]]; then
    printf "\t   Round 1: 1 gap remaining ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Round 1 ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 8.3: Second chain drop round (resolves second gap)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Round 2: Propose and accept second chain drop"

proposeResult=$(docker exec ${sender} eiou chaindrop propose ${receiverAddress} 2>&1)
echo -e "\t   Propose result: ${proposeResult:0:80}..."

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

echo -e "\t   After round 2 - Sender: $(format_chain_status ${senderIntegrity})"
echo -e "\t   After round 2 - Receiver: $(format_chain_status ${receiverIntegrity})"

if [[ "$senderIntegrity" == VALID:* ]] && [[ "$receiverIntegrity" == VALID:* ]]; then
    printf "\t   Round 2: all gaps resolved ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Round 2 ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 8.4: Verify final chain: tx1 -> tx3 -> tx5 -> tx6
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying final chain: tx1 -> tx3 -> tx5 -> tx6"

tx1Prev=$(get_previous_txid ${sender} "$tx1")
tx3Prev=$(get_previous_txid ${sender} "$tx3")
tx5Prev=$(get_previous_txid ${sender} "$tx5")
tx6Prev=$(get_previous_txid ${sender} "$tx6")

echo -e "\t   tx1.previous_txid: ${tx1Prev} (expect NULL)"
echo -e "\t   tx3.previous_txid: ${tx3Prev:0:40}... (expect tx1)"
echo -e "\t   tx5.previous_txid: ${tx5Prev:0:40}... (expect tx3)"
echo -e "\t   tx6.previous_txid: ${tx6Prev:0:40}... (expect tx5)"

# Also verify on receiver side
rx3Prev=$(get_previous_txid ${receiver} "$tx3")
rx5Prev=$(get_previous_txid ${receiver} "$tx5")
rx6Prev=$(get_previous_txid ${receiver} "$tx6")

echo -e "\t   [Receiver] tx3.prev: ${rx3Prev:0:40}... tx5.prev: ${rx5Prev:0:40}..."

if [[ "$tx1Prev" == "NULL" ]] && [[ "$tx3Prev" == "$tx1" ]] && [[ "$tx5Prev" == "$tx3" ]] && [[ "$tx6Prev" == "$tx5" ]] \
    && [[ "$rx3Prev" == "$tx1" ]] && [[ "$rx5Prev" == "$tx3" ]] && [[ "$rx6Prev" == "$tx5" ]]; then
    printf "\t   Final chain order correct on both nodes ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Final chain order ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 8.5: Transaction count is 4 on both (tx2 + tx4 dropped)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying transaction count is 4 on both nodes"

senderCount=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

receiverCount=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

echo -e "\t   Sender: ${senderCount}, Receiver: ${receiverCount} (expect 4)"

if [[ "$senderCount" == "4" ]] && [[ "$receiverCount" == "4" ]]; then
    printf "\t   Transaction count correct on both ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transaction count ${RED}FAILED${NC} (expected 4/4)\n"
    failure=$(( failure + 1 ))
fi

# Test 8.6: New send + ping both work after multi-round repair
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending new transaction + ping after 2-round repair"

sendOutput=$(docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t8-post-repair-${timestamp}" --json 2>&1)
wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

if echo "$sendOutput" | grep -q 'CHAIN_INTEGRITY_FAILED'; then
    printf "\t   Post-repair send ${RED}FAILED${NC} (still blocked)\n"
    failure=$(( failure + 1 ))
elif echo "$sendOutput" | grep -qi 'success\|queued\|sent'; then
    # Also verify ping
    pingOutput=$(docker exec -e EIOU_TEST_MODE=true ${sender} eiou ping ${receiverAddress} --json 2>&1)
    if echo "$pingOutput" | grep -q '"chain_valid":true\|"chain_valid": true' || echo "$pingOutput" | grep -qi 'chain is valid'; then
        printf "\t   Post-repair send + ping both OK ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Post-repair send OK but ping ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   Post-repair send ${RED}FAILED${NC} (unexpected output)\n"
    failure=$(( failure + 1 ))
fi

# Cleanup test 8
clean_chain > /dev/null

#################### TEST 9: Backup Recovery on Propose (Sender Side) ####################

echo -e "\n"
echo "========================================================================"
echo "Section 9: Backup Recovery During Sync (Sender Side)"
echo "========================================================================"
echo -e "\n"

echo -e "[9.0 Setup: Clean backups on both nodes]"
cleanup_backups ${sender}
cleanup_backups ${receiver}

timestamp=$(date +%s%N)
testPattern="chaindrop-t9-%${timestamp}"

# Test 9.1: Send 3 transactions and create backup on sender
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending 3 transactions and creating backup on sender"

docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t9-tx1-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 2 USD "chaindrop-t9-tx2-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 3 USD "chaindrop-t9-tx3-${timestamp}" 2>&1 > /dev/null

wait_for_queue_processed ${sender}
wait_for_queue_processed ${receiver}

# Retry delivery if needed
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

# Create backup on sender AFTER transactions are confirmed
backupResult=$(docker exec ${sender} eiou backup create "chaindrop-t9-${timestamp}" 2>&1)
echo -e "\t   Backup: ${backupResult:0:80}..."

senderTxids=$(get_test_txids ${sender} "${testPattern}")
IFS=',' read -ra senderTxArr <<< "$senderTxids"
backupCount=$(count_backups ${sender})

if [[ "${#senderTxArr[@]}" -ge 3 ]] && [[ "$backupCount" -ge 1 ]]; then
    printf "\t   3 txs sent + backup created ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Setup ${RED}FAILED${NC} (txs=${#senderTxArr[@]}, backups=${backupCount})\n"
    failure=$(( failure + 1 ))
fi

# Test 9.2: Verify chain is initially valid
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying chain is initially valid"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

if [[ "$senderIntegrity" == VALID:* ]] && [[ "$receiverIntegrity" == VALID:* ]]; then
    printf "\t   Initial chain valid ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Initial chain valid ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 9.3: Delete tx2 from BOTH nodes
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Deleting tx2 from both nodes"

tx1="${senderTxArr[0]}"
tx2="${senderTxArr[1]}"
tx3="${senderTxArr[2]}"
echo -e "\t   Deleting txid: ${tx2:0:40}..."

delete_txids ${sender} "$tx2"
delete_txids ${receiver} "$tx2"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

if [[ "$senderIntegrity" == INVALID:1:* ]] && [[ "$receiverIntegrity" == INVALID:1:* ]]; then
    printf "\t   Chain broken with 1 gap ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain broken detection ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 9.4: Sync transactions -- should recover tx2 from sender's local backup during sync
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Running sync transactions (should recover from local backup during sync)"

syncResult=$(docker exec ${sender} eiou sync transactions --json 2>&1)
echo -e "\t   Sync result: ${syncResult:0:120}..."

wait_for_queue_processed ${sender} 2

# After sync, the sender's local backup recovery should have restored tx2
senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})

if [[ "$senderIntegrity" == VALID:* ]]; then
    printf "\t   Backup recovery during sync ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Backup recovery during sync ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 9.5: Verify sender chain is valid after sync recovery
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying sender chain repaired from backup"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
echo -e "\t   Sender chain: $(format_chain_status ${senderIntegrity})"

if [[ "$senderIntegrity" == VALID:* ]]; then
    printf "\t   Sender chain repaired ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Sender chain repair ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 9.6: Verify tx2 exists in sender's database again
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying tx2 restored in sender database"

tx2Exists=$(verify_tx_exists ${sender} "$tx2")
echo -e "\t   tx2 exists: ${tx2Exists}"

if [[ "$tx2Exists" == "1" ]]; then
    printf "\t   tx2 restored from backup ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   tx2 restore ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 9.7: Verify chain links: tx3.previous_txid = tx2
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying chain links preserved (tx3 -> tx2 -> tx1)"

tx3Prev=$(get_previous_txid ${sender} "$tx3")
tx2Prev=$(get_previous_txid ${sender} "$tx2")
echo -e "\t   tx3.previous_txid: ${tx3Prev:0:40}... (expect tx2)"
echo -e "\t   tx2.previous_txid: ${tx2Prev:0:40}... (expect tx1)"

if [[ "$tx3Prev" == "$tx2" ]] && [[ "$tx2Prev" == "$tx1" ]]; then
    printf "\t   Chain links correct ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain links ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 9.8: No chain drop proposal should have been created on receiver
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying no proposal was sent to receiver"

wait_for_queue_processed ${sender} 2
wait_for_queue_processed ${receiver} 2

proposalId=$(get_pending_proposal ${receiver})
echo -e "\t   Receiver pending proposals: ${proposalId}"

if [[ "$proposalId" == "NONE" ]] || [[ -z "$proposalId" ]]; then
    printf "\t   No proposal created (sync-level recovery worked) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Unexpected proposal created ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Cleanup test 9
clean_chain > /dev/null
cleanup_backups ${sender}
cleanup_backups ${receiver}

#################### TEST 10: Backup Recovery on Propose (Receiver Side) ####################

echo -e "\n"
echo "========================================================================"
echo "Section 10: Backup Recovery During Sync (Receiver Side)"
echo "========================================================================"
echo -e "\n"

timestamp=$(date +%s%N)
testPattern="chaindrop-t10-%${timestamp}"

# Test 10.1: Send 3 transactions and create backup on receiver
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending 3 transactions and creating backup on receiver"

docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t10-tx1-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 2 USD "chaindrop-t10-tx2-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 3 USD "chaindrop-t10-tx3-${timestamp}" 2>&1 > /dev/null

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

# Create backup on RECEIVER
backupResult=$(docker exec ${receiver} eiou backup create "chaindrop-t10-${timestamp}" 2>&1)

senderTxids=$(get_test_txids ${sender} "${testPattern}")
IFS=',' read -ra senderTxArr <<< "$senderTxids"
backupCount=$(count_backups ${receiver})

if [[ "${#senderTxArr[@]}" -ge 3 ]] && [[ "$backupCount" -ge 1 ]]; then
    printf "\t   3 txs sent + receiver backup created ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Setup ${RED}FAILED${NC} (txs=${#senderTxArr[@]}, backups=${backupCount})\n"
    failure=$(( failure + 1 ))
fi

# Test 10.2: Delete tx2 from both nodes
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Deleting tx2 from both nodes"

tx1="${senderTxArr[0]}"
tx2="${senderTxArr[1]}"
tx3="${senderTxArr[2]}"

delete_txids ${sender} "$tx2"
delete_txids ${receiver} "$tx2"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

if [[ "$senderIntegrity" == INVALID:1:* ]] && [[ "$receiverIntegrity" == INVALID:1:* ]]; then
    printf "\t   Chain broken ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain broken detection ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 10.3: Sync from RECEIVER -- should recover from receiver's local backup during sync
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Running sync from receiver (should recover from local backup during sync)"

syncResult=$(docker exec ${receiver} eiou sync transactions --json 2>&1)
echo -e "\t   Sync result: ${syncResult:0:120}..."

wait_for_queue_processed ${receiver} 2

receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

if [[ "$receiverIntegrity" == VALID:* ]]; then
    printf "\t   Receiver sync-level backup recovery ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Receiver sync-level backup recovery ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 10.4: Verify receiver chain is valid
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying receiver chain repaired from backup"

receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})
echo -e "\t   Receiver chain: $(format_chain_status ${receiverIntegrity})"

if [[ "$receiverIntegrity" == VALID:* ]]; then
    printf "\t   Receiver chain repaired ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Receiver chain repair ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 10.5: Verify tx2 exists in receiver's database
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying tx2 restored in receiver database"

tx2Exists=$(verify_tx_exists ${receiver} "$tx2")

if [[ "$tx2Exists" == "1" ]]; then
    printf "\t   tx2 restored from receiver backup ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   tx2 restore ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 10.6: Verify chain links on receiver
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying receiver chain links preserved"

tx3Prev=$(get_previous_txid ${receiver} "$tx3")
tx2Prev=$(get_previous_txid ${receiver} "$tx2")

if [[ "$tx3Prev" == "$tx2" ]] && [[ "$tx2Prev" == "$tx1" ]]; then
    printf "\t   Receiver chain links correct ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Receiver chain links ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Cleanup test 10
clean_chain > /dev/null
cleanup_backups ${sender}
cleanup_backups ${receiver}

#################### TEST 11: Backup Recovery During Sync ####################

echo -e "\n"
echo "========================================================================"
echo "Section 11: Backup Recovery During Sync"
echo "========================================================================"
echo -e "\n"

timestamp=$(date +%s%N)
testPattern="chaindrop-t11-%${timestamp}"

# Test 11.1: Send 3 transactions, backup on sender
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending 3 transactions, creating backup on sender"

docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t11-tx1-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 2 USD "chaindrop-t11-tx2-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 3 USD "chaindrop-t11-tx3-${timestamp}" 2>&1 > /dev/null

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

backupResult=$(docker exec ${sender} eiou backup create "chaindrop-t11-${timestamp}" 2>&1)

senderTxids=$(get_test_txids ${sender} "${testPattern}")
IFS=',' read -ra senderTxArr <<< "$senderTxids"

if [[ "${#senderTxArr[@]}" -ge 3 ]]; then
    printf "\t   Setup complete ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Setup ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 11.2: Delete tx2 from both nodes
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Deleting tx2 from both nodes"

tx1="${senderTxArr[0]}"
tx2="${senderTxArr[1]}"
tx3="${senderTxArr[2]}"

delete_txids ${sender} "$tx2"
delete_txids ${receiver} "$tx2"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})

if [[ "$senderIntegrity" == INVALID:1:* ]]; then
    printf "\t   Chain broken ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain broken detection ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 11.3: Run sync on sender -- gap detected, backup recovery should trigger during sync
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Running sync transactions directly (should recover from local backup during sync)"

# Sync now checks local backups before contacting the remote node.
# Since sender has a backup with tx2, it should self-repair during sync.
syncResult=$(docker exec ${sender} eiou sync transactions --json 2>&1)
echo -e "\t   Sync result: ${syncResult:0:120}..."

wait_for_queue_processed ${sender} 3

# After sync, local backup recovery should have restored tx2
senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
echo -e "\t   Sender chain after sync: $(format_chain_status ${senderIntegrity})"

if [[ "$senderIntegrity" == VALID:* ]]; then
    printf "\t   Backup recovery during direct sync ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Backup recovery during sync ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 11.4: Verify tx2 was restored
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying tx2 restored in sender database"

tx2Exists=$(verify_tx_exists ${sender} "$tx2")

if [[ "$tx2Exists" == "1" ]]; then
    printf "\t   tx2 restored during sync path ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   tx2 restore ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 11.5: Verify full tx count (3 original + possibly 1 new send)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying all 3 original transactions present"

senderCount=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

echo -e "\t   Sender test tx count: ${senderCount} (expect 3)"

if [[ "$senderCount" -ge 3 ]]; then
    printf "\t   All original transactions present ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transaction count ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Cleanup test 11
clean_chain > /dev/null
cleanup_backups ${sender}
cleanup_backups ${receiver}

#################### TEST 12: Backup Recovery During Ping ####################

echo -e "\n"
echo "========================================================================"
echo "Section 12: Backup Recovery During Ping"
echo "========================================================================"
echo -e "\n"

timestamp=$(date +%s%N)
testPattern="chaindrop-t12-%${timestamp}"

# Test 12.1: Send 3 transactions, backup on sender
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending 3 transactions, creating backup on sender"

docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t12-tx1-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 2 USD "chaindrop-t12-tx2-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 3 USD "chaindrop-t12-tx3-${timestamp}" 2>&1 > /dev/null

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

backupResult=$(docker exec ${sender} eiou backup create "chaindrop-t12-${timestamp}" 2>&1)

senderTxids=$(get_test_txids ${sender} "${testPattern}")
IFS=',' read -ra senderTxArr <<< "$senderTxids"

if [[ "${#senderTxArr[@]}" -ge 3 ]]; then
    printf "\t   Setup complete ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Setup ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 12.2: Delete tx2 from both nodes
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Deleting tx2 from both nodes"

tx1="${senderTxArr[0]}"
tx2="${senderTxArr[1]}"
tx3="${senderTxArr[2]}"

delete_txids ${sender} "$tx2"
delete_txids ${receiver} "$tx2"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})

if [[ "$senderIntegrity" == INVALID:1:* ]]; then
    printf "\t   Chain broken ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain broken detection ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 12.3: Trigger backup recovery via sync, then verify with ping
# Sync now self-repairs from local backups. Ping triggers sync when chain mismatch detected.
# We run sync first to trigger recovery, then ping to verify chain is valid.
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Triggering backup recovery via sync, then verifying with ping"

syncResult=$(docker exec ${sender} eiou sync transactions --json 2>&1)
wait_for_queue_processed ${sender} 3

# Now ping should show chain valid (backup recovery during sync should have restored tx2)
pingResult=$(docker exec -e EIOU_TEST_MODE=true ${sender} eiou ping ${receiverAddress} --json 2>&1)
echo -e "\t   Ping result: ${pingResult:0:120}..."

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
echo -e "\t   Sender chain: $(format_chain_status ${senderIntegrity})"

if [[ "$senderIntegrity" == VALID:* ]]; then
    printf "\t   Chain valid after sync recovery + ping ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Ping after sync recovery ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 12.4: Verify tx2 restored
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying tx2 exists after backup recovery"

tx2Exists=$(verify_tx_exists ${sender} "$tx2")

if [[ "$tx2Exists" == "1" ]]; then
    printf "\t   tx2 present after sync + ping path ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   tx2 presence ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Cleanup test 12
clean_chain > /dev/null
cleanup_backups ${sender}
cleanup_backups ${receiver}

#################### TEST 13: No Backup -- Normal Chain Drop Fallback ####################

echo -e "\n"
echo "========================================================================"
echo "Section 13: No Backup Available -- Normal Chain Drop Fallback"
echo "========================================================================"
echo -e "\n"

timestamp=$(date +%s%N)
testPattern="chaindrop-t13-%${timestamp}"

# Ensure no backups exist
cleanup_backups ${sender}
cleanup_backups ${receiver}

# Test 13.1: Send 3 transactions (no backup)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending 3 transactions (NO backup created)"

docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 1 USD "chaindrop-t13-tx1-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 2 USD "chaindrop-t13-tx2-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiverAddress} 3 USD "chaindrop-t13-tx3-${timestamp}" 2>&1 > /dev/null

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
IFS=',' read -ra senderTxArr <<< "$senderTxids"
backupCount=$(count_backups ${sender})

if [[ "${#senderTxArr[@]}" -ge 3 ]] && [[ "$backupCount" == "0" ]]; then
    printf "\t   3 txs sent, no backups ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Setup ${RED}FAILED${NC} (txs=${#senderTxArr[@]}, backups=${backupCount})\n"
    failure=$(( failure + 1 ))
fi

# Test 13.2: Delete tx2 from both nodes
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Deleting tx2 from both nodes"

tx1="${senderTxArr[0]}"
tx2="${senderTxArr[1]}"
tx3="${senderTxArr[2]}"

delete_txids ${sender} "$tx2"
delete_txids ${receiver} "$tx2"

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})

if [[ "$senderIntegrity" == INVALID:1:* ]]; then
    printf "\t   Chain broken ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain broken detection ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 13.3: Propose chain drop -- no backup, should fall through to normal proposal
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Proposing chain drop (no backup, should propose normally)"

proposeResult=$(docker exec ${sender} eiou chaindrop propose ${receiverAddress} 2>&1)
echo -e "\t   Propose result: ${proposeResult:0:80}..."

if echo "$proposeResult" | grep -qi 'success\|proposal'; then
    printf "\t   Normal chain drop proposed ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain drop propose ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 13.4: Receiver accepts proposal
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Receiver accepting chain drop proposal"

wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

proposalId=$(get_pending_proposal ${receiver})
if [[ "$proposalId" == "NONE" ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
    proposalId=$(get_pending_proposal ${receiver})
fi

if [[ "$proposalId" != "NONE" ]] && [[ -n "$proposalId" ]]; then
    acceptResult=$(docker exec ${receiver} eiou chaindrop accept ${proposalId} 2>&1)
    echo -e "\t   Accept result: ${acceptResult:0:80}..."

    if echo "$acceptResult" | grep -qi 'success\|accepted'; then
        printf "\t   Proposal accepted (no backup fallback) ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Proposal accept ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   Proposal delivery ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 13.5: Verify chain is valid after normal chain drop
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying chain repaired via normal chain drop"

wait_for_queue_processed ${sender} 3
wait_for_queue_processed ${receiver} 3

senderIntegrity=$(check_chain_integrity ${sender} ${receiverPubkeyB64})
receiverIntegrity=$(check_chain_integrity ${receiver} ${senderPubkeyB64})

echo -e "\t   Sender: $(format_chain_status ${senderIntegrity})"
echo -e "\t   Receiver: $(format_chain_status ${receiverIntegrity})"

if [[ "$senderIntegrity" == VALID:* ]] && [[ "$receiverIntegrity" == VALID:* ]]; then
    printf "\t   Chain repaired via chain drop ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain repair ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 13.6: Verify chain relink: tx3.previous_txid = tx1 (tx2 was DROPPED, not recovered)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying tx2 was dropped (not recovered) and chain relinked"

tx3Prev=$(get_previous_txid ${sender} "$tx3")
tx2Exists=$(verify_tx_exists ${sender} "$tx2")

echo -e "\t   tx3.previous_txid: ${tx3Prev:0:40}... (expect tx1)"
echo -e "\t   tx2 exists: ${tx2Exists} (expect 0 -- dropped)"

if [[ "$tx3Prev" == "$tx1" ]] && [[ "$tx2Exists" == "0" ]]; then
    printf "\t   tx2 dropped, chain relinked ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Drop verification ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 13.7: Transaction count is 2 on both (tx2 permanently dropped)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying transaction count is 2 (tx2 dropped)"

senderCount=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

receiverCount=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${testPattern}'\")
        ->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

echo -e "\t   Sender: ${senderCount}, Receiver: ${receiverCount} (expect 2)"

if [[ "$senderCount" == "2" ]] && [[ "$receiverCount" == "2" ]]; then
    printf "\t   Transaction count correct ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transaction count ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Cleanup test 13
clean_chain > /dev/null
cleanup_backups ${sender}
cleanup_backups ${receiver}

# ========================= Summary =========================
succesrate "${totaltests}" "${passed}" "${failure}" "'chain drop test suite'"
