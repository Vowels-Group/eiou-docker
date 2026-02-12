#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Best Fee Routing Test ############################
# Tests best-fee P2P route selection feature (issue #596)
#
# Verifies:
# - Database schema includes new columns (fast, contacts_sent_count, contacts_responded_count)
# - rp2p_candidates table exists and is accessible
# - Service methods for best-fee routing are present
# - Fast mode (default, no flag) P2P completes successfully
# - Best-fee mode (--best) P2P completes successfully
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
    if [[ "$candidate" != "$testSender" ]] && [[ "${expectedContacts[$candidate]:-0}" -gt 0 ]]; then
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
        \$required = ['fast', 'contacts_sent_count', 'contacts_responded_count', 'hop_wait'];
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
echo -e "\n[Test 7: Fast mode send (default)]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Sending 5 USD from ${testSender} to ${testReceiver} (default fast mode)"

# Get initial balance of receiver
initialBalanceFast=$(docker exec ${testReceiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$balance = \Eiou\Core\Application::getInstance()->services->getBalanceRepository()->getUserBalanceCurrency('USD');
    echo \$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR ?: '0';
" 2>/dev/null || echo "0")

# Send without flags (default fast mode: first route wins)
fastStartTime=$(date +%s)
fastSendResult=$(docker exec ${testSender} eiou send ${containerAddresses[${testReceiver}]} 5 USD 2>&1)

# Wait for routing with polling
# Tor needs longer timeout for fast mode too (30s transport per hop vs 15s for HTTP)
fastTimeout=$( [[ "${MODE:-http}" == "tor" ]] && echo 90 || echo 30 )
echo -e "\t   Waiting for fast mode routing (timeout: ${fastTimeout}s)..."
balance_cmd="php -r \"
    require_once('${BOOTSTRAP_PATH}');
    \\\$balance = \Eiou\Core\Application::getInstance()->services->getBalanceRepository()->getUserBalanceCurrency('USD');
    echo \\\$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR ?: '0';
\""

newBalanceFast=$(wait_for_balance_change "${testReceiver}" "$initialBalanceFast" "$balance_cmd" "$fastTimeout" "fast mode send")

# Retry: wait for daemon processing
balanceChangedFast=$(awk "BEGIN {print ($newBalanceFast != $initialBalanceFast) ? 1 : 0}")
if [ "$balanceChangedFast" -eq 0 ]; then
    echo -e "\t   Balance unchanged, waiting for daemon processing..."
    sleep 10
    newBalanceFast=$(docker exec ${testReceiver} sh -c "$balance_cmd" 2>/dev/null || echo "$initialBalanceFast")
    balanceChangedFast=$(awk "BEGIN {print ($newBalanceFast != $initialBalanceFast) ? 1 : 0}")
fi

fastEndTime=$(date +%s)
fastElapsed=$((fastEndTime - fastStartTime))

if [ "$balanceChangedFast" -eq 1 ]; then
    printf "\t   Fast mode send ${GREEN}PASSED${NC} (Balance: %s -> %s, Time: %ds)\n" "$initialBalanceFast" "$newBalanceFast" "$fastElapsed"
    passed=$(( passed + 1 ))
else
    printf "\t   Fast mode send ${RED}FAILED${NC} (Balance unchanged: %s, Time: %ds)\n" "$initialBalanceFast" "$fastElapsed"
    printf "\t   Send result: %s\n" "${fastSendResult:0:200}"
    failure=$(( failure + 1 ))
fi

# Trace fast mode path (for comparison with best-fee later)
fastPath=""
fastMultiplier="1.000000"
if [ "$balanceChangedFast" -eq 1 ]; then
    fastHash=$(docker exec ${testSender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$stmt = \$pdo->query('SELECT hash FROM p2p WHERE fast = 1 ORDER BY id DESC LIMIT 1');
        \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
        echo \$row ? \$row['hash'] : 'UNKNOWN';
    " 2>/dev/null || echo "UNKNOWN")

    if [ "$fastHash" != "UNKNOWN" ]; then
        fastPath=$(trace_actual_path "$fastHash" "$testSender" "$testReceiver")
        # Compute compound fee multiplier for fast path (exclude destination)
        IFS=' ' read -ra fastHops <<< "${fastPath//->/ }"
        for ((i=0; i<${#fastHops[@]}-1; i++)); do
            from="${fastHops[$i]}"
            to="${fastHops[$((i+1))]}"
            if [ "$to" != "$testReceiver" ]; then
                linkFee=$(echo "${containersLinks[$from,$to]}" | awk '{print $1}')
                fastMultiplier=$(awk "BEGIN {printf \"%.6f\", $fastMultiplier * (1 + ${linkFee:-0}/100)}")
            fi
        done
        printf "\t   Fast path: %s (fee multiplier: %s)\n" "$fastPath" "$fastMultiplier"
    fi
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
echo -e "\n[Test 9: Best-fee mode send (--best)]"

totaltests=$(( totaltests + 1 ))

# --- Best-fee expiration sizing math ---
#
# Use the real-world default P2P expiration (300s) so relay nodes have adequate
# hop-wait time for best-fee candidate collection. The test still finishes fast
# when paths work (waits for balance change, not the full expiration).
#
# Constants (from Constants.php):
#   MIN_HOP_WAIT  = 15s  (P2P_MIN_HOP_WAIT_SECONDS = HTTP_TRANSPORT_TIMEOUT)
#   HOP_DIVISOR   = 12   (P2P_HOP_WAIT_DIVISOR, fixed divisor in hopWait formula for privacy)
#   HOP_BUFFER    = 2s   (P2P_HOP_PROCESSING_BUFFER_SECONDS)
#   DEFAULT_MAX   = 6    (P2P_DEFAULT_MAX_REQUEST_LEVEL, per-user setting)
#   JITTER        = +0/1 (random_int(0,1) added to maxLevel)
#
# hopWait = max(floor(expiration / HOP_DIVISOR) - HOP_BUFFER, MIN_HOP_WAIT)
#   300s expiration: floor(300/12)-2 = 23s per hop
#
# Relay expirations (scaledWait = hopWait × remainingHops, A0→A11 shortest = 4 hops):
#   A1/A2 (3 remaining): 23 × 3 = 69s
#   A4/A5 (2 remaining): 23 × 2 = 46s
#   A8    (1 remaining): 23 × 1 = 23s
#
# Each level gets ~23s breathing room before its upstream expires.
#
# Tor mode needs higher expiration because inter-node messages travel over Tor
# (30s timeout per request), so the RP2P cascade propagation is slower.
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

echo -e "\t-> Sending 5 USD from ${testSender} to ${testReceiver} with --best (best-fee mode)"

# Get initial balance of receiver
initialBalanceBest=$(docker exec ${testReceiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$balance = \Eiou\Core\Application::getInstance()->services->getBalanceRepository()->getUserBalanceCurrency('USD');
    echo \$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR ?: '0';
" 2>/dev/null || echo "0")

# Start timing benchmark
sendStartTime=$(date +%s)

# Send WITH --best (best-fee mode: collect all rp2p responses, pick cheapest)
bestFeeSendResult=$(docker exec ${testSender} eiou send ${containerAddresses[${testReceiver}]} 5 USD --best 2>&1)

# Best-fee mode with per-hop expiration:
# - Originator defines hopWait = floor(expiration/max_routing_level) - buffer
# - Relay nodes use hopWait as their P2P expiration (much shorter than originator's)
# - Leaf nodes expire first → select best candidate → forward upstream
# - Each level cascades until originator gets all best routes
# - Originator's full expiration is the backstop
#
# We process queues continuously and wait for natural P2P expiration to trigger
# candidate selection at each hop. The total time should be well under the
# originator's expiration since relay nodes expire after just hopWait seconds.

# Timeout must exceed: expiration + grace period (30s) + processing headroom (30s).
# The cleanup fallback triggers at expiration + grace period, so the test timeout
# must comfortably exceed that to avoid false failures at the boundary.
gracePeriod=30
headroom=30
bestfeeTimeout=$((testExpiration + gracePeriod + headroom))
echo -e "\t   Waiting for best-fee routing via daemon processing (timeout: ${bestfeeTimeout}s)..."

elapsed=0
balanceChangedBest=0
while [ $elapsed -lt $bestfeeTimeout ]; do
    sleep 3

    # Check if balance changed (early exit)
    newBalanceBest=$(docker exec ${testReceiver} sh -c "$balance_cmd" 2>/dev/null || echo "$initialBalanceBest")
    balanceChangedBest=$(awk "BEGIN {print ($newBalanceBest != $initialBalanceBest) ? 1 : 0}")
    if [ "$balanceChangedBest" -eq 1 ]; then
        break
    fi

    elapsed=$(( $(date +%s) - sendStartTime ))
done

# Calculate benchmark time
sendEndTime=$(date +%s)
bestfeeElapsed=$((sendEndTime - sendStartTime))

if [ "$balanceChangedBest" -eq 1 ]; then
    printf "\t   Best-fee mode send ${GREEN}PASSED${NC} (Balance: %s -> %s, Time: %ds)\n" "$initialBalanceBest" "$newBalanceBest" "$bestfeeElapsed"
    passed=$(( passed + 1 ))
else
    printf "\t   Best-fee mode send ${RED}FAILED${NC} (Balance unchanged: %s, Timeout after %ds)\n" "$initialBalanceBest" "$bestfeeElapsed"
    printf "\t   Send result: %s\n" "${bestFeeSendResult:0:200}"
    failure=$(( failure + 1 ))
fi

# Timing comparison: fast vs best-fee
echo -e "\n   --- Routing Mode Comparison ---"
printf "\t   Fast mode (first route):  %ds\n" "$fastElapsed"
printf "\t   Best-fee mode (optimal):  %ds\n" "$bestfeeElapsed"
if [ "$bestfeeElapsed" -gt 0 ] && [ "$fastElapsed" -gt 0 ]; then
    overhead=$((bestfeeElapsed - fastElapsed))
    printf "\t   Best-fee overhead:        +%ds\n" "$overhead"
fi
# Path comparison is printed after path analysis below (needs bestFeePath/bestFeeMultiplier)

# ==================== Path Analysis for Best-Fee Test ====================
echo -e "\n   --- Path Analysis ---"

# Print the randomized fee structure for this run
echo -e "\t   Fee structure (randomized per run):"
for key in $(echo "${!containersLinks[@]}" | tr ' ' '\n' | sort); do
    from="${key%%,*}"
    to="${key##*,}"
    # Only print one direction per edge (from < to alphabetically)
    if [[ "$from" < "$to" ]]; then
        linkFee=$(echo "${containersLinks[$key]}" | awk '{print $1}')
        linkMultiplier=$(awk "BEGIN {printf \"%.3f\", 1 + $linkFee/100}")
        printf "\t     %s <-> %s : %s%% (%sx)\n" "$from" "$to" "$linkFee" "$linkMultiplier"
    fi
done

# Show all possible paths with compound fee multipliers
# Fee = product of (1 + feePercent/100) at each relay hop; destination excluded
echo -e "\n\t   All paths from ${testSender} to ${testReceiver} (by compound fee multiplier):"
allPaths=$(enumerate_paths "$testSender" "$testReceiver")
bestFee=$(echo "$allPaths" | head -1 | grep -oP 'fee=\K[0-9.]+')

# Count how many paths share the best fee
bestCount=0
while IFS= read -r pathLine; do
    pathFee=$(echo "$pathLine" | grep -oP 'fee=\K[0-9.]+')
    if [ "$pathFee" = "$bestFee" ]; then
        bestCount=$((bestCount + 1))
    fi
done <<< "$allPaths"

while IFS= read -r pathLine; do
    pathFee=$(echo "$pathLine" | grep -oP 'fee=\K[0-9.]+')
    pathRoute=$(echo "$pathLine" | sed 's/ fee=.*//')
    if [ "$pathFee" = "$bestFee" ]; then
        if [ "$bestCount" -gt 1 ]; then
            printf "\t   ${GREEN}* %-40s %sx [TIED BEST]${NC}\n" "$pathRoute" "$pathFee"
        else
            printf "\t   ${GREEN}* %-40s %sx [BEST]${NC}\n" "$pathRoute" "$pathFee"
        fi
    else
        printf "\t     %-40s %sx\n" "$pathRoute" "$pathFee"
    fi
done <<< "$allPaths"

# Trace actual path taken (only if test passed)
bestFeePath=""
bestFeeMultiplier="1.000000"
if [ "$balanceChangedBest" -eq 1 ]; then
    # Get the P2P hash from the originator (most recent best-fee P2P)
    bestFeeHash=$(docker exec ${testSender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$stmt = \$pdo->query('SELECT hash FROM p2p WHERE fast = 0 ORDER BY id DESC LIMIT 1');
        \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
        echo \$row ? \$row['hash'] : 'UNKNOWN';
    " 2>/dev/null || echo "UNKNOWN")

    if [ "$bestFeeHash" != "UNKNOWN" ]; then
        bestFeePath=$(trace_actual_path "$bestFeeHash" "$testSender" "$testReceiver")
        # Compute compound fee multiplier for actual path (exclude destination)
        IFS=' ' read -ra actualHops <<< "${bestFeePath//->/ }"
        for ((i=0; i<${#actualHops[@]}-1; i++)); do
            from="${actualHops[$i]}"
            to="${actualHops[$((i+1))]}"
            if [ "$to" != "$testReceiver" ]; then
                linkFee=$(echo "${containersLinks[$from,$to]}" | awk '{print $1}')
                bestFeeMultiplier=$(awk "BEGIN {printf \"%.6f\", $bestFeeMultiplier * (1 + ${linkFee:-0}/100)}")
            fi
        done
        printf "\n\t   Best-fee path: ${GREEN}%s${NC} (%sx)\n" "$bestFeePath" "$bestFeeMultiplier"
        # Compare with enumerated best (float comparison via awk)
        isOptimal=$(awk "BEGIN {printf \"%d\", ($bestFeeMultiplier <= $bestFee * 1.000001) ? 1 : 0}")
        if [ "$isOptimal" -eq 1 ]; then
            printf "\t   ${GREEN}Route matches optimal best-fee path${NC}\n"
        else
            printf "\t   ${YELLOW}Route does NOT match optimal (best=%sx, actual=%sx)${NC}\n" "$bestFee" "$bestFeeMultiplier"
        fi
    fi
fi

# Path comparison: fast vs best-fee
if [ -n "$fastPath" ] && [ -n "$bestFeePath" ]; then
    echo -e "\n   --- Path Comparison ---"
    printf "\t   Fast path:     %-30s %sx\n" "$fastPath" "$fastMultiplier"
    printf "\t   Best-fee path: %-30s %sx\n" "$bestFeePath" "$bestFeeMultiplier"
    printf "\t   Optimal fee:   %-30s %sx\n" "" "$bestFee"

    # Check optimality of each route against enumerated best
    fastIsOptimal=$(awk "BEGIN {printf \"%d\", ($fastMultiplier <= $bestFee * 1.000001) ? 1 : 0}")
    bestFeeIsOptimal=$(awk "BEGIN {printf \"%d\", ($bestFeeMultiplier <= $bestFee * 1.000001) ? 1 : 0}")

    # Compare: did best-fee find a cheaper route than fast?
    betterRoute=$(awk "BEGIN {printf \"%d\", ($bestFeeMultiplier < $fastMultiplier - 0.000001) ? 1 : 0}")
    sameRoute=$(awk "BEGIN {printf \"%d\", ($bestFeeMultiplier >= $fastMultiplier - 0.000001 && $bestFeeMultiplier <= $fastMultiplier + 0.000001) ? 1 : 0}")

    if [ "$betterRoute" -eq 1 ]; then
        savings=$(awk "BEGIN {printf \"%.4f\", $fastMultiplier - $bestFeeMultiplier}")
        if [ "$bestFeeIsOptimal" -eq 1 ]; then
            printf "\t   ${GREEN}Best-fee found the optimal route (saved %sx vs fast)${NC}\n" "$savings"
            printf "\t   RESULT: OPTIMAL\n"
        else
            printf "\t   ${GREEN}Best-fee found a cheaper route (saved %sx), but not optimal (best=%sx)${NC}\n" "$savings" "$bestFee"
            printf "\t   RESULT: BETTER (not optimal)\n"
        fi
    elif [ "$sameRoute" -eq 1 ]; then
        if [ "$bestFeeIsOptimal" -eq 1 ]; then
            printf "\t   ${GREEN}Both modes found the same route — and it is the optimal path${NC}\n"
            printf "\t   RESULT: SAME (optimal)\n"
        else
            printf "\t   ${YELLOW}Both modes found the same route — but it is sub-optimal (best=%sx)${NC}\n" "$bestFee"
            printf "\t   RESULT: SAME (sub-optimal)\n"
        fi
    else
        extra=$(awk "BEGIN {printf \"%.4f\", $bestFeeMultiplier - $fastMultiplier}")
        if [ "$fastIsOptimal" -eq 1 ]; then
            printf "\t   ${YELLOW}Fast mode found the optimal route; best-fee did not (+%sx)${NC}\n" "$extra"
        else
            printf "\t   ${YELLOW}Fast mode found a cheaper route than best-fee (+%sx), neither is optimal (best=%sx)${NC}\n" "$extra" "$bestFee"
        fi
        printf "\t   RESULT: WORSE (fast won)\n"
    fi
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

# ==================== Test 11: Dead-End Cascade Cancel ====================
echo -e "\n[Test 11: Dead-end cascade cancel (non-existent recipient)]"

totaltests=$(( totaltests + 1 ))
echo -e "\t-> Sending 5 USD from ${testSender} to non-existent address with --best (expect fast cancel)"

cancelStartTime=$(date +%s)
cancelSendResult=$(docker exec ${testSender} eiou send ${containerAddresses[A12]} 5 USD --best 2>&1)

# With cascade cancel, dead-end nodes cancel immediately and propagate upstream.
# The originator should see cancellation well before full expiration.
cancelTimeout=$((testExpiration / 2))
echo -e "\t   Waiting for cascade cancel (timeout: ${cancelTimeout}s, expiration: ${testExpiration}s)..."

elapsed=0
cancelDetected=0
while [ $elapsed -lt $cancelTimeout ]; do
    sleep 3

    # Check if the P2P was cancelled on the originator
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

echo ""
succesrate "${totaltests}" "${passed}" "${failure}" "'best-fee routing'"
