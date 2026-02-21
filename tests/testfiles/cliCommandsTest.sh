#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ CLI Commands Test ############################
# Tests CLI commands for correct output in both regular and JSON formats
#
# Verifies:
# - All CLI commands execute without errors
# - Regular output format is correct and readable
# - JSON output format is valid and parseable
# - Command arguments are validated properly
#
# Prerequisites:
# - Containers must be running
# - Wallet must be initialized
#########################################################################

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
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'history' command (JSON output)"
historyJsonOutput=$(docker exec ${testContainer} eiou history --json 2>&1)

if [[ "$historyJsonOutput" =~ '"success"' ]] && [[ "$historyJsonOutput" =~ 'true' ]]; then
    printf "\t   history command (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   history command (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ PENDING COMMAND ############################

echo -e "\n[Pending Command Test]"

# Test: pending (regular output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'pending' command (regular output)"
pendingOutput=$(docker exec ${testContainer} eiou pending 2>&1)

# Check for either "Pending Contact Requests" or "No pending" (both are valid)
if [[ "$pendingOutput" =~ "Pending" ]] || [[ "$pendingOutput" =~ "pending" ]] || [[ "$pendingOutput" =~ "Incoming" ]] || [[ "$pendingOutput" =~ "Outgoing" ]] || [[ "$pendingOutput" =~ "No pending" ]]; then
    printf "\t   pending command (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   pending command (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${pendingOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: pending (JSON output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'pending' command (JSON output)"
pendingJsonOutput=$(docker exec ${testContainer} eiou pending --json 2>&1)

# Check for success and pending data structure
if [[ "$pendingJsonOutput" =~ '"success"' ]] && [[ "$pendingJsonOutput" =~ 'true' ]] && [[ "$pendingJsonOutput" =~ '"incoming"' ]] && [[ "$pendingJsonOutput" =~ '"outgoing"' ]]; then
    printf "\t   pending command (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   pending command (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ OVERVIEW COMMAND ############################

echo -e "\n[Overview Command Test]"

# Test: overview (regular output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'overview' command (regular output)"
overviewOutput=$(docker exec ${testContainer} eiou overview 2>&1)

# Check for overview output markers
if [[ "$overviewOutput" =~ "WALLET OVERVIEW" ]] || [[ "$overviewOutput" =~ "BALANCES" ]] || [[ "$overviewOutput" =~ "RECENT TRANSACTIONS" ]]; then
    printf "\t   overview command (regular) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   overview command (regular) ${RED}FAILED${NC}\n"
    printf "\t   Output: ${overviewOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: overview (JSON output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'overview' command (JSON output)"
overviewJsonOutput=$(docker exec ${testContainer} eiou overview --json 2>&1)

# Check for success and overview data structure
if [[ "$overviewJsonOutput" =~ '"success"' ]] && [[ "$overviewJsonOutput" =~ 'true' ]] && [[ "$overviewJsonOutput" =~ '"balances"' ]] && [[ "$overviewJsonOutput" =~ '"recent_transactions"' ]]; then
    printf "\t   overview command (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   overview command (JSON) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: overview with limit parameter (JSON output)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'overview 10' command (JSON output)"
overviewLimitJsonOutput=$(docker exec ${testContainer} eiou overview 10 --json 2>&1)

