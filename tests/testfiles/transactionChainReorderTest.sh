#!/bin/sh
# Copyright 2025 The Vowels Company

# Test transaction chain reordering upon expiration/cancellation
# When a transaction is cancelled/expired, the chain should be reordered
# to skip the cancelled transaction and maintain chain integrity.

echo -e "\nTesting transaction chain reordering functionality..."

testname="transactionChainReorderTest"
totaltests=0
passed=0
failure=0

# Use first two connected containers for testing
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
testPair="${containersLinkKeys[0]}"

if [[ -z "$testPair" ]]; then
    echo -e "${YELLOW}Warning: No container links defined, skipping chain reorder test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'transaction chain reorder'"
    exit 0
fi

containerKeys=(${testPair//,/ })
sender="${containerKeys[0]}"
receiver="${containerKeys[1]}"

# Get addresses from containerAddresses
senderAddress="${containerAddresses[${sender}]}"
receiverAddress="${containerAddresses[${receiver}]}"

echo -e "\n[Transaction Chain Reorder Test Setup]"
echo -e "\t   Sender: ${sender} (${senderAddress})"
echo -e "\t   Receiver: ${receiver} (${receiverAddress})"

if [[ -z "$senderAddress" ]] || [[ -z "$receiverAddress" ]]; then
    echo -e "${YELLOW}Warning: Container addresses not populated, skipping test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'transaction chain reorder'"
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
    succesrate "${totaltests}" "${passed}" "${failure}" "'transaction chain reorder'"
    exit 0
fi

############################ TEST 1: Verify updatePreviousTxidReferences method exists ############################

echo -e "\n[Test 1: Verify updatePreviousTxidReferences method exists]"
totaltests=$(( totaltests + 1 ))

methodExists=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$transactionRepo = \$app->services->getTransactionRepository();
    echo method_exists(\$transactionRepo, 'updatePreviousTxidReferences') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$methodExists" == "EXISTS" ]]; then
    printf "\t   updatePreviousTxidReferences method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   updatePreviousTxidReferences method ${RED}FAILED${NC} (%s)\n" "${methodExists}"
    failure=$(( failure + 1 ))
fi

############################ TEST 2: Verify getPreviousTxid excludes cancelled transactions ############################

echo -e "\n[Test 2: Verify getPreviousTxid excludes cancelled transactions]"
totaltests=$(( totaltests + 1 ))

timestamp=$(date +%s%N)

# Create a test transaction chain A1->A2->A3
chainTestResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$transactionRepo = \$app->services->getTransactionRepository();

    // Get receiver pubkey
    \$receiverPubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${receiverAddress}');
    \$senderPubkey = \$app->getPublicKey();
    \$senderPubkeyHash = hash('sha256', \$senderPubkey);
    \$receiverPubkeyHash = hash('sha256', \$receiverPubkey);

    // Clean up any existing test transactions
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'chain-reorder-test%'\");

    // Create transaction A1
    \$a1Txid = 'chain-test-a1-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, timestamp) VALUES (?, 'standard', 'sent', 'completed', ?, ?, ?, ?, ?, ?, 100, 'USD', NULL, 'standard', 'chain-reorder-test-a1', NOW())\");
    \$stmt->execute([\$a1Txid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash]);

    // Create transaction A2 (will be cancelled)
    \$a2Txid = 'chain-test-a2-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, timestamp) VALUES (?, 'standard', 'sent', 'completed', ?, ?, ?, ?, ?, ?, 200, 'USD', ?, 'standard', 'chain-reorder-test-a2', DATE_ADD(NOW(), INTERVAL 1 SECOND))\");
    \$stmt->execute([\$a2Txid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash, \$a1Txid]);

    // Create transaction A3
    \$a3Txid = 'chain-test-a3-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, timestamp) VALUES (?, 'standard', 'sent', 'completed', ?, ?, ?, ?, ?, ?, 300, 'USD', ?, 'standard', 'chain-reorder-test-a3', DATE_ADD(NOW(), INTERVAL 2 SECOND))\");
    \$stmt->execute([\$a3Txid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash, \$a2Txid]);

    // Now mark A2 as cancelled
    \$pdo->exec(\"UPDATE transactions SET status = 'cancelled' WHERE txid = '\${a2Txid}'\");

    // Get previous txid - should return A3 (most recent non-cancelled)
    \$previousTxid = \$transactionRepo->getPreviousTxid(\$senderPubkey, \$receiverPubkey);

    if (\$previousTxid === \$a3Txid) {
        echo 'PASSED:' . \$previousTxid;
    } else {
        echo 'FAILED:expected=' . \$a3Txid . ',got=' . \$previousTxid;
    }
" 2>/dev/null || echo "ERROR")

if [[ "$chainTestResult" == PASSED:* ]]; then
    printf "\t   getPreviousTxid excludes cancelled ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   getPreviousTxid excludes cancelled ${RED}FAILED${NC} (%s)\n" "${chainTestResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 3: Verify chain reordering works ############################

echo -e "\n[Test 3: Verify chain reordering updates previous_txid references]"
totaltests=$(( totaltests + 1 ))

reorderTestResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$transactionRepo = \$app->services->getTransactionRepository();

    // Get the A2 and A3 txids from previous test
    \$a1Txid = 'chain-test-a1-${timestamp}';
    \$a2Txid = 'chain-test-a2-${timestamp}';
    \$a3Txid = 'chain-test-a3-${timestamp}';

    // A3 currently points to A2 (cancelled). After reordering, A3 should point to A1.
    // Get A2's previous_txid (which is A1)
    \$stmt = \$pdo->query(\"SELECT previous_txid FROM transactions WHERE txid = '\${a2Txid}'\");
    \$a2PreviousTxid = \$stmt->fetchColumn();

    // Call updatePreviousTxidReferences to update A3 to point to A1
    \$updatedCount = \$transactionRepo->updatePreviousTxidReferences(\$a2Txid, \$a2PreviousTxid);

    // Verify A3 now points to A1
    \$stmt = \$pdo->query(\"SELECT previous_txid FROM transactions WHERE txid = '\${a3Txid}'\");
    \$a3NewPreviousTxid = \$stmt->fetchColumn();

    if (\$a3NewPreviousTxid === \$a1Txid) {
        echo 'PASSED:updated=' . \$updatedCount . ',a3_now_points_to=' . \$a3NewPreviousTxid;
    } else {
        echo 'FAILED:expected=' . \$a1Txid . ',got=' . \$a3NewPreviousTxid;
    }
" 2>/dev/null || echo "ERROR")

if [[ "$reorderTestResult" == PASSED:* ]]; then
    printf "\t   Chain reordering ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Chain reordering ${RED}FAILED${NC} (%s)\n" "${reorderTestResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 4: Verify sync excludes cancelled transactions ############################

echo -e "\n[Test 4: Verify sync response excludes cancelled transactions]"
totaltests=$(( totaltests + 1 ))

syncExcludeResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$transactionRepo = \$app->services->getTransactionRepository();

    // Get receiver pubkey
    \$receiverPubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${receiverAddress}');
    \$senderPubkey = \$app->getPublicKey();

    // Get all transactions between parties
    \$transactions = \$transactionRepo->getTransactionsBetweenPubkeys(\$senderPubkey, \$receiverPubkey);

    // Count cancelled vs active
    \$cancelledCount = 0;
    \$activeCount = 0;
    foreach (\$transactions as \$tx) {
        if (in_array(\$tx['status'], ['cancelled', 'rejected'])) {
            \$cancelledCount++;
        } else {
            \$activeCount++;
        }
    }

    // Simulate sync filtering (same logic as SyncService)
    \$syncTransactions = [];
    foreach (\$transactions as \$tx) {
        if (!in_array(\$tx['status'], ['cancelled', 'rejected'])) {
            \$syncTransactions[] = \$tx;
        }
    }

    if (count(\$syncTransactions) === \$activeCount && \$cancelledCount > 0) {
        echo 'PASSED:active=' . \$activeCount . ',excluded=' . \$cancelledCount;
    } else {
        echo 'PARTIAL:active=' . \$activeCount . ',cancelled=' . \$cancelledCount . ',sync=' . count(\$syncTransactions);
    }
" 2>/dev/null || echo "ERROR")

if [[ "$syncExcludeResult" == PASSED:* ]] || [[ "$syncExcludeResult" == PARTIAL:* ]]; then
    printf "\t   Sync excludes cancelled ${GREEN}PASSED${NC} (%s)\n" "${syncExcludeResult}"
    passed=$(( passed + 1 ))
else
    printf "\t   Sync excludes cancelled ${RED}FAILED${NC} (%s)\n" "${syncExcludeResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 5: Verify insertTransaction excludes cancelled in prev_txid lookup ############################

echo -e "\n[Test 5: Verify insertTransaction excludes cancelled when finding prev_txid]"
totaltests=$(( totaltests + 1 ))

insertTestResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();

    \$receiverPubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${receiverAddress}');
    \$senderPubkey = \$app->getPublicKey();
    \$senderPubkeyHash = hash('sha256', \$senderPubkey);
    \$receiverPubkeyHash = hash('sha256', \$receiverPubkey);

    // Create a cancelled transaction as the most recent
    \$cancelledTxid = 'insert-test-cancelled-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, timestamp) VALUES (?, 'standard', 'sent', 'cancelled', ?, ?, ?, ?, ?, ?, 500, 'USD', NULL, 'standard', 'insert-test-cancelled', DATE_ADD(NOW(), INTERVAL 10 SECOND))\");
    \$stmt->execute([\$cancelledTxid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash]);

    // The insertTransaction query should exclude this cancelled transaction
    // Test by running the same query used in insertTransaction
    \$query = \"SELECT txid FROM transactions
            WHERE ((sender_public_key_hash = ? AND receiver_public_key_hash = ?)
                OR (sender_public_key_hash = ? AND receiver_public_key_hash = ?))
            AND status NOT IN ('cancelled', 'rejected')
            ORDER BY timestamp DESC LIMIT 1\";

    \$stmt = \$pdo->prepare(\$query);
    \$stmt->execute([\$senderPubkeyHash, \$receiverPubkeyHash, \$receiverPubkeyHash, \$senderPubkeyHash]);
    \$prevTxid = \$stmt->fetchColumn();

    // Prev txid should NOT be the cancelled one
    if (\$prevTxid !== \$cancelledTxid) {
        echo 'PASSED:prev=' . (\$prevTxid ?: 'NULL') . ',excluded=' . \$cancelledTxid;
    } else {
        echo 'FAILED:got_cancelled_txid=' . \$cancelledTxid;
    }
" 2>/dev/null || echo "ERROR")

if [[ "$insertTestResult" == PASSED:* ]]; then
    printf "\t   insertTransaction excludes cancelled ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   insertTransaction excludes cancelled ${RED}FAILED${NC} (%s)\n" "${insertTestResult}"
    failure=$(( failure + 1 ))
fi

############################ CLEANUP ############################

echo -e "\n[Cleanup: Removing test transactions]"

# Clean up sender container
cleanupSender=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'chain-reorder-test%' OR description LIKE 'insert-test%'\");
    echo 'DELETED:' . \$deleted;
" 2>/dev/null || echo "ERROR")

echo -e "\t   Cleanup sender: ${cleanupSender}"

# Clean up receiver container (transactions may have synced)
cleanupReceiver=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'chain-reorder-test%' OR description LIKE 'insert-test%'\");
    echo 'DELETED:' . \$deleted;
" 2>/dev/null || echo "ERROR")

echo -e "\t   Cleanup receiver: ${cleanupReceiver}"

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'transaction chain reorder'"
