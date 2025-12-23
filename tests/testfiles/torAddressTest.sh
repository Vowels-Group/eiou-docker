#!/bin/sh
# Copyright 2025 The Vowels Company

############################ Testing #############################

testname="torAddressTest"
totaltests="${#containers[@]}"
passed=0
failure=0

for container in "${containers[@]}"; do
    # Get Tor addresses (exists by default if container created succesfully)
    containerAddresses[$container]=$(docker exec $container php -r '
        $json = json_decode(file_get_contents("'"${USERCONFIG}"'"),true);
        if (isset($json["torAddress"])) {
            echo $json["torAddress"];
        }
    ')

    if [[ ! -z "${containerAddresses[${container}]}" ]]; then
        printf "\t   ${testname} for %s ${GREEN}PASSED${NC}\n\n" ${container}
        passed=$(( passed + 1 ))
    else
        printf "\t   ${testname} for %s ${RED}FAILED${NC}\n\n" ${container}
        failure=$(( failure + 1 ))
    fi

done

succesrate "${totaltests}" "${passed}" "${failure}" "'generate'"

##################################################################





