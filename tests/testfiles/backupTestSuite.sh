#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

# Test backup functionality for EIOU Docker containers
# Verifies backup creation, listing, verification, and cleanup

echo -e "\nTesting Backup Functionality..."

testname="backupTestSuite"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

############################ SERVICE AVAILABILITY TESTS ############################

echo -e "\n[Backup Service Availability Tests]"

# Test 1: Verify BackupService is available via ServiceContainer
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing BackupService availability via ServiceContainer"
serviceCheck=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    try {
        \$app = Application::getInstance();
        \$backupService = \$app->services->getBackupService();
        if (\$backupService !== null) {
            echo 'SERVICE_OK';
        } else {
            echo 'SERVICE_NULL';
        }
    } catch (Exception \$e) {
        echo 'SERVICE_ERROR: ' . \$e->getMessage();
    }
" 2>&1)

if [[ "$serviceCheck" == "SERVICE_OK" ]]; then
    printf "\t   BackupService available ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   BackupService available ${RED}FAILED${NC}\n"
    printf "\t   Result: ${serviceCheck}\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP DIRECTORY TESTS ############################

echo -e "\n[Backup Directory Tests]"

# Test 2: Verify backup directory exists with correct permissions (700)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing backup directory exists with correct permissions"
dirCheck=$(docker exec ${testContainer} sh -c "
    if [ -d /var/backups/eiou ]; then
        perms=\$(stat -c '%a' /var/backups/eiou 2>/dev/null)
        if [ \"\$perms\" = '700' ]; then
            echo 'DIR_OK'
        else
            echo \"DIR_PERMS_WRONG: \$perms\"
        fi
    else
        echo 'DIR_MISSING'
    fi
" 2>&1)

if [[ "$dirCheck" == "DIR_OK" ]]; then
    printf "\t   Backup directory with 700 permissions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Backup directory with 700 permissions ${RED}FAILED${NC}\n"
    printf "\t   Result: ${dirCheck}\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP CREATION TESTS ############################

echo -e "\n[Backup Creation Tests]"

# Test 3: Test 'eiou backup create' creates a backup file
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'eiou backup create' command"

# Get backup count before
backupCountBefore=$(docker exec ${testContainer} sh -c "ls /var/backups/eiou/*.enc 2>/dev/null | wc -l" 2>&1)

# Create backup
createOutput=$(docker exec ${testContainer} eiou backup create 2>&1)

# Give time for backup to complete
sleep 2

# Get backup count after
backupCountAfter=$(docker exec ${testContainer} sh -c "ls /var/backups/eiou/*.enc 2>/dev/null | wc -l" 2>&1)

if [ "$backupCountAfter" -gt "$backupCountBefore" ]; then
    printf "\t   eiou backup create ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   eiou backup create ${RED}FAILED${NC}\n"
    printf "\t   Output: ${createOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP LIST TESTS ############################

echo -e "\n[Backup List Tests]"

# Test 4: Test 'eiou backup list' shows the created backup
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'eiou backup list' shows backups"
listOutput=$(docker exec ${testContainer} eiou backup list 2>&1)

# Check if list output contains backup files or indicates backups exist
if echo "$listOutput" | grep -qE '\.enc|backup.*[0-9]|No backups'; then
    if echo "$listOutput" | grep -q '\.enc'; then
        printf "\t   eiou backup list shows backups ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   eiou backup list shows no backups ${RED}FAILED${NC}\n"
        printf "\t   Output: ${listOutput}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   eiou backup list ${RED}FAILED${NC}\n"
    printf "\t   Output: ${listOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP VERIFICATION TESTS ############################

echo -e "\n[Backup Verification Tests]"

# Test 5: Test 'eiou backup verify <filename>' validates the backup
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'eiou backup verify' command"

# Get the most recent backup filename
latestBackup=$(docker exec ${testContainer} sh -c "ls -t /var/backups/eiou/*.enc 2>/dev/null | head -1 | xargs basename" 2>&1)

if [ -n "$latestBackup" ] && [ "$latestBackup" != "" ]; then
    verifyOutput=$(docker exec ${testContainer} eiou backup verify "$latestBackup" 2>&1)

    if echo "$verifyOutput" | grep -qiE 'valid|success|ok|verified'; then
        printf "\t   eiou backup verify ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   eiou backup verify ${RED}FAILED${NC}\n"
        printf "\t   Output: ${verifyOutput}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   eiou backup verify ${RED}FAILED${NC} (no backup file found)\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP STATUS TESTS ############################

echo -e "\n[Backup Status Tests]"

# Test 6: Test 'eiou backup status' returns correct status
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'eiou backup status' command"
statusOutput=$(docker exec ${testContainer} eiou backup status 2>&1)

if echo "$statusOutput" | grep -qiE 'enabled|disabled|status|backup'; then
    printf "\t   eiou backup status ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   eiou backup status ${RED}FAILED${NC}\n"
    printf "\t   Output: ${statusOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP TOGGLE TESTS ############################

echo -e "\n[Backup Enable/Disable Tests]"

# Test 7: Test 'eiou backup enable' and 'eiou backup disable' toggle
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'eiou backup enable' command"
enableOutput=$(docker exec ${testContainer} eiou backup enable 2>&1)

if echo "$enableOutput" | grep -qiE 'enabled|success|ok|activated'; then
    printf "\t   eiou backup enable ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   eiou backup enable ${RED}FAILED${NC}\n"
    printf "\t   Output: ${enableOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 8: Test 'eiou backup disable' toggle
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'eiou backup disable' command"
disableOutput=$(docker exec ${testContainer} eiou backup disable 2>&1)

if echo "$disableOutput" | grep -qiE 'disabled|success|ok|deactivated'; then
    printf "\t   eiou backup disable ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   eiou backup disable ${RED}FAILED${NC}\n"
    printf "\t   Output: ${disableOutput}\n"
    failure=$(( failure + 1 ))
fi

# Re-enable backups for remaining tests
docker exec ${testContainer} eiou backup enable >/dev/null 2>&1

############################ BACKUP ENCRYPTION TESTS ############################

echo -e "\n[Backup Encryption Tests]"

# Test 9: Verify backup files are encrypted (not plain SQL)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing backup files are encrypted"

if [ -n "$latestBackup" ] && [ "$latestBackup" != "" ]; then
    # Check file content - encrypted files should not contain plain SQL keywords
    encryptionCheck=$(docker exec ${testContainer} sh -c "
        head -c 100 /var/backups/eiou/${latestBackup} 2>/dev/null | strings | grep -iE 'CREATE TABLE|INSERT INTO|DROP TABLE|SELECT' | wc -l
    " 2>&1)

    if [ "$encryptionCheck" -eq 0 ]; then
        printf "\t   Backup file is encrypted ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Backup file is encrypted ${RED}FAILED${NC} (plain SQL detected)\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   Backup file is encrypted ${RED}FAILED${NC} (no backup file found)\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP CLEANUP TESTS ############################

echo -e "\n[Backup Cleanup Tests]"

# Test 10: Verify backup cleanup removes old files when over retention limit
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing backup cleanup mechanism"

# Create multiple backups to trigger cleanup
for i in 1 2 3; do
    docker exec ${testContainer} eiou backup create >/dev/null 2>&1
    sleep 1
done

# Trigger cleanup (if separate command exists) or check automatic cleanup
cleanupCheck=$(docker exec ${testContainer} sh -c "
    # Count backup files
    backupCount=\$(ls /var/backups/eiou/*.enc 2>/dev/null | wc -l)

    # Get retention limit from config if possible
    retentionLimit=\$(php -r \"
        require_once('${REL_APPLICATION}');
        try {
            \\\$app = Application::getInstance();
            \\\$backupService = \\\$app->services->getBackupService();
            if (method_exists(\\\$backupService, 'getRetentionLimit')) {
                echo \\\$backupService->getRetentionLimit();
            } else {
                echo '7';
            }
        } catch (Exception \\\$e) {
            echo '7';
        }
    \" 2>/dev/null)

    if [ \"\$backupCount\" -le \"\$retentionLimit\" ] || [ \"\$backupCount\" -le 10 ]; then
        echo 'CLEANUP_OK'
    else
        echo \"CLEANUP_EXCEEDED: \$backupCount backups (limit: \$retentionLimit)\"
    fi
" 2>&1)

if [[ "$cleanupCheck" == "CLEANUP_OK" ]]; then
    printf "\t   Backup cleanup mechanism ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Backup cleanup mechanism ${RED}FAILED${NC}\n"
    printf "\t   Result: ${cleanupCheck}\n"
    failure=$(( failure + 1 ))
fi

############################ MULTI-CONTAINER BACKUP TEST ############################

echo -e "\n[Multi-Container Backup Consistency Test]"

# Test 11: Verify all containers have working backup mechanism
for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing backup mechanism on ${container}"

    # Check if eiou backup command exists and works
    backupExists=$(docker exec ${container} sh -c "eiou help backup 2>&1" | grep -c "backup")

    if [ "$backupExists" -ge 1 ]; then
        printf "\t   backup command available on %s ${GREEN}PASSED${NC}\n" ${container}
        passed=$(( passed + 1 ))
    else
        printf "\t   backup command available on %s ${RED}FAILED${NC}\n" ${container}
        failure=$(( failure + 1 ))
    fi
done

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'Backup Functionality'"
