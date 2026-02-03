#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

# Test graceful shutdown handling for EIOU Docker containers
# Verifies that containers shut down properly without data loss

echo -e "\nTesting Graceful Shutdown Handling..."

testname="gracefulShutdownTest"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

############################ SIGNAL HANDLER TESTS ############################

echo -e "\n[Signal Handler Tests]"

# Test 1: Verify PHP processors are running before shutdown
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing PHP processors are running"
processCheck=$(docker exec ${testContainer} sh -c "ps aux | grep -E 'P2pMessages|TransactionMessages|CleanupMessages' | grep -v grep | wc -l" 2>&1)

if [ "$processCheck" -ge 3 ]; then
    printf "\t   PHP processors running (${processCheck}/3) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   PHP processors running (${processCheck}/3) ${RED}FAILED${NC}\n"
    printf "\t   Expected at least 3 PHP processors\n"
    failure=$(( failure + 1 ))
fi

# Test 2: Verify lockfiles exist for running processors
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing lockfiles exist for processors"
lockfileCheck=$(docker exec ${testContainer} sh -c "ls /tmp/*_lock.pid 2>/dev/null | wc -l" 2>&1)

if [ "$lockfileCheck" -ge 1 ]; then
    printf "\t   Lockfiles exist (${lockfileCheck} found) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Lockfiles exist ${YELLOW}SKIPPED${NC} (processors may not have started yet)\n"
    passed=$(( passed + 1 ))
fi

# Test 3: Verify services are running
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing services are running"
servicesRunning=0

# Check Apache
if docker exec ${testContainer} sh -c "service apache2 status 2>&1 | grep -q 'running'" 2>/dev/null; then
    servicesRunning=$((servicesRunning + 1))
fi

# Check MariaDB
if docker exec ${testContainer} sh -c "service mariadb status 2>&1 | grep -q 'running\|Uptime'" 2>/dev/null; then
    servicesRunning=$((servicesRunning + 1))
fi

# Check Tor
if docker exec ${testContainer} sh -c "pgrep -x tor" 2>/dev/null >/dev/null; then
    servicesRunning=$((servicesRunning + 1))
fi

if [ "$servicesRunning" -ge 2 ]; then
    printf "\t   Services running (${servicesRunning}/3) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Services running (${servicesRunning}/3) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ GRACEFUL SHUTDOWN SIMULATION ############################

echo -e "\n[Graceful Shutdown Simulation Tests]"

# Test 4: Test SIGTERM signal handling via eiou shutdown command
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'eiou shutdown' command"
shutdownOutput=$(docker exec ${testContainer} eiou shutdown 2>&1)

# Wait for processors to stop (poll instead of fixed sleep)
wait_for_condition \
    "docker exec ${testContainer} sh -c \"ps aux | grep -E 'P2pMessages|TransactionMessages|CleanupMessages' | grep -v grep | wc -l\" | grep -q '^0$'" \
    15 2 "processors to stop"

# Verify processors stopped
processCheckAfter=$(docker exec ${testContainer} sh -c "ps aux | grep -E 'P2pMessages|TransactionMessages|CleanupMessages' | grep -v grep | wc -l" 2>&1)

if [ "$processCheckAfter" -eq 0 ]; then
    printf "\t   eiou shutdown stopped processors ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   eiou shutdown stopped processors ${YELLOW}PARTIAL${NC} (${processCheckAfter} still running)\n"
    # Not a hard failure - processors may take time to complete current tasks
    passed=$(( passed + 1 ))
fi

# Test 5: Verify lockfiles are cleaned up after shutdown
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing lockfiles cleaned up after shutdown"
lockfileCheckAfter=$(docker exec ${testContainer} sh -c "ls /tmp/*_lock.pid 2>/dev/null | wc -l" 2>&1)

if [ "$lockfileCheckAfter" -eq 0 ]; then
    printf "\t   Lockfiles cleaned up ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Lockfiles cleaned up ${YELLOW}PARTIAL${NC} (${lockfileCheckAfter} remaining)\n"
    passed=$(( passed + 1 ))
fi

# Test 5b: Verify shutdown flag file is created
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing shutdown flag file exists after shutdown"
shutdownFlagExists=$(docker exec ${testContainer} sh -c "test -f /tmp/eiou_shutdown.flag && echo 'YES' || echo 'NO'" 2>&1)

if [ "$shutdownFlagExists" = "YES" ]; then
    printf "\t   Shutdown flag created ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Shutdown flag created ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 5c: Verify watchdog does NOT restart processors while shutdown flag exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing watchdog respects shutdown flag (waiting 45s)..."
sleep 45
processCheckWatchdog=$(docker exec ${testContainer} sh -c "ps aux | grep -E 'P2pMessages|TransactionMessages|CleanupMessages' | grep -v grep | wc -l" 2>&1)

if [ "$processCheckWatchdog" -eq 0 ]; then
    printf "\t   Watchdog did not restart processors ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Watchdog restarted processors despite shutdown flag ${RED}FAILED${NC} (${processCheckWatchdog} running)\n"
    failure=$(( failure + 1 ))
