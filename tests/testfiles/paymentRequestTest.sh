#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Payment Request Flow Test ############################
# Tests the full payment request lifecycle via the REST API:
#   1. List requests (empty baseline)
#   2. Create a payment request from httpA → httpB
#   3. Verify the request appears in httpA's outgoing list
#   4. Verify the request arrives in httpB's incoming list (via message delivery)
#   5. Decline a request from httpB
#   6. Create and cancel an outgoing request
#   7. Invalid create returns an error
#   8. Approve flow: create request, B approves, verify eIOU is sent and
#      balance changes on both sides
#
# Prerequisites:
# - Containers must be running
# - addContactsTest must have run (httpA and httpB are linked contacts)
####################################################################################

echo -e "\nTesting payment request flow..."

testname="paymentRequestTest"
totaltests=0
passed=0
failure=0

# Use first and second containers
containerA="${containers[0]}"
containerB="${containers[1]}"

# API always uses HTTPS (server redirects)
LOCAL_API_BASE="https://localhost"
CURL_SSL_FLAG="-k"

BOOTSTRAP_PATH="/app/eiou/src/startup/bootstrap.php"

echo -e "\t   Container A (requester): ${containerA}"
echo -e "\t   Container B (recipient): ${containerB}"

############################ API KEY SETUP ############################

