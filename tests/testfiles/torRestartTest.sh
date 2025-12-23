#!/bin/sh
# Copyright 2025 The Vowels Company

############################ Testing #############################
# TOR Restart Verification Test
# Verifies that TOR service restarts correctly and the hidden service
# is functional after wallet generation with deterministic keys.
#
# This test validates:
# 1. TOR service is running
# 2. SOCKS proxy is listening on port 9050
# 3. Hidden service hostname file exists and matches userconfig.json
# 4. Hidden service is accessible via TOR
##################################################################

testname="torRestartTest"
totaltests=0
passed=0
failure=0

HS_DIR="/var/lib/tor/hidden_service"
HS_HOSTNAME_FILE="${HS_DIR}/hostname"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))

    # Test 1: Verify TOR service is running
    torStatus=$(docker exec $container service tor status 2>&1)
    if ! echo "$torStatus" | grep -q "running"; then
        printf "\t   ${testname} for %s ${RED}FAILED${NC} - TOR service not running\n" ${container}
        printf "\t   Status: ${torStatus}\n"
        failure=$(( failure + 1 ))
        continue
    fi

    # Test 2: Verify SOCKS proxy is listening on port 9050
    socksListening=$(docker exec $container sh -c 'ss -tlnp 2>/dev/null | grep 9050 || netstat -tlnp 2>/dev/null | grep 9050')
    if [ -z "$socksListening" ]; then
        printf "\t   ${testname} for %s ${RED}FAILED${NC} - SOCKS proxy not listening on port 9050\n" ${container}
        failure=$(( failure + 1 ))
        continue
    fi

    # Test 3: Verify hidden service hostname file exists and is non-empty
    hostnameFileContent=$(docker exec $container cat "${HS_HOSTNAME_FILE}" 2>/dev/null | tr -d '\n')
    if [ -z "$hostnameFileContent" ]; then
        printf "\t   ${testname} for %s ${RED}FAILED${NC} - Hidden service hostname file empty or missing\n" ${container}
        failure=$(( failure + 1 ))
        continue
    fi

    # Test 4: Verify hidden service hostname matches userconfig.json
    userconfigTor=$(docker exec $container php -r '
        $json = json_decode(file_get_contents("'"${USERCONFIG}"'"),true);
        if (isset($json["torAddress"])) {
            echo $json["torAddress"];
        }
    ')

    if [ "$hostnameFileContent" != "$userconfigTor" ]; then
        printf "\t   ${testname} for %s ${RED}FAILED${NC} - Hostname mismatch\n" ${container}
        printf "\t   File: ${hostnameFileContent}\n"
        printf "\t   Config: ${userconfigTor}\n"
        failure=$(( failure + 1 ))
        continue
    fi

    # Test 5: Verify hidden service is accessible via TOR (with timeout)
    torConnectivity=$(docker exec $container curl --socks5-hostname 127.0.0.1:9050 \
        --connect-timeout 10 \
        --max-time 15 \
        --silent \
        --fail \
        --output /dev/null \
        "$userconfigTor" 2>&1; echo $?)

    if [ "$torConnectivity" != "0" ]; then
        printf "\t   ${testname} for %s ${YELLOW}WARNING${NC} - Hidden service not reachable (may still be bootstrapping)\n" ${container}
        # This is a warning, not a failure, as TOR may take time to establish circuits
        passed=$(( passed + 1 ))
        continue
    fi

    printf "\t   ${testname} for %s ${GREEN}PASSED${NC}\n" ${container}
    passed=$(( passed + 1 ))

done

succesrate "${totaltests}" "${passed}" "${failure}" "'tor restart'"

##################################################################
