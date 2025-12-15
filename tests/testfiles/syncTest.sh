#!/bin/sh

# Test sync functionality from SyncService.php
# Tests the sync command for contacts, transactions, and balances
# Issue #207 - Balance sync functionality

echo -e "\nTesting sync functionality..."

testname="syncTest"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

############################ SYNC HELP COMMAND ############################

echo -e "\n[Sync Command in Help Test]"

# Test 1: Verify sync command appears in help output (regular)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync' appears in help output (regular)"
helpOutput=$(docker exec ${testContainer} eiou help 2>&1)

if [[ "$helpOutput" =~ "sync" ]]; then
    printf "\t   sync in help (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync in help (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${helpOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 2: Verify sync command appears in help output (JSON)
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

# Test 3: Verify help sync command shows specific info (regular)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'help sync' command (regular)"
helpSyncOutput=$(docker exec ${testContainer} eiou help sync 2>&1)

if [[ "$helpSyncOutput" =~ "sync" ]] && [[ "$helpSyncOutput" =~ "Synchronize" ]]; then
    printf "\t   help sync (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   help sync (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${helpSyncOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 4: Verify help sync command shows specific info (JSON)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'help sync' command (JSON)"
helpSyncJsonOutput=$(docker exec ${testContainer} eiou help sync --json 2>&1)

if [[ "$helpSyncJsonOutput" =~ '"success"' ]] && [[ "$helpSyncJsonOutput" =~ 'true' ]] && [[ "$helpSyncJsonOutput" =~ '"sync"' ]]; then
    printf "\t   help sync (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   help sync (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ SYNC ALL COMMAND ############################

echo -e "\n[Sync All Command Test]"

# Test 5: sync (all) - regular output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync' command (all, regular output)"
syncAllOutput=$(docker exec ${testContainer} eiou sync 2>&1)

# Check for expected success message or output structure
if [[ "$syncAllOutput" =~ "Sync" ]] || [[ "$syncAllOutput" =~ "sync" ]] || [[ "$syncAllOutput" =~ "completed" ]] || [[ "$syncAllOutput" =~ "Synced" ]]; then
    printf "\t   sync all (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync all (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${syncAllOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 6: sync (all) - JSON output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync' command (all, JSON output)"
syncAllJsonOutput=$(docker exec ${testContainer} eiou sync --json 2>&1)

if [[ "$syncAllJsonOutput" =~ '"success"' ]] && [[ "$syncAllJsonOutput" =~ 'true' ]]; then
    printf "\t   sync all (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync all (JSON) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${syncAllJsonOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ SYNC CONTACTS COMMAND ############################

echo -e "\n[Sync Contacts Command Test]"

# Test 7: sync contacts - regular output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync contacts' command (regular output)"
syncContactsOutput=$(docker exec ${testContainer} eiou sync contacts 2>&1)

if [[ "$syncContactsOutput" =~ "Contact" ]] || [[ "$syncContactsOutput" =~ "contact" ]] || [[ "$syncContactsOutput" =~ "sync" ]] || [[ "$syncContactsOutput" =~ "Sync" ]]; then
    printf "\t   sync contacts (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync contacts (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${syncContactsOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 8: sync contacts - JSON output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync contacts' command (JSON output)"
syncContactsJsonOutput=$(docker exec ${testContainer} eiou sync contacts --json 2>&1)

if [[ "$syncContactsJsonOutput" =~ '"success"' ]] && [[ "$syncContactsJsonOutput" =~ 'true' ]]; then
    printf "\t   sync contacts (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync contacts (JSON) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${syncContactsJsonOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ SYNC TRANSACTIONS COMMAND ############################

echo -e "\n[Sync Transactions Command Test]"

# Test 9: sync transactions - regular output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync transactions' command (regular output)"
syncTransactionsOutput=$(docker exec ${testContainer} eiou sync transactions 2>&1)

if [[ "$syncTransactionsOutput" =~ "Transaction" ]] || [[ "$syncTransactionsOutput" =~ "transaction" ]] || [[ "$syncTransactionsOutput" =~ "sync" ]] || [[ "$syncTransactionsOutput" =~ "Sync" ]]; then
    printf "\t   sync transactions (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync transactions (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${syncTransactionsOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 10: sync transactions - JSON output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync transactions' command (JSON output)"
syncTransactionsJsonOutput=$(docker exec ${testContainer} eiou sync transactions --json 2>&1)

if [[ "$syncTransactionsJsonOutput" =~ '"success"' ]] && [[ "$syncTransactionsJsonOutput" =~ 'true' ]]; then
    printf "\t   sync transactions (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync transactions (JSON) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${syncTransactionsJsonOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ SYNC BALANCES COMMAND ############################

echo -e "\n[Sync Balances Command Test]"

# Test 11: sync balances - regular output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync balances' command (regular output)"
syncBalancesOutput=$(docker exec ${testContainer} eiou sync balances 2>&1)

if [[ "$syncBalancesOutput" =~ "Balance" ]] || [[ "$syncBalancesOutput" =~ "balance" ]] || [[ "$syncBalancesOutput" =~ "sync" ]] || [[ "$syncBalancesOutput" =~ "Sync" ]]; then
    printf "\t   sync balances (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync balances (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${syncBalancesOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 12: sync balances - JSON output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync balances' command (JSON output)"
syncBalancesJsonOutput=$(docker exec ${testContainer} eiou sync balances --json 2>&1)

