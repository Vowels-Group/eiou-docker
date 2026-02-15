#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC
#
# Routing performance benchmark for the collisionscluster topology.
# Systematically measures routing across protocols, hop distances, and routing modes.
#
# Tests fast mode (first route wins) vs best-fee mode (collect all routes, pick cheapest)
# at 1-hop, 3-hop, and 6-hop distances from C0, measuring:
#   - Delivery time (wall clock + DB-derived breakdowns)
#   - Path taken
#   - Fee multiplier
#   - Optimality (vs enumerated best path)
#
# Timing breakdown (per send):
#
#   |-- CLI overhead --|------- P2P search -------|---- settlement ----|-- poll jitter --|
#   |                  |                          |                    |                 |
#   eiou send     P2P created_at            P2P created_at       P2P completed_at   balance
#   (wall clock)  on SENDER                 on TARGET            on SENDER           detected
#                  |                          |                    |
#                  |<-------- p2p_time -------------------------------->|
#                  |<-- search_time --------->|<-- settle_time --->|
#   |<----------------------- wall_time ------------------------------------------>|
#
#   wall_time    = date before eiou send → balance change detected (includes poll jitter)
#   p2p_time     = p2p.created_at → p2p.completed_at on SENDER (full P2P round-trip)
#   search_time  = p2p.created_at on SENDER → p2p.created_at on TARGET (network propagation)
#   settle_time  = p2p.created_at on TARGET → p2p.completed_at on SENDER (tx chain back)
#
# Usage: ./benchmark-routing.sh [runs] [protocols] [topology] [send_mode]
#   runs      - Number of runs per condition (default: 10)
#   protocols - Comma-separated protocols (default: http,https,tor)
#   topology  - "shared" (default) or "rebuild"
#               shared:  build once, reuse across all protocols (same fees, faster)
#               rebuild: fresh topology per protocol (different random fees each time)
#   send_mode - "serial" (default) or "burst"
#               serial: send one at a time, poll for completion, collect results, repeat
#               burst:  fire all runs at once, wait for all to complete, collect from DB
#                       keeps processors warm (100ms polling), measures true throughput
#
# Examples:
#   ./benchmark-routing.sh 10                              # Full benchmark, serial
#   ./benchmark-routing.sh 3 http                          # Quick test, HTTP only
#   ./benchmark-routing.sh 5 http,https                    # HTTP and HTTPS only
#   ./benchmark-routing.sh 10 http,https,tor rebuild       # Fresh build per protocol
#   ./benchmark-routing.sh 3 http shared burst             # Burst mode, HTTP only
#   ./benchmark-routing.sh 10 http,https shared burst      # Burst mode, multi-protocol
#
# Total sends: protocols × 3 distances × 2 modes × runs
# With shared topology at $5/send, 180 sends = $900 total — well under $1000 credit limit.

set -e

RUNS="${1:-10}"
IFS=',' read -ra _INPUT_PROTOCOLS <<< "${2:-http,https,tor}"
TOPOLOGY_MODE="${3:-shared}"
SEND_MODE="${4:-serial}"
BENCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Sort protocols so tor always runs last — Tor needs extra startup time and
# running it first risks skewed results from incomplete Tor connectivity.
PROTOCOLS=()
for _p in "${_INPUT_PROTOCOLS[@]}"; do
    [ "$_p" != "tor" ] && PROTOCOLS+=("$_p")
done
for _p in "${_INPUT_PROTOCOLS[@]}"; do
    [ "$_p" = "tor" ] && PROTOCOLS+=("tor")
done
unset _INPUT_PROTOCOLS _p

# Source base configuration (colors, helpers, BOOTSTRAP_PATH)
cd "$BENCH_DIR"
. "${BENCH_DIR}/baseconfig/config.sh"

# Additional color codes
CYAN='\033[0;36m'
DIM='\033[2m'
BOLD='\033[1m'

SENDER="C0"
SEND_AMOUNT=5
SEND_CURRENCY="USD"

# Configurable targets (BFS-verified min-hop from C0 in collisionscluster)
# E1:  1 hop — direct neighbor, simple path
# MH:  3 hops — mesh hub, reachable via skip connections (e.g., C0→E1→E3→MH)
# LN3: 6 hops — linear branch dead end (e.g., C0→N1→N2→N4→LN1→LN2→LN3)
DISTANCES=(1 3 6)
declare -A TARGETS=( [1]="E1" [3]="MH" [6]="LN3" )
declare -A DISTANCE_LABELS=( [1]="1 hop" [3]="3 hops" [6]="6 hops" )

# Results storage: keyed by "${protocol}_${distance}_${mode}_${run}"
declare -A R_TIME R_PASS R_PATH R_FEE R_OPTIMAL R_P2P_TIME R_SEARCH_TIME R_SETTLE_TIME

# Optimal RP2P amount in cents per target per protocol build: "${protocol}_${distance}"
# Uses integer arithmetic matching production calculateFee to avoid float/int mismatch.
declare -A OPTIMAL_FEES

# Shared optimal amounts (used in shared topology mode): "${distance}"
declare -A _SHARED_OPTIMAL_FEE

# Enumerated paths per target: "${protocol}_${distance}" → raw enumerate_paths_limited output
declare -A ENUM_PATHS _SHARED_ENUM_PATHS

# ======================== Helper Functions ========================

# Build adjacency list from containersLinks for fast neighbor lookup.
# Without this, DFS scans all 148 links at every node. With it, each node
# only iterates its 3-4 actual neighbors.
# Populates: ADJ_NEIGHBORS[node]="N1 N2 N3" and ADJ_FEE[from,to]="0.5"
declare -A ADJ_NEIGHBORS ADJ_FEE
build_adjacency_list() {
    ADJ_NEIGHBORS=()
    ADJ_FEE=()
    for key in "${!containersLinks[@]}"; do
        local from="${key%%,*}"
        local to="${key##*,}"
        local fee=$(echo "${containersLinks[$key]}" | awk '{print $1}')
        ADJ_NEIGHBORS[$from]="${ADJ_NEIGHBORS[$from]:+${ADJ_NEIGHBORS[$from]} }${to}"
        ADJ_FEE[$key]="$fee"
    done
}

# Depth-limited path enumeration using pre-built adjacency list.
# Usage: enumerate_paths_limited <source> <dest> [max_depth]
# max_depth defaults to 10 (covers our 6-hop target with margin).
enumerate_paths_limited() {
    local source="$1"
    local dest="$2"
    local max_depth="${3:-10}"
    _dfs_limited "$source" "$dest" "$source" ",${source}," "1.000000" 0 "$max_depth" | sort -t= -k2 -n
}

# Internal DFS with depth limit — uses ADJ_NEIGHBORS/ADJ_FEE for O(degree) per node
_dfs_limited() {
    local current="$1" dest="$2" path="$3"
    local visited="$4" multiplier="$5" depth="$6" max_depth="$7"

    if [ "$current" = "$dest" ]; then
        printf "%s fee=%s\n" "$path" "$multiplier"
        return
    fi
    [ "$depth" -ge "$max_depth" ] && return

    for to in ${ADJ_NEIGHBORS[$current]}; do
        [[ "$visited" == *",$to,"* ]] && continue
        local fee="${ADJ_FEE[$current,$to]}"
        if [ "$to" = "$dest" ]; then
            _dfs_limited "$to" "$dest" "${path}->${to}" "${visited}${to}," "$multiplier" $((depth+1)) "$max_depth"
        else
            local new_m=$(awk "BEGIN {printf \"%.6f\", $multiplier * (1 + ${fee:-0}/100)}")
            _dfs_limited "$to" "$dest" "${path}->${to}" "${visited}${to}," "$new_m" $((depth+1)) "$max_depth"
        fi
    done
}

