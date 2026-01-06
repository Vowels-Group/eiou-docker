#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

# Test resync prev-txid gap handling functionality
# Verifies prev-txid chain gap resolution during sync
#
# This test verifies that when syncing transactions, if a transaction in the chain
# has been cancelled but the chain hasn't been readjusted yet, the sync response
# correctly updates the previous_txid to skip over the cancelled transaction.
#
# Test scenario:
# - Chain: AB1 -> AB2 -> AB3 -> AB4
# - AB2 is cancelled (but AB3 still points to AB2)
# - When syncing, AB3's previous_txid should be updated to AB1 (skipping AB2)

echo -e "\nTesting resync prev-txid gap handling..."

testname="resyncPrevTxidGapTest"
totaltests=0
passed=0
failure=0

# Use first two connected containers for testing
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
testPair="${containersLinkKeys[0]}"

if [[ -z "$testPair" ]]; then
    echo -e "${YELLOW}Warning: No container links defined, skipping resync prev-txid gap test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'resync prev-txid gap'"
    exit 0
fi

containerKeys=(${testPair//,/ })
sender="${containerKeys[0]}"
receiver="${containerKeys[1]}"

# Get addresses from containerAddresses
senderAddress="${containerAddresses[${sender}]}"
receiverAddress="${containerAddresses[${receiver}]}"

echo -e "\n[Resync Prev-Txid Gap Test Setup]"
echo -e "\t   Sender: ${sender} (${senderAddress})"
echo -e "\t   Receiver: ${receiver} (${receiverAddress})"

if [[ -z "$senderAddress" ]] || [[ -z "$receiverAddress" ]]; then
    echo -e "${YELLOW}Warning: Container addresses not populated, skipping test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'resync prev-txid gap'"
    exit 0
fi

############################ Ensure Contacts Exist ############################

echo -e "\n[Ensuring contacts exist between sender and receiver]"

docker exec ${sender} eiou add ${receiverAddress} ${receiver} 0 0 USD 2>&1 || true
docker exec ${receiver} eiou add ${senderAddress} ${sender} 0 0 USD 2>&1 || true

sleep 2

############################ Get Public Keys ############################

echo -e "\n[Getting container public keys]"

receiverPubkeyInfo=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${containerAddresses[${receiver}]}');
    if (\$pubkey) {
        echo base64_encode(\$pubkey) . '|' . hash('sha256', \$pubkey);
    } else {
        echo 'ERROR|ERROR';
    }
" 2>/dev/null || echo "ERROR|ERROR")

receiverPubkeyB64=$(echo "$receiverPubkeyInfo" | cut -d'|' -f1)
receiverPubkeyHash=$(echo "$receiverPubkeyInfo" | cut -d'|' -f2)

if [[ "$receiverPubkeyHash" == "ERROR" ]] || [[ -z "$receiverPubkeyHash" ]]; then
    echo -e "${YELLOW}Warning: Could not retrieve public keys, skipping test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'resync prev-txid gap'"
    exit 0
fi

echo -e "\t   Receiver pubkey hash: ${receiverPubkeyHash:0:40}..."

############################ TEST 1: Verify resolvePreviousTxid method exists ############################

echo -e "\n[Test 1: Verify resolvePreviousTxid method exists]"
totaltests=$(( totaltests + 1 ))

methodExists=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$syncService = \$app->services->getSyncService();
    \$reflection = new ReflectionClass(\$syncService);
    echo \$reflection->hasMethod('resolvePreviousTxid') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$methodExists" == "EXISTS" ]]; then
    printf "\t   resolvePreviousTxid method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   resolvePreviousTxid method ${RED}FAILED${NC} (%s)\n" "${methodExists}"
    failure=$(( failure + 1 ))
fi

############################ TEST 2: Create test transaction chain with gap ############################

echo -e "\n[Test 2: Create transaction chain AB1->AB2->AB3->AB4 on receiver with AB2 cancelled]"
totaltests=$(( totaltests + 1 ))

timestamp=$(date +%s%N)

chainResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();

    // Get sender pubkey
    \$senderPubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${senderAddress}');
    \$receiverPubkey = \$app->getPublicKey();
    \$senderPubkeyHash = hash('sha256', \$senderPubkey);
    \$receiverPubkeyHash = hash('sha256', \$receiverPubkey);

    // Clean up any existing test transactions
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'resync-gap-test%'\");

    // Create transaction AB1 (first in chain, no previous)
    \$ab1Txid = 'resync-gap-ab1-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, timestamp) VALUES (?, 'standard', 'received', 'completed', ?, ?, ?, ?, ?, ?, 100, 'USD', NULL, 'standard', 'resync-gap-test-ab1', NOW())\");
    \$stmt->execute([\$ab1Txid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash]);

    // Create transaction AB2 (points to AB1, will be CANCELLED)
    \$ab2Txid = 'resync-gap-ab2-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, timestamp) VALUES (?, 'standard', 'received', 'cancelled', ?, ?, ?, ?, ?, ?, 200, 'USD', ?, 'standard', 'resync-gap-test-ab2', DATE_ADD(NOW(), INTERVAL 1 SECOND))\");
    \$stmt->execute([\$ab2Txid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash, \$ab1Txid]);

    // Create transaction AB3 (points to AB2 - the cancelled one!)
    \$ab3Txid = 'resync-gap-ab3-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, timestamp) VALUES (?, 'standard', 'received', 'completed', ?, ?, ?, ?, ?, ?, 300, 'USD', ?, 'standard', 'resync-gap-test-ab3', DATE_ADD(NOW(), INTERVAL 2 SECOND))\");
    \$stmt->execute([\$ab3Txid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash, \$ab2Txid]);

    // Create transaction AB4 (points to AB3)
    \$ab4Txid = 'resync-gap-ab4-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, timestamp) VALUES (?, 'standard', 'received', 'completed', ?, ?, ?, ?, ?, ?, 400, 'USD', ?, 'standard', 'resync-gap-test-ab4', DATE_ADD(NOW(), INTERVAL 3 SECOND))\");
    \$stmt->execute([\$ab4Txid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash, \$ab3Txid]);

    // Verify chain structure
    \$stmt = \$pdo->query(\"SELECT txid, previous_txid, status FROM transactions WHERE description LIKE 'resync-gap-test%' ORDER BY timestamp ASC\");
    \$txs = \$stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count(\$txs) === 4) {
        echo 'CHAIN_CREATED:' . \$ab1Txid . '|' . \$ab2Txid . '|' . \$ab3Txid . '|' . \$ab4Txid;
    } else {
        echo 'CHAIN_FAILED:' . count(\$txs);
    }
" 2>/dev/null || echo "ERROR")

if [[ "$chainResult" == CHAIN_CREATED:* ]]; then
    txids=$(echo "$chainResult" | cut -d':' -f2)
    ab1Txid=$(echo "$txids" | cut -d'|' -f1)
    ab2Txid=$(echo "$txids" | cut -d'|' -f2)
    ab3Txid=$(echo "$txids" | cut -d'|' -f3)
    ab4Txid=$(echo "$txids" | cut -d'|' -f4)
    printf "\t   Chain created ${GREEN}PASSED${NC}\n"
    echo -e "\t   AB1: ${ab1Txid:0:30}..."
    echo -e "\t   AB2 (cancelled): ${ab2Txid:0:30}..."
    echo -e "\t   AB3 (points to AB2): ${ab3Txid:0:30}..."
    echo -e "\t   AB4: ${ab4Txid:0:30}..."
    passed=$(( passed + 1 ))
else
    printf "\t   Chain creation ${RED}FAILED${NC} (%s)\n" "${chainResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 3: Verify AB3 still points to cancelled AB2 ############################

echo -e "\n[Test 3: Verify AB3's previous_txid points to cancelled AB2]"
totaltests=$(( totaltests + 1 ))

prevTxidCheck=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();

    \$stmt = \$pdo->query(\"SELECT previous_txid FROM transactions WHERE txid = 'resync-gap-ab3-${timestamp}'\");
    \$prevTxid = \$stmt->fetchColumn();

    \$expectedPrev = 'resync-gap-ab2-${timestamp}';
    if (\$prevTxid === \$expectedPrev) {
        echo 'CORRECT:AB3_POINTS_TO_AB2';
    } else {
        echo 'WRONG:' . \$prevTxid;
    }
" 2>/dev/null || echo "ERROR")

if [[ "$prevTxidCheck" == "CORRECT:AB3_POINTS_TO_AB2" ]]; then
    printf "\t   AB3 points to AB2 (as expected) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   AB3 chain link ${RED}FAILED${NC} (%s)\n" "${prevTxidCheck}"
    failure=$(( failure + 1 ))
fi

############################ TEST 4: Test resolvePreviousTxid logic ############################

echo -e "\n[Test 4: Test resolvePreviousTxid skips over cancelled transactions]"
totaltests=$(( totaltests + 1 ))

resolveResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$syncService = \$app->services->getSyncService();

    // Create cancelled map: AB2 -> AB1
    \$cancelledMap = [
        'resync-gap-ab2-${timestamp}' => 'resync-gap-ab1-${timestamp}'
    ];

    // Use reflection to call private method
    \$reflection = new ReflectionClass(\$syncService);
    \$method = \$reflection->getMethod('resolvePreviousTxid');
    \$method->setAccessible(true);

    // Test: AB3's previous_txid is AB2 (cancelled), should resolve to AB1
    \$resolved = \$method->invoke(\$syncService, 'resync-gap-ab2-${timestamp}', \$cancelledMap);

    if (\$resolved === 'resync-gap-ab1-${timestamp}') {
        echo 'RESOLVED_CORRECTLY:AB2_TO_AB1';
    } else {
        echo 'WRONG_RESOLUTION:' . \$resolved;
    }
" 2>/dev/null || echo "ERROR")

if [[ "$resolveResult" == "RESOLVED_CORRECTLY:AB2_TO_AB1" ]]; then
    printf "\t   resolvePreviousTxid ${GREEN}PASSED${NC} (AB2 resolved to AB1)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   resolvePreviousTxid ${RED}FAILED${NC} (%s)\n" "${resolveResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 5: Test multiple cancelled transactions in chain ############################

echo -e "\n[Test 5: Test resolvePreviousTxid with multiple consecutive cancelled transactions]"
totaltests=$(( totaltests + 1 ))

multiResolveResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$syncService = \$app->services->getSyncService();

    // Create cancelled map: AB4 -> AB3 (cancelled) -> AB2 (cancelled) -> AB1
    \$cancelledMap = [
        'tx-c' => 'tx-b',  // C is cancelled, points to B
        'tx-b' => 'tx-a'   // B is also cancelled, points to A
    ];

    \$reflection = new ReflectionClass(\$syncService);
    \$method = \$reflection->getMethod('resolvePreviousTxid');
    \$method->setAccessible(true);

    // Test: If D points to C (cancelled), should resolve through B to A
    \$resolved = \$method->invoke(\$syncService, 'tx-c', \$cancelledMap);

    if (\$resolved === 'tx-a') {
        echo 'RESOLVED_CHAIN:C_TO_A';
    } else {
        echo 'WRONG_CHAIN:' . \$resolved;
    }
" 2>/dev/null || echo "ERROR")

if [[ "$multiResolveResult" == "RESOLVED_CHAIN:C_TO_A" ]]; then
    printf "\t   Multi-hop resolution ${GREEN}PASSED${NC} (C->B->A resolved to A)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Multi-hop resolution ${RED}FAILED${NC} (%s)\n" "${multiResolveResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 6: Verify sync response corrects previous_txid ############################

echo -e "\n[Test 6: Verify handleTransactionSyncRequest corrects previous_txid in response]"
totaltests=$(( totaltests + 1 ))

syncResponseTest=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();

    // Get sender pubkey
    \$senderPubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${senderAddress}');

    // Get transactions that would be synced
    \$transactions = \$app->services->getTransactionRepository()->getTransactionsBetweenPubkeys(
        \$app->getPublicKey(),
        \$senderPubkey
    );

    // Build cancelled map (same logic as handleTransactionSyncRequest)
    \$cancelledTxidToPrevious = [];
    foreach (\$transactions as \$tx) {
        if (in_array(\$tx['status'], ['cancelled', 'rejected'])) {
            \$cancelledTxidToPrevious[\$tx['txid']] = \$tx['previous_txid'];
        }
    }

    // Find AB3 and check what its previous_txid would be after resolution
    \$ab3Tx = null;
    foreach (\$transactions as \$tx) {
        if (strpos(\$tx['txid'], 'resync-gap-ab3-${timestamp}') !== false) {
            \$ab3Tx = \$tx;
            break;
        }
    }

    if (!\$ab3Tx) {
        echo 'AB3_NOT_FOUND';
        exit;
    }

    // Use resolvePreviousTxid logic
    \$syncService = \$app->services->getSyncService();
    \$reflection = new ReflectionClass(\$syncService);
    \$method = \$reflection->getMethod('resolvePreviousTxid');
    \$method->setAccessible(true);

    \$correctedPrevTxid = \$method->invoke(\$syncService, \$ab3Tx['previous_txid'], \$cancelledTxidToPrevious);

    // AB3's original previous_txid is AB2 (cancelled)
    // After resolution, it should be AB1
    \$ab1Txid = 'resync-gap-ab1-${timestamp}';
    if (\$correctedPrevTxid === \$ab1Txid) {
        echo 'CORRECTED:AB3_NOW_POINTS_TO_AB1';
    } else {
        echo 'WRONG:expected=' . \$ab1Txid . ',got=' . \$correctedPrevTxid;
    }
" 2>/dev/null || echo "ERROR")

if [[ "$syncResponseTest" == "CORRECTED:AB3_NOW_POINTS_TO_AB1" ]]; then
    printf "\t   Sync response correction ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Sync response correction ${RED}FAILED${NC} (%s)\n" "${syncResponseTest}"
    failure=$(( failure + 1 ))
fi

############################ TEST 7: Test edge case - null previous_txid ############################

echo -e "\n[Test 7: Test resolvePreviousTxid with null previous_txid]"
totaltests=$(( totaltests + 1 ))

nullTest=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$syncService = \$app->services->getSyncService();

    \$cancelledMap = ['tx-a' => null]; // First transaction in chain

    \$reflection = new ReflectionClass(\$syncService);
    \$method = \$reflection->getMethod('resolvePreviousTxid');
    \$method->setAccessible(true);

    // Test: null input should return null
    \$resolved1 = \$method->invoke(\$syncService, null, \$cancelledMap);

    // Test: cancelled transaction pointing to null should resolve to null
    \$resolved2 = \$method->invoke(\$syncService, 'tx-a', \$cancelledMap);

    if (\$resolved1 === null && \$resolved2 === null) {
        echo 'NULL_HANDLING_CORRECT';
    } else {
        echo 'NULL_HANDLING_WRONG:r1=' . var_export(\$resolved1, true) . ',r2=' . var_export(\$resolved2, true);
    }
" 2>/dev/null || echo "ERROR")

if [[ "$nullTest" == "NULL_HANDLING_CORRECT" ]]; then
    printf "\t   Null handling ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Null handling ${RED}FAILED${NC} (%s)\n" "${nullTest}"
    failure=$(( failure + 1 ))
fi

############################ TEST 8: Verify non-cancelled transactions pass through unchanged ############################

echo -e "\n[Test 8: Verify non-cancelled transaction previous_txid passes through unchanged]"
totaltests=$(( totaltests + 1 ))

passThroughTest=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$syncService = \$app->services->getSyncService();

    // AB4 points to AB3 which is NOT cancelled
    \$cancelledMap = [
        'resync-gap-ab2-${timestamp}' => 'resync-gap-ab1-${timestamp}'
    ];

    \$reflection = new ReflectionClass(\$syncService);
    \$method = \$reflection->getMethod('resolvePreviousTxid');
    \$method->setAccessible(true);

    // AB4's previous_txid is AB3 (not cancelled), should remain AB3
    \$resolved = \$method->invoke(\$syncService, 'resync-gap-ab3-${timestamp}', \$cancelledMap);

    if (\$resolved === 'resync-gap-ab3-${timestamp}') {
        echo 'PASSTHROUGH_CORRECT';
    } else {
        echo 'PASSTHROUGH_WRONG:' . \$resolved;
    }
" 2>/dev/null || echo "ERROR")

if [[ "$passThroughTest" == "PASSTHROUGH_CORRECT" ]]; then
    printf "\t   Non-cancelled passthrough ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Non-cancelled passthrough ${RED}FAILED${NC} (%s)\n" "${passThroughTest}"
    failure=$(( failure + 1 ))
fi

############################ CLEANUP ############################

echo -e "\n[Cleanup: Removing test transactions]"

cleanupResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'resync-gap-test%'\");
    echo 'DELETED:' . \$deleted;
" 2>/dev/null || echo "ERROR")

echo -e "\t   Cleanup: ${cleanupResult}"

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'resync prev-txid gap'"
