#!/bin/sh

############################ Testing #############################

testname="hostnameTest"
totaltests="${#containers[@]}"
passed=0
failure=0

for container in "${containers[@]}"; do
    containerAddress="http://"$container
    containerAddresses[$container]=$(docker exec $container php -r '
        $json = json_decode(file_get_contents("/etc/eiou/userconfig.json"),true);
        if (isset($json["hostname"])) {
            echo $json["hostname"];
        }
    ')

    if [[ "${containerAddresses[${container}]}" == $containerAddress ]]; then
        printf "${testname} for %s ${GREEN}PASSED${NC}\n\n" ${container}
        passed=$(( passed + 1 ))
    else
        printf "${testname} for %s ${RED}FAILED${NC}\n\n" ${container}
        failure=$(( failure + 1 ))
    fi

done

succesrate "${totaltests}" "${passed}" "${failure}" "'generate'"

##################################################################