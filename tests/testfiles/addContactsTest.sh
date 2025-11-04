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

sleep 5

for containersLinkKey in "${containersLinkKeys[@]}"; do
    containerKeys=(${containersLinkKey//,/ }) 
    
    phpstart='require_once("./etc/eiou/src/services/ServiceContainer.php"); $value=ServiceContainer::getInstance()->getContactRepository()->getContactStatus("'
    phpending='"); echo $value;'


#'require_once("./etc/eiou/src/services/ServiceContainer.php"); $value=ServiceContainer::getInstance()->getContactRepository()->getContactStatus("http","http://httpB");echo $value;'

    transportType0=$(determineTransport ${containerAddresses[${containerKeys[0]}]})
    contact0="${phpstart}${transportType0}\",\"${containerAddresses[${containerKeys[0]}]}$phpending"
    printf "%s\n" ${contact0}

    transportType1=$(determineTransport ${containerAddresses[${containerKeys[1]}]})
    contact1="${phpstart}${transportType1}\",\"${containerAddresses[${containerKeys[1]}]} $phpending"
    

    statusContact1=$(docker exec ${containerKeys[0]} php -r ${contact1})
    printf "%s has status %s with %s\n" ${containerKeys[1]} $statusContact1 ${containerKeys[0]}

    statusContact0=$(docker exec ${containerKeys[1]} php -r ${contact0})
    printf "%s has status %s with %s\n" ${containerKeys[0]} $statusContact0 ${containerKeys[1]}
done


