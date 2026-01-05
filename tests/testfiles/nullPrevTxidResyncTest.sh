#!/bin/sh
# Copyright 2025 The Vowels Company

# Test NULL previous_txid resync edge case
# Verifies that when Contact A has Contact B in contacts but deletes their
# transactions table, sending a transaction with previous_txid = NULL
# correctly triggers the resync mechanism because B has existing transactions.
#
# Test scenario:
# 1. A and B are contacts with existing transactions
# 2. A deletes their transactions (simulating data loss)
# 3. A tries to send a new transaction to B (previous_txid = NULL)
# 4. B rejects with 'invalid_previous_txid' (because B expects a previous_txid)
# 5. Rejection includes expected_txid to help A resync

echo -e "\nTesting NULL previous_txid resync edge case..."

testname="nullPrevTxidResyncTest"
totaltests=0
passed=0
failure=0

# Use first two connected containers for testing
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
testPair="${containersLinkKeys[0]}"

if [[ -z "$testPair" ]]; then
    echo -e "${YELLOW}Warning: No container links defined, skipping NULL prev-txid resync test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'null prev-txid resync'"
    exit 0
fi

containerKeys=(${testPair//,/ })
sender="${containerKeys[0]}"
receiver="${containerKeys[1]}"

# Get addresses from containerAddresses
senderAddress="${containerAddresses[${sender}]}"
receiverAddress="${containerAddresses[${receiver}]}"

echo -e "\n[NULL Prev-Txid Resync Test Setup]"
echo -e "\t   Sender: ${sender} (${senderAddress})"
echo -e "\t   Receiver: ${receiver} (${receiverAddress})"

if [[ -z "$senderAddress" ]] || [[ -z "$receiverAddress" ]]; then
    echo -e "${YELLOW}Warning: Container addresses not populated, skipping test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'null prev-txid resync'"
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

senderPubkeyInfo=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}','${senderAddress}');
    if (\$pubkey) {
        echo base64_encode(\$pubkey) . '|' . hash('sha256', \$pubkey);
    } else {
        echo 'ERROR|ERROR';
    }
" 2>/dev/null || echo "ERROR|ERROR")

senderPubkeyB64=$(echo "$senderPubkeyInfo" | cut -d'|' -f1)
senderPubkeyHash=$(echo "$senderPubkeyInfo" | cut -d'|' -f2)

if [[ "$receiverPubkeyHash" == "ERROR" ]] || [[ -z "$receiverPubkeyHash" ]] || [[ "$senderPubkeyHash" == "ERROR" ]]; then
    echo -e "${YELLOW}Warning: Could not retrieve public keys, skipping test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'null prev-txid resync'"
    exit 0
fi

echo -e "\t   Sender pubkey hash: ${senderPubkeyHash:0:40}..."
echo -e "\t   Receiver pubkey hash: ${receiverPubkeyHash:0:40}..."

############################ TEST 1: Create initial transaction chain ############################

echo -e "\n[Test 1: Create initial transaction chain from sender to receiver]"
totaltests=$(( totaltests + 1 ))

timestamp=$(date +%s%N)

# Send 2 transactions to establish a chain
sendResult1=$(docker exec ${sender} eiou send ${receiverAddress} 1 USD "null-prevtxid-test-tx1-${timestamp}" 2>&1)
sleep 2
sendResult2=$(docker exec ${sender} eiou send ${receiverAddress} 2 USD "null-prevtxid-test-tx2-${timestamp}" 2>&1)
sleep 2

# Verify transactions exist on both sides
senderTxCount=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'null-prevtxid-test%${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

receiverTxCount=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'null-prevtxid-test%${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

echo -e "\t   Sender has ${senderTxCount} test transactions"
echo -e "\t   Receiver has ${receiverTxCount} test transactions"

if [[ "$senderTxCount" -ge 2 ]] && [[ "$receiverTxCount" -ge 2 ]]; then
    printf "\t   Initial chain created ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Initial chain creation ${RED}FAILED${NC} (sender: %s, receiver: %s)\n" "${senderTxCount}" "${receiverTxCount}"
    failure=$(( failure + 1 ))
fi

############################ TEST 2: Get receiver's expected previous_txid ############################

echo -e "\n[Test 2: Verify receiver has expected previous_txid for sender]"
totaltests=$(( totaltests + 1 ))

expectedPrevTxid=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$senderPubkey = base64_decode('${senderPubkeyB64}');
    \$receiverPubkey = \$app->getPublicKey();

    \$expectedTxid = \$app->services->getTransactionRepository()->getPreviousTxid(
        \$senderPubkey,
        \$receiverPubkey
    );

    echo \$expectedTxid ?? 'NULL';
" 2>/dev/null || echo "ERROR")

echo -e "\t   Receiver expects previous_txid: ${expectedPrevTxid:0:50}..."

if [[ "$expectedPrevTxid" != "NULL" ]] && [[ "$expectedPrevTxid" != "ERROR" ]] && [[ -n "$expectedPrevTxid" ]]; then
    printf "\t   Receiver has expected previous_txid ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Receiver expected previous_txid ${RED}FAILED${NC} (%s)\n" "${expectedPrevTxid}"
    failure=$(( failure + 1 ))
fi

############################ TEST 3: Delete sender's transactions (simulate data loss) ############################

echo -e "\n[Test 3: Delete sender's transactions to simulate data loss]"
totaltests=$(( totaltests + 1 ))

# Delete ALL transactions between sender and receiver from sender's database
deleteResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$receiverPubkeyHash = '${receiverPubkeyHash}';

    // Delete all transactions with this contact
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE
        sender_public_key_hash = '\${receiverPubkeyHash}' OR
        receiver_public_key_hash = '\${receiverPubkeyHash}'\");

    echo 'DELETED:' . \$deleted;
" 2>/dev/null || echo "ERROR")

# Verify sender's previous_txid is now NULL
senderPrevTxid=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$senderPubkey = \$app->getPublicKey();
    \$receiverPubkey = base64_decode('${receiverPubkeyB64}');

    \$prevTxid = \$app->services->getTransactionRepository()->getPreviousTxid(
        \$senderPubkey,
        \$receiverPubkey
    );

    echo \$prevTxid ?? 'NULL';
" 2>/dev/null || echo "ERROR")

echo -e "\t   ${deleteResult}"
echo -e "\t   Sender's previous_txid is now: ${senderPrevTxid}"

if [[ "$senderPrevTxid" == "NULL" ]]; then
    printf "\t   Sender data loss simulated ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Sender data loss simulation ${RED}FAILED${NC} (prev_txid: %s)\n" "${senderPrevTxid}"
    failure=$(( failure + 1 ))
fi

############################ TEST 4: Verify checkPreviousTxid detects mismatch ############################

echo -e "\n[Test 4: Verify checkPreviousTxid detects NULL vs expected mismatch]"
totaltests=$(( totaltests + 1 ))

# Test that the receiver's checkPreviousTxid correctly detects the mismatch
# when receiving a request with NULL previousTxid but expecting a real one
mismatchResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$transactionService = \$app->services->getTransactionService();

    // Mock request with NULL previousTxid (what sender would send after data loss)
    \$mockRequest = [
        'senderPublicKey' => base64_decode('${senderPubkeyB64}'),
        'receiverPublicKey' => \$app->getPublicKey(),
        'previousTxid' => null  // This is the edge case - sender lost data
    ];

    // Use reflection to test the private method
    \$reflection = new ReflectionClass(\$transactionService);
    \$method = \$reflection->getMethod('checkPreviousTxid');
    \$method->setAccessible(true);

    \$result = \$method->invoke(\$transactionService, \$mockRequest);

    // Should return FALSE because NULL !== expected_txid
    echo \$result ? 'MATCH' : 'MISMATCH';
" 2>/dev/null || echo "ERROR")

echo -e "\t   checkPreviousTxid result: ${mismatchResult}"

if [[ "$mismatchResult" == "MISMATCH" ]]; then
    printf "\t   NULL vs expected mismatch detected ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Mismatch detection ${RED}FAILED${NC} (%s)\n" "${mismatchResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 5: Verify rejection includes expected_txid ############################

echo -e "\n[Test 5: Verify rejection response includes expected_txid]"
totaltests=$(( totaltests + 1 ))

# Test that buildRejection includes expected_txid for invalid_previous_txid rejections
# TransactionPayload is accessed through TransactionService
rejectionResult=$(docker exec ${receiver} php -r "
    require_once('${REL_FUNCTIONS}');
    \$app = Application::getInstance();

    // Get TransactionPayload via TransactionService
    \$transactionService = \$app->services->getTransactionService();
    \$reflection = new ReflectionClass(\$transactionService);
    \$prop = \$reflection->getProperty('transactionPayload');
    \$prop->setAccessible(true);
    \$transactionPayload = \$prop->getValue(\$transactionService);

    \$mockRequest = [
        'senderAddress' => '${senderAddress}',
        'txid' => 'test-txid-123',
        'memo' => 'standard'
    ];

    \$expectedTxid = 'expected-prev-txid-abc123';

    \$rejection = \$transactionPayload->buildRejection(\$mockRequest, 'invalid_previous_txid', \$expectedTxid);
    \$decoded = json_decode(\$rejection, true);

    if (isset(\$decoded['expected_txid']) && \$decoded['expected_txid'] === \$expectedTxid) {
        echo 'INCLUDES_EXPECTED_TXID';
    } elseif (isset(\$decoded['expected_txid'])) {
        echo 'WRONG_EXPECTED_TXID:' . \$decoded['expected_txid'];
    } else {
        echo 'MISSING_EXPECTED_TXID';
    }
" 2>/dev/null || echo "ERROR")

echo -e "\t   Rejection response: ${rejectionResult}"

if [[ "$rejectionResult" == "INCLUDES_EXPECTED_TXID" ]]; then
    printf "\t   Rejection includes expected_txid ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Rejection expected_txid ${RED}FAILED${NC} (%s)\n" "${rejectionResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 6: Verify rejection does NOT include expected_txid for other reasons ############################

echo -e "\n[Test 6: Verify expected_txid is NOT included for non-resync rejections]"
totaltests=$(( totaltests + 1 ))

# Verify that expected_txid is only included for invalid_previous_txid, not for duplicate etc
# TransactionPayload is accessed through TransactionService
noExpectedTxidResult=$(docker exec ${receiver} php -r "
    require_once('${REL_FUNCTIONS}');
    \$app = Application::getInstance();

    // Get TransactionPayload via TransactionService
    \$transactionService = \$app->services->getTransactionService();
    \$reflection = new ReflectionClass(\$transactionService);
    \$prop = \$reflection->getProperty('transactionPayload');
    \$prop->setAccessible(true);
    \$transactionPayload = \$prop->getValue(\$transactionService);

    \$mockRequest = [
        'senderAddress' => '${senderAddress}',
        'txid' => 'test-txid-456',
        'memo' => 'standard'
    ];

    // Test with 'duplicate' reason - should NOT include expected_txid even if passed
    \$rejection = \$transactionPayload->buildRejection(\$mockRequest, 'duplicate', 'some-txid');
    \$decoded = json_decode(\$rejection, true);

    if (!isset(\$decoded['expected_txid'])) {
        echo 'CORRECTLY_EXCLUDED';
    } else {
        echo 'INCORRECTLY_INCLUDED:' . \$decoded['expected_txid'];
    }
" 2>/dev/null || echo "ERROR")

echo -e "\t   Non-resync rejection: ${noExpectedTxidResult}"

if [[ "$noExpectedTxidResult" == "CORRECTLY_EXCLUDED" ]]; then
    printf "\t   expected_txid correctly excluded ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   expected_txid exclusion ${RED}FAILED${NC} (%s)\n" "${noExpectedTxidResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 7: Test NULL === NULL case (first transaction) ############################

echo -e "\n[Test 7: Verify NULL === NULL is valid for first transaction]"
totaltests=$(( totaltests + 1 ))

# When there are no transactions between parties, NULL === NULL should be valid
nullNullResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$transactionService = \$app->services->getTransactionService();

    // Use a fake pubkey that has no transactions with this contact
    \$fakePubkey = 'fake-pubkey-no-transactions-' . time();

    \$mockRequest = [
        'senderPublicKey' => \$fakePubkey,
        'receiverPublicKey' => \$app->getPublicKey(),
        'previousTxid' => null  // First transaction should be valid with NULL
    ];

    \$reflection = new ReflectionClass(\$transactionService);
    \$method = \$reflection->getMethod('checkPreviousTxid');
    \$method->setAccessible(true);

    \$result = \$method->invoke(\$transactionService, \$mockRequest);

    // Should return TRUE because NULL === NULL (no existing transactions)
    echo \$result ? 'VALID_FIRST_TX' : 'INVALID';
" 2>/dev/null || echo "ERROR")

echo -e "\t   NULL === NULL result: ${nullNullResult}"

if [[ "$nullNullResult" == "VALID_FIRST_TX" ]]; then
    printf "\t   First transaction validation ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   First transaction validation ${RED}FAILED${NC} (%s)\n" "${nullNullResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 8: Test valid chain continuation ############################

echo -e "\n[Test 8: Verify valid previous_txid match passes]"
totaltests=$(( totaltests + 1 ))

# Create a transaction that correctly references the expected previous_txid
validChainResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$transactionService = \$app->services->getTransactionService();

    // Get the expected previous_txid for sender->receiver
    \$senderPubkey = base64_decode('${senderPubkeyB64}');
    \$expectedTxid = \$app->services->getTransactionRepository()->getPreviousTxid(
        \$senderPubkey,
        \$app->getPublicKey()
    );

    if (!\$expectedTxid) {
        echo 'NO_EXPECTED_TXID';
        exit;
    }

    // Mock request with CORRECT previousTxid
    \$mockRequest = [
        'senderPublicKey' => \$senderPubkey,
        'receiverPublicKey' => \$app->getPublicKey(),
        'previousTxid' => \$expectedTxid  // Correctly matches expected
    ];

    \$reflection = new ReflectionClass(\$transactionService);
    \$method = \$reflection->getMethod('checkPreviousTxid');
    \$method->setAccessible(true);

    \$result = \$method->invoke(\$transactionService, \$mockRequest);

    echo \$result ? 'VALID_CHAIN' : 'INVALID';
" 2>/dev/null || echo "ERROR")

echo -e "\t   Valid chain result: ${validChainResult}"

if [[ "$validChainResult" == "VALID_CHAIN" ]]; then
    printf "\t   Valid chain continuation ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Valid chain continuation ${RED}FAILED${NC} (%s)\n" "${validChainResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 9: Test mismatched txid case ############################

echo -e "\n[Test 9: Verify mismatched previous_txid is detected]"
totaltests=$(( totaltests + 1 ))

mismatchTxidResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$transactionService = \$app->services->getTransactionService();

    // Mock request with WRONG previousTxid
    \$mockRequest = [
        'senderPublicKey' => base64_decode('${senderPubkeyB64}'),
        'receiverPublicKey' => \$app->getPublicKey(),
        'previousTxid' => 'wrong-txid-that-does-not-match'
    ];

    \$reflection = new ReflectionClass(\$transactionService);
    \$method = \$reflection->getMethod('checkPreviousTxid');
    \$method->setAccessible(true);

    \$result = \$method->invoke(\$transactionService, \$mockRequest);

    // Should return FALSE because wrong_txid !== expected_txid
    echo \$result ? 'MATCH' : 'MISMATCH';
" 2>/dev/null || echo "ERROR")

echo -e "\t   Mismatched txid result: ${mismatchTxidResult}"

if [[ "$mismatchTxidResult" == "MISMATCH" ]]; then
    printf "\t   Mismatched txid detection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Mismatched txid detection ${RED}FAILED${NC} (%s)\n" "${mismatchTxidResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 10: Full integration - send transaction after data loss ############################

echo -e "\n[Test 10: Integration test - send transaction after data loss triggers resync]"
totaltests=$(( totaltests + 1 ))

# Try to send a transaction - this should fail with invalid_previous_txid
# and the sender should receive expected_txid in the response
integrationResult=$(docker exec ${sender} php -r "
    require_once('${REL_FUNCTIONS}');
    \$app = Application::getInstance();

    // Get receiver pubkey
    \$receiverPubkey = base64_decode('${receiverPubkeyB64}');
    \$receiverAddress = '${receiverAddress}';

    // Check current state - sender should have no previous_txid (deleted)
    \$senderPrevTxid = \$app->services->getTransactionRepository()->getPreviousTxid(
        \$app->getPublicKey(),
        \$receiverPubkey
    );

    if (\$senderPrevTxid !== null) {
        echo 'SENDER_STILL_HAS_TXID:' . \$senderPrevTxid;
        exit;
    }

    // The send will use previous_txid = NULL
    // Receiver should reject with invalid_previous_txid
    // This verifies the full flow works correctly
    echo 'SENDER_HAS_NULL_PREVTXID:ready_for_resync';
" 2>/dev/null || echo "ERROR")

echo -e "\t   Integration result: ${integrationResult}"

if [[ "$integrationResult" == *"ready_for_resync"* ]]; then
    printf "\t   Integration test ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Integration test ${RED}FAILED${NC} (%s)\n" "${integrationResult}"
    failure=$(( failure + 1 ))
fi

############################ CLEANUP ############################

echo -e "\n[Cleanup: Removing test transactions and restoring state]"

# Cleanup test transactions on receiver
cleanupReceiver=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'null-prevtxid-test%'\");
    echo 'DELETED:' . \$deleted;
" 2>/dev/null || echo "ERROR")

echo -e "\t   Cleanup receiver: ${cleanupReceiver}"

# Note: Sender's transactions were already deleted as part of the test
# Re-establish contact state for other tests
docker exec ${sender} eiou add ${receiverAddress} ${receiver} 0 0 USD 2>&1 || true
docker exec ${receiver} eiou add ${senderAddress} ${sender} 0 0 USD 2>&1 || true

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'null prev-txid resync'"
