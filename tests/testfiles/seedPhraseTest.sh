#!/bin/sh

# Test seed phrase generate and restore functionality
# Verifies that restoring from a seed phrase yields the same keys as before
# PR #198 - Seed phrase deterministic derivation test
#
# NOTE: All paths use double slashes (//etc/eiou/) to prevent Git Bash on Windows
# from converting /etc/ to C:/Program Files/Git/etc/. This is safe on Linux too.

echo -e "\nTesting seed phrase generate and restore functionality..."

testname="seedPhraseTest"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

echo -e "\n[Seed Phrase Restore Test on ${testContainer}]"

############################ STORE ORIGINAL PUBLIC KEY ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1: Storing original public key from userconfig.json"

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
    succesrate "${totaltests}" "${passed}" "${failure}" "'seed phrase restore'"
    return 1
fi

############################ DECRYPT MNEMONIC ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 2: Decrypting seed phrase from encrypted mnemonic"

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
    succesrate "${totaltests}" "${passed}" "${failure}" "'seed phrase restore'"
    return 1
fi

############################ BACKUP MASTER KEY ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3: Backing up master key before deletion"

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

############################ DELETE USERCONFIG ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 4: Deleting userconfig.json to simulate fresh wallet"

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

if [[ "$verifyDeleted" == "DELETED" ]]; then
    printf "\t   userconfig.json deleted ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   userconfig.json deletion ${RED}FAILED${NC}\n"
    printf "\t   Delete result: ${deleteResult}\n"
    failure=$(( failure + 1 ))
fi

############################ RESTORE FROM SEED PHRASE ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 5: Restoring wallet with 'eiou generate restore <seed_phrase>'"

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
echo -e "\n\t-> Step 6: Retrieving restored public key"

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

############################ RESTORE FROM SEED PHRASE NEW CONTAINER ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 7: Restoring wallet with 'docker run -d ... -e RESTORE=<seed_phrase>'"

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
echo -e "\n\t-> Step 8: Retrieving restored public key from new Container"

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

############################ COMPARE PUBLIC KEYS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 9: Comparing original and restored public keys"

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

############################ RESTORE HOSTNAME (CLEANUP) ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 10: Verifying wallet configuration is intact"

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

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'seed phrase restore'"
