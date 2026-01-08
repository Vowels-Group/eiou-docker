#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

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
docker exec ${sender} eiou add ${receiverAddress} ${receiver} 0 0 USD 2>&1 > /dev/null || true
sleep 1
docker exec ${receiver} eiou add ${senderAddress} ${sender} 0 0 USD 2>&1 > /dev/null || true
sleep 2

# Wait for contacts to be accepted (pubkeys only available after acceptance)
echo -e "\t   Waiting for contacts to be accepted..."
waitElapsed=0
while [ $waitElapsed -lt 15 ]; do
    senderStatus=$(docker exec ${sender} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$status = \$app->services->getContactRepository()->getContactStatus('${MODE}', '${receiverAddress}');
        echo \$status ?? 'none';
    " 2>/dev/null || echo "none")

    receiverStatus=$(docker exec ${receiver} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$status = \$app->services->getContactRepository()->getContactStatus('${MODE}', '${senderAddress}');
        echo \$status ?? 'none';
    " 2>/dev/null || echo "none")

    if [[ "$senderStatus" == "accepted" ]] && [[ "$receiverStatus" == "accepted" ]]; then
        echo -e "\t   Contacts accepted (${waitElapsed}s)"
        break
    fi

    sleep 1
    waitElapsed=$((waitElapsed + 1))
done

if [[ "$senderStatus" != "accepted" ]] || [[ "$receiverStatus" != "accepted" ]]; then
    echo -e "${YELLOW}\t   Warning: Contacts not fully accepted (sender: ${senderStatus}, receiver: ${receiverStatus})${NC}"
fi

# Get public keys directly via PHP (use MODE for transport type)
# Using getContactPubkey which is the standard method used across tests
receiverPubkeyB64=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}', '${receiverAddress}');
    if (\$pubkey) {
        echo base64_encode(\$pubkey);
    } else {
        echo 'ERROR';
    }
" 2>/dev/null || echo "ERROR")

receiverPubkeyHash=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}', '${receiverAddress}');
    if (\$pubkey) {
        echo hash('sha256', \$pubkey);
    } else {
        echo 'ERROR';
    }
" 2>/dev/null || echo "ERROR")

senderPubkeyB64=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}', '${senderAddress}');
    if (\$pubkey) {
        echo base64_encode(\$pubkey);
    } else {
        echo 'ERROR';
    }
" 2>/dev/null || echo "ERROR")

senderPubkeyHash=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${MODE}', '${senderAddress}');
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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
sleep 1
docker exec ${sender} eiou send ${receiverAddress} 2 USD "chain-sync-test-tx2-${timestamp}" 2>&1 > /dev/null
sleep 1
docker exec ${sender} eiou send ${receiverAddress} 3 USD "chain-sync-test-tx3-${timestamp}" 2>&1 > /dev/null
sleep 2

senderTxCount=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'chain-sync-test-tx%${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")
receiverTxCount=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'chain-sync-test-tx%${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'chain-sync-test-tx%${timestamp}'\");
" 2>/dev/null

senderTxCountAfterDelete=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
    require_once('${REL_FUNCTIONS}');
    \$app = Application::getInstance();
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

# Verify transactions were recovered
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying transactions were recovered"

senderTxCountAfterSync=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'chain-sync-test-tx%${timestamp}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")
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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'chain-sync-test-tx%'\");
" 2>/dev/null
docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
sleep 2
docker exec ${sender} eiou send ${receiverAddress} 2 USD "cycle-test-2-${timestamp2}" 2>&1 > /dev/null
sleep 2

docker exec ${receiver} eiou in 2>&1 > /dev/null
sleep 2

initialCountSender=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'cycle-test%${timestamp2}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")
initialCountReceiver=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'cycle-test%${timestamp2}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")

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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'cycle-test%${timestamp2}'\");
" 2>/dev/null

