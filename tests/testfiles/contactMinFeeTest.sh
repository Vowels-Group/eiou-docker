#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Contact Min Fee Amount Test ############################
# Tests per-contact minimum fee amount configuration
#
# Verifies:
# - min_fee_amount column exists in contact_currencies table
# - min_fee_amount can be set and retrieved per contact/currency
# - min_fee_amount defaults to NULL when not specified
# - min_fee_amount can be updated via updateCurrencyConfig
# - getMinFeeAmount returns correct value or null
#
# Prerequisites:
# - Containers must be running
# - Contacts must be established (run addContactsTest first)
#########################################################################

echo -e "\nTesting per-contact minimum fee amount configuration..."

testname="contactMinFeeTest"
totaltests=0
passed=0
failure=0

# Use the first container for all tests
firstContainer="${containers[0]}"

# Get the first contact's pubkey hash for testing
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
firstLinkKey="${containersLinkKeys[0]}"
firstKeys=(${firstLinkKey//,/ })

# Test 1: Verify min_fee_amount column exists in contact_currencies
echo -e "\n[Test 1: min_fee_amount column exists]"
totaltests=$(( totaltests + 1 ))

columnExists=$(docker exec ${firstContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getDatabaseManager()->getConnection();
    \$stmt = \$pdo->query('DESCRIBE contact_currencies min_fee_amount');
    \$result = \$stmt->fetch(PDO::FETCH_ASSOC);
    echo \$result ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$columnExists" == "EXISTS" ]]; then
    printf "\t   min_fee_amount column exists ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   min_fee_amount column exists ${RED}FAILED${NC} (result: %s)\n" "$columnExists"
    failure=$(( failure + 1 ))
fi

# Test 2: Verify existing contacts have NULL min_fee_amount by default
echo -e "\n[Test 2: Default min_fee_amount is NULL]"
totaltests=$(( totaltests + 1 ))

defaultValue=$(docker exec ${firstKeys[0]} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$contactRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class);
    \$contact = \$contactRepo->lookupByAddress('${MODE}', '${containerAddresses[${firstKeys[1]}]}');
    if (\$contact) {
        \$pubkeyHash = hash(\Eiou\Core\Constants::HASH_ALGORITHM, \$contact['pubkey']);
        \$currencyRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);
        \$minFee = \$currencyRepo->getMinFeeAmount(\$pubkeyHash, 'USD');
        echo \$minFee === null ? 'NULL' : \$minFee;
    } else {
        echo 'CONTACT_NOT_FOUND';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$defaultValue" == "NULL" ]]; then
    printf "\t   Default min_fee_amount is NULL ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [[ "$defaultValue" == "CONTACT_NOT_FOUND" ]]; then
    printf "\t   Default min_fee_amount test ${RED}FAILED${NC} (contact not found)\n"
    failure=$(( failure + 1 ))
else
    printf "\t   Default min_fee_amount test ${RED}FAILED${NC} (expected NULL, got: %s)\n" "$defaultValue"
    failure=$(( failure + 1 ))
fi

# Test 3: Set min_fee_amount and verify it can be retrieved
echo -e "\n[Test 3: Set and retrieve min_fee_amount]"
totaltests=$(( totaltests + 1 ))

setAndGet=$(docker exec ${firstKeys[0]} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$contactRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class);
    \$contact = \$contactRepo->lookupByAddress('${MODE}', '${containerAddresses[${firstKeys[1]}]}');
    if (\$contact) {
        \$pubkeyHash = hash(\Eiou\Core\Constants::HASH_ALGORITHM, \$contact['pubkey']);
        \$currencyRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);
        // Set min_fee_amount to 500 (minor units)
        \$updated = \$currencyRepo->updateCurrencyConfig(\$pubkeyHash, 'USD', ['min_fee_amount' => 500]);
        // Retrieve it
        \$minFee = \$currencyRepo->getMinFeeAmount(\$pubkeyHash, 'USD');
        echo \$updated && \$minFee === 500 ? 'PASS' : 'FAIL:updated=' . var_export(\$updated, true) . ',minFee=' . var_export(\$minFee, true);
    } else {
        echo 'CONTACT_NOT_FOUND';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$setAndGet" == "PASS" ]]; then
    printf "\t   Set and retrieve min_fee_amount ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Set and retrieve min_fee_amount ${RED}FAILED${NC} (result: %s)\n" "$setAndGet"
    failure=$(( failure + 1 ))
fi

# Test 4: Update min_fee_amount to a different value
echo -e "\n[Test 4: Update min_fee_amount]"
totaltests=$(( totaltests + 1 ))

updateResult=$(docker exec ${firstKeys[0]} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$contactRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class);
    \$contact = \$contactRepo->lookupByAddress('${MODE}', '${containerAddresses[${firstKeys[1]}]}');
    if (\$contact) {
        \$pubkeyHash = hash(\Eiou\Core\Constants::HASH_ALGORITHM, \$contact['pubkey']);
        \$currencyRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);
        // Update to 1000
        \$currencyRepo->updateCurrencyConfig(\$pubkeyHash, 'USD', ['min_fee_amount' => 1000]);
        \$minFee = \$currencyRepo->getMinFeeAmount(\$pubkeyHash, 'USD');
        echo \$minFee === 1000 ? 'PASS' : 'FAIL:minFee=' . var_export(\$minFee, true);
    } else {
        echo 'CONTACT_NOT_FOUND';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$updateResult" == "PASS" ]]; then
    printf "\t   Update min_fee_amount ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Update min_fee_amount ${RED}FAILED${NC} (result: %s)\n" "$updateResult"
    failure=$(( failure + 1 ))
fi

# Test 5: Clear min_fee_amount (set to NULL)
echo -e "\n[Test 5: Clear min_fee_amount to NULL]"
totaltests=$(( totaltests + 1 ))

clearResult=$(docker exec ${firstKeys[0]} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$contactRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class);
    \$contact = \$contactRepo->lookupByAddress('${MODE}', '${containerAddresses[${firstKeys[1]}]}');
    if (\$contact) {
        \$pubkeyHash = hash(\Eiou\Core\Constants::HASH_ALGORITHM, \$contact['pubkey']);
        \$currencyRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);
        // Set to NULL
        \$currencyRepo->updateCurrencyConfig(\$pubkeyHash, 'USD', ['min_fee_amount' => null]);
        \$minFee = \$currencyRepo->getMinFeeAmount(\$pubkeyHash, 'USD');
        echo \$minFee === null ? 'PASS' : 'FAIL:minFee=' . var_export(\$minFee, true);
    } else {
        echo 'CONTACT_NOT_FOUND';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$clearResult" == "PASS" ]]; then
    printf "\t   Clear min_fee_amount to NULL ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Clear min_fee_amount to NULL ${RED}FAILED${NC} (result: %s)\n" "$clearResult"
    failure=$(( failure + 1 ))
fi

# Test 6: Verify min_fee_amount appears in getContactCurrencies output
echo -e "\n[Test 6: min_fee_amount in getContactCurrencies]"
totaltests=$(( totaltests + 1 ))

currenciesResult=$(docker exec ${firstKeys[0]} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$contactRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class);
    \$contact = \$contactRepo->lookupByAddress('${MODE}', '${containerAddresses[${firstKeys[1]}]}');
    if (\$contact) {
        \$pubkeyHash = hash(\Eiou\Core\Constants::HASH_ALGORITHM, \$contact['pubkey']);
        \$currencyRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);
        // Set a known value first
        \$currencyRepo->updateCurrencyConfig(\$pubkeyHash, 'USD', ['min_fee_amount' => 250]);
        \$currencies = \$currencyRepo->getContactCurrencies(\$pubkeyHash);
        \$found = false;
        foreach (\$currencies as \$c) {
            if (\$c['currency'] === 'USD' && array_key_exists('min_fee_amount', \$c) && (int)\$c['min_fee_amount'] === 250) {
                \$found = true;
                break;
            }
        }
        // Clean up - set back to null
        \$currencyRepo->updateCurrencyConfig(\$pubkeyHash, 'USD', ['min_fee_amount' => null]);
        echo \$found ? 'PASS' : 'FAIL:' . json_encode(\$currencies);
    } else {
        echo 'CONTACT_NOT_FOUND';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$currenciesResult" == "PASS" ]]; then
    printf "\t   min_fee_amount in getContactCurrencies ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   min_fee_amount in getContactCurrencies ${RED}FAILED${NC} (result: %s)\n" "$currenciesResult"
    failure=$(( failure + 1 ))
fi

# Test 7: Verify getCurrencyConfig includes min_fee_amount
echo -e "\n[Test 7: min_fee_amount in getCurrencyConfig]"
totaltests=$(( totaltests + 1 ))

configResult=$(docker exec ${firstKeys[0]} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$contactRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class);
    \$contact = \$contactRepo->lookupByAddress('${MODE}', '${containerAddresses[${firstKeys[1]}]}');
    if (\$contact) {
        \$pubkeyHash = hash(\Eiou\Core\Constants::HASH_ALGORITHM, \$contact['pubkey']);
        \$currencyRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);
        \$config = \$currencyRepo->getCurrencyConfig(\$pubkeyHash, 'USD');
        echo \$config !== null && array_key_exists('min_fee_amount', \$config) ? 'PASS' : 'FAIL:' . json_encode(\$config);
    } else {
        echo 'CONTACT_NOT_FOUND';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$configResult" == "PASS" ]]; then
    printf "\t   min_fee_amount in getCurrencyConfig ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   min_fee_amount in getCurrencyConfig ${RED}FAILED${NC} (result: %s)\n" "$configResult"
    failure=$(( failure + 1 ))
fi

succesrate "${totaltests}" "${passed}" "${failure}" "'contact min fee'"

##################################################################