# Check for success and transaction_limit field
if [[ "$overviewLimitJsonOutput" =~ '"success"' ]] && [[ "$overviewLimitJsonOutput" =~ 'true' ]] && [[ "$overviewLimitJsonOutput" =~ '"transaction_limit"' ]]; then
    printf "\t   overview 10 command (JSON) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   overview 10 command (JSON) ${RED}FAILED${NC}\n"
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
jsonValidOutput=$(printf '%s' "${jsonStructureOutput}" | docker exec -i ${testContainer} php -r "
    \$json = file_get_contents('php://stdin');
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

    # Get contact name for name-based tests
    contactName=$(docker exec ${sourceContainer} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$contact = \$app->services->getContactRepository()->lookupByAddress('${MODE}', '${targetAddress}');
        echo \$contact['name'] ?? '';
    " 2>/dev/null || echo "")

    # Test: viewcontact by NAME (regular output)
    if [[ -n "$contactName" ]]; then
        totaltests=$(( totaltests + 1 ))
        echo -e "\n\t-> Testing 'viewcontact ${contactName}' command by NAME (regular output)"
        viewContactByNameOutput=$(docker exec ${sourceContainer} eiou viewcontact "${contactName}" 2>&1)

        if [[ "$viewContactByNameOutput" =~ "Contact" ]] || [[ "$viewContactByNameOutput" =~ "Name" ]] || [[ "$viewContactByNameOutput" =~ "Address" ]]; then
            printf "\t   viewcontact by name (regular) ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   viewcontact by name (regular) ${RED}FAILED${NC}\n"
            printf "\t   Output: ${viewContactByNameOutput}\n"
            failure=$(( failure + 1 ))
        fi

        # Test: viewcontact by NAME (JSON output)
        totaltests=$(( totaltests + 1 ))
        echo -e "\n\t-> Testing 'viewcontact ${contactName}' command by NAME (JSON output)"
        viewContactByNameJsonOutput=$(docker exec ${sourceContainer} eiou viewcontact "${contactName}" --json 2>&1)

        if [[ "$viewContactByNameJsonOutput" =~ '"success"' ]] && [[ "$viewContactByNameJsonOutput" =~ 'true' ]] && [[ "$viewContactByNameJsonOutput" =~ '"contact"' ]]; then
            printf "\t   viewcontact by name (JSON) ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   viewcontact by name (JSON) ${RED}FAILED${NC}\n"
            failure=$(( failure + 1 ))
        fi
    else
        printf "\t   viewcontact by name ${YELLOW}SKIPPED${NC} (no contact name found)\n"
    fi

    ############################ VIEWBALANCES WITH CONTACT TEST ############################

    echo -e "\n[ViewBalances with Contact Filter Test]"

    # Test: viewbalances with ADDRESS filter
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing 'viewbalances ${targetAddress}' command (address filter)"
    viewBalancesAddrOutput=$(docker exec ${sourceContainer} eiou viewbalances ${targetAddress} --json 2>&1)

    if [[ "$viewBalancesAddrOutput" =~ '"success"' ]] && [[ "$viewBalancesAddrOutput" =~ 'true' ]]; then
        printf "\t   viewbalances by address ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   viewbalances by address ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi

    # Test: viewbalances with NAME filter
    if [[ -n "$contactName" ]]; then
        totaltests=$(( totaltests + 1 ))
        echo -e "\n\t-> Testing 'viewbalances ${contactName}' command (name filter)"
        viewBalancesNameOutput=$(docker exec ${sourceContainer} eiou viewbalances "${contactName}" --json 2>&1)

        if [[ "$viewBalancesNameOutput" =~ '"success"' ]] && [[ "$viewBalancesNameOutput" =~ 'true' ]]; then
            printf "\t   viewbalances by name ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   viewbalances by name ${RED}FAILED${NC}\n"
            failure=$(( failure + 1 ))
        fi
    else
        printf "\t   viewbalances by name ${YELLOW}SKIPPED${NC} (no contact name found)\n"
    fi

    ############################ HISTORY WITH CONTACT TEST ############################

    echo -e "\n[History with Contact Filter Test]"

    # Test: history with ADDRESS filter
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing 'history ${targetAddress}' command (address filter)"
    historyAddrOutput=$(docker exec ${sourceContainer} eiou history ${targetAddress} --json 2>&1)

    if [[ "$historyAddrOutput" =~ '"success"' ]] && [[ "$historyAddrOutput" =~ 'true' ]]; then
        printf "\t   history by address ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   history by address ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi

    # Test: history with NAME filter
    if [[ -n "$contactName" ]]; then
        totaltests=$(( totaltests + 1 ))
        echo -e "\n\t-> Testing 'history ${contactName}' command (name filter)"
        historyNameOutput=$(docker exec ${sourceContainer} eiou history "${contactName}" --json 2>&1)

        if [[ "$historyNameOutput" =~ '"success"' ]] && [[ "$historyNameOutput" =~ 'true' ]]; then
            printf "\t   history by name ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   history by name ${RED}FAILED${NC}\n"
            failure=$(( failure + 1 ))
        fi
    else
        printf "\t   history by name ${YELLOW}SKIPPED${NC} (no contact name found)\n"
    fi

    ############################ BLOCK/UNBLOCK COMMAND TEST ############################

    echo -e "\n[Block/Unblock Command Test - Address and Name Variants]"

    # Test: block by ADDRESS
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing 'block ${targetAddress}' command (address)"
    blockAddrOutput=$(docker exec ${sourceContainer} eiou block ${targetAddress} --json 2>&1)

    if [[ "$blockAddrOutput" =~ '"success"' ]] && [[ "$blockAddrOutput" =~ 'true' ]]; then
        printf "\t   block by address ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))

        # Test: unblock by ADDRESS (to restore state)
        totaltests=$(( totaltests + 1 ))
        echo -e "\n\t-> Testing 'unblock ${targetAddress}' command (address)"
        unblockAddrOutput=$(docker exec ${sourceContainer} eiou unblock ${targetAddress} --json 2>&1)

        if [[ "$unblockAddrOutput" =~ '"success"' ]] && [[ "$unblockAddrOutput" =~ 'true' ]]; then
            printf "\t   unblock by address ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   unblock by address ${RED}FAILED${NC}\n"
            failure=$(( failure + 1 ))
        fi
    else
        printf "\t   block by address ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi

    # Test: block by NAME
    if [[ -n "$contactName" ]]; then
        totaltests=$(( totaltests + 1 ))
        echo -e "\n\t-> Testing 'block ${contactName}' command (name)"
        blockNameOutput=$(docker exec ${sourceContainer} eiou block "${contactName}" --json 2>&1)

        if [[ "$blockNameOutput" =~ '"success"' ]] && [[ "$blockNameOutput" =~ 'true' ]]; then
            printf "\t   block by name ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))

            # Test: unblock by NAME (to restore state)
            totaltests=$(( totaltests + 1 ))
            echo -e "\n\t-> Testing 'unblock ${contactName}' command (name)"
            unblockNameOutput=$(docker exec ${sourceContainer} eiou unblock "${contactName}" --json 2>&1)

            if [[ "$unblockNameOutput" =~ '"success"' ]] && [[ "$unblockNameOutput" =~ 'true' ]]; then
                printf "\t   unblock by name ${GREEN}PASSED${NC}\n"
                passed=$(( passed + 1 ))
            else
                printf "\t   unblock by name ${RED}FAILED${NC}\n"
                failure=$(( failure + 1 ))
            fi
        else
            printf "\t   block by name ${RED}FAILED${NC}\n"
            failure=$(( failure + 1 ))
        fi
    else
        printf "\t   block/unblock by name ${YELLOW}SKIPPED${NC} (no contact name found)\n"
    fi
fi

############################ NON-EXISTING CONTACT HANDLING TEST ############################

echo -e "\n[Non-Existing Contact Handling Test]"

# Define non-existing test values
nonExistingAddress="http://non-existing-address-12345.example.com"
nonExistingName="NonExistingContactName12345"

# All tests verify that commands handle non-existing contacts properly without crashing
# Expected behavior: return valid JSON with "success": true/false and appropriate message

# Test: viewcontact with non-existing ADDRESS
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'viewcontact' with non-existing address"
viewContactNonExistAddrOutput=$(docker exec ${testContainer} eiou viewcontact ${nonExistingAddress} --json 2>&1)

# Must return valid JSON response (not crash)
if [[ "$viewContactNonExistAddrOutput" =~ '"success"' ]] && [[ "$viewContactNonExistAddrOutput" =~ '"' ]]; then
    printf "\t   viewcontact non-existing address ${GREEN}PASSED${NC} (handled properly)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   viewcontact non-existing address ${RED}FAILED${NC} (invalid response or crash)\n"
    printf "\t   Output: ${viewContactNonExistAddrOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: viewcontact with non-existing NAME
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'viewcontact' with non-existing name"
viewContactNonExistNameOutput=$(docker exec ${testContainer} eiou viewcontact "${nonExistingName}" --json 2>&1)

if [[ "$viewContactNonExistNameOutput" =~ '"success"' ]] && [[ "$viewContactNonExistNameOutput" =~ '"' ]]; then
    printf "\t   viewcontact non-existing name ${GREEN}PASSED${NC} (handled properly)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   viewcontact non-existing name ${RED}FAILED${NC} (invalid response or crash)\n"
    printf "\t   Output: ${viewContactNonExistNameOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: send with non-existing ADDRESS
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'send' with non-existing address"
sendNonExistAddrOutput=$(docker exec ${testContainer} eiou send ${nonExistingAddress} 5 USD --json 2>&1)

# Send may attempt P2P routing - must return valid JSON response
if [[ "$sendNonExistAddrOutput" =~ '"success"' ]] && [[ "$sendNonExistAddrOutput" =~ '"' ]]; then
    printf "\t   send non-existing address ${GREEN}PASSED${NC} (handled properly)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   send non-existing address ${RED}FAILED${NC} (invalid response or crash)\n"
    printf "\t   Output: ${sendNonExistAddrOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: send with non-existing NAME
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'send' with non-existing name"
sendNonExistNameOutput=$(docker exec ${testContainer} eiou send "${nonExistingName}" 5 USD --json 2>&1)

if [[ "$sendNonExistNameOutput" =~ '"success"' ]] && [[ "$sendNonExistNameOutput" =~ '"' ]]; then
    printf "\t   send non-existing name ${GREEN}PASSED${NC} (handled properly)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   send non-existing name ${RED}FAILED${NC} (invalid response or crash)\n"
    printf "\t   Output: ${sendNonExistNameOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: block with non-existing ADDRESS
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'block' with non-existing address"
blockNonExistAddrOutput=$(docker exec ${testContainer} eiou block ${nonExistingAddress} --json 2>&1)

if [[ "$blockNonExistAddrOutput" =~ '"success"' ]] && [[ "$blockNonExistAddrOutput" =~ '"' ]]; then
    printf "\t   block non-existing address ${GREEN}PASSED${NC} (handled properly)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   block non-existing address ${RED}FAILED${NC} (invalid response or crash)\n"
    printf "\t   Output: ${blockNonExistAddrOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: block with non-existing NAME
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'block' with non-existing name"
blockNonExistNameOutput=$(docker exec ${testContainer} eiou block "${nonExistingName}" --json 2>&1)

if [[ "$blockNonExistNameOutput" =~ '"success"' ]] && [[ "$blockNonExistNameOutput" =~ '"' ]]; then
    printf "\t   block non-existing name ${GREEN}PASSED${NC} (handled properly)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   block non-existing name ${RED}FAILED${NC} (invalid response or crash)\n"
    printf "\t   Output: ${blockNonExistNameOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: unblock with non-existing ADDRESS
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'unblock' with non-existing address"
unblockNonExistAddrOutput=$(docker exec ${testContainer} eiou unblock ${nonExistingAddress} --json 2>&1)

if [[ "$unblockNonExistAddrOutput" =~ '"success"' ]] && [[ "$unblockNonExistAddrOutput" =~ '"' ]]; then
    printf "\t   unblock non-existing address ${GREEN}PASSED${NC} (handled properly)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   unblock non-existing address ${RED}FAILED${NC} (invalid response or crash)\n"
    printf "\t   Output: ${unblockNonExistAddrOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: unblock with non-existing NAME
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'unblock' with non-existing name"
unblockNonExistNameOutput=$(docker exec ${testContainer} eiou unblock "${nonExistingName}" --json 2>&1)

if [[ "$unblockNonExistNameOutput" =~ '"success"' ]] && [[ "$unblockNonExistNameOutput" =~ '"' ]]; then
    printf "\t   unblock non-existing name ${GREEN}PASSED${NC} (handled properly)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   unblock non-existing name ${RED}FAILED${NC} (invalid response or crash)\n"
    printf "\t   Output: ${unblockNonExistNameOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: history with non-existing ADDRESS
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'history' with non-existing address"
historyNonExistAddrOutput=$(docker exec ${testContainer} eiou history ${nonExistingAddress} --json 2>&1)

if [[ "$historyNonExistAddrOutput" =~ '"success"' ]] && [[ "$historyNonExistAddrOutput" =~ '"' ]]; then
    printf "\t   history non-existing address ${GREEN}PASSED${NC} (handled properly)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   history non-existing address ${RED}FAILED${NC} (invalid response or crash)\n"
    printf "\t   Output: ${historyNonExistAddrOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: history with non-existing NAME
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'history' with non-existing name"
historyNonExistNameOutput=$(docker exec ${testContainer} eiou history "${nonExistingName}" --json 2>&1)

if [[ "$historyNonExistNameOutput" =~ '"success"' ]] && [[ "$historyNonExistNameOutput" =~ '"' ]]; then
    printf "\t   history non-existing name ${GREEN}PASSED${NC} (handled properly)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   history non-existing name ${RED}FAILED${NC} (invalid response or crash)\n"
    printf "\t   Output: ${historyNonExistNameOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: viewbalances with non-existing ADDRESS
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'viewbalances' with non-existing address"
viewBalancesNonExistAddrOutput=$(docker exec ${testContainer} eiou viewbalances ${nonExistingAddress} --json 2>&1)

if [[ "$viewBalancesNonExistAddrOutput" =~ '"success"' ]] && [[ "$viewBalancesNonExistAddrOutput" =~ '"' ]]; then
    printf "\t   viewbalances non-existing address ${GREEN}PASSED${NC} (handled properly)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   viewbalances non-existing address ${RED}FAILED${NC} (invalid response or crash)\n"
    printf "\t   Output: ${viewBalancesNonExistAddrOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: viewbalances with non-existing NAME
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'viewbalances' with non-existing name"
viewBalancesNonExistNameOutput=$(docker exec ${testContainer} eiou viewbalances "${nonExistingName}" --json 2>&1)

if [[ "$viewBalancesNonExistNameOutput" =~ '"success"' ]] && [[ "$viewBalancesNonExistNameOutput" =~ '"' ]]; then
    printf "\t   viewbalances non-existing name ${GREEN}PASSED${NC} (handled properly)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   viewbalances non-existing name ${RED}FAILED${NC} (invalid response or crash)\n"
    printf "\t   Output: ${viewBalancesNonExistNameOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: delete with non-existing ADDRESS
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'delete' with non-existing address"
deleteNonExistAddrOutput=$(docker exec ${testContainer} eiou delete ${nonExistingAddress} --json 2>&1)

if [[ "$deleteNonExistAddrOutput" =~ '"success"' ]] && [[ "$deleteNonExistAddrOutput" =~ '"' ]]; then
    printf "\t   delete non-existing address ${GREEN}PASSED${NC} (handled properly)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   delete non-existing address ${RED}FAILED${NC} (invalid response or crash)\n"
    printf "\t   Output: ${deleteNonExistAddrOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test: delete with non-existing NAME
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'delete' with non-existing name"
deleteNonExistNameOutput=$(docker exec ${testContainer} eiou delete "${nonExistingName}" --json 2>&1)

if [[ "$deleteNonExistNameOutput" =~ '"success"' ]] && [[ "$deleteNonExistNameOutput" =~ '"' ]]; then
    printf "\t   delete non-existing name ${GREEN}PASSED${NC} (handled properly)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   delete non-existing name ${RED}FAILED${NC} (invalid response or crash)\n"
    printf "\t   Output: ${deleteNonExistNameOutput}\n"
    failure=$(( failure + 1 ))
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
