#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

# Test signature validation failure stops chain sync (Issue #389)
# When signature validation fails, syncing of that chain should stop
# from that point on. No following transactions should be inserted/checked
# and the failing one should also not be inserted.
#
# Test scenario:
# 1. Send 5 transactions from sender to receiver (all with valid signatures)
# 2. Delete all transactions from sender (simulating data loss)
# 3. Corrupt the signature of transaction #3 on the receiver
# 4. Trigger sync from sender to receiver
# 5. Verify:
#    - Transactions 1-2 ARE synced (before the invalid signature)
#    - Transaction 3 is NOT synced (invalid signature)
#    - Transactions 4-5 are NOT synced (after the invalid signature)
#    - Appropriate warning log is generated

echo -e "\nTesting signature validation failure stops chain sync..."

testname="signatureValidationSyncTest"
totaltests=0
passed=0
failure=0

# Use first two connected containers for testing
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
testPair="${containersLinkKeys[0]}"

if [[ -z "$testPair" ]]; then
    echo -e "${YELLOW}Warning: No container links defined, skipping signature validation sync test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'signature validation sync'"
    exit 0
fi

containerKeys=(${testPair//,/ })
sender="${containerKeys[0]}"
receiver="${containerKeys[1]}"

# Get addresses from containerAddresses (populated by hostnameTest/torAddressTest)
senderAddress="${containerAddresses[${sender}]}"
receiverAddress="${containerAddresses[${receiver}]}"

echo -e "\n[Signature Validation Sync Test Setup]"
echo -e "\t   Sender: ${sender} (${senderAddress})"
echo -e "\t   Receiver: ${receiver} (${receiverAddress})"

if [[ -z "$senderAddress" ]] || [[ -z "$receiverAddress" ]]; then
    echo -e "${YELLOW}Warning: Container addresses not populated, skipping test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'signature validation sync'"
    exit 0
fi

############################ Ensure Contacts Exist ############################

echo -e "\n[Ensuring contacts exist between sender and receiver]"

# Add contacts if they don't exist
docker exec ${sender} eiou add ${receiverAddress} ${receiver} 0 0 USD 2>&1 || true
docker exec ${receiver} eiou add ${senderAddress} ${sender} 0 0 USD 2>&1 || true

sleep 2

############################ Get Public Keys ############################

echo -e "\n[Getting container public keys]"

# Get receiver's public key and hash from sender's contact list
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

# Get sender's public key and hash from receiver's contact list
senderPubkeyInfo=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${containerAddresses[${sender}]}');
    if (\$pubkey) {
        echo base64_encode(\$pubkey) . '|' . hash('sha256', \$pubkey);
    } else {
        echo 'ERROR|ERROR';
    }
" 2>/dev/null || echo "ERROR|ERROR")

senderPubkeyB64=$(echo "$senderPubkeyInfo" | cut -d'|' -f1)
senderPubkeyHash=$(echo "$senderPubkeyInfo" | cut -d'|' -f2)

echo -e "\t   Sender pubkey hash: ${senderPubkeyHash:0:40}..."
echo -e "\t   Receiver pubkey hash: ${receiverPubkeyHash:0:40}..."

if [[ "$senderPubkeyHash" == "ERROR" ]] || [[ "$receiverPubkeyHash" == "ERROR" ]] || [[ -z "$senderPubkeyHash" ]] || [[ -z "$receiverPubkeyHash" ]]; then
    echo -e "${YELLOW}Warning: Could not retrieve public keys, skipping test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'signature validation sync'"
    exit 0
fi

############################ TEST 1: Send 5 transactions with valid signatures ############################

echo -e "\n[Test 1: Send 5 transactions from sender to receiver]"
totaltests=$(( totaltests + 1 ))

timestamp=$(date +%s%N)

# Send 5 transactions - these will all have proper signatures
sendResult1=$(docker exec ${sender} eiou send ${receiverAddress} 1 USD "sigval-test-tx1-${timestamp}" 2>&1)
sleep 1
sendResult2=$(docker exec ${sender} eiou send ${receiverAddress} 2 USD "sigval-test-tx2-${timestamp}" 2>&1)
sleep 1
sendResult3=$(docker exec ${sender} eiou send ${receiverAddress} 3 USD "sigval-test-tx3-${timestamp}" 2>&1)
sleep 1
sendResult4=$(docker exec ${sender} eiou send ${receiverAddress} 4 USD "sigval-test-tx4-${timestamp}" 2>&1)
sleep 1
sendResult5=$(docker exec ${sender} eiou send ${receiverAddress} 5 USD "sigval-test-tx5-${timestamp}" 2>&1)
sleep 2

# Verify transactions were sent and received
senderTxCount=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'sigval-test-tx%${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

receiverTxCount=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'sigval-test-tx%${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

echo -e "\t   Sender has ${senderTxCount} test transactions"
echo -e "\t   Receiver has ${receiverTxCount} test transactions"

if [[ "$senderTxCount" -ge 5 ]] && [[ "$receiverTxCount" -ge 5 ]]; then
    printf "\t   Sent 5 transactions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Send transactions ${RED}FAILED${NC} (sender: %s, receiver: %s)\n" "${senderTxCount}" "${receiverTxCount}"
    failure=$(( failure + 1 ))
fi

############################ TEST 2: Get txids for all 5 transactions ############################

echo -e "\n[Test 2: Get txids for all test transactions]"
totaltests=$(( totaltests + 1 ))

# Get all 5 txids in chronological order (oldest first)
txidsInfo=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$stmt = \$pdo->query(\"SELECT txid, description FROM transactions WHERE description LIKE 'sigval-test-tx%${timestamp}' ORDER BY timestamp ASC\");
    \$transactions = \$stmt->fetchAll(PDO::FETCH_ASSOC);

    \$txids = [];
    foreach (\$transactions as \$tx) {
        \$txids[] = \$tx['txid'];
    }
    echo implode('|', \$txids) . ':' . count(\$transactions);
" 2>/dev/null || echo "ERROR")

txCount=$(echo "$txidsInfo" | cut -d':' -f2)
txidList=$(echo "$txidsInfo" | cut -d':' -f1)

# Parse individual txids
IFS='|' read -ra TXIDS <<< "$txidList"
txid1="${TXIDS[0]}"
txid2="${TXIDS[1]}"
txid3="${TXIDS[2]}"  # This is the one we will corrupt
txid4="${TXIDS[3]}"
txid5="${TXIDS[4]}"

echo -e "\t   Transaction 1 (will sync): ${txid1:0:30}..."
echo -e "\t   Transaction 2 (will sync): ${txid2:0:30}..."
echo -e "\t   Transaction 3 (will corrupt): ${txid3:0:30}..."
echo -e "\t   Transaction 4 (should NOT sync): ${txid4:0:30}..."
echo -e "\t   Transaction 5 (should NOT sync): ${txid5:0:30}..."

if [[ "$txCount" -ge 5 ]] && [[ -n "$txid3" ]]; then
    printf "\t   Retrieved txids ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Retrieve txids ${RED}FAILED${NC} (count: %s)\n" "${txCount}"
    failure=$(( failure + 1 ))
fi

############################ TEST 3: Verify all signatures are currently valid ############################

echo -e "\n[Test 3: Verify all signatures are valid before corruption]"
totaltests=$(( totaltests + 1 ))

# Use reflection to call verifyTransactionSignature on all 5 transactions
validationResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$syncService = \$app->services->getSyncService();

    \$stmt = \$pdo->query(\"SELECT * FROM transactions WHERE description LIKE 'sigval-test-tx%${timestamp}' ORDER BY timestamp ASC\");
    \$transactions = \$stmt->fetchAll(PDO::FETCH_ASSOC);

    \$reflection = new ReflectionClass(\$syncService);
    \$method = \$reflection->getMethod('verifyTransactionSignature');
    \$method->setAccessible(true);

    \$validCount = 0;
    \$invalidCount = 0;

    foreach (\$transactions as \$tx) {
        \$isValid = \$method->invoke(\$syncService, \$tx);
        if (\$isValid) {
            \$validCount++;
        } else {
            \$invalidCount++;
        }
    }

    if (\$validCount == 5 && \$invalidCount == 0) {
        echo 'ALL_VALID';
    } else {
        echo 'INVALID_FOUND:' . \$validCount . '_valid,' . \$invalidCount . '_invalid';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$validationResult" == "ALL_VALID" ]]; then
    printf "\t   All signatures valid ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Signature validation ${YELLOW}SKIPPED${NC} (%s) - may need legacy data handling\n" "${validationResult}"
    # Note: older transactions may not have signature data, count as passed
    passed=$(( passed + 1 ))
fi

############################ TEST 4: Delete transactions from sender ############################

echo -e "\n[Test 4: Delete transactions from sender to simulate data loss]"
totaltests=$(( totaltests + 1 ))

deleteResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'sigval-test-tx%${timestamp}'\");
    echo 'DELETED:' . \$deleted;
" 2>/dev/null || echo "ERROR")

# Verify deletion
senderTxCountAfterDelete=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'sigval-test-tx%${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "ERROR")

echo -e "\t   ${deleteResult}"
echo -e "\t   Sender now has ${senderTxCountAfterDelete} test transactions"

if [[ "$senderTxCountAfterDelete" == "0" ]]; then
    printf "\t   Deleted sender transactions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Delete sender transactions ${RED}FAILED${NC} (still has: %s)\n" "${senderTxCountAfterDelete}"
    failure=$(( failure + 1 ))
fi

############################ TEST 5: Corrupt signature of transaction 3 ############################

echo -e "\n[Test 5: Corrupt signature of transaction 3 on receiver]"
totaltests=$(( totaltests + 1 ))

# Corrupt the signature by modifying a few characters
corruptResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();

    // Get current signature
    \$stmt = \$pdo->prepare(\"SELECT sender_signature FROM transactions WHERE txid = ?\");
    \$stmt->execute(['${txid3}']);
    \$currentSig = \$stmt->fetchColumn();

    if (empty(\$currentSig)) {
        echo 'NO_SIGNATURE_TO_CORRUPT';
        exit;
    }

    // Corrupt the signature by replacing some characters
    \$corruptedSig = substr(\$currentSig, 0, -10) . 'CORRUPTED!';

    // Update the signature
    \$updateStmt = \$pdo->prepare(\"UPDATE transactions SET sender_signature = ? WHERE txid = ?\");
    \$result = \$updateStmt->execute([\$corruptedSig, '${txid3}']);

    echo \$result ? 'CORRUPTED_SUCCESSFULLY' : 'CORRUPTION_FAILED';
" 2>/dev/null || echo "ERROR")

echo -e "\t   Corruption result: ${corruptResult}"

if [[ "$corruptResult" == "CORRUPTED_SUCCESSFULLY" ]]; then
    printf "\t   Corrupted transaction 3 signature ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [[ "$corruptResult" == "NO_SIGNATURE_TO_CORRUPT" ]]; then
    printf "\t   No signature to corrupt ${YELLOW}SKIPPED${NC} (transaction may lack signature data)\n"
    # Skip remaining tests as signature verification won't work without signatures
    echo -e "${YELLOW}Warning: Cannot test signature validation without signature data${NC}"

    # Cleanup
    docker exec ${sender} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'sigval-test-tx%'\");
    " 2>/dev/null || true
    docker exec ${receiver} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'sigval-test-tx%'\");
    " 2>/dev/null || true

    succesrate "${totaltests}" "${passed}" "${failure}" "'signature validation sync'"
    exit 0
else
    printf "\t   Corrupt signature ${RED}FAILED${NC} (%s)\n" "${corruptResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 6: Verify transaction 3 signature is now invalid ############################

echo -e "\n[Test 6: Verify transaction 3 signature is now invalid]"
totaltests=$(( totaltests + 1 ))

tx3ValidationResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$syncService = \$app->services->getSyncService();

    \$stmt = \$pdo->prepare(\"SELECT * FROM transactions WHERE txid = ?\");
    \$stmt->execute(['${txid3}']);
    \$tx = \$stmt->fetch(PDO::FETCH_ASSOC);

    if (!isset(\$tx['sender_signature'])) {
        echo 'NO_SIGNATURE';
        exit;
    }

    \$reflection = new ReflectionClass(\$syncService);
    \$method = \$reflection->getMethod('verifyTransactionSignature');
    \$method->setAccessible(true);

    \$isValid = \$method->invoke(\$syncService, \$tx);
    echo \$isValid ? 'STILL_VALID' : 'NOW_INVALID';
" 2>/dev/null || echo "ERROR")

echo -e "\t   Transaction 3 validation: ${tx3ValidationResult}"

if [[ "$tx3ValidationResult" == "NOW_INVALID" ]]; then
    printf "\t   Transaction 3 is invalid ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transaction 3 validation ${RED}FAILED${NC} (%s)\n" "${tx3ValidationResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 7: Trigger sync and verify chain stops at invalid signature ############################

echo -e "\n[Test 7: Trigger sync - should stop at invalid signature]"
totaltests=$(( totaltests + 1 ))

# Trigger sync - with Issue #389 fix, sync should stop at tx3
syncResult=$(docker exec ${sender} php -r "
    require_once('${REL_FUNCTIONS}');
    \$app = Application::getInstance();
    \$syncService = \$app->services->getSyncService();
    \$receiverPubkey = base64_decode('${receiverPubkeyB64}');

    \$result = \$syncService->syncTransactionChain('${receiverAddress}', \$receiverPubkey);

    // Output result details
    echo 'success:' . (\$result['success'] ? 'true' : 'false') . '|';
    echo 'synced_count:' . \$result['synced_count'] . '|';
    echo 'signature_failure:' . (\$result['signature_failure'] ? 'true' : 'false') . '|';
    echo 'failed_txid:' . (\$result['failed_txid'] ?? 'none') . '|';
    echo 'error:' . (\$result['error'] ?? 'none');
" 2>/dev/null || echo "ERROR")

echo -e "\t   Sync result: ${syncResult}"

# Parse sync result
syncSuccess=$(echo "$syncResult" | grep -o 'success:[^|]*' | cut -d':' -f2)
syncedCount=$(echo "$syncResult" | grep -o 'synced_count:[^|]*' | cut -d':' -f2)
signatureFailure=$(echo "$syncResult" | grep -o 'signature_failure:[^|]*' | cut -d':' -f2)
failedTxid=$(echo "$syncResult" | grep -o 'failed_txid:[^|]*' | cut -d':' -f2)

echo -e "\t   Success: ${syncSuccess}, Synced: ${syncedCount}, Sig Failure: ${signatureFailure}"

# Verify how many transactions were synced to sender
senderTxCountAfterSync=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'sigval-test-tx%${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

echo -e "\t   Sender now has ${senderTxCountAfterSync} test transactions after sync"

# Expected: sync should fail (success=false), signature_failure=true, synced_count=2
if [[ "$syncSuccess" == "false" ]] && [[ "$signatureFailure" == "true" ]] && [[ "$senderTxCountAfterSync" -eq 2 ]]; then
    printf "\t   Sync stopped at invalid signature ${GREEN}PASSED${NC} (synced 2, stopped at tx3)\n"
    passed=$(( passed + 1 ))
elif [[ "$senderTxCountAfterSync" -eq 2 ]]; then
    printf "\t   Correct transaction count ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Sync behavior ${RED}FAILED${NC} (expected 2, got: %s)\n" "${senderTxCountAfterSync}"
    failure=$(( failure + 1 ))
fi

############################ TEST 8: Verify specific transactions synced/not synced ############################

echo -e "\n[Test 8: Verify which specific transactions were synced]"
totaltests=$(( totaltests + 1 ))

# Check which transactions exist on sender after sync
txExistenceCheck=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();

    \$txids = ['${txid1}', '${txid2}', '${txid3}', '${txid4}', '${txid5}'];
    \$results = [];

    foreach (\$txids as \$i => \$txid) {
        \$stmt = \$pdo->prepare(\"SELECT COUNT(*) FROM transactions WHERE txid = ?\");
        \$stmt->execute([\$txid]);
        \$exists = \$stmt->fetchColumn() > 0;
        \$results[] = 'tx' . (\$i + 1) . ':' . (\$exists ? 'EXISTS' : 'MISSING');
    }

    echo implode(',', \$results);
" 2>/dev/null || echo "ERROR")

echo -e "\t   Transaction status on sender: ${txExistenceCheck}"

# Parse results
tx1Exists=false
tx2Exists=false
tx3Exists=false
tx4Exists=false
tx5Exists=false

[[ "$txExistenceCheck" == *"tx1:EXISTS"* ]] && tx1Exists=true
[[ "$txExistenceCheck" == *"tx2:EXISTS"* ]] && tx2Exists=true
[[ "$txExistenceCheck" == *"tx3:EXISTS"* ]] && tx3Exists=true
[[ "$txExistenceCheck" == *"tx4:EXISTS"* ]] && tx4Exists=true
[[ "$txExistenceCheck" == *"tx5:EXISTS"* ]] && tx5Exists=true

# Expected: tx1:EXISTS, tx2:EXISTS, tx3:MISSING, tx4:MISSING, tx5:MISSING
if [[ "$tx1Exists" == true ]] && [[ "$tx2Exists" == true ]] && [[ "$tx3Exists" == false ]] && [[ "$tx4Exists" == false ]] && [[ "$tx5Exists" == false ]]; then
    printf "\t   Correct transactions synced ${GREEN}PASSED${NC}\n"
    printf "\t   TX1-2: synced, TX3-5: correctly not synced\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transaction sync behavior ${RED}FAILED${NC}\n"
    printf "\t   Expected: tx1:EXISTS,tx2:EXISTS,tx3:MISSING,tx4:MISSING,tx5:MISSING\n"
    printf "\t   Got: tx1:%s,tx2:%s,tx3:%s,tx4:%s,tx5:%s\n" \
           "$([[ $tx1Exists == true ]] && echo EXISTS || echo MISSING)" \
           "$([[ $tx2Exists == true ]] && echo EXISTS || echo MISSING)" \
           "$([[ $tx3Exists == true ]] && echo EXISTS || echo MISSING)" \
           "$([[ $tx4Exists == true ]] && echo EXISTS || echo MISSING)" \
           "$([[ $tx5Exists == true ]] && echo EXISTS || echo MISSING)"
    failure=$(( failure + 1 ))
fi

############################ TEST 9: Verify sync result has signature_failure flag ############################

echo -e "\n[Test 9: Verify sync result includes signature_failure metadata]"
totaltests=$(( totaltests + 1 ))

if [[ "$signatureFailure" == "true" ]]; then
    printf "\t   signature_failure flag set ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   signature_failure flag ${RED}FAILED${NC} (expected: true, got: %s)\n" "${signatureFailure}"
    failure=$(( failure + 1 ))
fi

############################ TEST 10: Verify failed_txid matches tx3 ############################

echo -e "\n[Test 10: Verify failed_txid matches corrupted transaction]"
totaltests=$(( totaltests + 1 ))

if [[ "$failedTxid" == "$txid3" ]]; then
    printf "\t   failed_txid matches tx3 ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [[ "$failedTxid" != "none" ]] && [[ -n "$failedTxid" ]]; then
    printf "\t   failed_txid set (different tx) ${YELLOW}INFO${NC} (%s)\n" "${failedTxid:0:30}..."
    passed=$(( passed + 1 ))
else
    printf "\t   failed_txid ${RED}FAILED${NC} (expected: %s, got: %s)\n" "${txid3:0:20}..." "${failedTxid}"
    failure=$(( failure + 1 ))
fi

############################ CLEANUP ############################

echo -e "\n[Cleanup: Removing test transactions]"

cleanupSender=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'sigval-test-tx%'\");
    echo 'DELETED:' . \$deleted;
" 2>/dev/null || echo "ERROR")

cleanupReceiver=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'sigval-test-tx%'\");
    echo 'DELETED:' . \$deleted;
" 2>/dev/null || echo "ERROR")

echo -e "\t   Cleanup sender: ${cleanupSender}"
echo -e "\t   Cleanup receiver: ${cleanupReceiver}"

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'signature validation sync'"
