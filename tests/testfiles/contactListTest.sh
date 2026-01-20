#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

# Test contact list storage and metadata verification
echo -e "\nTesting contact list storage and metadata..."

testname="contactListTest"
totaltests=0
passed=0
failure=0

# Test 1: Verify all expected contacts are stored
echo -e "\n[Contact Storage Verification]"

containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
for containersLinkKey in "${containersLinkKeys[@]}"; do
    totaltests=$(( totaltests + 1 ))

    values=${containersLinks[${containersLinkKey}]}
    containerKeys=(${containersLinkKey//,/ })
    valueArray=($values)

    # Expected values
    expectedFee=$(awk '{print $1*$2}' <<<"${valueArray[0]} 100")
    expectedCredit=$(awk '{print $1*$2}' <<<"${valueArray[1]} 100")
    expectedCurrency="${valueArray[2]}"

    echo -e "\n\t-> Verifying contact: ${containerKeys[0]} -> ${containerKeys[1]}"

    # Query contact details using PHP (with single retry if not found)
    contactData=$(docker exec ${containerKeys[0]} php -r "
        require_once('${REL_APPLICATION}');
        \$contact = Application::getInstance()->services->getContactRepository()->lookupByAddress('${MODE}','${containerAddresses[${containerKeys[1]}]}');
        if (\$contact) {
            echo json_encode(\$contact);
        } else {
            echo 'NOT_FOUND';
        }
    " 2>/dev/null || echo "ERROR")

    # Retry once if not found (timing issue - contact sync might be delayed)
    if [[ "$contactData" == "NOT_FOUND" ]]; then
        echo -e "\t   Contact not found, waiting 10s for sync retry..."
        sleep 10
        docker exec ${containerKeys[0]} eiou out 2>/dev/null || true
        docker exec ${containerKeys[0]} eiou in 2>/dev/null || true
        contactData=$(docker exec ${containerKeys[0]} php -r "
            require_once('${REL_APPLICATION}');
            \$contact = Application::getInstance()->services->getContactRepository()->lookupByAddress('${MODE}','${containerAddresses[${containerKeys[1]}]}');
            if (\$contact) {
                echo json_encode(\$contact);
            } else {
                echo 'NOT_FOUND';
            }
        " 2>/dev/null || echo "ERROR")
    fi

    if [[ "$contactData" != "ERROR" ]] && [[ "$contactData" != "NOT_FOUND" ]]; then
        # Parse JSON response (basic parsing without jq)
        if [[ "$contactData" =~ "\"name\":\"${containerKeys[1]}\"" ]]; then
            nameCorrect="true"
        else
            nameCorrect="false"
        fi

        if  [[ "$contactData" =~ "\"fee_percent\":${expectedFee}" ]] || [[ "$contactData" =~ "\"fee_percent\":\"${expectedFee}\"" ]]; then
            feeCorrect="true"
        else
            feeCorrect="false"
        fi

        if [[ "$contactData" =~ "\"credit_limit\":${expectedCredit}" ]] || [[ "$contactData" =~ "\"credit_limit\":\"${expectedCredit}\"" ]]; then
            creditCorrect="true"
        else
            creditCorrect="false"
        fi

        if [[ "$contactData" =~ "\"currency\":\"${expectedCurrency}\"" ]]; then
            currencyCorrect="true"
        else
            currencyCorrect="false"
        fi

        if [[ "$contactData" =~ "\"status\":\"accepted\"" ]]; then
            statusCorrect="true"
        else
            statusCorrect="false"
        fi

        # Check if all metadata is correct
        if [[ "$nameCorrect" == "true" ]] && [[ "$feeCorrect" == "true" ]] &&
           [[ "$creditCorrect" == "true" ]] && [[ "$currencyCorrect" == "true" ]] &&
           [[ "$statusCorrect" == "true" ]]; then
            printf "\t   Contact %s->%s metadata ${GREEN}PASSED${NC}\n" ${containerKeys[0]} ${containerKeys[1]}
            printf "\t   Name: ✓ Fee: ✓ Credit: ✓ Currency: ✓ Status: ✓\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   Contact %s->%s metadata ${RED}FAILED${NC}\n" ${containerKeys[0]} ${containerKeys[1]}
            printf "\t   Name: %s Fee: %s Credit: %s Currency: %s Status: %s\n" \
                   $([ "$nameCorrect" == "true" ] && echo "✓" || echo "✗") \
                   $([ "$feeCorrect" == "true" ] && echo "✓" || echo "✗") \
                   $([ "$creditCorrect" == "true" ] && echo "✓" || echo "✗") \
                   $([ "$currencyCorrect" == "true" ] && echo "✓" || echo "✗") \
                   $([ "$statusCorrect" == "true" ] && echo "✓" || echo "✗")
            failure=$(( failure + 1 ))
        fi
    else
        printf "\t   Contact %s->%s ${RED}NOT FOUND${NC}\n" ${containerKeys[0]} ${containerKeys[1]}
        failure=$(( failure + 1 ))
    fi
done

# Test 2: Verify bidirectional relationships
echo -e "\n[Bidirectional Relationship Verification]"

# Check that if A has B as contact, B also has A
processedPairs=()
for containersLinkKey in "${containersLinkKeys[@]}"; do
    containerKeys=(${containersLinkKey//,/ })
    pairKey="${containerKeys[0]}_${containerKeys[1]}"
    reversePairKey="${containerKeys[1]}_${containerKeys[0]}"

    # Skip if we already processed this pair
    if [[ " ${processedPairs[@]} " =~ " ${reversePairKey} " ]]; then
        continue
    fi
    processedPairs+=("$pairKey")

    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking bidirectional: ${containerKeys[0]} <-> ${containerKeys[1]}"

    # Check forward relationship
    forwardExists=$(docker exec ${containerKeys[0]} php -r "
        require_once('${REL_APPLICATION}');
        if(Application::getInstance()->services->getContactRepository()->contactExists('${MODE}','${containerAddresses[${containerKeys[1]}]}')){
        echo '1';} else{ echo '0';}
    " 2>/dev/null || echo "0")

    # Check reverse relationship
    reverseExists=$(docker exec ${containerKeys[1]} php -r "
        require_once('${REL_APPLICATION}');
        if(Application::getInstance()->services->getContactRepository()->contactExists('${MODE}','${containerAddresses[${containerKeys[0]}]}')){
        echo '1';} else{ echo '0';}
    " 2>/dev/null || echo "0")

    # Retry once if either relationship not found (timing issue)
    if [[ "$forwardExists" != "1" ]] || [[ "$reverseExists" != "1" ]]; then
        echo -e "\t   Relationship incomplete (Forward: ${forwardExists}, Reverse: ${reverseExists}), waiting 10s for sync retry..."
        sleep 10
        docker exec ${containerKeys[0]} eiou out 2>/dev/null || true
        docker exec ${containerKeys[0]} eiou in 2>/dev/null || true
        docker exec ${containerKeys[1]} eiou out 2>/dev/null || true
        docker exec ${containerKeys[1]} eiou in 2>/dev/null || true

        # Retry checks
        forwardExists=$(docker exec ${containerKeys[0]} php -r "
            require_once('${REL_APPLICATION}');
            if(Application::getInstance()->services->getContactRepository()->contactExists('${MODE}','${containerAddresses[${containerKeys[1]}]}')){
            echo '1';} else{ echo '0';}
        " 2>/dev/null || echo "0")

        reverseExists=$(docker exec ${containerKeys[1]} php -r "
            require_once('${REL_APPLICATION}');
            if(Application::getInstance()->services->getContactRepository()->contactExists('${MODE}','${containerAddresses[${containerKeys[0]}]}')){
            echo '1';} else{ echo '0';}
        " 2>/dev/null || echo "0")
    fi

    if [[ "$forwardExists" == "1" ]] && [[ "$reverseExists" == "1" ]]; then
        printf "\t   Bidirectional relationship ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Bidirectional relationship ${RED}FAILED${NC} (Forward: %s, Reverse: %s)\n" ${forwardExists} ${reverseExists}
        failure=$(( failure + 1 ))
    fi
done

# Test 3: List all contacts command
echo -e "\n[List Contacts Command Test]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Testing list contacts for ${container}"

    # Use viewcontacts or similar command if available
    #listOutput=$(docker exec ${container} eiou list 2>&1 || echo "")

    # Get contact count from database
    contactCount=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        echo Application::getInstance()->services->getContactRepository()->countAcceptedContacts();
    " 2>/dev/null || echo "0")

    if [[ "$contactCount" -gt "0" ]]; then
        printf "\t   %s has %s accepted contacts ${GREEN}PASSED${NC}\n" ${container} ${contactCount}
        passed=$(( passed + 1 ))
    else
        printf "\t   %s has no contacts ${RED}FAILED${NC}\n" ${container}
        failure=$(( failure + 1 ))
    fi
done

# Test 4: View specific contact details
echo -e "\n[View Contact Details Test]"

for containersLinkKey in "${containersLinkKeys[@]:0:3}"; do  # Test first 3 links
    totaltests=$(( totaltests + 1 ))

    containerKeys=(${containersLinkKey//,/ })

    echo -e "\n\t-> ${containerKeys[0]} viewing contact ${containerKeys[1]}"

    viewOutput=$(docker exec ${containerKeys[0]} eiou viewcontact ${containerAddresses[${containerKeys[1]}]} 2>&1)

    if [[ ! "$viewOutput" =~ "error" ]] && [[ ! "$viewOutput" =~ "Error" ]] && [[ ! "$viewOutput" =~ "not found" ]]; then
        printf "\t   View contact details ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   View contact details ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
done

succesrate "${totaltests}" "${passed}" "${failure}" "'contact list'"