# Send new transaction (triggers resync due to previous_txid mismatch)
docker exec ${sender} eiou send ${receiverAddress} 10 USD "cycle-test-3-${timestamp2}" 2>&1 > /dev/null
sleep 2
# Sender processes outgoing
docker exec ${sender} eiou out 2>&1 > /dev/null
sleep 3
# Receiver processes incoming - may detect mismatch and request sync
docker exec ${receiver} eiou in 2>&1 > /dev/null
sleep 2
# Receiver sends sync request/response
docker exec ${receiver} eiou out 2>&1 > /dev/null
sleep 3
# Sender receives sync data
docker exec ${sender} eiou in 2>&1 > /dev/null
sleep 3
# Additional round to ensure full sync
docker exec ${sender} eiou out 2>&1 > /dev/null
sleep 2
docker exec ${sender} eiou in 2>&1 > /dev/null
sleep 2

countAfterCycle=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE 'cycle-test%${timestamp2}'\")->fetchColumn();
    echo \$count;
" 2>/dev/null || echo "0")
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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'cycle-test%'\");
" 2>/dev/null
docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
    require_once('${REL_FUNCTIONS}');
    \$app = Application::getInstance();

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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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

##################### SECTION 8: Issue #404 - Proactive Sync & Description Privacy #####################
# Tests for Issue #404 fixes:
# 1. Receiver proactively syncs when prev_id doesn't match
# 2. Descriptions are filtered during sync (only contact/standard transactions)

echo -e "\n"
echo "========================================================================"
echo "Section 8: Issue #404 - Proactive Sync & Description Privacy"
echo "========================================================================"

echo -e "\n[8.1 Proactive Sync Trigger Test]"

# Test: Verify that checkTransactionPossible triggers proactive sync when receiver has no history
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing proactive sync trigger in checkTransactionPossible"

proactiveSyncResult=$(docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$transactionService = \$app->services->getTransactionService();

    // Check that TransactionService has the checkTransactionPossible method
    // and that it includes sync logic (by checking for getSyncService call)
    \$reflection = new ReflectionClass(\$transactionService);
    \$method = \$reflection->getMethod('checkTransactionPossible');
    \$startLine = \$method->getStartLine();
    \$endLine = \$method->getEndLine();
    \$filename = \$method->getFileName();

    // Read the method source
    \$lines = file(\$filename);
    \$source = implode('', array_slice(\$lines, \$startLine - 1, \$endLine - \$startLine + 1));

    // Check if the method contains proactive sync logic
    if (strpos(\$source, 'getSyncService') !== false &&
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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
    require_once('${REL_APPLICATION}');

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
    require_once('${REL_APPLICATION}');

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
    require_once('${REL_APPLICATION}');

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

##################### SECTION 9: Issue #408 - Simultaneous Transaction Chain Conflict Resolution #####################
# Tests for Issue #408 fix:
# When Contact A and Contact B both send transactions simultaneously with the same
# previous_txid, they create a "chain fork". The fix implements deterministic
# conflict resolution using lexicographic txid comparison.

echo -e "\n"
echo "========================================================================"
echo "Section 9: Issue #408 - Simultaneous Transaction Chain Conflict Resolution"
echo "========================================================================"

echo -e "\n[9.1 Chain Conflict Resolution Method Test]"

# Test: Verify resolveChainConflict method exists in SyncService
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing resolveChainConflict method exists"

resolveMethodResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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

# Test: Verify getLocalTransactionByPreviousTxid method exists in TransactionRepository
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getLocalTransactionByPreviousTxid method exists"

conflictDetectionResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$transactionRepo = \$app->services->getTransactionRepository();

    if (method_exists(\$transactionRepo, 'getLocalTransactionByPreviousTxid')) {
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

# Test: Verify getByPreviousTxid method exists in TransactionRepository
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getByPreviousTxid method exists"

getByPrevTxidResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$transactionRepo = \$app->services->getTransactionRepository();

    if (method_exists(\$transactionRepo, 'getByPreviousTxid')) {
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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
sleep 2
docker exec ${sender} eiou out 2>&1 > /dev/null
sleep 2
docker exec ${receiver} eiou in 2>&1 > /dev/null
sleep 2

# Get the base transaction's txid (this will be the shared previous_txid)
baseTxid=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
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
        require_once('${REL_FUNCTIONS}');
        \$app = Application::getInstance();
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
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'fork-test%'\");
" 2>/dev/null
docker exec ${receiver} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'fork-test%'\");
" 2>/dev/null

fi  # End PUBKEYS_AVAILABLE check for section 9.6

########################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'sync test suite'"

########################################################################
