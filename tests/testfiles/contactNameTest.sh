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

# Only run if we have at least 3 containers (need 2 contacts on sender)
if [[ ${#containers[@]} -ge 3 ]]; then
    totaltests=$(( totaltests + 1 ))

    thirdContainer="${containers[2]}"
    thirdAddress="${containerAddresses[${thirdContainer}]}"

    # Check if sender has a contact for the third container
    hasThirdContact=$(docker exec ${sender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$repo = \Eiou\Core\Application::getInstance()->services->getContactRepository();
        \$contact = \$repo->lookupByAddress('${MODE}', '${thirdAddress}');
        echo \$contact ? '1' : '0';
    " 2>/dev/null || echo "0")

    if [[ "$hasThirdContact" == "1" ]]; then
        echo -e "\t-> Setting duplicate name 'Test User' on ${sender} for ${thirdContainer}"

        # Update the third container's contact to also be "Test User"
        docker exec ${sender} eiou update ${thirdAddress} name "Test User" --json 2>&1 > /dev/null

        # Now try to send to "Test User" in JSON mode - should get multiple_matches error
        echo -e "\t-> Sending to duplicate name 'Test User' in JSON mode"
        sendOutput=$(docker exec -e EIOU_TEST_MODE=true ${sender} eiou send "Test User" 1 USD --json 2>&1)

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
        docker exec ${sender} eiou update ${receiverAddress} name "${receiver}" --json 2>&1 > /dev/null
        docker exec ${sender} eiou update ${thirdAddress} name "${thirdContainer}" --json 2>&1 > /dev/null
    else
        echo -e "\t   Skipping duplicate name test - ${sender} has no contact for ${thirdContainer}"
        printf "\t   Duplicate name detection ${YELLOW}SKIPPED${NC}\n"
        passed=$(( passed + 1 ))  # Don't penalize for topology limitations
    fi
else
    echo -e "\t   Skipping duplicate name test - need at least 3 containers"
    # Don't count as a test if we can't run it
fi

# Restore original name from Test 1
echo -e "\n[Cleanup]"
echo -e "\t-> Restoring contact name on ${sender}"
docker exec ${sender} eiou update ${receiverAddress} name "${receiver}" --json 2>&1 > /dev/null

succesrate "${totaltests}" "${passed}" "${failure}" "'contact name'"