if [[ "$syncBalancesJsonOutput" =~ '"success"' ]] && [[ "$syncBalancesJsonOutput" =~ 'true' ]]; then
    printf "\t   sync balances (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync balances (JSON) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${syncBalancesJsonOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ SYNC INVALID TYPE TEST ############################

echo -e "\n[Sync Invalid Type Test]"

# Test 13: sync with invalid type - regular output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync invalidtype' command (regular output)"
syncInvalidOutput=$(docker exec ${testContainer} eiou sync invalidtype 2>&1)

# Should return error message about invalid sync type
if [[ "$syncInvalidOutput" =~ "Invalid" ]] || [[ "$syncInvalidOutput" =~ "invalid" ]] || [[ "$syncInvalidOutput" =~ "error" ]] || [[ "$syncInvalidOutput" =~ "Error" ]]; then
    printf "\t   sync invalid type (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync invalid type (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${syncInvalidOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 14: sync with invalid type - JSON output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sync invalidtype' command (JSON output)"
syncInvalidJsonOutput=$(docker exec ${testContainer} eiou sync invalidtype --json 2>&1)

# Should return JSON error response with success: false
if [[ "$syncInvalidJsonOutput" =~ '"success":false' ]] || [[ "$syncInvalidJsonOutput" =~ '"success": false' ]]; then
    printf "\t   sync invalid type (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sync invalid type (JSON) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${syncInvalidJsonOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ MULTI-CONTAINER SYNC TEST ############################

echo -e "\n[Multi-Container Sync Test]"

# Test sync balances on all containers to verify consistency
for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing 'sync balances --json' on ${container}"

    containerSyncJson=$(docker exec ${container} eiou sync balances --json 2>&1)

    # Check JSON structure is consistent across all containers
    if [[ "$containerSyncJson" =~ '"success"' ]] && [[ "$containerSyncJson" =~ 'true' ]]; then
        printf "\t   sync balances on %s ${GREEN}PASSED${NC}\n" ${container}
        passed=$(( passed + 1 ))
    else
        printf "\t   sync balances on %s ${RED}FAILED${NC}\n" ${container}
        printf "\t   Output: ${containerSyncJson}\n"
        failure=$(( failure + 1 ))
    fi
done

############################ BALANCE SYNC VERIFICATION ############################

echo -e "\n[Balance Sync Verification Test]"

# Verify balance sync actually recalculates from transactions
# Pick first two connected containers for test
firstLink="${containersLinkKeys[0]}"
if [[ "$firstLink" ]]; then
    containerPair=(${firstLink//,/ })
    sender="${containerPair[0]}"
    receiver="${containerPair[1]}"

    # Get initial balances before sync
    echo -e "\n\t-> Getting balances before sync on ${sender}"

    # Send a test transaction to ensure there's data to sync
    testAmount="3"
    echo -e "\t   Sending ${testAmount} USD from ${sender} to ${receiver}..."
    sendOutput=$(docker exec ${sender} eiou send ${containerAddresses[${receiver}]} ${testAmount} USD 2>&1)

    # Wait for transaction to process
    echo -e "\t   Waiting for transaction processing..."
    sleep 5

    # Now run sync balances and verify it works
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing balance sync after transaction"

    syncVerifyOutput=$(docker exec ${sender} eiou sync balances --json 2>&1)

    if [[ "$syncVerifyOutput" =~ '"success"' ]] && [[ "$syncVerifyOutput" =~ 'true' ]] && [[ "$syncVerifyOutput" =~ '"synced"' ]]; then
        printf "\t   Balance sync verification ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Balance sync verification ${RED}FAILED${NC}\n"
        printf "\t   Output: ${syncVerifyOutput}\n"
        failure=$(( failure + 1 ))
    fi

    # Verify receiver also syncs correctly
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing balance sync on receiver ${receiver}"

    receiverSyncOutput=$(docker exec ${receiver} eiou sync balances --json 2>&1)

    if [[ "$receiverSyncOutput" =~ '"success"' ]] && [[ "$receiverSyncOutput" =~ 'true' ]]; then
        printf "\t   Receiver balance sync ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Receiver balance sync ${RED}FAILED${NC}\n"
        printf "\t   Output: ${receiverSyncOutput}\n"
        failure=$(( failure + 1 ))
    fi
fi

############################ JSON STRUCTURE VALIDATION ############################

echo -e "\n[Sync JSON Structure Validation Test]"

# Test: Validate sync JSON output has required metadata fields
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing sync JSON output structure (metadata fields)"
jsonStructureOutput=$(docker exec ${testContainer} eiou sync --json 2>&1)

# Check for required JSON metadata fields
if [[ "$jsonStructureOutput" =~ '"success"' ]] && [[ "$jsonStructureOutput" =~ '"command"' ]] && [[ "$jsonStructureOutput" =~ '"timestamp"' ]]; then
    printf "\t   Sync JSON metadata structure ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Sync JSON metadata structure ${RED}FAILED${NC}\n"
    printf "\t   Output: ${jsonStructureOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: Validate sync balances JSON has expected data structure
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing sync balances JSON data structure"
balancesStructureOutput=$(docker exec ${testContainer} eiou sync balances --json 2>&1)

# Check for expected data fields in balance sync response
if [[ "$balancesStructureOutput" =~ '"total_contacts"' ]] || [[ "$balancesStructureOutput" =~ '"synced"' ]] || [[ "$balancesStructureOutput" =~ '"data"' ]]; then
    printf "\t   Sync balances JSON structure ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Sync balances JSON structure ${RED}FAILED${NC}\n"
    printf "\t   Output: ${balancesStructureOutput}\n"
    failure=$(( failure + 1 ))
fi

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'sync operations'"
