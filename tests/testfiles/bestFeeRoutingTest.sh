#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Best Fee Routing Test ############################
# Tests best-fee P2P route selection feature (issue #596)
#
# Verifies:
# - Database schema includes new columns (fast, contacts_sent_count, contacts_responded_count)
# - rp2p_candidates table exists and is accessible
# - Service methods for best-fee routing are present
# - Fast mode (--fast) P2P completes successfully (backward compatibility)
# - Best-fee mode (no --fast) P2P completes successfully
# - P2P tracking columns are populated after send
# - Already-relayed collision handling method exists
#
# Prerequisites:
# - Containers must be running
# - Contacts must be established (run addContactsTest first)
# - Best tested with collisions topology for multiple route paths
###############################################################################

testname="bestFeeRoutingTest"
totaltests=0
passed=0
failure=0

# Select sender and receiver containers
# Use first container and last connected container (skip any without contacts)
testSender="${containers[0]}"
testReceiver=""
for ((i=${#containers[@]}-1; i>=0; i--)); do
    candidate="${containers[$i]}"
    if [[ "$candidate" != "$testSender" ]] && [[ -n "${expectedContacts[$candidate]}" ]]; then
        testReceiver="$candidate"
        break
    fi
done

# Fallback: use last container if no expectedContacts defined
if [[ -z "$testReceiver" ]]; then
    testReceiver="${containers[-1]}"
fi

echo -e "\nTesting best-fee routing features..."
echo -e "Sender: ${testSender}, Receiver: ${testReceiver}"

# ==================== Test 1: P2P Table Schema ====================
echo -e "\n[Test 1: P2P table new columns]"

for container in "$testSender" "$testReceiver"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\t-> Checking p2p table schema on ${container}"

    schemaCheck=$(docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$stmt = \$pdo->query('DESCRIBE p2p');
        \$columns = array_column(\$stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        \$required = ['fast', 'contacts_sent_count', 'contacts_responded_count'];
        \$missing = array_diff(\$required, \$columns);
        echo empty(\$missing) ? 'OK' : 'MISSING:' . implode(',', \$missing);
    " 2>/dev/null || echo "ERROR")

    if [ "$schemaCheck" = "OK" ]; then
        printf "\t   P2P columns on ${container} ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   P2P columns on ${container} ${RED}FAILED${NC} (${schemaCheck})\n"
        failure=$(( failure + 1 ))
    fi
done

# ==================== Test 2: rp2p_candidates Table ====================
echo -e "\n[Test 2: rp2p_candidates table exists]"

for container in "$testSender" "$testReceiver"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\t-> Checking rp2p_candidates table on ${container}"

    tableCheck=$(docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        try {
            \$pdo->query('SELECT COUNT(*) FROM rp2p_candidates');
            echo 'OK';
        } catch (Exception \$e) {
            echo 'MISSING';
        }
    " 2>/dev/null || echo "ERROR")

    if [ "$tableCheck" = "OK" ]; then
        printf "\t   rp2p_candidates table on ${container} ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   rp2p_candidates table on ${container} ${RED}FAILED${NC} (${tableCheck})\n"
        failure=$(( failure + 1 ))
    fi
done

# ==================== Test 3: Rp2pCandidateRepository Methods ====================
echo -e "\n[Test 3: Rp2pCandidateRepository service methods]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking Rp2pCandidateRepository methods on ${testSender}"

repoCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$repo = \$app->services->getRp2pCandidateRepository();
    \$methods = ['insertCandidate', 'getCandidatesByHash', 'getBestCandidate', 'getCandidateCount', 'deleteCandidatesByHash'];
    \$missing = [];
    foreach (\$methods as \$m) {
        if (!method_exists(\$repo, \$m)) \$missing[] = \$m;
    }
    echo empty(\$missing) ? 'OK' : 'MISSING:' . implode(',', \$missing);
" 2>/dev/null || echo "ERROR")

if [ "$repoCheck" = "OK" ]; then
    printf "\t   Rp2pCandidateRepository methods ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Rp2pCandidateRepository methods ${RED}FAILED${NC} (${repoCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 4: Rp2pService Best-Fee Methods ====================
echo -e "\n[Test 4: Rp2pService best-fee methods]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking Rp2pService methods on ${testSender}"

rp2pCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$service = \$app->services->getRp2pService();
    \$methods = ['selectAndForwardBestRp2p', 'handleRp2pCandidate'];
    \$missing = [];
    foreach (\$methods as \$m) {
        if (!method_exists(\$service, \$m)) \$missing[] = \$m;
    }
    echo empty(\$missing) ? 'OK' : 'MISSING:' . implode(',', \$missing);
" 2>/dev/null || echo "ERROR")

if [ "$rp2pCheck" = "OK" ]; then
    printf "\t   Rp2pService best-fee methods ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Rp2pService best-fee methods ${RED}FAILED${NC} (${rp2pCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 5: P2pPayload Already-Relayed Method ====================
echo -e "\n[Test 5: P2pPayload already-relayed handler]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking buildAlreadyRelayed method on ${testSender}"

payloadCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$payload = new \Eiou\Schemas\Payloads\P2pPayload(
        \$app->services->getCurrentUser(),
        \$app->services->getUtilityContainer()
    );
    echo method_exists(\$payload, 'buildAlreadyRelayed') ? 'OK' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$payloadCheck" = "OK" ]; then
    printf "\t   buildAlreadyRelayed method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   buildAlreadyRelayed method ${RED}FAILED${NC} (${payloadCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 6: P2pRepository Tracking Methods ====================
echo -e "\n[Test 6: P2pRepository tracking methods]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking P2pRepository tracking methods on ${testSender}"

p2pRepoCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$repo = \$app->services->getP2pRepository();
    \$methods = ['updateContactsSentCount', 'incrementContactsRespondedCount', 'getTrackingCounts'];
    \$missing = [];
    foreach (\$methods as \$m) {
        if (!method_exists(\$repo, \$m)) \$missing[] = \$m;
    }
    echo empty(\$missing) ? 'OK' : 'MISSING:' . implode(',', \$missing);
" 2>/dev/null || echo "ERROR")

if [ "$p2pRepoCheck" = "OK" ]; then
    printf "\t   P2pRepository tracking methods ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   P2pRepository tracking methods ${RED}FAILED${NC} (${p2pRepoCheck})\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 7: Fast Mode Send ====================
echo -e "\n[Test 7: Fast mode send (--fast)]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Sending 5 USD from ${testSender} to ${testReceiver} with --fast"

# Get initial balance of receiver
initialBalanceFast=$(docker exec ${testReceiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$balance = \Eiou\Core\Application::getInstance()->services->getBalanceRepository()->getUserBalanceCurrency('USD');
    echo \$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR ?: '0';
" 2>/dev/null || echo "0")

# Send with --fast flag (first route wins)
fastSendResult=$(docker exec ${testSender} eiou send ${containerAddresses[${testReceiver}]} 5 USD --fast 2>&1)

# Wait for routing with polling
echo -e "\t   Waiting for fast mode routing (timeout: 30s)..."
balance_cmd="php -r \"
    require_once('${BOOTSTRAP_PATH}');
    \\\$balance = \Eiou\Core\Application::getInstance()->services->getBalanceRepository()->getUserBalanceCurrency('USD');
    echo \\\$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR ?: '0';
\""

newBalanceFast=$(wait_for_balance_change "${testReceiver}" "$initialBalanceFast" "$balance_cmd" 30 "fast mode send")

# Retry with queue processing if needed
balanceChangedFast=$(awk "BEGIN {print ($newBalanceFast != $initialBalanceFast) ? 1 : 0}")
if [ "$balanceChangedFast" -eq 0 ]; then
    echo -e "\t   Balance unchanged, retrying with queue processing..."
    all_containers="${containers[*]}"
    for attempt in 1 2 3 4; do
        process_routing_queues "$all_containers"
    done
    newBalanceFast=$(docker exec ${testReceiver} sh -c "$balance_cmd" 2>/dev/null || echo "$initialBalanceFast")
    balanceChangedFast=$(awk "BEGIN {print ($newBalanceFast != $initialBalanceFast) ? 1 : 0}")
fi

if [ "$balanceChangedFast" -eq 1 ]; then
    printf "\t   Fast mode send ${GREEN}PASSED${NC} (Balance: %s -> %s)\n" "$initialBalanceFast" "$newBalanceFast"
    passed=$(( passed + 1 ))
else
    printf "\t   Fast mode send ${RED}FAILED${NC} (Balance unchanged: %s)\n" "$initialBalanceFast"
    printf "\t   Send result: %s\n" "${fastSendResult:0:200}"
    failure=$(( failure + 1 ))
fi

# ==================== Test 8: P2P Tracking Columns Populated ====================
echo -e "\n[Test 8: P2P tracking columns populated after send]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking contacts_sent_count on ${testSender}"

trackingCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$stmt = \$pdo->query('SELECT contacts_sent_count, fast FROM p2p WHERE contacts_sent_count > 0 LIMIT 1');
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
    if (\$row) {
        echo 'OK:sent=' . \$row['contacts_sent_count'] . ',fast=' . \$row['fast'];
    } else {
        echo 'NONE';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$trackingCheck" == OK:* ]]; then
    printf "\t   P2P tracking columns ${GREEN}PASSED${NC} (${trackingCheck#OK:})\n"
    passed=$(( passed + 1 ))
else
    printf "\t   P2P tracking columns ${RED}FAILED${NC} (no P2P records with tracking data)\n"
    failure=$(( failure + 1 ))
fi

# ==================== Test 9: Best-Fee Mode Send ====================
echo -e "\n[Test 9: Best-fee mode send (no --fast)]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Sending 5 USD from ${testSender} to ${testReceiver} without --fast (best-fee mode)"

# Get initial balance of receiver
initialBalanceBest=$(docker exec ${testReceiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$balance = \Eiou\Core\Application::getInstance()->services->getBalanceRepository()->getUserBalanceCurrency('USD');
    echo \$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR ?: '0';
" 2>/dev/null || echo "0")

# Send WITHOUT --fast (best-fee mode: collect all rp2p responses, pick cheapest)
bestFeeSendResult=$(docker exec ${testSender} eiou send ${containerAddresses[${testReceiver}]} 5 USD 2>&1)

# Best-fee mode: RP2P candidates are collected at the originator.
# Not all contacts may respond (paths blocked by already_relayed collisions),
# so selection is triggered by P2P expiration cleanup. We simulate that here
# by processing queues, then force-expiring the P2P and triggering cleanup.

echo -e "\t   Waiting for P2P propagation and RP2P collection (timeout: 30s)..."
all_containers="${containers[*]}"

# Process queues to propagate P2P and collect RP2P candidates
for attempt in 1 2 3 4 5 6; do
    process_routing_queues "$all_containers"
done

# Force-expire the best-fee P2P on ALL containers to trigger candidate selection.
# Each node collects RP2P candidates from its downstream contacts and picks the best.
# By expiring on all nodes, we trigger this selection at every hop simultaneously.
echo -e "\t   Triggering best-fee selection via forced expiration on all nodes..."
for container in "${containers[@]}"; do
    docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$pdo->exec('UPDATE p2p SET expiration = 1 WHERE fast = 0 AND status IN (\"queued\", \"sent\")');
        \$app->services->getCleanupService()->processCleanupMessages();
    " 2>/dev/null || true
done

# Process queues to propagate the best RP2P selections back through the network
for attempt in 1 2 3 4 5 6; do
    process_routing_queues "$all_containers"
done

# Check balance change
newBalanceBest=$(docker exec ${testReceiver} sh -c "$balance_cmd" 2>/dev/null || echo "$initialBalanceBest")
balanceChangedBest=$(awk "BEGIN {print ($newBalanceBest != $initialBalanceBest) ? 1 : 0}")

if [ "$balanceChangedBest" -eq 1 ]; then
    printf "\t   Best-fee mode send ${GREEN}PASSED${NC} (Balance: %s -> %s)\n" "$initialBalanceBest" "$newBalanceBest"
    passed=$(( passed + 1 ))
else
    printf "\t   Best-fee mode send ${RED}FAILED${NC} (Balance unchanged: %s)\n" "$initialBalanceBest"
    printf "\t   Send result: %s\n" "${bestFeeSendResult:0:200}"
    failure=$(( failure + 1 ))
fi

# ==================== Test 10: Best-Fee P2P Has fast=0 ====================
echo -e "\n[Test 10: Best-fee P2P stored with fast=0]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Checking P2P records for fast=0 on ${testSender}"

fastFlagCheck=$(docker exec ${testSender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$stmt = \$pdo->query('SELECT COUNT(*) as cnt FROM p2p WHERE fast = 0');
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
    echo (\$row && \$row['cnt'] > 0) ? 'OK:' . \$row['cnt'] : 'NONE';
" 2>/dev/null || echo "ERROR")

if [[ "$fastFlagCheck" == OK:* ]]; then
    count=$(echo "$fastFlagCheck" | cut -d: -f2)
    printf "\t   Best-fee P2P records (fast=0) ${GREEN}PASSED${NC} (count=%s)\n" "$count"
    passed=$(( passed + 1 ))
else
    printf "\t   Best-fee P2P records (fast=0) ${RED}FAILED${NC} (no P2P with fast=0 found)\n"
    failure=$(( failure + 1 ))
fi

succesrate "${totaltests}" "${passed}" "${failure}" "'best-fee routing'"
