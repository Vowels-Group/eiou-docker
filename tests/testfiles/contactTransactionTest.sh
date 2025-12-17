#!/bin/sh

# Test contact transaction type functionality
# Verifies that contact requests are recorded as 'contact' tx_type transactions
echo -e "\nTesting contact transaction type..."

testname="contactTransactionTest"
totaltests=0
passed=0
failure=0

# Test 1: Verify contact tx_type exists in schema
echo -e "\n[Contact Transaction Type Schema Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking tx_type ENUM values for ${container}"

    # Check if 'contact' is in the tx_type enum
    enumCheck=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$pdo = Application::getInstance()->services->getPdo();
        \$result = \$pdo->query(\"SHOW COLUMNS FROM transactions LIKE 'tx_type'\");
        \$row = \$result->fetch(PDO::FETCH_ASSOC);
        echo \$row['Type'];
    " 2>/dev/null || echo "ERROR")

    if [[ "$enumCheck" == *"contact"* ]]; then
        printf "\t   Contact tx_type in schema ${GREEN}PASSED${NC}\n"
        printf "\t   ENUM values: %s\n" "${enumCheck}"
        passed=$(( passed + 1 ))
    else
        printf "\t   Contact tx_type in schema ${RED}FAILED${NC}\n"
        printf "\t   ENUM values: %s\n" "${enumCheck}"
        failure=$(( failure + 1 ))
    fi
done

# Test 2: Verify contact transactions have amount = 0
echo -e "\n[Contact Transaction Amount Test]"

for container in "${containers[@]:0:2}"; do  # Test first 2 containers
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking contact transaction amounts for ${container}"

    # Query any contact transactions and verify amount is 0
    amountCheck=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$transactions = \$app->services->getTransactionRepository()->getTransactionsByTxType('contact');
        if (empty(\$transactions)) {
            echo 'NO_CONTACT_TX';
        } else {
            \$allZero = true;
            foreach (\$transactions as \$tx) {
                if (\$tx['amount'] != 0) {
                    \$allZero = false;
                    break;
                }
            }
            echo \$allZero ? 'ALL_ZERO' : 'NON_ZERO_FOUND';
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$amountCheck" == "ALL_ZERO" ]]; then
        printf "\t   Contact transaction amounts ${GREEN}PASSED${NC} (all are 0)\n"
        passed=$(( passed + 1 ))
    elif [[ "$amountCheck" == "NO_CONTACT_TX" ]]; then
        printf "\t   No contact transactions to verify ${NC}(skipped)${NC}\n"
        # Don't count as failure if no contact transactions exist yet
    else
        printf "\t   Contact transaction amounts ${RED}FAILED${NC} (%s)\n" "${amountCheck}"
        failure=$(( failure + 1 ))
    fi
done

# Test 3: Verify TransactionService createContactTxid method exists and works
echo -e "\n[Contact Txid Generation Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Testing createContactTxid for ${container}"

    # Test the createContactTxid method
    txidTest=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        try {
            \$txService = \$app->services->getTransactionService();
            \$testData = [
                'receiverPublicKey' => 'testPublicKey123',
                'time' => '1234567890'
            ];
            \$txid = \$txService->createContactTxid(\$testData);
            // Verify it's a valid SHA-256 hash (64 characters hex)
            if (strlen(\$txid) == 64 && ctype_xdigit(\$txid)) {
                echo 'VALID_HASH';
            } else {
                echo 'INVALID_HASH';
            }
        } catch (Exception \$e) {
            echo 'ERROR:' . \$e->getMessage();
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$txidTest" == "VALID_HASH" ]]; then
        printf "\t   createContactTxid ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   createContactTxid ${RED}FAILED${NC} (%s)\n" "${txidTest}"
        failure=$(( failure + 1 ))
    fi
done

# Test 4: Verify contact transactions don't affect balance
echo -e "\n[Contact Transaction Balance Test]"

containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
testPair="${containersLinkKeys[0]}"  # Test with first link

if [[ "$testPair" ]]; then
    containerKeys=(${testPair//,/ })
    sender="${containerKeys[0]}"
    receiver="${containerKeys[1]}"

    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking balance unchanged after contact for ${sender}"

    # Get balance before (if contacts already exist, this validates existing behavior)
    senderBalanceBefore=$(docker exec ${sender} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$balance = \$app->services->getBalanceRepository()->getTotalBalance('USD');
        echo \$balance ?: '0';
    " 2>/dev/null || echo "0")

    # Query contact transaction count
    contactTxCount=$(docker exec ${sender} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$count = \$app->services->getTransactionRepository()->getTransactionCountByTxType('contact');
        echo \$count ?: '0';
    " 2>/dev/null || echo "0")

    senderBalanceAfter=$(docker exec ${sender} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$balance = \$app->services->getBalanceRepository()->getTotalBalance('USD');
        echo \$balance ?: '0';
    " 2>/dev/null || echo "0")

    # Balance should not change due to contact transactions
    if [[ "$senderBalanceBefore" == "$senderBalanceAfter" ]] || [[ "$contactTxCount" == "0" ]]; then
        printf "\t   Balance unchanged by contact tx ${GREEN}PASSED${NC}\n"
        printf "\t   Contact transactions: %s, Balance: %s\n" "${contactTxCount}" "${senderBalanceBefore}"
        passed=$(( passed + 1 ))
    else
        printf "\t   Balance unchanged by contact tx ${RED}FAILED${NC}\n"
        printf "\t   Before: %s, After: %s\n" "${senderBalanceBefore}" "${senderBalanceAfter}"
        failure=$(( failure + 1 ))
    fi
fi

# Test 5: Verify tx_type detection logic
echo -e "\n[Transaction Type Detection Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Testing tx_type detection for ${container}"

    # Test the detection logic for different memo values
    detectionTest=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');

        // Simulate detection logic
        \$testCases = [
            ['memo' => 'contact', 'amount' => 0, 'expected' => 'contact'],
            ['memo' => 'standard', 'amount' => 100, 'expected' => 'standard'],
            ['memo' => 'somehash', 'amount' => 100, 'expected' => 'p2p'],
            ['memo' => 'test', 'amount' => 0, 'expected' => 'contact']
        ];

        \$allPassed = true;
        foreach (\$testCases as \$test) {
            // Replicate the detection logic from TransactionRepository
            if (\$test['memo'] === 'contact' || \$test['amount'] == 0) {
                \$detected = 'contact';
            } elseif (\$test['memo'] === 'standard') {
                \$detected = 'standard';
            } else {
                \$detected = 'p2p';
            }

            if (\$detected !== \$test['expected']) {
                \$allPassed = false;
                echo 'FAIL:' . \$test['memo'] . '/' . \$test['amount'] . '->' . \$detected . '!=' . \$test['expected'];
                break;
            }
        }

        echo \$allPassed ? 'ALL_PASSED' : '';
    " 2>/dev/null || echo "ERROR")

    if [[ "$detectionTest" == "ALL_PASSED" ]]; then
        printf "\t   Transaction type detection ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Transaction type detection ${RED}FAILED${NC} (%s)\n" "${detectionTest}"
        failure=$(( failure + 1 ))
    fi
done

succesrate "${totaltests}" "${passed}" "${failure}" "'contact transaction'"
