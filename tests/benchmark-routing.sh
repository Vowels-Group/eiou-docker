#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC
#
# Routing performance benchmark for the collisionscluster topology.
# Systematically measures routing across protocols, hop distances, and routing modes.
#
# Tests fast mode (first route wins) vs best-fee mode (collect all routes, pick cheapest)
# at 1-hop, 3-hop, and 6-hop distances from C0, measuring:
#   - Delivery time
#   - Path taken
#   - Fee multiplier
#   - Optimality (vs enumerated best path)
#
# Usage: ./benchmark-routing.sh [runs] [protocols] [topology]
#   runs      - Number of runs per condition (default: 10)
#   protocols - Comma-separated protocols (default: http,https,tor)
#   topology  - "shared" (default) or "rebuild"
#               shared:  build once, reuse across all protocols (same fees, faster)
#               rebuild: fresh topology per protocol (different random fees each time)
#
# Examples:
#   ./benchmark-routing.sh 10                        # Full benchmark, shared topology
#   ./benchmark-routing.sh 3 http                    # Quick test, HTTP only
#   ./benchmark-routing.sh 5 http,https              # HTTP and HTTPS only
#   ./benchmark-routing.sh 10 http,https,tor rebuild # Fresh build per protocol
#
# Total sends: protocols × 3 distances × 2 modes × runs
# With shared topology at $5/send, 180 sends = $900 total — well under $1000 credit limit.

set -e

RUNS="${1:-10}"
IFS=',' read -ra _INPUT_PROTOCOLS <<< "${2:-http,https,tor}"
TOPOLOGY_MODE="${3:-shared}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

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
cd "$SCRIPT_DIR"
. "${SCRIPT_DIR}/baseconfig/config.sh"

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
declare -A R_TIME R_PASS R_PATH R_FEE R_OPTIMAL

# Optimal fee per target per protocol build: "${protocol}_${distance}"
declare -A OPTIMAL_FEES

# Shared optimal fees (used in shared topology mode): "${distance}"
declare -A _SHARED_OPTIMAL_FEE

# ======================== Helper Functions ========================

# Compute compound fee multiplier for a traced path
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

    # Send
    local start_time=$(date +%s)
    if [ "$mode" = "fast" ]; then
        docker exec $SENDER eiou send $receiver_addr $SEND_AMOUNT $SEND_CURRENCY 2>&1 >/dev/null || true
    else
        docker exec $SENDER eiou send $receiver_addr $SEND_AMOUNT $SEND_CURRENCY --best 2>&1 >/dev/null || true
    fi

    # Wait for balance change — uniform polling for both modes.
    # Use the full timeout (P2P expiration) so we capture outliers and slow deliveries
    # rather than prematurely marking them as failures.
    local new_balance
    local balance_changed=0
    local elapsed_wait=0
    while [ $elapsed_wait -lt $timeout ]; do
        sleep 5
        new_balance=$(docker exec $target sh -c "$balance_cmd" 2>/dev/null || echo "$initial")
        balance_changed=$(awk "BEGIN {print ($new_balance != $initial) ? 1 : 0}")
        if [ "$balance_changed" -eq 1 ]; then
            break
        fi
        elapsed_wait=$(( $(date +%s) - start_time ))
    done

    local end_time=$(date +%s)
    local elapsed=$((end_time - start_time))

    # Trace path and compute fee if send succeeded
    local path="" fee="0" is_optimal="N/A"
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
            path=$(trace_actual_path "$hash" "$SENDER" "$target")
            fee=$(compute_path_fee "$path" "$target")
            local optimal="${OPTIMAL_FEES[${protocol}_${distance}]}"
            if [ -n "$optimal" ] && [ "$optimal" != "" ]; then
                is_optimal=$(awk "BEGIN {printf \"%s\", ($fee <= $optimal * 1.000001) ? \"OPTIMAL\" : \"SUB-OPT\"}")
            fi
        fi
    fi

    R_TIME[$key]="$elapsed"
    R_PASS[$key]="$balance_changed"
    R_PATH[$key]="$path"
    R_FEE[$key]="$fee"
    R_OPTIMAL[$key]="$is_optimal"

    # Print per-send line with full context
    local dist_label="${DISTANCE_LABELS[$distance]}"
    if [ "$balance_changed" -eq 1 ]; then
        local opt_color="${YELLOW}"
        [ "$is_optimal" = "OPTIMAL" ] && opt_color="${GREEN}"
        printf "[%-5s] Run %2d/%d | %-4s | %-6s -> %-4s | %3ds | %-35s | %sx | ${opt_color}%s${NC}\n" \
            "$protocol" "$run" "$RUNS" "$mode" "$dist_label" "$target" "$elapsed" "$path" "$fee" "$is_optimal"
    else
        printf "[%-5s] Run %2d/%d | %-4s | %-6s -> %-4s | %3ds | ${RED}FAILED${NC}\n" \
            "$protocol" "$run" "$RUNS" "$mode" "$dist_label" "$target" "$elapsed"
    fi
}