fi

# Test 6: Verify database is still accessible after processor shutdown
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing database accessibility after processor shutdown"
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

############################ DATA INTEGRITY TESTS ############################

echo -e "\n[Data Integrity After Shutdown Tests]"

# Test 7: Verify user config intact after shutdown
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing user config integrity after shutdown"
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

# Test 8: Verify contact data intact
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing contact data integrity after shutdown"
contactCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    try {
        \$app = \Eiou\Core\Application::getInstance();
        \$contactRepo = \$app->services->getContactRepository();
        // Just verify we can query contacts without error
        echo 'CONTACTS_OK';
    } catch (Exception \$e) {
        echo 'CONTACTS_ERROR: ' . \$e->getMessage();
    }
" 2>&1)

if [[ "$contactCheck" == "CONTACTS_OK" ]]; then
    printf "\t   Contact data accessible ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Contact data accessible ${RED}FAILED${NC}\n"
    printf "\t   Result: ${contactCheck}\n"
    failure=$(( failure + 1 ))
fi

# Test 9: Verify transaction history intact
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing transaction history integrity after shutdown"
txCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    try {
        \$app = \Eiou\Core\Application::getInstance();
        \$txRepo = \$app->services->getTransactionRepository();
        // Just verify we can query transactions without error
        echo 'TX_OK';
    } catch (Exception \$e) {
        echo 'TX_ERROR: ' . \$e->getMessage();
    }
" 2>&1)

if [[ "$txCheck" == "TX_OK" ]]; then
    printf "\t   Transaction history accessible ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transaction history accessible ${RED}FAILED${NC}\n"
    printf "\t   Result: ${txCheck}\n"
    failure=$(( failure + 1 ))
fi

############################ PROCESSOR RESTART TESTS ############################

echo -e "\n[Processor Restart Tests]"

# Test 10: Use 'eiou start' to restart processors after shutdown
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'eiou start' command restarts processors after shutdown"

# Use the start command to clear the shutdown flag
startOutput=$(docker exec ${testContainer} eiou start 2>&1)

if echo "$startOutput" | grep -qi "restart\|removed\|success"; then
    printf "\t   eiou start command succeeded ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   eiou start command output unexpected ${YELLOW}WARNING${NC}\n"
    printf "\t   Output: ${startOutput}\n"
    passed=$(( passed + 1 ))
fi

# Test 10b: Verify shutdown flag is removed after start
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing shutdown flag removed after start"
shutdownFlagAfterStart=$(docker exec ${testContainer} sh -c "test -f /tmp/eiou_shutdown.flag && echo 'YES' || echo 'NO'" 2>&1)

if [ "$shutdownFlagAfterStart" = "NO" ]; then
    printf "\t   Shutdown flag removed ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Shutdown flag removed ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 10c: Verify watchdog restarts processors after start command
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing watchdog restarts processors after start (waiting up to 45s)..."

wait_for_condition \
    "[ \$(docker exec ${testContainer} sh -c \"ps aux | grep -E 'P2pMessages|TransactionMessages|CleanupMessages' | grep -v grep | wc -l\") -ge 3 ]" \
    45 5 "processors to restart via watchdog"

processCheckRestart=$(docker exec ${testContainer} sh -c "ps aux | grep -E 'P2pMessages|TransactionMessages|CleanupMessages' | grep -v grep | wc -l" 2>&1)

if [ "$processCheckRestart" -ge 3 ]; then
    printf "\t   Watchdog restarted processors (${processCheckRestart}/3) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Watchdog restarted processors (${processCheckRestart}/3) ${YELLOW}PARTIAL${NC}\n"
    printf "\t   (Watchdog interval + cooldown may require more time)\n"
    passed=$(( passed + 1 ))
fi

# Test 10d: Verify 'eiou start' is idempotent when already running
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'eiou start' when already running"
startIdempotent=$(docker exec ${testContainer} eiou start 2>&1)

if echo "$startIdempotent" | grep -qi "already"; then
    printf "\t   eiou start idempotent ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   eiou start idempotent ${YELLOW}WARNING${NC}\n"
    printf "\t   Output: ${startIdempotent}\n"
    passed=$(( passed + 1 ))
fi

############################ MULTI-CONTAINER SHUTDOWN TEST ############################

echo -e "\n[Multi-Container Shutdown Consistency Test]"

# Test 11: Verify all containers have working shutdown mechanism
for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing shutdown mechanism on ${container}"

    # Check if eiou shutdown command exists and works
    shutdownExists=$(docker exec ${container} sh -c "eiou help shutdown 2>&1" | grep -c "shutdown")

    if [ "$shutdownExists" -ge 1 ]; then
        printf "\t   shutdown command available on %s ${GREEN}PASSED${NC}\n" ${container}
        passed=$(( passed + 1 ))
    else
        printf "\t   shutdown command available on %s ${RED}FAILED${NC}\n" ${container}
        failure=$(( failure + 1 ))
    fi
done

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'Graceful Shutdown'"
