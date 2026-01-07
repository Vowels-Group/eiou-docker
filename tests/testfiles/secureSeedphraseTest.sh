#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

# Test secure seedphrase display functionality
# Verifies that seedphrases are NOT exposed in Docker logs
# and that the secure file-based display mechanism works correctly

echo -e "\nTesting secure seedphrase display functionality..."

testname="secureSeedphraseTest"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

echo -e "\n[Secure Seedphrase Display Test on ${testContainer}]"

############################ TEST 1: VERIFY SEEDPHRASE NOT IN DOCKER LOGS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1: Checking that seedphrase is NOT in docker logs"

# Get the encrypted mnemonic and decrypt it
actualSeedPhrase=$(docker exec ${testContainer} php -r '
    require_once "'"${EIOU_DIR}"'/src/security/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["mnemonic_encrypted"])) {
        echo KeyEncryption::decrypt($json["mnemonic_encrypted"]);
    }
' 2>&1)

# Get docker logs for the container
dockerLogs=$(docker logs ${testContainer} 2>&1)

# Check if any word from the seedphrase appears in the logs
# We check for at least 3 consecutive words to avoid false positives
seedWords=($actualSeedPhrase)
seedPhraseInLogs=false

# Check for 3-word sequences from the seedphrase in logs
for i in $(seq 0 $((${#seedWords[@]} - 3))); do
    threeWordSequence="${seedWords[$i]} ${seedWords[$((i+1))]} ${seedWords[$((i+2))]}"
    if echo "$dockerLogs" | grep -q "$threeWordSequence"; then
        seedPhraseInLogs=true
        break
    fi
done

if [[ "$seedPhraseInLogs" == "false" ]]; then
    printf "\t   Seedphrase NOT found in docker logs ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Seedphrase found in docker logs ${RED}FAILED${NC}\n"
    printf "\t   SECURITY VULNERABILITY: Seedphrase is exposed in logs!\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 2: VERIFY MNEMONIC STORED CORRECTLY ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 2: Verifying mnemonic is properly stored in userconfig.json"

mnemonicCheck=$(docker exec ${testContainer} php -r '
    require_once "'"${EIOU_DIR}"'/src/security/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (!isset($json["mnemonic_encrypted"])) {
        echo "NO_MNEMONIC";
        exit;
    }
    $mnemonic = KeyEncryption::decrypt($json["mnemonic_encrypted"]);
    $wordCount = str_word_count($mnemonic);
    if ($wordCount === 24) {
        echo "VALID_24_WORDS";
    } else {
        echo "INVALID_WORD_COUNT:" . $wordCount;
    }
' 2>&1)

if [[ "$mnemonicCheck" == "VALID_24_WORDS" ]]; then
    printf "\t   Mnemonic stored correctly (24 words) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [[ "$mnemonicCheck" == "NO_MNEMONIC" ]]; then
    printf "\t   Mnemonic storage ${RED}FAILED${NC} - no mnemonic_encrypted field\n"
    failure=$(( failure + 1 ))
else
    printf "\t   Mnemonic storage ${RED}FAILED${NC} - ${mnemonicCheck}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 3: VERIFY /dev/shm EXISTS AND IS WRITABLE ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3: Verifying /dev/shm (tmpfs) is available for secure storage"

shmCheck=$(docker exec ${testContainer} sh -c '
    if [ -d /dev/shm ] && [ -w /dev/shm ]; then
        echo "AVAILABLE"
    else
        echo "NOT_AVAILABLE"
    fi
' 2>&1)

if [[ "$shmCheck" == "AVAILABLE" ]]; then
    printf "\t   /dev/shm available and writable ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   /dev/shm availability ${YELLOW}WARNING${NC} - fallback to /tmp\n"
    printf "\t   Status: ${shmCheck}\n"
    passed=$(( passed + 1 ))
fi

############################ TEST 4: TEST SECURE DISPLAY CLASS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 4: Testing SecureSeedphraseDisplay class availability"

displayClassCheck=$(docker exec ${testContainer} php -r '
    require_once "'"${EIOU_DIR}"'/src/utils/SecureSeedphraseDisplay.php";
    $availability = SecureSeedphraseDisplay::checkAvailability();
    echo json_encode($availability);
' 2>&1)

if echo "$displayClassCheck" | grep -q "shm_available"; then
    printf "\t   SecureSeedphraseDisplay class working ${GREEN}PASSED${NC}\n"
    printf "\t   Availability: ${displayClassCheck}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SecureSeedphraseDisplay class ${RED}FAILED${NC}\n"
    printf "\t   Output: ${displayClassCheck}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 5: TEST SECURE FILE DISPLAY METHOD ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 5: Testing secure file display method"

# Test the file-based display (simulating non-TTY environment)
fileDisplayTest=$(docker exec ${testContainer} php -r '
    require_once "'"${EIOU_DIR}"'/src/utils/SecureSeedphraseDisplay.php";

    // Force non-TTY mode by testing the file method directly
    $testPhrase = "test word one two three four five six seven eight nine ten eleven twelve";

    // Use reflection to access the private method
    $class = new ReflectionClass("SecureSeedphraseDisplay");
    $method = $class->getMethod("displayViaSecureFile");
    $method->setAccessible(true);

    $result = $method->invoke(null, $testPhrase);

    if ($result["success"] && isset($result["filepath"])) {
        // Verify file was created
        if (file_exists($result["filepath"])) {
            // Read file content
            $content = file_get_contents($result["filepath"]);
            if (strpos($content, "test word") !== false) {
                // Clean up test file
                unlink($result["filepath"]);
                echo "FILE_CREATED_AND_VALID";
            } else {
                echo "FILE_CONTENT_INVALID";
            }
        } else {
            echo "FILE_NOT_CREATED";
        }
    } else {
        echo "METHOD_FAILED:" . json_encode($result);
    }
' 2>&1)

if [[ "$fileDisplayTest" == "FILE_CREATED_AND_VALID" ]]; then
    printf "\t   Secure file display method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Secure file display method ${RED}FAILED${NC}\n"
    printf "\t   Result: ${fileDisplayTest}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 6: TEST RESTORE-FILE COMMAND ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 6: Testing restore-file command"

# First, get the current seedphrase
currentSeedPhrase=$(docker exec ${testContainer} php -r '
    require_once "'"${EIOU_DIR}"'/src/security/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    echo KeyEncryption::decrypt($json["mnemonic_encrypted"]);
' 2>&1)

# Store current public key
originalPubKey=$(docker exec ${testContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    echo $json["public"] ?? "ERROR";
' 2>&1)

# Create a temp file with the seedphrase
docker exec ${testContainer} sh -c "echo '${currentSeedPhrase}' > /dev/shm/test_restore_seedphrase"
docker exec ${testContainer} chmod 600 /dev/shm/test_restore_seedphrase

# Delete the userconfig to test restoration
docker exec ${testContainer} rm -f ${USERCONFIG}

# Test restore-file command
restoreOutput=$(docker exec ${testContainer} eiou generate restore-file /dev/shm/test_restore_seedphrase 2>&1)

# Clean up test file
docker exec ${testContainer} rm -f /dev/shm/test_restore_seedphrase

# Verify restoration
restoredPubKey=$(docker exec ${testContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    echo $json["public"] ?? "ERROR";
' 2>&1)

if [[ "$originalPubKey" == "$restoredPubKey" ]] && [[ "$restoredPubKey" != "ERROR" ]]; then
    printf "\t   restore-file command ${GREEN}PASSED${NC}\n"
    printf "\t   Public keys match after restore-file\n"
    passed=$(( passed + 1 ))
else
    printf "\t   restore-file command ${RED}FAILED${NC}\n"
    printf "\t   Original: ${originalPubKey}\n"
    printf "\t   Restored: ${restoredPubKey}\n"
    printf "\t   Output: ${restoreOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 7: VERIFY SEEDPHRASE NOT IN PROCESS LIST ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 7: Testing that restore-file doesn't expose seedphrase in process list"

# Create a temp file with a test seedphrase
docker exec ${testContainer} sh -c "echo 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about' > /dev/shm/test_ps_seedphrase"
docker exec ${testContainer} chmod 600 /dev/shm/test_ps_seedphrase

# Run restore-file in background and capture process list
docker exec ${testContainer} sh -c '
    # Start the restore-file command in background
    (eiou generate restore-file /dev/shm/test_ps_seedphrase > /dev/null 2>&1 &)
    sleep 0.5
    # Check if "abandon" appears in process arguments
    ps aux | grep -v grep | grep "abandon abandon abandon"
' > /tmp/ps_check_output 2>&1

psCheckResult=$(cat /tmp/ps_check_output)

# Clean up
docker exec ${testContainer} rm -f /dev/shm/test_ps_seedphrase
rm -f /tmp/ps_check_output

if [[ -z "$psCheckResult" ]]; then
    printf "\t   Seedphrase not exposed in process list ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Seedphrase exposed in process list ${RED}FAILED${NC}\n"
    printf "\t   SECURITY VULNERABILITY: Seedphrase visible in ps aux!\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 8: VERIFY SECURE LOGGER MASKS SEEDPHRASES ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 8: Testing SecureLogger masks seedphrases"

loggerMaskTest=$(docker exec ${testContainer} php -r '
    require_once "'"${EIOU_DIR}"'/src/utils/SecureLogger.php";

    // Test that SecureLogger masks a seedphrase pattern
    $testMessage = "mnemonic=abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about";

    // Use reflection to test the private maskSensitive method
    $class = new ReflectionClass("SecureLogger");
    $method = $class->getMethod("maskSensitive");
    $method->setAccessible(true);

    $masked = $method->invoke(null, $testMessage);

    if (strpos($masked, "abandon") === false && strpos($masked, "MASKED") !== false) {
        echo "PROPERLY_MASKED";
    } else {
        echo "NOT_MASKED:" . $masked;
    }
' 2>&1)

if [[ "$loggerMaskTest" == "PROPERLY_MASKED" ]]; then
    printf "\t   SecureLogger masks seedphrases ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SecureLogger masking ${RED}FAILED${NC}\n"
    printf "\t   Result: ${loggerMaskTest}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 9: VERIFY RESTORE_FILE APPROACH ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 9: Testing RESTORE_FILE approach (file-based restore)"

# Create a new container with RESTORE_FILE to test the file-based restore
# First, get the seedphrase and create a temp file on the host
hostSeedFile="/tmp/eiou_test_restore_seed_$$"

# Get the current seedphrase
docker exec ${testContainer} php -r '
    require_once "'"${EIOU_DIR}"'/src/security/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    echo KeyEncryption::decrypt($json["mnemonic_encrypted"]);
' > "${hostSeedFile}" 2>&1
chmod 600 "${hostSeedFile}"

# Get original public key for comparison
originalPubKeyRestoreFile=$(docker exec ${testContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    echo $json["public"] ?? "ERROR";
' 2>&1)

# Create a new container with RESTORE_FILE (file-based restore)
restoreFileContainer="httpRestoreFileTest"
docker run -d --network="${network}" --name "${restoreFileContainer}" \
    -v "${hostSeedFile}:/restore/seed:ro" \
    -e RESTORE_FILE=/restore/seed \
    -v "${restoreFileContainer}-mysql-data:/var/lib/mysql" \
    -v "${restoreFileContainer}-files:/etc/eiou/" \
    -v "${restoreFileContainer}-index:/var/www/html" \
    -v "${restoreFileContainer}-eiou:/usr/local/bin/" \
    eioud > /dev/null 2>&1

sleep 10

# Check if seedphrase is in environment (should NOT be with RESTORE_FILE)
seedInEnv=$(docker exec ${restoreFileContainer} printenv 2>&1 | grep -c "abandon\|tribe\|gloom\|legal" || echo "0")

# Get restored public key
restoredPubKeyRestoreFile=$(docker exec ${restoreFileContainer} php -r '
    $json = json_decode(file_get_contents("/etc/eiou/userconfig.json"), true);
    echo $json["public"] ?? "ERROR";
' 2>&1)

# Check if seedphrase is in docker logs
seedInLogs=$(docker logs ${restoreFileContainer} 2>&1 | grep -c "abandon\|tribe\|gloom\|legal" || echo "0")

# Clean up the new container
docker rm -f ${restoreFileContainer} > /dev/null 2>&1
docker volume rm ${restoreFileContainer}-mysql-data ${restoreFileContainer}-files ${restoreFileContainer}-index ${restoreFileContainer}-eiou > /dev/null 2>&1
rm -f "${hostSeedFile}"

if [[ "$seedInEnv" == "0" ]] && [[ "$seedInLogs" == "0" ]] && [[ "$originalPubKeyRestoreFile" == "$restoredPubKeyRestoreFile" ]] && [[ "$restoredPubKeyRestoreFile" != "ERROR" ]]; then
    printf "\t   RESTORE_FILE approach ${GREEN}PASSED${NC}\n"
    printf "\t   - Seedphrase NOT in environment\n"
    printf "\t   - Seedphrase NOT in logs\n"
    printf "\t   - Public keys match after restore\n"
    passed=$(( passed + 1 ))
else
    printf "\t   RESTORE_FILE approach ${RED}FAILED${NC}\n"
    if [[ "$seedInEnv" != "0" ]]; then
        printf "\t   - SECURITY: Seedphrase found in environment!\n"
    fi
    if [[ "$seedInLogs" != "0" ]]; then
        printf "\t   - SECURITY: Seedphrase found in logs!\n"
    fi
    if [[ "$originalPubKeyRestoreFile" != "$restoredPubKeyRestoreFile" ]]; then
        printf "\t   - Public key mismatch after restore\n"
    fi
    failure=$(( failure + 1 ))
fi

############################ TEST 10: VERIFY RESTORE ENV VAR APPROACH ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 10: Testing RESTORE env var approach"

# Get the current seedphrase from existing container
restoreEnvSeedPhrase=$(docker exec ${testContainer} php -r '
    require_once "'"${EIOU_DIR}"'/src/security/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    echo KeyEncryption::decrypt($json["mnemonic_encrypted"]);
' 2>&1)

# Get original public key for comparison
originalPubKeyRestoreEnv=$(docker exec ${testContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    echo $json["public"] ?? "ERROR";
' 2>&1)

# Create a new container with RESTORE env var
restoreEnvContainer="httpRestoreEnvTest"
docker run -d --network="${network}" --name "${restoreEnvContainer}" \
    -e RESTORE="${restoreEnvSeedPhrase}" \
    -v "${restoreEnvContainer}-mysql-data:/var/lib/mysql" \
    -v "${restoreEnvContainer}-files:/etc/eiou/" \
    -v "${restoreEnvContainer}-index:/var/www/html" \
    -v "${restoreEnvContainer}-eiou:/usr/local/bin/" \
    eioud > /dev/null 2>&1

sleep 10

# Get restored public key
restoredPubKeyRestoreEnv=$(docker exec ${restoreEnvContainer} php -r '
    $json = json_decode(file_get_contents("/etc/eiou/userconfig.json"), true);
    echo $json["public"] ?? "ERROR";
' 2>&1)

# Check if seedphrase is in docker logs (should NOT be)
seedInLogsEnv=$(docker logs ${restoreEnvContainer} 2>&1 | grep -c "${restoreEnvSeedPhrase:0:30}" || echo "0")

# Check for 3-word sequence in logs
firstThreeWords=$(echo "$restoreEnvSeedPhrase" | awk '{print $1" "$2" "$3}')
threeWordInLogs=$(docker logs ${restoreEnvContainer} 2>&1 | grep -c "$firstThreeWords" || echo "0")

# Clean up the new container
docker rm -f ${restoreEnvContainer} > /dev/null 2>&1
docker volume rm ${restoreEnvContainer}-mysql-data ${restoreEnvContainer}-files ${restoreEnvContainer}-index ${restoreEnvContainer}-eiou > /dev/null 2>&1

if [[ "$threeWordInLogs" == "0" ]] && [[ "$originalPubKeyRestoreEnv" == "$restoredPubKeyRestoreEnv" ]] && [[ "$restoredPubKeyRestoreEnv" != "ERROR" ]]; then
    printf "\t   RESTORE env var approach ${GREEN}PASSED${NC}\n"
    printf "\t   - Seedphrase NOT in docker logs\n"
    printf "\t   - Public keys match after restore\n"
    printf "\t   - Note: Seedphrase visible in container env (Docker limitation)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   RESTORE env var approach ${RED}FAILED${NC}\n"
    if [[ "$threeWordInLogs" != "0" ]]; then
        printf "\t   - SECURITY: Seedphrase found in logs!\n"
    fi
    if [[ "$originalPubKeyRestoreEnv" != "$restoredPubKeyRestoreEnv" ]]; then
        printf "\t   - Public key mismatch after restore\n"
        printf "\t   - Original: ${originalPubKeyRestoreEnv:0:50}...\n"
        printf "\t   - Restored: ${restoredPubKeyRestoreEnv:0:50}...\n"
    fi
    failure=$(( failure + 1 ))
fi

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'secure seedphrase display'"