# Accumulate stats for a set of keys into caller's variables:
#   _ts _tc _pc _oc _fc _fs _tmin _tmax
# Usage: accumulate_stats "http" "1" "fast" (or use "" to wildcard a dimension)
_accumulate_keys() {
    local p_filter="$1" d_filter="$2" m_filter="$3"
    _ts=0; _tc=0; _pc=0; _oc=0; _fc=0; _fs="0"; _tmin=999999; _tmax=0

    for protocol in "${PROTOCOLS[@]}"; do
        [ -n "$p_filter" ] && [ "$protocol" != "$p_filter" ] && continue
        for distance in "${DISTANCES[@]}"; do
            [ -n "$d_filter" ] && [ "$distance" != "$d_filter" ] && continue
            for mode in "fast" "best"; do
                [ -n "$m_filter" ] && [ "$mode" != "$m_filter" ] && continue
                for ((run=1; run<=RUNS; run++)); do
                    key="${protocol}_${distance}_${mode}_${run}"
                    if [ -n "${R_TIME[$key]}" ]; then
                        _ts=$((_ts + R_TIME[$key]))
                        _tc=$((_tc + 1))
                        [ "${R_TIME[$key]}" -lt "$_tmin" ] && _tmin=${R_TIME[$key]}
                        [ "${R_TIME[$key]}" -gt "$_tmax" ] && _tmax=${R_TIME[$key]}
                    fi
                    [ "${R_PASS[$key]}" = "1" ] && _pc=$((_pc + 1))
                    [ "${R_OPTIMAL[$key]}" = "OPTIMAL" ] && _oc=$((_oc + 1))
                    if [ "${R_PASS[$key]}" = "1" ] && [ "${R_FEE[$key]}" != "0" ] && [ -n "${R_FEE[$key]}" ]; then
                        _fs=$(awk "BEGIN {printf \"%.6f\", $_fs + ${R_FEE[$key]}}")
                        _fc=$((_fc + 1))
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
}

# Print the main per-condition summary table
print_summary_table() {
    printf "\n${BOLD}--- Per-Condition Results ---${NC}\n"
    printf "%-10s %-10s %-6s %9s %6s %6s %9s %7s %9s\n" \
        "Protocol" "Distance" "Mode" "Avg Time" "Min" "Max" "Optimal%" "Pass%" "Avg Fee"
    printf "%-10s %-10s %-6s %9s %6s %6s %9s %7s %9s\n" \
        "--------" "--------" "----" "--------" "---" "---" "--------" "------" "--------"

    for protocol in "${PROTOCOLS[@]}"; do
        for distance in "${DISTANCES[@]}"; do
            for mode in "fast" "best"; do
                _accumulate_keys "$protocol" "$distance" "$mode"
                _fmt_stats "" "$RUNS"
                printf "%-10s %-10s %-6s %8ss %5ss %5ss %8s%% %6s%% %8sx\n" \
                    "$protocol" "${DISTANCE_LABELS[$distance]}" "$mode" \
                    "$avg_time" "$min_time" "$max_time" "$optimal_pct" "$pass_pct" "$avg_fee"
            done
        done
    done
}

