#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

############################### Test Helper Functions #################################
# Common helper functions shared across test suites
# This library reduces code duplication by extracting common patterns
###################################################################################

# ==================== Test Prerequisites Validation ====================

# Validate prerequisites before running a test suite
# Sets global variables: TOR_AVAILABLE, TOR_REQUIRED
# Usage: validate_test_prerequisites <test_suite_name>
# Returns: 0 on success, 1 on validation failure
validate_test_prerequisites() {
    local suite_name="${1:-test suite}"

    # Validate that containers array is defined and non-empty
    if [[ -z "${containers[0]}" ]]; then
        echo -e "${RED}ERROR: containers array is not defined or empty${NC}"
        echo -e "${YELLOW}This test suite must be run after sourcing a build file (e.g., http4.sh)${NC}"
        echo -e "${YELLOW}Example: . ./buildfiles/http4.sh && . ./testfiles/${suite_name}.sh${NC}"
        return 1
    fi

    # Validate network is defined
    if [[ -z "${network}" ]]; then
        echo -e "${RED}ERROR: network variable is not defined${NC}"
        return 1
    fi

    # Verify the first container exists and is running
    local testContainer="${containers[0]}"
    if ! docker ps --format '{{.Names}}' | grep -q "^${testContainer}$"; then
        echo -e "${RED}ERROR: Container '${testContainer}' is not running${NC}"
        echo -e "${YELLOW}Available containers:${NC}"
        docker ps --format '{{.Names}}' | sed 's/^/  - /'
        return 1
    fi

    echo -e "${GREEN}Validation passed: Testing ${#containers[@]} containers on network '${network}'${NC}\n"

    # Detect if Tor hidden service is available by checking if hostname file exists
    TOR_AVAILABLE=$(docker exec ${testContainer} test -f ${TOR_HOSTNAME} && echo "true" || echo "false")

    # Determine if we should fail on Tor unavailability
    # In TOR mode (MODE="tor"), Tor must be available - failures are real failures
    # In HTTP mode (MODE="http"), Tor is optional - skip with warning if unavailable
    if [[ "$MODE" == "tor" ]]; then
        TOR_REQUIRED="true"
    else
        TOR_REQUIRED="false"
    fi

    # Export for use in test suites
    export TOR_AVAILABLE
    export TOR_REQUIRED

    return 0
}

# ==================== Container Selection ====================

