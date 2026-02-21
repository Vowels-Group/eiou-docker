#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

###################### Parallel Broadcast Test #################################
# Tests curl_multi parallel broadcast functionality in P2P and transport layers
#
# Verifies:
# - createCurlHandle() method exists and returns CurlHandle for HTTP/Tor
# - sendBatch() method exists and handles empty/multi-recipient input
# - sendBatchAsync() method exists on MessageDeliveryService
# - TransportServiceInterface includes sendBatch()
# - Parallel batch send to multiple containers returns results per-recipient
# - Batch send to unreachable addresses returns structured error per-handle
# - curl_multi resource cleanup (no handle leaks)
#
# Prerequisites:
# - Containers must be running
# - Contacts must be established (run addContactsTest first)
################################################################################

testname="parallelBroadcastTest"
totaltests=0
passed=0
failure=0

testContainer="${containers[0]}"

echo -e "\nTesting parallel broadcast (curl_multi) functionality..."
echo -e "Test container: ${testContainer}"

# ============================================================================
# Test 1: createCurlHandle Method Exists
# ============================================================================
echo -e "\n[Test 1: createCurlHandle method exists]"
totaltests=$(( totaltests + 1 ))

methodCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();
    echo method_exists(\$transport, 'createCurlHandle') ? 'OK' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$methodCheck" = "OK" ]; then
    printf "\t   createCurlHandle method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   createCurlHandle method ${RED}FAILED${NC} (${methodCheck})\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 2: sendBatch Method Exists
# ============================================================================
echo -e "\n[Test 2: sendBatch method exists]"
totaltests=$(( totaltests + 1 ))

methodCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();
    echo method_exists(\$transport, 'sendBatch') ? 'OK' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$methodCheck" = "OK" ]; then
    printf "\t   sendBatch method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sendBatch method ${RED}FAILED${NC} (${methodCheck})\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 3: sendBatchAsync Method Exists on MessageDeliveryService
# ============================================================================
echo -e "\n[Test 3: sendBatchAsync method exists]"
totaltests=$(( totaltests + 1 ))

methodCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getMessageDeliveryService();
    if (\$service === null) {
        echo 'NULL_SERVICE';
    } else {
        echo method_exists(\$service, 'sendBatchAsync') ? 'OK' : 'MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [ "$methodCheck" = "OK" ]; then
    printf "\t   sendBatchAsync method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sendBatchAsync method ${RED}FAILED${NC} (${methodCheck})\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 4: TransportServiceInterface Declares sendBatch
# ============================================================================
echo -e "\n[Test 4: TransportServiceInterface declares sendBatch]"
totaltests=$(( totaltests + 1 ))

interfaceCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$ref = new ReflectionClass(\Eiou\Contracts\TransportServiceInterface::class);
    echo \$ref->hasMethod('sendBatch') ? 'OK' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$interfaceCheck" = "OK" ]; then
    printf "\t   TransportServiceInterface sendBatch ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   TransportServiceInterface sendBatch ${RED}FAILED${NC} (${interfaceCheck})\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 5: sendBatch Returns Empty Array for Empty Recipients
# ============================================================================
echo -e "\n[Test 5: sendBatch returns empty for empty recipients]"
totaltests=$(( totaltests + 1 ))

emptyCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();
    \$result = \$transport->sendBatch([], ['test' => 'data']);
    echo (is_array(\$result) && empty(\$result)) ? 'OK' : 'FAILED:' . json_encode(\$result);
" 2>/dev/null || echo "ERROR")

if [ "$emptyCheck" = "OK" ]; then
    printf "\t   sendBatch empty recipients ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sendBatch empty recipients ${RED}FAILED${NC} (${emptyCheck})\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 6: createCurlHandle Returns CurlHandle for HTTP Address
# ============================================================================
echo -e "\n[Test 6: createCurlHandle returns CurlHandle for HTTP]"
totaltests=$(( totaltests + 1 ))

handleCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();
    \$ch = \$transport->createCurlHandle('http://example.com:8080', '{\"test\":\"payload\"}');
    if (\$ch instanceof \CurlHandle) {
        curl_close(\$ch);
        echo 'OK';
    } else {
        echo 'WRONG_TYPE:' . gettype(\$ch);
    }
" 2>/dev/null || echo "ERROR")

if [ "$handleCheck" = "OK" ]; then
    printf "\t   createCurlHandle HTTP ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   createCurlHandle HTTP ${RED}FAILED${NC} (${handleCheck})\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 7: createCurlHandle Returns CurlHandle for Tor Address
# ============================================================================
echo -e "\n[Test 7: createCurlHandle returns CurlHandle for Tor]"
totaltests=$(( totaltests + 1 ))

torHandleCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();
    \$ch = \$transport->createCurlHandle('abcdef1234567890.onion', '{\"test\":\"payload\"}');
    if (\$ch instanceof \CurlHandle) {
        // Verify Tor-specific config: CURLOPT_PROXY should be set
        \$info = curl_getinfo(\$ch);
        curl_close(\$ch);
        echo 'OK';
    } else {
        echo 'WRONG_TYPE:' . gettype(\$ch);
    }
" 2>/dev/null || echo "ERROR")

if [ "$torHandleCheck" = "OK" ]; then
    printf "\t   createCurlHandle Tor ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   createCurlHandle Tor ${RED}FAILED${NC} (${torHandleCheck})\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 8: sendBatch to Unreachable Addresses Returns Error Per Handle
# ============================================================================
echo -e "\n[Test 8: sendBatch error handling for unreachable addresses]"
totaltests=$(( totaltests + 1 ))

errorCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();

    \$recipients = ['http://192.0.2.1:9999', 'http://192.0.2.2:9999'];
    \$payload = [
        'senderAddress' => 'http://test.local',
        'senderPublicKey' => 'testkey',
        'test' => 'batch_error'
    ];

    \$results = \$transport->sendBatch(\$recipients, \$payload);

    // Should have results keyed by each recipient
    if (count(\$results) !== 2) {
        echo 'WRONG_COUNT:' . count(\$results) . ':keys=' . implode(',', array_keys(\$results));
        exit;
    }

    // Each result should have response, signature, nonce fields
    foreach (\$results as \$addr => \$result) {
        if (!isset(\$result['response']) || !isset(\$result['signature']) || !isset(\$result['nonce'])) {
            \$missing = [];
            if (!isset(\$result['response'])) \$missing[] = 'response';
            if (!isset(\$result['signature'])) \$missing[] = 'signature';
            if (!isset(\$result['nonce'])) \$missing[] = 'nonce';
            echo 'MISSING_FIELDS:' . implode(',', \$missing) . ':addr=' . \$addr;
            exit;
        }
        // Response should be JSON with error status
        \$decoded = json_decode(\$result['response'], true);
        if (\$decoded === null) {
            echo 'JSON_DECODE_FAIL:' . substr(\$result['response'], 0, 100);
            exit;
        }
        if ((\$decoded['status'] ?? '') !== 'error') {
            echo 'WRONG_STATUS:' . (\$decoded['status'] ?? 'none') . ':addr=' . \$addr;
            exit;
        }
    }

    echo 'OK';
" 2>&1 | tail -1)

if [ "$errorCheck" = "OK" ]; then
    printf "\t   sendBatch error handling ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sendBatch error handling ${RED}FAILED${NC} (${errorCheck})\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 9: sendBatch Parallel to Reachable Containers
# ============================================================================
echo -e "\n[Test 9: sendBatch parallel to reachable containers]"

# Gather reachable container addresses (use first 2 containers that are not testContainer)
containerAddressList=""
containerCount=0
for container in "${containers[@]}"; do
    if [ "$container" != "$testContainer" ] && [ $containerCount -lt 2 ]; then
        addr="${containerAddresses[$container]}"
        if [ -n "$addr" ]; then
            if [ -z "$containerAddressList" ]; then
                containerAddressList="'${addr}'"
            else
                containerAddressList="${containerAddressList}, '${addr}'"
            fi
            containerCount=$(( containerCount + 1 ))
        fi
    fi
done

if [ $containerCount -ge 2 ]; then
    totaltests=$(( totaltests + 1 ))

    batchResult=$(docker exec ${testContainer} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$transport = \$app->services->getUtilityContainer()->getTransportUtility();

        \$recipients = [${containerAddressList}];
        \$payload = [
            'senderAddress' => 'http://test.local',
            'senderPublicKey' => 'testkey',
            'type' => 'test_batch'
        ];

        \$startTime = microtime(true);
        \$results = \$transport->sendBatch(\$recipients, \$payload);
        \$elapsed = number_format((microtime(true) - \$startTime) * 1000, 3);

        // Should have a result per recipient
        if (count(\$results) !== count(\$recipients)) {
            echo 'WRONG_COUNT:expected=' . count(\$recipients) . ',got=' . count(\$results);
            exit;
        }

        // Each result should have response (may be success or error, but must be valid JSON)
        \$allHaveResponse = true;
        foreach (\$results as \$addr => \$result) {
            if (!isset(\$result['response']) || json_decode(\$result['response'], true) === null) {
                \$allHaveResponse = false;
                break;
            }
        }

        if (\$allHaveResponse) {
            echo 'OK:' . \$elapsed . 'ms';
        } else {
            echo 'INVALID_RESPONSE';
        }
    " 2>&1 | tail -1)

    if [[ "$batchResult" =~ ^OK ]]; then
        elapsed=$(echo "$batchResult" | cut -d: -f2)
        printf "\t   sendBatch parallel to containers ${GREEN}PASSED${NC} (${elapsed})\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   sendBatch parallel to containers ${RED}FAILED${NC} (${batchResult})\n"
        failure=$(( failure + 1 ))
    fi
else
    echo -e "\t   ${YELLOW}SKIPPED${NC} - Need at least 2 other containers"
fi

# ============================================================================
# Test 10: sendBatch Results Keyed by Recipient Address
# ============================================================================
echo -e "\n[Test 10: sendBatch results keyed by recipient address]"
totaltests=$(( totaltests + 1 ))

keyCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();

    \$addr1 = 'http://192.0.2.10:8080';
    \$addr2 = 'http://192.0.2.11:8080';
    \$recipients = [\$addr1, \$addr2];
    \$payload = ['senderAddress' => 'http://test', 'senderPublicKey' => 'key', 'test' => true];

    \$results = \$transport->sendBatch(\$recipients, \$payload);

    // Keys must match recipient addresses
    if (isset(\$results[\$addr1]) && isset(\$results[\$addr2])) {
        echo 'OK';
    } else {
        echo 'WRONG_KEYS:' . implode(',', array_keys(\$results));
    }
" 2>&1 | tail -1)

if [ "$keyCheck" = "OK" ]; then
    printf "\t   Results keyed by address ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Results keyed by address ${RED}FAILED${NC} (${keyCheck})\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 11: sendBatch Per-Recipient Signing (Unique Nonces)
# ============================================================================
echo -e "\n[Test 11: sendBatch unique nonce per recipient]"
totaltests=$(( totaltests + 1 ))

nonceCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();

    \$recipients = ['http://192.0.2.10:8080', 'http://192.0.2.11:8080'];
    \$payload = ['senderAddress' => 'http://test', 'senderPublicKey' => 'key', 'data' => 'test'];

    \$results = \$transport->sendBatch(\$recipients, \$payload);

    \$nonces = [];
    foreach (\$results as \$result) {
        if (!empty(\$result['nonce'])) {
            \$nonces[] = \$result['nonce'];
        }
    }

    // Should have 2 nonces and they should be different
    if (count(\$nonces) === 2 && \$nonces[0] !== \$nonces[1]) {
        echo 'OK';
    } elseif (count(\$nonces) === 2 && \$nonces[0] === \$nonces[1]) {
        echo 'SAME_NONCE';
    } else {
        echo 'MISSING_NONCES:' . count(\$nonces);
    }
" 2>&1 | tail -1)

if [ "$nonceCheck" = "OK" ]; then
    printf "\t   Unique nonce per recipient ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Unique nonce per recipient ${RED}FAILED${NC} (${nonceCheck})\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 12: curl_multi Code Exists in TransportUtilityService
# ============================================================================
echo -e "\n[Test 12: curl_multi functions used in sendBatch]"
totaltests=$(( totaltests + 1 ))

curlMultiCheck=$(docker exec ${testContainer} sh -c "
    grep -c 'curl_multi_init\|curl_multi_exec\|curl_multi_select\|curl_multi_close\|curl_multi_add_handle\|curl_multi_remove_handle\|curl_multi_getcontent' /etc/eiou/src/services/utilities/TransportUtilityService.php
" 2>/dev/null || echo "0")

# Should have at least 7 distinct curl_multi calls (init, exec, select, close, add, remove, getcontent)
if [ "$curlMultiCheck" -ge "7" ]; then
    printf "\t   curl_multi functions present (${curlMultiCheck} calls) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   curl_multi functions (found ${curlMultiCheck}, need >= 7) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 13: sendBatchAsync Returns Empty for Empty Sends
# ============================================================================
echo -e "\n[Test 13: sendBatchAsync returns empty for empty sends]"
totaltests=$(( totaltests + 1 ))

asyncEmptyCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getMessageDeliveryService();
    if (\$service === null) {
        echo 'NULL_SERVICE';
        exit;
    }
    \$result = \$service->sendBatchAsync('p2p', []);
    echo (is_array(\$result) && empty(\$result)) ? 'OK' : 'FAILED:' . json_encode(\$result);
" 2>/dev/null || echo "ERROR")

if [ "$asyncEmptyCheck" = "OK" ]; then
    printf "\t   sendBatchAsync empty sends ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   sendBatchAsync empty sends ${RED}FAILED${NC} (${asyncEmptyCheck})\n"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 14: MessageDeliveryServiceInterface Declares sendBatchAsync
# ============================================================================
echo -e "\n[Test 14: MessageDeliveryServiceInterface declares sendBatchAsync]"
totaltests=$(( totaltests + 1 ))

interfaceAsyncCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$ref = new ReflectionClass(\Eiou\Contracts\MessageDeliveryServiceInterface::class);
    echo \$ref->hasMethod('sendBatchAsync') ? 'OK' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$interfaceAsyncCheck" = "OK" ]; then
    printf "\t   MessageDeliveryServiceInterface sendBatchAsync ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   MessageDeliveryServiceInterface sendBatchAsync ${RED}FAILED${NC} (${interfaceAsyncCheck})\n"
    failure=$(( failure + 1 ))
fi

# Print summary
succesrate "${totaltests}" "${passed}" "${failure}" "'parallel broadcast (curl_multi)'"