# Print averages grouped by mode (fast vs best across all protocols and distances)
print_mode_averages() {
    local total_per_mode=$((${#PROTOCOLS[@]} * ${#DISTANCES[@]} * RUNS))

    printf "\n${BOLD}--- Average by Mode ---${NC}\n"
    printf "%-10s %9s %6s %6s %9s %7s %9s\n" \
        "Mode" "Avg Time" "Min" "Max" "Optimal%" "Pass%" "Avg Fee"
    printf "%-10s %9s %6s %6s %9s %7s %9s\n" \
        "--------" "--------" "---" "---" "--------" "------" "--------"

    for mode in "fast" "best"; do
        _accumulate_keys "" "" "$mode"
        _fmt_stats "" "$total_per_mode"
        printf "%-10s %8ss %5ss %5ss %8s%% %6s%% %8sx\n" \
            "$mode" "$avg_time" "$min_time" "$max_time" "$optimal_pct" "$pass_pct" "$avg_fee"
    done
}

# Print averages grouped by distance (across all protocols and modes)
print_distance_averages() {
    local total_per_dist=$((${#PROTOCOLS[@]} * 2 * RUNS))

    printf "\n${BOLD}--- Average by Distance ---${NC}\n"
    printf "%-10s %9s %6s %6s %9s %7s %9s\n" \
        "Distance" "Avg Time" "Min" "Max" "Optimal%" "Pass%" "Avg Fee"
    printf "%-10s %9s %6s %6s %9s %7s %9s\n" \
        "--------" "--------" "---" "---" "--------" "------" "--------"

    for distance in "${DISTANCES[@]}"; do
        _accumulate_keys "" "$distance" ""
        _fmt_stats "" "$total_per_dist"
        printf "%-10s %8ss %5ss %5ss %8s%% %6s%% %8sx\n" \
            "${DISTANCE_LABELS[$distance]}" "$avg_time" "$min_time" "$max_time" "$optimal_pct" "$pass_pct" "$avg_fee"
    done
}

# Print averages grouped by protocol (across all distances and modes)
print_protocol_averages() {
    local total_per_proto=$((${#DISTANCES[@]} * 2 * RUNS))

    printf "\n${BOLD}--- Average by Protocol ---${NC}\n"
    printf "%-10s %9s %6s %6s %9s %7s %9s\n" \
        "Protocol" "Avg Time" "Min" "Max" "Optimal%" "Pass%" "Avg Fee"
    printf "%-10s %9s %6s %6s %9s %7s %9s\n" \
        "--------" "--------" "---" "---" "--------" "------" "--------"

    for protocol in "${PROTOCOLS[@]}"; do
        _accumulate_keys "$protocol" "" ""
        _fmt_stats "" "$total_per_proto"
        printf "%-10s %8ss %5ss %5ss %8s%% %6s%% %8sx\n" \
            "$protocol" "$avg_time" "$min_time" "$max_time" "$optimal_pct" "$pass_pct" "$avg_fee"
    done
}

# Print grand total across everything
print_grand_total() {
    local total=$((${#PROTOCOLS[@]} * ${#DISTANCES[@]} * 2 * RUNS))

    printf "\n${BOLD}--- Grand Total ---${NC}\n"
    _accumulate_keys "" "" ""
    _fmt_stats "" "$total"
    printf "  Sends: %d  |  Avg Time: %ss  |  Min: %ss  |  Max: %ss  |  Optimal: %s%%  |  Pass: %s%%  |  Avg Fee: %sx\n" \
        "$total" "$avg_time" "$min_time" "$max_time" "$optimal_pct" "$pass_pct" "$avg_fee"
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

# ======================== Main Execution ========================

echo ""
echo "================================================================"
echo "  EIOU Routing Benchmark (collisionscluster)"
echo "================================================================"
echo "Runs per condition: ${RUNS}"
echo "Protocols:          ${PROTOCOLS[*]}"
echo "Topology:           ${TOPOLOGY_MODE}"
echo "Targets:            $(for d in "${DISTANCES[@]}"; do printf "%s(%s) " "${TARGETS[$d]}" "${DISTANCE_LABELS[$d]}"; done)"
echo "Total sends:        $((${#PROTOCOLS[@]} * ${#DISTANCES[@]} * 2 * RUNS))"
echo "Time:               $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# P2P expiration (production default: 300s) — also used as send timeout
testExpiration=300
send_timeout=$testExpiration

set +e  # Allow errors during main loop (we handle them ourselves)

# ---- Shared topology: build once, reuse for all protocols ----
if [ "$TOPOLOGY_MODE" = "shared" ]; then

    # Build topology once using first protocol for initial setup
    first_protocol="${PROTOCOLS[0]}"
    printf "\nBuilding shared topology (fees fixed across all protocols)...\n"
    export MODE="$first_protocol"
    export EIOU_CONTACT_STATUS_ENABLED=false

    cd "$SCRIPT_DIR"
    set -e
    . "${SCRIPT_DIR}/buildfiles/collisionscluster.sh"
    set +e

    if ! wait_for_containers "$first_protocol"; then
        printf "${RED}Container initialization failed, aborting${NC}\n"
        for container in "${containers[@]}"; do
            remove_container_if_exists $container 2>/dev/null || true
        done
        exit 1
    fi

    # Enumerate optimal paths once — fees are the same for all protocols
    # (Populate addresses temporarily to verify containers, then re-populate per protocol)
    populate_addresses "$first_protocol"

    if [ -z "${containerAddresses[$SENDER]}" ]; then
        printf "${RED}Failed to get address for ${SENDER}, aborting${NC}\n"
        for container in "${containers[@]}"; do
            remove_container_if_exists $container 2>/dev/null || true
        done
        exit 1
    fi

    printf "\nEnumerating optimal paths (shared fee structure)...\n"
    for distance in "${DISTANCES[@]}"; do
        target="${TARGETS[$distance]}"
        all_paths=$(enumerate_paths "$SENDER" "$target")
        best_fee=$(echo "$all_paths" | head -1 | grep -oP 'fee=\K[0-9.]+')
        # Store under a shared key — all protocols share the same optimal fees
        _SHARED_OPTIMAL_FEE[$distance]="$best_fee"
        path_count=$(echo "$all_paths" | wc -l | tr -d ' ')
        printf "  ${SENDER} -> %-4s (%s): %d paths, optimal fee=%sx\n" \
            "$target" "${DISTANCE_LABELS[$distance]}" "$path_count" "$best_fee"
    done

    # Set P2P expiration once
    printf "\nSetting P2P expiration to ${testExpiration}s on ${SENDER}\n"
    docker exec ${SENDER} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$app->services->getCurrentUser()->set('p2pExpiration', ${testExpiration});
    " 2>/dev/null || true

    # Establish contacts once using the first protocol's addresses
    establish_contacts

    # For each subsequent protocol we need the right addresses for `eiou send`.
    # Pre-populate all protocol addresses now so we can switch cheaply.
    declare -A PROTO_ADDRESSES  # keyed by "${protocol}_${container}"
    for container in "${containers[@]}"; do
        PROTO_ADDRESSES["${first_protocol}_${container}"]="${containerAddresses[$container]}"
    done
    for protocol in "${PROTOCOLS[@]}"; do
        [ "$protocol" = "$first_protocol" ] && continue
        populate_addresses "$protocol"
        for container in "${containers[@]}"; do
            PROTO_ADDRESSES["${protocol}_${container}"]="${containerAddresses[$container]}"
        done
        # Also add contacts for this transport so routing works over it
        establish_contacts
    done

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

        # Copy shared optimal fees into per-protocol keys
        for distance in "${DISTANCES[@]}"; do
            OPTIMAL_FEES["${protocol}_${distance}"]="${_SHARED_OPTIMAL_FEE[$distance]}"
        done

        # Fast mode sends
        printf "\n--- Fast mode sends (timeout: ${send_timeout}s) ---\n"
        for ((run=1; run<=RUNS; run++)); do
            for distance in "${DISTANCES[@]}"; do
                do_send "$protocol" "$distance" "fast" "$run" "$send_timeout"
            done
        done

        # Best mode sends
        printf "\n--- Best mode sends (timeout: ${send_timeout}s) ---\n"
        for ((run=1; run<=RUNS; run++)); do
            for distance in "${DISTANCES[@]}"; do
                do_send "$protocol" "$distance" "best" "$run" "$send_timeout"
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

        cd "$SCRIPT_DIR"
        set -e
        . "${SCRIPT_DIR}/buildfiles/collisionscluster.sh"
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

        # Enumerate paths (fresh fees each build)
        printf "\nEnumerating optimal paths...\n"
        for distance in "${DISTANCES[@]}"; do
            target="${TARGETS[$distance]}"
            all_paths=$(enumerate_paths "$SENDER" "$target")
            best_fee=$(echo "$all_paths" | head -1 | grep -oP 'fee=\K[0-9.]+')
            OPTIMAL_FEES["${protocol}_${distance}"]="$best_fee"
            path_count=$(echo "$all_paths" | wc -l | tr -d ' ')
            printf "  ${SENDER} -> %-4s (%s): %d paths, optimal fee=%sx\n" \
                "$target" "${DISTANCE_LABELS[$distance]}" "$path_count" "$best_fee"
        done

        # Fast mode sends
        printf "\n--- Fast mode sends (timeout: ${send_timeout}s) ---\n"
        for ((run=1; run<=RUNS; run++)); do
            for distance in "${DISTANCES[@]}"; do
                do_send "$protocol" "$distance" "fast" "$run" "$send_timeout"
            done
        done

        # Best mode sends
        printf "\n--- Best mode sends (timeout: ${send_timeout}s) ---\n"
        for ((run=1; run<=RUNS; run++)); do
            for distance in "${DISTANCES[@]}"; do
                do_send "$protocol" "$distance" "best" "$run" "$send_timeout"
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
print_mode_averages
print_distance_averages
print_protocol_averages
print_grand_total

echo ""
echo "Benchmark completed: $(date '+%Y-%m-%d %H:%M:%S')"
