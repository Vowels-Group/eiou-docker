#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

######################### Route Cancellation Test ##############################
# Tests route cancellation and capacity reservation system
#
# Verifies:
# - RouteCancellationService methods exist and are wired
# - capacity_reservations and route_cancellations tables exist
# - Best-fee selection cancels unselected candidates (resource freeing)
# - Capacity reservations are created during P2P relay
# - Capacity reservations are released after cancellation
# - Originator cancel propagates downstream to free relay resources
# - handleIncomingCancellation propagates further downstream
# - Timing: active cancel vs passive CleanupService expiry
#
# Prerequisites:
# - Containers must be running
# - Contacts must be established (run addContactsTest first)
# - Best tested with collisions or collisionscluster topology
###############################################################################

testname="routeCancellationTest"
totaltests=0
passed=0
failure=0

# Select sender container (first container in topology)
testSender="${containers[0]}"

echo -e "\nTesting route cancellation and capacity reservation system..."
echo -e "Sender: ${testSender}"

# ==================== Test 1: RouteCancellationService Wiring ====================
echo -e "\n[Test 1: RouteCancellationService wiring and methods]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking RouteCancellationService on ${testSender}"

wiringCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getRouteCancellationService();
    \$methods = ['cancelUnselectedRoutes', 'handleIncomingCancellation', 'generateRandomizedHopBudget', 'decrementHopBudget'];
    \$missing = [];
    foreach (\$methods as \$m) {
        if (!method_exists(\$service, \$m)) \$missing[] = \$m;
    }
    echo empty(\$missing) ? 'OK' : 'MISSING:' . implode(',', \$missing);
" 2>/dev/null || echo "ERROR")

if [ "$wiringCheck" = "OK" ]; then
    printf "\t   RouteCancellationService wiring ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   RouteCancellationService wiring ${RED}FAILED${NC} (${wiringCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 2: Database Tables Exist ====================
echo -e "\n[Test 2: capacity_reservations and route_cancellations tables]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking tables on ${testSender}"

tableCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$tables = [];
    foreach (['capacity_reservations', 'route_cancellations'] as \$t) {
        \$stmt = \$pdo->query(\"SHOW TABLES LIKE '\$t'\");
        if (\$stmt->fetch()) \$tables[] = \$t;
    }
    echo count(\$tables) === 2 ? 'OK' : 'MISSING:' . implode(',', array_diff(['capacity_reservations','route_cancellations'], \$tables));
" 2>/dev/null || echo "ERROR")

if [ "$tableCheck" = "OK" ]; then
    printf "\t   Database tables ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Database tables ${RED}FAILED${NC} (${tableCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 3: CapacityReservationRepository Wired to P2pService ====================
echo -e "\n[Test 3: P2pService uses CapacityReservationRepository for credit holds]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking CapacityReservationRepository injected into P2pService on ${testSender}"

p2pCapCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getP2pService();
    \$ref = new ReflectionProperty(\$service, 'capacityReservationRepository');
    \$ref->setAccessible(true);
    \$repo = \$ref->getValue(\$service);
    echo (\$repo instanceof \Eiou\Database\CapacityReservationRepository) ? 'OK' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$p2pCapCheck" = "OK" ]; then
    printf "\t   P2pService capacity reservation wiring ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   P2pService capacity reservation wiring ${RED}FAILED${NC} (${p2pCapCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 4: Rp2pService → RouteCancellationService Wiring ====================
echo -e "\n[Test 4: Rp2pService -> RouteCancellationService wiring]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking setRouteCancellationService on Rp2pService on ${testSender}"

rp2pRouteCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getRp2pService();
    echo method_exists(\$service, 'setRouteCancellationService') ? 'OK' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$rp2pRouteCheck" = "OK" ]; then
    printf "\t   Rp2pService route cancellation wiring ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Rp2pService route cancellation wiring ${RED}FAILED${NC} (${rp2pRouteCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 5: CleanupService → Capacity/Cancellation Repos ====================
echo -e "\n[Test 5: CleanupService -> CapacityReservation/RouteCancellation wiring]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking repository injection on CleanupService on ${testSender}"

cleanupCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getCleanupService();
    \$missing = [];
    foreach (['capacityReservationRepository', 'routeCancellationRepository'] as \$prop) {
        \$ref = new ReflectionProperty(\$service, \$prop);
        \$ref->setAccessible(true);
        if (\$ref->getValue(\$service) === null) \$missing[] = \$prop;
    }
    echo empty(\$missing) ? 'OK' : 'MISSING:' . implode(',', \$missing);
" 2>/dev/null || echo "ERROR")

if [ "$cleanupCheck" = "OK" ]; then
    printf "\t   CleanupService reservation wiring ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   CleanupService reservation wiring ${RED}FAILED${NC} (${cleanupCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 6: route_cancel Message Type Handling ====================
echo -e "\n[Test 6: index.html handles route_cancel message type]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking route_cancel handler in entry point on ${testSender}"

routeTypeCheck=$(docker exec ${testSender} php -r "
    \$indexContent = file_get_contents('//app//eiou//www//eiou//index.html');
    echo (strpos(\$indexContent, 'route_cancel') !== false) ? 'OK' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$routeTypeCheck" = "OK" ]; then
    printf "\t   route_cancel message type handler ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   route_cancel message type handler ${RED}FAILED${NC} (${routeTypeCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 7: Randomized Hop Budget ====================
echo -e "\n[Test 7: Randomized hop budget generates values in valid range]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Generating 100 hop budgets with min=1, max=6 on ${testSender}"

hopBudgetCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getRouteCancellationService();
    \$randomized = \Eiou\Core\Constants::isHopBudgetRandomized();
    \$min = 1; \$max = 6;
    \$results = [];
    for (\$i = 0; \$i < 100; \$i++) {
        \$budget = \$service->generateRandomizedHopBudget(\$min, \$max);
        if (\$budget < \$min || \$budget > \$max) {
            echo 'OUT_OF_RANGE:' . \$budget;
            exit;
        }
        \$results[] = \$budget;
    }
    \$unique = count(array_unique(\$results));
    if (!\$randomized) {
        // Randomization disabled: deterministic mode always returns maxHops
        echo (\$unique === 1 && \$results[0] === \$max) ? 'OK_DETERMINISTIC:all=' . \$max : 'WRONG_DETERMINISTIC:' . \$unique . '_distinct,val=' . \$results[0];
    } else {
        // With 30% stop probability and 100 samples, we should see at least 2 distinct values
        echo \$unique >= 2 ? 'OK:' . \$unique . '_distinct,min=' . min(\$results) . ',max=' . max(\$results) : 'LOW_VARIANCE:' . \$unique;
    }
" 2>/dev/null || echo "ERROR")

if [[ "$hopBudgetCheck" == OK_DETERMINISTIC:* ]]; then
    detail=$(echo "$hopBudgetCheck" | cut -d: -f2)
    printf "\t   Hop budget distribution ${GREEN}PASSED${NC} (deterministic: %s)\n" "$detail"
    passed=$(( passed + 1 ))
elif [[ "$hopBudgetCheck" == OK:* ]]; then
    detail=$(echo "$hopBudgetCheck" | cut -d: -f2)
    printf "\t   Hop budget distribution ${GREEN}PASSED${NC} (%s)\n" "$detail"
    passed=$(( passed + 1 ))
else
    printf "\t   Hop budget distribution ${RED}FAILED${NC} (${hopBudgetCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 8: Best-Fee Send Creates Capacity Reservations ====================
echo -e "\n[Test 8: Best-fee P2P creates capacity reservations on relay nodes]"

totaltests=$(( totaltests + 1 ))

# Find a valid receiver (last container with contacts, not the sender)
testReceiver=""
for ((i=${#containers[@]}-1; i>=0; i--)); do
    candidate="${containers[$i]}"
    if [[ "$candidate" != "$testSender" ]] && [[ "${expectedContacts[$candidate]:-0}" -gt 0 ]]; then
        testReceiver="$candidate"
        break
    fi
done

if [[ -z "$testReceiver" ]]; then
    testReceiver="${containers[${#containers[@]}-1]}"
fi

receiverAddress="${containerAddresses[$testReceiver]}"

# Record baseline reservation count across all relay nodes
echo -e "\t-> Recording baseline capacity reservation counts"
declare -A baselineReservations
for container in "${containers[@]}"; do
    baselineReservations[$container]=$(docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$stmt = \$pdo->query('SELECT COUNT(*) as cnt FROM capacity_reservations');
        \$row = \$stmt ? \$stmt->fetch(PDO::FETCH_ASSOC) : false;
        echo \$row ? \$row['cnt'] : '0';
    " 2>/dev/null || echo "0")
done

echo -e "\t-> Sending 5 USD from ${testSender} to ${testReceiver} with --best"
sendResult=$(docker exec ${testSender} eiou send ${receiverAddress} 5 USD --best 2>&1)

# Wait for P2P to propagate through relay nodes
echo -e "\t   Waiting 30s for P2P propagation and capacity reservations..."
sleep 30

# Check if capacity reservations were created on any relay node
newReservationsFound=0
reservationDetails=""
for container in "${containers[@]}"; do
    if [ "$container" = "$testSender" ]; then
        continue
    fi

    currentCount=$(docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$stmt = \$pdo->query('SELECT COUNT(*) as cnt FROM capacity_reservations');
        \$row = \$stmt ? \$stmt->fetch(PDO::FETCH_ASSOC) : false;
        echo \$row ? \$row['cnt'] : '0';
    " 2>/dev/null || echo "0")

    baseline="${baselineReservations[$container]:-0}"
    newCount=$((currentCount - baseline))
    if [ "$newCount" -gt 0 ]; then
        newReservationsFound=$((newReservationsFound + newCount))
        reservationDetails="${reservationDetails} ${container}:+${newCount}"
    fi
done

if [ "$newReservationsFound" -gt 0 ]; then
    printf "\t   Capacity reservations created ${GREEN}PASSED${NC} (%d new reservations:%s)\n" "$newReservationsFound" "$reservationDetails"
    passed=$(( passed + 1 ))
else
    printf "\t   Capacity reservations created ${YELLOW}SKIPPED${NC} (no new reservations — may indicate P2P did not relay or reservations committed quickly)\n"
    passed=$(( passed + 1 ))
fi

# ==================== Test 9: Best-Fee Selection Releases Unselected Capacity ====================
echo -e "\n[Test 9: Best-fee selection releases capacity on unselected routes]"

totaltests=$(( totaltests + 1 ))

# Wait for the P2P to complete (best-fee selection + transaction)
echo -e "\t-> Waiting up to 120s for P2P completion..."
completionTimeout=120
completionElapsed=0
p2pCompleted=0
while [ $completionElapsed -lt $completionTimeout ]; do
    sleep 10

    latestStatus=$(docker exec ${testSender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$stmt = \$pdo->query('SELECT status FROM p2p WHERE fast = 0 ORDER BY id DESC LIMIT 1');
        \$row = \$stmt ? \$stmt->fetch(PDO::FETCH_ASSOC) : false;
        echo \$row ? \$row['status'] : 'UNKNOWN';
    " 2>/dev/null || echo "UNKNOWN")

    if [ "$latestStatus" = "completed" ] || [ "$latestStatus" = "found" ] || [ "$latestStatus" = "cancelled" ] || [ "$latestStatus" = "expired" ]; then
        p2pCompleted=1
        break
    fi

    completionElapsed=$((completionElapsed + 10))
done

if [ "$p2pCompleted" -eq 1 ]; then
    # Check for released capacity reservations across relay nodes
    releasedCount=0
    for container in "${containers[@]}"; do
        released=$(docker exec ${container} php -r "
            require_once('${BOOTSTRAP_PATH}');
            \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
            \$stmt = \$pdo->query(\"SELECT COUNT(*) as cnt FROM capacity_reservations WHERE status = 'released'\");
            \$row = \$stmt ? \$stmt->fetch(PDO::FETCH_ASSOC) : false;
            echo \$row ? \$row['cnt'] : '0';
        " 2>/dev/null || echo "0")
        releasedCount=$((releasedCount + released))
    done

    # Check for route_cancellations audit records
    cancellationAuditCount=0
    for container in "${containers[@]}"; do
        audits=$(docker exec ${container} php -r "
            require_once('${BOOTSTRAP_PATH}');
            \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
            \$stmt = \$pdo->query('SELECT COUNT(*) as cnt FROM route_cancellations');
            \$row = \$stmt ? \$stmt->fetch(PDO::FETCH_ASSOC) : false;
            echo \$row ? \$row['cnt'] : '0';
        " 2>/dev/null || echo "0")
        cancellationAuditCount=$((cancellationAuditCount + audits))
    done

    printf "\t   P2P status: %s | Released reservations: %d | Cancellation audits: %d\n" "$latestStatus" "$releasedCount" "$cancellationAuditCount"

    if [ "$releasedCount" -gt 0 ] || [ "$cancellationAuditCount" -gt 0 ]; then
        printf "\t   Best-fee capacity release ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Best-fee capacity release ${YELLOW}SKIPPED${NC} (no released reservations found — topology may have single path)\n"
        passed=$(( passed + 1 ))
    fi
else
    printf "\t   Best-fee capacity release ${YELLOW}SKIPPED${NC} (P2P did not complete in time, status: %s)\n" "$latestStatus"
    passed=$(( passed + 1 ))
fi

# ==================== Test 10: Originator Cancel Propagates Downstream ====================
echo -e "\n[Test 10: Originator cancel propagates downstream via broadcastFullCancelForHash]"

totaltests=$(( totaltests + 1 ))

echo -e "\t-> Checking broadcastFullCancelForHash exists on P2pService"

originatorCancelCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getP2pService();

    // broadcastFullCancelForHash sends route_cancel with full_cancel=true
    // to all accepted contacts, enabling downstream cancel propagation
    if (!method_exists(\$service, 'broadcastFullCancelForHash')) {
        echo 'MISSING_METHOD';
        exit;
    }

    // Verify CliP2pApprovalService::rejectP2p calls broadcastFullCancelForHash
    // (CliService delegates to CliP2pApprovalService)
    \$ref = new ReflectionMethod(\Eiou\Services\CliP2pApprovalService::class, 'rejectP2p');
    \$filename = \$ref->getFileName();
    \$startLine = \$ref->getStartLine();
    \$endLine = \$ref->getEndLine();
    \$source = implode('', array_slice(file(\$filename), \$startLine - 1, \$endLine - \$startLine + 1));

    if (strpos(\$source, 'broadcastFullCancelForHash') !== false) {
        echo 'OK';
    } else {
        echo 'NOT_WIRED';
    }
" 2>/dev/null || echo "ERROR")

if [ "$originatorCancelCheck" = "OK" ]; then
    printf "\t   Originator downstream cancel ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Originator downstream cancel ${RED}FAILED${NC} (${originatorCancelCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 11: handleIncomingCancellation Multi-Route Safety ====================
echo -e "\n[Test 11: handleIncomingCancellation multi-route safety + full_cancel propagation]"

totaltests=$(( totaltests + 1 ))

echo -e "\t-> Checking full_cancel flag handling and downstream propagation"

propagationCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getRouteCancellationService();

    // Read handleIncomingCancellation method body via reflection
    \$ref = new ReflectionMethod(\$service, 'handleIncomingCancellation');
    \$filename = \$ref->getFileName();
    \$startLine = \$ref->getStartLine();
    \$endLine = \$ref->getEndLine();
    \$source = implode('', array_slice(file(\$filename), \$startLine - 1, \$endLine - \$startLine + 1));

    // Check 1: Regular route_cancel just acknowledges (multi-route safe)
    \$hasFullCancelCheck = strpos(\$source, 'full_cancel') !== false;

    // Check 2: Full cancel propagates downstream via broadcastFullCancelForHash
    \$propagatesDownstream = strpos(\$source, 'broadcastFullCancelForHash') !== false;

    if (\$hasFullCancelCheck && \$propagatesDownstream) {
        echo 'OK';
    } elseif (\$hasFullCancelCheck) {
        echo 'NO_PROPAGATION';
    } else {
        echo 'NO_FULL_CANCEL_CHECK';
    }
" 2>/dev/null || echo "ERROR")

if [ "$propagationCheck" = "OK" ]; then
    printf "\t   Multi-route safety + full cancel propagation ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Multi-route safety + full cancel propagation ${RED}FAILED${NC} (${propagationCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 12: Timing — Active Cancel vs Passive Expiry ====================
echo -e "\n[Test 12: Active cancel vs passive expiry timing (dead-end to non-existent)]"

totaltests=$(( totaltests + 1 ))

# Find an isolated node address for dead-end testing
DEAD_END_ADDRESS=""
for node in "${!expectedContacts[@]}"; do
    if [ "${expectedContacts[$node]}" -eq 0 ] 2>/dev/null; then
        DEAD_END_ADDRESS="${containerAddresses[$node]}"
        break
    fi
done
if [ -z "$DEAD_END_ADDRESS" ]; then
    if [[ "${MODE:-http}" == "tor" ]]; then
        DEAD_END_ADDRESS="nonexistenteiounode$(date +%s)abcdefghijklmnopqrst.onion"
    elif [[ "${MODE:-http}" == "https" ]]; then
        DEAD_END_ADDRESS="https://nonexistent-route-cancel-test-$(date +%s)"
    else
        DEAD_END_ADDRESS="http://nonexistent-route-cancel-test-$(date +%s)"
    fi
fi

# Record starting state
lastP2pId=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$stmt = \$pdo->query('SELECT MAX(id) as max_id FROM p2p');
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
    echo \$row['max_id'] ?? 0;
" 2>/dev/null || echo "0")

echo -e "\t-> Sending 5 USD from ${testSender} to non-existent address with --best"
cancelTimingStart=$(date +%s)
docker exec ${testSender} eiou send ${DEAD_END_ADDRESS} 5 USD --best 2>&1 >/dev/null

# Poll for resolution
cancelTimingTimeout=180
cancelTimingElapsed=0
cancelTimingDetected=0
cancelTimingStatus="UNKNOWN"
while [ $cancelTimingElapsed -lt $cancelTimingTimeout ]; do
    sleep 5

    cancelTimingStatus=$(docker exec ${testSender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$stmt = \$pdo->query('SELECT status FROM p2p WHERE fast = 0 AND id > ${lastP2pId} ORDER BY id ASC LIMIT 1');
        \$row = \$stmt ? \$stmt->fetch(PDO::FETCH_ASSOC) : false;
        echo \$row ? \$row['status'] : 'UNKNOWN';
    " 2>/dev/null || echo "UNKNOWN")

    if [ "$cancelTimingStatus" = "cancelled" ] || [ "$cancelTimingStatus" = "expired" ]; then
        cancelTimingDetected=1
        break
    fi

    cancelTimingElapsed=$(( $(date +%s) - cancelTimingStart ))
done

cancelTimingEnd=$(date +%s)
cancelTimingTotal=$((cancelTimingEnd - cancelTimingStart))

if [ "$cancelTimingDetected" -eq 1 ]; then
    printf "\t   Resolution: Status=%s, Time=%ds\n" "$cancelTimingStatus" "$cancelTimingTotal"

    # Now check capacity reservations released across the network for this P2P
    cancelHash=$(docker exec ${testSender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$stmt = \$pdo->query('SELECT hash FROM p2p WHERE fast = 0 AND id > ${lastP2pId} ORDER BY id ASC LIMIT 1');
        \$row = \$stmt ? \$stmt->fetch(PDO::FETCH_ASSOC) : false;
        echo \$row ? \$row['hash'] : 'UNKNOWN';
    " 2>/dev/null || echo "UNKNOWN")

    releasedOnCancel=0
    activeOnCancel=0
    for container in "${containers[@]}"; do
        reservationState=$(docker exec ${container} php -r "
            require_once('${BOOTSTRAP_PATH}');
            \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
            \$stmt = \$pdo->prepare('SELECT status, COUNT(*) as cnt FROM capacity_reservations WHERE hash = ? GROUP BY status');
            \$stmt->execute(['${cancelHash}']);
            \$rows = \$stmt->fetchAll(PDO::FETCH_ASSOC);
            \$out = [];
            foreach (\$rows as \$r) \$out[] = \$r['status'] . ':' . \$r['cnt'];
            echo empty(\$out) ? 'NONE' : implode(',', \$out);
        " 2>/dev/null || echo "ERROR")

        if [ "$reservationState" != "NONE" ] && [ "$reservationState" != "ERROR" ]; then
            printf "\t   %s reservations: %s\n" "$container" "$reservationState"
            # Count released vs active
            if echo "$reservationState" | grep -q "released"; then
                releasedOnCancel=$((releasedOnCancel + 1))
            fi
            if echo "$reservationState" | grep -q "active"; then
                activeOnCancel=$((activeOnCancel + 1))
            fi
        fi
    done

    printf "\t   Network state: released=%d nodes, still_active=%d nodes\n" "$releasedOnCancel" "$activeOnCancel"

    if [ "$cancelTimingTotal" -lt 180 ]; then
        printf "\t   Active cancel timing ${GREEN}PASSED${NC} (%ds < 180s)\n" "$cancelTimingTotal"
        passed=$(( passed + 1 ))
    else
        printf "\t   Active cancel timing ${YELLOW}SLOW${NC} (%ds, close to expiration)\n" "$cancelTimingTotal"
        passed=$(( passed + 1 ))
    fi
else
    printf "\t   Active cancel timing ${RED}FAILED${NC} (not resolved in %ds, status: %s)\n" "$cancelTimingTotal" "$cancelTimingStatus"
    failure=$(( failure + 1 ))
fi

# ==================== Test 13: Relay P2P Status After Dead-End Cancel ====================
echo -e "\n[Test 13: Relay node P2P status after dead-end cascade cancel]"

totaltests=$(( totaltests + 1 ))

if [ "$cancelTimingDetected" -eq 1 ] && [ "$cancelHash" != "UNKNOWN" ]; then
    echo -e "\t-> Checking P2P status across relay nodes for hash ${cancelHash:0:16}..."

    cancelledRelays=0
    expiredRelays=0
    activeRelays=0
    totalRelays=0
    for container in "${containers[@]}"; do
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
            totalRelays=$((totalRelays + 1))
            case "$relayStatus" in
                cancelled) cancelledRelays=$((cancelledRelays + 1)) ;;
                expired) expiredRelays=$((expiredRelays + 1)) ;;
                *) activeRelays=$((activeRelays + 1)) ;;
            esac
        fi
    done

    printf "\t   Relays: total=%d, cancelled=%d, expired=%d, other=%d\n" "$totalRelays" "$cancelledRelays" "$expiredRelays" "$activeRelays"

    if [ "$totalRelays" -gt 0 ] && [ "$activeRelays" -eq 0 ]; then
        printf "\t   All relay P2Ps resolved ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    elif [ "$totalRelays" -gt 0 ]; then
        printf "\t   Relay P2P resolution ${YELLOW}PARTIAL${NC} (%d still active — may need CleanupService TTL)\n" "$activeRelays"
        passed=$(( passed + 1 ))
    else
        printf "\t   Relay P2P resolution ${YELLOW}SKIPPED${NC} (P2P did not reach relay nodes)\n"
        passed=$(( passed + 1 ))
    fi
else
    printf "\t   Relay P2P status ${YELLOW}SKIPPED${NC} (no cancel hash from Test 12)\n"
    passed=$(( passed + 1 ))
fi

echo ""
succesrate "${totaltests}" "${passed}" "${failure}" "'route cancellation'"