# Compute compound fee multiplier for a traced path (floating-point, for display)
# Fee = product of (1 + feePercent/100) at each relay hop; destination excluded
# Usage: compute_path_fee "C0->E1->E3->MH" "MH"
compute_path_fee() {
    local path_str="$1"
    local receiver="$2"
    local multiplier="1.000000"
    IFS=' ' read -ra hops <<< "${path_str//->/ }"
    for ((i=0; i<${#hops[@]}-1; i++)); do
        local from="${hops[$i]}"
        local to="${hops[$((i+1))]}"
        if [ "$to" != "$receiver" ]; then
            local link_fee=$(echo "${containersLinks[$from,$to]}" | awk '{print $1}')
            multiplier=$(awk "BEGIN {printf \"%.6f\", $multiplier * (1 + ${link_fee:-0}/100)}")
        fi
    done
    echo "$multiplier"
}

# Compute total RP2P amount in cents using integer arithmetic matching production.
# Simulates CurrencyUtilityService::calculateFee per relay hop:
#   hop_fee = (int) round((amount / 100) * (feePercent_stored / 100))
# where feePercent_stored = link_fee * 100 (link_fee is 0.2 for 0.2%).
# This simplifies to: hop_fee = round(amount * link_fee / 100)
# Usage: compute_path_integer_amount "C0->S1->S3->MH" "MH" 500
compute_path_integer_amount() {
    local path_str="$1"
    local receiver="$2"
    local amount="$3"  # cents
    IFS=' ' read -ra hops <<< "${path_str//->/ }"
    for ((i=0; i<${#hops[@]}-1; i++)); do
        local from="${hops[$i]}"
        local to="${hops[$((i+1))]}"
        if [ "$to" != "$receiver" ]; then
            local link_fee=$(echo "${containersLinks[$from,$to]}" | awk '{print $1}')
            local hop_fee=$(awk "BEGIN {printf \"%.0f\", $amount * ${link_fee:-0} / 100}")
            amount=$((amount + hop_fee))
        fi
    done
    echo "$amount"
}

# Wait for all containers to initialize (simplified from run-all-tests.sh)
wait_for_containers() {
    local mode="$1"
    local max_wait=${EIOU_INIT_TIMEOUT:-120}

    printf "\nWaiting for containers to initialize (timeout: ${max_wait}s per container)...\n"

    for container in "${containers[@]}"; do
        printf "  ${container}... "
        local elapsed=0

        while [ $elapsed -lt $max_wait ]; do
            if ! docker ps --format '{{.Names}}' | grep -q "^${container}$"; then
                printf "${RED}stopped!${NC}\n"
                return 1
            fi

            if [ "$mode" = "tor" ]; then
                local tor_addr=$(docker exec "$container" php -r '
                    if (file_exists("/etc/eiou/config/userconfig.json")) {
                        $json = json_decode(file_get_contents("/etc/eiou/config/userconfig.json"), true);
                        if(isset($json["torAddress"])) echo $json["torAddress"];
                    }' 2>/dev/null)
                if [ -n "$tor_addr" ]; then
                    if docker exec "$container" curl --socks5-hostname 127.0.0.1:9050 \
                        --connect-timeout 5 --max-time 10 --silent --fail --output /dev/null \
                        "$tor_addr" 2>/dev/null; then
                        printf "${GREEN}ready${NC}\n"
                        break
                    fi
                fi
            else
                local hfield="hostname"
                [ "$mode" = "https" ] && hfield="hostname_secure"
                local addr=$(docker exec "$container" php -r "
                    if (file_exists('/etc/eiou/config/userconfig.json')) {
                        \$j = json_decode(file_get_contents('/etc/eiou/config/userconfig.json'), true);
                        if (isset(\$j['$hfield'])) echo \$j['$hfield'];
                    }" 2>/dev/null)
                if [ -n "$addr" ]; then
                    local procs=$(docker logs "$container" 2>&1 | grep -c "message processing started successfully" | tr -d '[:space:]' || echo "0")
                    procs=${procs:-0}
                    if [ "$procs" -ge 2 ] 2>/dev/null; then
                        printf "${GREEN}ready${NC}\n"
                        break
                    fi
                fi
            fi

            sleep 2
            elapsed=$((elapsed + 2))
        done

        if [ $elapsed -ge $max_wait ]; then
            printf "${RED}timeout!${NC}\n"
            return 1
        fi
    done

    printf "${GREEN}${CHECK} All containers initialized${NC}\n"
    sleep 1
}

# Populate containerAddresses from running containers
populate_addresses() {
    local mode="$1"
    printf "\nPopulating container addresses...\n"

    for container in "${containers[@]}"; do
        if [ "$mode" = "tor" ]; then
            containerAddresses[$container]=$(docker exec $container php -r '
                $json = json_decode(file_get_contents("'"${USERCONFIG}"'"),true);
                if(isset($json["torAddress"])) echo $json["torAddress"];
            ' 2>/dev/null)
        elif [ "$mode" = "https" ]; then
            containerAddresses[$container]=$(docker exec $container php -r '
                $json = json_decode(file_get_contents("'"${USERCONFIG}"'"),true);
                if(isset($json["hostname_secure"])) echo $json["hostname_secure"];
            ' 2>/dev/null)
        else
            containerAddresses[$container]=$(docker exec $container php -r '
                $json = json_decode(file_get_contents("'"${USERCONFIG}"'"),true);
                if(isset($json["hostname"])) echo $json["hostname"];
            ' 2>/dev/null)
        fi
    done

    printf "  ${SENDER} address: ${containerAddresses[$SENDER]:-MISSING}\n"
    for distance in "${DISTANCES[@]}"; do
        local t="${TARGETS[$distance]}"
        printf "  ${t} address: ${containerAddresses[$t]:-MISSING}\n"
    done
}

# Establish contacts from containersLinks array
establish_contacts() {
    printf "\nEstablishing contacts...\n"
    local link_keys=($(for x in "${!containersLinks[@]}"; do echo "$x"; done | sort))
    local total=${#link_keys[@]}
    local count=0

    for key in "${link_keys[@]}"; do
        local values=(${containersLinks[$key]})
        local pair=(${key//,/ })
        count=$((count + 1))
        printf "\r  Contact %d/%d: %s -> %s    " "$count" "$total" "${pair[0]}" "${pair[1]}"
        docker exec ${pair[0]} eiou add ${containerAddresses[${pair[1]}]} ${pair[1]} ${values[0]} ${values[1]} ${values[2]} 2>&1 >/dev/null || true
    done
    printf "\n"

    # Wait for contacts to be processed (53-node topology needs time)
    printf "  Waiting for contact processing...\n"
    sleep 15

    # Verify sample contact
    local first_key="${link_keys[0]}"
    local first_pair=(${first_key//,/ })
    local addr="${containerAddresses[${first_pair[1]}]}"
    local transport=$(getPhpTransportType "$addr")
    local status=$(docker exec ${first_pair[0]} php -r "
        require_once('${BOOTSTRAP_PATH}');
        echo \Eiou\Core\Application::getInstance()->services->getContactRepository()->getContactStatus('$transport','$addr');
    " 2>/dev/null || echo "unknown")
    printf "  Sample contact %s->%s status: %s\n" "${first_pair[0]}" "${first_pair[1]}" "$status"
}

# Register a new transport address with already-established contacts (one-sided).
# After the initial double-sided HTTP add establishes mutual contacts, a one-sided
# add of a new transport address (https/tor) triggers automatic address exchange —
# the remote side learns the sender's new address without needing its own add call.
add_transport_addresses() {
    local protocol="$1"
    printf "\nRegistering ${protocol} addresses with existing contacts...\n"
    local link_keys=($(for x in "${!containersLinks[@]}"; do echo "$x"; done | sort))
    local count=0
    declare -A _seen_pairs

    for key in "${link_keys[@]}"; do
        local values=(${containersLinks[$key]})
        local pair=(${key//,/ })
        # Only one direction per pair — automatic exchange handles the reverse
        local reverse="${pair[1]},${pair[0]}"
        if [ -n "${_seen_pairs[$reverse]}" ]; then
            continue
        fi
        _seen_pairs[$key]=1
        count=$((count + 1))
        printf "\r  ${protocol} %d: %s -> %s    " "$count" "${pair[0]}" "${pair[1]}"
        docker exec ${pair[0]} eiou add ${containerAddresses[${pair[1]}]} ${pair[1]} ${values[0]} ${values[1]} ${values[2]} 2>&1 >/dev/null || true
    done
    printf "\n  Waiting for address exchange...\n"
    sleep 10
}

# Execute a single send and record results
do_send() {
    local protocol="$1"
    local distance="$2"
    local mode="$3"    # "fast" or "best"
    local run="$4"
    local timeout="$5"

    local target="${TARGETS[$distance]}"
    local key="${protocol}_${distance}_${mode}_${run}"
    local receiver_addr="${containerAddresses[$target]}"

    # Balance command template
    local balance_cmd="php -r \"
        require_once('${BOOTSTRAP_PATH}');
        \\\$balance = \Eiou\Core\Application::getInstance()->services->getBalanceRepository()->getUserBalanceCurrency('USD');
        echo \\\$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR ?: '0';
    \""

    # Get initial balance
    local initial=$(docker exec $target sh -c "$balance_cmd" 2>/dev/null || echo "0")

    # Get max P2P ID before send (to identify our P2P record later)
    local max_id=$(docker exec $SENDER php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        echo \$pdo->query('SELECT COALESCE(MAX(id),0) FROM p2p')->fetchColumn();
    " 2>/dev/null || echo "0")

    # Send (EIOU_TEST_MODE disables CLI rate limiter — benchmarks exceed 30 sends/min)
    local start_time=$(date +%s)
    if [ "$mode" = "fast" ]; then
        docker exec -e EIOU_TEST_MODE=true $SENDER eiou send $receiver_addr $SEND_AMOUNT $SEND_CURRENCY 2>&1 >/dev/null || true
    else
        docker exec -e EIOU_TEST_MODE=true $SENDER eiou send $receiver_addr $SEND_AMOUNT $SEND_CURRENCY --best 2>&1 >/dev/null || true
    fi

    # Wait for balance change — uniform polling for both modes.
    # Use the full timeout (P2P expiration) so we capture outliers and slow deliveries
    # rather than prematurely marking them as failures.
    # Poll every 2s for better wall-clock granularity (DB timestamps are jitter-free).
    local new_balance
    local balance_changed=0
    local elapsed_wait=0
    while [ $elapsed_wait -lt $timeout ]; do
        sleep 2
        new_balance=$(docker exec $target sh -c "$balance_cmd" 2>/dev/null || echo "$initial")
        balance_changed=$(awk "BEGIN {print ($new_balance != $initial) ? 1 : 0}")
        if [ "$balance_changed" -eq 1 ]; then
            break
        fi
        elapsed_wait=$(( $(date +%s) - start_time ))
    done

    local end_time=$(date +%s)
    local elapsed=$((end_time - start_time))

    # Trace path, compute fee, and extract DB timestamps if send succeeded
    local path="" fee="0" is_optimal="N/A"
    local p2p_time="" search_time="" settle_time=""
    if [ "$balance_changed" -eq 1 ]; then
        local fast_flag=$( [ "$mode" = "fast" ] && echo 1 || echo 0 )
        local hash=$(docker exec $SENDER php -r "
            require_once('${BOOTSTRAP_PATH}');
            \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
            \$stmt = \$pdo->query('SELECT hash FROM p2p WHERE id > $max_id AND fast = $fast_flag ORDER BY id ASC LIMIT 1');
            \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
            echo \$row ? \$row['hash'] : 'UNKNOWN';
        " 2>/dev/null || echo "UNKNOWN")

        if [ "$hash" != "UNKNOWN" ]; then
            # Wait for P2P completion to propagate back to sender.
            # Balance changes on TARGET before completed_at is set on SENDER —
            # the confirmation chain must travel back through all relay hops.
            # This gap is especially noticeable over Tor multi-hop routes.
            local _cw=0
            while [ $_cw -lt 30 ]; do
                local _done=$(docker exec $SENDER php -r "
                    require_once('${BOOTSTRAP_PATH}');
                    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
                    \$stmt = \$pdo->prepare('SELECT completed_at FROM p2p WHERE hash = ?');
                    \$stmt->execute(['$hash']);
                    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
                    echo (\$row && \$row['completed_at']) ? '1' : '0';
                " 2>/dev/null || echo "0")
                [ "$_done" = "1" ] && break
                sleep 1
                _cw=$((_cw + 1))
            done

            path=$(trace_actual_path "$hash" "$SENDER" "$target")

            # Query P2P timestamps from sender: created_at and completed_at
            local sender_ts=$(docker exec $SENDER php -r "
                require_once('${BOOTSTRAP_PATH}');
                \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
                \$stmt = \$pdo->prepare('SELECT created_at, completed_at FROM p2p WHERE hash = ?');
                \$stmt->execute(['$hash']);
                \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
                if (\$row) {
                    echo (\$row['created_at'] ?? '') . '|' . (\$row['completed_at'] ?? '');
                }
            " 2>/dev/null || echo "")

            # Query P2P timestamps from target: created_at
            local target_ts=$(docker exec $target php -r "
                require_once('${BOOTSTRAP_PATH}');
                \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
                \$stmt = \$pdo->prepare('SELECT created_at FROM p2p WHERE hash = ?');
                \$stmt->execute(['$hash']);
                \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
                if (\$row) echo \$row['created_at'] ?? '';
            " 2>/dev/null || echo "")

            # Parse timestamps and compute timing breakdowns (seconds with 1 decimal)
            local sender_created="${sender_ts%%|*}"
            local sender_completed="${sender_ts##*|}"

            if [ -n "$sender_created" ] && [ -n "$sender_completed" ]; then
                p2p_time=$(docker exec $SENDER php -r "
                    \$c = strtotime('$sender_created');
                    \$d = strtotime('$sender_completed');
                    if (\$c && \$d) printf('%.1f', \$d - \$c);
                " 2>/dev/null || echo "")
            fi

            if [ -n "$sender_created" ] && [ -n "$target_ts" ]; then
                search_time=$(docker exec $SENDER php -r "
                    \$c = strtotime('$sender_created');
                    \$t = strtotime('$target_ts');
                    if (\$c && \$t) printf('%.1f', \$t - \$c);
                " 2>/dev/null || echo "")
            fi

            if [ -n "$target_ts" ] && [ -n "$sender_completed" ]; then
                settle_time=$(docker exec $SENDER php -r "
                    \$t = strtotime('$target_ts');
                    \$d = strtotime('$sender_completed');
                    if (\$t && \$d) printf('%.1f', \$d - \$t);
                " 2>/dev/null || echo "")
            fi
        fi

        # Direct sends (1 hop) have no relay chain — trace returns empty.
        # Fall back to the obvious direct path.
        if [ -z "$path" ]; then
            path="${SENDER}->${target}"
        fi
        fee=$(compute_path_fee "$path" "$target")

        # Compare using integer amounts (matching production calculateFee rounding)
        # to avoid false SUB-OPT from float/int mismatch on paths with equal integer cost.
        local actual_amount=$(compute_path_integer_amount "$path" "$target" $((SEND_AMOUNT * 100)))
        local optimal="${OPTIMAL_FEES[${protocol}_${distance}]}"
        if [ -n "$optimal" ] && [ "$optimal" != "" ]; then
            is_optimal=$([ "$actual_amount" -le "$optimal" ] && echo "OPTIMAL" || echo "SUB-OPT")
        fi
    fi

    R_TIME[$key]="$elapsed"
    R_PASS[$key]="$balance_changed"
    R_PATH[$key]="$path"
    R_FEE[$key]="$fee"
    R_OPTIMAL[$key]="$is_optimal"
    R_P2P_TIME[$key]="${p2p_time:-}"
    R_SEARCH_TIME[$key]="${search_time:-}"
    R_SETTLE_TIME[$key]="${settle_time:-}"

    # Print per-send line with full context
    local dist_label="${DISTANCE_LABELS[$distance]}"
    if [ "$balance_changed" -eq 1 ]; then
        local opt_color="${YELLOW}"
        [ "$is_optimal" = "OPTIMAL" ] && opt_color="${GREEN}"
        local timing_detail=""
        if [ -n "$p2p_time" ]; then
            timing_detail=" (p2p:${p2p_time}s"
            [ -n "$search_time" ] && timing_detail="${timing_detail} srch:${search_time}s"
            [ -n "$settle_time" ] && timing_detail="${timing_detail} sttl:${settle_time}s"
            timing_detail="${timing_detail})"
        fi
        printf "[%-5s] Run %2d/%d | %-4s | %-6s -> %-4s | %3ds%-28s | %-35s | %sx | ${opt_color}%s${NC}\n" \
            "$protocol" "$run" "$RUNS" "$mode" "$dist_label" "$target" "$elapsed" "$timing_detail" "$path" "$fee" "$is_optimal"
    else
        printf "[%-5s] Run %2d/%d | %-4s | %-6s -> %-4s | %3ds | ${RED}FAILED${NC}\n" \
            "$protocol" "$run" "$RUNS" "$mode" "$dist_label" "$target" "$elapsed"
    fi
}

# Execute all runs for one distance+mode combination in burst mode.
# Three phases: fire all sends, wait for completion, collect per-run results from DB.
do_burst() {
    local protocol="$1"
    local distance="$2"
    local mode="$3"    # "fast" or "best"
    local timeout="$4"

    local target="${TARGETS[$distance]}"
    local receiver_addr="${containerAddresses[$target]}"
    local fast_flag=$( [ "$mode" = "fast" ] && echo 1 || echo 0 )
    local dist_label="${DISTANCE_LABELS[$distance]}"

    # Balance command template
    local balance_cmd="php -r \"
        require_once('${BOOTSTRAP_PATH}');
        \\\$balance = \Eiou\Core\Application::getInstance()->services->getBalanceRepository()->getUserBalanceCurrency('USD');
        echo \\\$balance/\Eiou\Core\Constants::TRANSACTION_USD_CONVERSION_FACTOR ?: '0';
    \""

    # ---- Phase 1: Fire all sends ----
    # Record max P2P ID before any sends (to identify our records later)
    local max_id=$(docker exec $SENDER php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        echo \$pdo->query('SELECT COALESCE(MAX(id),0) FROM p2p')->fetchColumn();
    " 2>/dev/null || echo "0")

    # Record initial balance on target
    local initial_balance=$(docker exec $target sh -c "$balance_cmd" 2>/dev/null || echo "0")

    declare -a _burst_fire_time
    for ((run=1; run<=RUNS; run++)); do
        _burst_fire_time[$run]=$(date +%s)
        if [ "$mode" = "fast" ]; then
            docker exec -e EIOU_TEST_MODE=true $SENDER eiou send $receiver_addr $SEND_AMOUNT $SEND_CURRENCY 2>&1 >/dev/null || true
        else
            docker exec -e EIOU_TEST_MODE=true $SENDER eiou send $receiver_addr $SEND_AMOUNT $SEND_CURRENCY --best 2>&1 >/dev/null || true
        fi
        printf "[%-5s] Fired %2d/%d | %-4s | %-6s -> %-4s\n" \
            "$protocol" "$run" "$RUNS" "$mode" "$dist_label" "$target"
    done

    # ---- Phase 2: Wait for all to complete ----
    # 1-hop direct sends bypass the P2P table (standard transactions only).
    # Detect which mode applies, then poll accordingly.
    local wait_start=$(date +%s)
    local completed=0
    local failed=0
    local _use_p2p=1

    # Brief pause for P2P records to appear (if they will)
    sleep 2
    local p2p_total=$(docker exec $SENDER php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        echo \$pdo->query('SELECT COUNT(*) FROM p2p WHERE id > $max_id AND fast = $fast_flag')->fetchColumn();
    " 2>/dev/null || echo "0")

    if [ "$p2p_total" -eq 0 ]; then
        _use_p2p=0
        # Direct sends — poll target balance (same approach as serial do_send)
        while true; do
            local elapsed_wait=$(( $(date +%s) - wait_start ))
            if [ $elapsed_wait -ge $timeout ]; then break; fi
            local current_balance=$(docker exec $target sh -c "$balance_cmd" 2>/dev/null || echo "$initial_balance")
            local bal_diff=$(awk "BEGIN {print ($current_balance != $initial_balance) ? 1 : 0}")
            if [ "$bal_diff" -eq 1 ]; then
                completed=$RUNS
                break
            fi
            printf "\r  Waiting: balance check (%ds)...  " "$elapsed_wait"
            sleep 2
        done
    else
        # P2P records exist — poll for completion/failure
        while [ $((completed + failed)) -lt $RUNS ]; do
            local elapsed_wait=$(( $(date +%s) - wait_start ))
            if [ $elapsed_wait -ge $timeout ]; then break; fi

            completed=$(docker exec $SENDER php -r "
                require_once('${BOOTSTRAP_PATH}');
                \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
                echo \$pdo->query('SELECT COUNT(*) FROM p2p WHERE id > $max_id AND fast = $fast_flag AND completed_at IS NOT NULL')->fetchColumn();
            " 2>/dev/null || echo "0")

            failed=$(docker exec $SENDER php -r "
                require_once('${BOOTSTRAP_PATH}');
                \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
                echo \$pdo->query(\"SELECT COUNT(*) FROM p2p WHERE id > $max_id AND fast = $fast_flag AND status IN ('cancelled','expired')\")->fetchColumn();
            " 2>/dev/null || echo "0")

            printf "\r  Waiting: %d/%d completed (%ds)...  " "$completed" "$RUNS" "$elapsed_wait"
            sleep 2
        done
    fi
    printf "\r  Waiting: %d/%d completed, %d failed (%ds)     \n" "$completed" "$RUNS" "$failed" "$(( $(date +%s) - wait_start ))"

    # ---- Phase 3: Collect per-run results ----
    if [ "$_use_p2p" -eq 0 ]; then
        # Direct sends (no P2P records) — results from transactions.
        # These complete synchronously during fire; no timing breakdown available.
        local fee=$(compute_path_fee "${SENDER}->${target}" "$target")
        local actual_amount=$(compute_path_integer_amount "${SENDER}->${target}" "$target" $((SEND_AMOUNT * 100)))
        local optimal="${OPTIMAL_FEES[${protocol}_${distance}]}"
        local is_optimal="N/A"
        if [ -n "$optimal" ] && [ "$optimal" != "" ]; then
            is_optimal=$([ "$actual_amount" -le "$optimal" ] && echo "OPTIMAL" || echo "SUB-OPT")
        fi
        local opt_color="${YELLOW}"
        [ "$is_optimal" = "OPTIMAL" ] && opt_color="${GREEN}"

        for ((run=1; run<=RUNS; run++)); do
            local key="${protocol}_${distance}_${mode}_${run}"
            local fire_time="${_burst_fire_time[$run]}"
            # Wall time: fire to next fire (last run: ~0s since send is synchronous)
            local next_fire="${_burst_fire_time[$((run+1))]:-${_burst_fire_time[$run]}}"
            local elapsed=$((next_fire - fire_time))

            R_TIME[$key]="$elapsed"
            R_PASS[$key]="1"
            R_PATH[$key]="${SENDER}->${target}"
            R_FEE[$key]="$fee"
            R_OPTIMAL[$key]="$is_optimal"
            R_P2P_TIME[$key]=""
            R_SEARCH_TIME[$key]=""
            R_SETTLE_TIME[$key]=""

            printf "[%-5s] Run %2d/%d | %-4s | %-6s -> %-4s | %3ds%-28s | %-35s | %sx | ${opt_color}%s${NC}\n" \
                "$protocol" "$run" "$RUNS" "$mode" "$dist_label" "$target" "$elapsed" "" "${SENDER}->${target}" "$fee" "$is_optimal"
        done
    else
        # P2P sends — collect from P2P table
        local hash_data=$(docker exec $SENDER php -r "
            require_once('${BOOTSTRAP_PATH}');
            \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
            \$stmt = \$pdo->query('SELECT hash, created_at, completed_at FROM p2p WHERE id > $max_id AND fast = $fast_flag ORDER BY id ASC');
            while (\$row = \$stmt->fetch(PDO::FETCH_ASSOC)) {
                echo \$row['hash'] . '|' . (\$row['created_at'] ?? '') . '|' . (\$row['completed_at'] ?? '') . \"\n\";
            }
        " 2>/dev/null || echo "")

        # Parse hash_data into arrays
        local -a _burst_hashes _burst_sender_created _burst_sender_completed
        local hash_count=0
        while IFS='|' read -r h_hash h_created h_completed; do
            [ -z "$h_hash" ] && continue
            hash_count=$((hash_count + 1))
            _burst_hashes[$hash_count]="$h_hash"
            _burst_sender_created[$hash_count]="$h_created"
            _burst_sender_completed[$hash_count]="$h_completed"
        done <<< "$hash_data"

        # Process each run
        for ((run=1; run<=RUNS; run++)); do
            local key="${protocol}_${distance}_${mode}_${run}"
            local hash="${_burst_hashes[$run]:-}"
            local sender_created="${_burst_sender_created[$run]:-}"
            local sender_completed="${_burst_sender_completed[$run]:-}"
            local fire_time="${_burst_fire_time[$run]}"

            if [ -z "$hash" ] || [ -z "$sender_completed" ]; then
                # No hash found or not completed — mark as failed
                local elapsed=$(( $(date +%s) - fire_time ))
                R_TIME[$key]="$elapsed"
                R_PASS[$key]="0"
                R_PATH[$key]=""
                R_FEE[$key]="0"
                R_OPTIMAL[$key]="N/A"
                R_P2P_TIME[$key]=""
                R_SEARCH_TIME[$key]=""
                R_SETTLE_TIME[$key]=""
                printf "[%-5s] Run %2d/%d | %-4s | %-6s -> %-4s | %3ds | ${RED}FAILED${NC}\n" \
                    "$protocol" "$run" "$RUNS" "$mode" "$dist_label" "$target" "$elapsed"
                continue
            fi

            # Wall time: fire_time to completed_at epoch
            local completed_epoch=$(docker exec $SENDER php -r "
                echo strtotime('$sender_completed');
            " 2>/dev/null || echo "0")
            local elapsed=$((completed_epoch - fire_time))
            [ "$elapsed" -lt 0 ] && elapsed=0

            # Trace path
            local path=$(trace_actual_path "$hash" "$SENDER" "$target")
            if [ -z "$path" ]; then
                path="${SENDER}->${target}"
            fi
            local fee=$(compute_path_fee "$path" "$target")

            # Optimality check
            local is_optimal="N/A"
            local actual_amount=$(compute_path_integer_amount "$path" "$target" $((SEND_AMOUNT * 100)))
            local optimal="${OPTIMAL_FEES[${protocol}_${distance}]}"
            if [ -n "$optimal" ] && [ "$optimal" != "" ]; then
                is_optimal=$([ "$actual_amount" -le "$optimal" ] && echo "OPTIMAL" || echo "SUB-OPT")
            fi

            # Timing breakdowns from DB timestamps
            local p2p_time="" search_time="" settle_time=""

            if [ -n "$sender_created" ] && [ -n "$sender_completed" ]; then
                p2p_time=$(docker exec $SENDER php -r "
                    \$c = strtotime('$sender_created');
                    \$d = strtotime('$sender_completed');
                    if (\$c && \$d) printf('%.1f', \$d - \$c);
                " 2>/dev/null || echo "")
            fi

            # Query target timestamps for search_time
            local target_ts=$(docker exec $target php -r "
                require_once('${BOOTSTRAP_PATH}');
                \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
                \$stmt = \$pdo->prepare('SELECT created_at FROM p2p WHERE hash = ?');
                \$stmt->execute(['$hash']);
                \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
                if (\$row) echo \$row['created_at'] ?? '';
            " 2>/dev/null || echo "")

            if [ -n "$sender_created" ] && [ -n "$target_ts" ]; then
                search_time=$(docker exec $SENDER php -r "
                    \$c = strtotime('$sender_created');
                    \$t = strtotime('$target_ts');
                    if (\$c && \$t) printf('%.1f', \$t - \$c);
                " 2>/dev/null || echo "")
            fi

            if [ -n "$target_ts" ] && [ -n "$sender_completed" ]; then
                settle_time=$(docker exec $SENDER php -r "
                    \$t = strtotime('$target_ts');
                    \$d = strtotime('$sender_completed');
                    if (\$t && \$d) printf('%.1f', \$d - \$t);
                " 2>/dev/null || echo "")
            fi

            # Store results (same keys as serial mode)
            R_TIME[$key]="$elapsed"
            R_PASS[$key]="1"
            R_PATH[$key]="$path"
            R_FEE[$key]="$fee"
            R_OPTIMAL[$key]="$is_optimal"
            R_P2P_TIME[$key]="${p2p_time:-}"
            R_SEARCH_TIME[$key]="${search_time:-}"
            R_SETTLE_TIME[$key]="${settle_time:-}"

            # Print per-run result line (same format as serial do_send)
            local opt_color="${YELLOW}"
            [ "$is_optimal" = "OPTIMAL" ] && opt_color="${GREEN}"
            local timing_detail=""
            if [ -n "$p2p_time" ]; then
                timing_detail=" (p2p:${p2p_time}s"
                [ -n "$search_time" ] && timing_detail="${timing_detail} srch:${search_time}s"
                [ -n "$settle_time" ] && timing_detail="${timing_detail} sttl:${settle_time}s"
                timing_detail="${timing_detail})"
            fi
            printf "[%-5s] Run %2d/%d | %-4s | %-6s -> %-4s | %3ds%-28s | %-35s | %sx | ${opt_color}%s${NC}\n" \
                "$protocol" "$run" "$RUNS" "$mode" "$dist_label" "$target" "$elapsed" "$timing_detail" "$path" "$fee" "$is_optimal"
        done
    fi
}

# Accumulate stats for a set of keys into caller's variables:
#   _ts _tc _pc _oc _fc _fs _tmin _tmax (wall-clock time stats)
#   _p2p_sum _p2p_cnt _srch_sum _srch_cnt _sttl_sum _sttl_cnt (timing breakdown stats)
# Usage: accumulate_stats "http" "1" "fast" (or use "" to wildcard a dimension)
_accumulate_keys() {
    local p_filter="$1" d_filter="$2" m_filter="$3"
    _ts=0; _tc=0; _pc=0; _oc=0; _fc=0; _fs="0"; _tmin=999999; _tmax=0
    _p2p_sum="0"; _p2p_cnt=0; _srch_sum="0"; _srch_cnt=0; _sttl_sum="0"; _sttl_cnt=0

    # Use _ak_ prefixed vars to avoid clobbering outer loop variables
    # (bash has no block scoping — inner loops overwrite outer variables)
    local _ak_p _ak_d _ak_m _ak_r _ak_key
    for _ak_p in "${PROTOCOLS[@]}"; do
        [ -n "$p_filter" ] && [ "$_ak_p" != "$p_filter" ] && continue
        for _ak_d in "${DISTANCES[@]}"; do
            [ -n "$d_filter" ] && [ "$_ak_d" != "$d_filter" ] && continue
            for _ak_m in "fast" "best"; do
                [ -n "$m_filter" ] && [ "$_ak_m" != "$m_filter" ] && continue
                for ((_ak_r=1; _ak_r<=RUNS; _ak_r++)); do
                    _ak_key="${_ak_p}_${_ak_d}_${_ak_m}_${_ak_r}"
                    if [ -n "${R_TIME[$_ak_key]}" ]; then
                        _ts=$((_ts + R_TIME[$_ak_key]))
                        _tc=$((_tc + 1))
                        [ "${R_TIME[$_ak_key]}" -lt "$_tmin" ] && _tmin=${R_TIME[$_ak_key]}
                        [ "${R_TIME[$_ak_key]}" -gt "$_tmax" ] && _tmax=${R_TIME[$_ak_key]}
                    fi
                    [ "${R_PASS[$_ak_key]}" = "1" ] && _pc=$((_pc + 1))
                    [ "${R_OPTIMAL[$_ak_key]}" = "OPTIMAL" ] && _oc=$((_oc + 1))
                    if [ "${R_PASS[$_ak_key]}" = "1" ] && [ "${R_FEE[$_ak_key]}" != "0" ] && [ -n "${R_FEE[$_ak_key]}" ]; then
                        _fs=$(awk "BEGIN {printf \"%.6f\", $_fs + ${R_FEE[$_ak_key]}}")
                        _fc=$((_fc + 1))
                    fi
                    # Timing breakdowns (float sums via awk)
                    if [ -n "${R_P2P_TIME[$_ak_key]}" ]; then
                        _p2p_sum=$(awk "BEGIN {printf \"%.1f\", $_p2p_sum + ${R_P2P_TIME[$_ak_key]}}")
                        _p2p_cnt=$((_p2p_cnt + 1))
                    fi
                    if [ -n "${R_SEARCH_TIME[$_ak_key]}" ]; then
                        _srch_sum=$(awk "BEGIN {printf \"%.1f\", $_srch_sum + ${R_SEARCH_TIME[$_ak_key]}}")
                        _srch_cnt=$((_srch_cnt + 1))
                    fi
                    if [ -n "${R_SETTLE_TIME[$_ak_key]}" ]; then
                        _sttl_sum=$(awk "BEGIN {printf \"%.1f\", $_sttl_sum + ${R_SETTLE_TIME[$_ak_key]}}")
                        _sttl_cnt=$((_sttl_cnt + 1))
                    fi
                done
            done
        done
    done
    [ "$_tmin" -eq 999999 ] && _tmin=0
}

# Format a stats row: avg_time min max optimal% pass% avg_fee
_fmt_stats() {
    local label="$1" total_runs="$2"
    avg_time="N/A"; min_time="-"; max_time="-"
    if [ "$_tc" -gt 0 ]; then
        avg_time=$(awk "BEGIN {printf \"%.1f\", $_ts / $_tc}")
        min_time="${_tmin}"
        max_time="${_tmax}"
    fi
    optimal_pct="N/A"
    [ "$_pc" -gt 0 ] && optimal_pct=$(awk "BEGIN {printf \"%.0f\", ($_oc * 100.0 / $_pc)}")
    pass_pct="N/A"
    [ "$total_runs" -gt 0 ] && pass_pct=$(awk "BEGIN {printf \"%.0f\", ($_pc * 100.0 / $total_runs)}")
    avg_fee="N/A"
    [ "$_fc" -gt 0 ] && avg_fee=$(awk "BEGIN {printf \"%.4f\", $_fs / $_fc}")
    avg_p2p="N/A"; avg_search="N/A"; avg_settle="N/A"
    [ "$_p2p_cnt" -gt 0 ] && avg_p2p=$(awk "BEGIN {printf \"%.1f\", $_p2p_sum / $_p2p_cnt}")
    [ "$_srch_cnt" -gt 0 ] && avg_search=$(awk "BEGIN {printf \"%.1f\", $_srch_sum / $_srch_cnt}")
    [ "$_sttl_cnt" -gt 0 ] && avg_settle=$(awk "BEGIN {printf \"%.1f\", $_sttl_sum / $_sttl_cnt}")
}

# Print the main per-condition summary table
print_summary_table() {
    printf "\n${BOLD}--- Per-Condition Results ---${NC}\n"
    printf "%-10s %-10s %-6s %9s %6s %6s %8s %8s %8s %9s %7s %9s\n" \
        "Protocol" "Distance" "Mode" "Avg Wall" "Min" "Max" "Avg P2P" "Avg Srch" "Avg Sttl" "Optimal%" "Pass%" "Avg Fee"
    printf "%-10s %-10s %-6s %9s %6s %6s %8s %8s %8s %9s %7s %9s\n" \
        "--------" "--------" "----" "--------" "---" "---" "-------" "--------" "--------" "--------" "------" "--------"

    for protocol in "${PROTOCOLS[@]}"; do
        for distance in "${DISTANCES[@]}"; do
            for mode in "fast" "best"; do
                _accumulate_keys "$protocol" "$distance" "$mode"
                _fmt_stats "" "$RUNS"
                printf "%-10s %-10s %-6s %8ss %5ss %5ss %7ss %7ss %7ss %8s%% %6s%% %8sx\n" \
                    "$protocol" "${DISTANCE_LABELS[$distance]}" "$mode" \
                    "$avg_time" "$min_time" "$max_time" \
                    "$avg_p2p" "$avg_search" "$avg_settle" \
                    "$optimal_pct" "$pass_pct" "$avg_fee"
            done
        done
    done
}

# Print averages grouped by mode (fast vs best across all protocols and distances)
print_mode_averages() {
    local total_per_mode=$((${#PROTOCOLS[@]} * ${#DISTANCES[@]} * RUNS))

    printf "\n${BOLD}--- Average by Mode ---${NC}\n"
    printf "%-10s %9s %6s %6s %8s %8s %8s %9s %7s %9s\n" \
        "Mode" "Avg Wall" "Min" "Max" "Avg P2P" "Avg Srch" "Avg Sttl" "Optimal%" "Pass%" "Avg Fee"
    printf "%-10s %9s %6s %6s %8s %8s %8s %9s %7s %9s\n" \
        "--------" "--------" "---" "---" "-------" "--------" "--------" "--------" "------" "--------"

    for mode in "fast" "best"; do
        _accumulate_keys "" "" "$mode"
        _fmt_stats "" "$total_per_mode"
        printf "%-10s %8ss %5ss %5ss %7ss %7ss %7ss %8s%% %6s%% %8sx\n" \
            "$mode" "$avg_time" "$min_time" "$max_time" \
            "$avg_p2p" "$avg_search" "$avg_settle" \
            "$optimal_pct" "$pass_pct" "$avg_fee"
    done
}

# Print averages grouped by distance (across all protocols and modes)
print_distance_averages() {
    local total_per_dist=$((${#PROTOCOLS[@]} * 2 * RUNS))

    printf "\n${BOLD}--- Average by Distance ---${NC}\n"
    printf "%-10s %9s %6s %6s %8s %8s %8s %9s %7s %9s\n" \
        "Distance" "Avg Wall" "Min" "Max" "Avg P2P" "Avg Srch" "Avg Sttl" "Optimal%" "Pass%" "Avg Fee"
    printf "%-10s %9s %6s %6s %8s %8s %8s %9s %7s %9s\n" \
        "--------" "--------" "---" "---" "-------" "--------" "--------" "--------" "------" "--------"

    for distance in "${DISTANCES[@]}"; do
        _accumulate_keys "" "$distance" ""
        _fmt_stats "" "$total_per_dist"
        printf "%-10s %8ss %5ss %5ss %7ss %7ss %7ss %8s%% %6s%% %8sx\n" \
            "${DISTANCE_LABELS[$distance]}" "$avg_time" "$min_time" "$max_time" \
            "$avg_p2p" "$avg_search" "$avg_settle" \
            "$optimal_pct" "$pass_pct" "$avg_fee"
    done
}

# Print averages grouped by protocol (across all distances and modes)
print_protocol_averages() {
    local total_per_proto=$((${#DISTANCES[@]} * 2 * RUNS))

    printf "\n${BOLD}--- Average by Protocol ---${NC}\n"
    printf "%-10s %9s %6s %6s %8s %8s %8s %9s %7s %9s\n" \
        "Protocol" "Avg Wall" "Min" "Max" "Avg P2P" "Avg Srch" "Avg Sttl" "Optimal%" "Pass%" "Avg Fee"
    printf "%-10s %9s %6s %6s %8s %8s %8s %9s %7s %9s\n" \
        "--------" "--------" "---" "---" "-------" "--------" "--------" "--------" "------" "--------"

    for protocol in "${PROTOCOLS[@]}"; do
        _accumulate_keys "$protocol" "" ""
        _fmt_stats "" "$total_per_proto"
        printf "%-10s %8ss %5ss %5ss %7ss %7ss %7ss %8s%% %6s%% %8sx\n" \
            "$protocol" "$avg_time" "$min_time" "$max_time" \
            "$avg_p2p" "$avg_search" "$avg_settle" \
            "$optimal_pct" "$pass_pct" "$avg_fee"
    done
}

# Print grand total across everything
print_grand_total() {
    local total=$((${#PROTOCOLS[@]} * ${#DISTANCES[@]} * 2 * RUNS))

    printf "\n${BOLD}--- Grand Total ---${NC}\n"
    _accumulate_keys "" "" ""
    _fmt_stats "" "$total"
    printf "  Sends: %d  |  Avg Wall: %ss  |  Min: %ss  |  Max: %ss  |  Optimal: %s%%  |  Pass: %s%%  |  Avg Fee: %sx\n" \
        "$total" "$avg_time" "$min_time" "$max_time" "$optimal_pct" "$pass_pct" "$avg_fee"
    printf "  Avg P2P: %ss  |  Avg Search: %ss  |  Avg Settle: %ss\n" \
        "$avg_p2p" "$avg_search" "$avg_settle"
}

# Print Fast vs Best comparison table
print_comparison_table() {
    printf "\n%-10s %-10s %16s %16s %18s\n" \
        "Protocol" "Distance" "Fast Optimal%" "Best Optimal%" "Best Advantage"
    printf "%-10s %-10s %16s %16s %18s\n" \
        "--------" "--------" "--------------" "--------------" "---------------"

    for protocol in "${PROTOCOLS[@]}"; do
        for distance in "${DISTANCES[@]}"; do
            fast_optimal=0; fast_pass=0; best_optimal=0; best_pass=0

            for ((run=1; run<=RUNS; run++)); do
                fk="${protocol}_${distance}_fast_${run}"
                bk="${protocol}_${distance}_best_${run}"
                [ "${R_PASS[$fk]}" = "1" ] && fast_pass=$((fast_pass + 1))
                [ "${R_OPTIMAL[$fk]}" = "OPTIMAL" ] && fast_optimal=$((fast_optimal + 1))
                [ "${R_PASS[$bk]}" = "1" ] && best_pass=$((best_pass + 1))
                [ "${R_OPTIMAL[$bk]}" = "OPTIMAL" ] && best_optimal=$((best_optimal + 1))
            done

            fast_pct="N/A"; best_pct="N/A"; advantage="N/A"
            [ "$fast_pass" -gt 0 ] && fast_pct=$(awk "BEGIN {printf \"%.0f\", ($fast_optimal * 100.0 / $fast_pass)}")
            [ "$best_pass" -gt 0 ] && best_pct=$(awk "BEGIN {printf \"%.0f\", ($best_optimal * 100.0 / $best_pass)}")
            if [ "$fast_pass" -gt 0 ] && [ "$best_pass" -gt 0 ]; then
                fv=$(awk "BEGIN {printf \"%.1f\", ($fast_optimal * 100.0 / $fast_pass)}")
                bv=$(awk "BEGIN {printf \"%.1f\", ($best_optimal * 100.0 / $best_pass)}")
                advantage=$(awk "BEGIN {printf \"%+.0f\", $bv - $fv}")
            fi

            printf "%-10s %-10s %15s%% %15s%% %17s%%\n" \
                "$protocol" "${DISTANCE_LABELS[$distance]}" "$fast_pct" "$best_pct" "$advantage"
        done
    done
}

# Print all enumerated paths for multi-hop targets (3 and 6 hops) with integer amounts.
# Shows which paths the system could take and whether each is optimal by integer cost.
print_path_analysis() {
    printf "\n${BOLD}--- Enumerated Path Analysis (3+ hops) ---${NC}\n"

    # In shared mode, paths are identical across protocols — use first protocol only
    local shown_protocols=("${PROTOCOLS[@]}")
    if [ "$TOPOLOGY_MODE" = "shared" ]; then
        shown_protocols=("${PROTOCOLS[0]}")
        printf "${DIM}(shared topology — paths identical across protocols)${NC}\n"
    fi

    local amount_cents=$((SEND_AMOUNT * 100))

    for protocol in "${shown_protocols[@]}"; do
        for distance in 3 6; do
            local target="${TARGETS[$distance]}"
            local optimal="${OPTIMAL_FEES[${protocol}_${distance}]}"
            local paths="${ENUM_PATHS[${protocol}_${distance}]}"
            [ -z "$paths" ] && continue

            local path_count=$(echo "$paths" | wc -l | tr -d ' ')
            if [ "$TOPOLOGY_MODE" != "shared" ]; then
                printf "\n  [%s] %s → %s  (optimal: %d¢, %d paths)\n" \
                    "$protocol" "${DISTANCE_LABELS[$distance]}" "$target" "$optimal" "$path_count"
            else
                printf "\n  %s → %s  (optimal: %d¢, %d paths)\n" \
                    "${DISTANCE_LABELS[$distance]}" "$target" "$optimal" "$path_count"
            fi
            printf "  %-50s %10s %10s  %s\n" "Path" "Float Fee" "Int Amt" "Status"
            printf "  %-50s %10s %10s  %s\n" "----" "---------" "-------" "------"

            while IFS= read -r path_line; do
                [ -z "$path_line" ] && continue
                local p=$(echo "$path_line" | awk '{print $1}')
                local ff=$(echo "$path_line" | grep -oP 'fee=\K[0-9.]+')
                local ia=$(compute_path_integer_amount "$p" "$target" "$amount_cents")
                local status="SUB-OPT"
                local color="${YELLOW}"
                if [ "$ia" -le "$optimal" ]; then
                    status="OPTIMAL"
                    color="${GREEN}"
                fi
                printf "  %-50s %9sx %9d¢  ${color}%s${NC}\n" "$p" "$ff" "$ia" "$status"
            done <<< "$paths"
        done
    done
}

# ======================== Main Execution ========================

echo ""
echo "================================================================"
echo "  EIOU Routing Benchmark (collisionscluster)"
echo "================================================================"
echo "Runs per condition: ${RUNS}"
echo "Protocols:          ${PROTOCOLS[*]}"
echo "Topology:           ${TOPOLOGY_MODE}"
echo "Send mode:          ${SEND_MODE}"
echo "Targets:            $(for d in "${DISTANCES[@]}"; do printf "%s(%s) " "${TARGETS[$d]}" "${DISTANCE_LABELS[$d]}"; done)"
echo "Total sends:        $((${#PROTOCOLS[@]} * ${#DISTANCES[@]} * 2 * RUNS))"
echo "Time:               $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# P2P expiration — must outlast the slowest transport's round trip.
# Tor hidden services add ~2-5s latency per hop; a 6-hop chain (12 hops round trip)
# needs well over 300s. Use 600s when Tor is in the protocol list.
_has_tor=0
for _p in "${PROTOCOLS[@]}"; do [ "$_p" = "tor" ] && _has_tor=1; done
testExpiration=$(( _has_tor ? 600 : 300 ))

# Per-protocol send timeouts (polling deadline in do_send/do_burst).
# HTTP/HTTPS complete quickly; Tor needs the full extended window.
declare -A _PROTO_TIMEOUT=( [http]=300 [https]=300 [tor]=600 )

set +e  # Allow errors during main loop (we handle them ourselves)

# ---- Shared topology: build once, reuse for all protocols ----
if [ "$TOPOLOGY_MODE" = "shared" ]; then

    # Build topology once — fees are randomized at build time and stay fixed.
    # MODE only affects which docker-compose config is used; all transports
    # (http, https, tor) are available regardless of the build MODE.
    first_protocol="${PROTOCOLS[0]}"
    printf "\nBuilding shared topology (fees fixed across all protocols)...\n"
    export MODE="$first_protocol"
    export EIOU_CONTACT_STATUS_ENABLED=false

    cd "$BENCH_DIR"
    set -e
    . "${BENCH_DIR}/buildfiles/collisionscluster.sh"
    set +e

    # Wait for the most demanding transport — tor readiness implies http/https
    # are also ready since Tor services start last.
    wait_protocol="$first_protocol"
    for _p in "${PROTOCOLS[@]}"; do
        if [ "$_p" = "tor" ]; then wait_protocol="tor"; break; fi
        if [ "$_p" = "https" ]; then wait_protocol="https"; fi
    done
    unset _p

    if ! wait_for_containers "$wait_protocol"; then
        printf "${RED}Container initialization failed, aborting${NC}\n"
        for container in "${containers[@]}"; do
            remove_container_if_exists $container 2>/dev/null || true
        done
        exit 1
    fi

    # Populate addresses and establish contacts for ALL requested protocols.
    # Each node needs to know its contacts' addresses for every transport type
    # so that routing works over http, https, AND tor.
    #
    # Flow: full double-sided add for the first protocol (establishes mutual contacts),
    # then one-sided adds for subsequent protocols (automatic address exchange).
    declare -A PROTO_ADDRESSES  # keyed by "${protocol}_${container}"

    # First protocol: full double-sided establish (both A→B and B→A)
    populate_addresses "$first_protocol"
    for container in "${containers[@]}"; do
        PROTO_ADDRESSES["${first_protocol}_${container}"]="${containerAddresses[$container]}"
    done
    establish_contacts

    # Subsequent protocols: one-sided adds (exchange handles the reverse)
    for protocol in "${PROTOCOLS[@]}"; do
        [ "$protocol" = "$first_protocol" ] && continue
        populate_addresses "$protocol"
        for container in "${containers[@]}"; do
            PROTO_ADDRESSES["${protocol}_${container}"]="${containerAddresses[$container]}"
        done
        add_transport_addresses "$protocol"
    done

    # Verify we got a sender address (use last populated protocol)
    if [ -z "${containerAddresses[$SENDER]}" ]; then
        printf "${RED}Failed to get address for ${SENDER}, aborting${NC}\n"
        for container in "${containers[@]}"; do
            remove_container_if_exists $container 2>/dev/null || true
        done
        exit 1
    fi

    # Build adjacency list for fast DFS neighbor lookup
    build_adjacency_list

    # Enumerate optimal paths once — fees are the same for all protocols.
    # Depth limit = distance + 2 so we explore near-optimal alternatives
    # without wasting time on long detours (e.g., 183 paths for a 1-hop target).
    # Integer amounts are computed per path to match production calculateFee rounding.
    amount_cents=$((SEND_AMOUNT * 100))
    printf "\nEnumerating optimal paths (shared fee structure)...\n"
    for distance in "${DISTANCES[@]}"; do
        target="${TARGETS[$distance]}"
        all_paths=$(enumerate_paths_limited "$SENDER" "$target" $((distance + 2)))
        path_count=$(echo "$all_paths" | wc -l | tr -d ' ')

        # Find optimal integer amount across all enumerated paths
        best_int_amount=999999
        best_float_fee="1.000000"
        while IFS= read -r path_line; do
            [ -z "$path_line" ] && continue
            p_path=$(echo "$path_line" | awk '{print $1}')
            int_amount=$(compute_path_integer_amount "$p_path" "$target" "$amount_cents")
            if [ "$int_amount" -lt "$best_int_amount" ]; then
                best_int_amount="$int_amount"
                best_float_fee=$(echo "$path_line" | grep -oP 'fee=\K[0-9.]+')
            fi
        done <<< "$all_paths"

        _SHARED_OPTIMAL_FEE[$distance]="$best_int_amount"
        _SHARED_ENUM_PATHS[$distance]="$all_paths"
        printf "  ${SENDER} -> %-4s (%s): %d paths, optimal=%d¢ (%.4fx)\n" \
            "$target" "${DISTANCE_LABELS[$distance]}" "$path_count" "$best_int_amount" "$best_float_fee"
    done

    # Set P2P expiration once
    printf "\nSetting P2P expiration to ${testExpiration}s on ${SENDER}\n"
    docker exec ${SENDER} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$app->services->getCurrentUser()->set('p2pExpiration', ${testExpiration});
    " 2>/dev/null || true

    # Run sends for each protocol
    for protocol in "${PROTOCOLS[@]}"; do
        echo ""
        echo "================================================================"
        printf "  Protocol: ${BOLD}${protocol}${NC} (shared topology)\n"
        echo "================================================================"

        export MODE="$protocol"

        # Restore this protocol's addresses into containerAddresses (used by do_send)
        for container in "${containers[@]}"; do
            containerAddresses[$container]="${PROTO_ADDRESSES[${protocol}_${container}]}"
        done

        # Copy shared optimal fees and paths into per-protocol keys
        for distance in "${DISTANCES[@]}"; do
            OPTIMAL_FEES["${protocol}_${distance}"]="${_SHARED_OPTIMAL_FEE[$distance]}"
            ENUM_PATHS["${protocol}_${distance}"]="${_SHARED_ENUM_PATHS[$distance]}"
        done

        # Sends grouped by distance then mode: all runs for 1-hop fast, 1-hop best,
        # then 3-hop fast, 3-hop best, etc. This keeps topology state consistent
        # for each hop distance before moving on.
        send_timeout="${_PROTO_TIMEOUT[$protocol]:-$testExpiration}"
        for distance in "${DISTANCES[@]}"; do
            target="${TARGETS[$distance]}"
            printf "\n--- ${DISTANCE_LABELS[$distance]} → ${target} (timeout: ${send_timeout}s) ---\n"
            for mode in "fast" "best"; do
                [ "$mode" = "best" ] && printf "\n"
                if [ "$SEND_MODE" = "burst" ]; then
                    do_burst "$protocol" "$distance" "$mode" "$send_timeout"
                else
                    for ((run=1; run<=RUNS; run++)); do
                        do_send "$protocol" "$distance" "$mode" "$run" "$send_timeout"
                    done
                fi
            done
        done
    done

    # Cleanup after all protocols
    if [ "${SKIP_CLEANUP:-0}" = "1" ]; then
        printf "\nSkipping cleanup (SKIP_CLEANUP=1)\n"
    else
        printf "\nCleaning up containers...\n"
        for container in "${containers[@]}"; do
            remove_container_if_exists $container 2>/dev/null || true
        done
    fi

# ---- Rebuild topology: fresh build per protocol (different random fees) ----
else

    for protocol in "${PROTOCOLS[@]}"; do
        echo ""
        echo "================================================================"
        printf "  Protocol: ${BOLD}${protocol}${NC} (fresh topology)\n"
        echo "================================================================"

        # Build fresh topology
        printf "\nBuilding collisionscluster topology for ${protocol}...\n"
        export MODE="$protocol"
        export EIOU_CONTACT_STATUS_ENABLED=false

        cd "$BENCH_DIR"
        set -e
        . "${BENCH_DIR}/buildfiles/collisionscluster.sh"
        set +e

        if ! wait_for_containers "$protocol"; then
            printf "${RED}Container initialization failed for ${protocol}, skipping${NC}\n"
            for container in "${containers[@]}"; do
                remove_container_if_exists $container 2>/dev/null || true
            done
            continue
        fi

        populate_addresses "$protocol"

        if [ -z "${containerAddresses[$SENDER]}" ]; then
            printf "${RED}Failed to get address for ${SENDER}, skipping ${protocol}${NC}\n"
            for container in "${containers[@]}"; do
                remove_container_if_exists $container 2>/dev/null || true
            done
            continue
        fi

        establish_contacts

        # Set P2P expiration
        printf "\nSetting P2P expiration to ${testExpiration}s on ${SENDER}\n"
        docker exec ${SENDER} php -r "
            require_once('${BOOTSTRAP_PATH}');
            \$app = \Eiou\Core\Application::getInstance();
            \$app->services->getCurrentUser()->set('p2pExpiration', ${testExpiration});
        " 2>/dev/null || true

        # Build adjacency list for fast DFS neighbor lookup
        build_adjacency_list

        # Enumerate paths (fresh fees each build)
        # Integer amounts computed per path to match production calculateFee rounding.
        amount_cents=$((SEND_AMOUNT * 100))
        printf "\nEnumerating optimal paths...\n"
        for distance in "${DISTANCES[@]}"; do
            target="${TARGETS[$distance]}"
            all_paths=$(enumerate_paths_limited "$SENDER" "$target" $((distance + 2)))
            path_count=$(echo "$all_paths" | wc -l | tr -d ' ')

            # Find optimal integer amount across all enumerated paths
            best_int_amount=999999
            best_float_fee="1.000000"
            while IFS= read -r path_line; do
                [ -z "$path_line" ] && continue
                p_path=$(echo "$path_line" | awk '{print $1}')
                int_amount=$(compute_path_integer_amount "$p_path" "$target" "$amount_cents")
                if [ "$int_amount" -lt "$best_int_amount" ]; then
                    best_int_amount="$int_amount"
                    best_float_fee=$(echo "$path_line" | grep -oP 'fee=\K[0-9.]+')
                fi
            done <<< "$all_paths"

            OPTIMAL_FEES["${protocol}_${distance}"]="$best_int_amount"
            ENUM_PATHS["${protocol}_${distance}"]="$all_paths"
            printf "  ${SENDER} -> %-4s (%s): %d paths, optimal=%d¢ (%.4fx)\n" \
                "$target" "${DISTANCE_LABELS[$distance]}" "$path_count" "$best_int_amount" "$best_float_fee"
        done

        # Sends grouped by distance then mode: all runs for 1-hop fast, 1-hop best,
        # then 3-hop fast, 3-hop best, etc.
        send_timeout="${_PROTO_TIMEOUT[$protocol]:-$testExpiration}"
        for distance in "${DISTANCES[@]}"; do
            target="${TARGETS[$distance]}"
            printf "\n--- ${DISTANCE_LABELS[$distance]} → ${target} (timeout: ${send_timeout}s) ---\n"
            for mode in "fast" "best"; do
                [ "$mode" = "best" ] && printf "\n"
                if [ "$SEND_MODE" = "burst" ]; then
                    do_burst "$protocol" "$distance" "$mode" "$send_timeout"
                else
                    for ((run=1; run<=RUNS; run++)); do
                        do_send "$protocol" "$distance" "$mode" "$run" "$send_timeout"
                    done
                fi
            done
        done

        # Cleanup (keep containers on last protocol if SKIP_CLEANUP=1)
        if [ "$protocol" = "${PROTOCOLS[-1]}" ] && [ "${SKIP_CLEANUP:-0}" = "1" ]; then
            printf "\nSkipping cleanup (SKIP_CLEANUP=1)\n"
        else
            printf "\nCleaning up containers...\n"
            for container in "${containers[@]}"; do
                remove_container_if_exists $container 2>/dev/null || true
            done
        fi
    done

fi

# ======================== Summary Tables ========================

echo ""
echo "================================================================"
echo "  BENCHMARK SUMMARY"
echo "================================================================"

print_summary_table
print_comparison_table
print_path_analysis
print_mode_averages
print_distance_averages
print_protocol_averages
print_grand_total

# ======================== CSV Export ========================

CSV_FILE="${BENCH_DIR}/benchmark-results-$(date '+%Y%m%d-%H%M%S').csv"

{
    echo "protocol,distance,target,mode,run,wall_time_s,pass,path,fee_multiplier,optimal,p2p_time_s,search_time_s,settle_time_s"
    for protocol in "${PROTOCOLS[@]}"; do
        for distance in "${DISTANCES[@]}"; do
            target="${TARGETS[$distance]}"
            for mode in "fast" "best"; do
                for ((run=1; run<=RUNS; run++)); do
                    key="${protocol}_${distance}_${mode}_${run}"
                    # Quote path field (contains -> arrows)
                    echo "${protocol},${distance},${target},${mode},${run},${R_TIME[$key]:-},${R_PASS[$key]:-0},\"${R_PATH[$key]:-}\",${R_FEE[$key]:-},${R_OPTIMAL[$key]:-},${R_P2P_TIME[$key]:-},${R_SEARCH_TIME[$key]:-},${R_SETTLE_TIME[$key]:-}"
                done
            done
        done
    done
} > "$CSV_FILE"

printf "\nResults saved to: ${CSV_FILE}\n"

echo ""
echo "Benchmark completed: $(date '+%Y-%m-%d %H:%M:%S')"
