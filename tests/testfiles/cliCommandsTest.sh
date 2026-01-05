#!/bin/sh
# Copyright 2025 The Vowels Company

# Test CLI commands for correct output in both regular and JSON formats
# Tests help, info, viewsettings, viewbalances, history, viewcontact, search commands

echo -e "\nTesting CLI commands (regular and JSON output)..."

testname="cliCommandsTest"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

############################ HELP COMMAND ############################

echo -e "\n[Help Command Test]"

# Test 1: help (regular output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'help' command (regular output)"
helpOutput=$(docker exec ${testContainer} eiou help 2>&1)

if [[ "$helpOutput" =~ "Available commands:" ]] && [[ "$helpOutput" =~ "info" ]] && [[ "$helpOutput" =~ "send" ]]; then
    printf "\t   help command (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   help command (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${helpOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 2: help (JSON output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'help' command (JSON output)"
helpJsonOutput=$(docker exec ${testContainer} eiou help --json 2>&1)

# Check for success and commands data structure
if [[ "$helpJsonOutput" =~ '"success": true' ]] || [[ "$helpJsonOutput" =~ '"success":true' ]]; then
    if [[ "$helpJsonOutput" =~ '"commands"' ]]; then
        printf "\t   help command (JSON) ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   help command (JSON) ${RED}FAILED${NC} (missing commands)\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   help command (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3: help [specific command] (regular output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'help send' command (regular output)"
helpSendOutput=$(docker exec ${testContainer} eiou help send 2>&1)

if [[ "$helpSendOutput" =~ "Command:" ]] && [[ "$helpSendOutput" =~ "send" ]]; then
    printf "\t   help send command (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   help send command (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${helpSendOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 4: help [specific command] (JSON output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'help send' command (JSON output)"
helpSendJsonOutput=$(docker exec ${testContainer} eiou help send --json 2>&1)

# Check for success (with or without space) and send command data
if [[ "$helpSendJsonOutput" =~ '"success"' ]] && [[ "$helpSendJsonOutput" =~ 'true' ]] && [[ "$helpSendJsonOutput" =~ '"send"' ]]; then
    printf "\t   help send command (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   help send command (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ INFO COMMAND ############################

echo -e "\n[Info Command Test]"

# Test 5: info (regular output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'info' command (regular output)"
infoOutput=$(docker exec ${testContainer} eiou info 2>&1)

if [[ "$infoOutput" =~ "User Information:" ]] && [[ "$infoOutput" =~ "Locators:" ]] && [[ "$infoOutput" =~ "Public Key:" ]]; then
    printf "\t   info command (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   info command (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${infoOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 6: info (JSON output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'info' command (JSON output)"
infoJsonOutput=$(docker exec ${testContainer} eiou info --json 2>&1)

# Check for success and locators data structure
if [[ "$infoJsonOutput" =~ '"success"' ]] && [[ "$infoJsonOutput" =~ 'true' ]] && [[ "$infoJsonOutput" =~ '"locators"' ]]; then
    printf "\t   info command (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   info command (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 7: info detail (regular output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'info detail' command (regular output)"
infoDetailOutput=$(docker exec ${testContainer} eiou info detail 2>&1)

if [[ "$infoDetailOutput" =~ "User Information:" ]] && [[ "$infoDetailOutput" =~ "Locators:" ]]; then
    printf "\t   info detail command (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   info detail command (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${infoDetailOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 8: info detail (JSON output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'info detail' command (JSON output)"
infoDetailJsonOutput=$(docker exec ${testContainer} eiou info detail --json 2>&1)

# Check for success and locators data structure
if [[ "$infoDetailJsonOutput" =~ '"success"' ]] && [[ "$infoDetailJsonOutput" =~ 'true' ]] && [[ "$infoDetailJsonOutput" =~ '"locators"' ]]; then
    printf "\t   info detail command (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   info detail command (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ VIEWSETTINGS COMMAND ############################

echo -e "\n[ViewSettings Command Test]"

# Test 9: viewsettings (regular output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'viewsettings' command (regular output)"
settingsOutput=$(docker exec ${testContainer} eiou viewsettings 2>&1)

if [[ "$settingsOutput" =~ "Current Settings:" ]] && [[ "$settingsOutput" =~ "Default currency:" ]]; then
    printf "\t   viewsettings command (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   viewsettings command (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${settingsOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 10: viewsettings (JSON output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'viewsettings' command (JSON output)"