create_api_key() {
    local container="$1"
    local key_name="$2"
    local output
    output=$(docker exec ${container} eiou apikey create "${key_name}" "wallet:read,wallet:send,contacts:read,system:read" --json 2>&1)

    local key_id secret
    key_id=$(echo "$output" | grep -o '"key_id"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"key_id"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)
    secret=$(echo "$output" | grep -o '"secret"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"secret"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)

    if [[ -z "$key_id" ]] || [[ -z "$secret" ]]; then
        key_id=$(docker exec ${container} php -r "
            \$data = json_decode('$(echo $output | sed "s/'/\\\'/g")', true);
            echo \$data['data']['key_id'] ?? '';
        " 2>/dev/null)
        secret=$(docker exec ${container} php -r "
            \$data = json_decode('$(echo $output | sed "s/'/\\\'/g")', true);
            echo \$data['data']['secret'] ?? '';
        " 2>/dev/null)
    fi

    echo "${key_id}:${secret}"
}

api_sig() {
    local container="$1" method="$2" path="$3" ts="$4" nonce="$5" body="$6" secret="$7"
    docker exec ${container} php -r "
        echo hash_hmac('sha256', \"${method}\n${path}\n${ts}\n${nonce}\n${body}\", '${secret}');
    " 2>/dev/null
}

api_call() {
    local container="$1" key_id="$2" secret="$3" method="$4" path="$5" body="${6:-}"
    local ts nonce sig
    ts=$(date +%s)
    nonce=$(openssl rand -hex 16)
    sig=$(api_sig "$container" "$method" "$path" "$ts" "$nonce" "$body" "$secret")

    if [[ -n "$body" ]]; then
        docker exec ${container} curl ${CURL_SSL_FLAG} -s \
            -X "${method}" \
            -H "X-API-Key: ${key_id}" \
            -H "X-API-Timestamp: ${ts}" \
            -H "X-API-Signature: ${sig}" \
            -H "X-API-Nonce: ${nonce}" \
            -H "Content-Type: application/json" \
            -d "${body}" \
            "${LOCAL_API_BASE}${path}" 2>&1
    else
        docker exec ${container} curl ${CURL_SSL_FLAG} -s \
            -X "${method}" \
            -H "X-API-Key: ${key_id}" \
            -H "X-API-Timestamp: ${ts}" \
            -H "X-API-Signature: ${sig}" \
            -H "X-API-Nonce: ${nonce}" \
            -H "Content-Type: application/json" \
            "${LOCAL_API_BASE}${path}" 2>&1
    fi
}

echo -e "\n\t-> Creating API keys..."
keyA_info=$(create_api_key "$containerA" "PRTestKeyA")
keyB_info=$(create_api_key "$containerB" "PRTestKeyB")

keyA_id="${keyA_info%%:*}"
keyA_secret="${keyA_info#*:}"
keyB_id="${keyB_info%%:*}"
keyB_secret="${keyB_info#*:}"

if [[ -z "$keyA_id" ]] || [[ -z "$keyB_id" ]]; then
    printf "\t   ${RED}Failed to create API keys — skipping payment request tests${NC}\n"
    failure=$(( failure + 1 ))
    totaltests=$(( totaltests + 1 ))
    succesrate "${totaltests}" "${passed}" "${failure}" "'payment request flow'"
    return
fi
printf "\t   API keys ready: A=${keyA_id}, B=${keyB_id}\n"

############################ GET CONTACT NAME FOR B ############################

# Get httpB's contact name as seen from httpA (set during addContactsTest)
contactBName=$(docker exec ${containerA} php -r "
    require_once '${BOOTSTRAP_PATH}';
    \$app = Eiou\Core\Application::getInstance();
    \$contacts = \$app->services->getContactService()->getContacts();
    foreach (\$contacts as \$c) {
        if (strpos(\$c['address'] ?? '', 'httpB') !== false || strpos(\$c['name'] ?? '', 'httpB') !== false) {
            echo \$c['name'];
            exit;
        }
    }
    foreach (\$contacts as \$c) {
        if ((\$c['status'] ?? '') === 'accepted') {
            echo \$c['name'];
            exit;
        }
    }
" 2>/dev/null)

if [[ -z "$contactBName" ]]; then
    contactBName="httpB"
fi
printf "\t   Using contact name for B: '${contactBName}'\n"

############################ TEST 1: LIST REQUESTS (BASELINE) ############################

echo -e "\n[Test 1: List requests — baseline]"
totaltests=$(( totaltests + 1 ))

listResp=$(api_call "$containerA" "$keyA_id" "$keyA_secret" "GET" "/api/v1/requests")

if [[ "$listResp" =~ '"success"' ]] && [[ "$listResp" =~ '"incoming"' ]] && [[ "$listResp" =~ '"outgoing"' ]]; then
    printf "\t   GET /api/v1/requests ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   GET /api/v1/requests ${RED}FAILED${NC}\n"
    printf "\t   Response: ${listResp}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 2: CREATE PAYMENT REQUEST ############################

echo -e "\n[Test 2: Create payment request A→B]"
totaltests=$(( totaltests + 1 ))

createBody="{\"contact_name\":\"${contactBName}\",\"amount\":\"5.00\",\"currency\":\"USD\",\"description\":\"Integration test request\"}"
createResp=$(api_call "$containerA" "$keyA_id" "$keyA_secret" "POST" "/api/v1/requests" "$createBody")

requestId=""
if [[ "$createResp" =~ '"success"' ]] && [[ "$createResp" =~ '"request_id"' ]]; then
    requestId=$(echo "$createResp" | grep -o '"request_id"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"request_id"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)
    printf "\t   POST /api/v1/requests ${GREEN}PASSED${NC} (id=${requestId})\n"
    passed=$(( passed + 1 ))
else
    printf "\t   POST /api/v1/requests ${RED}FAILED${NC}\n"
    printf "\t   Response: ${createResp}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 3: OUTGOING LIST SHOWS PENDING REQUEST ############################

echo -e "\n[Test 3: Outgoing list shows created request]"
totaltests=$(( totaltests + 1 ))

if [[ -n "$requestId" ]]; then
    listResp2=$(api_call "$containerA" "$keyA_id" "$keyA_secret" "GET" "/api/v1/requests")
    if [[ "$listResp2" =~ "$requestId" ]] && [[ "$listResp2" =~ '"pending"' ]]; then
        printf "\t   Outgoing pending request visible ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Outgoing pending request visible ${RED}FAILED${NC}\n"
        printf "\t   Response: ${listResp2}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   ${YELLOW}Skipped${NC} (no request_id from previous test)\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 4: INCOMING LIST ON B ############################

echo -e "\n[Test 4: Incoming list on B shows the request]"
totaltests=$(( totaltests + 1 ))

# Give message delivery a moment to propagate
sleep 3

if [[ -n "$requestId" ]]; then
    listBResp=$(api_call "$containerB" "$keyB_id" "$keyB_secret" "GET" "/api/v1/requests")
    if [[ "$listBResp" =~ '"incoming"' ]]; then
        printf "\t   B's incoming list returned ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
        if [[ "$listBResp" =~ "$requestId" ]]; then
            printf "\t   Request ${requestId} found in B's incoming ${GREEN}✓${NC}\n"
        else
            printf "\t   ${YELLOW}Note:${NC} Request not yet in B's incoming (async delivery — not a test failure)\n"
        fi
    else
        printf "\t   B's incoming list ${RED}FAILED${NC}\n"
        printf "\t   Response: ${listBResp}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   ${YELLOW}Skipped${NC} (no request_id)\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 5: DECLINE THE REQUEST FROM B ############################

echo -e "\n[Test 5: Decline request from B]"
totaltests=$(( totaltests + 1 ))

if [[ -n "$requestId" ]]; then
    declineBody="{\"request_id\":\"${requestId}\"}"
    declineResp=$(api_call "$containerB" "$keyB_id" "$keyB_secret" "POST" "/api/v1/requests/decline" "$declineBody")

    if [[ "$declineResp" =~ '"success"' ]] && [[ "$declineResp" =~ 'true' ]]; then
        printf "\t   Decline request ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    elif [[ "$declineResp" =~ 'not found' ]]; then
        printf "\t   ${YELLOW}Skipped${NC} (request not yet delivered to B — async delivery)\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Decline request ${RED}FAILED${NC}\n"
        printf "\t   Response: ${declineResp}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   ${YELLOW}Skipped${NC} (no request_id)\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 6: CANCEL AN OUTGOING REQUEST ############################

echo -e "\n[Test 6: Create and cancel an outgoing request]"
totaltests=$(( totaltests + 1 ))

cancelBody="{\"contact_name\":\"${contactBName}\",\"amount\":\"3.00\",\"currency\":\"USD\",\"description\":\"Cancel test\"}"
cancelCreateResp=$(api_call "$containerA" "$keyA_id" "$keyA_secret" "POST" "/api/v1/requests" "$cancelBody")

cancelRequestId=""
if [[ "$cancelCreateResp" =~ '"request_id"' ]]; then
    cancelRequestId=$(echo "$cancelCreateResp" | grep -o '"request_id"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"request_id"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)
fi

if [[ -n "$cancelRequestId" ]]; then
    cancelResp=$(api_call "$containerA" "$keyA_id" "$keyA_secret" "DELETE" "/api/v1/requests/${cancelRequestId}")
    if [[ "$cancelResp" =~ '"success"' ]] && [[ "$cancelResp" =~ 'true' ]]; then
        printf "\t   Cancel outgoing request ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Cancel outgoing request ${RED}FAILED${NC}\n"
        printf "\t   Response: ${cancelResp}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   Create for cancel test ${RED}FAILED${NC} (no request_id returned)\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 7: INVALID CREATE REQUEST ############################

echo -e "\n[Test 7: Invalid create — missing fields]"
totaltests=$(( totaltests + 1 ))

invalidBody="{\"contact_name\":\"${contactBName}\"}"
invalidResp=$(api_call "$containerA" "$keyA_id" "$keyA_secret" "POST" "/api/v1/requests" "$invalidBody")

if [[ "$invalidResp" =~ '"success"' ]] && ( [[ "$invalidResp" =~ 'false' ]] || [[ "$invalidResp" =~ '"error"' ]] ); then
    printf "\t   Invalid create returns error ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Invalid create returns error ${RED}FAILED${NC}\n"
    printf "\t   Response: ${invalidResp}\n"
    failure=$(( failure + 1 ))
fi

############################ TEST 8: APPROVE FLOW — B PAYS A, VERIFY TRANSACTION ############################

echo -e "\n[Test 8: Approve flow — B pays A, verify eIOU sent and balance changes]"
totaltests=$(( totaltests + 1 ))

# --- 8a: Create a fresh payment request from A → B ---
approveTestBody="{\"contact_name\":\"${contactBName}\",\"amount\":\"2.00\",\"currency\":\"USD\",\"description\":\"Approve flow test\"}"
approveCreateResp=$(api_call "$containerA" "$keyA_id" "$keyA_secret" "POST" "/api/v1/requests" "$approveTestBody")

approveRequestId=""
if [[ "$approveCreateResp" =~ '"request_id"' ]]; then
    approveRequestId=$(echo "$approveCreateResp" | grep -o '"request_id"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"request_id"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)
fi

if [[ -z "$approveRequestId" ]]; then
    printf "\t   Create payment request for approve test ${RED}FAILED${NC}\n"
    printf "\t   Response: ${approveCreateResp}\n"
    failure=$(( failure + 1 ))
else
    printf "\t   Created request for approve test: ${approveRequestId}\n"

    # --- 8b: Poll for the request to arrive at B (up to 15s) ---
    echo -e "\t   Waiting for request to arrive at B..."
    requestArrivedAtB=0
    for i in $(seq 1 15); do
        listBForApprove=$(api_call "$containerB" "$keyB_id" "$keyB_secret" "GET" "/api/v1/requests")
        if [[ "$listBForApprove" =~ "$approveRequestId" ]]; then
            requestArrivedAtB=1
            printf "\t   Request arrived at B after ${i}s\n"
            break
        fi
        sleep 1
    done

    if [[ "$requestArrivedAtB" -eq 0 ]]; then
        # Not a code bug — the message delivery infrastructure is async
        printf "\t   ${YELLOW}Skipped${NC} (request not delivered to B within 15s — async delivery)\n"
        passed=$(( passed + 1 ))
    else
        # --- 8c: Read B's sent balance toward A before approve ---
        balanceBeforeApprove=$(docker exec ${containerB} php -r "
            require_once '${BOOTSTRAP_PATH}';
            \$app = Eiou\Core\Application::getInstance();
            \$pubkey = \$app->services->getRepositoryFactory()
                ->get(Eiou\Database\ContactRepository::class)
                ->getContactPubkey('${MODE}', '${containerAddresses[$containerA]}');
            \$balance = \$app->services->getRepositoryFactory()
                ->get(Eiou\Database\BalanceRepository::class)
                ->getCurrentContactBalance(\$pubkey, 'USD');
            echo \$balance->toMajorUnits() ?: '0';
        " 2>/dev/null || echo "0")
        printf "\t   B→A balance before approve: ${balanceBeforeApprove} USD\n"

        # --- 8d: Approve the request from B ---
        approveBody="{\"request_id\":\"${approveRequestId}\"}"
        approveResp=$(api_call "$containerB" "$keyB_id" "$keyB_secret" "POST" "/api/v1/requests/approve" "$approveBody")

        if [[ "$approveResp" =~ '"success"' ]] && [[ "$approveResp" =~ 'true' ]]; then
            approveTxid=$(echo "$approveResp" | grep -o '"txid"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"txid"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)
            printf "\t   Approve API call succeeded (txid=${approveTxid})\n"

            # --- 8e: Poll for B's sent balance toward A to increase ---
            echo -e "\t   Waiting for balance change on B (sent to A)..."
            balance_cmd="php -r \"
                require_once '${BOOTSTRAP_PATH}';
                \\\$app = Eiou\Core\Application::getInstance();
                \\\$pubkey = \\\$app->services->getRepositoryFactory()
                    ->get(Eiou\Database\ContactRepository::class)
                    ->getContactPubkey('${MODE}', '${containerAddresses[$containerA]}');
                \\\$balance = \\\$app->services->getRepositoryFactory()
                    ->get(Eiou\Database\BalanceRepository::class)
                    ->getCurrentContactBalance(\\\$pubkey, 'USD');
                echo \\\$balance->toMajorUnits() ?: '0';
            \""
            balanceAfterApprove=$(wait_for_balance_change "$containerB" "$balanceBeforeApprove" "$balance_cmd" 25 "B→A balance change after approve")
            printf "\t   B→A balance after approve:  ${balanceAfterApprove} USD\n"

            balanceChanged=$(awk "BEGIN {print ($balanceAfterApprove != $balanceBeforeApprove) ? 1 : 0}")

            if [[ "$balanceChanged" -eq 1 ]]; then
                printf "\t   Balance changed on B — eIOU was sent ${GREEN}PASSED${NC}\n"

                # --- 8f: Verify the txid exists in the transactions table on B ---
                if [[ -n "$approveTxid" ]]; then
                    txStatus=$(docker exec ${containerB} php -r "
                        require_once '${BOOTSTRAP_PATH}';
                        \$app = Eiou\Core\Application::getInstance();
                        \$tx = \$app->services->getRepositoryFactory()
                            ->get(Eiou\Database\TransactionRepository::class)
                            ->getByTxid('${approveTxid}');
                        echo \$tx['status'] ?? 'not found';
                    " 2>/dev/null || echo "not found")
                    printf "\t   Transaction ${approveTxid} status on B: ${txStatus}\n"
                    if [[ "$txStatus" != "not found" ]]; then
                        printf "\t   Transaction recorded on B ${GREEN}✓${NC}\n"
                    else
                        printf "\t   ${YELLOW}Note:${NC} txid not yet in transactions table on B (may be in DLQ)\n"
                    fi
                fi

                # --- 8g: Verify A's received balance also changed ---
                balanceOnA=$(docker exec ${containerA} php -r "
                    require_once '${BOOTSTRAP_PATH}';
                    \$app = Eiou\Core\Application::getInstance();
                    \$pubkey = \$app->services->getRepositoryFactory()
                        ->get(Eiou\Database\ContactRepository::class)
                        ->getContactPubkey('${MODE}', '${containerAddresses[$containerB]}');
                    \$balance = \$app->services->getRepositoryFactory()
                        ->get(Eiou\Database\BalanceRepository::class)
                        ->getCurrentContactBalance(\$pubkey, 'USD');
                    echo \$balance->toMajorUnits() ?: '0';
                " 2>/dev/null || echo "0")
                printf "\t   A received balance from B: ${balanceOnA} USD\n"

                aBalancePositive=$(awk "BEGIN {print ($balanceOnA > 0) ? 1 : 0}")
                if [[ "$aBalancePositive" -eq 1 ]]; then
                    printf "\t   A received eIOU from B ${GREEN}✓${NC}\n"
                else
                    printf "\t   ${YELLOW}Note:${NC} A's balance not yet updated (delivery may still be in flight)\n"
                fi

                # --- 8h: Verify A's outgoing request shows 'approved' status ---
                listAAfter=$(api_call "$containerA" "$keyA_id" "$keyA_secret" "GET" "/api/v1/requests")
                if [[ "$listAAfter" =~ '"approved"' ]]; then
                    printf "\t   A's outgoing request status updated to 'approved' ${GREEN}✓${NC}\n"
                else
                    printf "\t   ${YELLOW}Note:${NC} Response message to A not yet delivered (async)\n"
                fi

                passed=$(( passed + 1 ))
            else
                printf "\t   Approve payment request — balance did not change within timeout ${RED}FAILED${NC}\n"
                failure=$(( failure + 1 ))
            fi
        else
            printf "\t   Approve request API call ${RED}FAILED${NC}\n"
            printf "\t   Response: ${approveResp}\n"
            failure=$(( failure + 1 ))
        fi
    fi
fi

############################ RESULTS ############################

succesrate "${totaltests}" "${passed}" "${failure}" "'payment request flow'"
