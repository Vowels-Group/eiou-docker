#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

# Consolidated Seedphrase Test Suite
# Combines:
# - Seed phrase generate and restore functionality
# - Secure seedphrase display (security validation)
# - Authcode restoration from seedphrase
#
# NOTE: All paths use double slashes (//etc/eiou/) to prevent Git Bash on Windows
# from converting /etc/ to C:/Program Files/Git/etc/. This is safe on Linux too.

echo -e "\n"
echo -e "================================================================"
echo -e "         SEEDPHRASE TEST SUITE"
echo -e "================================================================"
echo -e "Testing:"
echo -e "  - Seed phrase generate/restore"
echo -e "  - Secure seedphrase display (security)"
echo -e "  - Authcode restoration from seedphrase"
echo -e "================================================================\n"

testname="seedphraseTestSuite"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

################################################################################
#                    PREREQUISITE VALIDATION
################################################################################

# Use shared validation function from testHelpers.sh
if ! validate_test_prerequisites "seedphraseTestSuite"; then
    succesrate "0" "0" "0" "'seedphrase test suite'"
    return 1
fi

################################################################################
#                    PART 1: SEED PHRASE RESTORE TEST
################################################################################

echo -e "\n[PART 1: Seed Phrase Restore Test on ${testContainer}]"
echo -e "================================================================"

############################ STORE ORIGINAL PUBLIC KEY ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.1: Storing original public key from userconfig.json"

originalPublicKey=$(docker exec ${testContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["public"])) {
        echo $json["public"];
    } else {
        echo "ERROR_NO_PUBLIC_KEY";
    }
' 2>&1)

if [[ "$originalPublicKey" != "ERROR_NO_PUBLIC_KEY" ]] && [[ -n "$originalPublicKey" ]]; then
    printf "\t   Original public key retrieved ${GREEN}PASSED${NC}\n"
    printf "\t   BEFORE - Public Key: ${originalPublicKey}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Original public key retrieval ${RED}FAILED${NC}\n"
    printf "\t   Could not retrieve original public key\n"
    failure=$(( failure + 1 ))
    # Cannot continue without original key
    succesrate "${totaltests}" "${passed}" "${failure}" "'seedphrase test suite'"
    return 1
fi

############################ STORE ORIGINAL TOR ADDRESS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.2: Storing original Tor address from userconfig.json"

originalTorAddress=$(docker exec ${testContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["torAddress"])) {
        echo $json["torAddress"];
    } else {
        echo "ERROR_NO_TOR_ADDRESS";
    }
' 2>&1)

