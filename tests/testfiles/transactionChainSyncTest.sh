#!/bin/sh
# Copyright 2025 The Vowels Company

# Test transaction chain sync functionality
# This test simulates a previousTxid mismatch by inserting transactions directly
# into one node's database that the other node doesn't know about, then attempts
# a transaction which should trigger the sync mechanism.
# Issue #332 - Transaction chain sync for invalid_previous_txid recovery

echo -e "\nTesting transaction chain sync functionality..."

testname="transactionChainSyncTest"
totaltests=0
passed=0
failure=0

# Use first two connected containers for testing
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
testPair="${containersLinkKeys[0]}"

if [[ -z "$testPair" ]]; then
    echo -e "${YELLOW}Warning: No container links defined, skipping transaction chain sync test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'transaction chain sync'"
    exit 0
fi

containerKeys=(${testPair//,/ })
sender="${containerKeys[0]}"
receiver="${containerKeys[1]}"

# Get addresses from containerAddresses (populated by hostnameTest/torAddressTest)
senderAddress="${containerAddresses[${sender}]}"
receiverAddress="${containerAddresses[${receiver}]}"

echo -e "\n[Transaction Chain Sync Test Setup]"
echo -e "\t   Sender: ${sender} (${senderAddress})"
echo -e "\t   Receiver: ${receiver} (${receiverAddress})"

if [[ -z "$senderAddress" ]] || [[ -z "$receiverAddress" ]]; then
    echo -e "${YELLOW}Warning: Container addresses not populated, skipping test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'transaction chain sync'"
    exit 0
fi

############################ Get Public Keys ############################

echo -e "\n[Getting container public keys]"

# Get receiver's public key and hash from sender's contact list
# Use the same pattern as balanceTest.sh - pass containerAddresses directly
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
    succesrate "${totaltests}" "${passed}" "${failure}" "'transaction chain sync'"
    exit 0
fi

############################ TEST 1: Verify sync method exists ############################

echo -e "\n[Test 1: Verify syncTransactionChain method exists]"
totaltests=$(( totaltests + 1 ))

methodExists=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$syncService = \$app->services->getSyncService();
    echo method_exists(\$syncService, 'syncTransactionChain') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$methodExists" == "EXISTS" ]]; then
    printf "\t   syncTransactionChain method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   syncTransactionChain method ${RED}FAILED${NC} (%s)\n" "${methodExists}"
    failure=$(( failure + 1 ))
fi

############################ TEST 2: Verify handleTransactionSyncRequest method exists ############################

echo -e "\n[Test 2: Verify handleTransactionSyncRequest method exists]"
totaltests=$(( totaltests + 1 ))

handlerExists=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$syncService = \$app->services->getSyncService();
    echo method_exists(\$syncService, 'handleTransactionSyncRequest') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$handlerExists" == "EXISTS" ]]; then
    printf "\t   handleTransactionSyncRequest method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   handleTransactionSyncRequest method ${RED}FAILED${NC} (%s)\n" "${handlerExists}"
    failure=$(( failure + 1 ))
fi

############################ TEST 3: Verify sync message payload builders exist ############################

echo -e "\n[Test 3: Verify sync message payload builders exist]"
totaltests=$(( totaltests + 1 ))

payloadBuildersExist=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    require_once('/etc/eiou/src/schemas/payloads/MessagePayload.php');

    \$app = Application::getInstance();
    \$user = \$app->services->getCurrentUser();
    \$utilityContainer = \$app->services->getUtilityContainer();
    \$messagePayload = new MessagePayload(\$user, \$utilityContainer);

    \$methods = [
        'buildTransactionSyncRequest',
        'buildTransactionSyncResponse',
        'buildTransactionSyncAcknowledgment',
        'buildTransactionSyncRejection'
    ];

    \$allExist = true;
    foreach (\$methods as \$method) {
        if (!method_exists(\$messagePayload, \$method)) {
            echo 'MISSING:' . \$method;
            \$allExist = false;
            break;
        }
    }
    if (\$allExist) echo 'ALL_EXIST';
" 2>/dev/null || echo "ERROR")

if [[ "$payloadBuildersExist" == "ALL_EXIST" ]]; then
    printf "\t   Sync payload builders ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Sync payload builders ${RED}FAILED${NC} (%s)\n" "${payloadBuildersExist}"
    failure=$(( failure + 1 ))
fi

############################ TEST 4: Inject fake transactions into receiver ############################

echo -e "\n[Test 4: Inject fake transactions into receiver to create chain mismatch]"
totaltests=$(( totaltests + 1 ))

# Generate unique transaction IDs for fake transactions
timestamp=$(date +%s%N)
fakeTxid1="fake-sync-test-txid-1-${timestamp}"
fakeTxid2="fake-sync-test-txid-2-${timestamp}"
fakeTxid3="fake-sync-test-txid-3-${timestamp}"

# Insert 3 fake completed transactions directly into receiver's database
# These represent transactions that sender doesn't know about
injectResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();

    \$senderPubkey = base64_decode('${senderPubkeyB64}');
    \$senderPubkeyHash = '${senderPubkeyHash}';
    \$senderAddress = '${senderAddress}';
    \$receiverPubkey = base64_decode('${receiverPubkeyB64}');
    \$receiverPubkeyHash = '${receiverPubkeyHash}';
    \$receiverAddress = '${receiverAddress}';

    \$fakeTxids = ['${fakeTxid1}', '${fakeTxid2}', '${fakeTxid3}'];
    \$previousTxid = null;
    \$success = true;

    try {
        foreach (\$fakeTxids as \$index => \$txid) {
            \$stmt = \$pdo->prepare('INSERT INTO transactions
                (tx_type, type, status, sender_address, sender_public_key, sender_public_key_hash,
                 receiver_address, receiver_public_key, receiver_public_key_hash,
                 amount, currency, txid, previous_txid, memo, description)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

            \$result = \$stmt->execute([
                'standard',
                'received',
                'completed',
                \$senderAddress,
                \$senderPubkey,
                \$senderPubkeyHash,
                \$receiverAddress,
                \$receiverPubkey,
                \$receiverPubkeyHash,
                10 + \$index,  // amounts: 10, 11, 12
                'USD',
                \$txid,
                \$previousTxid,
                'standard',
                'Fake transaction for sync test ' . (\$index + 1)
            ]);

            if (!\$result) {
                \$success = false;
                break;
            }
            \$previousTxid = \$txid;
        }

        if (\$success) {
            // Verify transactions were inserted
            \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE txid LIKE 'fake-sync-test-%'\")->fetchColumn();
            echo \$count == 3 ? 'INJECTED_3' : 'WRONG_COUNT:' . \$count;
        } else {
            echo 'INSERT_FAILED';
        }
    } catch (Exception \$e) {
        echo 'ERROR:' . \$e->getMessage();
    }
" 2>/dev/null || echo "ERROR")

if [[ "$injectResult" == "INJECTED_3" ]]; then
    printf "\t   Injected 3 fake transactions ${GREEN}PASSED${NC}\n"
    printf "\t   Fake txids: ${fakeTxid1:0:30}...\n"
    printf "\t               ${fakeTxid2:0:30}...\n"
    printf "\t               ${fakeTxid3:0:30}...\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Inject fake transactions ${RED}FAILED${NC} (%s)\n" "${injectResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 5: Verify previousTxid mismatch ############################

echo -e "\n[Test 5: Verify previousTxid mismatch exists between containers]"
totaltests=$(( totaltests + 1 ))

# Get sender's view of previous_txid (should be NULL or different)
senderPreviousTxid=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$txRepo = \$app->services->getTransactionRepository();
    \$senderPubkey = base64_decode('${senderPubkeyB64}');
    \$receiverPubkey = base64_decode('${receiverPubkeyB64}');
    \$prevTxid = \$txRepo->getPreviousTxid(\$senderPubkey, \$receiverPubkey);
    echo \$prevTxid ?: 'NULL';
" 2>/dev/null || echo "ERROR")

# Get receiver's view of previous_txid (should be our fake txid3)
receiverPreviousTxid=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$txRepo = \$app->services->getTransactionRepository();
    \$senderPubkey = base64_decode('${senderPubkeyB64}');
    \$receiverPubkey = base64_decode('${receiverPubkeyB64}');
    \$prevTxid = \$txRepo->getPreviousTxid(\$senderPubkey, \$receiverPubkey);
    echo \$prevTxid ?: 'NULL';
" 2>/dev/null || echo "ERROR")

echo -e "\t   Sender's previous_txid: ${senderPreviousTxid:0:40}..."
echo -e "\t   Receiver's previous_txid: ${receiverPreviousTxid:0:40}..."

if [[ "$senderPreviousTxid" != "$receiverPreviousTxid" ]]; then
    printf "\t   previousTxid mismatch confirmed ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   previousTxid mismatch ${RED}FAILED${NC} (both are same: %s)\n" "${senderPreviousTxid}"
    failure=$(( failure + 1 ))
fi

############################ TEST 6: Test sync request message flow ############################

echo -e "\n[Test 6: Test sync request message flow]"
totaltests=$(( totaltests + 1 ))

# Manually trigger a sync request to verify the flow works
# Note: Uses REL_FUNCTIONS instead of REL_APPLICATION because syncTransactionChain uses output()
syncFlowTest=$(docker exec ${sender} php -r "
    require_once('${REL_FUNCTIONS}');
    \$app = Application::getInstance();

    \$syncService = \$app->services->getSyncService();
    \$receiverPubkey = base64_decode('${receiverPubkeyB64}');

    // Attempt to sync transaction chain with receiver
    \$result = \$syncService->syncTransactionChain('${receiverAddress}', \$receiverPubkey);

    if (\$result['success']) {
        echo 'SYNC_SUCCESS:' . \$result['synced_count'];
    } else {
        echo 'SYNC_FAILED:' . (\$result['error'] ?? 'unknown');
    }
" 2>/dev/null || echo "ERROR")

if [[ "$syncFlowTest" == SYNC_SUCCESS:* ]]; then
    syncedCount=$(echo "$syncFlowTest" | cut -d':' -f2)
    printf "\t   Sync flow test ${GREEN}PASSED${NC} (synced %s transactions)\n" "${syncedCount}"
    passed=$(( passed + 1 ))
else
    printf "\t   Sync flow test ${RED}FAILED${NC} (%s)\n" "${syncFlowTest}"
    failure=$(( failure + 1 ))
fi

############################ TEST 7: Verify transactions were synced to sender ############################

echo -e "\n[Test 7: Verify fake transactions now exist on sender]"
totaltests=$(( totaltests + 1 ))

senderHasFakeTx=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();

    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE txid LIKE 'fake-sync-test-%'\")->fetchColumn();
    echo \$count >= 3 ? 'HAS_FAKE_TX:' . \$count : 'MISSING:' . \$count;
" 2>/dev/null || echo "ERROR")

if [[ "$senderHasFakeTx" == HAS_FAKE_TX:* ]]; then
    txCount=$(echo "$senderHasFakeTx" | cut -d':' -f2)
    printf "\t   Sender has synced transactions ${GREEN}PASSED${NC} (count: %s)\n" "${txCount}"
    passed=$(( passed + 1 ))
else
    printf "\t   Sender has synced transactions ${RED}FAILED${NC} (%s)\n" "${senderHasFakeTx}"
    failure=$(( failure + 1 ))
fi

############################ TEST 8: Verify latest transaction exists on sender ############################

echo -e "\n[Test 8: Verify receiver's latest transaction exists on sender after sync]"
totaltests=$(( totaltests + 1 ))

# Check if the receiver's latest fake txid (fakeTxid3) now exists on sender
senderHasLatestTx=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();

    // Check if the latest fake txid exists
    \$stmt = \$pdo->prepare('SELECT COUNT(*) FROM transactions WHERE txid = ?');
    \$stmt->execute(['${fakeTxid3}']);
    \$count = \$stmt->fetchColumn();
    echo \$count > 0 ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

echo -e "\t   Checking for latest receiver txid: ${fakeTxid3:0:40}..."

if [[ "$senderHasLatestTx" == "EXISTS" ]]; then
    printf "\t   Receiver's latest transaction now on sender ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Receiver's latest transaction on sender ${RED}FAILED${NC} (%s)\n" "${senderHasLatestTx}"
    failure=$(( failure + 1 ))
fi

############################ CLEANUP ############################

echo -e "\n[Cleanup: Removing fake transactions]"

cleanupSender=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE txid LIKE 'fake-sync-test-%'\");
    echo 'DELETED:' . \$deleted;
" 2>/dev/null || echo "ERROR")

cleanupReceiver=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE txid LIKE 'fake-sync-test-%'\");
    echo 'DELETED:' . \$deleted;
" 2>/dev/null || echo "ERROR")

echo -e "\t   Cleanup sender: ${cleanupSender}"
echo -e "\t   Cleanup receiver: ${cleanupReceiver}"

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'transaction chain sync'"
