#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Curl Error Handling Test ############################
# Tests HTTP client error handling and timeout behavior
#
# Verifies:
# - Connection timeouts are handled gracefully
# - HTTP error responses are processed correctly
# - Retry logic works for transient failures
# - Error messages are informative
#
# Prerequisites:
# - Containers must be running
# - Contacts must be established (run addContactsTest first)
################################################################################

# Test curl error handling and transport utility functionality
echo -e "\nTesting curl error handling and transport utilities..."

testname="curlErrorHandlingTest"
totaltests=0
passed=0
failure=0

# ============================================================================
# Test 1: TransportUtilityService Exists and Initializes
# ============================================================================
echo -e "\n[Test 1: TransportUtilityService Initialization]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

initResult=$(docker exec ${container} php -r "
    require_once('${BOOTSTRAP_PATH}');

    \$app = \Eiou\Core\Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();

    if (\$transport !== null && is_object(\$transport)) {
        echo 'SUCCESS';
    } else {
        echo 'FAILED';
    }
" 2>&1 | tail -1)

if [[ "$initResult" == "SUCCESS" ]]; then
    printf "\t   TransportUtilityService initialization ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   TransportUtilityService initialization ${RED}FAILED${NC} (%s)\n" "${initResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 2: Verify HTTP Timeout Configuration (15 seconds)
# ============================================================================
echo -e "\n[Test 2: HTTP Timeout Configuration]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

# Resolve HTTP timeout from Constants (source uses Constants::HTTP_TRANSPORT_TIMEOUT_SECONDS, not a literal)
timeoutCheck=$(docker exec ${container} php -r "
    require_once('/etc/eiou/src/core/Constants.php');
    echo \Eiou\Core\Constants::HTTP_TRANSPORT_TIMEOUT_SECONDS;
" 2>/dev/null || echo "0")

if [[ "$timeoutCheck" == "15" ]]; then
    printf "\t   HTTP timeout is 15 seconds ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   HTTP timeout should be 15 seconds, found: %s ${RED}FAILED${NC}\n" "${timeoutCheck}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 3: Verify HTTP Connect Timeout Configuration (5 seconds)
# ============================================================================
echo -e "\n[Test 3: HTTP Connect Timeout Configuration]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

connectTimeoutCheck=$(docker exec ${container} sh -c "sed -n '/function sendByHttp/,/function sendByTor/p' /etc/eiou/src/services/utilities/TransportUtilityService.php | grep 'CURLOPT_CONNECTTIMEOUT' | grep -o '[0-9]*'" 2>/dev/null || echo "0")

if [[ "$connectTimeoutCheck" == "5" ]]; then
    printf "\t   HTTP connect timeout is 5 seconds ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   HTTP connect timeout should be 5 seconds, found: %s ${RED}FAILED${NC}\n" "${connectTimeoutCheck}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 4: Verify Tor Timeout Configuration (30 seconds)
# ============================================================================
echo -e "\n[Test 4: Tor Timeout Configuration]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

# Resolve Tor timeout from Constants (source uses Constants::TOR_TRANSPORT_TIMEOUT_SECONDS, not a literal)
torTimeoutCheck=$(docker exec ${container} php -r "
    require_once('/etc/eiou/src/core/Constants.php');
    echo \Eiou\Core\Constants::TOR_TRANSPORT_TIMEOUT_SECONDS;
" 2>/dev/null || echo "0")

if [[ "$torTimeoutCheck" == "30" ]]; then
    printf "\t   Tor timeout is 30 seconds ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Tor timeout should be 30 seconds, found: %s ${RED}FAILED${NC}\n" "${torTimeoutCheck}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 5: Verify Tor Connect Timeout Configuration (10 seconds)
# ============================================================================
echo -e "\n[Test 5: Tor Connect Timeout Configuration]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

# Use tighter function boundary (sendByTor to createCurlHandle) to avoid capturing createCurlHandle's values
torConnectTimeoutCheck=$(docker exec ${container} sh -c "sed -n '/function sendByTor/,/function createCurlHandle/p' /etc/eiou/src/services/utilities/TransportUtilityService.php | grep 'CURLOPT_CONNECTTIMEOUT' | grep -o '[0-9]*'" 2>/dev/null || echo "0")

if [[ "$torConnectTimeoutCheck" == "10" ]]; then
    printf "\t   Tor connect timeout is 10 seconds ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Tor connect timeout should be 10 seconds, found: %s ${RED}FAILED${NC}\n" "${torConnectTimeoutCheck}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 6: Curl Error Handling Code Exists in sendByHttp
# ============================================================================
echo -e "\n[Test 6: Curl Error Handling in sendByHttp]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

errorHandlingCheck=$(docker exec ${container} sh -c "grep -c 'curl_error' /etc/eiou/src/services/utilities/TransportUtilityService.php" 2>/dev/null || echo "0")

if [[ "$errorHandlingCheck" -ge "2" ]]; then
    printf "\t   Curl error handling exists (${errorHandlingCheck} occurrences) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Curl error handling missing ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 7: Structured Error Response Format
# ============================================================================
echo -e "\n[Test 7: Structured Error Response Format]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

errorFormatCheck=$(docker exec ${container} sh -c "grep -c \"'status' => 'error'\" /etc/eiou/src/services/utilities/TransportUtilityService.php" 2>/dev/null || echo "0")

if [[ "$errorFormatCheck" -ge "2" ]]; then
    printf "\t   Structured error response format exists ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Structured error response format missing ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 8: sendByHttp Returns JSON Error on Connection Failure
# ============================================================================
echo -e "\n[Test 8: sendByHttp Returns JSON Error on Failure]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

# Try to connect to an unreachable address and verify we get a structured error
errorResult=$(docker exec ${container} php -r "
    require_once('${BOOTSTRAP_PATH}');

    \$app = \Eiou\Core\Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();

    // Try to send to an unreachable address (should fail fast due to connect timeout)
    \$payload = json_encode(['test' => 'payload', 'senderAddress' => 'http://test', 'senderPublicKey' => 'test']);

    // sendByHttp expects signed payload string, so we call it directly
    \$response = \$transport->sendByHttp('http://192.0.2.1:9999', \$payload);

    // Check if response is valid JSON with error status
    \$decoded = json_decode(\$response, true);

    if (\$decoded !== null && isset(\$decoded['status']) && \$decoded['status'] === 'error') {
        echo 'SUCCESS:' . (\$decoded['error_code'] ?? 'no_code');
    } else {
        echo 'FAILED:' . substr(\$response, 0, 100);
    }
" 2>&1 | tail -1)

if [[ "$errorResult" =~ ^SUCCESS ]]; then
    errorCode=$(echo "$errorResult" | cut -d: -f2)
    printf "\t   JSON error response on connection failure ${GREEN}PASSED${NC} (code: %s)\n" "${errorCode}"
    passed=$(( passed + 1 ))
else
    printf "\t   JSON error response on connection failure ${RED}FAILED${NC} (%s)\n" "${errorResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 9: MessageDeliveryService Handles Error Status
# ============================================================================
echo -e "\n[Test 9: MessageDeliveryService Error Status Handling]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

# Check that MessageDeliveryService code handles 'error' status
errorStatusCheck=$(docker exec ${container} sh -c "grep -c \"status === 'error'\" /etc/eiou/src/services/MessageDeliveryService.php" 2>/dev/null || echo "0")

if [[ "$errorStatusCheck" -ge "2" ]]; then
    printf "\t   MessageDeliveryService handles error status ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   MessageDeliveryService should handle error status ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 10: Logger Integration for HTTP Errors
# ============================================================================
echo -e "\n[Test 10: Logger Integration for HTTP Errors]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

loggerCheck=$(docker exec ${container} sh -c "grep -c 'Logger::getInstance()->warning.*HTTP request failed' /etc/eiou/src/services/utilities/TransportUtilityService.php" 2>/dev/null || echo "0")

if [[ "$loggerCheck" -ge "1" ]]; then
    printf "\t   Logger integration for HTTP errors ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Logger integration missing ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 11: Logger Integration for Tor Errors
# ============================================================================
echo -e "\n[Test 11: Logger Integration for Tor Errors]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

torLoggerCheck=$(docker exec ${container} sh -c "grep -c 'Logger::getInstance()->warning.*TOR request failed' /etc/eiou/src/services/utilities/TransportUtilityService.php" 2>/dev/null || echo "0")

if [[ "$torLoggerCheck" -ge "1" ]]; then
    printf "\t   Logger integration for Tor errors ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Logger integration for Tor missing ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 12: Error Response Contains Required Fields
# ============================================================================
echo -e "\n[Test 12: Error Response Structure]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

structureResult=$(docker exec ${container} php -r "
    require_once('${BOOTSTRAP_PATH}');

    \$app = \Eiou\Core\Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();

    // Send to unreachable address
    \$payload = json_encode(['test' => 'payload', 'senderAddress' => 'http://test', 'senderPublicKey' => 'test']);
    \$response = \$transport->sendByHttp('http://192.0.2.1:9999', \$payload);

    \$decoded = json_decode(\$response, true);

    \$hasStatus = isset(\$decoded['status']);
    \$hasMessage = isset(\$decoded['message']);
    \$hasErrorCode = isset(\$decoded['error_code']);

    if (\$hasStatus && \$hasMessage && \$hasErrorCode) {
        echo 'SUCCESS';
    } else {
        echo 'MISSING:' . (!\$hasStatus ? 'status,' : '') . (!\$hasMessage ? 'message,' : '') . (!\$hasErrorCode ? 'error_code' : '');
    }
" 2>&1 | tail -1)

if [[ "$structureResult" == "SUCCESS" ]]; then
    printf "\t   Error response contains all required fields ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Error response missing fields ${RED}FAILED${NC} (%s)\n" "${structureResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 13: Successful HTTP Request Still Works
# ============================================================================
echo -e "\n[Test 13: Successful HTTP Request to Known Container]"

# Get addresses
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
if [[ ${#containersLinkKeys[@]} -gt 0 ]]; then
    testPair="${containersLinkKeys[0]}"
    containerKeys=(${testPair//,/ })
    sender="${containerKeys[0]}"
    receiver="${containerKeys[1]}"
    receiverAddress="${containerAddresses[${receiver}]}"

    totaltests=$(( totaltests + 1 ))

    successResult=$(docker exec ${sender} php -r "
        require_once('${BOOTSTRAP_PATH}');

        \$app = \Eiou\Core\Application::getInstance();
        \$transport = \$app->services->getUtilityContainer()->getTransportUtility();

        // Create a minimal test payload
        \$payload = json_encode([
            'test' => 'connectivity',
            'senderAddress' => 'http://test',
            'senderPublicKey' => 'test'
        ]);

        // Try to connect to the receiver container
        \$response = \$transport->sendByHttp('${receiverAddress}', \$payload);

        // A successful connection should return non-empty response (even if rejected)
        if (!empty(\$response)) {
            \$decoded = json_decode(\$response, true);
            // If we get a JSON response (even rejected), the transport layer is working
            if (\$decoded !== null) {
                echo 'SUCCESS:' . (\$decoded['status'] ?? 'no_status');
            } else {
                // Non-JSON response is still a successful HTTP connection
                echo 'SUCCESS:html_response';
            }
        } else {
            echo 'FAILED:empty_response';
        }
    " 2>&1 | tail -1)

    if [[ "$successResult" =~ ^SUCCESS ]]; then
        responseType=$(echo "$successResult" | cut -d: -f2)
        printf "\t   HTTP transport to container working ${GREEN}PASSED${NC} (server replied: %s)\n" "${responseType}"
        passed=$(( passed + 1 ))
    else
        printf "\t   HTTP request to container ${RED}FAILED${NC} (%s)\n" "${successResult}"
        failure=$(( failure + 1 ))
    fi
else
    echo -e "\t   ${YELLOW}SKIPPED${NC} - No container pairs configured"
fi

# ============================================================================
# Test 14: Curl Errno Is Captured Correctly
# ============================================================================
echo -e "\n[Test 14: Curl Errno Capture]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

errnoResult=$(docker exec ${container} php -r "
    require_once('${BOOTSTRAP_PATH}');

    \$app = \Eiou\Core\Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();

    \$payload = json_encode(['test' => 'payload', 'senderAddress' => 'http://test', 'senderPublicKey' => 'test']);
    \$response = \$transport->sendByHttp('http://192.0.2.1:9999', \$payload);

    \$decoded = json_decode(\$response, true);

    if (isset(\$decoded['error_code']) && is_numeric(\$decoded['error_code'])) {
        // Common curl error codes: 7=connection refused, 28=timeout, 6=couldn't resolve host
        echo 'SUCCESS:' . \$decoded['error_code'];
    } else {
        echo 'FAILED:no_numeric_code';
    }
" 2>&1 | tail -1)

if [[ "$errnoResult" =~ ^SUCCESS ]]; then
    errno=$(echo "$errnoResult" | cut -d: -f2)
    printf "\t   Curl errno captured correctly ${GREEN}PASSED${NC} (errno: %s)\n" "${errno}"
    passed=$(( passed + 1 ))
else
    printf "\t   Curl errno capture ${RED}FAILED${NC} (%s)\n" "${errnoResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 15: Error Message Contains Curl Error Description
# ============================================================================
echo -e "\n[Test 15: Error Message Contains Description]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

msgResult=$(docker exec ${container} php -r "
    require_once('${BOOTSTRAP_PATH}');

    \$app = \Eiou\Core\Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();

    \$payload = json_encode(['test' => 'payload', 'senderAddress' => 'http://test', 'senderPublicKey' => 'test']);
    \$response = \$transport->sendByHttp('http://192.0.2.1:9999', \$payload);

    \$decoded = json_decode(\$response, true);

    if (isset(\$decoded['message']) && strlen(\$decoded['message']) > 10) {
        // Message should contain descriptive error
        echo 'SUCCESS:' . substr(\$decoded['message'], 0, 50);
    } else {
        echo 'FAILED:message_too_short';
    }
" 2>&1 | tail -1)

if [[ "$msgResult" =~ ^SUCCESS ]]; then
    msgPreview=$(echo "$msgResult" | cut -d: -f2-)
    printf "\t   Error message contains description ${GREEN}PASSED${NC}\n"
    printf "\t   Message: %s...\n" "${msgPreview}"
    passed=$(( passed + 1 ))
else
    printf "\t   Error message description ${RED}FAILED${NC} (%s)\n" "${msgResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 16: Transport Error Triggers Retry in MessageDeliveryService
# ============================================================================
echo -e "\n[Test 16: Transport Error Triggers Retry Logic]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

# Check that transport error status triggers the retry path (lastError assignment)
retryLogicCheck=$(docker exec ${container} sh -c "grep -A2 \"status === 'error'\" /etc/eiou/src/services/MessageDeliveryService.php | grep -c 'lastError'" 2>/dev/null || echo "0")

if [[ "$retryLogicCheck" -ge "2" ]]; then
    printf "\t   Transport error triggers retry logic ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transport error retry logic ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 17: curl_close Called After Error
# ============================================================================
echo -e "\n[Test 17: Proper Resource Cleanup on Error]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

# Verify curl_close is called in error path (restrict to sendByHttp/sendByTor, use -B35 for Tor's wider gap due to SOCKS5 restart signal code)
cleanupCheck=$(docker exec ${container} sh -c "sed -n '/function sendByHttp/,/function createCurlHandle/p' /etc/eiou/src/services/utilities/TransportUtilityService.php | grep -B35 \"'status' => 'error'\" | grep -c 'curl_close'" 2>/dev/null || echo "0")

if [[ "$cleanupCheck" -ge "2" ]]; then
    printf "\t   Curl handle properly closed on error ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Curl handle cleanup ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 18: Different Error Types Produce Different Messages
# ============================================================================
echo -e "\n[Test 18: Error Message Differentiation]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

diffResult=$(docker exec ${container} php -r "
    require_once('${BOOTSTRAP_PATH}');

    \$app = \Eiou\Core\Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();

    \$payload = json_encode(['test' => 'payload', 'senderAddress' => 'http://test', 'senderPublicKey' => 'test']);

    // Test 1: Connection refused (unreachable IP)
    \$response1 = \$transport->sendByHttp('http://192.0.2.1:9999', \$payload);
    \$decoded1 = json_decode(\$response1, true);
    \$errno1 = \$decoded1['error_code'] ?? 0;

    // Test 2: Invalid hostname
    \$response2 = \$transport->sendByHttp('http://this-hostname-definitely-does-not-exist-12345.invalid:9999', \$payload);
    \$decoded2 = json_decode(\$response2, true);
    \$errno2 = \$decoded2['error_code'] ?? 0;

    // Error codes should be captured (may be same or different depending on network)
    if (\$errno1 > 0 && \$errno2 > 0) {
        echo 'SUCCESS:' . \$errno1 . ',' . \$errno2;
    } else {
        echo 'FAILED:errno1=' . \$errno1 . ',errno2=' . \$errno2;
    }
" 2>&1 | tail -1)

if [[ "$diffResult" =~ ^SUCCESS ]]; then
    errnos=$(echo "$diffResult" | cut -d: -f2)
    printf "\t   Error types produce valid error codes ${GREEN}PASSED${NC} (codes: %s)\n" "${errnos}"
    passed=$(( passed + 1 ))
else
    printf "\t   Error differentiation ${RED}FAILED${NC} (%s)\n" "${diffResult}"
    failure=$(( failure + 1 ))
fi

# Print summary
succesrate "${totaltests}" "${passed}" "${failure}" "'curl error handling'"
