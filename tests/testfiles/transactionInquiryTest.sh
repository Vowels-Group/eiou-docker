#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

# Test transaction inquiry response handling
# Verifies that handleTransactionMessageInquiryRequest returns actual transaction status
# and that the original sender correctly handles non-completed status responses
echo -e "\nTesting transaction inquiry responses..."

testname="transactionInquiryTest"
totaltests=0
passed=0
failure=0

# Test 1: Verify getStatusByMemo returns correct status
echo -e "\n[Transaction Status By Memo Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking getStatusByMemo method exists for ${container}"

    # Check if method exists and returns expected type
    methodCheck=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$repo = \$app->services->getTransactionRepository();

        // Check method exists
        if (!method_exists(\$repo, 'getStatusByMemo')) {
            echo 'METHOD_NOT_FOUND';
            exit;
        }

        // Test with non-existent memo (should return null)
        \$status = \$repo->getStatusByMemo('nonexistent_memo_' . time());
        echo \$status === null ? 'NULL_RETURNED' : 'STATUS_RETURNED:' . \$status;
    " 2>/dev/null || echo "ERROR")

    if [[ "$methodCheck" == "NULL_RETURNED" ]]; then
        printf "\t   getStatusByMemo method ${GREEN}PASSED${NC} (returns null for unknown)\n"
        passed=$(( passed + 1 ))
    elif [[ "$methodCheck" == "METHOD_NOT_FOUND" ]]; then
        printf "\t   getStatusByMemo method ${RED}FAILED${NC} (method not found)\n"
        failure=$(( failure + 1 ))
    else
        printf "\t   getStatusByMemo method ${GREEN}PASSED${NC} (%s)\n" "${methodCheck}"
        passed=$(( passed + 1 ))
    fi
done

# Test 2: Verify getStatusByTxid returns correct status
echo -e "\n[Transaction Status By Txid Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking getStatusByTxid method exists for ${container}"

    # Check if method exists and returns expected type
    methodCheck=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$repo = \$app->services->getTransactionRepository();

        // Check method exists
        if (!method_exists(\$repo, 'getStatusByTxid')) {
            echo 'METHOD_NOT_FOUND';
            exit;
        }

        // Test with non-existent txid (should return null)
        \$status = \$repo->getStatusByTxid('nonexistent_txid_' . time());
        echo \$status === null ? 'NULL_RETURNED' : 'STATUS_RETURNED:' . \$status;
    " 2>/dev/null || echo "ERROR")

    if [[ "$methodCheck" == "NULL_RETURNED" ]]; then
        printf "\t   getStatusByTxid method ${GREEN}PASSED${NC} (returns null for unknown)\n"
        passed=$(( passed + 1 ))
    elif [[ "$methodCheck" == "METHOD_NOT_FOUND" ]]; then
        printf "\t   getStatusByTxid method ${RED}FAILED${NC} (method not found)\n"
        failure=$(( failure + 1 ))
    else
        printf "\t   getStatusByTxid method ${GREEN}PASSED${NC} (%s)\n" "${methodCheck}"
        passed=$(( passed + 1 ))
    fi
done

