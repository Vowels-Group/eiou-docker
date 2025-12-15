#!/bin/sh

# Test sync functionality from SynchService.php
# Tests the synch command for contacts, transactions, and balances
# Issue #207 - Balance sync functionality

echo -e "\nTesting sync functionality..."

testname="synchTest"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

############################ SYNCH HELP COMMAND ############################

echo -e "\n[Synch Command in Help Test]"

# Test 1: Verify synch command appears in help output (regular)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'synch' appears in help output (regular)"
helpOutput=$(docker exec ${testContainer} eiou help 2>&1)

if [[ "$helpOutput" =~ "synch" ]]; then
    printf "\t   synch in help (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   synch in help (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${helpOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 2: Verify synch command appears in help output (JSON)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'synch' appears in help output (JSON)"
helpJsonOutput=$(docker exec ${testContainer} eiou help --json 2>&1)

if [[ "$helpJsonOutput" =~ '"synch"' ]]; then
    printf "\t   synch in help (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   synch in help (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3: Verify help synch command shows specific info (regular)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'help synch' command (regular)"
helpSynchOutput=$(docker exec ${testContainer} eiou help synch 2>&1)

if [[ "$helpSynchOutput" =~ "synch" ]] && [[ "$helpSynchOutput" =~ "Synchronize" ]]; then
    printf "\t   help synch (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   help synch (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${helpSynchOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 4: Verify help synch command shows specific info (JSON)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'help synch' command (JSON)"
helpSynchJsonOutput=$(docker exec ${testContainer} eiou help synch --json 2>&1)

if [[ "$helpSynchJsonOutput" =~ '"success"' ]] && [[ "$helpSynchJsonOutput" =~ 'true' ]] && [[ "$helpSynchJsonOutput" =~ '"synch"' ]]; then
    printf "\t   help synch (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   help synch (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ SYNCH ALL COMMAND ############################

echo -e "\n[Synch All Command Test]"

# Test 5: synch (all) - regular output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'synch' command (all, regular output)"
synchAllOutput=$(docker exec ${testContainer} eiou synch 2>&1)

# Check for expected success message or output structure
if [[ "$synchAllOutput" =~ "Sync" ]] || [[ "$synchAllOutput" =~ "sync" ]] || [[ "$synchAllOutput" =~ "completed" ]] || [[ "$synchAllOutput" =~ "Synched" ]]; then
    printf "\t   synch all (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   synch all (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${synchAllOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 6: synch (all) - JSON output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'synch' command (all, JSON output)"
synchAllJsonOutput=$(docker exec ${testContainer} eiou synch --json 2>&1)

if [[ "$synchAllJsonOutput" =~ '"success"' ]] && [[ "$synchAllJsonOutput" =~ 'true' ]]; then
    printf "\t   synch all (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   synch all (JSON) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${synchAllJsonOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ SYNCH CONTACTS COMMAND ############################

echo -e "\n[Synch Contacts Command Test]"

# Test 7: synch contacts - regular output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'synch contacts' command (regular output)"
synchContactsOutput=$(docker exec ${testContainer} eiou synch contacts 2>&1)

if [[ "$synchContactsOutput" =~ "Contact" ]] || [[ "$synchContactsOutput" =~ "contact" ]] || [[ "$synchContactsOutput" =~ "sync" ]] || [[ "$synchContactsOutput" =~ "Sync" ]]; then
    printf "\t   synch contacts (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   synch contacts (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${synchContactsOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 8: synch contacts - JSON output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'synch contacts' command (JSON output)"
synchContactsJsonOutput=$(docker exec ${testContainer} eiou synch contacts --json 2>&1)

if [[ "$synchContactsJsonOutput" =~ '"success"' ]] && [[ "$synchContactsJsonOutput" =~ 'true' ]]; then
    printf "\t   synch contacts (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   synch contacts (JSON) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${synchContactsJsonOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ SYNCH TRANSACTIONS COMMAND ############################

echo -e "\n[Synch Transactions Command Test]"

# Test 9: synch transactions - regular output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'synch transactions' command (regular output)"
synchTransactionsOutput=$(docker exec ${testContainer} eiou synch transactions 2>&1)

if [[ "$synchTransactionsOutput" =~ "Transaction" ]] || [[ "$synchTransactionsOutput" =~ "transaction" ]] || [[ "$synchTransactionsOutput" =~ "sync" ]] || [[ "$synchTransactionsOutput" =~ "Sync" ]]; then
    printf "\t   synch transactions (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   synch transactions (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${synchTransactionsOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 10: synch transactions - JSON output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'synch transactions' command (JSON output)"
synchTransactionsJsonOutput=$(docker exec ${testContainer} eiou synch transactions --json 2>&1)

if [[ "$synchTransactionsJsonOutput" =~ '"success"' ]] && [[ "$synchTransactionsJsonOutput" =~ 'true' ]]; then
    printf "\t   synch transactions (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   synch transactions (JSON) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${synchTransactionsJsonOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ SYNCH BALANCES COMMAND ############################

echo -e "\n[Synch Balances Command Test]"

# Test 11: synch balances - regular output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'synch balances' command (regular output)"
synchBalancesOutput=$(docker exec ${testContainer} eiou synch balances 2>&1)

