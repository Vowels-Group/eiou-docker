#!/bin/sh
# Copyright 2025 The Vowels Company

############################ Testing #############################
# TOR Rapid Restart Resilience Test
# Verifies that TOR handles multiple rapid restarts without corruption
# or loss of hidden service identity.
#
# This test validates:
# 1. TOR can handle multiple consecutive restarts
# 2. Hidden service address remains consistent after restarts
# 3. TOR process is stable after rapid restarts
# 4. No key file corruption occurs
#
# Note: This test only runs on the first container to avoid
# excessive test duration.
##################################################################

testname="torRapidRestartTest"
totaltests=1
passed=0
failure=0

# Only test on the first container to keep test duration reasonable
testContainer="${containers[0]}"

if [ -z "$testContainer" ]; then
    printf "\t   ${testname} ${RED}FAILED${NC} - No containers available\n"
    failure=1
    succesrate "${totaltests}" "${passed}" "${failure}" "'tor rapid restart'"
    exit 0
fi

HS_DIR="/var/lib/tor/hidden_service"
HS_HOSTNAME_FILE="${HS_DIR}/hostname"
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
    printf "\t   ${testname} for %s ${RED}FAILED${NC} - Could not get original TOR address\n" ${testContainer}
    failure=1
    succesrate "${totaltests}" "${passed}" "${failure}" "'tor rapid restart'"
    exit 0
fi

# Store original hostname file content for verification
originalHostname=$(docker exec $testContainer cat "${HS_HOSTNAME_FILE}" 2>/dev/null | tr -d '\n')

printf "\t   Testing ${RESTART_COUNT} rapid restarts on ${testContainer}...\n"

# Perform multiple rapid restarts
restartSuccess=true
for i in $(seq 1 $RESTART_COUNT); do
    printf "\t   Restart ${i}/${RESTART_COUNT}... "

    restartResult=$(docker exec $testContainer service tor restart 2>&1)
    exitCode=$?

    if [ $exitCode -ne 0 ]; then
        printf "${RED}FAILED${NC}\n"
        printf "\t   Error: ${restartResult}\n"
        restartSuccess=false
        break
    fi

    # Very short delay between restarts to simulate rapid restarts
    sleep 1
    printf "${GREEN}OK${NC}\n"
done

if [ "$restartSuccess" = false ]; then
    printf "\t   ${testname} for %s ${RED}FAILED${NC} - Restart command failed\n" ${testContainer}
    failure=1
    succesrate "${totaltests}" "${passed}" "${failure}" "'tor rapid restart'"
    exit 0
fi

# Wait for TOR to stabilize after rapid restarts
printf "\t   Waiting ${STABILITY_WAIT}s for TOR to stabilize...\n"
sleep $STABILITY_WAIT

# Verify TOR is running after rapid restarts
torStatus=$(docker exec $testContainer service tor status 2>&1)
if ! echo "$torStatus" | grep -q "running"; then
    printf "\t   ${testname} for %s ${RED}FAILED${NC} - TOR not running after rapid restarts\n" ${testContainer}
    printf "\t   Status: ${torStatus}\n"

    # Check for crash indicators in logs
    crashIndicators=$(docker exec $testContainer grep -i "crash\|segfault\|abort\|fatal" /var/log/tor/log 2>/dev/null | tail -5)
    if [ -n "$crashIndicators" ]; then
        printf "\t   Crash indicators in TOR log:\n"
        echo "$crashIndicators" | while read line; do
            printf "\t     ${line}\n"
        done
    fi

    failure=1
    succesrate "${totaltests}" "${passed}" "${failure}" "'tor rapid restart'"
    exit 0
fi

# Verify TOR address is consistent after restarts
finalTorAddress=$(docker exec $testContainer php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"),true);
    if (isset($json["torAddress"])) {
        echo $json["torAddress"];
    }
')

if [ "$originalTorAddress" != "$finalTorAddress" ]; then
    printf "\t   ${testname} for %s ${RED}FAILED${NC} - TOR address changed after restarts\n" ${testContainer}
    printf "\t   Original: ${originalTorAddress}\n"
    printf "\t   Final: ${finalTorAddress}\n"
    failure=1
    succesrate "${totaltests}" "${passed}" "${failure}" "'tor rapid restart'"
    exit 0
fi

# Verify hostname file is consistent
finalHostname=$(docker exec $testContainer cat "${HS_HOSTNAME_FILE}" 2>/dev/null | tr -d '\n')
if [ "$originalHostname" != "$finalHostname" ]; then
    printf "\t   ${testname} for %s ${RED}FAILED${NC} - Hostname file changed after restarts\n" ${testContainer}
    printf "\t   Original: ${originalHostname}\n"
    printf "\t   Final: ${finalHostname}\n"
    failure=1
    succesrate "${totaltests}" "${passed}" "${failure}" "'tor rapid restart'"
    exit 0
fi

# Verify key files are not corrupted (check sizes are reasonable)
secretKeySize=$(docker exec $testContainer stat -c '%s' "${HS_DIR}/hs_ed25519_secret_key" 2>/dev/null)
publicKeySize=$(docker exec $testContainer stat -c '%s' "${HS_DIR}/hs_ed25519_public_key" 2>/dev/null)

# Ed25519 secret key should be 96 bytes (header + key), public key should be 64 bytes
if [ "$secretKeySize" -lt 50 ] || [ "$secretKeySize" -gt 200 ]; then
    printf "\t   ${testname} for %s ${RED}FAILED${NC} - Secret key size abnormal: ${secretKeySize} bytes\n" ${testContainer}
    failure=1
    succesrate "${totaltests}" "${passed}" "${failure}" "'tor rapid restart'"
    exit 0
fi

if [ "$publicKeySize" -lt 30 ] || [ "$publicKeySize" -gt 100 ]; then
    printf "\t   ${testname} for %s ${RED}FAILED${NC} - Public key size abnormal: ${publicKeySize} bytes\n" ${testContainer}
    failure=1
    succesrate "${totaltests}" "${passed}" "${failure}" "'tor rapid restart'"
    exit 0
fi

# All tests passed
printf "\t   ${testname} for %s ${GREEN}PASSED${NC}\n" ${testContainer}
printf "\t   TOR stable after ${RESTART_COUNT} rapid restarts, address: ${finalTorAddress}\n"
passed=1

succesrate "${totaltests}" "${passed}" "${failure}" "'tor rapid restart'"

##################################################################
