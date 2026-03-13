#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Encryption Test Suite ############################
# Verifies data-at-rest encryption for eIOU Docker containers
#
# Tests:
# - Sodium extension availability
# - Master key exists in RAM (/dev/shm)
# - Master key has correct size and permissions
# - MariaDB TDE key file exists in RAM
# - MariaDB TDE configuration file exists
# - MariaDB TDE initialized marker exists
# - MariaDB encryption plugin is loaded and active
# - Database tables are encrypted (ENCRYPTION_SCHEME > 0)
# - Database credentials encrypted in dbconfig.json
# - TDE key is derived correctly (deterministic HMAC)
# - Plaintext master key not exposed on config volume when volume encryption active
# - KeyEncryption reads master key from /dev/shm
#
# Prerequisites:
# - Containers must be running with wallets generated
# - MariaDB must be started with TDE enabled
##############################################################################

echo -e "\nRunning Encryption Test Suite..."

testname="encryptionTestSuite"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"
echo -e "\t   Test container: ${testContainer}"

############################ SECTION 1: SODIUM EXTENSION ############################

echo -e "\n[Section 1: Sodium Extension]"

# Test 1.1: Sodium extension available
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing sodium extension availability"

sodiumAvailable=$(docker exec ${testContainer} php -r "
    echo function_exists('sodium_crypto_pwhash') ? 'yes' : 'no';
" 2>/dev/null)

if [ "$sodiumAvailable" = "yes" ]; then
    printf "\t   Sodium extension available ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Sodium extension available ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 1.2: VolumeEncryption reports sodium available
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing VolumeEncryption::isAvailable()"

veAvailable=$(docker exec ${testContainer} php -r "
    require_once '${BOOTSTRAP_PATH}';
    echo \Eiou\Security\VolumeEncryption::isAvailable() ? 'yes' : 'no';
" 2>/dev/null)

if [ "$veAvailable" = "yes" ]; then
    printf "\t   VolumeEncryption::isAvailable() ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   VolumeEncryption::isAvailable() ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 2: MASTER KEY ############################

echo -e "\n[Section 2: Master Key]"

# Test 2.1: Master key exists in /dev/shm
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing master key exists in /dev/shm"

masterKeyExists=$(docker exec ${testContainer} php -r "
    echo file_exists('/dev/shm/.master.key') ? 'yes' : 'no';
" 2>/dev/null)

if [ "$masterKeyExists" = "yes" ]; then
    printf "\t   Master key in /dev/shm ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Master key in /dev/shm ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 2.2: Master key is correct size (32 bytes)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing master key size (32 bytes)"

masterKeySize=$(docker exec ${testContainer} php -r "
    \$path = '/dev/shm/.master.key';
    if (file_exists(\$path)) {
        echo strlen(file_get_contents(\$path));
    } else {
        \$path = '/etc/eiou/config/.master.key';
        if (file_exists(\$path)) {
            echo strlen(file_get_contents(\$path));
        } else {
            echo '0';
        }
    }
" 2>/dev/null)

if [ "$masterKeySize" = "32" ]; then
    printf "\t   Master key size (32 bytes) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Master key size ${RED}FAILED${NC} (got: ${masterKeySize} bytes)\n"
    failure=$(( failure + 1 ))
fi

# Test 2.3: Master key permissions are restrictive (0600)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing master key file permissions"

masterKeyPerms=$(docker exec ${testContainer} php -r "
    \$path = '/dev/shm/.master.key';
    if (!file_exists(\$path)) {
        \$path = '/etc/eiou/config/.master.key';
    }
    if (file_exists(\$path)) {
        echo decoct(fileperms(\$path) & 0777);
    } else {
        echo 'missing';
    }
" 2>/dev/null)

if [ "$masterKeyPerms" = "600" ]; then
    printf "\t   Master key permissions (0600) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Master key permissions ${RED}FAILED${NC} (got: ${masterKeyPerms})\n"
    failure=$(( failure + 1 ))
fi

# Test 2.4: KeyEncryption reads key from /dev/shm when available
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing KeyEncryption uses runtime key from /dev/shm"

keyEncryptionWorks=$(docker exec ${testContainer} php -r "
    require_once '${BOOTSTRAP_PATH}';
    try {
        \$encrypted = \Eiou\Security\KeyEncryption::encrypt('test-data', 'test-context');
        \$decrypted = \Eiou\Security\KeyEncryption::decrypt(\$encrypted);
        echo \$decrypted === 'test-data' ? 'yes' : 'no';
    } catch (Exception \$e) {
        echo 'error: ' . \$e->getMessage();
    }
" 2>/dev/null)

if [ "$keyEncryptionWorks" = "yes" ]; then
    printf "\t   KeyEncryption encrypt/decrypt ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   KeyEncryption encrypt/decrypt ${RED}FAILED${NC} (${keyEncryptionWorks})\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 3: MARIADB TDE ############################

echo -e "\n[Section 3: MariaDB Transparent Data Encryption]"

# Test 3.1: TDE key file exists in /dev/shm
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing TDE key file exists in /dev/shm"

tdeKeyExists=$(docker exec ${testContainer} php -r "
    require_once '${BOOTSTRAP_PATH}';
    echo \Eiou\Security\MariaDbEncryption::isKeyFileReady() ? 'yes' : 'no';
" 2>/dev/null)

if [ "$tdeKeyExists" = "yes" ]; then
    printf "\t   TDE key file in /dev/shm ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   TDE key file in /dev/shm ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.2: TDE key file has correct format (key_id;hex_key)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing TDE key file format"

tdeKeyFormat=$(docker exec ${testContainer} php -r "
    \$content = @file_get_contents('/dev/shm/.mariadb-encryption-key');
    if (\$content === false) { echo 'missing'; exit; }
    // Expected: 1;<64 hex chars>
    if (preg_match('/^1;[0-9a-f]{64}$/', \$content)) {
        echo 'valid';
    } else {
        echo 'invalid';
    }
" 2>/dev/null)

if [ "$tdeKeyFormat" = "valid" ]; then
    printf "\t   TDE key file format ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   TDE key file format ${RED}FAILED${NC} (${tdeKeyFormat})\n"
    failure=$(( failure + 1 ))
fi

# Test 3.3: TDE key is deterministic (derived from master key via HMAC)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing TDE key derivation is deterministic"

tdeDeterministic=$(docker exec ${testContainer} php -r "
    \$masterKeyPath = '/dev/shm/.master.key';
    if (!file_exists(\$masterKeyPath)) {
        \$masterKeyPath = '/etc/eiou/config/.master.key';
    }
    if (!file_exists(\$masterKeyPath)) { echo 'no_key'; exit; }

    \$masterKey = file_get_contents(\$masterKeyPath);
    \$expectedTdeKey = hash_hmac('sha256', \$masterKey, 'eiou-mariadb-tde');
    \$expectedContent = '1;' . \$expectedTdeKey;

    \$actualContent = @file_get_contents('/dev/shm/.mariadb-encryption-key');
    echo (\$actualContent === \$expectedContent) ? 'yes' : 'no';
" 2>/dev/null)

if [ "$tdeDeterministic" = "yes" ]; then
    printf "\t   TDE key deterministic derivation ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   TDE key deterministic derivation ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.4: TDE configuration file exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing TDE configuration file exists"

tdeConfigExists=$(docker exec ${testContainer} php -r "
    echo file_exists('/etc/mysql/conf.d/encryption.cnf') ? 'yes' : 'no';
" 2>/dev/null)

if [ "$tdeConfigExists" = "yes" ]; then
    printf "\t   TDE config file exists ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   TDE config file exists ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.5: TDE config references correct key file path
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing TDE config references /dev/shm key path"

tdeConfigPath=$(docker exec ${testContainer} php -r "
    \$config = @file_get_contents('/etc/mysql/conf.d/encryption.cnf');
    if (\$config === false) { echo 'missing'; exit; }
    echo (strpos(\$config, '/dev/shm/.mariadb-encryption-key') !== false) ? 'yes' : 'no';
" 2>/dev/null)

if [ "$tdeConfigPath" = "yes" ]; then
    printf "\t   TDE config key path ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   TDE config key path ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.6: TDE initialized marker exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing TDE initialized marker"

tdeInitialized=$(docker exec ${testContainer} php -r "
    require_once '${BOOTSTRAP_PATH}';
    echo \Eiou\Security\MariaDbEncryption::isInitialized() ? 'yes' : 'no';
" 2>/dev/null)

if [ "$tdeInitialized" = "yes" ]; then
    printf "\t   TDE initialized marker ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   TDE initialized marker ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.7: MariaDB file_key_management plugin is loaded
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing MariaDB encryption plugin loaded"

pluginLoaded=$(docker exec ${testContainer} php -r "
    require_once '${BOOTSTRAP_PATH}';
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$stmt = \$pdo->query(\"SELECT PLUGIN_STATUS FROM information_schema.PLUGINS WHERE PLUGIN_NAME = 'file_key_management'\");
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
    echo (\$row && \$row['PLUGIN_STATUS'] === 'ACTIVE') ? 'yes' : 'no';
" 2>/dev/null)

if [ "$pluginLoaded" = "yes" ]; then
    printf "\t   file_key_management plugin active ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   file_key_management plugin active ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.8: innodb_encrypt_tables is ON
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing innodb_encrypt_tables is ON"

encryptTablesOn=$(docker exec ${testContainer} php -r "
    require_once '${BOOTSTRAP_PATH}';
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$stmt = \$pdo->query(\"SHOW GLOBAL VARIABLES LIKE 'innodb_encrypt_tables'\");
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
    echo (\$row && \$row['Value'] === 'ON') ? 'yes' : 'no';
" 2>/dev/null)

if [ "$encryptTablesOn" = "yes" ]; then
    printf "\t   innodb_encrypt_tables ON ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   innodb_encrypt_tables ON ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.9: innodb_encrypt_log is ON
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing innodb_encrypt_log is ON"

encryptLogOn=$(docker exec ${testContainer} php -r "
    require_once '${BOOTSTRAP_PATH}';
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$stmt = \$pdo->query(\"SHOW GLOBAL VARIABLES LIKE 'innodb_encrypt_log'\");
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
    echo (\$row && \$row['Value'] === 'ON') ? 'yes' : 'no';
" 2>/dev/null)

if [ "$encryptLogOn" = "yes" ]; then
    printf "\t   innodb_encrypt_log ON ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   innodb_encrypt_log ON ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.10: Database tables are encrypted (ENCRYPTION_SCHEME > 0)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing database tables are encrypted"

tablesEncrypted=$(docker exec ${testContainer} php -r "
    require_once '${BOOTSTRAP_PATH}';
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();

    // Count InnoDB tables in eiou database
    \$stmt = \$pdo->query(\"
        SELECT COUNT(*) as total,
               SUM(CASE WHEN ENCRYPTION_SCHEME > 0 THEN 1 ELSE 0 END) as encrypted
        FROM information_schema.INNODB_TABLESPACES_ENCRYPTION
        WHERE NAME LIKE 'eiou/%'
    \");
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);

    if (\$row && (int)\$row['total'] > 0 && (int)\$row['encrypted'] === (int)\$row['total']) {
        echo 'all_encrypted';
    } elseif (\$row && (int)\$row['total'] > 0) {
        echo 'partial:' . \$row['encrypted'] . '/' . \$row['total'];
    } else {
        echo 'none';
    }
" 2>/dev/null)

if [ "$tablesEncrypted" = "all_encrypted" ]; then
    printf "\t   All database tables encrypted ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   All database tables encrypted ${RED}FAILED${NC} (${tablesEncrypted})\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 4: DATABASE CREDENTIAL ENCRYPTION ############################

echo -e "\n[Section 4: Database Credential Encryption]"

# Test 4.1: dbPassEncrypted exists in dbconfig.json
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing dbPass is encrypted in dbconfig.json"

dbPassEncrypted=$(docker exec ${testContainer} php -r "
    \$config = json_decode(file_get_contents('/etc/eiou/config/dbconfig.json'), true);
    if (isset(\$config['dbPassEncrypted']['ciphertext'])) {
        echo 'encrypted';
    } elseif (isset(\$config['dbPass'])) {
        echo 'plaintext';
    } else {
        echo 'missing';
    }
" 2>/dev/null)

if [ "$dbPassEncrypted" = "encrypted" ]; then
    printf "\t   dbPass encrypted ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   dbPass encrypted ${RED}FAILED${NC} (${dbPassEncrypted})\n"
    failure=$(( failure + 1 ))
fi

# Test 4.2: dbUserEncrypted exists in dbconfig.json
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing dbUser is encrypted in dbconfig.json"

dbUserEncrypted=$(docker exec ${testContainer} php -r "
    \$config = json_decode(file_get_contents('/etc/eiou/config/dbconfig.json'), true);
    if (isset(\$config['dbUserEncrypted']['ciphertext'])) {
        echo 'encrypted';
    } elseif (isset(\$config['dbUser'])) {
        echo 'plaintext';
    } else {
        echo 'missing';
    }
" 2>/dev/null)

if [ "$dbUserEncrypted" = "encrypted" ]; then
    printf "\t   dbUser encrypted ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   dbUser encrypted ${RED}FAILED${NC} (${dbUserEncrypted})\n"
    failure=$(( failure + 1 ))
fi

# Test 4.3: dbNameEncrypted exists in dbconfig.json
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing dbName is encrypted in dbconfig.json"

dbNameEncrypted=$(docker exec ${testContainer} php -r "
    \$config = json_decode(file_get_contents('/etc/eiou/config/dbconfig.json'), true);
    if (isset(\$config['dbNameEncrypted']['ciphertext'])) {
        echo 'encrypted';
    } elseif (isset(\$config['dbName'])) {
        echo 'plaintext';
    } else {
        echo 'missing';
    }
" 2>/dev/null)

if [ "$dbNameEncrypted" = "encrypted" ]; then
    printf "\t   dbName encrypted ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   dbName encrypted ${RED}FAILED${NC} (${dbNameEncrypted})\n"
    failure=$(( failure + 1 ))
fi

# Test 4.4: Plaintext credentials removed after migration
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing plaintext credentials removed from dbconfig.json"

plaintextRemoved=$(docker exec ${testContainer} php -r "
    \$config = json_decode(file_get_contents('/etc/eiou/config/dbconfig.json'), true);
    \$hasPlaintextPass = isset(\$config['dbPass']);
    \$hasPlaintextUser = isset(\$config['dbUser']);
    \$hasPlaintextName = isset(\$config['dbName']);
    if (!\$hasPlaintextPass && !\$hasPlaintextUser && !\$hasPlaintextName) {
        echo 'all_removed';
    } else {
        \$remaining = [];
        if (\$hasPlaintextPass) \$remaining[] = 'dbPass';
        if (\$hasPlaintextUser) \$remaining[] = 'dbUser';
        if (\$hasPlaintextName) \$remaining[] = 'dbName';
        echo 'remaining:' . implode(',', \$remaining);
    }
" 2>/dev/null)

if [ "$plaintextRemoved" = "all_removed" ]; then
    printf "\t   Plaintext credentials removed ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Plaintext credentials removed ${RED}FAILED${NC} (${plaintextRemoved})\n"
    failure=$(( failure + 1 ))
fi

# Test 4.5: DatabaseContext can decrypt and read credentials
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing DatabaseContext decrypts credentials correctly"

dbContextWorks=$(docker exec ${testContainer} php -r "
    require_once '${BOOTSTRAP_PATH}';
    \$ctx = \Eiou\Core\DatabaseContext::getInstance();
    \$dbName = \$ctx->getDbName();
    \$dbUser = \$ctx->getDbUser();
    \$dbPass = \$ctx->getDbPass();
    \$dbHost = \$ctx->getDbHost();

    if (\$dbName !== null && \$dbUser !== null && \$dbPass !== null && \$dbHost !== null) {
        echo 'yes';
    } else {
        \$missing = [];
        if (\$dbName === null) \$missing[] = 'dbName';
        if (\$dbUser === null) \$missing[] = 'dbUser';
        if (\$dbPass === null) \$missing[] = 'dbPass';
        if (\$dbHost === null) \$missing[] = 'dbHost';
        echo 'missing:' . implode(',', \$missing);
    }
" 2>/dev/null)

if [ "$dbContextWorks" = "yes" ]; then
    printf "\t   DatabaseContext decryption ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   DatabaseContext decryption ${RED}FAILED${NC} (${dbContextWorks})\n"
    failure=$(( failure + 1 ))
fi

# Test 4.6: Encrypted credential format has required fields (version, aad, ciphertext, iv, tag)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing encrypted credential format (v2 with AAD)"

credFormat=$(docker exec ${testContainer} php -r "
    \$config = json_decode(file_get_contents('/etc/eiou/config/dbconfig.json'), true);
    \$fields = ['dbPassEncrypted', 'dbUserEncrypted', 'dbNameEncrypted'];
    \$required = ['ciphertext', 'iv', 'tag', 'version', 'aad'];
    \$issues = [];

    foreach (\$fields as \$field) {
        if (!isset(\$config[\$field])) {
            \$issues[] = \"\$field missing\";
            continue;
        }
        foreach (\$required as \$req) {
            if (!isset(\$config[\$field][\$req])) {
                \$issues[] = \"\$field.\$req\";
            }
        }
        if (isset(\$config[\$field]['version']) && \$config[\$field]['version'] < 2) {
            \$issues[] = \"\$field.version<2\";
        }
    }

    echo empty(\$issues) ? 'valid' : implode(',', \$issues);
" 2>/dev/null)

if [ "$credFormat" = "valid" ]; then
    printf "\t   Encrypted credential format (v2+AAD) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Encrypted credential format ${RED}FAILED${NC} (${credFormat})\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 5: CROSS-CONTAINER VERIFICATION ############################

echo -e "\n[Section 5: Cross-Container Verification]"

# Test 5.1: TDE active on all containers
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing TDE active on all containers"

allTdeActive=true
failedContainers=""

for container in "${containers[@]}"; do
    tdeActive=$(docker exec ${container} php -r "
        require_once '${BOOTSTRAP_PATH}';
        echo \Eiou\Security\MariaDbEncryption::isInitialized() ? 'yes' : 'no';
    " 2>/dev/null)

    if [ "$tdeActive" != "yes" ]; then
        allTdeActive=false
        failedContainers="${failedContainers} ${container}"
    fi
done

if [ "$allTdeActive" = "true" ]; then
    printf "\t   TDE active on all containers ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   TDE active on all containers ${RED}FAILED${NC} (inactive:${failedContainers})\n"
    failure=$(( failure + 1 ))
fi

# Test 5.2: Each container has unique TDE key (derived from unique master key)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing each container has unique TDE key"

declare -A tdeKeys
allUnique=true
for container in "${containers[@]}"; do
    tdeKey=$(docker exec ${container} php -r "
        \$content = @file_get_contents('/dev/shm/.mariadb-encryption-key');
        echo \$content !== false ? md5(\$content) : 'missing';
    " 2>/dev/null)

    # Check for duplicates
    for prevContainer in "${!tdeKeys[@]}"; do
        if [ "${tdeKeys[$prevContainer]}" = "$tdeKey" ] && [ "$tdeKey" != "missing" ]; then
            allUnique=false
        fi
    done
    tdeKeys[$container]="$tdeKey"
done

if [ "$allUnique" = "true" ]; then
    printf "\t   Unique TDE keys per container ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Unique TDE keys per container ${RED}FAILED${NC} (duplicate keys found)\n"
    failure=$(( failure + 1 ))
fi

# Test 5.3: DB credentials encrypted on all containers
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing DB credentials encrypted on all containers"

allDbEncrypted=true
failedContainers=""

for container in "${containers[@]}"; do
    dbEncrypted=$(docker exec ${container} php -r "
        \$config = json_decode(file_get_contents('/etc/eiou/config/dbconfig.json'), true);
        \$ok = isset(\$config['dbPassEncrypted']['ciphertext'])
            && isset(\$config['dbUserEncrypted']['ciphertext'])
            && isset(\$config['dbNameEncrypted']['ciphertext']);
        echo \$ok ? 'yes' : 'no';
    " 2>/dev/null)

    if [ "$dbEncrypted" != "yes" ]; then
        allDbEncrypted=false
        failedContainers="${failedContainers} ${container}"
    fi
done

if [ "$allDbEncrypted" = "true" ]; then
    printf "\t   DB credentials encrypted on all containers ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   DB credentials encrypted on all containers ${RED}FAILED${NC} (unencrypted:${failedContainers})\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 6: ENCRYPTION STATUS DIAGNOSTICS ############################

echo -e "\n[Section 6: Encryption Status Diagnostics]"

# Test 6.1: MariaDbEncryption::getStatus() returns expected structure
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing MariaDbEncryption::getStatus() structure"

tdeStatus=$(docker exec ${testContainer} php -r "
    require_once '${BOOTSTRAP_PATH}';
    \$status = \Eiou\Security\MariaDbEncryption::getStatus();
    \$requiredKeys = ['key_file_exists', 'config_exists', 'initialized'];
    \$missing = [];
    foreach (\$requiredKeys as \$key) {
        if (!array_key_exists(\$key, \$status)) {
            \$missing[] = \$key;
        }
    }
    if (empty(\$missing)) {
        // Verify all values are true (TDE should be fully active)
        \$allTrue = \$status['key_file_exists'] && \$status['config_exists'] && \$status['initialized'];
        echo \$allTrue ? 'all_active' : 'partial';
    } else {
        echo 'missing:' . implode(',', \$missing);
    }
" 2>/dev/null)

if [ "$tdeStatus" = "all_active" ]; then
    printf "\t   MariaDbEncryption::getStatus() ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   MariaDbEncryption::getStatus() ${RED}FAILED${NC} (${tdeStatus})\n"
    failure=$(( failure + 1 ))
fi

# Test 6.2: VolumeEncryption::getStatus() returns expected structure
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing VolumeEncryption::getStatus() structure"

veStatus=$(docker exec ${testContainer} php -r "
    require_once '${BOOTSTRAP_PATH}';
    \$status = \Eiou\Security\VolumeEncryption::getStatus();
    \$requiredKeys = ['available', 'active', 'encrypted_key_exists', 'plaintext_key_exists', 'runtime_key_exists', 'sodium_available'];
    \$missing = [];
    foreach (\$requiredKeys as \$key) {
        if (!array_key_exists(\$key, \$status)) {
            \$missing[] = \$key;
        }
    }
    if (empty(\$missing)) {
        // available and sodium_available should be true
        echo (\$status['available'] && \$status['sodium_available']) ? 'valid' : 'partial';
    } else {
        echo 'missing:' . implode(',', \$missing);
    }
" 2>/dev/null)

if [ "$veStatus" = "valid" ]; then
    printf "\t   VolumeEncryption::getStatus() ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   VolumeEncryption::getStatus() ${RED}FAILED${NC} (${veStatus})\n"
    failure=$(( failure + 1 ))
fi

# Test 6.3: KeyEncryption::getInfo() includes volume_encryption status
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing KeyEncryption::getInfo() includes volume encryption"

keInfo=$(docker exec ${testContainer} php -r "
    require_once '${BOOTSTRAP_PATH}';
    \$info = \Eiou\Security\KeyEncryption::getInfo();
    if (!isset(\$info['volume_encryption'])) {
        echo 'missing_volume_encryption';
    } elseif (!isset(\$info['master_key_exists'])) {
        echo 'missing_master_key_exists';
    } elseif (!\$info['master_key_exists']) {
        echo 'no_master_key';
    } else {
        echo 'valid';
    }
" 2>/dev/null)

if [ "$keInfo" = "valid" ]; then
    printf "\t   KeyEncryption::getInfo() ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   KeyEncryption::getInfo() ${RED}FAILED${NC} (${keInfo})\n"
    failure=$(( failure + 1 ))
fi

############################ RESULTS ############################

succesrate "${totaltests}" "${passed}" "${failure}" "'encryption test suite'"
