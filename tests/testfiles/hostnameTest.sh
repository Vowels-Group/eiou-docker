#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

############################ Testing #############################

testname="hostnameTest"
totaltests="${#containers[@]}"
passed=0
failure=0

# Determine expected protocol based on MODE (default to https for backward compatibility)
if [ "$MODE" = 'http' ]; then
    EXPECTED_PROTOCOL="http://"
else
    EXPECTED_PROTOCOL="https://"
fi

for container in "${containers[@]}"; do
    containerAddress="${EXPECTED_PROTOCOL}"$container
    # Get Http addresses if exists
    containerAddresses[$container]=$(docker exec $container php -r '
        $json = json_decode(file_get_contents("'"${USERCONFIG}"'"),true);
        if (isset($json["hostname"])) {
            echo $json["hostname"];
        }
    ')

    if [[ "${containerAddresses[${container}]}" == $containerAddress ]]; then
        printf "\t   ${testname} for %s ${GREEN}PASSED${NC}\n\n" ${container}
        passed=$(( passed + 1 ))
    else
        printf "\t   ${testname} for %s ${RED}FAILED${NC}\n\n" ${container}
        failure=$(( failure + 1 ))
    fi

done

succesrate "${totaltests}" "${passed}" "${failure}" "'generate'"

##################################################################