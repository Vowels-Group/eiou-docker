#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Mutual Contact Request Test ############################
# Tests the mutual contact request auto-accept feature
#
# Scenario: When User A sends a contact request to User B, then B sends one
# back to A, the system should recognize the mutual intent and auto-accept
# on both sides — instead of leaving both stuck at "Pending Response."
#
# Test cases:
# 1. Sequential mutual request: A adds B, then B adds A → both auto-accept
# 2. No duplicate contact records on either side
# 3. Contact transactions exist on both sides
# 4. Cleanup: delete test contacts so other tests are unaffected
#
# Prerequisites:
# - Containers must be running (at least 2 containers)
# - containerAddresses array must be populated by build file
# - Does NOT require addContactsTest to have run first
#
# This test uses the FIRST and LAST containers in the topology (e.g., httpA
# and httpD in http4) which are not directly linked in containersLinks.
####################################################################################

testname="mutualContactTest"
totaltests=0
passed=0
failure=0

echo -e "\n"
echo "========================================================================"
echo "                    MUTUAL CONTACT REQUEST TEST"
echo "========================================================================"
echo -e "\n"

# Validate prerequisites
if ! validate_test_prerequisites "mutualContactTest"; then
    succesrate "0" "0" "0" "'mutual contact'"
    return 1
fi

