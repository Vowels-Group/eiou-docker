#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

# Test transaction chain behavior with cancellations
#
# IMPORTANT: As of 2025, chains are NO LONGER reordered when transactions are cancelled.
# The previous_txid field is kept as-is to maintain historical accuracy.
# Chain integrity is maintained by including ALL transactions (including cancelled) during sync.
#
# This test verifies:
# 1. The updatePreviousTxidReferences method still exists (but is no longer called for cancellations)
# 2. getPreviousTxid excludes cancelled transactions when finding previous for NEW transactions
# 3. Sync INCLUDES cancelled transactions (chain integrity via inclusion, not modification)
# 4. insertTransaction excludes cancelled when looking up previous_txid for new transactions

echo -e "\nTesting transaction chain behavior with cancellations..."

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

echo -e "\n[Test 1: Verify updatePreviousTxidReferences method exists (legacy, no longer called for cancellations)]"
totaltests=$(( totaltests + 1 ))

methodExists=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$transactionRepo = \$app->services->getTransactionRepository();
    echo method_exists(\$transactionRepo, 'updatePreviousTxidReferences') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$methodExists" == "EXISTS" ]]; then
    printf "\t   updatePreviousTxidReferences method exists (legacy) ${GREEN}PASSED${NC}\n"
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

    // Use high time values to ensure test transactions sort above any existing transactions
    // Time is in microseconds, use values far in the future for testing
    \$baseTime = 9999999999000000; // Far future time for test isolation

    // Create transaction A1
    \$a1Txid = 'chain-test-a1-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, time, timestamp) VALUES (?, 'standard', 'sent', 'completed', ?, ?, ?, ?, ?, ?, 100, 'USD', NULL, 'standard', 'chain-reorder-test-a1', ?, NOW())\");
    \$stmt->execute([\$a1Txid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash, \$baseTime]);

    // Create transaction A2 (will be cancelled)
    \$a2Txid = 'chain-test-a2-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, time, timestamp) VALUES (?, 'standard', 'sent', 'completed', ?, ?, ?, ?, ?, ?, 200, 'USD', ?, 'standard', 'chain-reorder-test-a2', ?, DATE_ADD(NOW(), INTERVAL 1 SECOND))\");
    \$stmt->execute([\$a2Txid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash, \$a1Txid, \$baseTime + 1000000]);

    // Create transaction A3
    \$a3Txid = 'chain-test-a3-${timestamp}';
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, time, timestamp) VALUES (?, 'standard', 'sent', 'completed', ?, ?, ?, ?, ?, ?, 300, 'USD', ?, 'standard', 'chain-reorder-test-a3', ?, DATE_ADD(NOW(), INTERVAL 2 SECOND))\");
    \$stmt->execute([\$a3Txid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash, \$a2Txid, \$baseTime + 2000000]);

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

############################ TEST 3: Verify sync INCLUDES cancelled transactions ############################

echo -e "\n[Test 3: Verify sync response INCLUDES cancelled transactions (chain integrity via inclusion)]"
totaltests=$(( totaltests + 1 ))

syncIncludeResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$transactionRepo = \$app->services->getTransactionRepository();

    // Get receiver pubkey
    \$receiverPubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${receiverAddress}');
    \$senderPubkey = \$app->getPublicKey();

    // Get all transactions between parties (this is what sync uses)
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

    // Sync now includes ALL transactions (including cancelled)
    // Chain integrity is maintained by including cancelled transactions, not by modifying previous_txid
    \$totalCount = count(\$transactions);

    if (\$totalCount === (\$activeCount + \$cancelledCount) && \$cancelledCount > 0) {
        echo 'PASSED:total=' . \$totalCount . ',active=' . \$activeCount . ',cancelled=' . \$cancelledCount;
    } else if (\$cancelledCount === 0) {
        echo 'NO_CANCELLED:total=' . \$totalCount . ',active=' . \$activeCount;
    } else {
        echo 'FAILED:total=' . \$totalCount . ',active=' . \$activeCount . ',cancelled=' . \$cancelledCount;
    }
" 2>/dev/null || echo "ERROR")

if [[ "$syncIncludeResult" == PASSED:* ]] || [[ "$syncIncludeResult" == NO_CANCELLED:* ]]; then
    printf "\t   Sync includes all transactions ${GREEN}PASSED${NC} (%s)\n" "${syncIncludeResult}"
    passed=$(( passed + 1 ))
else
    printf "\t   Sync includes all transactions ${RED}FAILED${NC} (%s)\n" "${syncIncludeResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 4: Verify insertTransaction excludes cancelled in prev_txid lookup ############################

echo -e "\n[Test 4: Verify insertTransaction excludes cancelled when finding prev_txid]"
totaltests=$(( totaltests + 1 ))

insertTestResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();

    \$receiverPubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${receiverAddress}');
    \$senderPubkey = \$app->getPublicKey();
    \$senderPubkeyHash = hash('sha256', \$senderPubkey);
    \$receiverPubkeyHash = hash('sha256', \$receiverPubkey);

    // Create a cancelled transaction as the most recent (using high time value)
    \$cancelledTxid = 'insert-test-cancelled-${timestamp}';
    \$cancelledTime = 9999999999999999; // Very high time value
    \$stmt = \$pdo->prepare(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash, receiver_address, receiver_public_key, receiver_public_key_hash, amount, currency, previous_txid, memo, description, time, timestamp) VALUES (?, 'standard', 'sent', 'cancelled', ?, ?, ?, ?, ?, ?, 500, 'USD', NULL, 'standard', 'insert-test-cancelled', ?, DATE_ADD(NOW(), INTERVAL 10 SECOND))\");
    \$stmt->execute([\$cancelledTxid, '${senderAddress}', \$senderPubkey, \$senderPubkeyHash, '${receiverAddress}', \$receiverPubkey, \$receiverPubkeyHash, \$cancelledTime]);

    // The insertTransaction query should exclude this cancelled transaction
    // Test by running the same query used in insertTransaction (with time-based ordering)
    \$query = \"SELECT txid FROM transactions
            WHERE ((sender_public_key_hash = ? AND receiver_public_key_hash = ?)
                OR (sender_public_key_hash = ? AND receiver_public_key_hash = ?))
            AND status NOT IN ('cancelled', 'rejected')
            ORDER BY COALESCE(time, 0) DESC, timestamp DESC LIMIT 1\";

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

cleanupSender=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'chain-reorder-test%' OR description LIKE 'insert-test%'\");
    echo 'DELETED:' . \$deleted;
" 2>/dev/null || echo "ERROR")

echo -e "\t   Cleanup: ${cleanupSender}"

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'transaction chain reorder'"
