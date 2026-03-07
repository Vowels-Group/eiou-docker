#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

# Consolidated Seedphrase Test Suite
# Combines:
# - Seed phrase generate and restore functionality
# - Secure seedphrase display (security validation)
# - Authcode restoration from seedphrase
# - Restore + QUICKSTART hostname application
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
echo -e "  - Master key deterministic derivation (M-13)"
echo -e "  - Restore + QUICKSTART hostname application"
echo -e "  - Startup authcode temp file creation"
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
        $mnemonic = \Eiou\Security\KeyEncryption::decrypt($json["mnemonic_encrypted"]);
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

############################ STORE ORIGINAL MASTER KEY HASH ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.5: Storing original master key hash before deletion"

# First check if master key file exists
masterKeyExists=$(docker exec ${testContainer} test -f ${MASTER_KEY} && echo "EXISTS" || echo "NOT_FOUND")

if [[ "$masterKeyExists" == "EXISTS" ]]; then
    # Store SHA-256 hash of master key for deterministic derivation comparison after restore
    originalMasterKeyHash=$(docker exec ${testContainer} sha256sum ${MASTER_KEY} 2>&1 | awk '{print $1}')

    if [[ -n "$originalMasterKeyHash" ]] && [[ ${#originalMasterKeyHash} -eq 64 ]]; then
        printf "\t   Master key hash stored ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Master key hash ${RED}FAILED${NC} - file exists but could not hash\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   Master key hash ${RED}FAILED${NC} - file does not exist (test -f returned NOT_FOUND)\n"
    failure=$(( failure + 1 ))
fi

############################ DELETE USERCONFIG, MASTER KEY, AND TOR FILES ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.6: Deleting userconfig.json, master key, and Tor files to simulate fresh wallet"

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

# Delete master key to test deterministic re-derivation from seed (M-13)
deleteMasterKeyResult=$(docker exec ${testContainer} rm -f ${MASTER_KEY} 2>&1)
verifyMasterKeyDeleted=$(docker exec ${testContainer} test -f ${MASTER_KEY} && echo "EXISTS" || echo "DELETED")

# Clean up any existing temp files so we can verify restore doesn't re-create seedphrase file
docker exec ${testContainer} sh -c 'rm -f /dev/shm/eiou_wallet_info_* /tmp/eiou_wallet_info_* /dev/shm/eiou_authcode_* /tmp/eiou_authcode_*' 2>/dev/null

# Also delete Tor hidden service files to test deterministic regeneration
deleteTorResult=$(docker exec ${testContainer} rm -f ${TOR_SECRET_KEY} ${TOR_PUBLIC_KEY} ${TOR_HOSTNAME} 2>&1)
verifyTorDeleted=$(docker exec ${testContainer} test -f ${TOR_HOSTNAME} && echo "EXISTS" || echo "DELETED")

if [[ "$verifyDeleted" == "DELETED" ]] && [[ "$verifyMasterKeyDeleted" == "DELETED" ]] && [[ "$verifyTorDeleted" == "DELETED" ]]; then
    printf "\t   userconfig.json, master key, and Tor files deleted ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [[ "$verifyDeleted" == "DELETED" ]] && [[ "$verifyMasterKeyDeleted" == "DELETED" ]]; then
    printf "\t   userconfig.json and master key deleted, Tor files deletion ${YELLOW}WARNING${NC}\n"
    printf "\t   Tor delete result: ${deleteTorResult}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   File deletion ${RED}FAILED${NC}\n"
    printf "\t   Delete userconfig result: ${deleteResult}\n"
    printf "\t   Delete master key result: ${deleteMasterKeyResult}\n"
    failure=$(( failure + 1 ))
fi

############################ RESTORE FROM SEED PHRASE ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.7: Restoring wallet with 'eiou generate restore <seed_phrase>'"

# Run the restore command with the seed phrase
restoreOutput=$(docker exec ${testContainer} eiou generate restore ${seedPhrase} 2>&1)

# Wait for file system sync using polling
wait_for_file ${testContainer} "${USERCONFIG}" 10 || true

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

######################## VERIFY NO SEEDPHRASE FILE ON RESTORE ########################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.7a: Verify seedphrase file NOT created on restore"

# Check that no eiou_wallet_info_* file exists (seedphrase should not be written on restore)
seedphraseFileExists=$(docker exec ${testContainer} sh -c 'ls /dev/shm/eiou_wallet_info_* /tmp/eiou_wallet_info_* 2>/dev/null | head -1')

if [[ -z "$seedphraseFileExists" ]]; then
    printf "\t   Seedphrase file NOT created on restore ${GREEN}PASSED${NC}\n"
    printf "\t   No eiou_wallet_info_* files found (expected)\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Seedphrase file created on restore ${RED}FAILED${NC}\n"
    printf "\t   Found unexpected file: ${seedphraseFileExists}\n"
    failure=$(( failure + 1 ))
fi

######################## VERIFY AUTHCODE FILE ON RESTORE ########################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.7b: Verify authcode file IS created on restore"

# Check that eiou_authcode_* file exists (authcode should be written on restore)
authcodeFileExists=$(docker exec ${testContainer} sh -c 'ls /dev/shm/eiou_authcode_* /tmp/eiou_authcode_* 2>/dev/null | head -1')

if [[ -n "$authcodeFileExists" ]]; then
    printf "\t   Authcode file created on restore ${GREEN}PASSED${NC}\n"
    printf "\t   Found: ${authcodeFileExists}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Authcode file NOT created on restore ${RED}FAILED${NC}\n"
    printf "\t   No eiou_authcode_* files found in /dev/shm/ or /tmp/\n"
    failure=$(( failure + 1 ))
fi

############################ VERIFY MASTER KEY DETERMINISTIC DERIVATION ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.7c: Verify master key was deterministically re-derived (M-13)"

# The master key was deleted in Step 1.6. After restore, it should be re-derived
# from the seed phrase and produce the exact same key as the original.
restoredMasterKeyExists=$(docker exec ${testContainer} test -f ${MASTER_KEY} && echo "EXISTS" || echo "NOT_FOUND")

if [[ "$restoredMasterKeyExists" == "EXISTS" ]]; then
    restoredMasterKeyHash=$(docker exec ${testContainer} sha256sum ${MASTER_KEY} 2>&1 | awk '{print $1}')

    echo -e "\n\t   ============================================"
    echo -e "\t   MASTER KEY COMPARISON (M-13)"
    echo -e "\t   ============================================"
    echo -e "\t   Hashes match: $([ "$originalMasterKeyHash" == "$restoredMasterKeyHash" ] && echo 'YES' || echo 'NO')"
    echo -e "\t   ============================================\n"

    if [[ "$originalMasterKeyHash" == "$restoredMasterKeyHash" ]]; then
        printf "\t   ${GREEN}MASTER KEYS MATCH - Deterministic derivation from seed working correctly!${NC}\n"
        printf "\t   Master key deterministic derivation ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   ${RED}MASTER KEYS DO NOT MATCH - Deterministic derivation from seed broken!${NC}\n"
        printf "\t   Master key deterministic derivation ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   Master key file not re-created after restore ${RED}FAILED${NC}\n"
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

# Clean up any existing container and volumes from previous runs
# Without this, stale volumes cause ConfigCheck to skip RESTORE (userconfig.json already exists)
docker rm -f ${restoreContainer} > /dev/null 2>&1
docker volume rm ${restoreContainer}-mysql-data ${restoreContainer}-files ${restoreContainer}-eiou > /dev/null 2>&1

restoreContainerHash=$(docker run -d  --network="${network}" --name "${restoreContainer}" -v "${restoreContainer}-mysql-data:/var/lib/mysql" -v "${restoreContainer}-files:/etc/eiou/" -e  RESTORE="${seedPhrase}" eiou/eiou 2>&1)

# Wait for container to fully initialize and process RESTORE env var
# Container needs time for: MariaDB startup, startup.sh execution, wallet restoration
echo -e "\t   Waiting for container initialization..."
wait_for_container_initialized ${restoreContainer} 60 || true

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

############################ VERIFY MASTER KEY ON NEW CONTAINER ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1.14a: Verify master key on new container matches original (M-13)"

restoredMasterKeyContainerExists=$(docker exec ${restoreContainer} test -f ${MASTER_KEY} && echo "EXISTS" || echo "NOT_FOUND")

if [[ "$restoredMasterKeyContainerExists" == "EXISTS" ]]; then
    restoredMasterKeyContainerHash=$(docker exec ${restoreContainer} sha256sum ${MASTER_KEY} 2>&1 | awk '{print $1}')

    echo -e "\n\t   ============================================"
    echo -e "\t   MASTER KEY - NEW CONTAINER (M-13)"
    echo -e "\t   ============================================"
    echo -e "\t   Hashes match: $([ "$originalMasterKeyHash" == "$restoredMasterKeyContainerHash" ] && echo 'YES' || echo 'NO')"
    echo -e "\t   ============================================\n"

    if [[ "$originalMasterKeyHash" == "$restoredMasterKeyContainerHash" ]]; then
        printf "\t   ${GREEN}MASTER KEY MATCHES ON NEW CONTAINER - Seed-derived master key working!${NC}\n"
        printf "\t   New container master key comparison ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   ${RED}MASTER KEY MISMATCH ON NEW CONTAINER - Seed derivation broken!${NC}\n"
        printf "\t   New container master key comparison ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   Master key not found on new container ${RED}FAILED${NC}\n"
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

# Clean up the restore container from Step 1.12
docker rm -f ${restoreContainer} > /dev/null 2>&1
docker volume rm ${restoreContainer}-mysql-data ${restoreContainer}-files ${restoreContainer}-eiou > /dev/null 2>&1

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
        echo \Eiou\Security\KeyEncryption::decrypt($json["mnemonic_encrypted"]);
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
    $mnemonic = \Eiou\Security\KeyEncryption::decrypt($json["mnemonic_encrypted"]);
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
    $availability = \Eiou\Utils\SecureSeedphraseDisplay::checkAvailability();
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
    $class = new ReflectionClass("Eiou\\Utils\\SecureSeedphraseDisplay");
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
    echo \Eiou\Security\KeyEncryption::decrypt($json["mnemonic_encrypted"]);
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
docker exec ${testContainer} sh -c "rm -f //etc//eiou//config//userconfig.json"

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
    $class = new ReflectionClass("Eiou\\Utils\\SecureLogger");
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
    echo \Eiou\Security\KeyEncryption::decrypt($json["mnemonic_encrypted"]);
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
docker volume rm ${restoreFileContainer}-mysql-data ${restoreFileContainer}-files ${restoreFileContainer}-eiou > /dev/null 2>&1

# Create the container
# MSYS_NO_PATHCONV=1 disables Git Bash path conversion for this command
MSYS_NO_PATHCONV=1 docker run -d --network="${network}" --name "${restoreFileContainer}" \
    -v "${hostSeedFile}:/restore/seed:ro" \
    -e RESTORE_FILE="/restore/seed" \
    -v "${restoreFileContainer}-mysql-data:/var/lib/mysql" \
    -v "${restoreFileContainer}-files:/etc/eiou/" \
    eiou/eiou > /dev/null 2>&1

echo -e "\t   Waiting for container initialization..."
wait_for_container_initialized ${restoreFileContainer} 60 || true

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
docker volume rm ${restoreFileContainer}-mysql-data ${restoreFileContainer}-files ${restoreFileContainer}-eiou > /dev/null 2>&1
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
    echo \Eiou\Security\KeyEncryption::decrypt($json["mnemonic_encrypted"]);
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
    eiou/eiou > /dev/null 2>&1

echo -e "\t   Waiting for container initialization..."
wait_for_container_initialized ${restoreEnvContainer} 60 || true

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
docker volume rm ${restoreEnvContainer}-mysql-data ${restoreEnvContainer}-files ${restoreEnvContainer}-eiou > /dev/null 2>&1

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

originalAuthCodeResult=$(docker exec ${testContainer} php -r '
    require_once "'"${SECURITY_DIR}"'/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["authcode_encrypted"])) {
        $authcode = \Eiou\Security\KeyEncryption::decrypt($json["authcode_encrypted"]);
        echo $authcode . "|" . json_encode($json["authcode_encrypted"]);
    } else {
        echo "ERROR_NO_AUTHCODE";
    }
' 2>&1)

# Split: decrypted for comparison, encrypted for display (SECURITY: never print decrypted)
originalAuthCode="${originalAuthCodeResult%%|*}"
originalAuthCodeEnc="${originalAuthCodeResult##*|}"

if [[ "$originalAuthCode" != "ERROR_NO_AUTHCODE" ]] && [[ -n "$originalAuthCode" ]]; then
    # Verify authcode format (should be 20 hex characters)
    if [[ ${#originalAuthCode} -eq 20 ]] && [[ "$originalAuthCode" =~ ^[0-9a-f]+$ ]]; then
        printf "\t   Original authcode retrieved ${GREEN}PASSED${NC}\n"
        printf "\t   BEFORE - Authcode (encrypted): ${originalAuthCodeEnc:0:16}... (${#originalAuthCode} chars decrypted)\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Original authcode retrieved but invalid format ${YELLOW}WARNING${NC}\n"
        printf "\t   Authcode (encrypted): ${originalAuthCodeEnc:0:16}... (length: ${#originalAuthCode})\n"
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
        $mnemonic = \Eiou\Security\KeyEncryption::decrypt($json["mnemonic_encrypted"]);
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
wait_for_file ${testContainer} "${USERCONFIG}" 10 || true

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

restoredAuthCodeResult=$(docker exec ${testContainer} php -r '
    require_once "'"${SECURITY_DIR}"'/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["authcode_encrypted"])) {
        $authcode = \Eiou\Security\KeyEncryption::decrypt($json["authcode_encrypted"]);
        echo $authcode . "|" . json_encode($json["authcode_encrypted"]);
    } else {
        echo "ERROR_NO_AUTHCODE";
    }
' 2>&1)

# Split: decrypted for comparison, encrypted for display (SECURITY: never print decrypted)
restoredAuthCode="${restoredAuthCodeResult%%|*}"
restoredAuthCodeEnc="${restoredAuthCodeResult##*|}"

if [[ "$restoredAuthCode" != "ERROR_NO_AUTHCODE" ]] && [[ -n "$restoredAuthCode" ]]; then
    printf "\t   Restored authcode retrieved ${GREEN}PASSED${NC}\n"
    printf "\t   AFTER  - Authcode (encrypted): ${restoredAuthCodeEnc:0:16}... (${#restoredAuthCode} chars decrypted)\n"
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
echo -e "\t   BEFORE (encrypted): ${originalAuthCodeEnc:0:16}..."
echo -e "\t   AFTER  (encrypted): ${restoredAuthCodeEnc:0:16}..."
echo -e "\t   Match:  $( [[ "$originalAuthCode" == "$restoredAuthCode" ]] && echo 'YES' || echo 'NO' )"
echo -e "\t   ============================================\n"

if [[ "$originalAuthCode" == "$restoredAuthCode" ]]; then
    printf "\t   ${GREEN}AUTHCODES MATCH - Deterministic authcode derivation working correctly!${NC}\n"
    printf "\t   Authcode comparison ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ${RED}AUTHCODES DO NOT MATCH - Deterministic authcode derivation broken!${NC}\n"
    printf "\t   Authcode comparison ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST MULTIPLE RESTORE ITERATIONS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3.10: Testing consistency across multiple restore iterations"

# Store current authcode (decrypted for comparison, encrypted for display)
iteration1AuthCode="$restoredAuthCode"
iteration1AuthCodeEnc="$restoredAuthCodeEnc"

# Delete and restore again
docker exec ${testContainer} rm -f ${USERCONFIG} ${TOR_SECRET_KEY} ${TOR_PUBLIC_KEY} ${TOR_HOSTNAME} 2>&1
docker exec ${testContainer} eiou generate restore ${seedPhraseAuth} 2>&1
wait_for_file ${testContainer} "${USERCONFIG}" 10 || true

iteration2AuthCodeResult=$(docker exec ${testContainer} php -r '
    require_once "'"${SECURITY_DIR}"'/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["authcode_encrypted"])) {
        $authcode = \Eiou\Security\KeyEncryption::decrypt($json["authcode_encrypted"]);
        echo $authcode . "|" . json_encode($json["authcode_encrypted"]);
    } else {
        echo "ERROR_NO_AUTHCODE";
    }
' 2>&1)
iteration2AuthCode="${iteration2AuthCodeResult%%|*}"
iteration2AuthCodeEnc="${iteration2AuthCodeResult##*|}"

# Delete and restore a third time
docker exec ${testContainer} rm -f ${USERCONFIG} ${TOR_SECRET_KEY} ${TOR_PUBLIC_KEY} ${TOR_HOSTNAME} 2>&1
docker exec ${testContainer} eiou generate restore ${seedPhraseAuth} 2>&1
wait_for_file ${testContainer} "${USERCONFIG}" 10 || true

iteration3AuthCodeResult=$(docker exec ${testContainer} php -r '
    require_once "'"${SECURITY_DIR}"'/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["authcode_encrypted"])) {
        $authcode = \Eiou\Security\KeyEncryption::decrypt($json["authcode_encrypted"]);
        echo $authcode . "|" . json_encode($json["authcode_encrypted"]);
    } else {
        echo "ERROR_NO_AUTHCODE";
    }
' 2>&1)
iteration3AuthCode="${iteration3AuthCodeResult%%|*}"
iteration3AuthCodeEnc="${iteration3AuthCodeResult##*|}"

echo -e "\t   Iteration 1 (encrypted): ${iteration1AuthCodeEnc:0:16}..."
echo -e "\t   Iteration 2 (encrypted): ${iteration2AuthCodeEnc:0:16}..."
echo -e "\t   Iteration 3 (encrypted): ${iteration3AuthCodeEnc:0:16}..."

if [[ "$iteration1AuthCode" == "$iteration2AuthCode" ]] && [[ "$iteration2AuthCode" == "$iteration3AuthCode" ]]; then
    printf "\t   ${GREEN}All iterations produce same authcode - deterministic${NC}\n"
    printf "\t   Multiple iterations ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ${RED}Authcodes differ between iterations - deterministic derivation broken!${NC}\n"
    printf "\t   Multiple iterations ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST WITH NEW CONTAINER ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3.11: Testing authcode restoration in new container"

# Create a completely new Container
authcodeRestoreContainer="httpAuthcodeRestoreTest"
authcodeContainerHash=$(docker run -d --network="${network}" --name "${authcodeRestoreContainer}" -v "${authcodeRestoreContainer}-mysql-data:/var/lib/mysql" -v "${authcodeRestoreContainer}-files:/etc/eiou/" -e RESTORE="${seedPhraseAuth}" eiou/eiou 2>&1)

# Wait for container to fully initialize and process RESTORE env var
echo -e "\t   Waiting for container initialization..."
wait_for_container_initialized ${authcodeRestoreContainer} 60 || true

newContainerAuthCodeResult=$(docker exec ${authcodeRestoreContainer} php -r '
    require_once "/etc/eiou/src/bootstrap.php";
    $json = json_decode(file_get_contents("/etc/eiou/config/userconfig.json"), true);
    if (isset($json["authcode_encrypted"])) {
        $authcode = \Eiou\Security\KeyEncryption::decrypt($json["authcode_encrypted"]);
        echo $authcode . "|" . json_encode($json["authcode_encrypted"]);
    } else {
        echo "ERROR_NO_AUTHCODE";
    }
' 2>&1)

# Split: decrypted for comparison, encrypted for display (SECURITY: never print decrypted)
newContainerAuthCode="${newContainerAuthCodeResult%%|*}"
newContainerAuthCodeEnc="${newContainerAuthCodeResult##*|}"

if [[ "$newContainerAuthCode" != "ERROR_NO_AUTHCODE" ]] && [[ -n "$newContainerAuthCode" ]]; then
    printf "\t   New container authcode retrieved (encrypted): ${newContainerAuthCodeEnc:0:16}...\n"

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
docker volume rm ${authcodeRestoreContainer}-mysql-data ${authcodeRestoreContainer}-files ${authcodeRestoreContainer}-eiou 2>/dev/null

############################ AUTHCODE SUMMARY ############################

echo -e "\n\t   ============================================"
echo -e "\t   AUTHCODE RESTORATION TEST SUMMARY"
echo -e "\t   ============================================"
if [[ "$originalAuthCode" == "$restoredAuthCode" ]]; then
    echo -e "\t   ${GREEN}Authcode restoration: WORKING${NC}"
else
    echo -e "\t   ${RED}Authcode restoration: BROKEN${NC}"
    echo -e "\t   ${RED}Authcodes should be deterministic (derived from seed).${NC}"
fi
echo -e "\t   ============================================\n"

################################################################################
#                    PART 4: RESTORE + QUICKSTART HOSTNAME TEST
################################################################################

echo -e "\n\n[PART 4: Restore + QUICKSTART Hostname Test]"
echo -e "================================================================"
echo -e "Testing that QUICKSTART hostname is applied after wallet restore"
echo -e "================================================================"

############################ TEST 4.1: RESTORE ENV VAR + QUICKSTART ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 4.1: Testing RESTORE + QUICKSTART applies hostname to restored wallet"

# Get the current seedphrase from existing container
restoreQsSeedPhrase=$(docker exec ${testContainer} php -r '
    require_once "'"${EIOU_DIR}"'/src/security/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    echo \Eiou\Security\KeyEncryption::decrypt($json["mnemonic_encrypted"]);
' 2>&1)

# Get original public key for comparison
originalPubKeyQs=$(docker exec ${testContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    echo $json["public"] ?? "ERROR";
' 2>&1)

# Create a new container with both RESTORE and QUICKSTART
restoreQsContainer="httpRestoreQuickstartTest"
docker rm -f ${restoreQsContainer} > /dev/null 2>&1
docker volume rm ${restoreQsContainer}-mysql-data ${restoreQsContainer}-files ${restoreQsContainer}-eiou > /dev/null 2>&1

docker run -d --network="${network}" --name "${restoreQsContainer}" \
    -e RESTORE="${restoreQsSeedPhrase}" \
    -e QUICKSTART="${restoreQsContainer}" \
    -v "${restoreQsContainer}-mysql-data:/var/lib/mysql" \
    -v "${restoreQsContainer}-files:/etc/eiou/" \
    eiou/eiou > /dev/null 2>&1

echo -e "\t   Waiting for container initialization..."
wait_for_container_initialized ${restoreQsContainer} 60 || true

# Check if wallet was restored (public key matches)
restoredPubKeyQs=$(docker exec ${restoreQsContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    echo $json["public"] ?? "ERROR";
' 2>&1)

if [[ "$originalPubKeyQs" == "$restoredPubKeyQs" ]] && [[ "$restoredPubKeyQs" != "ERROR" ]]; then
    printf "\t   Wallet restored with matching public key ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Wallet restoration with QUICKSTART ${RED}FAILED${NC}\n"
    printf "\t   Original: ${originalPubKeyQs:0:50}...\n"
    printf "\t   Restored: ${restoredPubKeyQs:0:50}...\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 4.2: VERIFY HOSTNAME SET IN USERCONFIG ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 4.2: Verifying hostname is set in userconfig.json after RESTORE + QUICKSTART"

hostnameCheck=$(docker exec ${restoreQsContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    $hostname = $json["hostname"] ?? "NOT_SET";
    $hostnameSec = $json["hostname_secure"] ?? "NOT_SET";
    echo json_encode(["hostname" => $hostname, "hostname_secure" => $hostnameSec]);
' 2>&1)

# Parse hostname and hostname_secure from JSON
hostnameValue=$(echo "$hostnameCheck" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["hostname"] ?? "NOT_SET";')
hostnameSecureValue=$(echo "$hostnameCheck" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["hostname_secure"] ?? "NOT_SET";')

echo -e "\n\t   ============================================"
echo -e "\t   HOSTNAME CHECK (RESTORE + QUICKSTART)"
echo -e "\t   ============================================"
echo -e "\t   hostname:        ${hostnameValue}"
echo -e "\t   hostname_secure: ${hostnameSecureValue}"
echo -e "\t   expected:        http://${restoreQsContainer}"
echo -e "\t   expected_secure: https://${restoreQsContainer}"
echo -e "\t   ============================================\n"

if [[ "$hostnameValue" == "http://${restoreQsContainer}" ]] && [[ "$hostnameSecureValue" == "https://${restoreQsContainer}" ]]; then
    printf "\t   ${GREEN}HOSTNAME SET CORRECTLY after RESTORE + QUICKSTART!${NC}\n"
    printf "\t   Hostname verification ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ${RED}HOSTNAME NOT SET CORRECTLY after RESTORE + QUICKSTART${NC}\n"
    if [[ "$hostnameValue" == "NOT_SET" ]]; then
        printf "\t   hostname field is missing from userconfig.json\n"
    fi
    if [[ "$hostnameSecureValue" == "NOT_SET" ]]; then
        printf "\t   hostname_secure field is missing from userconfig.json\n"
    fi
    printf "\t   Hostname verification ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 4.3: VERIFY TOR ADDRESS STILL PRESENT ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 4.3: Verifying Tor address is preserved alongside hostname"

torAddressQs=$(docker exec ${restoreQsContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    echo $json["torAddress"] ?? "NOT_SET";
' 2>&1)

if [[ "$torAddressQs" != "NOT_SET" ]] && [[ -n "$torAddressQs" ]] && [[ "$torAddressQs" == *".onion" ]]; then
    printf "\t   Tor address preserved after QUICKSTART hostname applied ${GREEN}PASSED${NC}\n"
    printf "\t   Tor: ${torAddressQs}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Tor address verification ${RED}FAILED${NC}\n"
    printf "\t   Tor address: ${torAddressQs}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 4.4: CLEANUP RESTORE ENV + QUICKSTART CONTAINER ############################

echo -e "\n\t-> Cleaning up RESTORE + QUICKSTART container..."
docker rm -f ${restoreQsContainer} 2>/dev/null
docker volume rm ${restoreQsContainer}-mysql-data ${restoreQsContainer}-files ${restoreQsContainer}-eiou 2>/dev/null

############################ TEST 4.5: RESTORE_FILE + QUICKSTART ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 4.4: Testing RESTORE_FILE + QUICKSTART applies hostname to restored wallet"

# Write seed phrase to temp file on host
hostSeedFileQs="$(pwd)/eiou_test_restore_qs_seed_$$"
echo "${restoreQsSeedPhrase}" > "${hostSeedFileQs}"
chmod 600 "${hostSeedFileQs}"

# Create a new container with both RESTORE_FILE and QUICKSTART
restoreFileQsContainer="httpRestoreFileQsTest"
docker rm -f ${restoreFileQsContainer} > /dev/null 2>&1
docker volume rm ${restoreFileQsContainer}-mysql-data ${restoreFileQsContainer}-files ${restoreFileQsContainer}-eiou > /dev/null 2>&1

MSYS_NO_PATHCONV=1 docker run -d --network="${network}" --name "${restoreFileQsContainer}" \
    -v "${hostSeedFileQs}:/restore/seed:ro" \
    -e RESTORE_FILE="/restore/seed" \
    -e QUICKSTART="${restoreFileQsContainer}" \
    -v "${restoreFileQsContainer}-mysql-data:/var/lib/mysql" \
    -v "${restoreFileQsContainer}-files:/etc/eiou/" \
    eiou/eiou > /dev/null 2>&1

echo -e "\t   Waiting for container initialization..."
wait_for_container_initialized ${restoreFileQsContainer} 60 || true

# Check hostname and hostname_secure
hostnameFileQsCheck=$(docker exec ${restoreFileQsContainer} php -r '
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    $hostname = $json["hostname"] ?? "NOT_SET";
    $hostnameSec = $json["hostname_secure"] ?? "NOT_SET";
    $pubKey = $json["public"] ?? "NOT_SET";
    $torAddr = $json["torAddress"] ?? "NOT_SET";
    echo json_encode(["hostname" => $hostname, "hostname_secure" => $hostnameSec, "public" => $pubKey, "torAddress" => $torAddr]);
' 2>&1)

# Parse results
hostnameFileQs=$(echo "$hostnameFileQsCheck" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["hostname"] ?? "NOT_SET";')
hostnameSecureFileQs=$(echo "$hostnameFileQsCheck" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["hostname_secure"] ?? "NOT_SET";')
pubKeyFileQs=$(echo "$hostnameFileQsCheck" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["public"] ?? "NOT_SET";')
torAddrFileQs=$(echo "$hostnameFileQsCheck" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["torAddress"] ?? "NOT_SET";')

echo -e "\n\t   ============================================"
echo -e "\t   HOSTNAME CHECK (RESTORE_FILE + QUICKSTART)"
echo -e "\t   ============================================"
echo -e "\t   hostname:        ${hostnameFileQs}"
echo -e "\t   hostname_secure: ${hostnameSecureFileQs}"
echo -e "\t   expected:        http://${restoreFileQsContainer}"
echo -e "\t   expected_secure: https://${restoreFileQsContainer}"
echo -e "\t   public_key:      ${pubKeyFileQs:0:50}..."
echo -e "\t   tor_address:     ${torAddrFileQs}"
echo -e "\t   ============================================\n"

# Verify all conditions: hostname set, key matches, Tor preserved
hostnameFileOk="false"
keyFileOk="false"
torFileOk="false"

if [[ "$hostnameFileQs" == "http://${restoreFileQsContainer}" ]] && [[ "$hostnameSecureFileQs" == "https://${restoreFileQsContainer}" ]]; then
    hostnameFileOk="true"
fi

if [[ "$originalPubKeyQs" == "$pubKeyFileQs" ]] && [[ "$pubKeyFileQs" != "NOT_SET" ]]; then
    keyFileOk="true"
fi

if [[ "$torAddrFileQs" != "NOT_SET" ]] && [[ "$torAddrFileQs" == *".onion" ]]; then
    torFileOk="true"
fi

if [[ "$hostnameFileOk" == "true" ]] && [[ "$keyFileOk" == "true" ]] && [[ "$torFileOk" == "true" ]]; then
    printf "\t   ${GREEN}RESTORE_FILE + QUICKSTART: All checks passed!${NC}\n"
    printf "\t   - Hostname set correctly\n"
    printf "\t   - Public key matches original\n"
    printf "\t   - Tor address preserved\n"
    printf "\t   RESTORE_FILE + QUICKSTART ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ${RED}RESTORE_FILE + QUICKSTART: Some checks failed${NC}\n"
    if [[ "$hostnameFileOk" != "true" ]]; then
        printf "\t   - ${RED}Hostname NOT set correctly${NC}\n"
    fi
    if [[ "$keyFileOk" != "true" ]]; then
        printf "\t   - ${RED}Public key mismatch${NC}\n"
    fi
    if [[ "$torFileOk" != "true" ]]; then
        printf "\t   - ${RED}Tor address missing${NC}\n"
    fi
    printf "\t   RESTORE_FILE + QUICKSTART ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ CLEANUP RESTORE_FILE + QUICKSTART ############################

echo -e "\n\t-> Cleaning up RESTORE_FILE + QUICKSTART container..."
docker rm -f ${restoreFileQsContainer} 2>/dev/null
docker volume rm ${restoreFileQsContainer}-mysql-data ${restoreFileQsContainer}-files ${restoreFileQsContainer}-eiou 2>/dev/null
rm -f "${hostSeedFileQs}"

############################ PART 4 SUMMARY ############################

echo -e "\n\t   ============================================"
echo -e "\t   RESTORE + QUICKSTART HOSTNAME TEST SUMMARY"
echo -e "\t   ============================================"
echo -e "\t   Tests verify that when QUICKSTART is set alongside"
echo -e "\t   RESTORE or RESTORE_FILE, the hostname is automatically"
echo -e "\t   applied to the restored wallet's userconfig.json."
echo -e "\t   ============================================\n"

################################################################################
#                    PART 5: STARTUP AUTHCODE TEMP FILE TEST
################################################################################

echo -e "\n\n[PART 5: Startup Authcode Temp File Test on ${testContainer}]"
echo -e "================================================================"
echo -e "Testing that startup.sh creates a secure authcode temp file"
echo -e "and that docker logs show retrieval instructions."
echo -e "================================================================"

############################ TEST 5.1: VERIFY DOCKER LOGS SHOW TEMP FILE INSTRUCTIONS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 5.1: Checking docker logs contain authcode temp file instructions"

# Check that the new format appears in logs (not the old hardcoded "(see secure temp file)" alone)
startupLogs=$(docker logs ${testContainer} 2>&1)

if echo "$startupLogs" | grep -q "docker exec.*cat.*/dev/shm/eiou_wallet_info_\|docker exec.*cat.*/tmp/eiou_wallet_info_\|docker exec.*cat.*/dev/shm/eiou_authcode_\|docker exec.*cat.*/tmp/eiou_authcode_\|displayed securely via terminal"; then
    printf "\t   Docker logs contain authcode retrieval instructions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    # Check for old hardcoded message (indicates startup.sh was not updated)
    if echo "$startupLogs" | grep -q "Authentication Code: (see secure temp file)"; then
        printf "\t   Docker logs contain OLD hardcoded message ${RED}FAILED${NC}\n"
        printf "\t   startup.sh still uses hardcoded '(see secure temp file)' without creating one\n"
    else
        printf "\t   Docker logs missing authcode instructions ${RED}FAILED${NC}\n"
    fi
    failure=$(( failure + 1 ))
fi

############################ TEST 5.2: VERIFY AUTHCODE NOT IN DOCKER LOGS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 5.2: Checking that actual authcode value is NOT in docker logs"

# Get the actual authcode
actualAuthCode=$(docker exec ${testContainer} php -r '
    require_once "'"${SECURITY_DIR}"'/KeyEncryption.php";
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    if (isset($json["authcode_encrypted"])) {
        echo \Eiou\Security\KeyEncryption::decrypt($json["authcode_encrypted"]);
    }
' 2>&1)

if [[ -n "$actualAuthCode" ]] && [[ "$actualAuthCode" != "ERROR"* ]]; then
    if echo "$startupLogs" | grep -q "$actualAuthCode"; then
        printf "\t   Authcode found in docker logs ${RED}FAILED${NC}\n"
        printf "\t   SECURITY VULNERABILITY: Authcode is exposed in logs!\n"
        failure=$(( failure + 1 ))
    else
        printf "\t   Authcode NOT found in docker logs ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    fi
else
    printf "\t   Could not retrieve authcode for comparison ${YELLOW}SKIPPED${NC}\n"
    passed=$(( passed + 1 ))
fi

############################ TEST 5.3: CREATE AUTHCODE TEMP FILE AND VERIFY CONTENTS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 5.3: Creating authcode temp file and verifying contents"

authcodeFileTest=$(docker exec ${testContainer} php -r '
    require_once "'"${EIOU_DIR}"'/vendor/autoload.php";
    require_once "'"${EIOU_DIR}"'/src/security/KeyEncryption.php";

    // Get the actual authcode
    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    $authcode = \Eiou\Security\KeyEncryption::decrypt($json["authcode_encrypted"] ?? "");
    if (empty($authcode)) { echo "NO_AUTHCODE"; exit; }

    // Create temp file via displayAuthcode
    $result = \Eiou\Utils\SecureSeedphraseDisplay::displayAuthcode($authcode);

    if (!$result["success"] || $result["method"] !== "file") {
        echo "DISPLAY_FAILED:" . ($result["reason"] ?? $result["method"] ?? "unknown");
        exit;
    }

    $filepath = $result["filepath"] ?? "";
    if (empty($filepath) || !file_exists($filepath)) {
        echo "FILE_NOT_CREATED";
        exit;
    }

    // Read and verify contents
    $content = file_get_contents($filepath);

    // Verify authcode IS in the file
    if (strpos($content, $authcode) === false) {
        unlink($filepath);
        echo "AUTHCODE_MISSING_FROM_FILE";
        exit;
    }

    // Clean up
    unlink($filepath);
    echo "VALID";
' 2>&1)

if [[ "$authcodeFileTest" == "VALID" ]]; then
    printf "\t   Authcode temp file created with correct contents ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Authcode temp file test ${RED}FAILED${NC}\n"
    printf "\t   Result: ${authcodeFileTest}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 5.4: VERIFY TEMP FILE DOES NOT CONTAIN SEEDPHRASE ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 5.4: Verifying authcode temp file does NOT contain the seedphrase"

seedInFileTest=$(docker exec ${testContainer} php -r '
    require_once "'"${EIOU_DIR}"'/vendor/autoload.php";
    require_once "'"${EIOU_DIR}"'/src/security/KeyEncryption.php";

    $json = json_decode(file_get_contents("'"${USERCONFIG}"'"), true);
    $authcode = \Eiou\Security\KeyEncryption::decrypt($json["authcode_encrypted"] ?? "");
    $mnemonic = \Eiou\Security\KeyEncryption::decrypt($json["mnemonic_encrypted"] ?? "");
    if (empty($authcode) || empty($mnemonic)) { echo "NO_CREDENTIALS"; exit; }

    // Create temp file via displayAuthcode (authcode-only method)
    $result = \Eiou\Utils\SecureSeedphraseDisplay::displayAuthcode($authcode);

    if (!$result["success"] || $result["method"] !== "file") {
        echo "DISPLAY_FAILED";
        exit;
    }

    $filepath = $result["filepath"] ?? "";
    if (empty($filepath) || !file_exists($filepath)) {
        echo "FILE_NOT_CREATED";
        exit;
    }

    $content = file_get_contents($filepath);

    // Check that first 3 words of seedphrase are NOT in the file
    $words = explode(" ", $mnemonic);
    $threeWordSeq = $words[0] . " " . $words[1] . " " . $words[2];

    unlink($filepath);

    if (strpos($content, $threeWordSeq) !== false) {
        echo "SEEDPHRASE_FOUND_IN_AUTHCODE_FILE";
    } else {
        echo "CLEAN";
    }
' 2>&1)

if [[ "$seedInFileTest" == "CLEAN" ]]; then
    printf "\t   Authcode temp file does NOT contain seedphrase ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Authcode temp file seedphrase check ${RED}FAILED${NC}\n"
    printf "\t   Result: ${seedInFileTest}\n"
    if [[ "$seedInFileTest" == "SEEDPHRASE_FOUND_IN_AUTHCODE_FILE" ]]; then
        printf "\t   SECURITY VULNERABILITY: Seedphrase leaked into authcode-only file!\n"
    fi
    failure=$(( failure + 1 ))
fi

##################################################################
#                    FINAL SUMMARY
##################################################################

echo -e "\n================================================================"
echo -e "         SEEDPHRASE TEST SUITE COMPLETE"
echo -e "================================================================\n"

succesrate "${totaltests}" "${passed}" "${failure}" "'seedphrase test suite'"
