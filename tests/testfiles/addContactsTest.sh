#!/bin/sh



# Add contacts
echo -e "\nAdding contacts..."
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
for containersLinkKey in "${containersLinkKeys[@]}"; do
    values=${containersLinks[${containersLinkKey}]}
    containerKeys=(${containersLinkKey//,/ })    
    echo -e "\n\t-> Adding ${containerKeys[0]} To ${containerKeys[1]} as a contact: "
    docker exec ${containerKeys[0]} eiou add ${containerAddresses[${containerKeys[1]}]} ${containerKeys[1]} ${values[0]} ${values[1]} ${values[2]}
done

# Wait for contacts to be added
echo -e "\n\t   Waiting 10 seconds for contact requests to be properly processed (faster but certainty)..."
sleep 10

############################ Testing #############################

testname="addContactsTest"
totaltests=$(( "${#containersLinkKeys[@]}" + "${#containersLinkKeys[@]}" ))
passed=0
failure=0

for containersLinkKey in "${containersLinkKeys[@]}"; do
    containerKeys=(${containersLinkKey//,/ }) 
    
    # httpA -> httpB (i.e forwards)
    transportType1=$(determineTransport ${containerAddresses[${containerKeys[1]}]})
    statusContact1=$(docker exec ${containerKeys[0]} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        echo Application::getInstance()->services->getContactRepository()->getContactStatus(
            '""${transportType1}""','""${containerAddresses[${containerKeys[1]}]}""'
        );
    ")

    printf "\n\t   %s has status %s with %s\n" ${containerKeys[1]} ${statusContact1} ${containerKeys[0]}
    if [[ "${statusContact1}" == "accepted" ]]; then
        printf "\t   ${testname} for %s ${GREEN}PASSED${NC}\n" ${containerKeys[0]}
        passed=$(( passed + 1 ))
    else
        printf "\t   ${testname} for %s ${RED}FAILED${NC}\n" ${containerKeys[0]}
        failure=$(( failure + 1 ))
    fi

   
    # httpB -> httpA (i.e backwards)
    transportType0=$(determineTransport ${containerAddresses[${containerKeys[0]}]})
    statusContact0=$(docker exec ${containerKeys[1]} php -r "
        require_once('./etc/eiou/src/services/ServiceContainer.php');
        echo Application::getInstance()->services->getContactRepository()->getContactStatus(
            '""${transportType0}""','""${containerAddresses[${containerKeys[0]}]}""'
        );
    ")

    printf "\n\t   %s has status %s with %s\n" ${containerKeys[0]} ${statusContact0} ${containerKeys[1]}  
    if [[ "${statusContact0}" == "accepted" ]]; then
        printf "\t   ${testname} for %s ${GREEN}PASSED${NC}\n" ${containerKeys[1]}
        passed=$(( passed + 1 ))
    else
        printf "\t   ${testname} for %s ${RED}FAILED${NC}\n" ${containerKeys[1]}
        failure=$(( failure + 1 ))
    fi


done

succesrate "${totaltests}" "${passed}" "${failure}" "'add'"

##################################################################