if [[ "$synchBalancesOutput" =~ "Balance" ]] || [[ "$synchBalancesOutput" =~ "balance" ]] || [[ "$synchBalancesOutput" =~ "sync" ]] || [[ "$synchBalancesOutput" =~ "Sync" ]]; then
    printf "\t   synch balances (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   synch balances (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${synchBalancesOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 12: synch balances - JSON output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'synch balances' command (JSON output)"
synchBalancesJsonOutput=$(docker exec ${testContainer} eiou synch balances --json 2>&1)

if [[ "$synchBalancesJsonOutput" =~ '"success"' ]] && [[ "$synchBalancesJsonOutput" =~ 'true' ]]; then
    printf "\t   synch balances (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   synch balances (JSON) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${synchBalancesJsonOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ SYNCH INVALID TYPE TEST ############################

echo -e "\n[Synch Invalid Type Test]"

# Test 13: synch with invalid type - regular output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'synch invalidtype' command (regular output)"
synchInvalidOutput=$(docker exec ${testContainer} eiou synch invalidtype 2>&1)

# Should return error message about invalid sync type
if [[ "$synchInvalidOutput" =~ "Invalid" ]] || [[ "$synchInvalidOutput" =~ "invalid" ]] || [[ "$synchInvalidOutput" =~ "error" ]] || [[ "$synchInvalidOutput" =~ "Error" ]]; then
    printf "\t   synch invalid type (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   synch invalid type (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${synchInvalidOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 14: synch with invalid type - JSON output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'synch invalidtype' command (JSON output)"
synchInvalidJsonOutput=$(docker exec ${testContainer} eiou synch invalidtype --json 2>&1)

# Should return JSON error response with success: false
if [[ "$synchInvalidJsonOutput" =~ '"success":false' ]] || [[ "$synchInvalidJsonOutput" =~ '"success": false' ]]; then
    printf "\t   synch invalid type (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   synch invalid type (JSON) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${synchInvalidJsonOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ MULTI-CONTAINER SYNCH TEST ############################

echo -e "\n[Multi-Container Synch Test]"

# Test synch balances on all containers to verify consistency
for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing 'synch balances --json' on ${container}"

    containerSynchJson=$(docker exec ${container} eiou synch balances --json 2>&1)

    # Check JSON structure is consistent across all containers
    if [[ "$containerSynchJson" =~ '"success"' ]] && [[ "$containerSynchJson" =~ 'true' ]]; then
        printf "\t   synch balances on %s ${GREEN}PASSED${NC}\n" ${container}
        passed=$(( passed + 1 ))
    else
        printf "\t   synch balances on %s ${RED}FAILED${NC}\n" ${container}
        printf "\t   Output: ${containerSynchJson}\n"
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

    # Now run synch balances and verify it works
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing balance sync after transaction"

    synchVerifyOutput=$(docker exec ${sender} eiou synch balances --json 2>&1)

    if [[ "$synchVerifyOutput" =~ '"success"' ]] && [[ "$synchVerifyOutput" =~ 'true' ]] && [[ "$synchVerifyOutput" =~ '"synced"' ]]; then
        printf "\t   Balance sync verification ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Balance sync verification ${RED}FAILED${NC}\n"
        printf "\t   Output: ${synchVerifyOutput}\n"
        failure=$(( failure + 1 ))
    fi

    # Verify receiver also syncs correctly
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing balance sync on receiver ${receiver}"

    receiverSynchOutput=$(docker exec ${receiver} eiou synch balances --json 2>&1)

    if [[ "$receiverSynchOutput" =~ '"success"' ]] && [[ "$receiverSynchOutput" =~ 'true' ]]; then
        printf "\t   Receiver balance sync ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Receiver balance sync ${RED}FAILED${NC}\n"
        printf "\t   Output: ${receiverSynchOutput}\n"
        failure=$(( failure + 1 ))
    fi
fi

############################ JSON STRUCTURE VALIDATION ############################

echo -e "\n[Synch JSON Structure Validation Test]"

# Test: Validate synch JSON output has required metadata fields
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing synch JSON output structure (metadata fields)"
jsonStructureOutput=$(docker exec ${testContainer} eiou synch --json 2>&1)

# Check for required JSON metadata fields
if [[ "$jsonStructureOutput" =~ '"success"' ]] && [[ "$jsonStructureOutput" =~ '"command"' ]] && [[ "$jsonStructureOutput" =~ '"timestamp"' ]]; then
    printf "\t   Synch JSON metadata structure ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Synch JSON metadata structure ${RED}FAILED${NC}\n"
    printf "\t   Output: ${jsonStructureOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: Validate synch balances JSON has expected data structure
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing synch balances JSON data structure"
balancesStructureOutput=$(docker exec ${testContainer} eiou synch balances --json 2>&1)

# Check for expected data fields in balance sync response
if [[ "$balancesStructureOutput" =~ '"total_contacts"' ]] || [[ "$balancesStructureOutput" =~ '"synced"' ]] || [[ "$balancesStructureOutput" =~ '"data"' ]]; then
    printf "\t   Synch balances JSON structure ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Synch balances JSON structure ${RED}FAILED${NC}\n"
    printf "\t   Output: ${balancesStructureOutput}\n"
    failure=$(( failure + 1 ))
fi

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'sync operations'"
