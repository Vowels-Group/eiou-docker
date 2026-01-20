#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Testing #############################

testname="hostnameTest"
totaltests="${#containers[@]}"
passed=0
failure=0

for container in "${containers[@]}"; do
    # Get hostname from container's userconfig.json
    containerAddresses[$container]=$(docker exec $container php -r '
        $json = json_decode(file_get_contents("'"${USERCONFIG}"'"),true);
        if (isset($json["hostname"])) {
            echo $json["hostname"];
        }
    ')

    actualHostname="${containerAddresses[${container}]}"
    testPassed=false

    # Validate hostname based on MODE
    # In http mode, accept both http:// and https:// for backward compatibility
    # (containers are built with SSL enabled by default)
    # In https mode, require https://
    if [ "$MODE" == 'http' ]; then
        # HTTP mode: accept http:// or https:// prefix with correct container name
        if [[ "$actualHostname" == "http://${container}" ]] || [[ "$actualHostname" == "https://${container}" ]]; then
            testPassed=true
        fi
    elif [ "$MODE" == 'https' ]; then
        # HTTPS mode: require https:// prefix
        if [[ "$actualHostname" == "https://${container}" ]]; then
            testPassed=true
        fi
    fi

    if [ "$testPassed" == "true" ]; then
        printf "\t   ${testname} for %s ${GREEN}PASSED${NC}\n\n" ${container}
        passed=$(( passed + 1 ))
    else
        printf "\t   ${testname} for %s ${RED}FAILED${NC} (got: %s)\n\n" ${container} "${actualHostname}"
        failure=$(( failure + 1 ))
    fi

done

succesrate "${totaltests}" "${passed}" "${failure}" "'generate'"

##################################################################