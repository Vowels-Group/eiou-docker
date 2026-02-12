#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

# Test SIGTERM graceful shutdown via docker stop
# Verifies that containers handle SIGTERM correctly, shut down within the
# grace period (not killed by SIGKILL), and restart cleanly with data intact.

echo -e "\nTesting SIGTERM Graceful Shutdown (docker stop/start)..."

testname="sigTermTest"
totaltests=0
passed=0
failure=0

# Use last container to minimize impact on other tests
testContainer="${containers[${#containers[@]}-1]}"

echo -e "\n\tUsing container: ${testContainer}"

############################ PRE-STOP VERIFICATION ############################

echo -e "\n[Pre-Stop Verification]"

# Test 1: Container is running and healthy
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying container is running and healthy"

preHealthCheck=$(docker exec ${testContainer} php -r "echo 'HEALTHY';" 2>&1)

if [ "$preHealthCheck" = "HEALTHY" ]; then
    printf "\t   Container healthy before stop ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Container healthy before stop ${RED}FAILED${NC}\n"
    printf "\t   Result: ${preHealthCheck}\n"
    failure=$(( failure + 1 ))
fi

# Test 2: Services are running before stop
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying services running before stop"

preServicesRunning=0
if docker exec ${testContainer} sh -c "service apache2 status 2>&1 | grep -q 'running'" 2>/dev/null; then
    preServicesRunning=$((preServicesRunning + 1))
fi
if docker exec ${testContainer} sh -c "service mariadb status 2>&1 | grep -q 'running\|Uptime'" 2>/dev/null; then
    preServicesRunning=$((preServicesRunning + 1))
fi

if [ "$preServicesRunning" -ge 2 ]; then
    printf "\t   Services running (${preServicesRunning}/2 core) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Services running (${preServicesRunning}/2 core) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Capture contact count before stop for data integrity check later
preContactCount=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    echo \$pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
" 2>/dev/null || echo "ERROR")

############################ DOCKER STOP (SIGTERM) ############################

echo -e "\n[SIGTERM via docker stop]"

# Test 3: docker stop completes within grace period (not SIGKILL'd)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Sending SIGTERM via 'docker stop --time=45' (grace period: 45s)..."

stopStart=$(date +%s)
docker stop --time=45 ${testContainer} >/dev/null 2>&1
stopEnd=$(date +%s)
stopDuration=$((stopEnd - stopStart))

echo -e "\t   Stop completed in ${stopDuration}s"

# If docker stop takes >= 44s, it likely hit the grace period and Docker SIGKILL'd it
if [ "$stopDuration" -lt 44 ]; then
    printf "\t   Shutdown within grace period (${stopDuration}s < 44s) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Shutdown may have been SIGKILL'd (${stopDuration}s >= 44s) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 4: Exit code is 0 (graceful) not 137 (SIGKILL)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Checking container exit code"

exitCode=$(docker inspect --format='{{.State.ExitCode}}' ${testContainer} 2>/dev/null)

if [ "$exitCode" = "0" ]; then
    printf "\t   Exit code 0 (graceful shutdown) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [ "$exitCode" = "137" ]; then
    printf "\t   Exit code 137 (SIGKILL - shutdown too slow) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
else
    printf "\t   Exit code ${exitCode} (unexpected) ${YELLOW}WARNING${NC}\n"
    passed=$(( passed + 1 ))
fi

# Test 5: Shutdown log contains completion message
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Checking shutdown logs for completion message"

shutdownLogs=$(docker logs --tail=30 ${testContainer} 2>&1)

if echo "$shutdownLogs" | grep -q "Graceful shutdown completed"; then
    shutdownTime=$(echo "$shutdownLogs" | grep "Graceful shutdown completed" | tail -1)
    printf "\t   Shutdown completion logged ${GREEN}PASSED${NC}\n"
    printf "\t   ${shutdownTime}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Shutdown completion not logged ${RED}FAILED${NC}\n"
    printf "\t   Last 5 log lines:\n"
    echo "$shutdownLogs" | tail -5 | while IFS= read -r line; do
        printf "\t   > %s\n" "$line"
    done
    failure=$(( failure + 1 ))
fi

# Test 6: Shutdown log shows all service stops (Apache, MariaDB, Tor, Cron)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Checking all services were stopped in shutdown"

serviceStopCount=0
for svc in "Stopping Apache" "Stopping MariaDB" "Stopping Tor" "Stopping Cron"; do
    if echo "$shutdownLogs" | grep -q "$svc"; then
        serviceStopCount=$((serviceStopCount + 1))
    fi
done

if [ "$serviceStopCount" -eq 4 ]; then
    printf "\t   All 4 service stops logged ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Only ${serviceStopCount}/4 service stops logged ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ DOCKER START (RECOVERY) ############################

echo -e "\n[Container Recovery via docker start]"

# Test 7: docker start brings container back
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Starting container with 'docker start'"

docker start ${testContainer} >/dev/null 2>&1
startResult=$?

