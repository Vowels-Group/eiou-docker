#!/bin/sh

# Test seed phrase generate and restore functionality
# Verifies that restoring from a seed phrase yields the same keys as before
# PR #198 - Seed phrase deterministic derivation test

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
    $json = json_decode(file_get_contents("/etc/eiou/userconfig.json"), true);
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
    require_once "/etc/eiou/src/security/KeyEncryption.php";
    $json = json_decode(file_get_contents("/etc/eiou/userconfig.json"), true);
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

# Backup the master key since it's needed for encryption operations
masterKeyBackup=$(docker exec ${testContainer} cat /etc/eiou/.master.key 2>/dev/null | base64)

if [[ -n "$masterKeyBackup" ]]; then
    printf "\t   Master key backed up ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Master key backup ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ DELETE USERCONFIG ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 4: Deleting userconfig.json to simulate fresh wallet"

deleteResult=$(docker exec ${testContainer} rm -f /etc/eiou/userconfig.json 2>&1)
verifyDeleted=$(docker exec ${testContainer} test -f /etc/eiou/userconfig.json && echo "EXISTS" || echo "DELETED")

if [[ "$verifyDeleted" == "DELETED" ]]; then
    printf "\t   userconfig.json deleted ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   userconfig.json deletion ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ RESTORE FROM SEED PHRASE ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 5: Restoring wallet with 'eiou generate restore <seed_phrase>'"

# Run the restore command with the seed phrase
restoreOutput=$(docker exec ${testContainer} eiou generate restore ${seedPhrase} 2>&1)

# Check if restore was successful by verifying userconfig.json was created
verifyRestored=$(docker exec ${testContainer} test -f /etc/eiou/userconfig.json && echo "CREATED" || echo "NOT_CREATED")

if [[ "$verifyRestored" == "CREATED" ]]; then
    printf "\t   Wallet restored from seed phrase ${GREEN}PASSED${NC}\n"
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
    $json = json_decode(file_get_contents("/etc/eiou/userconfig.json"), true);
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

############################ COMPARE PUBLIC KEYS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 7: Comparing original and restored public keys"

echo -e "\n\t   ============================================"
echo -e "\t   KEY COMPARISON RESULTS"
echo -e "\t   ============================================"
echo -e "\t   BEFORE: ${originalPublicKey}"
echo -e "\t   AFTER:  ${restoredPublicKey}"
echo -e "\t   ============================================\n"

if [[ "$originalPublicKey" == "$restoredPublicKey" ]]; then
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
echo -e "\n\t-> Step 8: Verifying wallet configuration is intact"

# Check that the restored wallet has required fields
configCheck=$(docker exec ${testContainer} php -r '
    $json = json_decode(file_get_contents("/etc/eiou/userconfig.json"), true);
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