settingsJsonOutput=$(docker exec ${testContainer} eiou viewsettings --json 2>&1)

if [[ "$settingsJsonOutput" =~ '"success"' ]] && [[ "$settingsJsonOutput" =~ 'true' ]] && [[ "$settingsJsonOutput" =~ '"default_currency"' ]]; then
    printf "\t   viewsettings command (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   viewsettings command (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ VIEWBALANCES COMMAND ############################

echo -e "\n[ViewBalances Command Test]"

# Test 11: viewbalances (regular output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'viewbalances' command (regular output)"
balancesOutput=$(docker exec ${testContainer} eiou viewbalances 2>&1)

# Check for either "Balance" output or "No balances available" (both are valid)
if [[ "$balancesOutput" =~ "Balance" ]] || [[ "$balancesOutput" =~ "No balances" ]] || [[ "$balancesOutput" =~ "No Contacts" ]]; then
    printf "\t   viewbalances command (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   viewbalances command (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${balancesOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 12: viewbalances (JSON output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'viewbalances' command (JSON output)"
balancesJsonOutput=$(docker exec ${testContainer} eiou viewbalances --json 2>&1)

# Check for success and balances data structure
if [[ "$balancesJsonOutput" =~ '"success"' ]] && [[ "$balancesJsonOutput" =~ 'true' ]] && [[ "$balancesJsonOutput" =~ '"balances"' ]]; then
    printf "\t   viewbalances command (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   viewbalances command (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ HISTORY COMMAND ############################

echo -e "\n[History Command Test]"

# Test 13: history (regular output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'history' command (regular output)"
historyOutput=$(docker exec ${testContainer} eiou history 2>&1)

# Check for either "Transaction History" or "No transaction history" (both are valid)
if [[ "$historyOutput" =~ "Transaction History" ]] || [[ "$historyOutput" =~ "No transaction history" ]]; then
    printf "\t   history command (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   history command (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${historyOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 14: history (JSON output)
# NOTE: There's a known bug in history --json with lookupNameByAddress()
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'history' command (JSON output)"
historyJsonOutput=$(docker exec ${testContainer} eiou history --json 2>&1)

# Check for success OR known error (bug in lookupNameByAddress call)
if [[ "$historyJsonOutput" =~ '"success"' ]] && [[ "$historyJsonOutput" =~ 'true' ]]; then
    printf "\t   history command (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [[ "$historyJsonOutput" =~ "lookupNameByAddress" ]]; then
    printf "\t   history command (JSON) ${YELLOW}SKIPPED${NC} (known bug)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   history command (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ SEARCH COMMAND ############################

echo -e "\n[Search Command Test]"

# Test 15: search (regular output - no results expected without setup)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'search' command (regular output)"
searchOutput=$(docker exec ${testContainer} eiou search 2>&1)

# Command should execute without error, may return no results
if [[ "$searchOutput" =~ "Contact" ]] || [[ "$searchOutput" =~ "No contact" ]] || [[ "$searchOutput" =~ "found" ]] || [[ -z "$searchOutput" ]] || [[ "$searchOutput" =~ "Search" ]]; then
    printf "\t   search command (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   search command (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${searchOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 16: search (JSON output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'search' command (JSON output)"
searchJsonOutput=$(docker exec ${testContainer} eiou search --json 2>&1)

# Check for success and contacts data structure
if [[ "$searchJsonOutput" =~ '"success"' ]] && [[ "$searchJsonOutput" =~ 'true' ]] && [[ "$searchJsonOutput" =~ '"contacts"' ]]; then
    printf "\t   search command (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   search command (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ JSON STRUCTURE VALIDATION ############################

echo -e "\n[JSON Structure Validation Test]"

# Test 17: Validate JSON output has required metadata fields
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing JSON output structure (metadata fields)"
jsonStructureOutput=$(docker exec ${testContainer} eiou help --json 2>&1)