# Get first container pair for testing
# Sets global: sender, receiver, senderAddress, receiverAddress
# Returns: 0 on success, 1 if no container links defined
get_container_pair() {
    containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
    testPair="${containersLinkKeys[0]}"

    if [[ -z "$testPair" ]]; then
        return 1
    fi

    containerKeys=(${testPair//,/ })
    sender="${containerKeys[0]}"
    receiver="${containerKeys[1]}"
    senderAddress="${containerAddresses[${sender}]}"
    receiverAddress="${containerAddresses[${receiver}]}"
    return 0
}

# ==================== Public Key Functions ====================

# Get public key info for a contact
# Usage: get_pubkey_info <container> <mode> <address>
# Returns: "base64_pubkey|sha256_hash" or "ERROR|ERROR"
get_pubkey_info() {
    local container="$1"
    local mode="$2"
    local address="$3"
    docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pubkey = \$app->services->getContactRepository()->getContactPubkey('${mode}','${address}');
        if (\$pubkey) {
            echo base64_encode(\$pubkey) . '|' . hash('sha256', \$pubkey);
        } else {
            echo 'ERROR|ERROR';
        }
    " 2>/dev/null || echo "ERROR|ERROR"
}

# Parse pubkey info returned by get_pubkey_info
# Usage: parse_pubkey_b64 <pubkey_info>
parse_pubkey_b64() {
    echo "$1" | cut -d'|' -f1
}

# Usage: parse_pubkey_hash <pubkey_info>
parse_pubkey_hash() {
    echo "$1" | cut -d'|' -f2
}

# ==================== Contact Management ====================

# Ensure bidirectional contacts exist between two containers
# Usage: ensure_contacts <sender> <receiver> <sender_addr> <receiver_addr>
ensure_contacts() {
    local s="$1"
    local r="$2"
    local s_addr="$3"
    local r_addr="$4"

    docker exec ${s} eiou add ${r_addr} ${r} 0 0 USD 2>&1 > /dev/null || true
    docker exec ${r} eiou add ${s_addr} ${s} 0 0 USD 2>&1 > /dev/null || true
    sleep 2
}

# ==================== Transaction Functions ====================

# Get transaction count matching pattern
# Usage: get_tx_count_by_desc <container> <description_pattern>
get_tx_count_by_desc() {
    local container="$1"
    local pattern="$2"
    docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE description LIKE '${pattern}'\")->fetchColumn();
        echo \$count;
    " 2>/dev/null || echo "0"
}

# Get transaction count for a pubkey hash
# Usage: get_tx_count_by_pubkey <container> <pubkey_hash>
get_tx_count_by_pubkey() {
    local container="$1"
    local pubkey_hash="$2"
    docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE
            (sender_public_key_hash = '${pubkey_hash}' OR receiver_public_key_hash = '${pubkey_hash}')
            AND memo = 'standard'\")->fetchColumn();
        echo \$count;
    " 2>/dev/null || echo "0"
}

# Cleanup test transactions
# Usage: cleanup_transactions <container> <pattern>
cleanup_transactions() {
    local container="$1"
    local pattern="$2"
    docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE '${pattern}'\");
        echo 'DELETED:' . \$deleted;
    " 2>/dev/null || echo "ERROR"
}

# Delete all transactions for a pubkey hash
# Usage: delete_tx_by_pubkey <container> <pubkey_hash>
delete_tx_by_pubkey() {
    local container="$1"
    local pubkey_hash="$2"
    docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$deleted = \$pdo->exec(\"DELETE FROM transactions WHERE
            (sender_public_key_hash = '${pubkey_hash}' OR receiver_public_key_hash = '${pubkey_hash}')
            AND memo = 'standard'\");
        echo \$deleted;
    " 2>/dev/null || echo "0"
}

# Send test transaction
# Usage: send_test_tx <sender_container> <receiver_address> <amount> <memo>
send_test_tx() {
    local sender="$1"
    local receiver_addr="$2"
    local amount="$3"
    local memo="$4"
    docker exec ${sender} eiou send ${receiver_addr} ${amount} USD "${memo}" 2>&1
}

# ==================== Method Verification ====================

# Check if a method exists on a service
# Usage: check_method_exists <container> <service_getter> <method_name>
check_method_exists() {
    local container="$1"
    local service_getter="$2"
    local method_name="$3"
    docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$service = \$app->services->${service_getter}();
        echo method_exists(\$service, '${method_name}') ? 'EXISTS' : 'MISSING';
    " 2>/dev/null || echo "ERROR"
}

# ==================== TOR Functions ====================

# Check if TOR is running
# Usage: check_tor_running <container>
# Returns: 0 if running, 1 if not
check_tor_running() {
    local container="$1"
    docker exec $container service tor status 2>&1 | grep -q "running"
}

# Get TOR address from userconfig
# Usage: get_tor_address <container>
get_tor_address() {
    local container="$1"
    docker exec $container php -r '
        $json = json_decode(file_get_contents("'"${USERCONFIG}"'"),true);
        if (isset($json["torAddress"])) {
            echo $json["torAddress"];
        }
    '
}

# Check SOCKS proxy is listening
# Usage: check_socks_proxy <container>
check_socks_listening() {
    local container="$1"
    docker exec $container sh -c 'ss -tlnp 2>/dev/null | grep 9050 || netstat -tlnp 2>/dev/null | grep 9050'
}

# Verify Tor hidden service key files exist and have correct sizes
# Usage: verify_tor_key_files <container>
# Returns: status string - "PASSED|secret_size|public_size", "SKIPPED", "WARNING|secret_size|public_size", "FAILED|details"
# Uses global: TOR_AVAILABLE, TOR_REQUIRED, TOR_SECRET_KEY, TOR_PUBLIC_KEY, TOR_HOSTNAME
verify_tor_key_files() {
    local container="$1"

    # Skip if Tor is not available and not required
    if [[ "$TOR_AVAILABLE" == "false" ]] && [[ "$TOR_REQUIRED" != "true" ]]; then
        echo "SKIPPED"
        return 0
    fi

    # Check for Tor hidden service files
    local secretKeyExists=$(docker exec ${container} test -f ${TOR_SECRET_KEY} && echo "EXISTS" || echo "NOT_FOUND")
    local publicKeyExists=$(docker exec ${container} test -f ${TOR_PUBLIC_KEY} && echo "EXISTS" || echo "NOT_FOUND")
    local hostnameExists=$(docker exec ${container} test -f ${TOR_HOSTNAME} && echo "EXISTS" || echo "NOT_FOUND")

    if [[ "$secretKeyExists" == "EXISTS" ]] && [[ "$publicKeyExists" == "EXISTS" ]] && [[ "$hostnameExists" == "EXISTS" ]]; then
        # Verify file sizes are correct
        local secretKeySize=$(docker exec ${container} stat -c '%s' ${TOR_SECRET_KEY} 2>/dev/null)
        local publicKeySize=$(docker exec ${container} stat -c '%s' ${TOR_PUBLIC_KEY} 2>/dev/null)

        # Secret key should be 96 bytes (32-byte header + 64-byte key)
        # Public key should be 64 bytes (32-byte header + 32-byte key)
        if [[ "$secretKeySize" -eq 96 ]] && [[ "$publicKeySize" -eq 64 ]]; then
            echo "PASSED|${secretKeySize}|${publicKeySize}"
            return 0
        else
            echo "WARNING|${secretKeySize}|${publicKeySize}"
            return 0
        fi
    else
        echo "FAILED|Secret:${secretKeyExists}|Public:${publicKeyExists}|Hostname:${hostnameExists}"
        return 1
    fi
}

# Handle Tor key file verification result and print appropriate message
# Usage: handle_tor_key_result <result> <label> <container>
# Updates global: passed, failure
handle_tor_key_result() {
    local result="$1"
    local label="$2"
    local container="$3"

    local status=$(echo "$result" | cut -d'|' -f1)

    case "$status" in
        "SKIPPED")
            printf "\t   %s for %s ${YELLOW}SKIPPED${NC} (HTTP mode)\n" "$label" "$container"
            passed=$(( passed + 1 ))
            ;;
        "PASSED")
            local secretSize=$(echo "$result" | cut -d'|' -f2)
            local publicSize=$(echo "$result" | cut -d'|' -f3)
            printf "\t   %s verified ${GREEN}PASSED${NC}\n" "$label"
            printf "\t   Secret key: ${secretSize} bytes, Public key: ${publicSize} bytes\n"
            passed=$(( passed + 1 ))
            ;;
        "WARNING")
            local secretSize=$(echo "$result" | cut -d'|' -f2)
            local publicSize=$(echo "$result" | cut -d'|' -f3)
            printf "\t   %s have unexpected sizes ${YELLOW}WARNING${NC}\n" "$label"
            printf "\t   Secret key: ${secretSize} bytes (expected 96), Public key: ${publicSize} bytes (expected 64)\n"
            passed=$(( passed + 1 ))
            ;;
        "FAILED")
            local details=$(echo "$result" | cut -d'|' -f2-)
            printf "\t   %s ${RED}FAILED${NC}\n" "$label"
            printf "\t   ${details}\n"
            failure=$(( failure + 1 ))
            ;;
    esac
}