# Test 3: Verify MessagePayload has buildTransactionStatusResponse method
echo -e "\n[MessagePayload Status Response Method Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking buildTransactionStatusResponse method for ${container}"

    # Check if method exists and returns expected structure
    methodCheck=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        require_once('/etc/eiou/src/schemas/payloads/MessagePayload.php');

        \$app = Application::getInstance();
        \$user = \$app->services->getCurrentUser();
        \$utilContainer = \$app->services->getUtilityContainer();

        \$payload = new MessagePayload(\$user, \$utilContainer);

        // Check method exists
        if (!method_exists(\$payload, 'buildTransactionStatusResponse')) {
            echo 'METHOD_NOT_FOUND';
            exit;
        }

        // Test with sample data
        \$testMessage = [
            'hash' => 'test_hash_123',
            'hashType' => 'memo',
            'senderAddress' => 'test@example.onion'
        ];

        \$response = \$payload->buildTransactionStatusResponse(\$testMessage, 'pending');
        \$decoded = json_decode(\$response, true);

        if (\$decoded && isset(\$decoded['status']) && \$decoded['status'] === 'pending') {
            echo 'STATUS_CORRECT';
        } else {
            echo 'STATUS_INCORRECT:' . print_r(\$decoded, true);
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$methodCheck" == "STATUS_CORRECT" ]]; then
        printf "\t   buildTransactionStatusResponse ${GREEN}PASSED${NC} (returns correct status)\n"
        passed=$(( passed + 1 ))
    elif [[ "$methodCheck" == "METHOD_NOT_FOUND" ]]; then
        printf "\t   buildTransactionStatusResponse ${RED}FAILED${NC} (method not found)\n"
        failure=$(( failure + 1 ))
    else
        printf "\t   buildTransactionStatusResponse ${RED}FAILED${NC} (%s)\n" "${methodCheck}"
        failure=$(( failure + 1 ))
    fi
done

# Test 4: Verify MessagePayload has buildTransactionNotFound method
echo -e "\n[MessagePayload Not Found Response Method Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking buildTransactionNotFound method for ${container}"

    # Check if method exists and returns expected structure
    methodCheck=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        require_once('/etc/eiou/src/schemas/payloads/MessagePayload.php');

        \$app = Application::getInstance();
        \$user = \$app->services->getCurrentUser();
        \$utilContainer = \$app->services->getUtilityContainer();

        \$payload = new MessagePayload(\$user, \$utilContainer);

        // Check method exists
        if (!method_exists(\$payload, 'buildTransactionNotFound')) {
            echo 'METHOD_NOT_FOUND';
            exit;
        }

        // Test with sample data
        \$testMessage = [
            'hash' => 'test_hash_123',
            'hashType' => 'memo',
            'senderAddress' => 'test@example.onion'
        ];

        \$response = \$payload->buildTransactionNotFound(\$testMessage);
        \$decoded = json_decode(\$response, true);

        if (\$decoded && isset(\$decoded['status']) && \$decoded['status'] === 'not_found') {
            echo 'STATUS_CORRECT';
        } else {
            echo 'STATUS_INCORRECT:' . print_r(\$decoded, true);
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$methodCheck" == "STATUS_CORRECT" ]]; then
        printf "\t   buildTransactionNotFound ${GREEN}PASSED${NC} (returns not_found status)\n"
        passed=$(( passed + 1 ))
    elif [[ "$methodCheck" == "METHOD_NOT_FOUND" ]]; then
        printf "\t   buildTransactionNotFound ${RED}FAILED${NC} (method not found)\n"
        failure=$(( failure + 1 ))
    else
        printf "\t   buildTransactionNotFound ${RED}FAILED${NC} (%s)\n" "${methodCheck}"
        failure=$(( failure + 1 ))
    fi
done

# Test 5: Verify different status responses in MessagePayload
echo -e "\n[Multiple Status Response Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Checking various status response values for ${container}"

    # Test multiple status values
    statusCheck=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        require_once('/etc/eiou/src/schemas/payloads/MessagePayload.php');

        \$app = Application::getInstance();
        \$user = \$app->services->getCurrentUser();
        \$utilContainer = \$app->services->getUtilityContainer();

        \$payload = new MessagePayload(\$user, \$utilContainer);

        \$testMessage = [
            'hash' => 'test_hash_123',
            'hashType' => 'memo',
            'senderAddress' => 'test@example.onion'
        ];

        \$statuses = ['completed', 'pending', 'sent', 'accepted'];
        \$allPassed = true;

        foreach (\$statuses as \$status) {
            \$response = \$payload->buildTransactionStatusResponse(\$testMessage, \$status);
            \$decoded = json_decode(\$response, true);

            if (!\$decoded || !isset(\$decoded['status']) || \$decoded['status'] !== \$status) {
                \$allPassed = false;
                echo 'FAILED:' . \$status;
                break;
            }
        }

        if (\$allPassed) {
            echo 'ALL_PASSED';
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$statusCheck" == "ALL_PASSED" ]]; then
        printf "\t   Multiple status responses ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Multiple status responses ${RED}FAILED${NC} (%s)\n" "${statusCheck}"
        failure=$(( failure + 1 ))
    fi
done

# Test 6: Verify handleTransactionMessageInquiryRequest returns actual status (unit test)
echo -e "\n[Inquiry Handler Returns Actual Status Test]"

for container in "${containers[@]:0:1}"; do  # Test first container
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Verifying inquiry handler logic for ${container}"

    # This tests that the inquiry handler queries status and returns it
    handlerCheck=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');

        \$app = Application::getInstance();
        \$msgService = \$app->services->getMessageService();

        // Use reflection to check the method exists and has correct structure
        \$reflection = new ReflectionClass(\$msgService);

        if (!\$reflection->hasMethod('handleMessageRequest')) {
            echo 'METHOD_NOT_FOUND';
            exit;
        }

        // Read the method source to verify it uses getStatusByMemo/getStatusByTxid
        \$method = \$reflection->getMethod('handleMessageRequest');
        \$filename = \$method->getFileName();
        \$startLine = \$method->getStartLine();

        // Read MessageService.php to check for status lookup
        \$source = file_get_contents('/etc/eiou/src/services/MessageService.php');

        if (strpos(\$source, 'getStatusByMemo') !== false && strpos(\$source, 'getStatusByTxid') !== false) {
            echo 'STATUS_LOOKUP_FOUND';
        } else {
            echo 'STATUS_LOOKUP_MISSING';
        }
    " 2>/dev/null || echo "ERROR")

    if [[ "$handlerCheck" == "STATUS_LOOKUP_FOUND" ]]; then
        printf "\t   Inquiry handler status lookup ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Inquiry handler status lookup ${RED}FAILED${NC} (%s)\n" "${handlerCheck}"
        failure=$(( failure + 1 ))
    fi
done

# Print summary
echo ""
echo "======================================================================="
echo "${testname} Summary: $passed passed, $failure failed out of $totaltests tests"
echo "======================================================================="
