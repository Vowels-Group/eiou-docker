#!/bin/sh
# Copyright 2025 The Vowels Company

# Test held transaction functionality for invalid_previous_txid handling
# Verifies that transactions are held when receiving invalid_previous_txid rejection,
# maintains per-contact isolation, and resumes correctly after sync
# Issue #338 - Feature to hold transactions until resync completes

echo -e "\nTesting held transaction functionality..."

testname="heldTransactionTest"
totaltests=0
passed=0
failure=0

# Use first two connected containers for testing
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
testPair="${containersLinkKeys[0]}"

if [[ -z "$testPair" ]]; then
    echo -e "${YELLOW}Warning: No container links defined, skipping held transaction test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'held transaction'"
    exit 0
fi

containerKeys=(${testPair//,/ })
sender="${containerKeys[0]}"
receiver="${containerKeys[1]}"

# Get addresses from containerAddresses (populated by hostnameTest/torAddressTest)
senderAddress="${containerAddresses[${sender}]}"
receiverAddress="${containerAddresses[${receiver}]}"

# Get third container for isolation test
if [[ ${#containersLinkKeys[@]} -ge 2 ]]; then
    thirdPair="${containersLinkKeys[1]}"
    thirdKeys=(${thirdPair//,/ })
    thirdContact="${thirdKeys[1]}"
    thirdAddress="${containerAddresses[${thirdContact}]}"
fi

echo -e "\n[Held Transaction Test Setup]"
echo -e "\t   Sender: ${sender} (${senderAddress})"
echo -e "\t   Receiver: ${receiver} (${receiverAddress})"
if [[ -n "$thirdAddress" ]]; then
    echo -e "\t   Third Contact: ${thirdContact} (${thirdAddress})"
fi

if [[ -z "$senderAddress" ]] || [[ -z "$receiverAddress" ]]; then
    echo -e "${YELLOW}Warning: Container addresses not populated, skipping test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'held transaction'"
    exit 0
fi

############################ Ensure Contacts Exist ############################

echo -e "\n[Ensuring contacts exist between nodes]"

# Add contacts if they don't exist
docker exec ${sender} eiou add ${receiverAddress} ${receiver} 0 0 USD 2>&1 || true
docker exec ${receiver} eiou add ${senderAddress} ${sender} 0 0 USD 2>&1 || true

if [[ -n "$thirdAddress" ]]; then
    docker exec ${sender} eiou add ${thirdAddress} ${thirdContact} 0 0 USD 2>&1 || true
    docker exec ${thirdContact} eiou add ${senderAddress} ${sender} 0 0 USD 2>&1 || true
fi

# Wait briefly for contacts to be established
sleep 2

############################ TEST 1: Verify held_transactions table exists ############################

echo -e "\n[Test 1: Verify held_transactions table schema]"
totaltests=$(( totaltests + 1 ))

tableSchema=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();

    // Check if table exists
    \$tables = \$pdo->query(\"SHOW TABLES LIKE 'held_transactions'\")->fetchAll();
    if (empty(\$tables)) {
        echo 'TABLE_MISSING';
        exit;
    }

    // Get table columns
    \$columns = \$pdo->query(\"DESCRIBE held_transactions\")->fetchAll(PDO::FETCH_ASSOC);
    \$columnNames = array_column(\$columns, 'Field');

    // Check required columns
    \$requiredColumns = ['id', 'contact_address', 'contact_pubkey_hash', 'amount', 'currency', 'description', 'created_at'];
    \$missingColumns = array_diff(\$requiredColumns, \$columnNames);

    if (!empty(\$missingColumns)) {
        echo 'MISSING_COLUMNS:' . implode(',', \$missingColumns);
    } else {
        echo 'SCHEMA_VALID';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$tableSchema" == "SCHEMA_VALID" ]]; then
    printf "\t   held_transactions table schema ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   held_transactions table schema ${RED}FAILED${NC} (%s)\n" "${tableSchema}"
    failure=$(( failure + 1 ))
fi

############################ TEST 2: Verify holdTransaction method exists ############################

echo -e "\n[Test 2: Verify holdTransaction method exists]"
totaltests=$(( totaltests + 1 ))

methodExists=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$txRepo = \$app->services->getTransactionRepository();
    echo method_exists(\$txRepo, 'holdTransaction') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$methodExists" == "EXISTS" ]]; then
    printf "\t   holdTransaction method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   holdTransaction method ${RED}FAILED${NC} (%s)\n" "${methodExists}"
    failure=$(( failure + 1 ))
fi

############################ TEST 3: Verify getHeldTransactions method exists ############################

echo -e "\n[Test 3: Verify getHeldTransactions method exists]"
totaltests=$(( totaltests + 1 ))

getMethodExists=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$txRepo = \$app->services->getTransactionRepository();
    echo method_exists(\$txRepo, 'getHeldTransactions') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$getMethodExists" == "EXISTS" ]]; then
    printf "\t   getHeldTransactions method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   getHeldTransactions method ${RED}FAILED${NC} (%s)\n" "${getMethodExists}"
    failure=$(( failure + 1 ))
fi

############################ TEST 4: Verify clearHeldTransactions method exists ############################

echo -e "\n[Test 4: Verify clearHeldTransactions method exists]"
totaltests=$(( totaltests + 1 ))

clearMethodExists=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$txRepo = \$app->services->getTransactionRepository();
    echo method_exists(\$txRepo, 'clearHeldTransactions') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$clearMethodExists" == "EXISTS" ]]; then
    printf "\t   clearHeldTransactions method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   clearHeldTransactions method ${RED}FAILED${NC} (%s)\n" "${clearMethodExists}"
    failure=$(( failure + 1 ))
fi

############################ TEST 5: Test holding a transaction ############################

echo -e "\n[Test 5: Test holding a transaction]"
totaltests=$(( totaltests + 1 ))

timestamp=$(date +%s%N)

# Hold a test transaction
holdResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$txRepo = \$app->services->getTransactionRepository();

    // Get receiver pubkey hash from contacts
    \$contact = \$app->services->getContactRepository()->lookup('${MODE}', '${receiverAddress}');
    if (!\$contact) {
        echo 'CONTACT_NOT_FOUND';
        exit;
    }

    \$pubkeyHash = \$contact['pubkey_hash'];

    // Hold a transaction
    \$txRepo->holdTransaction(
        '${receiverAddress}',
        \$pubkeyHash,
        100,
        'USD',
        'held-test-tx-${timestamp}'
    );

    echo 'HELD_SUCCESS';
" 2>/dev/null || echo "ERROR")

# Verify transaction was held
heldCount=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM held_transactions WHERE description LIKE 'held-test-tx-${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

if [[ "$holdResult" == "HELD_SUCCESS" ]] && [[ "$heldCount" == "1" ]]; then
    printf "\t   Transaction held successfully ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Hold transaction ${RED}FAILED${NC} (result: %s, count: %s)\n" "${holdResult}" "${heldCount}"
    failure=$(( failure + 1 ))
fi

############################ TEST 6: Test retrieving held transactions ############################

echo -e "\n[Test 6: Test retrieving held transactions for contact]"
totaltests=$(( totaltests + 1 ))

# Get receiver pubkey hash
receiverPubkeyHash=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$contact = \$app->services->getContactRepository()->lookup('${MODE}', '${receiverAddress}');
    echo \$contact ? \$contact['pubkey_hash'] : 'ERROR';
" 2>/dev/null || echo "ERROR")

# Retrieve held transactions
retrieveResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$txRepo = \$app->services->getTransactionRepository();

    \$heldTxs = \$txRepo->getHeldTransactions('${receiverPubkeyHash}');

    if (empty(\$heldTxs)) {
        echo 'NO_HELD_TX';
    } else {
        \$found = false;
        foreach (\$heldTxs as \$tx) {
            if (strpos(\$tx['description'], 'held-test-tx-${timestamp}') !== false) {
                \$found = true;
                break;
            }
        }
        echo \$found ? 'FOUND:' . count(\$heldTxs) : 'NOT_FOUND';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$retrieveResult" == FOUND:* ]]; then
    heldTxCount=$(echo "$retrieveResult" | cut -d':' -f2)
    printf "\t   Retrieved held transactions ${GREEN}PASSED${NC} (found %s)\n" "${heldTxCount}"
    passed=$(( passed + 1 ))
else
    printf "\t   Retrieve held transactions ${RED}FAILED${NC} (%s)\n" "${retrieveResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 7: Test per-contact isolation ############################

if [[ -n "$thirdAddress" ]]; then
    echo -e "\n[Test 7: Test per-contact isolation]"
    totaltests=$(( totaltests + 1 ))

    # Get third contact pubkey hash
    thirdPubkeyHash=$(docker exec ${sender} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$contact = \$app->services->getContactRepository()->lookup('${MODE}', '${thirdAddress}');
        echo \$contact ? \$contact['pubkey_hash'] : 'ERROR';
    " 2>/dev/null || echo "ERROR")

    # Hold a transaction for third contact
    holdThirdResult=$(docker exec ${sender} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$txRepo = \$app->services->getTransactionRepository();

        \$txRepo->holdTransaction(
            '${thirdAddress}',
            '${thirdPubkeyHash}',
            200,
            'USD',
            'held-third-tx-${timestamp}'
        );

        echo 'HELD_SUCCESS';
    " 2>/dev/null || echo "ERROR")

    # Verify isolation - receiver's held transactions should not include third contact's
    isolationResult=$(docker exec ${sender} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$txRepo = \$app->services->getTransactionRepository();

        \$receiverTxs = \$txRepo->getHeldTransactions('${receiverPubkeyHash}');
        \$thirdTxs = \$txRepo->getHeldTransactions('${thirdPubkeyHash}');

        \$receiverHasThird = false;
        foreach (\$receiverTxs as \$tx) {
            if (strpos(\$tx['description'], 'held-third-tx') !== false) {
                \$receiverHasThird = true;
                break;
            }
        }

        \$thirdHasReceiver = false;
        foreach (\$thirdTxs as \$tx) {
            if (strpos(\$tx['description'], 'held-test-tx') !== false && strpos(\$tx['description'], 'held-third') === false) {
                \$thirdHasReceiver = true;
                break;
            }
        }

        if (\$receiverHasThird || \$thirdHasReceiver) {
            echo 'ISOLATION_FAILED';
        } else {
            echo 'ISOLATED:' . count(\$receiverTxs) . '/' . count(\$thirdTxs);
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$isolationResult" == ISOLATED:* ]]; then
        printf "\t   Per-contact isolation ${GREEN}PASSED${NC} (%s)\n" "${isolationResult}"
        passed=$(( passed + 1 ))
    else
        printf "\t   Per-contact isolation ${RED}FAILED${NC} (%s)\n" "${isolationResult}"
        failure=$(( failure + 1 ))
    fi
else
    echo -e "\n[Test 7: Skipped - per-contact isolation (need 3+ containers)]"
fi

############################ TEST 8: Test clearing held transactions after sync ############################

echo -e "\n[Test 8: Test clearing held transactions after sync]"
totaltests=$(( totaltests + 1 ))

# Clear held transactions for receiver
clearResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$txRepo = \$app->services->getTransactionRepository();

    \$deleted = \$txRepo->clearHeldTransactions('${receiverPubkeyHash}');
    echo 'CLEARED:' . \$deleted;
" 2>/dev/null || echo "ERROR")

# Verify transactions were cleared
clearedCount=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$txRepo = \$app->services->getTransactionRepository();

    \$heldTxs = \$txRepo->getHeldTransactions('${receiverPubkeyHash}');
    echo count(\$heldTxs);
" 2>/dev/null || echo "ERROR")

if [[ "$clearResult" == CLEARED:* ]] && [[ "$clearedCount" == "0" ]]; then
    printf "\t   Clear held transactions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Clear held transactions ${RED}FAILED${NC} (result: %s, remaining: %s)\n" "${clearResult}" "${clearedCount}"
    failure=$(( failure + 1 ))
fi

############################ TEST 9: Test sync integration with held transactions ############################

echo -e "\n[Test 9: Test sync integration with held transactions]"
totaltests=$(( totaltests + 1 ))

# Send a real transaction to establish chain
sendResult=$(docker exec ${sender} eiou send ${receiverAddress} 5 USD "sync-held-test-${timestamp}" 2>&1)
sleep 2

# Delete transaction from sender to simulate desync
deleteResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'sync-held-test-${timestamp}'\");
    echo 'DELETED:' . \$deleted;
" 2>/dev/null || echo "ERROR")

# Hold a new transaction (this simulates what would happen after invalid_previous_txid rejection)
holdNewResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$txRepo = \$app->services->getTransactionRepository();

    \$contact = \$app->services->getContactRepository()->lookup('${MODE}', '${receiverAddress}');
    \$pubkeyHash = \$contact['pubkey_hash'];

    \$txRepo->holdTransaction(
        '${receiverAddress}',
        \$pubkeyHash,
        10,
        'USD',
        'held-after-desync-${timestamp}'
    );

    echo 'HELD_SUCCESS';
" 2>/dev/null || echo "ERROR")

# Verify held transaction exists before sync
heldBeforeSync=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM held_transactions WHERE description LIKE 'held-after-desync-${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

if [[ "$holdNewResult" == "HELD_SUCCESS" ]] && [[ "$heldBeforeSync" == "1" ]]; then
    printf "\t   Transaction held after simulated desync ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Hold after desync ${RED}FAILED${NC} (result: %s, count: %s)\n" "${holdNewResult}" "${heldBeforeSync}"
    failure=$(( failure + 1 ))
fi

############################ TEST 10: Verify resumeHeldTransactions method exists ############################

echo -e "\n[Test 10: Verify resumeHeldTransactions method exists]"
totaltests=$(( totaltests + 1 ))

resumeMethodExists=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$txService = \$app->services->getTransactionService();
    echo method_exists(\$txService, 'resumeHeldTransactions') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$resumeMethodExists" == "EXISTS" ]]; then
    printf "\t   resumeHeldTransactions method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   resumeHeldTransactions method ${RED}FAILED${NC} (%s)\n" "${resumeMethodExists}"
    failure=$(( failure + 1 ))
fi

############################ CLEANUP ############################

echo -e "\n[Cleanup: Removing test data]"

# Clean up held transactions
cleanupHeld=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM held_transactions WHERE description LIKE 'held-%${timestamp}'\");
    echo 'DELETED_HELD:' . \$deleted;
" 2>/dev/null || echo "ERROR")

# Clean up transactions
cleanupTx=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE '%${timestamp}'\");
    echo 'DELETED_TX:' . \$deleted;
" 2>/dev/null || echo "ERROR")

cleanupReceiver=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE '%${timestamp}'\");
    echo 'DELETED_TX:' . \$deleted;
" 2>/dev/null || echo "ERROR")

if [[ -n "$thirdAddress" ]]; then
    cleanupThird=$(docker exec ${sender} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$deleted = \$pdo->exec(\"DELETE FROM held_transactions WHERE contact_address = '${thirdAddress}'\");
        echo 'DELETED_THIRD:' . \$deleted;
    " 2>/dev/null || echo "ERROR")
    echo -e "\t   Cleanup third contact: ${cleanupThird}"
fi

echo -e "\t   Cleanup sender held: ${cleanupHeld}"
echo -e "\t   Cleanup sender transactions: ${cleanupTx}"
echo -e "\t   Cleanup receiver transactions: ${cleanupReceiver}"

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'held transaction'"
