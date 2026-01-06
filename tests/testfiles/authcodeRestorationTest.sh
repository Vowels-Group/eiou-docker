#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

# Test authcode restoration from seedphrase
# Verifies that restoring a wallet from the same seedphrase yields the same authcode
#
# NOTE: All paths use double slashes (//etc/eiou/) to prevent Git Bash on Windows
# from converting /etc/ to C:/Program Files/Git/etc/. This is safe on Linux too.

echo -e "\nTesting authcode restoration from seedphrase..."

testname="authcodeRestorationTest"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

echo -e "\n[Authcode Restoration Test on ${testContainer}]"

############################ STORE ORIGINAL AUTHCODE ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 1: Storing original authcode from userconfig.json"

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
    # Cannot continue without original authcode
    succesrate "${totaltests}" "${passed}" "${failure}" "'authcode restoration'"
    return 1
fi

############################ STORE ORIGINAL PUBLIC KEY ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 2: Storing original public key for verification"

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
    passed=$(( passed + 1 ))
else
    printf "\t   Original public key retrieval ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ DECRYPT MNEMONIC ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 3: Decrypting seed phrase from encrypted mnemonic"

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
    failure=$(( failure + 1 ))
    succesrate "${totaltests}" "${passed}" "${failure}" "'authcode restoration'"
    return 1
fi

############################ DELETE USERCONFIG ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 4: Deleting userconfig.json to simulate fresh wallet"

deleteResult=$(docker exec ${testContainer} rm -f ${USERCONFIG} 2>&1)
verifyDeleted=$(docker exec ${testContainer} test -f ${USERCONFIG} && echo "EXISTS" || echo "DELETED")

# Also delete Tor hidden service files for full restore test
deleteTorResult=$(docker exec ${testContainer} rm -f ${TOR_SECRET_KEY} ${TOR_PUBLIC_KEY} ${TOR_HOSTNAME} 2>&1)

if [[ "$verifyDeleted" == "DELETED" ]]; then
    printf "\t   userconfig.json deleted ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   File deletion ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ RESTORE FROM SEED PHRASE ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 5: Restoring wallet with 'eiou generate restore <seed_phrase>'"

restoreOutput=$(docker exec ${testContainer} eiou generate restore ${seedPhrase} 2>&1)
sleep 1

verifyRestored=$(docker exec ${testContainer} test -f ${USERCONFIG} && echo "CREATED" || echo "NOT_CREATED")

if [[ "$verifyRestored" == "CREATED" ]]; then
    printf "\t   Wallet restored from seed phrase ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Wallet restoration ${RED}FAILED${NC}\n"
    printf "\t   Restore output: ${restoreOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ GET RESTORED AUTHCODE ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 6: Retrieving restored authcode"

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

############################ GET RESTORED PUBLIC KEY ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 7: Retrieving restored public key for verification"

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
    passed=$(( passed + 1 ))
else
    printf "\t   Restored public key retrieval ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ VERIFY PUBLIC KEY MATCHES ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 8: Verifying public key matches (deterministic derivation working)"

if [[ "$originalPublicKey" == "$restoredPublicKey" ]]; then
    printf "\t   Public key matches - deterministic derivation confirmed ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Public key mismatch - deterministic derivation broken ${RED}FAILED${NC}\n"
    printf "\t   Original: ${originalPublicKey}\n"
    printf "\t   Restored: ${restoredPublicKey}\n"
    failure=$(( failure + 1 ))
fi

############################ COMPARE AUTHCODES ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Step 9: Comparing original and restored authcodes"

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
echo -e "\n\t-> Step 10: Testing consistency across multiple restore iterations"

# Store current authcode
iteration1AuthCode="$restoredAuthCode"

# Delete and restore again
docker exec ${testContainer} rm -f ${USERCONFIG} ${TOR_SECRET_KEY} ${TOR_PUBLIC_KEY} ${TOR_HOSTNAME} 2>&1
docker exec ${testContainer} eiou generate restore ${seedPhrase} 2>&1
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
docker exec ${testContainer} eiou generate restore ${seedPhrase} 2>&1
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
echo -e "\n\t-> Step 11: Testing authcode restoration in new container"

# Create a completely new Container
authcodeRestoreContainer="httpAuthcodeRestoreTest"
authcodeContainerHash=$(docker run -d --network="${network}" --name "${authcodeRestoreContainer}" -v "${authcodeRestoreContainer}-mysql-data:/var/lib/mysql" -v "${authcodeRestoreContainer}-files:/etc/eiou/" -v "${authcodeRestoreContainer}-index:/var/www/html" -v "${authcodeRestoreContainer}-eiou:/usr/local/bin/" -e RESTORE="${seedPhrase}" eioud 2>&1)

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

############################ SUMMARY ############################

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

succesrate "${totaltests}" "${passed}" "${failure}" "'authcode restoration'"