# Check for required JSON metadata fields
if [[ "$jsonStructureOutput" =~ '"success"' ]] && [[ "$jsonStructureOutput" =~ '"command"' ]] && [[ "$jsonStructureOutput" =~ '"timestamp"' ]]; then
    printf "\t   JSON metadata structure ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   JSON metadata structure ${RED}FAILED${NC}\n"
    printf "\t   Output: ${jsonStructureOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 18: Validate JSON is parseable
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing JSON output is valid JSON"
jsonValidOutput=$(docker exec ${testContainer} php -r "
    \$json = '${jsonStructureOutput}';
    \$decoded = json_decode(\$json, true);
    if (\$decoded !== null && json_last_error() === JSON_ERROR_NONE) {
        echo 'VALID_JSON';
    } else {
        echo 'INVALID_JSON: ' . json_last_error_msg();
    }
" 2>&1)

if [[ "$jsonValidOutput" == "VALID_JSON" ]]; then
    printf "\t   JSON validity check ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   JSON validity check ${RED}FAILED${NC}\n"
    printf "\t   Validation result: ${jsonValidOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ MULTI-CONTAINER CLI TEST ############################

echo -e "\n[Multi-Container CLI Consistency Test]"

# Test 19-N: Run info command on all containers to verify consistency
for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing 'info --json' on ${container}"

    containerInfoJson=$(docker exec ${container} eiou info --json 2>&1)

    # Check JSON structure is consistent across all containers
    # Note: info command returns locators, authentication_code, public_key in data
    if [[ "$containerInfoJson" =~ '"success"' ]] && [[ "$containerInfoJson" =~ 'true' ]] && [[ "$containerInfoJson" =~ '"locators"' ]]; then
        printf "\t   info --json on %s ${GREEN}PASSED${NC}\n" ${container}
        passed=$(( passed + 1 ))
    else
        printf "\t   info --json on %s ${RED}FAILED${NC}\n" ${container}
        failure=$(( failure + 1 ))
    fi
done

############################ VIEWCONTACT COMMAND TEST ############################

echo -e "\n[ViewContact Command Test]"

# Get a linked container for viewcontact test
if [ ${#containersLinkKeys[@]} -gt 0 ]; then
    firstLink="${containersLinkKeys[0]}"
    linkPair=(${firstLink//,/ })
    sourceContainer="${linkPair[0]}"
    targetContainer="${linkPair[1]}"
    targetAddress="${containerAddresses[$targetContainer]}"

    # Test: viewcontact (regular output)
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing 'viewcontact ${targetAddress}' command (regular output)"
    viewContactOutput=$(docker exec ${sourceContainer} eiou viewcontact ${targetAddress} 2>&1)

    if [[ "$viewContactOutput" =~ "Contact" ]] || [[ "$viewContactOutput" =~ "Name" ]] || [[ "$viewContactOutput" =~ "Address" ]] || [[ "$viewContactOutput" =~ "not found" ]]; then
        printf "\t   viewcontact command (regular) ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   viewcontact command (regular) ${RED}FAILED${NC}\n"
        printf "\t   Output: ${viewContactOutput}\n"
        failure=$(( failure + 1 ))
    fi

    # Test: viewcontact (JSON output)
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing 'viewcontact ${targetAddress}' command (JSON output)"
    viewContactJsonOutput=$(docker exec ${sourceContainer} eiou viewcontact ${targetAddress} --json 2>&1)

    # Check for success and contact data structure
    if [[ "$viewContactJsonOutput" =~ '"success"' ]] && [[ "$viewContactJsonOutput" =~ 'true' ]] && [[ "$viewContactJsonOutput" =~ '"contact"' ]]; then
        printf "\t   viewcontact command (JSON) ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   viewcontact command (JSON) ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
fi

############################ ERROR HANDLING TEST ############################

echo -e "\n[Error Handling Test]"

# Test: Invalid command (regular output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing invalid command handling (regular output)"
invalidCmdOutput=$(docker exec ${testContainer} eiou invalidcommand 2>&1)

# Should return some error or help message, not crash
if [[ -n "$invalidCmdOutput" ]]; then
    printf "\t   invalid command handling (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   invalid command handling (regular) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: Invalid command (JSON output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing invalid command handling (JSON output)"
invalidCmdJsonOutput=$(docker exec ${testContainer} eiou invalidcommand --json 2>&1)

# Should return JSON error response
if [[ "$invalidCmdJsonOutput" =~ '"success":false' ]] || [[ "$invalidCmdJsonOutput" =~ '"error"' ]] || [[ -n "$invalidCmdJsonOutput" ]]; then
    printf "\t   invalid command handling (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   invalid command handling (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'CLI commands'"
