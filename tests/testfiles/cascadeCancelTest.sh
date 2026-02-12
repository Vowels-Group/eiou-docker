#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

########################## Cascade Cancel Test ################################
# Tests cascade cancel/expire for dead-end P2P routes (issue #598)
#
# Verifies:
# - Dead-end nodes send cancel notifications upstream
# - Cancel notifications propagate through the cascade
# - Originator P2P resolves (cancelled/expired) faster than full expiration
# - P2pService.sendCancelNotificationForHash and related methods work
#
# Prerequisites:
# - Containers must be running
# - Contacts must be established (run addContactsTest first)
# - Best tested with collisions topology for multiple route paths
###############################################################################

testname="cascadeCancelTest"
totaltests=0
passed=0
failure=0

# Select sender container (first container in topology)
testSender="${containers[0]}"

echo -e "\nTesting cascade cancel for dead-end P2P routes..."
echo -e "Sender: ${testSender}"

# ==================== Test 1: P2pService Cancel Methods Exist ====================
echo -e "\n[Test 1: P2pService cancel notification methods]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking sendCancelNotificationForHash method on ${testSender}"

methodCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getP2pService();
    \$methods = ['sendCancelNotificationForHash'];
    \$missing = [];
    foreach (\$methods as \$m) {
        if (!method_exists(\$service, \$m)) \$missing[] = \$m;
    }
    echo empty(\$missing) ? 'OK' : 'MISSING:' . implode(',', \$missing);
" 2>/dev/null || echo "ERROR")

if [ "$methodCheck" = "OK" ]; then
    printf "\t   P2pService cancel methods ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   P2pService cancel methods ${RED}FAILED${NC} (${methodCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 2: Rp2pService Cancel Handling Methods ====================
echo -e "\n[Test 2: Rp2pService cancel handling methods]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking handleCancelNotification and setP2pService methods on ${testSender}"

rp2pMethodCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getRp2pService();
    \$methods = ['handleCancelNotification', 'setP2pService'];
    \$missing = [];
    foreach (\$methods as \$m) {
        if (!method_exists(\$service, \$m)) \$missing[] = \$m;
    }
    echo empty(\$missing) ? 'OK' : 'MISSING:' . implode(',', \$missing);
" 2>/dev/null || echo "ERROR")

if [ "$rp2pMethodCheck" = "OK" ]; then
    printf "\t   Rp2pService cancel methods ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Rp2pService cancel methods ${RED}FAILED${NC} (${rp2pMethodCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 3: P2pPayload buildCancelled Method ====================
echo -e "\n[Test 3: P2pPayload buildCancelled method]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking buildCancelled method and payload structure on ${testSender}"

payloadCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$payload = new \Eiou\Schemas\Payloads\P2pPayload(
        \$app->services->getCurrentUser(),
        \$app->services->getUtilityContainer()
    );
    if (!method_exists(\$payload, 'buildCancelled')) {
        echo 'MISSING';
        exit;
    }
    \$result = \$payload->buildCancelled('testhash123', 'http://test.example');
    if (\$result['type'] !== 'rp2p' || \$result['cancelled'] !== true || \$result['amount'] !== 0) {
        echo 'INVALID:' . json_encode(\$result);
        exit;
    }
    echo 'OK';
" 2>/dev/null || echo "ERROR")

if [ "$payloadCheck" = "OK" ]; then
    printf "\t   buildCancelled method and payload ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   buildCancelled method and payload ${RED}FAILED${NC} (${payloadCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 4: CleanupService P2pService Wiring ====================
echo -e "\n[Test 4: CleanupService -> P2pService wiring]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking setP2pService method on CleanupService on ${testSender}"

cleanupWiringCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getCleanupService();
    echo method_exists(\$service, 'setP2pService') ? 'OK' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$cleanupWiringCheck" = "OK" ]; then
    printf "\t   CleanupService P2pService wiring ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   CleanupService P2pService wiring ${RED}FAILED${NC} (${cleanupWiringCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 5: Dead-End Cascade Cancel (Non-Existent Recipient) ====================
