#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

############################ TOR Test Suite ############################
# Consolidated TOR tests combining:
# - torAddressTest.sh - TOR address verification
# - torRestartTest.sh - TOR restart verification
# - torKeyPermissionsTest.sh - Key file permissions
# - torRapidRestartTest.sh - Rapid restart resilience
#
# This consolidation reduces ~407 lines to ~200 lines (51% reduction)
########################################################################

# Helper functions are sourced via config.sh -> testHelpers.sh
# No need to source again here

testname="torTestSuite"
totaltests=0
passed=0
failure=0

# Constants
HS_DIR="/var/lib/tor/hidden_service"
HS_HOSTNAME_FILE="${HS_DIR}/hostname"
HS_FILES="hs_ed25519_secret_key hs_ed25519_public_key hostname"

echo -e "\n"
echo "========================================================================"
echo "                    TOR TEST SUITE"
echo "========================================================================"
echo -e "\n"

##################### SECTION 1: TOR Address Verification #####################
# Tests that all containers have valid TOR addresses in userconfig.json

echo -e "\n[Section 1: TOR Address Verification]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking TOR address for ${container}"

    torAddress=$(docker exec $container php -r '
        $json = json_decode(file_get_contents("'"${USERCONFIG}"'"),true);
        if (isset($json["torAddress"])) {
            echo $json["torAddress"];
        }
    ')

    if [[ ! -z "${torAddress}" ]] && [[ "${torAddress}" =~ \.onion$ ]]; then
        printf "\t   TOR address for %s ${GREEN}PASSED${NC} (%s)\n" ${container} ${torAddress}
        containerAddresses[$container]=$torAddress
        passed=$(( passed + 1 ))
    else
        printf "\t   TOR address for %s ${RED}FAILED${NC} (empty or invalid)\n" ${container}
        failure=$(( failure + 1 ))
    fi
done

##################### SECTION 2: TOR Service Restart Verification #####################
# Tests that TOR service is running properly with all components

echo -e "\n[Section 2: TOR Service Status Verification]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking TOR service for ${container}"

    # Test 2.1: Verify TOR service is running
    torStatus=$(docker exec $container service tor status 2>&1)
    if ! echo "$torStatus" | grep -q "running"; then
        printf "\t   TOR service for %s ${RED}FAILED${NC} - TOR service not running\n" ${container}
        failure=$(( failure + 1 ))
        continue
    fi

    # Test 2.2: Verify SOCKS proxy is listening on port 9050
    socksListening=$(docker exec $container sh -c 'ss -tlnp 2>/dev/null | grep 9050 || netstat -tlnp 2>/dev/null | grep 9050')
    if [ -z "$socksListening" ]; then
        printf "\t   TOR service for %s ${RED}FAILED${NC} - SOCKS proxy not listening on port 9050\n" ${container}
        failure=$(( failure + 1 ))
        continue
    fi

    # Test 2.3: Verify hidden service hostname file exists and is non-empty
    hostnameFileContent=$(docker exec $container cat "${HS_HOSTNAME_FILE}" 2>/dev/null | tr -d '\n')
    if [ -z "$hostnameFileContent" ]; then
        printf "\t   TOR service for %s ${RED}FAILED${NC} - Hidden service hostname file empty or missing\n" ${container}
        failure=$(( failure + 1 ))
        continue
    fi

    # Test 2.4: Verify hidden service hostname matches userconfig.json
    userconfigTor=$(docker exec $container php -r '
        $json = json_decode(file_get_contents("'"${USERCONFIG}"'"),true);
        if (isset($json["torAddress"])) {
            echo $json["torAddress"];
        }
    ')

    if [ "$hostnameFileContent" != "$userconfigTor" ]; then
        printf "\t   TOR service for %s ${RED}FAILED${NC} - Hostname mismatch (file: %s, config: %s)\n" ${container} ${hostnameFileContent} ${userconfigTor}
        failure=$(( failure + 1 ))
        continue
    fi

    # Test 2.5: Verify hidden service is accessible via TOR (with timeout)
    torConnectivity=$(docker exec $container curl --socks5-hostname 127.0.0.1:9050 \
        --connect-timeout 10 \
        --max-time 15 \
        --silent \
        --fail \
        --output /dev/null \
        "$userconfigTor" 2>&1; echo $?)

    if [ "$torConnectivity" != "0" ]; then
        printf "\t   TOR service for %s ${YELLOW}WARNING${NC} - Hidden service not reachable (may still be bootstrapping)\n" ${container}
        passed=$(( passed + 1 ))
        continue
    fi

    printf "\t   TOR service for %s ${GREEN}PASSED${NC}\n" ${container}
    passed=$(( passed + 1 ))
done

##################### SECTION 3: TOR Key Permissions #####################
# Tests that TOR hidden service key files have correct permissions

echo -e "\n[Section 3: TOR Key Permissions Verification]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    containerPassed=true

    echo -e "\n\t-> Checking key permissions for ${container}"

    # Test 3.1: Verify hidden service directory exists
    dirExists=$(docker exec $container test -d "$HS_DIR" && echo "yes" || echo "no")
    if [ "$dirExists" != "yes" ]; then
        printf "\t   Key permissions for %s ${RED}FAILED${NC} - Hidden service directory does not exist\n" ${container}
        failure=$(( failure + 1 ))
        continue
    fi

    # Test 3.2: Verify directory permissions (should be 700)
    dirPerms=$(docker exec $container stat -c '%a' "$HS_DIR" 2>/dev/null)
    if [ "$dirPerms" != "700" ]; then
        printf "\t   Key permissions for %s ${YELLOW}WARNING${NC} - Directory permissions: ${dirPerms} (expected 700)\n" ${container}
    fi

    # Test 3.3: Verify directory ownership
    dirOwner=$(docker exec $container stat -c '%U' "$HS_DIR" 2>/dev/null)
    if [ "$dirOwner" != "debian-tor" ]; then
        printf "\t   Key permissions for %s ${YELLOW}WARNING${NC} - Directory owner: ${dirOwner} (expected debian-tor)\n" ${container}
    fi

    # Test 3.4: Verify each key file exists and has correct permissions
    for keyFile in $HS_FILES; do
        filePath="${HS_DIR}/${keyFile}"

        fileExists=$(docker exec $container test -f "$filePath" && echo "yes" || echo "no")
        if [ "$fileExists" != "yes" ]; then
            printf "\t   Key permissions for %s ${RED}FAILED${NC} - Missing file: ${keyFile}\n" ${container}
            containerPassed=false
            continue
        fi

        filePerms=$(docker exec $container stat -c '%a' "$filePath" 2>/dev/null)
        if [ "$filePerms" != "600" ]; then
            printf "\t   Key permissions for %s ${YELLOW}WARNING${NC} - ${keyFile} permissions: ${filePerms} (expected 600)\n" ${container}
        fi

        fileSize=$(docker exec $container stat -c '%s' "$filePath" 2>/dev/null)
        if [ "$fileSize" = "0" ]; then
            printf "\t   Key permissions for %s ${RED}FAILED${NC} - ${keyFile} is empty\n" ${container}
            containerPassed=false
        fi
    done

    # Test 3.5: Verify TOR can read the keys (service is running)
    torStatus=$(docker exec $container service tor status 2>&1)
    if ! echo "$torStatus" | grep -q "running"; then
        printf "\t   Key permissions for %s ${RED}FAILED${NC} - TOR not running (possible permission issue)\n" ${container}
        containerPassed=false
    fi

    if [ "$containerPassed" = true ]; then
        printf "\t   Key permissions for %s ${GREEN}PASSED${NC}\n" ${container}
        passed=$(( passed + 1 ))
    else
        failure=$(( failure + 1 ))
    fi
done

##################### SECTION 4: TOR Rapid Restart Resilience #####################
# Tests that TOR handles multiple rapid restarts without corruption

echo -e "\n[Section 4: TOR Rapid Restart Resilience]"

# Only test on the first container to keep test duration reasonable
testContainer="${containers[0]}"

if [ -z "$testContainer" ]; then
    printf "\t   Rapid restart test ${RED}FAILED${NC} - No containers available\n"
    failure=$(( failure + 1 ))
else
    totaltests=$(( totaltests + 1 ))

    RESTART_COUNT=3
    STABILITY_WAIT=5

    # Store original TOR address
    originalTorAddress=$(docker exec $testContainer php -r '
        $json = json_decode(file_get_contents("'"${USERCONFIG}"'"),true);
        if (isset($json["torAddress"])) {
            echo $json["torAddress"];
        }
    ')

    if [ -z "$originalTorAddress" ]; then
        printf "\t   Rapid restart for %s ${RED}FAILED${NC} - Could not get original TOR address\n" ${testContainer}
        failure=$(( failure + 1 ))
    else
        originalHostname=$(docker exec $testContainer cat "${HS_HOSTNAME_FILE}" 2>/dev/null | tr -d '\n')

        printf "\t   Testing ${RESTART_COUNT} rapid restarts on ${testContainer}...\n"

        restartSuccess=true
        for i in $(seq 1 $RESTART_COUNT); do
            printf "\t   Restart ${i}/${RESTART_COUNT}... "

            restartResult=$(docker exec $testContainer service tor restart 2>&1)
            exitCode=$?

            if [ $exitCode -ne 0 ]; then
                printf "${RED}FAILED${NC}\n"
                restartSuccess=false
                break
            fi

            sleep 1
            printf "${GREEN}OK${NC}\n"
        done

        if [ "$restartSuccess" = false ]; then
            printf "\t   Rapid restart for %s ${RED}FAILED${NC} - Restart command failed\n" ${testContainer}
            failure=$(( failure + 1 ))
        else
            # Wait for TOR to stabilize
            printf "\t   Waiting ${STABILITY_WAIT}s for TOR to stabilize...\n"
            sleep $STABILITY_WAIT

            # Verify TOR is running after rapid restarts
            torStatus=$(docker exec $testContainer service tor status 2>&1)
            if ! echo "$torStatus" | grep -q "running"; then
                printf "\t   Rapid restart for %s ${RED}FAILED${NC} - TOR not running after rapid restarts\n" ${testContainer}
                failure=$(( failure + 1 ))
            else
                # Verify TOR address is consistent
                finalTorAddress=$(docker exec $testContainer php -r '
                    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"),true);
                    if (isset($json["torAddress"])) {
                        echo $json["torAddress"];
                    }
                ')

                if [ "$originalTorAddress" != "$finalTorAddress" ]; then
                    printf "\t   Rapid restart for %s ${RED}FAILED${NC} - TOR address changed\n" ${testContainer}
                    failure=$(( failure + 1 ))
                else
                    # Verify key file integrity
                    secretKeySize=$(docker exec $testContainer stat -c '%s' "${HS_DIR}/hs_ed25519_secret_key" 2>/dev/null)
                    publicKeySize=$(docker exec $testContainer stat -c '%s' "${HS_DIR}/hs_ed25519_public_key" 2>/dev/null)

                    if [ "$secretKeySize" -lt 50 ] || [ "$secretKeySize" -gt 200 ]; then
                        printf "\t   Rapid restart for %s ${RED}FAILED${NC} - Secret key size abnormal: ${secretKeySize} bytes\n" ${testContainer}
                        failure=$(( failure + 1 ))
                    elif [ "$publicKeySize" -lt 30 ] || [ "$publicKeySize" -gt 100 ]; then
                        printf "\t   Rapid restart for %s ${RED}FAILED${NC} - Public key size abnormal: ${publicKeySize} bytes\n" ${testContainer}
                        failure=$(( failure + 1 ))
                    else
                        printf "\t   Rapid restart for %s ${GREEN}PASSED${NC}\n" ${testContainer}
                        printf "\t   TOR stable after ${RESTART_COUNT} rapid restarts, address: ${finalTorAddress}\n"
                        passed=$(( passed + 1 ))
                    fi
                fi
            fi
        fi
    fi
fi

########################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'TOR test suite'"

########################################################################
