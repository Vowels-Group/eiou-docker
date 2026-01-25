#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Add Contacts Test ############################
# Tests contact addition workflow between containers
#
# Verifies:
# - Contact requests are sent successfully between nodes
# - Contact acceptance is processed correctly
# - Contact status changes to 'accepted' after mutual addition
#
# Prerequisites:
# - Containers must be running
# - containerAddresses array must be populated by build file
# - containersLinks array defines which contacts to add
#########################################################################

# Add contacts
echo -e "\nAdding contacts..."
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
for containersLinkKey in "${containersLinkKeys[@]}"; do
    values=${containersLinks[${containersLinkKey}]}
    containerKeys=(${containersLinkKey//,/ })    
    echo -e "\n\t-> Adding ${containerKeys[0]} To ${containerKeys[1]} as a contact: "
    docker exec ${containerKeys[0]} eiou add ${containerAddresses[${containerKeys[1]}]} ${containerKeys[1]} ${values[0]} ${values[1]} ${values[2]}
done

# Wait for contacts to be added with polling
echo -e "\n\t   Waiting for contact requests to be processed (timeout: 10s)..."
# Use a simple wait - contacts should be added quickly
wait_elapsed=0
while [ $wait_elapsed -lt 10 ]; do
    # Check if at least one contact is accepted
    first_key="${containersLinkKeys[0]}"
    first_keys=(${first_key//,/ })
    transportCheck=$(getPhpTransportType ${containerAddresses[${first_keys[1]}]})
    statusCheck=$(docker exec ${first_keys[0]} php -r "
        require_once('${REL_APPLICATION}');
        echo Application::getInstance()->services->getContactRepository()->getContactStatus(
            '""${transportCheck}""','""${containerAddresses[${first_keys[1]}]}""'
        );
    " 2>/dev/null || echo "pending")

    if [ "$statusCheck" = "accepted" ]; then
        echo -e "\t   Contacts processed early (${wait_elapsed}s)"
        break
    fi

    sleep 1
    wait_elapsed=$((wait_elapsed + 1))
done

############################ Testing #############################

testname="addContactsTest"
totaltests="${#containersLinkKeys[@]}"
passed=0
failure=0

for containersLinkKey in "${containersLinkKeys[@]}"; do
    containerKeys=(${containersLinkKey//,/ })

    # httpA -> httpB (i.e forwards and the next LinkKey should be httpB -> httpA (i.e backwards))
    transportType1=$(getPhpTransportType ${containerAddresses[${containerKeys[1]}]})

    # Use retry helper: check status, if not accepted wait 10s, process queues, retry once
    statusContact1=$(check_contact_status_with_retry ${containerKeys[0]} ${transportType1} "${containerAddresses[${containerKeys[1]}]}" 10)

    printf "\n\t   %s has status %s with %s\n" "${containerKeys[1]}" "${statusContact1}" "${containerKeys[0]}"
    if [[ "${statusContact1}" == "accepted" ]]; then
        printf "\t   ${testname} for %s ${GREEN}PASSED${NC}\n" ${containerKeys[0]}
        passed=$(( passed + 1 ))
    else
        printf "\t   ${testname} for %s ${RED}FAILED${NC} (status after retry: %s)\n" ${containerKeys[0]} "${statusContact1}"
        failure=$(( failure + 1 ))
    fi

done

succesrate "${totaltests}" "${passed}" "${failure}" "'add'"

##################################################################