# ==================== Chain Verification ====================

# Get transaction chain info for debugging
# Usage: get_chain_info <container> <pubkey_hash>
get_chain_info() {
    local container="$1"
    local pubkey_hash="$2"
    docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$stmt = \$pdo->query(\"SELECT txid, previous_txid, status FROM transactions
            WHERE (sender_public_key_hash = '${pubkey_hash}' OR receiver_public_key_hash = '${pubkey_hash}')
            AND memo = 'standard'
            ORDER BY COALESCE(time, 0) ASC, timestamp ASC\");
        \$txs = \$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach (\$txs as \$tx) {
            echo substr(\$tx['txid'], 0, 8) . '->' . (isset(\$tx['previous_txid']) ? substr(\$tx['previous_txid'], 0, 8) : 'NULL') . ':' . \$tx['status'] . '|';
        }
    " 2>/dev/null || echo "ERROR"
}

# Verify chain integrity
# Usage: verify_chain_integrity <container> <pubkey_hash>
verify_chain_integrity() {
    local container="$1"
    local pubkey_hash="$2"
    docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$stmt = \$pdo->query(\"SELECT txid, previous_txid FROM transactions
            WHERE (sender_public_key_hash = '${pubkey_hash}' OR receiver_public_key_hash = '${pubkey_hash}')
            AND memo = 'standard'\");
        \$txs = \$stmt->fetchAll(PDO::FETCH_ASSOC);

        \$txids = array_column(\$txs, 'txid');
        \$issues = [];

        foreach (\$txs as \$tx) {
            if (\$tx['previous_txid'] !== null && !in_array(\$tx['previous_txid'], \$txids)) {
                \$exists = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE txid = '\" . \$tx['previous_txid'] . \"'\")->fetchColumn();
                if (\$exists == 0) {
                    \$issues[] = substr(\$tx['txid'], 0, 8) . ' points to missing ' . substr(\$tx['previous_txid'], 0, 8);
                }
            }
        }

        if (empty(\$issues)) {
            echo 'VALID:' . count(\$txs);
        } else {
            echo 'INVALID:' . implode(',', \$issues);
        }
    " 2>/dev/null || echo "ERROR"
}

# ==================== Retry Helpers for Time-Dependent Tests ====================

# Wait for transaction count to reach expected value with retry
# Usage: wait_for_tx_count <container> <pattern> <expected_count> <timeout>
# Returns: actual count (echoed), return code 0 if reached, 1 if timeout
wait_for_tx_count() {
    local container="$1"
    local pattern="$2"
    local expected_count="$3"
    local timeout="${4:-10}"
    local elapsed=0
    local count

    while [ $elapsed -lt $timeout ]; do
        count=$(get_tx_count "$container" "$pattern")

        if [ "$count" -ge "$expected_count" ]; then
            echo "$count"
            return 0
        fi

        sleep 2
        elapsed=$((elapsed + 2))
    done

    # Timeout - return current count
    echo "$count"
    return 1
}

# Check transaction count with retry on failure
# Usage: check_tx_count_with_retry <container> <pattern> <expected_count> <retry_delay>
# Returns: actual count (echoed)
check_tx_count_with_retry() {
    local container="$1"
    local pattern="$2"
    local expected_count="$3"
    local retry_delay="${4:-10}"

    # First check
    local count=$(get_tx_count "$container" "$pattern")

    # If below expected, wait and retry
    if [ "$count" -lt "$expected_count" ]; then
        sleep "$retry_delay"
        # Process queues again during retry
        process_all_queues 2>/dev/null || true
        count=$(get_tx_count "$container" "$pattern")
    fi

    echo "$count"
}

# ==================== Contact Status Functions ====================

# Check contact status with single retry on failure
# Usage: check_contact_status_with_retry <container> <transport_type> <address> <retry_delay>
# Returns: status string (echoed)
# Does NOT loop - waits once, retries once, returns final status
check_contact_status_with_retry() {
    local container="$1"
    local transport_type="$2"
    local address="$3"
    local retry_delay="${4:-10}"

    # First check
    local status=$(docker exec ${container} php -r "
        require_once('${REL_APPLICATION}');
        echo Application::getInstance()->services->getContactRepository()->getContactStatus(
            '""${transport_type}""','""${address}""'
        );
    " 2>/dev/null || echo "error")

    # If not accepted, wait once and retry (no loop)
    if [ "$status" != "accepted" ]; then
        echo -e "\t   Status is '${status}', waiting ${retry_delay}s for retry..." >&2
        sleep "$retry_delay"
        # Process message queues during retry
        docker exec ${container} eiou out 2>/dev/null || true
        docker exec ${container} eiou in 2>/dev/null || true
        # Retry check
        status=$(docker exec ${container} php -r "
            require_once('${REL_APPLICATION}');
            echo Application::getInstance()->services->getContactRepository()->getContactStatus(
                '""${transport_type}""','""${address}""'
            );
        " 2>/dev/null || echo "error")
    fi

    echo "$status"
}

# ==================== Sync Functions ====================

# Trigger sync for a container
# Usage: trigger_sync <container> <receiver_address> <receiver_pubkey_b64>
trigger_sync() {
    local container="$1"
    local receiver_addr="$2"
    local receiver_pubkey_b64="$3"
    docker exec ${container} php -r "
        require_once('${REL_FUNCTIONS}');
        \$app = Application::getInstance();
        \$syncService = \$app->services->getSyncService();
        \$receiverPubkey = base64_decode('${receiver_pubkey_b64}');

        \$result = \$syncService->syncTransactionChain('${receiver_addr}', \$receiverPubkey);

        if (\$result['success']) {
            echo 'SYNC_SUCCESS:' . \$result['synced_count'];
        } else {
            echo 'SYNC_FAILED:' . (\$result['error'] ?? 'unknown');
        }
    " 2>/dev/null || echo "ERROR"
}

###################################################################################