# Need at least 2 containers
if [ ${#containers[@]} -lt 2 ]; then
    echo -e "${YELLOW}Warning: Mutual contact test requires at least 2 containers, skipping${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'mutual contact'"
    return 0
fi

# Use first and last containers (not directly linked in linear topologies)
containerA="${containers[0]}"
containerB="${containers[${#containers[@]}-1]}"

# Get addresses based on mode
if [[ "$MODE" == "http" ]] || [[ "$MODE" == "https" ]]; then
    addressA="${containerAddresses[${containerA}]}"
    addressB="${containerAddresses[${containerB}]}"
else
    addressA=$(get_tor_address "${containerA}")
    addressB=$(get_tor_address "${containerB}")
fi

transportA=$(getPhpTransportType "${addressA}")
transportB=$(getPhpTransportType "${addressB}")

echo -e "[Test Setup]"
echo -e "\t   Container A: ${containerA} (${addressA})"
echo -e "\t   Container B: ${containerB} (${addressB})"
echo -e "\t   Transport:   ${transportA}"

# ==================== CLEANUP: Remove any existing contacts between A and B ====================
echo -e "\n[Pre-test Cleanup] Removing any existing contacts between ${containerA} and ${containerB}..."

# Wipe ALL contact-related data from a container (full reset)
# This ensures no leftover state bleeds into subsequent tests
wipe_contact_data() {
    local container="$1"
    docker exec ${container} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$pdo->exec('DELETE FROM contact_currencies');
        \$pdo->exec('DELETE FROM contacts');
        \$pdo->exec('DELETE FROM addresses');
        \$pdo->exec('DELETE FROM balances');
        \$pdo->exec('DELETE FROM contact_credit');
        \$pdo->exec(\"DELETE FROM transactions WHERE memo = 'contact'\");
        echo 'WIPED';
    " 2>/dev/null || echo "ERROR"
}

wipeA=$(wipe_contact_data "${containerA}")
wipeB=$(wipe_contact_data "${containerB}")
echo -e "\t   ${containerA}: ${wipeA}, ${containerB}: ${wipeB}"

# Brief pause after cleanup
sleep 1

# ==================== TEST 1: Sequential mutual contact request ====================
echo -e "\n[Test 1/4] Sequential mutual contact request: A adds B, then B adds A"
totaltests=$((totaltests + 1))

# Step 1: A sends contact request to B
echo -e "\t   Step 1: ${containerA} adds ${containerB} as contact..."
addResultA=$(docker exec ${containerA} eiou add ${addressB} TestContactB 0.1 1000 USD 2>&1)
echo -e "\t   Result: $(echo "${addResultA}" | head -1)"

# Check A's contact status — should be 'pending' (waiting for B to accept)
statusA_step1=$(docker exec ${containerA} php -r "
    require_once('${BOOTSTRAP_PATH}');
    echo \Eiou\Core\Application::getInstance()->services->getContactRepository()->getContactStatus(
        '${transportB}','${addressB}'
    );
" 2>/dev/null || echo "error")
echo -e "\t   ${containerA}'s contact status with ${containerB}: ${statusA_step1}"

# Step 2: B sends contact request to A (mutual request)
echo -e "\t   Step 2: ${containerB} adds ${containerA} as contact (mutual request)..."
addResultB=$(docker exec ${containerB} eiou add ${addressA} TestContactA 0.1 1000 USD 2>&1)
echo -e "\t   Result: $(echo "${addResultB}" | head -1)"

# Wait briefly for async processing
sleep 2

# Check both sides — both should be 'accepted' now
statusA=$(docker exec ${containerA} php -r "
    require_once('${BOOTSTRAP_PATH}');
    echo \Eiou\Core\Application::getInstance()->services->getContactRepository()->getContactStatus(
        '${transportB}','${addressB}'
    );
" 2>/dev/null || echo "error")

statusB=$(docker exec ${containerB} php -r "
    require_once('${BOOTSTRAP_PATH}');
    echo \Eiou\Core\Application::getInstance()->services->getContactRepository()->getContactStatus(
        '${transportA}','${addressA}'
    );
" 2>/dev/null || echo "error")

echo -e "\t   ${containerA}'s contact status with ${containerB}: ${statusA}"
echo -e "\t   ${containerB}'s contact status with ${containerA}: ${statusB}"

if [[ "${statusA}" == "accepted" ]] && [[ "${statusB}" == "accepted" ]]; then
    printf "\t   ${testname} Test 1 (mutual auto-accept) ${GREEN}PASSED${NC}\n"
    passed=$((passed + 1))
else
    # Retry with queue processing
    echo -e "\t   Status not yet accepted, processing queues and retrying (10s)..."
    wait_for_queue_processed "${containerA}" 2
    wait_for_queue_processed "${containerB}" 2
    sleep 5

    statusA=$(docker exec ${containerA} php -r "
        require_once('${BOOTSTRAP_PATH}');
        echo \Eiou\Core\Application::getInstance()->services->getContactRepository()->getContactStatus(
            '${transportB}','${addressB}'
        );
    " 2>/dev/null || echo "error")

    statusB=$(docker exec ${containerB} php -r "
        require_once('${BOOTSTRAP_PATH}');
        echo \Eiou\Core\Application::getInstance()->services->getContactRepository()->getContactStatus(
            '${transportA}','${addressA}'
        );
    " 2>/dev/null || echo "error")

    echo -e "\t   After retry: ${containerA}=${statusA}, ${containerB}=${statusB}"

    if [[ "${statusA}" == "accepted" ]] && [[ "${statusB}" == "accepted" ]]; then
        printf "\t   ${testname} Test 1 (mutual auto-accept) ${GREEN}PASSED${NC}\n"
        passed=$((passed + 1))
    else
        printf "\t   ${testname} Test 1 (mutual auto-accept) ${RED}FAILED${NC} (A=${statusA}, B=${statusB})\n"
        failure=$((failure + 1))
    fi
fi

# ==================== TEST 2: No duplicate contact records ====================
echo -e "\n[Test 2/4] Verify no duplicate contact records"
totaltests=$((totaltests + 1))

countA=$(docker exec ${containerA} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$contact = \$app->services->getContactRepository()->getContactByAddress('${transportB}', '${addressB}');
    if (\$contact) {
        \$hash = hash('sha256', \$contact['pubkey']);
        \$count = \$pdo->query(\"SELECT COUNT(*) FROM contacts WHERE pubkey_hash = '\" . \$hash . \"'\")->fetchColumn();
        echo \$count;
    } else {
        echo '0';
    }
" 2>/dev/null || echo "error")

countB=$(docker exec ${containerB} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$contact = \$app->services->getContactRepository()->getContactByAddress('${transportA}', '${addressA}');
    if (\$contact) {
        \$hash = hash('sha256', \$contact['pubkey']);
        \$count = \$pdo->query(\"SELECT COUNT(*) FROM contacts WHERE pubkey_hash = '\" . \$hash . \"'\")->fetchColumn();
        echo \$count;
    } else {
        echo '0';
    }
" 2>/dev/null || echo "error")

echo -e "\t   ${containerA} contact records for ${containerB}: ${countA}"
echo -e "\t   ${containerB} contact records for ${containerA}: ${countB}"

if [[ "${countA}" == "1" ]] && [[ "${countB}" == "1" ]]; then
    printf "\t   ${testname} Test 2 (no duplicates) ${GREEN}PASSED${NC}\n"
    passed=$((passed + 1))
else
    printf "\t   ${testname} Test 2 (no duplicates) ${RED}FAILED${NC} (A has ${countA}, B has ${countB} records)\n"
    failure=$((failure + 1))
fi

# ==================== TEST 3: Contact transactions exist on both sides ====================
echo -e "\n[Test 3/4] Verify contact transactions exist"
totaltests=$((totaltests + 1))

txCountA=$(docker exec ${containerA} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$contact = \$app->services->getContactRepository()->getContactByAddress('${transportB}', '${addressB}');
    if (\$contact) {
        \$hash = hash('sha256', \$contact['pubkey']);
        \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE memo = 'contact' AND (sender_public_key_hash = '\" . \$hash . \"' OR receiver_public_key_hash = '\" . \$hash . \"')\")->fetchColumn();
        echo \$count;
    } else {
        echo '0';
    }
" 2>/dev/null || echo "error")

txCountB=$(docker exec ${containerB} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$contact = \$app->services->getContactRepository()->getContactByAddress('${transportA}', '${addressA}');
    if (\$contact) {
        \$hash = hash('sha256', \$contact['pubkey']);
        \$count = \$pdo->query(\"SELECT COUNT(*) FROM transactions WHERE memo = 'contact' AND (sender_public_key_hash = '\" . \$hash . \"' OR receiver_public_key_hash = '\" . \$hash . \"')\")->fetchColumn();
        echo \$count;
    } else {
        echo '0';
    }
" 2>/dev/null || echo "error")

echo -e "\t   ${containerA} contact transactions with ${containerB}: ${txCountA}"
echo -e "\t   ${containerB} contact transactions with ${containerA}: ${txCountB}"

if [[ "${txCountA}" -ge "1" ]] && [[ "${txCountB}" -ge "1" ]]; then
    printf "\t   ${testname} Test 3 (contact transactions) ${GREEN}PASSED${NC}\n"
    passed=$((passed + 1))
else
    printf "\t   ${testname} Test 3 (contact transactions) ${RED}FAILED${NC} (A has ${txCountA}, B has ${txCountB} txs)\n"
    failure=$((failure + 1))
fi

# ==================== TEST 4: Contact names are preserved correctly ====================
echo -e "\n[Test 4/4] Verify contact names are preserved"
totaltests=$((totaltests + 1))

nameOnA=$(docker exec ${containerA} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$contact = \Eiou\Core\Application::getInstance()->services->getContactRepository()->getContactByAddress('${transportB}', '${addressB}');
    echo \$contact ? \$contact['name'] : 'NOT_FOUND';
" 2>/dev/null || echo "error")

nameOnB=$(docker exec ${containerB} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$contact = \Eiou\Core\Application::getInstance()->services->getContactRepository()->getContactByAddress('${transportA}', '${addressA}');
    echo \$contact ? \$contact['name'] : 'NOT_FOUND';
" 2>/dev/null || echo "error")

echo -e "\t   ${containerA} calls ${containerB}: '${nameOnA}'"
echo -e "\t   ${containerB} calls ${containerA}: '${nameOnB}'"

# A chose the name "TestContactB" for B, B chose "TestContactA" for A
# Each side should have the name THEY chose preserved
if [[ "${nameOnA}" == "TestContactB" ]] && [[ "${nameOnB}" == "TestContactA" ]]; then
    printf "\t   ${testname} Test 4 (names preserved) ${GREEN}PASSED${NC}\n"
    passed=$((passed + 1))
else
    printf "\t   ${testname} Test 4 (names preserved) ${RED}FAILED${NC} (A='${nameOnA}', B='${nameOnB}')\n"
    failure=$((failure + 1))
fi

# ==================== POST-TEST CLEANUP ====================
# Wipe ALL contact-related data from ALL containers so subsequent tests
# (addContactsTest, routing, etc.) start with a completely clean slate
echo -e "\n[Post-test Cleanup] Wiping all contact data from all containers..."
for container in "${containers[@]}"; do
    wipeResult=$(wipe_contact_data "${container}")
    echo -e "\t   ${container}: ${wipeResult}"
done

# ==================== SUMMARY ====================
echo ""
succesrate "${totaltests}" "${passed}" "${failure}" "'mutual contact'"

####################################################################################
