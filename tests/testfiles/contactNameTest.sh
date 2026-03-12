#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

######################### Contact Name Test #########################
# Tests multi-part contact names and duplicate name disambiguation
#
# Verifies:
# - Contact names with spaces can be stored and retrieved
# - Name validation rejects invalid characters on update
# - Duplicate name detection returns multiple_matches error in JSON mode
#
# Prerequisites:
# - Containers must be running
# - Contacts must be established (run addContactsTest first)
#####################################################################

echo -e "\nTesting multi-part contact names and disambiguation..."

testname="contactNameTest"
totaltests=0
passed=0
failure=0

# Use first two containers from the topology
testContainer="${containers[0]}"
testContainer2="${containers[1]}"

# Get the first container link for address reference
firstLinkKey=$(for x in ${!containersLinks[@]}; do echo $x; done | sort | head -1)
linkContainers=(${firstLinkKey//,/ })
sender="${linkContainers[0]}"
receiver="${linkContainers[1]}"
receiverAddress="${containerAddresses[${receiver}]}"

# Test 1: Update contact name to multi-word name
echo -e "\n[Multi-word Contact Name Update]"
totaltests=$(( totaltests + 1 ))

echo -e "\t-> Updating contact name to 'Test User' on ${sender}"
updateOutput=$(docker exec ${sender} eiou update ${receiverAddress} name "Test User" --json 2>&1)

if echo "$updateOutput" | grep -q '"success"'; then
    printf "\t   Multi-word name update ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Multi-word name update ${RED}FAILED${NC}\n"
    printf "\t   Output: %s\n" "$updateOutput"
    failure=$(( failure + 1 ))
fi

# Test 2: Search for multi-word name
echo -e "\n[Multi-word Contact Name Search]"
totaltests=$(( totaltests + 1 ))

echo -e "\t-> Searching for 'Test User' on ${sender}"
searchOutput=$(docker exec ${sender} eiou search "Test User" --json 2>&1)

if echo "$searchOutput" | grep -q '"Test User"'; then
    printf "\t   Multi-word name search ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Multi-word name search ${RED}FAILED${NC}\n"
    printf "\t   Output: %s\n" "$searchOutput"
    failure=$(( failure + 1 ))
fi

# Test 3: Name validation rejects invalid characters on update
echo -e "\n[Name Validation on Update]"
totaltests=$(( totaltests + 1 ))

echo -e "\t-> Attempting invalid name update on ${sender}"
invalidOutput=$(docker exec ${sender} eiou update ${receiverAddress} name '!!invalid<>!!' --json 2>&1)

if echo "$invalidOutput" | grep -q '"error"' && echo "$invalidOutput" | grep -q 'INVALID_NAME'; then
    printf "\t   Invalid name rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Invalid name rejection ${RED}FAILED${NC}\n"
    printf "\t   Output: %s\n" "$invalidOutput"
    failure=$(( failure + 1 ))
fi

# Test 4: Duplicate name detection in JSON mode
# This test creates a duplicate name scenario by updating two contacts to the same name
echo -e "\n[Duplicate Name Detection]"

# Find a container that has 2+ accepted contacts (needed for duplicate name test)
dupTestSender=""
dupContact1Address=""
dupContact2Address=""
dupContact1Name=""
dupContact2Name=""

for candidateContainer in "${containers[@]}"; do
    # Get all accepted contact addresses for this container
    contactList=$(docker exec ${candidateContainer} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$repo = \Eiou\Core\Application::getInstance()->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class);
        \$addresses = \$repo->getAllSingleAcceptedAddresses('${MODE}');
        echo implode(',', \$addresses);
    " 2>/dev/null || echo "")

    if [[ -n "$contactList" ]]; then
        IFS=',' read -ra contactAddrs <<< "$contactList"
        if [[ ${#contactAddrs[@]} -ge 2 ]]; then
            dupTestSender="${candidateContainer}"
            dupContact1Address="${contactAddrs[0]}"
            dupContact2Address="${contactAddrs[1]}"
            break
        fi
    fi
done

if [[ -n "$dupTestSender" ]]; then
    totaltests=$(( totaltests + 1 ))

    echo -e "\t-> Using ${dupTestSender} as sender (has 2+ contacts)"

    # Save original names so we can restore them
    dupContact1Name=$(docker exec ${dupTestSender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$repo = \Eiou\Core\Application::getInstance()->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class);
        \$contact = \$repo->lookupByAddress('${MODE}', '${dupContact1Address}');
        echo \$contact ? \$contact->getName() : '';
    " 2>/dev/null || echo "")

    dupContact2Name=$(docker exec ${dupTestSender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$repo = \Eiou\Core\Application::getInstance()->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class);
        \$contact = \$repo->lookupByAddress('${MODE}', '${dupContact2Address}');
        echo \$contact ? \$contact->getName() : '';
    " 2>/dev/null || echo "")

    # Set both contacts to the same name "Test User"
    echo -e "\t-> Setting duplicate name 'Test User' on ${dupTestSender} for two contacts"
    docker exec ${dupTestSender} eiou update ${dupContact1Address} name "Test User" --json 2>&1 > /dev/null
    docker exec ${dupTestSender} eiou update ${dupContact2Address} name "Test User" --json 2>&1 > /dev/null

    # Now try to send to "Test User" in JSON mode - should get multiple_matches error
    echo -e "\t-> Sending to duplicate name 'Test User' in JSON mode"
    sendOutput=$(docker exec -e EIOU_TEST_MODE=true ${dupTestSender} eiou send "Test User" 1 USD --json 2>&1)

    if echo "$sendOutput" | grep -q 'MULTIPLE_MATCHES' || echo "$sendOutput" | grep -q 'multiple_matches'; then
        printf "\t   Duplicate name detection ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Duplicate name detection ${RED}FAILED${NC}\n"
        printf "\t   Output: %s\n" "$sendOutput"
        failure=$(( failure + 1 ))
    fi

    # Restore original names to avoid interfering with other tests
    echo -e "\t-> Restoring original contact names..."
    docker exec ${dupTestSender} eiou update ${dupContact1Address} name "${dupContact1Name}" --json 2>&1 > /dev/null
    docker exec ${dupTestSender} eiou update ${dupContact2Address} name "${dupContact2Name}" --json 2>&1 > /dev/null
else
    echo -e "\t   Skipping duplicate name test - no container found with 2+ accepted contacts"
    # Don't count as a test if we can't run it
fi

# Restore original name from Test 1
echo -e "\n[Cleanup]"
echo -e "\t-> Restoring contact name on ${sender}"
docker exec ${sender} eiou update ${receiverAddress} name "${receiver}" --json 2>&1 > /dev/null

succesrate "${totaltests}" "${passed}" "${failure}" "'contact name'"