if [[ "$originalTorAddress" != "ERROR_NO_TOR_ADDRESS" ]] && [[ -n "$originalTorAddress" ]]; then
    # Verify it's a valid .onion address (56 chars + .onion = 62 total)
    if [[ "$originalTorAddress" == *".onion" ]] && [[ ${#originalTorAddress} -eq 62 ]]; then
        printf "\t   Original Tor address retrieved ${GREEN}PASSED${NC}\n"
        printf "\t   BEFORE - Tor Address: ${originalTorAddress}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Original Tor address retrieved but invalid format ${YELLOW}WARNING${NC}\n"
        printf "\t   Address: ${originalTorAddress} (length: ${#originalTorAddress})\n"
        passed=$(( passed + 1 ))
    fi
else
    # In HTTP mode, Tor address may not be set - this is expected behavior
    if [[ "$TOR_AVAILABLE" == "false" ]]; then
        printf "\t   Original Tor address not available (HTTP mode) ${YELLOW}SKIPPED${NC}\n"
        passed=$(( passed + 1 ))
        originalTorAddress=""
    else
        printf "\t   Original Tor address retrieval ${RED}FAILED${NC}\n"
        printf "\t   Could not retrieve original Tor address\n"
        failure=$(( failure + 1 ))
    fi
fi

############################ VERIFY ORIGINAL TOR KEY FILES ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.3: Verifying original Tor hidden service key files exist"

# Use shared function to verify Tor key files
torKeyResult=$(verify_tor_key_files "${testContainer}")
handle_tor_key_result "$torKeyResult" "Tor hidden service key files" "${testContainer}"

############################ DECRYPT MNEMONIC ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.4: Decrypting seed phrase from encrypted mnemonic"

seedPhrase=$(docker exec ${testContainer} php -r '
    require_once "'"${EIOU_DIR}"'/src/security/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["mnemonic_encrypted"])) {
        $mnemonic = KeyEncryption::decrypt($json["mnemonic_encrypted"]);
        echo $mnemonic;
    } else {
        echo "ERROR_NO_MNEMONIC";
    }
' 2>&1)

if [[ "$seedPhrase" != "ERROR_NO_MNEMONIC" ]] && [[ -n "$seedPhrase" ]]; then
    # Count words in seed phrase (should be 24 for BIP39)
    wordCount=$(echo "$seedPhrase" | wc -w)
    if [[ "$wordCount" -eq 24 ]]; then
        printf "\t   Seed phrase decrypted (${wordCount} words) ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Seed phrase decrypted but has ${wordCount} words (expected 24) ${YELLOW}WARNING${NC}\n"
        passed=$(( passed + 1 ))
    fi
else
    printf "\t   Seed phrase decryption ${RED}FAILED${NC}\n"
    printf "\t   Could not decrypt mnemonic\n"
    failure=$(( failure + 1 ))
    # Cannot continue without seed phrase
    succesrate "${totaltests}" "${passed}" "${failure}" "'seedphrase test suite'"
    return 1
fi

############################ BACKUP MASTER KEY ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.5: Backing up master key before deletion"

# Show file permissions for debugging
masterKeyPerms=$(docker exec ${testContainer} ls -la ${MASTER_KEY} 2>&1)

# First check if master key file exists
masterKeyExists=$(docker exec ${testContainer} test -f ${MASTER_KEY} && echo "EXISTS" || echo "NOT_FOUND")

if [[ "$masterKeyExists" == "EXISTS" ]]; then
    # Backup the master key since it's needed for encryption operations
    masterKeyBackup=$(docker exec ${testContainer} cat ${MASTER_KEY} 2>&1 | base64)

    if [[ -n "$masterKeyBackup" ]]; then
        printf "\t   Master key backed up ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Master key backup ${RED}FAILED${NC} - file exists but could not read\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   Master key backup ${RED}FAILED${NC} - file does not exist (test -f returned NOT_FOUND)\n"
    failure=$(( failure + 1 ))
fi

############################ DELETE USERCONFIG AND TOR FILES ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.6: Deleting userconfig.json and Tor files to simulate fresh wallet"

# Show file permissions for debugging
userconfigPerms=$(docker exec ${testContainer} ls -la ${USERCONFIG} 2>&1)

# Check if userconfig.json exists before getting timestamp
userconfigExists=$(docker exec ${testContainer} test -f ${USERCONFIG} && echo "EXISTS" || echo "NOT_FOUND")

if [[ "$userconfigExists" == "EXISTS" ]]; then
    # Get timestamp of original file BEFORE deletion
    originalTimestamp=$(docker exec ${testContainer} stat -c '%Y %y' ${USERCONFIG} 2>&1)
    printf "\t   Original file timestamp: ${originalTimestamp}\n"
else
    printf "\t   ${RED}ERROR - userconfig.json does not exist before deletion (test -f returned NOT_FOUND)${NC}\n"
    originalTimestamp="FILE_NOT_FOUND"
fi

deleteResult=$(docker exec ${testContainer} rm -f ${USERCONFIG} 2>&1)
verifyDeleted=$(docker exec ${testContainer} test -f ${USERCONFIG} && echo "EXISTS" || echo "DELETED")

# Also delete Tor hidden service files to test deterministic regeneration
deleteTorResult=$(docker exec ${testContainer} rm -f ${TOR_SECRET_KEY} ${TOR_PUBLIC_KEY} ${TOR_HOSTNAME} 2>&1)
verifyTorDeleted=$(docker exec ${testContainer} test -f ${TOR_HOSTNAME} && echo "EXISTS" || echo "DELETED")

if [[ "$verifyDeleted" == "DELETED" ]] && [[ "$verifyTorDeleted" == "DELETED" ]]; then
    printf "\t   userconfig.json and Tor files deleted ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [[ "$verifyDeleted" == "DELETED" ]]; then
    printf "\t   userconfig.json deleted, Tor files deletion ${YELLOW}WARNING${NC}\n"
    printf "\t   Tor delete result: ${deleteTorResult}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   File deletion ${RED}FAILED${NC}\n"
    printf "\t   Delete result: ${deleteResult}\n"
    failure=$(( failure + 1 ))
fi

############################ RESTORE FROM SEED PHRASE ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.7: Restoring wallet with 'eiou generate restore <seed_phrase>'"

# Run the restore command with the seed phrase
restoreOutput=$(docker exec ${testContainer} eiou generate restore ${seedPhrase} 2>&1)

# Small delay to ensure file system sync
sleep 1

# Check if restore was successful by verifying userconfig.json was created
verifyRestored=$(docker exec ${testContainer} test -f ${USERCONFIG} && echo "CREATED" || echo "NOT_CREATED")

if [[ "$verifyRestored" == "CREATED" ]]; then
    # Get timestamp of restored file AFTER creation
    restoredTimestamp=$(docker exec ${testContainer} stat -c '%Y %y' ${USERCONFIG} 2>&1)
    printf "\t   Wallet restored from seed phrase ${GREEN}PASSED${NC}\n"
    printf "\t   Restored file timestamp: ${restoredTimestamp}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Wallet restoration ${RED}FAILED${NC}\n"
    printf "\t   Restore output: ${restoreOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ GET NEW PUBLIC KEY ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.8: Retrieving restored public key"

restoredPublicKey=$(docker exec ${testContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["public"])) {
        echo $json["public"];
    } else {
        echo "ERROR_NO_PUBLIC_KEY";
    }
' 2>&1)

if [[ "$restoredPublicKey" != "ERROR_NO_PUBLIC_KEY" ]] && [[ -n "$restoredPublicKey" ]]; then
    printf "\t   Restored public key retrieved ${GREEN}PASSED${NC}\n"
    printf "\t   AFTER  - Public Key: ${restoredPublicKey}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Restored public key retrieval ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ GET RESTORED TOR ADDRESS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.9: Retrieving restored Tor address"

restoredTorAddress=$(docker exec ${testContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["torAddress"])) {
        echo $json["torAddress"];
    } else {
        echo "ERROR_NO_TOR_ADDRESS";
    }
' 2>&1)

if [[ "$restoredTorAddress" != "ERROR_NO_TOR_ADDRESS" ]] && [[ -n "$restoredTorAddress" ]]; then
    printf "\t   Restored Tor address retrieved ${GREEN}PASSED${NC}\n"
    printf "\t   AFTER  - Tor Address: ${restoredTorAddress}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Restored Tor address retrieval ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ VERIFY RESTORED TOR KEY FILES ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.10: Verifying restored Tor hidden service key files"

# Use shared function to verify Tor key files
restoredTorKeyResult=$(verify_tor_key_files "${testContainer}")
handle_tor_key_result "$restoredTorKeyResult" "Restored Tor key files" "${testContainer}"

############################ COMPARE TOR ADDRESSES ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.11: Comparing original and restored Tor addresses"

# Skip Tor address comparison if Tor is not available
if [[ "$TOR_AVAILABLE" == "false" ]]; then
    printf "\t   Tor address comparison not applicable (HTTP mode) ${YELLOW}SKIPPED${NC}\n"
    passed=$(( passed + 1 ))
else
    echo -e "\n\t   ============================================"
    echo -e "\t   TOR ADDRESS COMPARISON RESULTS"
    echo -e "\t   ============================================"
    echo -e "\t   BEFORE: ${originalTorAddress}"
    echo -e "\t   AFTER:  ${restoredTorAddress}"
    echo -e "\t   ============================================\n"

    if [[ "$originalTorAddress" == "$restoredTorAddress" ]]; then
        printf "\t   ${GREEN}TOR ADDRESSES MATCH - Deterministic Tor derivation working correctly!${NC}\n"
        printf "\t   Tor address comparison ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   ${RED}TOR ADDRESSES DO NOT MATCH - Deterministic Tor derivation broken!${NC}\n"
        printf "\t   Tor address comparison ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
fi

############################ RESTORE FROM SEED PHRASE NEW CONTAINER ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.12: Restoring wallet with 'docker run -d ... -e RESTORE=<seed_phrase>'"

# Create a completely new Container
restoreContainer="httpRestoreSeedTest"
restoreContainerHash=$(docker run -d  --network="${network}" --name "${restoreContainer}" -v "${restoreContainer}-mysql-data:/var/lib/mysql" -v "${restoreContainer}-files:/etc/eiou/" -v "${restoreContainer}-index:/var/www/html" -v "${restoreContainer}-eiou:/usr/local/bin/" -e  RESTORE="${seedPhrase}" eioud 2>&1)

# Small delay to ensure file system sync (and bootup container)
sleep 5

# Check if restore was successful by verifying userconfig.json was created
verifyRestoredContainer=$(docker exec ${restoreContainer} test -f ${USERCONFIG} && echo "CREATED" || echo "NOT_CREATED")

if [[ "$verifyRestoredContainer" == "CREATED" ]]; then
    # Get timestamp of restored file AFTER creation
    restoredTimestampContainer=$(docker exec ${restoreContainer} stat -c '%Y %y' ${USERCONFIG} 2>&1)
    printf "\t   Wallet restored from seed phrase ${GREEN}PASSED${NC}\n"
    printf "\t   Restored file timestamp: ${restoredTimestampContainer}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Wallet restoration ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ GET NEW PUBLIC KEY NEW CONTAINER ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.13: Retrieving restored public key from new Container"

restoredPublicKeyContainer=$(docker exec ${restoreContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["public"])) {
        echo $json["public"];
    } else {
        echo "ERROR_NO_PUBLIC_KEY";
    }
' 2>&1)

if [[ "$restoredPublicKeyContainer" != "ERROR_NO_PUBLIC_KEY" ]] && [[ -n "$restoredPublicKeyContainer" ]]; then
    printf "\t   Restored public key retrieved ${GREEN}PASSED${NC}\n"
    printf "\t   AFTER  - Public Key: ${restoredPublicKeyContainer}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Restored public key retrieval ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ GET TOR ADDRESS NEW CONTAINER ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.14: Retrieving Tor address from new Container"

restoredTorAddressContainer=$(docker exec ${restoreContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["torAddress"])) {
        echo $json["torAddress"];
    } else {
        echo "ERROR_NO_TOR_ADDRESS";
    }
' 2>&1)

if [[ "$restoredTorAddressContainer" != "ERROR_NO_TOR_ADDRESS" ]] && [[ -n "$restoredTorAddressContainer" ]]; then
    printf "\t   Tor address from new container retrieved ${GREEN}PASSED${NC}\n"
    printf "\t   AFTER  - Tor Address: ${restoredTorAddressContainer}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Tor address retrieval from new container ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ COMPARE PUBLIC KEYS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.15: Comparing original and restored public keys"

echo -e "\n\t   ============================================"
echo -e "\t   KEY COMPARISON RESULTS"
echo -e "\t   ============================================"
echo -e "\t   BEFORE: ${originalPublicKey}"
echo -e "\t   AFTER:  ${restoredPublicKey}"
echo -e "\t   AFTER:  ${restoredPublicKeyContainer}"
echo -e "\t   ============================================\n"

if [[ "$originalPublicKey" == "$restoredPublicKey" && "$originalPublicKey" == "$restoredPublicKeyContainer" ]]; then
    printf "\t   ${GREEN}PUBLIC KEYS MATCH - Deterministic derivation working correctly!${NC}\n"
    printf "\t   Key comparison ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ${RED}PUBLIC KEYS DO NOT MATCH - Deterministic derivation may be broken!${NC}\n"
    printf "\t   Key comparison ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ COMPARE ALL TOR ADDRESSES ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.16: Comparing all Tor addresses (original, restored, new container)"

# Skip Tor address comparison if Tor is not available
if [[ "$TOR_AVAILABLE" == "false" ]]; then
    printf "\t   Full Tor address comparison not applicable (HTTP mode) ${YELLOW}SKIPPED${NC}\n"
    passed=$(( passed + 1 ))
else
    echo -e "\n\t   ============================================"
    echo -e "\t   FULL TOR ADDRESS COMPARISON"
    echo -e "\t   ============================================"
    echo -e "\t   ORIGINAL:      ${originalTorAddress}"
    echo -e "\t   RESTORED:      ${restoredTorAddress}"
    echo -e "\t   NEW CONTAINER: ${restoredTorAddressContainer}"
    echo -e "\t   ============================================\n"

    if [[ "$originalTorAddress" == "$restoredTorAddress" && "$originalTorAddress" == "$restoredTorAddressContainer" ]]; then
        printf "\t   ${GREEN}ALL TOR ADDRESSES MATCH - Deterministic Tor derivation working correctly!${NC}\n"
        printf "\t   Full Tor address comparison ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   ${RED}TOR ADDRESSES DO NOT MATCH - Deterministic Tor derivation broken!${NC}\n"
        if [[ "$originalTorAddress" != "$restoredTorAddress" ]]; then
            printf "\t   Original != Restored\n"
        fi
        if [[ "$originalTorAddress" != "$restoredTorAddressContainer" ]]; then
            printf "\t   Original != New Container\n"
        fi
        printf "\t   Full Tor address comparison ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
fi

############################ VERIFY WALLET CONFIG INTACT ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.17: Verifying wallet configuration is intact"

# Check that the restored wallet has required fields
configCheck=$(docker exec ${testContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    $hasPublicKey = isset($json["public"]);
    $hasPrivateKey = isset($json["private_encrypted"]);
    $hasMnemonic = isset($json["mnemonic_encrypted"]);

    if ($hasPublicKey && $hasPrivateKey && $hasMnemonic) {
        echo "CONFIG_VALID";
    } else {
        echo "CONFIG_INCOMPLETE";
    }
' 2>&1)

if [[ "$configCheck" == "CONFIG_VALID" ]]; then
    printf "\t   Wallet configuration verified ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Wallet configuration verification ${RED}FAILED${NC}\n"
    printf "\t   Config check result: ${configCheck}\n"
    failure=$(( failure + 1 ))
fi

################################################################################
#                    PART 2: SECURE SEEDPHRASE DISPLAY TEST
################################################################################

echo -e "\n\n[PART 2: Secure Seedphrase Display Test on ${testContainer}]"
echo -e "================================================================"

############################ TEST 2.1: VERIFY SEEDPHRASE NOT IN DOCKER LOGS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 2.1: Checking that seedphrase is NOT in docker logs"

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

############################ TEST 2.2: VERIFY MNEMONIC STORED CORRECTLY ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 2.2: Verifying mnemonic is properly stored in userconfig.json"

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

############################ TEST 2.3: VERIFY /dev/shm EXISTS AND IS WRITABLE ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 2.3: Verifying /dev/shm (tmpfs) is available for secure storage"

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

############################ TEST 2.4: TEST SECURE DISPLAY CLASS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 2.4: Testing SecureSeedphraseDisplay class availability"

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

############################ TEST 2.5: TEST SECURE FILE DISPLAY METHOD ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 2.5: Testing secure file display method"

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
            // Check for first word "test" - content is formatted with line numbers
            // so "test word" wont appear as consecutive string
            if (strpos($content, "test") !== false && strpos($content, "word") !== false) {
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

############################ TEST 2.6: TEST RESTORE-FILE COMMAND ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 2.6: Testing restore-file command"

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

# Create a temp file with the seedphrase (use double slashes to prevent Git Bash path conversion)
docker exec ${testContainer} sh -c "echo '${currentSeedPhrase}' > //dev//shm//test_restore_seedphrase"
docker exec ${testContainer} sh -c "chmod 600 //dev//shm//test_restore_seedphrase"

# Delete the userconfig to test restoration
docker exec ${testContainer} sh -c "rm -f //etc//eiou//userconfig.json"

# Test restore-file command
restoreOutput=$(docker exec ${testContainer} sh -c "eiou generate restore-file //dev//shm//test_restore_seedphrase" 2>&1)

# Clean up test file
docker exec ${testContainer} sh -c "rm -f //dev//shm//test_restore_seedphrase"

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

############################ TEST 2.7: VERIFY SEEDPHRASE NOT IN PROCESS LIST ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 2.7: Testing that restore-file doesn't expose seedphrase in process list"

# Create a temp file with a test seedphrase (use double slashes to prevent Git Bash path conversion)
docker exec ${testContainer} sh -c "echo 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about' > //dev//shm//test_ps_seedphrase"
docker exec ${testContainer} sh -c "chmod 600 //dev//shm//test_ps_seedphrase"

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
docker exec ${testContainer} sh -c "rm -f //dev//shm//test_ps_seedphrase"
rm -f /tmp/ps_check_output

if [[ -z "$psCheckResult" ]]; then
    printf "\t   Seedphrase not exposed in process list ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Seedphrase exposed in process list ${RED}FAILED${NC}\n"
    printf "\t   SECURITY VULNERABILITY: Seedphrase visible in ps aux!\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 2.8: VERIFY SECURE LOGGER MASKS SEEDPHRASES ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 2.8: Testing SecureLogger masks seedphrases"

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

############################ TEST 2.9: VERIFY RESTORE_FILE APPROACH ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 2.9: Testing RESTORE_FILE approach (file-based restore)"

# Create a new container with RESTORE_FILE to test the file-based restore
# Use current directory for temp file to avoid Git Bash path conversion issues
hostSeedFile="$(pwd)/eiou_test_restore_seed_$$"

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

# Clean up any existing container first
docker rm -f ${restoreFileContainer} > /dev/null 2>&1
docker volume rm ${restoreFileContainer}-mysql-data ${restoreFileContainer}-files ${restoreFileContainer}-index ${restoreFileContainer}-eiou > /dev/null 2>&1

# Create the container
# MSYS_NO_PATHCONV=1 disables Git Bash path conversion for this command
MSYS_NO_PATHCONV=1 docker run -d --network="${network}" --name "${restoreFileContainer}" \
    -v "${hostSeedFile}:/restore/seed:ro" \
    -e RESTORE_FILE="/restore/seed" \
    -v "${restoreFileContainer}-mysql-data:/var/lib/mysql" \
    -v "${restoreFileContainer}-files:/etc/eiou/" \
    -v "${restoreFileContainer}-index:/var/www/html" \
    -v "${restoreFileContainer}-eiou:/usr/local/bin/" \
    eioud > /dev/null 2>&1

sleep 30

# Extract first 3 words from seedphrase for checking
firstThreeWordsFile=$(cat "${hostSeedFile}" | awk '{print $1" "$2" "$3}')

# Check if seedphrase is in environment (should NOT be with RESTORE_FILE)
if docker exec ${restoreFileContainer} printenv 2>&1 | grep -q "$firstThreeWordsFile"; then
    seedInEnv="1"
else
    seedInEnv="0"
fi

# Get restored public key
restoredPubKeyRestoreFile=$(docker exec ${restoreFileContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    echo $json["public"] ?? "ERROR";
' 2>&1)

# Check if seedphrase is in docker logs
if docker logs ${restoreFileContainer} 2>&1 | grep -q "$firstThreeWordsFile"; then
    seedInLogs="1"
else
    seedInLogs="0"
fi

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

############################ TEST 2.10: VERIFY RESTORE ENV VAR APPROACH ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 2.10: Testing RESTORE env var approach"

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
# Note: Docker volume mounts don't need double slashes - only docker exec paths do
restoreEnvContainer="httpRestoreEnvTest"
docker run -d --network="${network}" --name "${restoreEnvContainer}" \
    -e RESTORE="${restoreEnvSeedPhrase}" \
    -v "${restoreEnvContainer}-mysql-data:/var/lib/mysql" \
    -v "${restoreEnvContainer}-files:/etc/eiou/" \
    -v "${restoreEnvContainer}-index:/var/www/html" \
    -v "${restoreEnvContainer}-eiou:/usr/local/bin/" \
    eioud > /dev/null 2>&1

sleep 25

# Get restored public key
restoredPubKeyRestoreEnv=$(docker exec ${restoreEnvContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    echo $json["public"] ?? "ERROR";
' 2>&1)

# Check for 3-word sequence in logs
# Use grep -q for boolean check, avoiding grep -c output issues
firstThreeWords=$(echo "$restoreEnvSeedPhrase" | awk '{print $1" "$2" "$3}')
if docker logs ${restoreEnvContainer} 2>&1 | grep -q "$firstThreeWords"; then
    threeWordInLogs="1"
else
    threeWordInLogs="0"
fi

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

################################################################################
#                    PART 3: AUTHCODE RESTORATION TEST
################################################################################

echo -e "\n\n[PART 3: Authcode Restoration Test on ${testContainer}]"
echo -e "================================================================"

############################ STORE ORIGINAL AUTHCODE ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3.1: Storing original authcode from userconfig.json"

originalAuthCode=$(docker exec ${testContainer} php -r '
    require_once "'"${SECURITY_DIR}"'/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["authcode_encrypted"])) {
        $authcode = KeyEncryption::decrypt($json["authcode_encrypted"]);
        echo $authcode;
    } else {
        echo "ERROR_NO_AUTHCODE";
    }
' 2>&1)

if [[ "$originalAuthCode" != "ERROR_NO_AUTHCODE" ]] && [[ -n "$originalAuthCode" ]]; then
    # Verify authcode format (should be 20 hex characters)
    if [[ ${#originalAuthCode} -eq 20 ]] && [[ "$originalAuthCode" =~ ^[0-9a-f]+$ ]]; then
        printf "\t   Original authcode retrieved ${GREEN}PASSED${NC}\n"
        printf "\t   BEFORE - Authcode: ${originalAuthCode}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Original authcode retrieved but invalid format ${YELLOW}WARNING${NC}\n"
        printf "\t   Authcode: ${originalAuthCode} (length: ${#originalAuthCode})\n"
        passed=$(( passed + 1 ))
    fi
else
    printf "\t   Original authcode retrieval ${RED}FAILED${NC}\n"
    printf "\t   Could not retrieve original authcode\n"
    failure=$(( failure + 1 ))
    # Cannot continue without original authcode - skip remaining authcode tests
    echo -e "\n\t   Skipping remaining authcode tests..."
    succesrate "${totaltests}" "${passed}" "${failure}" "'seedphrase test suite'"
    return 0
fi

############################ STORE ORIGINAL PUBLIC KEY FOR AUTHCODE TEST ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3.2: Storing original public key for verification"

originalPublicKeyAuth=$(docker exec ${testContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["public"])) {
        echo $json["public"];
    } else {
        echo "ERROR_NO_PUBLIC_KEY";
    }
' 2>&1)

if [[ "$originalPublicKeyAuth" != "ERROR_NO_PUBLIC_KEY" ]] && [[ -n "$originalPublicKeyAuth" ]]; then
    printf "\t   Original public key retrieved ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Original public key retrieval ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ DECRYPT MNEMONIC FOR AUTHCODE TEST ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3.3: Decrypting seed phrase from encrypted mnemonic"

seedPhraseAuth=$(docker exec ${testContainer} php -r '
    require_once "'"${EIOU_DIR}"'/src/security/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["mnemonic_encrypted"])) {
        $mnemonic = KeyEncryption::decrypt($json["mnemonic_encrypted"]);
        echo $mnemonic;
    } else {
        echo "ERROR_NO_MNEMONIC";
    }
' 2>&1)

if [[ "$seedPhraseAuth" != "ERROR_NO_MNEMONIC" ]] && [[ -n "$seedPhraseAuth" ]]; then
    wordCountAuth=$(echo "$seedPhraseAuth" | wc -w)
    if [[ "$wordCountAuth" -eq 24 ]]; then
        printf "\t   Seed phrase decrypted (${wordCountAuth} words) ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Seed phrase decrypted but has ${wordCountAuth} words (expected 24) ${YELLOW}WARNING${NC}\n"
        passed=$(( passed + 1 ))
    fi
else
    printf "\t   Seed phrase decryption ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
    succesrate "${totaltests}" "${passed}" "${failure}" "'seedphrase test suite'"
    return 0
fi

############################ DELETE USERCONFIG FOR AUTHCODE TEST ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3.4: Deleting userconfig.json to simulate fresh wallet"

deleteResultAuth=$(docker exec ${testContainer} rm -f ${USERCONFIG} 2>&1)
verifyDeletedAuth=$(docker exec ${testContainer} test -f ${USERCONFIG} && echo "EXISTS" || echo "DELETED")

# Also delete Tor hidden service files for full restore test
deleteTorResultAuth=$(docker exec ${testContainer} rm -f ${TOR_SECRET_KEY} ${TOR_PUBLIC_KEY} ${TOR_HOSTNAME} 2>&1)

if [[ "$verifyDeletedAuth" == "DELETED" ]]; then
    printf "\t   userconfig.json deleted ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   File deletion ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ RESTORE FROM SEED PHRASE FOR AUTHCODE TEST ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3.5: Restoring wallet with 'eiou generate restore <seed_phrase>'"

restoreOutputAuth=$(docker exec ${testContainer} eiou generate restore ${seedPhraseAuth} 2>&1)
sleep 1

verifyRestoredAuth=$(docker exec ${testContainer} test -f ${USERCONFIG} && echo "CREATED" || echo "NOT_CREATED")

if [[ "$verifyRestoredAuth" == "CREATED" ]]; then
    printf "\t   Wallet restored from seed phrase ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Wallet restoration ${RED}FAILED${NC}\n"
    printf "\t   Restore output: ${restoreOutputAuth}\n"
    failure=$(( failure + 1 ))
fi

############################ GET RESTORED AUTHCODE ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3.6: Retrieving restored authcode"

restoredAuthCode=$(docker exec ${testContainer} php -r '
    require_once "'"${SECURITY_DIR}"'/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["authcode_encrypted"])) {
        $authcode = KeyEncryption::decrypt($json["authcode_encrypted"]);
        echo $authcode;
    } else {
        echo "ERROR_NO_AUTHCODE";
    }
' 2>&1)

if [[ "$restoredAuthCode" != "ERROR_NO_AUTHCODE" ]] && [[ -n "$restoredAuthCode" ]]; then
    printf "\t   Restored authcode retrieved ${GREEN}PASSED${NC}\n"
    printf "\t   AFTER  - Authcode: ${restoredAuthCode}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Restored authcode retrieval ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ GET RESTORED PUBLIC KEY FOR AUTHCODE TEST ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3.7: Retrieving restored public key for verification"

restoredPublicKeyAuth=$(docker exec ${testContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["public"])) {
        echo $json["public"];
    } else {
        echo "ERROR_NO_PUBLIC_KEY";
    }
' 2>&1)

if [[ "$restoredPublicKeyAuth" != "ERROR_NO_PUBLIC_KEY" ]] && [[ -n "$restoredPublicKeyAuth" ]]; then
    printf "\t   Restored public key retrieved ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Restored public key retrieval ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ VERIFY PUBLIC KEY MATCHES ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3.8: Verifying public key matches (deterministic derivation working)"

if [[ "$originalPublicKeyAuth" == "$restoredPublicKeyAuth" ]]; then
    printf "\t   Public key matches - deterministic derivation confirmed ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Public key mismatch - deterministic derivation broken ${RED}FAILED${NC}\n"
    printf "\t   Original: ${originalPublicKeyAuth}\n"
    printf "\t   Restored: ${restoredPublicKeyAuth}\n"
    failure=$(( failure + 1 ))
fi

############################ COMPARE AUTHCODES ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3.9: Comparing original and restored authcodes"

echo -e "\n\t   ============================================"
echo -e "\t   AUTHCODE COMPARISON RESULTS"
echo -e "\t   ============================================"
echo -e "\t   BEFORE: ${originalAuthCode}"
echo -e "\t   AFTER:  ${restoredAuthCode}"
echo -e "\t   ============================================\n"

if [[ "$originalAuthCode" == "$restoredAuthCode" ]]; then
    printf "\t   ${GREEN}AUTHCODES MATCH - Deterministic authcode derivation working correctly!${NC}\n"
    printf "\t   Authcode comparison ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ${RED}AUTHCODES DO NOT MATCH - Authcode is not being derived from seedphrase!${NC}\n"
    printf "\t   ${YELLOW}This is the expected behavior currently - authcode uses random_bytes()${NC}\n"
    printf "\t   ${YELLOW}To fix: derive authcode deterministically from seed in Wallet.php${NC}\n"
    printf "\t   Authcode comparison ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST MULTIPLE RESTORE ITERATIONS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3.10: Testing consistency across multiple restore iterations"

# Store current authcode
iteration1AuthCode="$restoredAuthCode"

# Delete and restore again
docker exec ${testContainer} rm -f ${USERCONFIG} ${TOR_SECRET_KEY} ${TOR_PUBLIC_KEY} ${TOR_HOSTNAME} 2>&1
docker exec ${testContainer} eiou generate restore ${seedPhraseAuth} 2>&1
sleep 1

iteration2AuthCode=$(docker exec ${testContainer} php -r '
    require_once "'"${SECURITY_DIR}"'/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["authcode_encrypted"])) {
        $authcode = KeyEncryption::decrypt($json["authcode_encrypted"]);
        echo $authcode;
    } else {
        echo "ERROR_NO_AUTHCODE";
    }
' 2>&1)

# Delete and restore a third time
docker exec ${testContainer} rm -f ${USERCONFIG} ${TOR_SECRET_KEY} ${TOR_PUBLIC_KEY} ${TOR_HOSTNAME} 2>&1
docker exec ${testContainer} eiou generate restore ${seedPhraseAuth} 2>&1
sleep 1

iteration3AuthCode=$(docker exec ${testContainer} php -r '
    require_once "'"${SECURITY_DIR}"'/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["authcode_encrypted"])) {
        $authcode = KeyEncryption::decrypt($json["authcode_encrypted"]);
        echo $authcode;
    } else {
        echo "ERROR_NO_AUTHCODE";
    }
' 2>&1)

echo -e "\t   Iteration 1: ${iteration1AuthCode}"
echo -e "\t   Iteration 2: ${iteration2AuthCode}"
echo -e "\t   Iteration 3: ${iteration3AuthCode}"

if [[ "$iteration1AuthCode" == "$iteration2AuthCode" ]] && [[ "$iteration2AuthCode" == "$iteration3AuthCode" ]]; then
    printf "\t   ${GREEN}All iterations produce same authcode - deterministic${NC}\n"
    printf "\t   Multiple iterations ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ${RED}Authcodes differ between iterations - random generation detected${NC}\n"
    printf "\t   ${YELLOW}This confirms authcode is NOT derived from seedphrase${NC}\n"
    printf "\t   Multiple iterations ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST WITH NEW CONTAINER ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3.11: Testing authcode restoration in new container"

# Create a completely new Container
authcodeRestoreContainer="httpAuthcodeRestoreTest"
authcodeContainerHash=$(docker run -d --network="${network}" --name "${authcodeRestoreContainer}" -v "${authcodeRestoreContainer}-mysql-data:/var/lib/mysql" -v "${authcodeRestoreContainer}-files:/etc/eiou/" -v "${authcodeRestoreContainer}-index:/var/www/html" -v "${authcodeRestoreContainer}-eiou:/usr/local/bin/" -e RESTORE="${seedPhraseAuth}" eioud 2>&1)

sleep 5

newContainerAuthCode=$(docker exec ${authcodeRestoreContainer} php -r '
    require_once "/etc/eiou/src/security/KeyEncryption.php";
    $json = json_decode(file_get_contents("/etc/eiou/userconfig.json"), true);
    if (isset($json["authcode_encrypted"])) {
        $authcode = \KeyEncryption::decrypt($json["authcode_encrypted"]);
        echo $authcode;
    } else {
        echo "ERROR_NO_AUTHCODE";
    }
' 2>&1)

if [[ "$newContainerAuthCode" != "ERROR_NO_AUTHCODE" ]] && [[ -n "$newContainerAuthCode" ]]; then
    printf "\t   New container authcode retrieved: ${newContainerAuthCode}\n"

    if [[ "$originalAuthCode" == "$newContainerAuthCode" ]]; then
        printf "\t   ${GREEN}New container authcode matches original!${NC}\n"
        printf "\t   New container test ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   ${RED}New container authcode differs from original${NC}\n"
        printf "\t   ${YELLOW}Expected behavior with current random implementation${NC}\n"
        printf "\t   New container test ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   New container authcode retrieval ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ CLEANUP TEST CONTAINER ############################

echo -e "\n\t-> Cleaning up test container..."
docker rm -f ${authcodeRestoreContainer} 2>/dev/null
docker volume rm ${authcodeRestoreContainer}-mysql-data ${authcodeRestoreContainer}-files ${authcodeRestoreContainer}-index ${authcodeRestoreContainer}-eiou 2>/dev/null

############################ AUTHCODE SUMMARY ############################

echo -e "\n\t   ============================================"
echo -e "\t   AUTHCODE RESTORATION TEST SUMMARY"
echo -e "\t   ============================================"
if [[ "$originalAuthCode" == "$restoredAuthCode" ]]; then
    echo -e "\t   ${GREEN}Authcode restoration: WORKING${NC}"
else
    echo -e "\t   ${RED}Authcode restoration: NOT IMPLEMENTED${NC}"
    echo -e "\t   ${YELLOW}The authcode is randomly generated, not derived from seed.${NC}"
    echo -e "\t   ${YELLOW}To fix: Modify Wallet.php to derive authcode from seed${NC}"
    echo -e "\t   ${YELLOW}using HMAC-SHA256 or similar deterministic method.${NC}"
fi
echo -e "\t   ============================================\n"

##################################################################
#                    FINAL SUMMARY
##################################################################

echo -e "\n================================================================"
echo -e "         SEEDPHRASE TEST SUITE COMPLETE"
echo -e "================================================================\n"

succesrate "${totaltests}" "${passed}" "${failure}" "'seedphrase test suite'"