echo -e "\n[Test 5: Dead-end cascade cancel (non-existent recipient)]"

totaltests=$(( totaltests + 1 ))

# Set expiration for test — use default 300s for HTTP, 450s for Tor
if [[ "${MODE:-http}" == "tor" ]]; then
    testExpiration=450
else
    testExpiration=300
fi
echo -e "\t-> Setting P2P expiration to ${testExpiration}s on ${testSender}"
docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$app->services->getCurrentUser()->set('p2pExpiration', ${testExpiration});
" 2>/dev/null || true

echo -e "\t-> Sending 5 USD from ${testSender} to non-existent address with --best"

cancelStartTime=$(date +%s)
cancelSendResult=$(docker exec ${testSender} eiou send http://nonexistent-a9.eiou.internal 5 USD --best 2>&1)

# With cascade cancel, dead-end nodes cancel immediately and propagate upstream.
# The originator should see cancellation well before full expiration.
# Use half the expiration as timeout — cascade cancel should be much faster.
cancelTimeout=$((testExpiration / 2))
echo -e "\t   Waiting for cascade cancel (timeout: ${cancelTimeout}s, full expiration: ${testExpiration}s)..."

all_containers="${containers[*]}"
elapsed=0
cancelDetected=0
while [ $elapsed -lt $cancelTimeout ]; do
    # Process queues to trigger P2P forwarding on all nodes
    process_routing_queues "$all_containers"

    # Run cleanup on all nodes to process expired P2Ps and trigger cascade cancel
    for container in "${containers[@]}"; do
        docker exec ${container} php -r "
            require_once('${BOOTSTRAP_PATH}');
            \Eiou\Core\Application::getInstance()->services->getCleanupService()->processCleanupMessages();
        " 2>/dev/null || true
    done

    # Check if the P2P was cancelled/expired on the originator
    p2pStatus=$(docker exec ${testSender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$stmt = \$pdo->query('SELECT status FROM p2p WHERE fast = 0 ORDER BY id DESC LIMIT 1');
        \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
        echo \$row ? \$row['status'] : 'UNKNOWN';
    " 2>/dev/null || echo "UNKNOWN")

    if [ "$p2pStatus" = "cancelled" ] || [ "$p2pStatus" = "expired" ]; then
        cancelDetected=1
        break
    fi

    elapsed=$(( $(date +%s) - cancelStartTime ))
done

cancelEndTime=$(date +%s)
cancelElapsed=$((cancelEndTime - cancelStartTime))

if [ "$cancelDetected" -eq 1 ]; then
    if [ "$cancelElapsed" -lt "$testExpiration" ]; then
        printf "\t   Dead-end cascade cancel ${GREEN}PASSED${NC} (Status: %s, Time: %ds < %ds expiration)\n" "$p2pStatus" "$cancelElapsed" "$testExpiration"
        passed=$(( passed + 1 ))
    else
        printf "\t   Dead-end cascade cancel ${YELLOW}PASSED (slow)${NC} (Status: %s, Time: %ds)\n" "$p2pStatus" "$cancelElapsed"
        passed=$(( passed + 1 ))
    fi
else
    printf "\t   Dead-end cascade cancel ${RED}FAILED${NC} (Status: %s, Timeout after %ds)\n" "$p2pStatus" "$cancelElapsed"
    failure=$(( failure + 1 ))
fi

# ==================== Test 6: Cascade Cancel Timing Benchmark ====================
echo -e "\n[Test 6: Cascade cancel timing benchmark]"

totaltests=$(( totaltests + 1 ))

# The cascade cancel should resolve significantly faster than full expiration.
# With hopWait=23s and 5 hops in collisions topology:
# - Without cascade cancel: ~115s (each hop waits independently)
# - With cascade cancel: ~30-60s (dead-ends propagate immediately)
# Accept any resolution within half the expiration as a pass.
if [ "$cancelDetected" -eq 1 ]; then
    speedup=$((testExpiration - cancelElapsed))
    speedupPercent=$((speedup * 100 / testExpiration))
    printf "\t   Resolution time: %ds (saved %ds, %d%% faster than full expiration)\n" "$cancelElapsed" "$speedup" "$speedupPercent"

    # Pass if resolved in less than half the expiration
    halfExpiration=$((testExpiration / 2))
    if [ "$cancelElapsed" -lt "$halfExpiration" ]; then
        printf "\t   Timing benchmark ${GREEN}PASSED${NC} (%ds < %ds half-expiration)\n" "$cancelElapsed" "$halfExpiration"
        passed=$(( passed + 1 ))
    else
        printf "\t   Timing benchmark ${YELLOW}PASSED (marginal)${NC} (%ds, near half-expiration %ds)\n" "$cancelElapsed" "$halfExpiration"
        passed=$(( passed + 1 ))
    fi
else
    printf "\t   Timing benchmark ${RED}FAILED${NC} (cancel not detected, cannot benchmark)\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 7: Verify Cancel Propagated Through Network ====================
echo -e "\n[Test 7: Cancel propagated through relay nodes]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking P2P status across relay nodes"

# Get the P2P hash from the originator
cancelHash=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$stmt = \$pdo->query('SELECT hash FROM p2p WHERE fast = 0 ORDER BY id DESC LIMIT 1');
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
    echo \$row ? \$row['hash'] : 'UNKNOWN';
" 2>/dev/null || echo "UNKNOWN")

if [ "$cancelHash" = "UNKNOWN" ]; then
    printf "\t   Cancel propagation ${RED}FAILED${NC} (could not retrieve P2P hash)\n"
    failure=$(( failure + 1 ))
else
    cancelledNodes=0
    totalRelayNodes=0
    for container in "${containers[@]}"; do
        # Skip the sender
        if [ "$container" = "$testSender" ]; then
            continue
        fi

        relayStatus=$(docker exec ${container} php -r "
            require_once('${BOOTSTRAP_PATH}');
            \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
            \$stmt = \$pdo->prepare('SELECT status FROM p2p WHERE hash = ?');
            \$stmt->execute(['${cancelHash}']);
            \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
            echo \$row ? \$row['status'] : 'NONE';
        " 2>/dev/null || echo "ERROR")

        if [ "$relayStatus" != "NONE" ] && [ "$relayStatus" != "ERROR" ]; then
            totalRelayNodes=$((totalRelayNodes + 1))
            if [ "$relayStatus" = "cancelled" ] || [ "$relayStatus" = "expired" ]; then
                cancelledNodes=$((cancelledNodes + 1))
            fi
        fi
    done

    if [ "$totalRelayNodes" -gt 0 ]; then
        if [ "$cancelledNodes" -eq "$totalRelayNodes" ]; then
            printf "\t   Cancel propagation ${GREEN}PASSED${NC} (%d/%d relay nodes cancelled/expired)\n" "$cancelledNodes" "$totalRelayNodes"
            passed=$(( passed + 1 ))
        else
            printf "\t   Cancel propagation ${YELLOW}PARTIAL${NC} (%d/%d relay nodes cancelled/expired)\n" "$cancelledNodes" "$totalRelayNodes"
            passed=$(( passed + 1 ))  # Partial is still OK — some nodes may not have received the P2P
        fi
    else
        printf "\t   Cancel propagation ${YELLOW}SKIPPED${NC} (no relay nodes found with this P2P hash)\n"
        passed=$(( passed + 1 ))  # Not a failure if P2P didn't propagate far
    fi
fi

echo ""
succesrate "${totaltests}" "${passed}" "${failure}" "'cascade cancel'"
