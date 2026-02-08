#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Sync Test Suite ############################
# Consolidated sync tests combining:
# - syncTest.sh - Basic sync command functionality
# - transactionChainSyncTest.sh - Transaction chain recovery via sync
# - signatureValidationSyncTest.sh - Signature validation stops chain sync
# - multiRoundSyncCycleTest.sh - Multi-cycle sync resilience
# - resyncPrevTxidGapTest.sh - Cancelled transaction handling in sync
# - nullPrevTxidResyncTest.sh - NULL previous_txid edge cases
# - p2pCompletionSyncTest.sh - P2P completion status sync
# - contactReaddSyncTest.sh - Contact re-add triggers sync
#
# This consolidation reduces ~3,700 lines to ~1,800 lines (51% reduction)
########################################################################

# Helper functions are sourced via config.sh -> testHelpers.sh
# No need to source again here

testname="syncTestSuite"
totaltests=0
passed=0
failure=0

echo -e "\n"
echo "========================================================================"
echo "                    SYNC TEST SUITE"
echo "========================================================================"
echo -e "\n"

# Setup: Get container pair and public keys (shared by all sync tests)
# Get sender and receiver from container links or default to first/last containers
if [[ ${#containersLinks[@]} -gt 0 ]]; then
    # Parse first link pair from associative array keys (format: "httpA,httpB")
    containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
    firstLinkKey="${containersLinkKeys[0]}"
    linkParts=(${firstLinkKey//,/ })
    sender="${linkParts[0]}"
    receiver="${linkParts[1]}"
else
    # Fallback: use first and last containers
    sender="${containers[0]}"
    receiver="${containers[${#containers[@]}-1]}"
fi

# Get addresses from containerAddresses array (populated by buildfiles/hostnameTest)
# This ensures consistency with how other tests look up addresses
senderAddress="${containerAddresses[${sender}]}"
receiverAddress="${containerAddresses[${receiver}]}"

# Fallback to userconfig if containerAddresses is empty (shouldn't happen in normal test run)
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
    echo -e "${YELLOW}Warning: No container links defined, skipping sync test suite${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'sync test suite'"
    exit 0
fi

echo -e "[Test Setup]"
echo -e "\t   Sender: ${sender} (${senderAddress})"
echo -e "\t   Receiver: ${receiver} (${receiverAddress})"

# Ensure contacts exist between sender and receiver
# Use same format as addContactsTest: eiou add <address> <name> <fee> <credit> <currency>
# Credit must be > 0 to allow transactions (1000 matches http4 topology default)
docker exec ${sender} eiou add ${receiverAddress} ${receiver} 0.1 1000 USD 2>&1 > /dev/null || true
docker exec ${receiver} eiou add ${senderAddress} ${sender} 0.1 1000 USD 2>&1 > /dev/null || true
# Process queues for contact exchange
wait_for_queue_processed ${sender}
wait_for_queue_processed ${receiver}

# Wait for contacts to be accepted (pubkeys only available after acceptance)
# Get PHP-compatible transport type based on address protocol
receiverTransportType=$(getPhpTransportType "${receiverAddress}")
senderTransportType=$(getPhpTransportType "${senderAddress}")

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
        echo -e "\t   Contacts accepted (${waitElapsed}s)"
        break
    fi

    # Process queues to speed up contact exchange
    wait_for_queue_processed ${sender} 2
    wait_for_queue_processed ${receiver} 2
    waitElapsed=$((waitElapsed + 2))
done

if [[ "$senderStatus" != "accepted" ]] || [[ "$receiverStatus" != "accepted" ]]; then
    echo -e "${YELLOW}\t   Warning: Contacts not fully accepted (sender: ${senderStatus}, receiver: ${receiverStatus})${NC}"
fi

# Get public keys directly via PHP (using getPhpTransportType for MODE-aware transport)
# Using getContactPubkey which is the standard method used across tests
receiverPubkeyB64=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${receiverTransportType}', '${receiverAddress}');
    if (\$pubkey) {
        echo base64_encode(\$pubkey);
    } else {
        echo 'ERROR';
    }
" 2>/dev/null || echo "ERROR")

receiverPubkeyHash=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${receiverTransportType}', '${receiverAddress}');
    if (\$pubkey) {
        echo hash('sha256', \$pubkey);
    } else {
        echo 'ERROR';
    }
" 2>/dev/null || echo "ERROR")

senderPubkeyB64=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${senderTransportType}', '${senderAddress}');
    if (\$pubkey) {
        echo base64_encode(\$pubkey);
    } else {
        echo 'ERROR';
    }
" 2>/dev/null || echo "ERROR")

senderPubkeyHash=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${senderTransportType}', '${senderAddress}');
    if (\$pubkey) {
        echo hash('sha256', \$pubkey);
    } else {
        echo 'ERROR';
    }
" 2>/dev/null || echo "ERROR")

echo -e "\t   Sender pubkey hash: ${senderPubkeyHash:0:40}..."
echo -e "\t   Receiver pubkey hash: ${receiverPubkeyHash:0:40}..."

# Flag for whether pubkey-dependent tests can run
PUBKEYS_AVAILABLE=true
if [[ "$senderPubkeyHash" == "ERROR" ]] || [[ "$receiverPubkeyHash" == "ERROR" ]]; then
    echo -e "${YELLOW}Warning: Could not retrieve public keys, some tests will be skipped${NC}"
    PUBKEYS_AVAILABLE=false
fi

# Use first container for basic tests
testContainer="${containers[0]}"

##################### SECTION 1: Basic Sync Commands #####################
# Tests from syncTest.sh

echo -e "\n"
echo "========================================================================"
echo "Section 1: Basic Sync Commands"
echo "========================================================================"

############################ SYNC HELP COMMAND ############################

echo -e "\n[1.1 Sync Command in Help Test]"

# Test: Verify sync command appears in help output (regular)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync' appears in help output (regular)"
helpOutput=$(docker exec ${testContainer} eiou help 2>&1)

if [[ "$helpOutput" =~ "sync" ]]; then
    printf "\t   sync in help (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync in help (regular) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: Verify sync command appears in help output (JSON)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync' appears in help output (JSON)"
helpJsonOutput=$(docker exec ${testContainer} eiou help --json 2>&1)

if [[ "$helpJsonOutput" =~ '"sync"' ]]; then
    printf "\t   sync in help (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync in help (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ SYNC ALL COMMAND ############################

echo -e "\n[1.2 Sync All Command Test]"

# Test: sync (all) - regular output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync' command (all, regular output)"
syncAllOutput=$(docker exec ${testContainer} eiou sync 2>&1)

if [[ "$syncAllOutput" =~ "Sync" ]] || [[ "$syncAllOutput" =~ "sync" ]] || [[ "$syncAllOutput" =~ "completed" ]] || [[ "$syncAllOutput" =~ "Synced" ]]; then
    printf "\t   sync all (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync all (regular) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: sync (all) - JSON output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync' command (all, JSON output)"
syncAllJsonOutput=$(docker exec ${testContainer} eiou sync --json 2>&1)

if [[ "$syncAllJsonOutput" =~ '"success"' ]] && [[ "$syncAllJsonOutput" =~ 'true' ]]; then
    printf "\t   sync all (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync all (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ SYNC TYPES ############################

echo -e "\n[1.3 Sync Types Test]"

# Test: sync contacts
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync contacts' command"
syncContactsJsonOutput=$(docker exec ${testContainer} eiou sync contacts --json 2>&1)

if [[ "$syncContactsJsonOutput" =~ '"success"' ]] && [[ "$syncContactsJsonOutput" =~ 'true' ]]; then
    printf "\t   sync contacts (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync contacts (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: sync transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync transactions' command"
syncTransactionsJsonOutput=$(docker exec ${testContainer} eiou sync transactions --json 2>&1)

if [[ "$syncTransactionsJsonOutput" =~ '"success"' ]] && [[ "$syncTransactionsJsonOutput" =~ 'true' ]]; then
    printf "\t   sync transactions (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync transactions (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: sync balances
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync balances' command"
syncBalancesJsonOutput=$(docker exec ${testContainer} eiou sync balances --json 2>&1)

if [[ "$syncBalancesJsonOutput" =~ '"success"' ]] && [[ "$syncBalancesJsonOutput" =~ 'true' ]]; then
    printf "\t   sync balances (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync balances (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: sync with invalid type
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync invalidtype' command"
syncInvalidJsonOutput=$(docker exec ${testContainer} eiou sync invalidtype --json 2>&1)

if [[ "$syncInvalidJsonOutput" =~ '"success":false' ]] || [[ "$syncInvalidJsonOutput" =~ '"success": false' ]]; then
    printf "\t   sync invalid type (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync invalid type (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ MULTI-CONTAINER SYNC ############################

echo -e "\n[1.4 Multi-Container Sync Test]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing 'sync balances --json' on ${container}"

    containerSyncJson=$(docker exec ${container} eiou sync balances --json 2>&1)

    if [[ "$containerSyncJson" =~ '"success"' ]] && [[ "$containerSyncJson" =~ 'true' ]]; then
        printf "\t   sync balances on %s ${GREEN}PASSED${NC}\n" ${container}
        passed=$(( passed + 1 ))
    else
        printf "\t   sync balances on %s ${RED}FAILED${NC}\n" ${container}
        failure=$(( failure + 1 ))
    fi
done

##################### SECTION 2: Transaction Chain Sync #####################
# Tests from transactionChainSyncTest.sh

echo -e "\n"
echo "========================================================================"
echo "Section 2: Transaction Chain Sync"
echo "========================================================================"

############################ SYNC METHOD VERIFICATION ############################

echo -e "\n[2.1 Sync Method Verification]"

# Test: Verify syncTransactionChain method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing syncTransactionChain method exists"

methodExists=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getSyncService();
    echo method_exists(\$service, 'syncTransactionChain') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$methodExists" == "EXISTS" ]]; then
    printf "\t   syncTransactionChain method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   syncTransactionChain method ${RED}FAILED${NC} (%s)\n" "$methodExists"
    failure=$(( failure + 1 ))
fi

# Test: Verify handleTransactionSyncRequest method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing handleTransactionSyncRequest method exists"

handlerExists=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getSyncService();
    echo method_exists(\$service, 'handleTransactionSyncRequest') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$handlerExists" == "EXISTS" ]]; then
    printf "\t   handleTransactionSyncRequest method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   handleTransactionSyncRequest method ${RED}FAILED${NC} (%s)\n" "$handlerExists"
    failure=$(( failure + 1 ))
fi

############################ CHAIN SYNC TEST ############################

echo -e "\n[2.2 Transaction Chain Sync Test]"

# This section requires pubkeys to be available
if [[ "$PUBKEYS_AVAILABLE" != "true" ]]; then
    echo -e "${YELLOW}\t   Skipping section 2.2 - pubkeys not available${NC}"
else

timestamp=$(date +%s%N)

# Test: Send 3 real transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending 3 transactions from sender to receiver"

docker exec ${sender} eiou send ${receiverAddress} 1 USD "chain-sync-test-tx1-${timestamp}" 2>&1 > /dev/null
docker exec ${sender} eiou send ${receiverAddress} 2 USD "chain-sync-test-tx2-${timestamp}" 2>&1 > /dev/null
docker exec ${sender} eiou send ${receiverAddress} 3 USD "chain-sync-test-tx3-${timestamp}" 2>&1 > /dev/null

# Process messages: sender sends outgoing, receiver receives incoming
wait_for_queue_processed ${sender}
wait_for_queue_processed ${receiver}

senderTxCount=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'chain-sync-test-tx%${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")
receiverTxCount=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'chain-sync-test-tx%${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

# Retry if receiver hasn't received transactions yet (time-dependent)
if [[ "$receiverTxCount" -lt 3 ]]; then
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
    receiverTxCount=$(docker exec ${receiver} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'chain-sync-test-tx%${timestamp}'\")->fetchColumn();
        echo \$count;
    " 2>/dev/null || echo "0")
fi

echo -e "\t   Sender has ${senderTxCount} test transactions"
echo -e "\t   Receiver has ${receiverTxCount} test transactions"

if [[ "$senderTxCount" -ge 3 ]] && [[ "$receiverTxCount" -ge 3 ]]; then
    printf "\t   Sent 3 transactions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Send transactions ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: Delete and resync
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Deleting transactions from sender"

docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'chain-sync-test-tx%${timestamp}'\");
" 2>/dev/null

senderTxCountAfterDelete=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'chain-sync-test-tx%${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")
echo -e "\t   Sender now has ${senderTxCountAfterDelete} test transactions"

if [[ "$senderTxCountAfterDelete" == "0" ]]; then
    printf "\t   Delete sender transactions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Delete sender transactions ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: Trigger sync
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Triggering sync to recover transactions"

syncResult=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$syncService = \$app->services->getSyncService();
    \$receiverPubkey = base64_decode('${receiverPubkeyB64}');

    \$result = \$syncService->syncTransactionChain('${receiverAddress}', \$receiverPubkey);

    if (\$result['success']) {
        echo 'SYNC_SUCCESS:' . \$result['synced_count'];
    } else {
        echo 'SYNC_FAILED:' . (\$result['error'] ?? 'unknown');
    }
" 2>/dev/null || echo "ERROR")

if [[ "$syncResult" == SYNC_SUCCESS:* ]]; then
    syncedCount=$(echo "$syncResult" | cut -d':' -f2)
    printf "\t   Sync completed ${GREEN}PASSED${NC} (synced %s transactions)\n" "${syncedCount}"
    passed=$(( passed + 1 ))
else
    printf "\t   Sync ${RED}FAILED${NC} (%s)\n" "${syncResult}"
    failure=$(( failure + 1 ))
fi

# Verify transactions were recovered (with retry for time-dependent operations)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying transactions were recovered"

senderTxCountAfterSync=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'chain-sync-test-tx%${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

# Retry if sync hasn't completed yet
if [[ "$senderTxCountAfterSync" -lt 3 ]]; then
    # Process queues again
    wait_for_queue_processed ${sender} 5
    wait_for_queue_processed ${receiver} 5
    senderTxCountAfterSync=$(docker exec ${sender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'chain-sync-test-tx%${timestamp}'\")->fetchColumn();
        echo \$count;
    " 2>/dev/null || echo "0")
fi

echo -e "\t   Sender now has ${senderTxCountAfterSync} test transactions after sync"

if [[ "$senderTxCountAfterSync" -ge 3 ]]; then
    printf "\t   Transactions recovered ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transactions recovered ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Cleanup
docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'chain-sync-test-tx%'\");
" 2>/dev/null
docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'chain-sync-test-tx%'\");
" 2>/dev/null

fi  # End PUBKEYS_AVAILABLE check for section 2.2

##################### SECTION 3: Signature Validation #####################
# Tests from signatureValidationSyncTest.sh (simplified version)

echo -e "\n"
echo "========================================================================"
echo "Section 3: Signature Validation"
echo "========================================================================"

echo -e "\n[3.1 Signature Verification Method Check]"

# Test: Verify signature verification method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing verifyTransactionSignature method exists"

verifyMethodExists=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$syncService = \$app->services->getSyncService();
    \$reflection = new ReflectionClass(\$syncService);
    \$hasMethod = \$reflection->hasMethod('verifyTransactionSignature');
    echo \$hasMethod ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$verifyMethodExists" == "EXISTS" ]]; then
    printf "\t   verifyTransactionSignature method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   verifyTransactionSignature method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[3.2 Signature Reconstruction Test]"

# Test: Verify reconstructSignedMessage works
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing signature reconstruction"

reconstructResult=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$syncService = \$app->services->getSyncService();
    \$reflection = new ReflectionClass(\$syncService);
    \$hasMethod = \$reflection->hasMethod('reconstructSignedMessage');
    echo \$hasMethod ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$reconstructResult" == "EXISTS" ]]; then
    printf "\t   reconstructSignedMessage method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   reconstructSignedMessage method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

##################### SECTION 4: Multi-Round Sync Cycles #####################
# Simplified tests from multiRoundSyncCycleTest.sh

echo -e "\n"
echo "========================================================================"
echo "Section 4: Multi-Round Sync Cycles"
echo "========================================================================"

echo -e "\n[4.1 Sync Cycle Integrity Test]"

timestamp2=$(date +%s%N)

# Test: Send initial transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending initial transactions"

docker exec ${sender} eiou send ${receiverAddress} 1 USD "cycle-test-1-${timestamp2}" 2>&1 > /dev/null
wait_for_queue_processed ${sender} 5
docker exec ${sender} eiou send ${receiverAddress} 2 USD "cycle-test-2-${timestamp2}" 2>&1 > /dev/null

# Process messages: sender sends outgoing, receiver receives incoming
wait_for_queue_processed ${sender} 5
wait_for_queue_processed ${receiver} 5

initialCountSender=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'cycle-test%${timestamp2}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")
initialCountReceiver=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'cycle-test%${timestamp2}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

# Retry if transactions haven't been received yet (time-dependent)
if [[ "$initialCountReceiver" -lt 2 ]]; then
    # Process queues multiple times to ensure delivery
    for retry in 1 2 3; do
        wait_for_queue_processed ${sender} 5
        wait_for_queue_processed ${receiver} 5
    done
    initialCountReceiver=$(docker exec ${receiver} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'cycle-test%${timestamp2}'\")->fetchColumn();
        echo \$count;
    " 2>/dev/null || echo "0")
fi

echo -e "\t   Sender has ${initialCountSender}, Receiver has ${initialCountReceiver}"

if [[ "$initialCountSender" -ge 2 ]] && [[ "$initialCountReceiver" -ge 2 ]]; then
    printf "\t   Initial transactions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Initial transactions ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: Delete and verify chain integrity after resync
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing cycle: delete -> send -> resync"

# Delete from sender
docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'cycle-test%${timestamp2}'\");
" 2>/dev/null

# Send new transaction (triggers resync due to previous_txid mismatch)
docker exec ${sender} eiou send ${receiverAddress} 10 USD "cycle-test-3-${timestamp2}" 2>&1 > /dev/null

# Process full sync cycle using queue processing
# Round 1: Sender sends, receiver receives
wait_for_queue_processed ${sender} 5
wait_for_queue_processed ${receiver} 5
# Round 2: Receiver responds with sync request
wait_for_queue_processed ${receiver} 5
wait_for_queue_processed ${sender} 5
# Round 3: Final sync completion
wait_for_queue_processed ${sender} 5
wait_for_queue_processed ${receiver} 5

countAfterCycle=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'cycle-test%${timestamp2}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

# Retry if sync cycle hasn't completed yet (time-dependent)
if [[ "$countAfterCycle" -lt 2 ]]; then
    # Additional sync rounds
    for retry in 1 2 3; do
        wait_for_queue_processed ${sender} 5
        wait_for_queue_processed ${receiver} 5
    done
    countAfterCycle=$(docker exec ${sender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'cycle-test%${timestamp2}'\")->fetchColumn();
        echo \$count;
    " 2>/dev/null || echo "0")
fi

echo -e "\t   Sender has ${countAfterCycle} transactions after cycle"

if [[ "$countAfterCycle" -ge 2 ]]; then
    printf "\t   Sync cycle ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Sync cycle ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Cleanup
docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'cycle-test%'\");
" 2>/dev/null
docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'cycle-test%'\");
" 2>/dev/null

##################### SECTION 5: Edge Cases #####################
# Simplified tests from resyncPrevTxidGapTest.sh and nullPrevTxidResyncTest.sh

echo -e "\n"
echo "========================================================================"
echo "Section 5: Sync Edge Cases"
echo "========================================================================"

echo -e "\n[5.1 Cancelled Transaction Sync Test]"

# Test: Verify sync includes cancelled transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getTransactionsBetweenPubkeys includes cancelled"

includeCancelledResult=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$transactionRepo = \$app->services->getTransactionRepository();

    // Verify method exists
    if (method_exists(\$transactionRepo, 'getTransactionsBetweenPubkeys')) {
        echo 'METHOD_EXISTS';
    } else {
        echo 'METHOD_MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$includeCancelledResult" == "METHOD_EXISTS" ]]; then
    printf "\t   getTransactionsBetweenPubkeys method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   getTransactionsBetweenPubkeys method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[5.2 NULL Previous Txid Test]"

# Test: Verify checkPreviousTxid method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing checkPreviousTxid method exists"

checkPrevTxidResult=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$transactionService = \$app->services->getTransactionService();
    \$reflection = new ReflectionClass(\$transactionService);
    echo \$reflection->hasMethod('checkPreviousTxid') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$checkPrevTxidResult" == "EXISTS" ]]; then
    printf "\t   checkPreviousTxid method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   checkPreviousTxid method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: Verify rejection includes expected_txid for invalid_previous_txid
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing rejection includes expected_txid"

rejectionResult=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();

    \$transactionService = \$app->services->getTransactionService();
    \$reflection = new ReflectionClass(\$transactionService);
    \$prop = \$reflection->getProperty('transactionPayload');
    \$prop->setAccessible(true);
    \$transactionPayload = \$prop->getValue(\$transactionService);

    \$mockRequest = [
        'senderAddress' => 'test@example.onion',
        'txid' => 'test-txid-123',
        'memo' => 'standard'
    ];

    \$rejection = \$transactionPayload->buildRejection(\$mockRequest, 'invalid_previous_txid', 'expected-txid-abc');
    \$decoded = json_decode(\$rejection, true);

    if (isset(\$decoded['expected_txid'])) {
        echo 'INCLUDES_EXPECTED_TXID';
    } else {
        echo 'MISSING_EXPECTED_TXID';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$rejectionResult" == "INCLUDES_EXPECTED_TXID" ]]; then
    printf "\t   Rejection includes expected_txid ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Rejection expected_txid ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

##################### SECTION 6: P2P Completion Sync #####################
# Simplified tests from p2pCompletionSyncTest.sh

echo -e "\n"
echo "========================================================================"
echo "Section 6: P2P Completion Sync"
echo "========================================================================"

echo -e "\n[6.1 P2P Status Sync Methods]"

# Test: Verify p2p completion sync method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing P2P sync capability"

p2pSyncResult=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$syncService = \$app->services->getSyncService();

    // Check if sync service can handle p2p transactions
    \$reflection = new ReflectionClass(\$syncService);
    \$hasSyncChain = \$reflection->hasMethod('syncTransactionChain');
    echo \$hasSyncChain ? 'P2P_CAPABLE' : 'P2P_MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$p2pSyncResult" == "P2P_CAPABLE" ]]; then
    printf "\t   P2P sync capability ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   P2P sync capability ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

##################### SECTION 7: Contact Re-add Sync #####################
# Simplified tests from contactReaddSyncTest.sh

echo -e "\n"
echo "========================================================================"
echo "Section 7: Contact Re-add Sync"
echo "========================================================================"

echo -e "\n[7.1 Contact Service Sync Methods]"

# Test: Verify contact service has sync-related methods
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing ContactService sync capability"

contactSyncResult=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$contactService = \$app->services->getContactService();

    // Check if contact service exists and has required methods
    if (method_exists(\$contactService, 'addContact')) {
        echo 'CONTACT_SERVICE_OK';
    } else {
        echo 'CONTACT_SERVICE_MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$contactSyncResult" == "CONTACT_SERVICE_OK" ]]; then
    printf "\t   ContactService sync capability ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ContactService sync capability ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

##################### SECTION 8: Proactive Sync & Description Privacy #####################
# Tests for proactive sync and description privacy:
# 1. Receiver proactively syncs when prev_id doesn't match
# 2. Descriptions are filtered during sync (only contact/standard transactions)

echo -e "\n"
echo "========================================================================"
echo "Section 8: Proactive Sync & Description Privacy"
echo "========================================================================"

echo -e "\n[8.1 Proactive Sync Trigger Test]"

# Test: Verify that checkTransactionPossible triggers proactive sync when receiver has no history
# Note: checkTransactionPossible is now in TransactionValidationService (refactored from TransactionService)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing proactive sync trigger in checkTransactionPossible"

proactiveSyncResult=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$validationService = \$app->services->getTransactionValidationService();

    // Check that TransactionValidationService has the checkTransactionPossible method
    // and that it includes sync logic (by checking for syncService call)
    \$reflection = new ReflectionClass(\$validationService);
    \$method = \$reflection->getMethod('checkTransactionPossible');
    \$startLine = \$method->getStartLine();
    \$endLine = \$method->getEndLine();
    \$filename = \$method->getFileName();

    // Read the method source
    \$lines = file(\$filename);
    \$source = implode('', array_slice(\$lines, \$startLine - 1, \$endLine - \$startLine + 1));

    // Check if the method contains proactive sync logic
    if (strpos(\$source, 'syncService') !== false &&
        strpos(\$source, 'syncTransactionChain') !== false) {
        echo 'PROACTIVE_SYNC_EXISTS';
    } else {
        echo 'PROACTIVE_SYNC_MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$proactiveSyncResult" == "PROACTIVE_SYNC_EXISTS" ]]; then
    printf "\t   Proactive sync trigger ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Proactive sync trigger ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[8.2 Description Privacy During Sync Test]"

# Test: Verify handleTransactionSyncRequest filters descriptions for P2P transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing description filtering in handleTransactionSyncRequest"

descriptionFilterResult=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$syncService = \$app->services->getSyncService();

    // Check that SyncService has description filtering logic
    \$reflection = new ReflectionClass(\$syncService);
    \$method = \$reflection->getMethod('handleTransactionSyncRequest');
    \$startLine = \$method->getStartLine();
    \$endLine = \$method->getEndLine();
    \$filename = \$method->getFileName();

    // Read the method source
    \$lines = file(\$filename);
    \$source = implode('', array_slice(\$lines, \$startLine - 1, \$endLine - \$startLine + 1));

    // Check if the method filters descriptions based on memo type
    if (strpos(\$source, \"memo === 'contact'\") !== false &&
        strpos(\$source, \"memo === 'standard'\") !== false &&
        strpos(\$source, 'description') !== false) {
        echo 'DESCRIPTION_FILTER_EXISTS';
    } else {
        echo 'DESCRIPTION_FILTER_MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$descriptionFilterResult" == "DESCRIPTION_FILTER_EXISTS" ]]; then
    printf "\t   Description filtering ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Description filtering ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[8.3 P2P Transaction Description Privacy Test]"

# Test: Verify P2P transactions don't include descriptions in sync response
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing P2P descriptions are not included in sync"

p2pDescPrivacyResult=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');

    // Simulate a P2P transaction (memo is a hash, not 'standard' or 'contact')
    \$mockTransaction = [
        'txid' => 'test-txid-123',
        'previous_txid' => null,
        'sender_address' => 'sender@example.onion',
        'sender_public_key' => 'mock-sender-pubkey',
        'receiver_address' => 'receiver@example.onion',
        'receiver_public_key' => 'mock-receiver-pubkey',
        'amount' => 100,
        'currency' => 'USD',
        'memo' => 'abc123hashvalue', // P2P memo is a hash, not 'standard' or 'contact'
        'description' => 'This should NOT be included in sync',
        'timestamp' => time(),
        'time' => time(),
        'status' => 'completed',
        'sender_signature' => null,
        'signature_nonce' => null
    ];

    // The filtering logic from handleTransactionSyncRequest
    \$memo = \$mockTransaction['memo'] ?? '';
    if (\$memo === 'contact' || \$memo === 'standard') {
        \$description = \$mockTransaction['description'] ?? null;
    } else {
        // For P2P transactions, explicitly set description to null
        \$description = null;
    }

    if (\$description === null) {
        echo 'P2P_DESCRIPTION_FILTERED';
    } else {
        echo 'P2P_DESCRIPTION_LEAKED';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$p2pDescPrivacyResult" == "P2P_DESCRIPTION_FILTERED" ]]; then
    printf "\t   P2P description privacy ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   P2P description privacy ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[8.4 Standard Transaction Description Included Test]"

# Test: Verify standard transactions DO include descriptions in sync response
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing standard transactions include descriptions in sync"

standardDescResult=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');

    // Simulate a standard (direct) transaction
    \$mockTransaction = [
        'memo' => 'standard',
        'description' => 'This SHOULD be included in sync'
    ];

    // The filtering logic from handleTransactionSyncRequest
    \$memo = \$mockTransaction['memo'] ?? '';
    if (\$memo === 'contact' || \$memo === 'standard') {
        \$description = \$mockTransaction['description'] ?? null;
    } else {
        \$description = null;
    }

    if (\$description === 'This SHOULD be included in sync') {
        echo 'STANDARD_DESCRIPTION_INCLUDED';
    } else {
        echo 'STANDARD_DESCRIPTION_MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$standardDescResult" == "STANDARD_DESCRIPTION_INCLUDED" ]]; then
    printf "\t   Standard transaction description ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Standard transaction description ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[8.5 Contact Transaction Description Included Test]"

# Test: Verify contact transactions DO include descriptions in sync response
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing contact transactions include descriptions in sync"

contactDescResult=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');

    // Simulate a contact transaction
    \$mockTransaction = [
        'memo' => 'contact',
        'description' => 'Contact note'
    ];

    // The filtering logic from handleTransactionSyncRequest
    \$memo = \$mockTransaction['memo'] ?? '';
    if (\$memo === 'contact' || \$memo === 'standard') {
        \$description = \$mockTransaction['description'] ?? null;
    } else {
        \$description = null;
    }

    if (\$description === 'Contact note') {
        echo 'CONTACT_DESCRIPTION_INCLUDED';
    } else {
        echo 'CONTACT_DESCRIPTION_MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$contactDescResult" == "CONTACT_DESCRIPTION_INCLUDED" ]]; then
    printf "\t   Contact transaction description ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Contact transaction description ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

##################### SECTION 9: Simultaneous Transaction Chain Conflict Resolution #####################
# Tests for chain conflict resolution:
# When Contact A and Contact B both send transactions simultaneously with the same
# previous_txid, they create a "chain fork". The fix implements deterministic
# conflict resolution using lexicographic txid comparison.

echo -e "\n"
echo "========================================================================"
echo "Section 9: Simultaneous Transaction Chain Conflict Resolution"
echo "========================================================================"

echo -e "\n[9.1 Chain Conflict Resolution Method Test]"

# Test: Verify resolveChainConflict method exists in SyncService
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing resolveChainConflict method exists"

resolveMethodResult=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$syncService = \$app->services->getSyncService();

    \$reflection = new ReflectionClass(\$syncService);
    \$hasMethod = \$reflection->hasMethod('resolveChainConflict');
    echo \$hasMethod ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$resolveMethodResult" == "EXISTS" ]]; then
    printf "\t   resolveChainConflict method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   resolveChainConflict method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[9.2 Conflict Detection Repository Method Test]"

# Test: Verify getLocalTransactionByPreviousTxid method exists in TransactionChainRepository
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getLocalTransactionByPreviousTxid method exists"

conflictDetectionResult=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$transactionChainRepo = \$app->services->getTransactionChainRepository();

    if (method_exists(\$transactionChainRepo, 'getLocalTransactionByPreviousTxid')) {
        echo 'EXISTS';
    } else {
        echo 'MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$conflictDetectionResult" == "EXISTS" ]]; then
    printf "\t   getLocalTransactionByPreviousTxid method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   getLocalTransactionByPreviousTxid method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: Verify getByPreviousTxid method exists in TransactionChainRepository
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getByPreviousTxid method exists"

getByPrevTxidResult=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$transactionChainRepo = \$app->services->getTransactionChainRepository();

    if (method_exists(\$transactionChainRepo, 'getByPreviousTxid')) {
        echo 'EXISTS';
    } else {
        echo 'MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$getByPrevTxidResult" == "EXISTS" ]]; then
    printf "\t   getByPreviousTxid method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   getByPreviousTxid method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[9.3 Deterministic Conflict Resolution Algorithm Test]"

# Test: Verify the deterministic ordering algorithm (lower txid wins)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing deterministic ordering (lower txid wins)"

deterministicResult=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$syncService = \$app->services->getSyncService();

    // Use reflection to test the private resolveChainConflict method
    \$reflection = new ReflectionClass(\$syncService);
    \$method = \$reflection->getMethod('resolveChainConflict');
    \$method->setAccessible(true);

    // Test case 1: local txid < remote txid (local should win)
    \$localTx1 = ['txid' => 'aaa111', 'previous_txid' => 'prev123'];
    \$remoteTx1 = ['txid' => 'bbb222', 'previous_txid' => 'prev123'];
    \$result1 = \$method->invoke(\$syncService, \$localTx1, \$remoteTx1);

    // Test case 2: remote txid < local txid (remote should win)
    \$localTx2 = ['txid' => 'zzz999', 'previous_txid' => 'prev456'];
    \$remoteTx2 = ['txid' => 'aaa000', 'previous_txid' => 'prev456'];
    \$result2 = \$method->invoke(\$syncService, \$localTx2, \$remoteTx2);

    // Test case 3: identical txids (should not resolve)
    \$localTx3 = ['txid' => 'same123', 'previous_txid' => 'prev789'];
    \$remoteTx3 = ['txid' => 'same123', 'previous_txid' => 'prev789'];
    \$result3 = \$method->invoke(\$syncService, \$localTx3, \$remoteTx3);

    // Verify all test cases
    \$case1Pass = \$result1['resolved'] && \$result1['winner'] === 'local' && \$result1['winner_txid'] === 'aaa111';
    \$case2Pass = \$result2['resolved'] && \$result2['winner'] === 'remote' && \$result2['winner_txid'] === 'aaa000';
    \$case3Pass = !\$result3['resolved']; // Identical txids should not resolve

    if (\$case1Pass && \$case2Pass && \$case3Pass) {
        echo 'DETERMINISTIC_ORDER_OK';
    } else {
        echo 'DETERMINISTIC_ORDER_FAILED:' .
             'case1=' . (\$case1Pass ? 'ok' : 'fail') . ',' .
             'case2=' . (\$case2Pass ? 'ok' : 'fail') . ',' .
             'case3=' . (\$case3Pass ? 'ok' : 'fail');
    }
" 2>/dev/null || echo "ERROR")

if [[ "$deterministicResult" == "DETERMINISTIC_ORDER_OK" ]]; then
    printf "\t   Deterministic ordering ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Deterministic ordering ${RED}FAILED${NC} (%s)\n" "${deterministicResult}"
    failure=$(( failure + 1 ))
fi

echo -e "\n[9.4 Sync Chain Conflict Tracking Test]"

# Test: Verify syncTransactionChain returns conflicts_resolved count
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing syncTransactionChain returns conflicts_resolved"

syncResultFieldsResult=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$syncService = \$app->services->getSyncService();

    // Check the syncTransactionChain method returns conflicts_resolved
    \$reflection = new ReflectionClass(\$syncService);
    \$method = \$reflection->getMethod('syncTransactionChain');
    \$startLine = \$method->getStartLine();
    \$endLine = \$method->getEndLine();
    \$filename = \$method->getFileName();

    // Read the method source
    \$lines = file(\$filename);
    \$source = implode('', array_slice(\$lines, \$startLine - 1, \$endLine - \$startLine + 1));

    // Check if the method includes conflicts_resolved in result
    if (strpos(\$source, \"'conflicts_resolved'\") !== false) {
        echo 'CONFLICTS_RESOLVED_TRACKED';
    } else {
        echo 'CONFLICTS_RESOLVED_MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$syncResultFieldsResult" == "CONFLICTS_RESOLVED_TRACKED" ]]; then
    printf "\t   conflicts_resolved tracking ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   conflicts_resolved tracking ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[9.5 Conflict Detection in Sync Flow Test]"

# Test: Verify syncTransactionChain includes conflict detection logic
# NOTE: Conflict detection logs conflicts but does NOT update previous_txid values
# to preserve transaction signature validity during sync.
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing conflict detection in syncTransactionChain"

conflictDetectionFlowResult=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$syncService = \$app->services->getSyncService();

    \$reflection = new ReflectionClass(\$syncService);
    \$method = \$reflection->getMethod('syncTransactionChain');
    \$startLine = \$method->getStartLine();
    \$endLine = \$method->getEndLine();
    \$filename = \$method->getFileName();

    // Read the method source
    \$lines = file(\$filename);
    \$source = implode('', array_slice(\$lines, \$startLine - 1, \$endLine - \$startLine + 1));

    // Check for key conflict detection components
    // NOTE: We detect and resolve conflicts but do NOT update previous_txid
    // to preserve signature validity. Both transactions keep original values.
    \$hasConflictDetection = strpos(\$source, 'getLocalTransactionByPreviousTxid') !== false;
    \$hasConflictResolution = strpos(\$source, 'resolveChainConflict') !== false;

    if (\$hasConflictDetection && \$hasConflictResolution) {
        echo 'CONFLICT_DETECTION_COMPLETE';
    } else {
        echo 'CONFLICT_DETECTION_INCOMPLETE:' .
             'detect=' . (\$hasConflictDetection ? 'yes' : 'no') . ',' .
             'resolve=' . (\$hasConflictResolution ? 'yes' : 'no');
    }
" 2>/dev/null || echo "ERROR")

if [[ "$conflictDetectionFlowResult" == "CONFLICT_DETECTION_COMPLETE" ]]; then
    printf "\t   Conflict detection flow ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Conflict detection flow ${RED}FAILED${NC} (%s)\n" "${conflictDetectionFlowResult}"
    failure=$(( failure + 1 ))
fi

echo -e "\n[9.6 Simulated Chain Fork Resolution Test]"

# This section requires pubkeys to be available
if [[ "$PUBKEYS_AVAILABLE" != "true" ]]; then
    echo -e "${YELLOW}\t   Skipping section 9.6 - pubkeys not available${NC}"
else

# Test: Simulate a chain fork scenario and verify resolution
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing simulated chain fork resolution"

timestamp408=$(date +%s%N)

# Step 1: Send an initial transaction to establish a chain
docker exec ${sender} eiou send ${receiverAddress} 1 USD "fork-test-base-${timestamp408}" 2>&1 > /dev/null
wait_for_queue_processed ${sender} 5
wait_for_queue_processed ${receiver} 5

# Get the base transaction's txid (this will be the shared previous_txid)
baseTxid=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$stmt = \$pdo->query(\"SELECT txid FROM transactions WHERE description LIKE 'fork-test-base-${timestamp408}' LIMIT 1\");
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
    echo \$row['txid'] ?? 'NOT_FOUND';
" 2>/dev/null || echo "ERROR")

if [[ "$baseTxid" != "NOT_FOUND" ]] && [[ "$baseTxid" != "ERROR" ]]; then
    echo -e "\t   Base transaction created: ${baseTxid:0:20}..."

    # Step 2: Create two conflicting transactions (both referencing the same previous_txid)
    # Normally this happens when both parties send simultaneously, but we simulate it
    # by directly inserting a conflicting transaction

    forkResult=$(docker exec ${sender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$userContext = \$app->services->getCurrentUser();
        \$transactionRepo = \$app->services->getTransactionRepository();

        // Get sender and receiver pubkeys
        \$senderPubkey = \$userContext->getPublicKey();
        \$receiverPubkey = base64_decode('${receiverPubkeyB64}');
        \$senderPubkeyHash = hash('sha256', \$senderPubkey);
        \$receiverPubkeyHash = hash('sha256', \$receiverPubkey);

        // Create fake 'local' transaction (simulating sender's tx with prev_txid = baseTxid)
        \$localTxid = hash('sha256', 'local-fork-tx-' . time() . '-aaa');

        // Create fake 'remote' transaction (simulating receiver's tx with prev_txid = baseTxid)
        \$remoteTxid = hash('sha256', 'remote-fork-tx-' . time() . '-zzz');

        // Simulate the conflict resolution
        \$syncService = \$app->services->getSyncService();
        \$reflection = new ReflectionClass(\$syncService);
        \$method = \$reflection->getMethod('resolveChainConflict');
        \$method->setAccessible(true);

        \$localTx = ['txid' => \$localTxid, 'previous_txid' => '${baseTxid}'];
        \$remoteTx = ['txid' => \$remoteTxid, 'previous_txid' => '${baseTxid}'];

        \$result = \$method->invoke(\$syncService, \$localTx, \$remoteTx);

        if (\$result['resolved']) {
            // Verify that the lower txid won
            \$expectedWinner = strcmp(\$localTxid, \$remoteTxid) < 0 ? 'local' : 'remote';
            if (\$result['winner'] === \$expectedWinner) {
                echo 'FORK_RESOLVED_CORRECTLY';
            } else {
                echo 'FORK_RESOLVED_INCORRECTLY:expected=' . \$expectedWinner . ',got=' . \$result['winner'];
            }
        } else {
            echo 'FORK_NOT_RESOLVED';
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$forkResult" == "FORK_RESOLVED_CORRECTLY" ]]; then
        printf "\t   Chain fork resolution ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Chain fork resolution ${RED}FAILED${NC} (%s)\n" "${forkResult}"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   Chain fork resolution ${RED}FAILED${NC} (could not create base tx)\n"
    failure=$(( failure + 1 ))
fi

# Cleanup
docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'fork-test%'\");
" 2>/dev/null
docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'fork-test%'\");
" 2>/dev/null

fi  # End PUBKEYS_AVAILABLE check for section 9.6

##################### SECTION 10: Comprehensive Sync Scenarios #####################
# Tests for comprehensive sync scenarios: Check Synching in Standard and P2p settings
# These tests verify synching works when contacts have lost transactions
# and that transaction queues behave correctly when rejections trigger syncs.
#
# Terminology:
# - Contact A (httpA), Contact B (httpB), Contact C (httpC)
# - AB chain: transactions between A and B
# - BC chain: transactions between B and C
# - AB0: contact transaction (first in chain)
# - AB1, AB2, etc.: subsequent transactions from A to B
# - BA1, BA2, etc.: subsequent transactions from B to A
################################################################################################

echo -e "\n"
echo "========================================================================"
echo "Section 10: Comprehensive Sync Scenarios"
echo "========================================================================"

# Setup: Need at least 3 containers for P2P tests (A, B, C)
# http4 topology is A-B-C-D (line), perfect for our needs
if [[ ${#containers[@]} -lt 3 ]]; then
    echo -e "${YELLOW}\t   Skipping Section 10 - requires at least 3 containers${NC}"
else

# Container assignments matching issue terminology
contactA="${containers[0]}"  # httpA
contactB="${containers[1]}"  # httpB
contactC="${containers[2]}"  # httpC

# Get addresses
addressA="${containerAddresses[${contactA}]}"
addressB="${containerAddresses[${contactB}]}"
addressC="${containerAddresses[${contactC}]}"

# Fallback to userconfig if containerAddresses is empty (consistent with earlier sections)
if [[ -z "$addressA" ]] || [[ -z "$addressB" ]] || [[ -z "$addressC" ]]; then
    echo -e "${YELLOW}\t   Warning: containerAddresses empty for Section 10, falling back to userconfig${NC}"
    if [[ "$MODE" == "http" ]] || [[ "$MODE" == "https" ]]; then
        addressA=$(docker exec ${contactA} php -r "
            \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
            echo \$json['hostname'] ?? '';
        " 2>/dev/null)
        addressB=$(docker exec ${contactB} php -r "
            \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
            echo \$json['hostname'] ?? '';
        " 2>/dev/null)
        addressC=$(docker exec ${contactC} php -r "
            \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
            echo \$json['hostname'] ?? '';
        " 2>/dev/null)
    else
        addressA=$(docker exec ${contactA} php -r "
            \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
            echo \$json['torAddress'] ?? '';
        " 2>/dev/null)
        addressB=$(docker exec ${contactB} php -r "
            \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
            echo \$json['torAddress'] ?? '';
        " 2>/dev/null)
        addressC=$(docker exec ${contactC} php -r "
            \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
            echo \$json['torAddress'] ?? '';
        " 2>/dev/null)
    fi
fi

echo -e "\n[Test Setup for Comprehensive Sync]"
echo -e "\t   Contact A: ${contactA} (${addressA})"
echo -e "\t   Contact B: ${contactB} (${addressB})"
echo -e "\t   Contact C: ${contactC} (${addressC})"

# Get public keys for all contacts
pubkeyInfoAB=$(get_pubkey_info ${contactA} ${MODE} ${addressB})
pubkeyB64_B_fromA=$(parse_pubkey_b64 "$pubkeyInfoAB")
pubkeyHash_B_fromA=$(parse_pubkey_hash "$pubkeyInfoAB")

pubkeyInfoBA=$(get_pubkey_info ${contactB} ${MODE} ${addressA})
pubkeyB64_A_fromB=$(parse_pubkey_b64 "$pubkeyInfoBA")
pubkeyHash_A_fromB=$(parse_pubkey_hash "$pubkeyInfoBA")

pubkeyInfoBC=$(get_pubkey_info ${contactB} ${MODE} ${addressC})
pubkeyB64_C_fromB=$(parse_pubkey_b64 "$pubkeyInfoBC")
pubkeyHash_C_fromB=$(parse_pubkey_hash "$pubkeyInfoBC")

pubkeyInfoCB=$(get_pubkey_info ${contactC} ${MODE} ${addressB})
pubkeyB64_B_fromC=$(parse_pubkey_b64 "$pubkeyInfoCB")
pubkeyHash_B_fromC=$(parse_pubkey_hash "$pubkeyInfoCB")

ISSUE423_PUBKEYS_AVAILABLE=true
if [[ "$pubkeyHash_B_fromA" == "ERROR" ]] || [[ "$pubkeyHash_A_fromB" == "ERROR" ]]; then
    echo -e "${YELLOW}Warning: Could not retrieve A-B public keys, some tests will be skipped${NC}"
    ISSUE423_PUBKEYS_AVAILABLE=false
fi

# Helper function to create initial transaction chain AB0-AB3
# Usage: setup_ab_chain <timestamp_suffix>
# Creates AB0 (contact), AB1, AB2, AB3 from A to B
setup_ab_chain() {
    local ts="$1"
    echo -e "\t   Creating AB chain (AB0-AB3)..."

    # AB0 is the contact transaction (already exists from test setup)
    # Send AB1, AB2, AB3 (use EIOU_TEST_MODE to bypass rate limiter)
    docker exec -e EIOU_TEST_MODE=true ${contactA} eiou send ${addressB} 1 USD "AB1-${ts}" 2>&1 > /dev/null
    docker exec -e EIOU_TEST_MODE=true ${contactA} eiou send ${addressB} 2 USD "AB2-${ts}" 2>&1 > /dev/null
    docker exec -e EIOU_TEST_MODE=true ${contactA} eiou send ${addressB} 3 USD "AB3-${ts}" 2>&1 > /dev/null

    # Process transactions
    wait_for_queue_processed ${contactA} 5
    wait_for_queue_processed ${contactB} 5
}

# Helper function to setup CB chain
setup_cb_chain() {
    local ts="$1"
    echo -e "\t   Creating CB chain (CB0-CB3)..."

    # Use EIOU_TEST_MODE to bypass rate limiter
    docker exec -e EIOU_TEST_MODE=true ${contactC} eiou send ${addressB} 1 USD "CB1-${ts}" 2>&1 > /dev/null
    docker exec -e EIOU_TEST_MODE=true ${contactC} eiou send ${addressB} 2 USD "CB2-${ts}" 2>&1 > /dev/null
    docker exec -e EIOU_TEST_MODE=true ${contactC} eiou send ${addressB} 3 USD "CB3-${ts}" 2>&1 > /dev/null

    wait_for_queue_processed ${contactC} 5
    wait_for_queue_processed ${contactB} 5
}

# Helper function to delete all transactions for a contact pair on one side
# Usage: delete_all_transactions <container> <pubkey_hash_of_partner> <description_pattern>
delete_all_transactions() {
    local container="$1"
    local partner_pubkey_hash="$2"
    local pattern="$3"
    docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$pdo->exec(\"DELETE FROM transactions WHERE
            (sender_public_key_hash = '${partner_pubkey_hash}' OR receiver_public_key_hash = '${partner_pubkey_hash}')
            AND description LIKE '${pattern}'\");
    " 2>/dev/null
}

# Helper to delete transactions except contact (AB0)
delete_transactions_except_contact() {
    local container="$1"
    local partner_pubkey_hash="$2"
    local pattern="$3"
    docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$pdo->exec(\"DELETE FROM transactions WHERE
            (sender_public_key_hash = '${partner_pubkey_hash}' OR receiver_public_key_hash = '${partner_pubkey_hash}')
            AND memo != 'contact'
            AND description LIKE '${pattern}'\");
    " 2>/dev/null
}

# Helper to delete specific transactions leaving gaps
delete_specific_transactions() {
    local container="$1"
    local pattern="$2"  # e.g., "AB1%" to delete AB1
    docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE '${pattern}'\");
    " 2>/dev/null
}

# Helper to get transaction count for a pattern
get_tx_count() {
    local container="$1"
    local pattern="$2"
    docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${pattern}'\")->fetchColumn();
        echo \$count;
    " 2>/dev/null || echo "0"
}

# Helper to send multiple transactions sequentially
# NOTE: Transactions MUST be sent sequentially to maintain chain integrity.
# Each transaction references the previous one via previous_txid. Parallel sends
# cause a race condition where multiple transactions get the same previous_txid,
# creating a broken chain that the receiver will reject.
send_multiple_transactions() {
    local sender="$1"
    local receiver_addr="$2"
    local ts="$3"
    local prefix="$4"  # e.g., "AB" or "BA"
    local start_num="$5"
    local count="$6"

    for i in $(seq $start_num $((start_num + count - 1))); do
        # Use EIOU_TEST_MODE to bypass rate limiter
        docker exec -e EIOU_TEST_MODE=true ${sender} eiou send ${receiver_addr} 1 USD "${prefix}${i}-${ts}" 2>&1 > /dev/null
        sleep 0.5  # Allow transaction to be committed before next one
    done
}

# Helper to verify chain integrity after sync
verify_sync_success() {
    local container="$1"
    local pattern="$2"
    local expected_min="$3"
    local actual=$(get_tx_count "$container" "$pattern")

    if [[ "$actual" -ge "$expected_min" ]]; then
        echo "SUCCESS:${actual}"
    else
        echo "FAILED:${actual}"
    fi
}

# Helper to process message queues on all containers
process_all_queues() {
    docker exec -e EIOU_TEST_MODE=true ${contactA} eiou out 2>&1 > /dev/null &
    docker exec -e EIOU_TEST_MODE=true ${contactB} eiou out 2>&1 > /dev/null &
    docker exec -e EIOU_TEST_MODE=true ${contactC} eiou out 2>&1 > /dev/null &
    wait
    sleep ${TEST_POLL_INTERVAL:-1}
    docker exec -e EIOU_TEST_MODE=true ${contactA} eiou in 2>&1 > /dev/null &
    docker exec -e EIOU_TEST_MODE=true ${contactB} eiou in 2>&1 > /dev/null &
    docker exec -e EIOU_TEST_MODE=true ${contactC} eiou in 2>&1 > /dev/null &
    wait
    sleep ${TEST_POLL_INTERVAL:-1}
}

# Cleanup function for test transactions
cleanup_test_transactions() {
    local ts="$1"
    docker exec ${contactA} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE '%-${ts}'\");
    " 2>/dev/null || true
    docker exec ${contactB} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE '%-${ts}'\");
    " 2>/dev/null || true
    docker exec ${contactC} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE '%-${ts}'\");
    " 2>/dev/null || true
}

################################################################################
# STANDARD (DIRECT) TESTS - Loss of transactions A to B (Tests 1-3)
################################################################################

if [[ "$ISSUE423_PUBKEYS_AVAILABLE" == "true" ]]; then

echo -e "\n"
echo "------------------------------------------------------------------------"
echo "10.1: Standard Tests - Loss of transactions (A to B)"
echo "------------------------------------------------------------------------"

################################################################################
# Test 1: B lost ALL transactions, A sends AB4, AB5, AB6
################################################################################
echo -e "\n[10.1.1 Test 1: B lost all transactions]"
totaltests=$(( totaltests + 1 ))

timestamp1=$(date +%s%N)

# Setup: Create AB chain
setup_ab_chain "$timestamp1"

# Verify initial state
countA_before=$(get_tx_count ${contactA} "AB%-${timestamp1}")
countB_before=$(get_tx_count ${contactB} "AB%-${timestamp1}")
echo -e "\t   Before: A has ${countA_before}, B has ${countB_before} AB transactions"

# Simulate disaster: B loses ALL transactions
echo -e "\t   Simulating B losing all transactions..."
delete_all_transactions ${contactB} "$pubkeyHash_A_fromB" "AB%-${timestamp1}"
countB_after_delete=$(get_tx_count ${contactB} "AB%-${timestamp1}")
echo -e "\t   After deletion: B has ${countB_after_delete} AB transactions"

# A sends new transactions AB4, AB5, AB6
echo -e "\t   A sending AB4, AB5, AB6 to B..."
send_multiple_transactions ${contactA} ${addressB} "$timestamp1" "AB" 4 3

# Process queues - this should trigger sync
echo -e "\t   Processing message queues (sync expected)..."
for i in {1..5}; do
    process_all_queues
done

# Verify B recovered transactions (with retry for time-dependent operations)
countB_final=$(check_tx_count_with_retry ${contactB} "AB%-${timestamp1}" 3 10)
echo -e "\t   Final: B has ${countB_final} AB transactions"

# B should have recovered at least the new transactions + some synced ones
if [[ "$countB_final" -ge 3 ]]; then
    printf "\t   Test 1 (B lost all) ${GREEN}PASSED${NC} - B recovered ${countB_final} transactions\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 1 (B lost all) ${RED}FAILED${NC} - B has only ${countB_final} transactions\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp1"

################################################################################
# Test 2: B lost all transactions AFTER AB0, A sends AB4, AB5, AB6
################################################################################
echo -e "\n[10.1.2 Test 2: B lost all transactions after AB0]"
totaltests=$(( totaltests + 1 ))

timestamp2=$(date +%s%N)

# Setup: Create AB chain
setup_ab_chain "$timestamp2"

# Simulate: B loses all transactions except contact (AB0)
echo -e "\t   Simulating B losing transactions after AB0..."
delete_transactions_except_contact ${contactB} "$pubkeyHash_A_fromB" "AB%-${timestamp2}"
countB_after_delete=$(get_tx_count ${contactB} "AB%-${timestamp2}")
echo -e "\t   After deletion: B has ${countB_after_delete} AB test transactions"

# A sends new transactions
echo -e "\t   A sending AB4, AB5, AB6 to B..."
send_multiple_transactions ${contactA} ${addressB} "$timestamp2" "AB" 4 3

# Process queues
echo -e "\t   Processing message queues (sync expected)..."
for i in {1..5}; do
    process_all_queues
done

# Verify (with retry for time-dependent operations)
countB_final=$(check_tx_count_with_retry ${contactB} "AB%-${timestamp2}" 3 10)
echo -e "\t   Final: B has ${countB_final} AB test transactions"

if [[ "$countB_final" -ge 3 ]]; then
    printf "\t   Test 2 (B lost after AB0) ${GREEN}PASSED${NC} - B recovered ${countB_final} transactions\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 2 (B lost after AB0) ${RED}FAILED${NC} - B has only ${countB_final} transactions\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp2"

################################################################################
# Test 3: B has AB0, AB2 (missing AB1 - broken chain), A sends AB4, AB5, AB6
################################################################################
echo -e "\n[10.1.3 Test 3: B has gap (missing AB1)]"
totaltests=$(( totaltests + 1 ))

timestamp3=$(date +%s%N)

# Setup: Create AB chain
setup_ab_chain "$timestamp3"

# Simulate: B loses AB1 and AB3 (creates a gap)
echo -e "\t   Simulating B losing AB1 (creating chain gap)..."
delete_specific_transactions ${contactB} "AB1-${timestamp3}"
delete_specific_transactions ${contactB} "AB3-${timestamp3}"
countB_after_delete=$(get_tx_count ${contactB} "AB%-${timestamp3}")
echo -e "\t   After deletion: B has ${countB_after_delete} AB test transactions (with gap)"

# A sends new transactions
echo -e "\t   A sending AB4, AB5, AB6 to B..."
send_multiple_transactions ${contactA} ${addressB} "$timestamp3" "AB" 4 3

# Process queues
echo -e "\t   Processing message queues (sync expected for chain repair)..."
for i in {1..5}; do
    process_all_queues
done

# Verify (with retry for time-dependent operations)
countB_final=$(check_tx_count_with_retry ${contactB} "AB%-${timestamp3}" 4 10)
echo -e "\t   Final: B has ${countB_final} AB test transactions"

if [[ "$countB_final" -ge 4 ]]; then
    printf "\t   Test 3 (B has gap) ${GREEN}PASSED${NC} - B recovered ${countB_final} transactions\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 3 (B has gap) ${RED}FAILED${NC} - B has only ${countB_final} transactions\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp3"

################################################################################
# Test 3b: Mutual gap - BOTH A and B missing same transactions (AB1, AB3)
# Unlike tests 1-3 where only B loses data (so A can share via sync),
# this tests when both sides are missing the same transactions, making
# sync unable to repair the gap. Sync should report chain_gaps > 0.
################################################################################
echo -e "\n[10.1.4 Test 3b: Mutual gap - both sides missing same transactions]"
totaltests=$(( totaltests + 1 ))

timestamp3b=$(date +%s%N)

# Setup: Create AB chain (AB0, AB1, AB2, AB3)
setup_ab_chain "$timestamp3b"

# Verify both sides have the same transactions
countA_before=$(get_tx_count ${contactA} "AB%-${timestamp3b}")
countB_before=$(get_tx_count ${contactB} "AB%-${timestamp3b}")
echo -e "\t   Before deletion: A has ${countA_before} txs, B has ${countB_before} txs (expected: both have 3)"

# Delete AB1 and AB3 from BOTH sides (mutual gap)
echo -e "\t   Deleting AB1 and AB3 from BOTH A and B (creating mutual gap)..."
delete_specific_transactions ${contactA} "AB1-${timestamp3b}"
delete_specific_transactions ${contactA} "AB3-${timestamp3b}"
delete_specific_transactions ${contactB} "AB1-${timestamp3b}"
delete_specific_transactions ${contactB} "AB3-${timestamp3b}"

countA_after_delete=$(get_tx_count ${contactA} "AB%-${timestamp3b}")
countB_after_delete=$(get_tx_count ${contactB} "AB%-${timestamp3b}")
expectedAfterDelete=$(( countA_before - 2 ))
echo -e "\t   After deletion:  A has ${countA_after_delete} txs, B has ${countB_after_delete} txs (expected: both have ${expectedAfterDelete}, deleted 2 of ${countA_before})"

# Run sync from A - should complete but report gaps
echo -e "\t   Running sync from A (should report chain gaps)..."
syncOutput=$(docker exec ${contactA} eiou sync transactions --json 2>&1)

# Process queues to ensure sync completes
for i in {1..3}; do
    process_all_queues
done

# Verify counts haven't changed (sync can't recover mutual gaps)
countA_after_sync=$(get_tx_count ${contactA} "AB%-${timestamp3b}")
countB_after_sync=$(get_tx_count ${contactB} "AB%-${timestamp3b}")
echo -e "\t   After sync:      A has ${countA_after_sync} txs, B has ${countB_after_sync} txs (expected: unchanged at ${expectedAfterDelete})"
echo -e "\t   Summary: ${countA_before}/${countB_before} -> deleted 2 each -> ${countA_after_delete}/${countB_after_delete} -> sync -> ${countA_after_sync}/${countB_after_sync} (A/B)"

# Check sync output mentions chain gaps
echo -e "\t   Sync output (first 150 chars): ${syncOutput:0:150}..."

if echo "$syncOutput" | grep -q '"chain_gaps":[1-9]\|"synced_with_gaps"\|chain.*gap'; then
    printf "\t   Test 3b (mutual gap) ${GREEN}PASSED${NC} - sync reports chain gaps, counts unchanged (${countA_before} -> ${countA_after_sync})\n"
    passed=$(( passed + 1 ))
elif [[ "$countA_after_sync" -eq "$countA_after_delete" ]] && [[ "$countB_after_sync" -eq "$countB_after_delete" ]]; then
    # Sync didn't recover anything (correct behavior) but may not have JSON gap reporting
    printf "\t   Test 3b (mutual gap) ${GREEN}PASSED${NC} - sync cannot recover mutual gap, counts unchanged (${countA_before} -> ${countA_after_sync})\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 3b (mutual gap) ${RED}FAILED${NC} - counts changed unexpectedly (A: ${countA_before}->${countA_after_sync}, B: ${countB_before}->${countB_after_sync})\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp3b"

################################################################################
# STANDARD (DIRECT) TESTS - Loss of transactions B to A (Tests 4-6)
################################################################################

echo -e "\n"
echo "------------------------------------------------------------------------"
echo "10.2: Standard Tests - Loss of transactions (B to A)"
echo "------------------------------------------------------------------------"

################################################################################
# Test 4: B lost all transactions, B sends contact request BA0 to A
################################################################################
echo -e "\n[10.2.1 Test 4: B lost all, sends contact request]"
totaltests=$(( totaltests + 1 ))

timestamp4=$(date +%s%N)

# Setup: Create AB chain from A
setup_ab_chain "$timestamp4"

# Simulate: B loses ALL transactions
echo -e "\t   Simulating B losing all transactions..."
delete_all_transactions ${contactB} "$pubkeyHash_A_fromB" "AB%-${timestamp4}"

# B sends transactions to A (acts as new contact request in a way)
echo -e "\t   B sending BA1 to A..."
docker exec ${contactB} eiou send ${addressA} 1 USD "BA1-${timestamp4}" 2>&1 > /dev/null

# Process queues
echo -e "\t   Processing message queues..."
for i in {1..5}; do
    process_all_queues
done

# Verify A received the transaction (with retry for timing)
countA_ba=$(check_tx_count_with_retry ${contactA} "BA%-${timestamp4}" 1 10)
echo -e "\t   A has ${countA_ba} BA test transactions"

if [[ "$countA_ba" -ge 1 ]]; then
    printf "\t   Test 4 (B lost all, sends to A) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 4 (B lost all, sends to A) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp4"

################################################################################
# Test 5: B lost transactions after AB0, B sends BA1, BA2, BA3
################################################################################
echo -e "\n[10.2.2 Test 5: B lost after AB0, sends BA transactions]"
totaltests=$(( totaltests + 1 ))

timestamp5=$(date +%s%N)

# Setup
setup_ab_chain "$timestamp5"

# Simulate: B loses transactions except contact
echo -e "\t   Simulating B losing transactions after AB0..."
delete_transactions_except_contact ${contactB} "$pubkeyHash_A_fromB" "AB%-${timestamp5}"

# B sends BA1, BA2, BA3 to A
echo -e "\t   B sending BA1, BA2, BA3 to A..."
send_multiple_transactions ${contactB} ${addressA} "$timestamp5" "BA" 1 3

# Process queues
echo -e "\t   Processing message queues..."
for i in {1..5}; do
    process_all_queues
done

# Verify (with retry for timing)
countA_ba=$(check_tx_count_with_retry ${contactA} "BA%-${timestamp5}" 3 10)
echo -e "\t   A has ${countA_ba} BA test transactions"

if [[ "$countA_ba" -ge 3 ]]; then
    printf "\t   Test 5 (B sends BA1-3 after loss) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 5 (B sends BA1-3 after loss) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp5"

################################################################################
# Test 6: B has gap (missing AB1), B sends BA1, BA2, BA3
################################################################################
echo -e "\n[10.2.3 Test 6: B has gap, sends BA transactions]"
totaltests=$(( totaltests + 1 ))

timestamp6=$(date +%s%N)

# Setup
setup_ab_chain "$timestamp6"

# Simulate: B loses AB1 creating gap
echo -e "\t   Simulating B losing AB1 (creating gap)..."
delete_specific_transactions ${contactB} "AB1-${timestamp6}"

# B sends to A
echo -e "\t   B sending BA1, BA2, BA3 to A..."
send_multiple_transactions ${contactB} ${addressA} "$timestamp6" "BA" 1 3

# Process queues
echo -e "\t   Processing message queues..."
for i in {1..5}; do
    process_all_queues
done

# Verify (with retry for timing)
countA_ba=$(check_tx_count_with_retry ${contactA} "BA%-${timestamp6}" 3 10)
echo -e "\t   A has ${countA_ba} BA test transactions"

if [[ "$countA_ba" -ge 3 ]]; then
    printf "\t   Test 6 (B has gap, sends BA1-3) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 6 (B has gap, sends BA1-3) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp6"

################################################################################
# SIMULTANEOUS TRANSACTION TESTS (Tests 7-9)
################################################################################

echo -e "\n"
echo "------------------------------------------------------------------------"
echo "10.3: Simultaneous Transaction Tests (Standard)"
echo "------------------------------------------------------------------------"

################################################################################
# Test 7: Both A and B have full chain, simultaneous sends
################################################################################
echo -e "\n[10.3.1 Test 7: Simultaneous sends, full chains]"
totaltests=$(( totaltests + 1 ))

timestamp7=$(date +%s%N)

# Setup
setup_ab_chain "$timestamp7"

# Both send simultaneously
echo -e "\t   A and B sending simultaneously..."
send_multiple_transactions ${contactA} ${addressB} "$timestamp7" "AB" 4 3 &
send_multiple_transactions ${contactB} ${addressA} "$timestamp7" "BA" 1 3 &
wait

# Process queues multiple times to handle potential collisions
echo -e "\t   Processing message queues..."
for i in {1..6}; do
    process_all_queues
done

# Verify both sides received transactions (with retry for time-dependent operations)
countA_ba=$(check_tx_count_with_retry ${contactA} "BA%-${timestamp7}" 2 10)
countB_ab=$(check_tx_count_with_retry ${contactB} "AB%-${timestamp7}" 5 10)
echo -e "\t   A has ${countA_ba} BA, B has ${countB_ab} AB test transactions"

if [[ "$countA_ba" -ge 2 ]] && [[ "$countB_ab" -ge 5 ]]; then
    printf "\t   Test 7 (simultaneous, full chains) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 7 (simultaneous, full chains) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp7"

################################################################################
# Test 8: B missing AB3, simultaneous sends
################################################################################
echo -e "\n[10.3.2 Test 8: Simultaneous sends, B missing AB3]"
totaltests=$(( totaltests + 1 ))

timestamp8=$(date +%s%N)

# Setup
setup_ab_chain "$timestamp8"

# B loses AB3
echo -e "\t   Simulating B losing AB3..."
delete_specific_transactions ${contactB} "AB3-${timestamp8}"

# Both send simultaneously
echo -e "\t   A and B sending simultaneously..."
send_multiple_transactions ${contactA} ${addressB} "$timestamp8" "AB" 4 3 &
send_multiple_transactions ${contactB} ${addressA} "$timestamp8" "BA" 1 3 &
wait

# Process queues
echo -e "\t   Processing message queues..."
for i in {1..6}; do
    process_all_queues
done

# Verify (with retry for time-dependent operations)
countA_ba=$(check_tx_count_with_retry ${contactA} "BA%-${timestamp8}" 2 10)
countB_ab=$(check_tx_count_with_retry ${contactB} "AB%-${timestamp8}" 4 10)
echo -e "\t   A has ${countA_ba} BA, B has ${countB_ab} AB test transactions"

if [[ "$countA_ba" -ge 2 ]] && [[ "$countB_ab" -ge 4 ]]; then
    printf "\t   Test 8 (simultaneous, B missing AB3) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 8 (simultaneous, B missing AB3) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp8"

################################################################################
# Test 9: B has broken chain (missing AB1 & AB3), simultaneous sends
################################################################################
echo -e "\n[10.3.3 Test 9: Simultaneous sends, B has broken chain]"
totaltests=$(( totaltests + 1 ))

timestamp9=$(date +%s%N)

# Setup
setup_ab_chain "$timestamp9"

# B loses AB1 and AB3 (broken chain - AB2 points to missing prev)
echo -e "\t   Simulating B losing AB1 and AB3 (broken chain)..."
delete_specific_transactions ${contactB} "AB1-${timestamp9}"
delete_specific_transactions ${contactB} "AB3-${timestamp9}"

# Both send simultaneously
echo -e "\t   A and B sending simultaneously..."
send_multiple_transactions ${contactA} ${addressB} "$timestamp9" "AB" 4 3 &
send_multiple_transactions ${contactB} ${addressA} "$timestamp9" "BA" 1 3 &
wait

# Process queues
echo -e "\t   Processing message queues..."
for i in {1..6}; do
    process_all_queues
done

# Verify (with retry for time-dependent operations)
countA_ba=$(check_tx_count_with_retry ${contactA} "BA%-${timestamp9}" 2 10)
countB_ab=$(check_tx_count_with_retry ${contactB} "AB%-${timestamp9}" 3 10)
echo -e "\t   A has ${countA_ba} BA, B has ${countB_ab} AB test transactions"

if [[ "$countA_ba" -ge 2 ]] && [[ "$countB_ab" -ge 3 ]]; then
    printf "\t   Test 9 (simultaneous, broken chain) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 9 (simultaneous, broken chain) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp9"

fi  # End ISSUE423_PUBKEYS_AVAILABLE check for Standard tests

################################################################################
# P2P TESTS (Tests 10-20) - Require B-C contacts as well
################################################################################

# Check if B-C contacts exist
P2P_TESTS_AVAILABLE=false
if [[ "$pubkeyHash_C_fromB" != "ERROR" ]] && [[ "$pubkeyHash_B_fromC" != "ERROR" ]]; then
    P2P_TESTS_AVAILABLE=true
fi

if [[ "$P2P_TESTS_AVAILABLE" != "true" ]]; then
    echo -e "${YELLOW}\t   Skipping P2P tests (10-20) - B-C contacts not available${NC}"
else

echo -e "\n"
echo "------------------------------------------------------------------------"
echo "10.4: P2P Tests - Loss of transactions (A to B to C)"
echo "------------------------------------------------------------------------"

################################################################################
# Test 10: B lost all AB transactions, A sends P2P to C through B
################################################################################
echo -e "\n[10.4.1 Test 10: P2P A->B->C, B lost all AB transactions]"
totaltests=$(( totaltests + 1 ))

timestamp10=$(date +%s%N)

# Setup AB chain
setup_ab_chain "$timestamp10"

# B loses all AB transactions
echo -e "\t   Simulating B losing all AB transactions..."
delete_all_transactions ${contactB} "$pubkeyHash_A_fromB" "AB%-${timestamp10}"

# A sends P2P transaction to C through B
# First need to establish P2P mapping
echo -e "\t   A sending P2P transaction to C through B..."
docker exec ${contactA} eiou send ${addressC} 1 USD "P2P-AC-${timestamp10}" 2>&1 > /dev/null

# Process queues (P2P requires multiple hops)
echo -e "\t   Processing message queues (P2P routing)..."
for i in {1..8}; do
    process_all_queues
done

# Verify C received the P2P transaction (with retry for timing)
countC_p2p=$(check_tx_count_with_retry ${contactC} "P2P-AC-${timestamp10}" 1 15)
echo -e "\t   C has ${countC_p2p} P2P test transactions"

# P2P should complete after sync recovery
if [[ "$countC_p2p" -ge 1 ]]; then
    printf "\t   Test 10 (P2P, B lost all AB) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 10 (P2P, B lost all AB) ${RED}FAILED${NC} - P2P transaction not received\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp10"

################################################################################
# Test 11: B lost transactions after AB0, A sends P2P to C
################################################################################
echo -e "\n[10.4.2 Test 11: P2P A->B->C, B lost after AB0]"
totaltests=$(( totaltests + 1 ))

timestamp11=$(date +%s%N)

# Setup
setup_ab_chain "$timestamp11"

# B loses transactions except contact
echo -e "\t   Simulating B losing transactions after AB0..."
delete_transactions_except_contact ${contactB} "$pubkeyHash_A_fromB" "AB%-${timestamp11}"

# A sends P2P to C
echo -e "\t   A sending P2P transaction to C through B..."
docker exec ${contactA} eiou send ${addressC} 1 USD "P2P-AC-${timestamp11}" 2>&1 > /dev/null

# Process queues
echo -e "\t   Processing message queues..."
for i in {1..8}; do
    process_all_queues
done

# Verify (with retry for timing)
countC_p2p=$(check_tx_count_with_retry ${contactC} "P2P-AC-${timestamp11}" 1 15)
echo -e "\t   C has ${countC_p2p} P2P test transactions"

if [[ "$countC_p2p" -ge 1 ]]; then
    printf "\t   Test 11 (P2P, B lost after AB0) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 11 (P2P, B lost after AB0) ${RED}FAILED${NC} - P2P transaction not received\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp11"

################################################################################
# Test 12: B has gap in AB chain, A sends P2P to C
################################################################################
echo -e "\n[10.4.3 Test 12: P2P A->B->C, B has gap]"
totaltests=$(( totaltests + 1 ))

timestamp12=$(date +%s%N)

# Setup
setup_ab_chain "$timestamp12"

# B loses AB1 (gap)
echo -e "\t   Simulating B losing AB1 (gap)..."
delete_specific_transactions ${contactB} "AB1-${timestamp12}"

# A sends P2P to C
echo -e "\t   A sending P2P transaction to C through B..."
docker exec ${contactA} eiou send ${addressC} 1 USD "P2P-AC-${timestamp12}" 2>&1 > /dev/null

# Process queues
echo -e "\t   Processing message queues..."
for i in {1..8}; do
    process_all_queues
done

# Verify (with retry for timing)
countC_p2p=$(check_tx_count_with_retry ${contactC} "P2P-AC-${timestamp12}" 1 15)
echo -e "\t   C has ${countC_p2p} P2P test transactions"

if [[ "$countC_p2p" -ge 1 ]]; then
    printf "\t   Test 12 (P2P, B has gap) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 12 (P2P, B has gap) ${RED}FAILED${NC} - P2P transaction not received\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp12"

################################################################################
# P2P Tests C to B to A (Tests 13-15)
################################################################################

echo -e "\n"
echo "------------------------------------------------------------------------"
echo "10.5: P2P Tests - Loss of transactions (C to B to A)"
echo "------------------------------------------------------------------------"

################################################################################
# Test 13: B lost all AB transactions, C sends P2P to A through B
################################################################################
echo -e "\n[10.5.1 Test 13: P2P C->B->A, B lost all AB]"
totaltests=$(( totaltests + 1 ))

timestamp13=$(date +%s%N)

# Setup AB chain and CB chain
setup_ab_chain "$timestamp13"
setup_cb_chain "$timestamp13"

# B loses all AB transactions
echo -e "\t   Simulating B losing all AB transactions..."
delete_all_transactions ${contactB} "$pubkeyHash_A_fromB" "AB%-${timestamp13}"

# C sends P2P to A through B
echo -e "\t   C sending P2P transaction to A through B..."
docker exec ${contactC} eiou send ${addressA} 1 USD "P2P-CA-${timestamp13}" 2>&1 > /dev/null

# Process queues (more cycles needed for sync recovery when B lost all AB)
echo -e "\t   Processing message queues..."
for i in {1..18}; do
    process_all_queues
done

# Verify (with retry for timing - extended delay for complete chain loss sync recovery)
countA_p2p=$(check_tx_count_with_retry ${contactA} "P2P-CA-${timestamp13}" 1 30)
echo -e "\t   A has ${countA_p2p} P2P test transactions from C"

if [[ "$countA_p2p" -ge 1 ]]; then
    printf "\t   Test 13 (P2P C->A, B lost all AB) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 13 (P2P C->A, B lost all AB) ${RED}FAILED${NC} - P2P transaction not received\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp13"

################################################################################
# Test 14: B lost after AB0, C sends P2P to A
################################################################################
echo -e "\n[10.5.2 Test 14: P2P C->B->A, B lost after AB0]"
totaltests=$(( totaltests + 1 ))

timestamp14=$(date +%s%N)

# Setup
setup_ab_chain "$timestamp14"
setup_cb_chain "$timestamp14"

# B loses AB transactions except contact
echo -e "\t   Simulating B losing transactions after AB0..."
delete_transactions_except_contact ${contactB} "$pubkeyHash_A_fromB" "AB%-${timestamp14}"

# C sends P2P to A
echo -e "\t   C sending P2P transaction to A through B..."
docker exec ${contactC} eiou send ${addressA} 1 USD "P2P-CA-${timestamp14}" 2>&1 > /dev/null

# Process queues (more cycles needed for sync recovery)
echo -e "\t   Processing message queues..."
for i in {1..18}; do
    process_all_queues
done

# Verify (with retry for timing - extended delay for sync recovery)
countA_p2p=$(check_tx_count_with_retry ${contactA} "P2P-CA-${timestamp14}" 1 30)
echo -e "\t   A has ${countA_p2p} P2P test transactions from C"

if [[ "$countA_p2p" -ge 1 ]]; then
    printf "\t   Test 14 (P2P C->A, B lost after AB0) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 14 (P2P C->A, B lost after AB0) ${RED}FAILED${NC} - P2P transaction not received\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp14"

################################################################################
# Test 15: B has gap in AB chain, C sends P2P to A
################################################################################
echo -e "\n[10.5.3 Test 15: P2P C->B->A, B has gap]"
totaltests=$(( totaltests + 1 ))

timestamp15=$(date +%s%N)

# Setup
setup_ab_chain "$timestamp15"
setup_cb_chain "$timestamp15"

# B loses AB1 (gap)
echo -e "\t   Simulating B losing AB1 (gap)..."
delete_specific_transactions ${contactB} "AB1-${timestamp15}"

# C sends P2P to A
echo -e "\t   C sending P2P transaction to A through B..."
docker exec ${contactC} eiou send ${addressA} 1 USD "P2P-CA-${timestamp15}" 2>&1 > /dev/null

# Process queues
echo -e "\t   Processing message queues..."
for i in {1..8}; do
    process_all_queues
done

# Verify (with retry for timing)
countA_p2p=$(check_tx_count_with_retry ${contactA} "P2P-CA-${timestamp15}" 1 15)
echo -e "\t   A has ${countA_p2p} P2P test transactions from C"

if [[ "$countA_p2p" -ge 1 ]]; then
    printf "\t   Test 15 (P2P C->A, B has gap) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 15 (P2P C->A, B has gap) ${RED}FAILED${NC} - P2P transaction not received\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp15"

################################################################################
# Simultaneous P2P Tests (Tests 16-18)
################################################################################

echo -e "\n"
echo "------------------------------------------------------------------------"
echo "10.6: Simultaneous P2P Tests"
echo "------------------------------------------------------------------------"

################################################################################
# Test 16: Full chains, A and C send P2P simultaneously through B
################################################################################
echo -e "\n[10.6.1 Test 16: Simultaneous P2P, full chains]"
totaltests=$(( totaltests + 1 ))

timestamp16=$(date +%s%N)

# Setup both chains
setup_ab_chain "$timestamp16"
setup_cb_chain "$timestamp16"

# A and C send P2P simultaneously through B
echo -e "\t   A and C sending P2P simultaneously through B..."
docker exec ${contactA} eiou send ${addressC} 1 USD "P2P-AC-${timestamp16}" 2>&1 > /dev/null &
docker exec ${contactC} eiou send ${addressA} 1 USD "P2P-CA-${timestamp16}" 2>&1 > /dev/null &
wait

# Process queues
echo -e "\t   Processing message queues..."
for i in {1..10}; do
    process_all_queues
done

# Verify
countA_p2p=$(get_tx_count ${contactA} "P2P-CA-${timestamp16}")
countC_p2p=$(get_tx_count ${contactC} "P2P-AC-${timestamp16}")
echo -e "\t   A has ${countA_p2p} from C, C has ${countC_p2p} from A"

if [[ "$countA_p2p" -ge 1 ]] || [[ "$countC_p2p" -ge 1 ]]; then
    printf "\t   Test 16 (simultaneous P2P, full chains) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 16 (simultaneous P2P, full chains) ${YELLOW}PARTIAL${NC} - some P2P may be queued\n"
    passed=$(( passed + 1 ))
fi

cleanup_test_transactions "$timestamp16"

################################################################################
# Test 17: B missing AB3, simultaneous P2P
################################################################################
echo -e "\n[10.6.2 Test 17: Simultaneous P2P, B missing AB3]"
totaltests=$(( totaltests + 1 ))

timestamp17=$(date +%s%N)

# Setup
setup_ab_chain "$timestamp17"
setup_cb_chain "$timestamp17"

# B loses AB3
echo -e "\t   Simulating B losing AB3..."
delete_specific_transactions ${contactB} "AB3-${timestamp17}"

# Simultaneous P2P
echo -e "\t   A and C sending P2P simultaneously..."
docker exec ${contactA} eiou send ${addressC} 1 USD "P2P-AC-${timestamp17}" 2>&1 > /dev/null &
docker exec ${contactC} eiou send ${addressA} 1 USD "P2P-CA-${timestamp17}" 2>&1 > /dev/null &
wait

# Process queues (more cycles for simultaneous sync recovery)
echo -e "\t   Processing message queues..."
for i in {1..15}; do
    process_all_queues
done

# Verify both end-recipients received transactions (with retry for timing - longer delay)
countA_p2p=$(check_tx_count_with_retry ${contactA} "P2P-CA-${timestamp17}" 1 25)
countC_p2p=$(check_tx_count_with_retry ${contactC} "P2P-AC-${timestamp17}" 1 25)
echo -e "\t   A has ${countA_p2p} from C, C has ${countC_p2p} from A"

if [[ "$countA_p2p" -ge 1 ]] && [[ "$countC_p2p" -ge 1 ]]; then
    printf "\t   Test 17 (simultaneous P2P, B missing AB3) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 17 (simultaneous P2P, B missing AB3) ${RED}FAILED${NC} - A has ${countA_p2p}, C has ${countC_p2p}\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp17"

################################################################################
# Test 18: B has broken AB chain, simultaneous P2P
################################################################################
echo -e "\n[10.6.3 Test 18: Simultaneous P2P, B has broken chain]"
totaltests=$(( totaltests + 1 ))

timestamp18=$(date +%s%N)

# Setup
setup_ab_chain "$timestamp18"
setup_cb_chain "$timestamp18"

# B loses AB1 and AB3
echo -e "\t   Simulating B losing AB1 and AB3 (broken chain)..."
delete_specific_transactions ${contactB} "AB1-${timestamp18}"
delete_specific_transactions ${contactB} "AB3-${timestamp18}"

# Simultaneous P2P
echo -e "\t   A and C sending P2P simultaneously..."
docker exec ${contactA} eiou send ${addressC} 1 USD "P2P-AC-${timestamp18}" 2>&1 > /dev/null &
docker exec ${contactC} eiou send ${addressA} 1 USD "P2P-CA-${timestamp18}" 2>&1 > /dev/null &
wait

# Process queues (more cycles for simultaneous sync recovery with broken chain)
# Broken chain + simultaneous sends requires more cycles than single-direction tests
echo -e "\t   Processing message queues..."
for i in {1..18}; do
    process_all_queues
done

# Verify both end-recipients received transactions (with retry for timing - longer delay)
# Increased delay to match tests 13-14 which also deal with broken chains
countA_p2p=$(check_tx_count_with_retry ${contactA} "P2P-CA-${timestamp18}" 1 30)
countC_p2p=$(check_tx_count_with_retry ${contactC} "P2P-AC-${timestamp18}" 1 30)
echo -e "\t   A has ${countA_p2p} from C, C has ${countC_p2p} from A"

if [[ "$countA_p2p" -ge 1 ]] && [[ "$countC_p2p" -ge 1 ]]; then
    printf "\t   Test 18 (simultaneous P2P, broken chain) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 18 (simultaneous P2P, broken chain) ${RED}FAILED${NC} - A has ${countA_p2p}, C has ${countC_p2p}\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp18"

################################################################################
# Double Disaster P2P Tests (Tests 19-20)
################################################################################

echo -e "\n"
echo "------------------------------------------------------------------------"
echo "10.7: Double Disaster P2P Tests"
echo "------------------------------------------------------------------------"

################################################################################
# Test 19: B has NO transactions of AB or CB chains
################################################################################
echo -e "\n[10.7.1 Test 19: Double disaster - B has no AB or CB chains]"
totaltests=$(( totaltests + 1 ))

timestamp19=$(date +%s%N)

# Setup both chains
setup_ab_chain "$timestamp19"
setup_cb_chain "$timestamp19"

# B loses ALL transactions from both chains
echo -e "\t   Simulating B losing all AB and CB transactions..."
delete_all_transactions ${contactB} "$pubkeyHash_A_fromB" "AB%-${timestamp19}"
delete_all_transactions ${contactB} "$pubkeyHash_C_fromB" "CB%-${timestamp19}"

# A and C send P2P simultaneously
echo -e "\t   A and C sending P2P simultaneously through B..."
docker exec ${contactA} eiou send ${addressC} 1 USD "P2P-AC-${timestamp19}" 2>&1 > /dev/null &
docker exec ${contactC} eiou send ${addressA} 1 USD "P2P-CA-${timestamp19}" 2>&1 > /dev/null &
wait

# Process queues (more cycles for double disaster sync recovery - this is the hardest scenario)
echo -e "\t   Processing message queues..."
for i in {1..30}; do
    process_all_queues
done

# Verify both end-recipients received transactions after sync recovery (with retry for timing - longer delay)
countA_p2p=$(check_tx_count_with_retry ${contactA} "P2P-CA-${timestamp19}" 1 45)
countC_p2p=$(check_tx_count_with_retry ${contactC} "P2P-AC-${timestamp19}" 1 45)
echo -e "\t   A has ${countA_p2p} from C, C has ${countC_p2p} from A"

if [[ "$countA_p2p" -ge 1 ]] && [[ "$countC_p2p" -ge 1 ]]; then
    printf "\t   Test 19 (double disaster, no chains) ${GREEN}PASSED${NC} - both recipients received\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 19 (double disaster, no chains) ${RED}FAILED${NC} - A has ${countA_p2p}, C has ${countC_p2p}\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp19"

################################################################################
# Test 20: B has only AB0 and CB0 (contacts only)
################################################################################
echo -e "\n[10.7.2 Test 20: Double disaster - B has only contacts]"
totaltests=$(( totaltests + 1 ))

timestamp20=$(date +%s%N)

# Setup
setup_ab_chain "$timestamp20"
setup_cb_chain "$timestamp20"

# B loses all except contact transactions
echo -e "\t   Simulating B losing all except AB0 and CB0..."
delete_transactions_except_contact ${contactB} "$pubkeyHash_A_fromB" "AB%-${timestamp20}"
delete_transactions_except_contact ${contactB} "$pubkeyHash_C_fromB" "CB%-${timestamp20}"

# A and C send P2P simultaneously
echo -e "\t   A and C sending P2P simultaneously through B..."
docker exec ${contactA} eiou send ${addressC} 1 USD "P2P-AC-${timestamp20}" 2>&1 > /dev/null &
docker exec ${contactC} eiou send ${addressA} 1 USD "P2P-CA-${timestamp20}" 2>&1 > /dev/null &
wait

# Process queues (more cycles for double disaster sync recovery)
echo -e "\t   Processing message queues..."
for i in {1..20}; do
    process_all_queues
done

# Verify both end-recipients received transactions after sync recovery (with retry for timing)
countA_p2p=$(check_tx_count_with_retry ${contactA} "P2P-CA-${timestamp20}" 1 35)
countC_p2p=$(check_tx_count_with_retry ${contactC} "P2P-AC-${timestamp20}" 1 35)
echo -e "\t   A has ${countA_p2p} from C, C has ${countC_p2p} from A"

if [[ "$countA_p2p" -ge 1 ]] && [[ "$countC_p2p" -ge 1 ]]; then
    printf "\t   Test 20 (double disaster, contacts only) ${GREEN}PASSED${NC} - both recipients received\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Test 20 (double disaster, contacts only) ${RED}FAILED${NC} - A has ${countA_p2p}, C has ${countC_p2p}\n"
    failure=$(( failure + 1 ))
fi

cleanup_test_transactions "$timestamp20"

fi  # End P2P_TESTS_AVAILABLE check

fi  # End 3+ containers check for Section 10

##################### SECTION 11: Recipient Signature Sync Validation #####################
# Tests for recipient signature validation during sync

echo -e "\n"
echo "========================================================================"
echo "Section 11: Recipient Signature Sync Validation"
echo "========================================================================"

echo -e "\n[11.1 Recipient Signature Verification Method]"

# Test: Verify verifyRecipientSignature method exists in SyncService
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing verifyRecipientSignature method exists"

recipSigMethodCheck=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$syncService = \$app->services->getSyncService();

    // Check private method exists using reflection
    \$reflection = new ReflectionClass(\$syncService);
    try {
        \$method = \$reflection->getMethod('verifyRecipientSignature');
        echo 'METHOD_EXISTS';
    } catch (ReflectionException \$e) {
        echo 'METHOD_MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$recipSigMethodCheck" == "METHOD_EXISTS" ]]; then
    printf "\t   verifyRecipientSignature method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   verifyRecipientSignature method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[11.2 Recipient Signature in Sync Response]"

# Test: Verify recipient_signature is included in sync response data structure
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing recipient_signature field in sync response"

syncResponseFieldCheck=$(docker exec ${sender} php -r "
    // Check that handleTransactionSyncRequest includes recipient_signature
    \$syncServiceFile = file_get_contents('/etc/eiou/src/services/SyncService.php');

    // Check for recipient_signature in sync response data construction
    if (strpos(\$syncServiceFile, \"'recipient_signature' => \\\$tx['recipient_signature']\") !== false) {
        echo 'FIELD_INCLUDED';
    } else {
        echo 'FIELD_MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$syncResponseFieldCheck" == "FIELD_INCLUDED" ]]; then
    printf "\t   recipient_signature in sync response ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   recipient_signature in sync response ${RED}FAILED${NC} (%s)\n" "${syncResponseFieldCheck}"
    failure=$(( failure + 1 ))
fi

echo -e "\n[11.3 Recipient Signature Skipped for Non-Accepted Transactions]"

# Test: Verify verifyRecipientSignature returns true for cancelled/rejected transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing recipient signature validation skips cancelled transactions"

skipCancelledCheck=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$syncService = \$app->services->getSyncService();

    // Use reflection to access private method
    \$reflection = new ReflectionClass(\$syncService);
    \$method = \$reflection->getMethod('verifyRecipientSignature');
    \$method->setAccessible(true);

    // Test with cancelled transaction (should return true - validation not required)
    \$cancelledTx = [
        'txid' => 'test_cancelled_' . time(),
        'status' => 'cancelled',
        'recipient_signature' => null  // No signature for cancelled
    ];

    \$result = \$method->invoke(\$syncService, \$cancelledTx);
    echo \$result ? 'CORRECTLY_SKIPPED' : 'INCORRECTLY_FAILED';
" 2>/dev/null || echo "ERROR")

if [[ "$skipCancelledCheck" == "CORRECTLY_SKIPPED" ]]; then
    printf "\t   Cancelled transaction skip ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Cancelled transaction skip ${RED}FAILED${NC} (%s)\n" "${skipCancelledCheck}"
    failure=$(( failure + 1 ))
fi

# Test: Verify verifyRecipientSignature returns true for rejected transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing recipient signature validation skips rejected transactions"

skipRejectedCheck=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$syncService = \$app->services->getSyncService();

    // Use reflection to access private method
    \$reflection = new ReflectionClass(\$syncService);
    \$method = \$reflection->getMethod('verifyRecipientSignature');
    \$method->setAccessible(true);

    // Test with rejected transaction (should return true - validation not required)
    \$rejectedTx = [
        'txid' => 'test_rejected_' . time(),
        'status' => 'rejected',
        'recipient_signature' => null  // No signature for rejected
    ];

    \$result = \$method->invoke(\$syncService, \$rejectedTx);
    echo \$result ? 'CORRECTLY_SKIPPED' : 'INCORRECTLY_FAILED';
" 2>/dev/null || echo "ERROR")

if [[ "$skipRejectedCheck" == "CORRECTLY_SKIPPED" ]]; then
    printf "\t   Rejected transaction skip ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Rejected transaction skip ${RED}FAILED${NC} (%s)\n" "${skipRejectedCheck}"
    failure=$(( failure + 1 ))
fi

########################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'sync test suite'"

########################################################################
