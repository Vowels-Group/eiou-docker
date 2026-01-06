#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

# Test sync behavior with cancelled transactions
#
# IMPORTANT: As of 2025, the sync behavior has changed:
# - Cancelled transactions are now INCLUDED in sync (not filtered out)
# - previous_txid is NOT modified during sync (kept as-is for historical accuracy)
# - Chain integrity is maintained by including all transactions, not by chain reordering
#
# This test verifies:
# 1. Sync includes cancelled transactions (not filtered)
# 2. previous_txid is preserved as-is during sync
# 3. Chain integrity is maintained by including all transactions
# 4. New transactions correctly skip cancelled when finding previous_txid

echo -e "\nTesting sync behavior with cancelled transactions..."

testname="resyncPrevTxidGapTest"
totaltests=0
passed=0
failure=0

# Use first two connected containers for testing
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
testPair="${containersLinkKeys[0]}"

if [[ -z "$testPair" ]]; then
    echo -e "${YELLOW}Warning: No container links defined, skipping sync cancelled test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'sync cancelled transactions'"
    exit 0
fi

containerKeys=(${testPair//,/ })
sender="${containerKeys[0]}"
receiver="${containerKeys[1]}"

# Get addresses from containerAddresses
senderAddress="${containerAddresses[${sender}]}"
receiverAddress="${containerAddresses[${receiver}]}"

echo -e "\n[Sync Cancelled Transactions Test Setup]"
echo -e "\t   Sender: ${sender} (${senderAddress})"
echo -e "\t   Receiver: ${receiver} (${receiverAddress})"

if [[ -z "$senderAddress" ]] || [[ -z "$receiverAddress" ]]; then
    echo -e "${YELLOW}Warning: Container addresses not populated, skipping test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'sync cancelled transactions'"
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
    succesrate "${totaltests}" "${passed}" "${failure}" "'sync cancelled transactions'"
    exit 0
fi

echo -e "\t   Receiver pubkey hash: ${receiverPubkeyHash:0:40}..."

############################ TEST 1: Create test chain with cancelled transaction ############################

echo -e "\n[Test 1: Create transaction chain AB1->AB2(cancelled)->AB3->AB4 on receiver]"
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
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'sync-cancel-test%'\");

    // Create transaction AB1 (first in chain, no previous)
    \$ab1Txid = 'sync-cancel-ab1-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, timestamp) VALUES (?, 'standard', 'received', 'completed', ?, ?, ?, ?, ?, ?, 100, 'USD', NULL, 'standard', 'sync-cancel-test-ab1', NOW())\");
    \$stmt->execute([\$ab1Txid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash]);

    // Create transaction AB2 (points to AB1, will be CANCELLED)
    \$ab2Txid = 'sync-cancel-ab2-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, timestamp) VALUES (?, 'standard', 'received', 'cancelled', ?, ?, ?, ?, ?, ?, 200, 'USD', ?, 'standard', 'sync-cancel-test-ab2', DATE_ADD(NOW(), INTERVAL 1 SECOND))\");
    \$stmt->execute([\$ab2Txid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash, \$ab1Txid]);

    // Create transaction AB3 (points to AB2 - the cancelled one!)
    \$ab3Txid = 'sync-cancel-ab3-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, timestamp) VALUES (?, 'standard', 'received', 'completed', ?, ?, ?, ?, ?, ?, 300, 'USD', ?, 'standard', 'sync-cancel-test-ab3', DATE_ADD(NOW(), INTERVAL 2 SECOND))\");
    \$stmt->execute([\$ab3Txid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash, \$ab2Txid]);

    // Create transaction AB4 (points to AB3)
    \$ab4Txid = 'sync-cancel-ab4-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, timestamp) VALUES (?, 'standard', 'received', 'completed', ?, ?, ?, ?, ?, ?, 400, 'USD', ?, 'standard', 'sync-cancel-test-ab4', DATE_ADD(NOW(), INTERVAL 3 SECOND))\");
    \$stmt->execute([\$ab4Txid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash, \$ab3Txid]);

    // Verify chain structure
    \$stmt = \$pdo->query(\"SELECT txid, previous_txid, status FROM transactions WHERE description LIKE 'sync-cancel-test%' ORDER BY timestamp ASC\");
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

############################ TEST 2: Verify sync INCLUDES cancelled transactions ############################

echo -e "\n[Test 2: Verify getTransactionsBetweenPubkeys includes cancelled transactions]"
totaltests=$(( totaltests + 1 ))

syncIncludesResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$transactionRepo = \$app->services->getTransactionRepository();

    // Get sender pubkey
    \$senderPubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${senderAddress}');
    \$receiverPubkey = \$app->getPublicKey();

    // Get transactions between parties (used for sync)
    \$transactions = \$transactionRepo->getTransactionsBetweenPubkeys(\$receiverPubkey, \$senderPubkey);

    // Filter to only our test transactions
    \$testTxs = array_filter(\$transactions, function(\$tx) {
        return strpos(\$tx['description'], 'sync-cancel-test') !== false;
    });

    // Check if cancelled transaction (AB2) is included
    \$cancelledIncluded = false;
    foreach (\$testTxs as \$tx) {
        if (\$tx['status'] === 'cancelled') {
            \$cancelledIncluded = true;
            break;
        }
    }

    if (count(\$testTxs) === 4 && \$cancelledIncluded) {
        echo 'PASSED:all_4_included,cancelled_included';
    } else if (count(\$testTxs) === 4) {
        echo 'PASSED:all_4_included,no_cancelled_found';
    } else {
        echo 'FAILED:expected=4,got=' . count(\$testTxs) . ',cancelled=' . (\$cancelledIncluded ? 'yes' : 'no');
    }
" 2>/dev/null || echo "ERROR")

if [[ "$syncIncludesResult" == PASSED:* ]]; then
    printf "\t   Sync includes cancelled ${GREEN}PASSED${NC} (%s)\n" "${syncIncludesResult}"
    passed=$(( passed + 1 ))
else
    printf "\t   Sync includes cancelled ${RED}FAILED${NC} (%s)\n" "${syncIncludesResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 3: Verify previous_txid is NOT modified ############################

echo -e "\n[Test 3: Verify previous_txid is preserved as-is (not modified to skip cancelled)]"
totaltests=$(( totaltests + 1 ))

prevTxidPreserved=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();

    // AB3 should still point to AB2 (the cancelled transaction)
    // We do NOT modify previous_txid anymore
    \$stmt = \$pdo->query(\"SELECT previous_txid FROM transactions WHERE txid = 'sync-cancel-ab3-${timestamp}'\");
    \$ab3PrevTxid = \$stmt->fetchColumn();

    \$expectedPrev = 'sync-cancel-ab2-${timestamp}';
    if (\$ab3PrevTxid === \$expectedPrev) {
        echo 'PRESERVED:AB3_still_points_to_cancelled_AB2';
    } else {
        echo 'MODIFIED:expected=' . \$expectedPrev . ',got=' . \$ab3PrevTxid;
    }
" 2>/dev/null || echo "ERROR")

if [[ "$prevTxidPreserved" == PRESERVED:* ]]; then
    printf "\t   previous_txid preserved ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   previous_txid preserved ${RED}FAILED${NC} (%s)\n" "${prevTxidPreserved}"
    failure=$(( failure + 1 ))
fi

############################ TEST 4: Verify chain integrity via inclusion ############################

echo -e "\n[Test 4: Verify chain integrity is maintained by including all transactions]"
totaltests=$(( totaltests + 1 ))

chainIntegrityResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$transactionRepo = \$app->services->getTransactionRepository();

    // Get sender pubkey
    \$senderPubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${senderAddress}');
    \$receiverPubkey = \$app->getPublicKey();

    // Get all transactions
    \$transactions = \$transactionRepo->getTransactionsBetweenPubkeys(\$receiverPubkey, \$senderPubkey);

    // Filter to test transactions and index by txid
    \$testTxs = [];
    foreach (\$transactions as \$tx) {
        if (strpos(\$tx['description'], 'sync-cancel-test') !== false) {
            \$testTxs[\$tx['txid']] = \$tx;
        }
    }

    // Verify we can follow the complete chain including cancelled
    \$ab1 = 'sync-cancel-ab1-${timestamp}';
    \$ab2 = 'sync-cancel-ab2-${timestamp}';
    \$ab3 = 'sync-cancel-ab3-${timestamp}';
    \$ab4 = 'sync-cancel-ab4-${timestamp}';

    // Check chain links
    \$ab2PointsToAb1 = isset(\$testTxs[\$ab2]) && \$testTxs[\$ab2]['previous_txid'] === \$ab1;
    \$ab3PointsToAb2 = isset(\$testTxs[\$ab3]) && \$testTxs[\$ab3]['previous_txid'] === \$ab2;
    \$ab4PointsToAb3 = isset(\$testTxs[\$ab4]) && \$testTxs[\$ab4]['previous_txid'] === \$ab3;
    \$ab1HasNoPrev = isset(\$testTxs[\$ab1]) && \$testTxs[\$ab1]['previous_txid'] === null;

    if (\$ab1HasNoPrev && \$ab2PointsToAb1 && \$ab3PointsToAb2 && \$ab4PointsToAb3) {
        echo 'CHAIN_INTACT:AB1<-AB2<-AB3<-AB4';
    } else {
        echo 'CHAIN_BROKEN:' .
            'ab1_null=' . (\$ab1HasNoPrev ? 'yes' : 'no') . ',' .
            'ab2->ab1=' . (\$ab2PointsToAb1 ? 'yes' : 'no') . ',' .
            'ab3->ab2=' . (\$ab3PointsToAb2 ? 'yes' : 'no') . ',' .
            'ab4->ab3=' . (\$ab4PointsToAb3 ? 'yes' : 'no');
    }
" 2>/dev/null || echo "ERROR")

if [[ "$chainIntegrityResult" == CHAIN_INTACT:* ]]; then
    printf "\t   Chain integrity via inclusion ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain integrity via inclusion ${RED}FAILED${NC} (%s)\n" "${chainIntegrityResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 5: Verify NEW transactions skip cancelled for previous_txid ############################

echo -e "\n[Test 5: Verify getPreviousTxid skips cancelled when finding prev for NEW transactions]"
totaltests=$(( totaltests + 1 ))

newTxPrevResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$transactionRepo = \$app->services->getTransactionRepository();
    \$pdo = \$app->services->getPdo();

    // Get sender pubkey
    \$senderPubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${senderAddress}');
    \$receiverPubkey = \$app->getPublicKey();
    \$senderPubkeyHash = hash('sha256', \$senderPubkey);
    \$receiverPubkeyHash = hash('sha256', \$receiverPubkey);

    // Create a new cancelled transaction with a very high time value to ensure it's the latest
    \$cancelledTxid = 'sync-cancel-latest-${timestamp}';

    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, time, timestamp) VALUES (?, 'standard', 'received', 'cancelled', ?, ?, ?, ?, ?, ?, 999, 'USD', 'sync-cancel-ab4-${timestamp}', 'standard', 'sync-cancel-test-latest', 9999999999999999, DATE_ADD(NOW(), INTERVAL 10 SECOND))\");
    \$stmt->execute([\$cancelledTxid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash]);

    // getPreviousTxid should NOT return the cancelled transaction we just created
    // It should return some other non-cancelled transaction
    \$prevTxid = \$transactionRepo->getPreviousTxid(\$receiverPubkey, \$senderPubkey);

    // Verify the cancelled transaction is NOT returned
    if (\$prevTxid === \$cancelledTxid) {
        echo 'FAILED:incorrectly_returned_cancelled';
    } else if (\$prevTxid !== null) {
        // Check if the returned transaction is actually non-cancelled
        \$stmt = \$pdo->prepare('SELECT status FROM transactions WHERE txid = ?');
        \$stmt->execute([\$prevTxid]);
        \$status = \$stmt->fetchColumn();
        if (\$status && !in_array(\$status, ['cancelled', 'rejected'])) {
            echo 'PASSED:returned_non_cancelled=' . \$prevTxid;
        } else {
            echo 'FAILED:returned_cancelled_status=' . \$status;
        }
    } else {
        echo 'PASSED:null_is_valid_no_active_transactions';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$newTxPrevResult" == PASSED:* ]]; then
    printf "\t   New tx skips cancelled for prev_txid ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   New tx skips cancelled for prev_txid ${RED}FAILED${NC} (%s)\n" "${newTxPrevResult}"
    failure=$(( failure + 1 ))
fi

############################ CLEANUP ############################

echo -e "\n[Cleanup: Removing test transactions]"

cleanupResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'sync-cancel-test%'\");
    echo 'DELETED:' . \$deleted;
" 2>/dev/null || echo "ERROR")

echo -e "\t   Cleanup: ${cleanupResult}"

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'sync cancelled transactions'"