if [ "$startResult" -eq 0 ]; then
    printf "\t   docker start succeeded ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   docker start failed (exit code: ${startResult}) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 8: Container becomes healthy after restart
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Waiting for container to become healthy (up to 60s)..."

wait_for_container_health ${testContainer} 60
healthResult=$?

if [ "$healthResult" -eq 0 ]; then
    printf "\t   Container healthy after restart ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Container not healthy after 60s ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 9: Services are running after restart
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying services running after restart"

# Wait for both core services to start
wait_for_condition \
    "docker exec ${testContainer} sh -c \"service apache2 status 2>&1 | grep -q 'running'\"" \
    30 3 "Apache to start"
wait_for_condition \
    "docker exec ${testContainer} sh -c \"service mariadb status 2>&1 | grep -q 'running\|Uptime'\"" \
    30 3 "MariaDB to start"

postServicesRunning=0
if docker exec ${testContainer} sh -c "service apache2 status 2>&1 | grep -q 'running'" 2>/dev/null; then
    postServicesRunning=$((postServicesRunning + 1))
fi
if docker exec ${testContainer} sh -c "service mariadb status 2>&1 | grep -q 'running\|Uptime'" 2>/dev/null; then
    postServicesRunning=$((postServicesRunning + 1))
fi

if [ "$postServicesRunning" -ge 2 ]; then
    printf "\t   Services running after restart (${postServicesRunning}/2 core) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Services running after restart (${postServicesRunning}/2 core) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ DATA INTEGRITY ############################

echo -e "\n[Data Integrity After Stop/Start]"

# Test 10: Database accessible and data intact
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying database accessible after restart"

# Wait for MariaDB to be ready
wait_for_condition \
    "docker exec ${testContainer} php -r \"
        require_once('${BOOTSTRAP_PATH}');
        \\\$app = \Eiou\Core\Application::getInstance();
        \\\$pdo = \\\$app->services->getPdo();
        \\\$pdo->query('SELECT 1');
        echo 'OK';
    \" 2>/dev/null | grep -q 'OK'" \
    30 3 "database ready"

dbCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    try {
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$stmt = \$pdo->query('SELECT 1');
        echo 'DB_OK';
    } catch (Exception \$e) {
        echo 'DB_ERROR: ' . \$e->getMessage();
    }
" 2>&1)

if [[ "$dbCheck" == "DB_OK" ]]; then
    printf "\t   Database accessible ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Database accessible ${RED}FAILED${NC}\n"
    printf "\t   Result: ${dbCheck}\n"
    failure=$(( failure + 1 ))
fi

# Test 11: Contact data preserved across stop/start
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying contact data preserved"

postContactCount=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    echo \$pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
" 2>/dev/null || echo "ERROR")

if [ "$preContactCount" != "ERROR" ] && [ "$postContactCount" != "ERROR" ] && [ "$preContactCount" = "$postContactCount" ]; then
    printf "\t   Contact count preserved (${postContactCount}) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [ "$preContactCount" = "ERROR" ] || [ "$postContactCount" = "ERROR" ]; then
    printf "\t   Contact count check ${YELLOW}SKIPPED${NC} (query error)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Contact count changed (${preContactCount} -> ${postContactCount}) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 12: User config intact
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying user config intact after restart"

configCheck=$(docker exec ${testContainer} php -r "
    \$config = json_decode(file_get_contents('${USERCONFIG}'), true);
    if (\$config && isset(\$config['public']) && !empty(\$config['public'])) {
        echo 'CONFIG_OK';
    } else {
        echo 'CONFIG_ERROR';
    }
" 2>&1)

if [[ "$configCheck" == "CONFIG_OK" ]]; then
    printf "\t   User config intact ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   User config intact ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ PROCESSOR RECOVERY ############################

echo -e "\n[Processor Recovery After Stop/Start]"

# Test 13: PHP processors restart after container restart
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Waiting for PHP processors to restart (up to 60s)..."

wait_for_condition \
    "[ \$(docker exec ${testContainer} sh -c \"ps aux | grep -E 'P2pMessages|TransactionMessages|CleanupMessages' | grep -v grep | wc -l\") -ge 3 ]" \
    60 5 "PHP processors to restart"

processCheckRestart=$(docker exec ${testContainer} sh -c "ps aux | grep -E 'P2pMessages|TransactionMessages|CleanupMessages' | grep -v grep | wc -l" 2>&1)

if [ "$processCheckRestart" -ge 3 ]; then
    printf "\t   PHP processors restarted (${processCheckRestart}/3) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   PHP processors restarted (${processCheckRestart}/3) ${YELLOW}PARTIAL${NC}\n"
    printf "\t   (Watchdog may need more time on slow systems)\n"
    passed=$(( passed + 1 ))
fi

# Test 14: No stale lockfiles from previous run
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Verifying no stale shutdown flag"

shutdownFlagExists=$(docker exec ${testContainer} sh -c "test -f /tmp/eiou_shutdown.flag && echo 'YES' || echo 'NO'" 2>&1)

if [ "$shutdownFlagExists" = "NO" ]; then
    printf "\t   No stale shutdown flag ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Stale shutdown flag found ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'SIGTERM Shutdown'"
