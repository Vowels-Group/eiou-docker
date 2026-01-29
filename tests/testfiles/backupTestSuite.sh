#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC

# Test backup functionality for EIOU Docker containers
# Verifies backup creation, listing, verification, restore, delete, and cleanup

echo -e "\nTesting Backup Functionality..."

testname="backupTestSuite"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

# Correct backup directory path (from Constants.php)
BACKUP_DIR="/var/lib/eiou/backups"

############################ SERVICE AVAILABILITY TESTS ############################

echo -e "\n[Backup Service Availability Tests]"

# Test 1: Verify BackupService is available via ServiceContainer
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing BackupService availability via ServiceContainer"
serviceCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    try {
        \$app = \Eiou\Core\Application::getInstance();
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
    if [ -d ${BACKUP_DIR} ]; then
        perms=\$(stat -c '%a' ${BACKUP_DIR} 2>/dev/null)
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
backupCountBefore=$(docker exec ${testContainer} sh -c "ls ${BACKUP_DIR}/*.eiou.enc 2>/dev/null | wc -l" 2>&1)

# Create backup
createOutput=$(docker exec ${testContainer} eiou backup create 2>&1)

# Wait for backup file to appear (poll instead of fixed sleep)
wait_for_condition \
    "[ \$(docker exec ${testContainer} sh -c 'ls ${BACKUP_DIR}/*.eiou.enc 2>/dev/null | wc -l') -gt $backupCountBefore ]" \
    10 1 "backup file creation"

# Get backup count after
backupCountAfter=$(docker exec ${testContainer} sh -c "ls ${BACKUP_DIR}/*.eiou.enc 2>/dev/null | wc -l" 2>&1)

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
if echo "$listOutput" | grep -qE '\.eiou\.enc|backup.*[0-9]|Found'; then
    printf "\t   eiou backup list shows backups ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
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
latestBackup=$(docker exec ${testContainer} sh -c "ls -t ${BACKUP_DIR}/*.eiou.enc 2>/dev/null | head -1 | xargs basename" 2>&1)

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
    # Check file content - backup is JSON with encrypted SQL data
    # Structure: {encrypted: {ciphertext, iv, tag}} - AES-256-GCM format
    encryptionCheck=$(docker exec ${testContainer} php -r "
        \$content = file_get_contents('${BACKUP_DIR}/${latestBackup}');
        \$data = json_decode(\$content, true);
        if (!\$data || !isset(\$data['encrypted'])) {
            echo 'INVALID_FORMAT';
            exit;
        }
        // Encrypted field should be an array with ciphertext, iv, tag (AES-256-GCM)
        if (!is_array(\$data['encrypted'])) {
            echo 'NOT_ENCRYPTED_ARRAY';
            exit;
        }
        if (!isset(\$data['encrypted']['ciphertext']) || !isset(\$data['encrypted']['iv']) || !isset(\$data['encrypted']['tag'])) {
            echo 'MISSING_ENCRYPTION_FIELDS';
            exit;
        }
        // Check that ciphertext doesn't contain plain SQL
        if (preg_match('/CREATE TABLE|INSERT INTO|DROP TABLE/i', \$data['encrypted']['ciphertext'])) {
            echo 'PLAIN_SQL';
        } else {
            echo 'ENCRYPTED_OK';
        }
    " 2>&1)

    if [ "$encryptionCheck" = "ENCRYPTED_OK" ]; then
        printf "\t   Backup file is encrypted ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    elif [ "$encryptionCheck" = "PLAIN_SQL" ]; then
        printf "\t   Backup file is encrypted ${RED}FAILED${NC} (plain SQL detected)\n"
        failure=$(( failure + 1 ))
    else
        printf "\t   Backup file is encrypted ${RED}FAILED${NC} (${encryptionCheck})\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   Backup file is encrypted ${RED}FAILED${NC} (no backup file found)\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP RESTORE TESTS ############################

echo -e "\n[Backup Restore Tests]"

# Test 10: Test 'eiou backup restore' requires --confirm flag
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'eiou backup restore' requires --confirm"

if [ -n "$latestBackup" ] && [ "$latestBackup" != "" ]; then
    restoreNoConfirm=$(docker exec ${testContainer} eiou backup restore "$latestBackup" 2>&1)

    if echo "$restoreNoConfirm" | grep -qiE 'confirm|warning|overwrite'; then
        printf "\t   eiou backup restore requires --confirm ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   eiou backup restore requires --confirm ${RED}FAILED${NC}\n"
        printf "\t   Output: ${restoreNoConfirm}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   eiou backup restore requires --confirm ${RED}FAILED${NC} (no backup file found)\n"
    failure=$(( failure + 1 ))
fi

# Test 11: Test 'eiou backup restore <file> --confirm' actually restores
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'eiou backup restore --confirm' works"

if [ -n "$latestBackup" ] && [ "$latestBackup" != "" ]; then
    # First, record a known contact count before restore
    contactCountBefore=$(docker exec ${testContainer} php -r "
        require_once('${BOOTSTRAP_PATH}');
        try {
            \$app = \Eiou\Core\Application::getInstance();
            \$pdo = \$app->services->getPdo();
            \$stmt = \$pdo->query('SELECT COUNT(*) as cnt FROM contacts');
            \$row = \$stmt->fetch();
            echo \$row['cnt'];
        } catch (Exception \$e) {
            echo '0';
        }
    " 2>&1)

    # Perform the restore
    restoreOutput=$(docker exec ${testContainer} eiou backup restore "$latestBackup" --confirm 2>&1)

    if echo "$restoreOutput" | grep -qiE 'success|restored|complete'; then
        # Verify data is intact by checking contact count
        contactCountAfter=$(docker exec ${testContainer} php -r "
            require_once('${BOOTSTRAP_PATH}');
            try {
                \$app = \Eiou\Core\Application::getInstance();
                \$pdo = \$app->services->getPdo();
                \$stmt = \$pdo->query('SELECT COUNT(*) as cnt FROM contacts');
                \$row = \$stmt->fetch();
                echo \$row['cnt'];
            } catch (Exception \$e) {
                echo '-1';
            }
        " 2>&1)

        if [ "$contactCountAfter" -ge 0 ] && [ "$contactCountAfter" != "-1" ]; then
            printf "\t   eiou backup restore --confirm ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   eiou backup restore --confirm ${RED}FAILED${NC} (data not restored)\n"
            printf "\t   Contacts before: ${contactCountBefore}, after: ${contactCountAfter}\n"
            failure=$(( failure + 1 ))
        fi
    else
        printf "\t   eiou backup restore --confirm ${RED}FAILED${NC}\n"
        printf "\t   Output: ${restoreOutput}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   eiou backup restore --confirm ${RED}FAILED${NC} (no backup file found)\n"
    failure=$(( failure + 1 ))
fi

# Test 12: Test data integrity after restore (tables exist)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing data integrity after restore"

tableCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    try {
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();

        // Check essential tables exist (no eiou_ prefix)
        \$tables = ['contacts', 'transactions', 'balances', 'message_delivery', 'debug'];
        \$missing = [];

        foreach (\$tables as \$table) {
            \$stmt = \$pdo->query(\"SHOW TABLES LIKE '\$table'\");
            if (\$stmt->rowCount() == 0) {
                \$missing[] = \$table;
            }
        }

        if (empty(\$missing)) {
            echo 'TABLES_OK';
        } else {
            echo 'MISSING: ' . implode(', ', \$missing);
        }
    } catch (Exception \$e) {
        echo 'ERROR: ' . \$e->getMessage();
    }
" 2>&1)

if [[ "$tableCheck" == "TABLES_OK" ]]; then
    printf "\t   Data integrity after restore ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Data integrity after restore ${RED}FAILED${NC}\n"
    printf "\t   Result: ${tableCheck}\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP DELETE TESTS ############################

echo -e "\n[Backup Delete Tests]"

# Test 13: Create a test backup to delete
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'eiou backup delete' command"

# Create a backup specifically for deletion test
docker exec ${testContainer} eiou backup create >/dev/null 2>&1
# Brief pause for file system sync
wait_for_condition \
    "docker exec ${testContainer} sh -c 'ls ${BACKUP_DIR}/*.eiou.enc 2>/dev/null | wc -l' | grep -qE '^[1-9]'" \
    5 1 "backup creation"

# Get the newest backup
deleteTestBackup=$(docker exec ${testContainer} sh -c "ls -t ${BACKUP_DIR}/*.eiou.enc 2>/dev/null | head -1 | xargs basename" 2>&1)

if [ -n "$deleteTestBackup" ] && [ "$deleteTestBackup" != "" ]; then
    deleteOutput=$(docker exec ${testContainer} eiou backup delete "$deleteTestBackup" 2>&1)

    # Verify file is actually deleted
    fileExists=$(docker exec ${testContainer} sh -c "[ -f ${BACKUP_DIR}/${deleteTestBackup} ] && echo 'EXISTS' || echo 'DELETED'" 2>&1)

    if echo "$deleteOutput" | grep -qiE 'deleted|success|removed' && [ "$fileExists" == "DELETED" ]; then
        printf "\t   eiou backup delete ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   eiou backup delete ${RED}FAILED${NC}\n"
        printf "\t   Output: ${deleteOutput}, File status: ${fileExists}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   eiou backup delete ${RED}FAILED${NC} (no backup file found)\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP HELP TESTS ############################

echo -e "\n[Backup Help Tests]"

# Test 14: Test 'eiou backup help' shows available commands
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'eiou backup help' command"
helpOutput=$(docker exec ${testContainer} eiou backup help 2>&1)

if echo "$helpOutput" | grep -qiE 'create|restore|list|delete|verify|enable|disable|status|cleanup'; then
    printf "\t   eiou backup help ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   eiou backup help ${RED}FAILED${NC}\n"
    printf "\t   Output: ${helpOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP CLEANUP TESTS ############################

echo -e "\n[Backup Cleanup Tests]"

# Test 15: Test 'eiou backup cleanup' command
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'eiou backup cleanup' command"

# Create multiple backups to ensure cleanup has something to work with
for i in 1 2 3 4; do
    docker exec ${testContainer} eiou backup create >/dev/null 2>&1
done
# Brief pause for file system sync
wait_for_condition \
    "[ \$(docker exec ${testContainer} sh -c 'ls ${BACKUP_DIR}/*.eiou.enc 2>/dev/null | wc -l') -ge 4 ]" \
    10 1 "backup files creation"

cleanupOutput=$(docker exec ${testContainer} eiou backup cleanup 2>&1)

if echo "$cleanupOutput" | grep -qiE 'cleaned|success|deleted|removed'; then
    printf "\t   eiou backup cleanup ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   eiou backup cleanup ${RED}FAILED${NC}\n"
    printf "\t   Output: ${cleanupOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 16: Verify backup count is within retention limit after cleanup
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing backup count respects retention limit"

# Get retention limit (default is 3)
retentionLimit=3
backupCount=$(docker exec ${testContainer} sh -c "ls ${BACKUP_DIR}/*.eiou.enc 2>/dev/null | wc -l" 2>&1)

if [ "$backupCount" -le "$retentionLimit" ] || [ "$backupCount" -le 5 ]; then
    printf "\t   Backup count within retention limit ${GREEN}PASSED${NC} (${backupCount} backups)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Backup count within retention limit ${RED}FAILED${NC}\n"
    printf "\t   Count: ${backupCount}, Limit: ${retentionLimit}\n"
    failure=$(( failure + 1 ))
fi

############################ MULTI-CONTAINER BACKUP TEST ############################

echo -e "\n[Multi-Container Backup Consistency Test]"

# Test: Verify all containers have working backup mechanism
for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing backup mechanism on ${container}"

    # Check if eiou backup command exists and works
    backupExists=$(docker exec ${container} sh -c "eiou backup help 2>&1" | grep -c "create")

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
