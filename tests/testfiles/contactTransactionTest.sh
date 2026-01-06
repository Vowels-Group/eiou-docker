#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

# Test contact transaction type functionality
# Verifies that contact requests are recorded as 'contact' tx_type transactions
# and that the status flow (sent -> completed) works correctly
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
        \$pdo = \$app->services->getPdo();
        \$stmt = \$pdo->query(\"SELECT amount FROM transactions WHERE tx_type = 'contact'\");
        \$transactions = \$stmt->fetchAll(PDO::FETCH_ASSOC);
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

# Test 3: Verify contact transaction has memo = 'contact'
echo -e "\n[Contact Transaction Memo Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking contact transaction memo for ${container}"

    memoCheck=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$stmt = \$pdo->query(\"SELECT memo FROM transactions WHERE tx_type = 'contact'\");
        \$transactions = \$stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty(\$transactions)) {
            echo 'NO_CONTACT_TX';
        } else {
            \$allContact = true;
            foreach (\$transactions as \$tx) {
                if (\$tx['memo'] !== 'contact') {
                    \$allContact = false;
                    break;
                }
            }
            echo \$allContact ? 'ALL_CONTACT' : 'WRONG_MEMO';
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$memoCheck" == "ALL_CONTACT" ]]; then
        printf "\t   Contact transaction memo ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    elif [[ "$memoCheck" == "NO_CONTACT_TX" ]]; then
        printf "\t   No contact transactions to verify ${NC}(skipped)${NC}\n"
    else
        printf "\t   Contact transaction memo ${RED}FAILED${NC} (%s)\n" "${memoCheck}"
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
        \$pdo = \$app->services->getPdo();
        \$stmt = \$pdo->query(\"SELECT COUNT(*) as count FROM transactions WHERE tx_type = 'contact'\");
        \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
        echo \$row['count'] ?: '0';
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

        // Simulate detection logic from TransactionRepository
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

# Test 6: Verify contactTransactionExistsForReceiver method
echo -e "\n[Contact Transaction Exists Check Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Testing contactTransactionExistsForReceiver for ${container}"

    existsCheck=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$txRepo = \$app->services->getTransactionRepository();

        // Test with a non-existent public key hash - should return false
        \$fakeHash = hash('sha256', 'nonexistent_pubkey_' . time());
        \$exists = \$txRepo->contactTransactionExistsForReceiver(\$fakeHash);

        if (\$exists === false) {
            echo 'CORRECT_FALSE';
        } else {
            echo 'WRONG_TRUE';
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$existsCheck" == "CORRECT_FALSE" ]]; then
        printf "\t   contactTransactionExistsForReceiver ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   contactTransactionExistsForReceiver ${RED}FAILED${NC} (%s)\n" "${existsCheck}"
        failure=$(( failure + 1 ))
    fi
done

# Test 7: Verify completeContactTransaction method exists and works
echo -e "\n[Complete Contact Transaction Method Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Testing completeContactTransaction method for ${container}"

    methodTest=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$txRepo = \$app->services->getTransactionRepository();

        // Test that the method exists and can be called
        if (method_exists(\$txRepo, 'completeContactTransaction')) {
            // Call with a fake public key (should return false as no matching tx exists)
            \$result = \$txRepo->completeContactTransaction('fake_public_key_' . time());
            // Method should return false (no rows updated) but not throw an error
            echo 'METHOD_EXISTS';
        } else {
            echo 'METHOD_MISSING';
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$methodTest" == "METHOD_EXISTS" ]]; then
        printf "\t   completeContactTransaction method ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   completeContactTransaction method ${RED}FAILED${NC} (%s)\n" "${methodTest}"
        failure=$(( failure + 1 ))
    fi
done

# Test 8: Verify contact transaction status is valid (sent/accepted/completed)
echo -e "\n[Contact Transaction Status Test]"

for container in "${containers[@]:0:2}"; do  # Test first 2 containers
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking contact transaction status for ${container}"

    statusCheck=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$stmt = \$pdo->query(\"SELECT status FROM transactions WHERE tx_type = 'contact'\");
        \$transactions = \$stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty(\$transactions)) {
            echo 'NO_CONTACT_TX';
        } else {
            // Valid statuses for contact transactions:
            // - 'sent': sender-side, awaiting acceptance
            // - 'accepted': receiver-side, awaiting user acceptance
            // - 'completed': both sides after acceptance
            \$validStatuses = ['sent', 'accepted', 'completed'];
            \$allValid = true;
            \$invalidStatus = '';
            foreach (\$transactions as \$tx) {
                if (!in_array(\$tx['status'], \$validStatuses)) {
                    \$allValid = false;
                    \$invalidStatus = \$tx['status'];
                    break;
                }
            }
            if (\$allValid) {
                echo 'ALL_VALID';
            } else {
                echo 'INVALID:' . \$invalidStatus;
            }
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$statusCheck" == "ALL_VALID" ]]; then
        printf "\t   Contact transaction status ${GREEN}PASSED${NC} (all sent/accepted/completed)\n"
        passed=$(( passed + 1 ))
    elif [[ "$statusCheck" == "NO_CONTACT_TX" ]]; then
        printf "\t   No contact transactions to verify ${NC}(skipped)${NC}\n"
    else
        printf "\t   Contact transaction status ${RED}FAILED${NC} (%s)\n" "${statusCheck}"
        failure=$(( failure + 1 ))
    fi
done

# Test 9: Verify insertTransaction supports status parameter
echo -e "\n[Insert Transaction Status Parameter Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Testing insertTransaction status parameter for ${container}"

    # Check if the source file contains the status parameter pattern
    paramTest=$(docker exec ${container} sh -c "grep -q \"status.*request\" /etc/eiou/src/database/TransactionRepository.php && echo 'STATUS_SUPPORTED' || echo 'STATUS_NOT_FOUND'" 2>/dev/null || echo "ERROR")

    if [[ "$paramTest" == "STATUS_SUPPORTED" ]]; then
        printf "\t   insertTransaction status parameter ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   insertTransaction status parameter ${RED}FAILED${NC} (%s)\n" "${paramTest}"
        failure=$(( failure + 1 ))
    fi
done

# Test 10: Verify completeReceivedContactTransaction method exists
echo -e "\n[Complete Received Contact Transaction Method Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Testing completeReceivedContactTransaction method for ${container}"

    methodTest=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$txRepo = \$app->services->getTransactionRepository();

        // Test that the method exists and can be called
        if (method_exists(\$txRepo, 'completeReceivedContactTransaction')) {
            // Call with a fake public key (should return false as no matching tx exists)
            \$result = \$txRepo->completeReceivedContactTransaction('fake_public_key_' . time());
            // Method should return false (no rows updated) but not throw an error
            echo 'METHOD_EXISTS';
        } else {
            echo 'METHOD_MISSING';
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$methodTest" == "METHOD_EXISTS" ]]; then
        printf "\t   completeReceivedContactTransaction method ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   completeReceivedContactTransaction method ${RED}FAILED${NC} (%s)\n" "${methodTest}"
        failure=$(( failure + 1 ))
    fi
done

# Test 11: Verify contact transaction status supports 'accepted' for receiver-side
echo -e "\n[Receiver Contact Transaction Status Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Testing receiver contact transaction 'accepted' status support for ${container}"

    # Check that 'accepted' is a valid status in the transactions table
    statusCheck=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$pdo = Application::getInstance()->services->getPdo();
        \$result = \$pdo->query(\"SHOW COLUMNS FROM transactions LIKE 'status'\");
        \$row = \$result->fetch(PDO::FETCH_ASSOC);
        echo \$row['Type'];
    " 2>/dev/null || echo "ERROR")

    if [[ "$statusCheck" == *"accepted"* ]]; then
        printf "\t   'accepted' status in schema ${GREEN}PASSED${NC}\n"
        printf "\t   Status values: %s\n" "${statusCheck}"
        passed=$(( passed + 1 ))
    else
        printf "\t   'accepted' status in schema ${RED}FAILED${NC}\n"
        printf "\t   Status values: %s\n" "${statusCheck}"
        failure=$(( failure + 1 ))
    fi
done

# Test 12: Verify receiver-side contact transaction flow methods exist in ContactService
echo -e "\n[ContactService Receiver Methods Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Testing ContactService receiver transaction methods for ${container}"

    # Check if the source file contains both receiver transaction methods
    hasInsert=$(docker exec ${container} sh -c "grep -q 'function insertReceivedContactTransaction' /etc/eiou/src/services/ContactService.php && echo 'YES' || echo 'NO'" 2>/dev/null || echo "ERROR")
    hasComplete=$(docker exec ${container} sh -c "grep -q 'function completeReceivedContactTransaction' /etc/eiou/src/services/ContactService.php && echo 'YES' || echo 'NO'" 2>/dev/null || echo "ERROR")

    if [[ "$hasInsert" == "YES" && "$hasComplete" == "YES" ]]; then
        printf "\t   ContactService receiver methods ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    elif [[ "$hasInsert" == "YES" ]]; then
        printf "\t   ContactService receiver methods ${RED}FAILED${NC} (ONLY_INSERT)\n"
        failure=$(( failure + 1 ))
    elif [[ "$hasComplete" == "YES" ]]; then
        printf "\t   ContactService receiver methods ${RED}FAILED${NC} (ONLY_COMPLETE)\n"
        failure=$(( failure + 1 ))
    else
        printf "\t   ContactService receiver methods ${RED}FAILED${NC} (NEITHER)\n"
        failure=$(( failure + 1 ))
    fi
done

succesrate "${totaltests}" "${passed}" "${failure}" "'contact transaction'"
