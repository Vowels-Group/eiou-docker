#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Chunked Sync Test ############################
# Tests for chunked transaction sync protocol:
# - Responder returns hasMore=true when transaction count > SYNC_CHUNK_SIZE
# - Responder returns hasMore=false when under chunk size
# - Full chunked sync end-to-end with cursor advancement
# - Constants are properly defined
########################################################################

# Helper functions are sourced via config.sh -> testHelpers.sh

testname="chunkedSyncTest"
totaltests=0
passed=0
failure=0

echo -e "\n"
echo "========================================================================"
echo "                    CHUNKED SYNC TEST"
echo "========================================================================"
echo -e "\n"

# Setup: Get container pair and public keys (same pattern as syncTestSuite)
if [[ ${#containersLinks[@]} -gt 0 ]]; then
    containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
    firstLinkKey="${containersLinkKeys[0]}"
    linkParts=(${firstLinkKey//,/ })
    sender="${linkParts[0]}"
    receiver="${linkParts[1]}"
else
    sender="${containers[0]}"
    receiver="${containers[${#containers[@]}-1]}"
fi

senderAddress="${containerAddresses[${sender}]}"
receiverAddress="${containerAddresses[${receiver}]}"

# Fallback to userconfig if containerAddresses is empty
if [[ -z "$senderAddress" ]] || [[ -z "$receiverAddress" ]]; then
    echo -e "${YELLOW}\t   Warning: containerAddresses empty, falling back to userconfig${NC}"
    if [[ "$MODE" == "http" ]]; then
        senderAddress=$(docker exec ${sender} php -r "
            \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
            echo \$json['hostname'] ?? '';
        " 2>/dev/null)
        receiverAddress=$(docker exec ${receiver} php -r "
            \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
            echo \$json['hostname'] ?? '';
        " 2>/dev/null)
    else
        senderAddress=$(docker exec ${sender} php -r "
            \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
            echo \$json['torAddress'] ?? '';
        " 2>/dev/null)
        receiverAddress=$(docker exec ${receiver} php -r "
            \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
            echo \$json['torAddress'] ?? '';
        " 2>/dev/null)
    fi
fi

if [[ -z "$sender" ]] || [[ -z "$receiver" ]]; then
    echo -e "${YELLOW}Warning: No container links defined, skipping chunked sync test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'chunked sync test'"
    exit 0
fi

echo -e "[Test Setup]"
echo -e "\t   Sender: ${sender} (${senderAddress})"
echo -e "\t   Receiver: ${receiver} (${receiverAddress})"

# Ensure contacts exist
docker exec ${sender} eiou add ${receiverAddress} ${receiver} 0.1 1000 USD 2>&1 > /dev/null || true
docker exec ${receiver} eiou add ${senderAddress} ${sender} 0.1 1000 USD 2>&1 > /dev/null || true
wait_for_queue_processed ${sender}
wait_for_queue_processed ${receiver}

# Get transport types
receiverTransportType=$(getPhpTransportType "${receiverAddress}")
senderTransportType=$(getPhpTransportType "${senderAddress}")

# Wait for contacts to be accepted
echo -e "\t   Waiting for contacts to be accepted..."
waitElapsed=0
while [ $waitElapsed -lt 15 ]; do
    senderStatus=$(docker exec ${sender} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$status = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class)->getContactStatus('${receiverTransportType}', '${receiverAddress}');
        echo \$status ?? 'none';
    " 2>/dev/null || echo "none")

    receiverStatus=$(docker exec ${receiver} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        \$status = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class)->getContactStatus('${senderTransportType}', '${senderAddress}');
        echo \$status ?? 'none';
    " 2>/dev/null || echo "none")

    if [[ "$senderStatus" == "accepted" ]] && [[ "$receiverStatus" == "accepted" ]]; then
        echo -e "\t   Contacts accepted (${waitElapsed}s)"
        break
    fi

    wait_for_queue_processed ${sender} 2
    wait_for_queue_processed ${receiver} 2
    waitElapsed=$((waitElapsed + 2))
done

if [[ "$senderStatus" != "accepted" ]] || [[ "$receiverStatus" != "accepted" ]]; then
    echo -e "${YELLOW}\t   Warning: Contacts not fully accepted (sender: ${senderStatus}, receiver: ${receiverStatus})${NC}"
fi

# Get public keys
receiverPubkeyB64=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pubkey = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class)->getContactPubkey('${receiverTransportType}', '${receiverAddress}');
    if (\$pubkey) {
        echo base64_encode(\$pubkey);
    } else {
        echo 'ERROR';
    }
" 2>/dev/null || echo "ERROR")

senderPubkeyB64=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pubkey = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class)->getContactPubkey('${senderTransportType}', '${senderAddress}');
    if (\$pubkey) {
        echo base64_encode(\$pubkey);
    } else {
        echo 'ERROR';
    }
" 2>/dev/null || echo "ERROR")

PUBKEYS_AVAILABLE=true
if [[ "$senderPubkeyB64" == "ERROR" ]] || [[ "$receiverPubkeyB64" == "ERROR" ]]; then
    echo -e "${YELLOW}Warning: Could not retrieve public keys, skipping chunked sync tests${NC}"
    PUBKEYS_AVAILABLE=false
fi

##################### SECTION 1: Constants Verification #####################

echo -e "\n"
echo "========================================================================"
echo "Section 1: Chunked Sync Constants"
echo "========================================================================"

# Test 1.1: SYNC_CHUNK_SIZE constant exists and has expected value
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing SYNC_CHUNK_SIZE constant"

chunkSize=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    echo \Eiou\Core\Constants::SYNC_CHUNK_SIZE;
" 2>/dev/null || echo "ERROR")

if [[ "$chunkSize" == "50" ]]; then
    printf "\t   SYNC_CHUNK_SIZE = 50 ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SYNC_CHUNK_SIZE ${RED}FAILED${NC} (got: %s)\n" "$chunkSize"
    failure=$(( failure + 1 ))
fi

# Test 1.2: SYNC_MAX_CHUNKS constant exists and has expected value
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing SYNC_MAX_CHUNKS constant"

maxChunks=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    echo \Eiou\Core\Constants::SYNC_MAX_CHUNKS;
" 2>/dev/null || echo "ERROR")

if [[ "$maxChunks" == "100" ]]; then
    printf "\t   SYNC_MAX_CHUNKS = 100 ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SYNC_MAX_CHUNKS ${RED}FAILED${NC} (got: %s)\n" "$maxChunks"
    failure=$(( failure + 1 ))
fi

##################### SECTION 2: Responder Chunking #####################

echo -e "\n"
echo "========================================================================"
echo "Section 2: Responder Chunking Behavior"
echo "========================================================================"

if [[ "$PUBKEYS_AVAILABLE" != "true" ]]; then
    echo -e "${YELLOW}\t   Skipping section 2 - pubkeys not available${NC}"
else

timestamp=$(date +%s%N)

# Test 2.1: Responder chunks large response (hasMore=true)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing responder returns hasMore=true for >SYNC_CHUNK_SIZE transactions"

# Insert 60 synthetic transactions directly into receiver's DB
# These are minimal records that will appear in the sync response
insertResult=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();

    \$myPubkey = \$app->services->getCurrentUser()->getPublicKey();
    \$myPubkeyHash = hash('sha256', \$myPubkey);
    \$contactPubkey = base64_decode('${senderPubkeyB64}');
    \$contactPubkeyHash = hash('sha256', \$contactPubkey);
    \$myAddress = '${receiverAddress}';
    \$contactAddress = '${senderAddress}';

    \$insertCount = 0;
    \$prevTxid = null;

    for (\$i = 1; \$i <= 60; \$i++) {
        \$txid = hash('sha256', 'chunked-sync-test-${timestamp}-' . \$i);
        \$ts = time() - (60 - \$i);
        \$microtime = \$ts . '.' . str_pad(\$i, 4, '0', STR_PAD_LEFT);

        \$stmt = \$pdo->prepare('INSERT INTO transactions (
            txid, previous_txid, sender_address, sender_public_key, sender_public_key_hash,
            receiver_address, receiver_public_key, receiver_public_key_hash,
            amount, currency, memo, description, status, timestamp, time,
            sender_signature, signature_nonce, recipient_signature, type
        ) VALUES (
            :txid, :prev_txid, :sender_addr, :sender_pk, :sender_pk_hash,
            :recv_addr, :recv_pk, :recv_pk_hash,
            :amount, :currency, :memo, :description, :status, :timestamp, :time,
            :signature, :nonce, :recv_sig, :type
        )');

        \$stmt->execute([
            ':txid' => \$txid,
            ':prev_txid' => \$prevTxid,
            ':sender_addr' => \$contactAddress,
            ':sender_pk' => \$contactPubkey,
            ':sender_pk_hash' => \$contactPubkeyHash,
            ':recv_addr' => \$myAddress,
            ':recv_pk' => \$myPubkey,
            ':recv_pk_hash' => \$myPubkeyHash,
            ':amount' => 100,
            ':currency' => 'USD',
            ':memo' => 'standard',
            ':description' => 'chunked-sync-test-${timestamp}-' . \$i,
            ':status' => 'completed',
            ':timestamp' => date('Y-m-d H:i:s', \$ts),
            ':time' => \$microtime,
            ':signature' => base64_encode('fake-sig-' . \$i),
            ':nonce' => \$ts,
            ':recv_sig' => base64_encode('fake-recv-sig-' . \$i),
            ':type' => 'received'
        ]);

        \$prevTxid = \$txid;
        \$insertCount++;
    }

    echo \$insertCount;
" 2>/dev/null || echo "ERROR")

echo -e "\t   Inserted ${insertResult} synthetic transactions into receiver"

# Call handleTransactionSyncRequest directly via PHP and check response
chunkResponse=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();

    \$senderPubkey = base64_decode('${senderPubkeyB64}');

    // Capture output from handleTransactionSyncRequest
    ob_start();
    \$app->services->getSyncService()->handleTransactionSyncRequest([
        'senderAddress' => '${senderAddress}',
        'senderPublicKey' => \$senderPubkey,
        'lastKnownTxid' => null,
    ]);
    \$output = ob_get_clean();

    \$response = json_decode(\$output, true);
    if (\$response) {
        echo json_encode([
            'hasMore' => \$response['hasMore'] ?? 'MISSING',
            'totalTransactions' => \$response['totalTransactions'] ?? 'MISSING',
            'transactionCount' => \$response['transactionCount'] ?? 0,
            'status' => \$response['status'] ?? 'MISSING',
        ]);
    } else {
        echo 'PARSE_ERROR';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$chunkResponse" == "ERROR" ]] || [[ "$chunkResponse" == "PARSE_ERROR" ]]; then
    printf "\t   Responder chunking ${RED}FAILED${NC} (response: %s)\n" "$chunkResponse"
    failure=$(( failure + 1 ))
else
    hasMore=$(echo "$chunkResponse" | docker exec -i ${receiver} php -r "
        \$json = json_decode(file_get_contents('php://stdin'), true);
        echo \$json['hasMore'] === true ? 'true' : 'false';
    " 2>/dev/null || echo "ERROR")

    txCount=$(echo "$chunkResponse" | docker exec -i ${receiver} php -r "
        \$json = json_decode(file_get_contents('php://stdin'), true);
        echo \$json['transactionCount'] ?? 0;
    " 2>/dev/null || echo "0")

    totalTx=$(echo "$chunkResponse" | docker exec -i ${receiver} php -r "
        \$json = json_decode(file_get_contents('php://stdin'), true);
        echo \$json['totalTransactions'] ?? 0;
    " 2>/dev/null || echo "0")

    echo -e "\t   Response: hasMore=${hasMore}, transactionCount=${txCount}, totalTransactions=${totalTx}"

    if [[ "$hasMore" == "true" ]] && [[ "$txCount" -le 50 ]] && [[ "$totalTx" -ge 60 ]]; then
        printf "\t   Responder chunks large response ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Responder chunks large response ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
fi

# Cleanup synthetic transactions
docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'chunked-sync-test-${timestamp}-%'\");
" 2>/dev/null

# Test 2.2: Responder returns all when under chunk size (hasMore=false)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing responder returns hasMore=false for <SYNC_CHUNK_SIZE transactions"

timestamp2=$(date +%s%N)

# Count pre-existing transactions between sender and receiver (from earlier test suites)
preExistingCount=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$senderPubkey = base64_decode('${senderPubkeyB64}');
    \$txs = \$app->services->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class)->getTransactionsBetweenPubkeys(
        \$app->services->getCurrentUser()->getPublicKey(),
        \$senderPubkey
    );
    // Count only non-pending/sending (same filter as handleTransactionSyncRequest)
    \$count = 0;
    foreach (\$txs as \$tx) {
        \$status = \$tx['status'] ?? '';
        if (\$status !== \Eiou\Core\Constants::STATUS_PENDING && \$status !== \Eiou\Core\Constants::STATUS_SENDING) {
            \$count++;
        }
    }
    echo \$count;
" 2>/dev/null || echo "0")

# Insert 10 synthetic transactions
insertResult2=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();

    \$myPubkey = \$app->services->getCurrentUser()->getPublicKey();
    \$myPubkeyHash = hash('sha256', \$myPubkey);
    \$contactPubkey = base64_decode('${senderPubkeyB64}');
    \$contactPubkeyHash = hash('sha256', \$contactPubkey);
    \$myAddress = '${receiverAddress}';
    \$contactAddress = '${senderAddress}';

    \$insertCount = 0;
    \$prevTxid = null;

    for (\$i = 1; \$i <= 10; \$i++) {
        \$txid = hash('sha256', 'small-sync-test-${timestamp2}-' . \$i);
        \$ts = time() - (10 - \$i);
        \$microtime = \$ts . '.' . str_pad(\$i, 4, '0', STR_PAD_LEFT);

        \$stmt = \$pdo->prepare('INSERT INTO transactions (
            txid, previous_txid, sender_address, sender_public_key, sender_public_key_hash,
            receiver_address, receiver_public_key, receiver_public_key_hash,
            amount, currency, memo, description, status, timestamp, time,
            sender_signature, signature_nonce, recipient_signature, type
        ) VALUES (
            :txid, :prev_txid, :sender_addr, :sender_pk, :sender_pk_hash,
            :recv_addr, :recv_pk, :recv_pk_hash,
            :amount, :currency, :memo, :description, :status, :timestamp, :time,
            :signature, :nonce, :recv_sig, :type
        )');

        \$stmt->execute([
            ':txid' => \$txid,
            ':prev_txid' => \$prevTxid,
            ':sender_addr' => \$contactAddress,
            ':sender_pk' => \$contactPubkey,
            ':sender_pk_hash' => \$contactPubkeyHash,
            ':recv_addr' => \$myAddress,
            ':recv_pk' => \$myPubkey,
            ':recv_pk_hash' => \$myPubkeyHash,
            ':amount' => 100,
            ':currency' => 'USD',
            ':memo' => 'standard',
            ':description' => 'small-sync-test-${timestamp2}-' . \$i,
            ':status' => 'completed',
            ':timestamp' => date('Y-m-d H:i:s', \$ts),
            ':time' => \$microtime,
            ':signature' => base64_encode('fake-sig-' . \$i),
            ':nonce' => \$ts,
            ':recv_sig' => base64_encode('fake-recv-sig-' . \$i),
            ':type' => 'received'
        ]);

        \$prevTxid = \$txid;
        \$insertCount++;
    }

    echo \$insertCount;
" 2>/dev/null || echo "ERROR")

echo -e "\t   Inserted ${insertResult2} synthetic transactions into receiver"

smallChunkResponse=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();

    \$senderPubkey = base64_decode('${senderPubkeyB64}');

    ob_start();
    \$app->services->getSyncService()->handleTransactionSyncRequest([
        'senderAddress' => '${senderAddress}',
        'senderPublicKey' => \$senderPubkey,
        'lastKnownTxid' => null,
    ]);
    \$output = ob_get_clean();

    \$response = json_decode(\$output, true);
    if (\$response) {
        echo json_encode([
            'hasMore' => \$response['hasMore'] ?? 'MISSING',
            'totalTransactions' => \$response['totalTransactions'] ?? 'MISSING',
            'transactionCount' => \$response['transactionCount'] ?? 0,
        ]);
    } else {
        echo 'PARSE_ERROR';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$smallChunkResponse" == "ERROR" ]] || [[ "$smallChunkResponse" == "PARSE_ERROR" ]]; then
    printf "\t   Small response test ${RED}FAILED${NC} (response: %s)\n" "$smallChunkResponse"
    failure=$(( failure + 1 ))
else
    smallHasMore=$(echo "$smallChunkResponse" | docker exec -i ${receiver} php -r "
        \$json = json_decode(file_get_contents('php://stdin'), true);
        echo \$json['hasMore'] === false ? 'false' : 'true';
    " 2>/dev/null || echo "ERROR")

    smallTxCount=$(echo "$smallChunkResponse" | docker exec -i ${receiver} php -r "
        \$json = json_decode(file_get_contents('php://stdin'), true);
        echo \$json['transactionCount'] ?? 0;
    " 2>/dev/null || echo "0")

    expectedSmallCount=$((preExistingCount + 10))
    echo -e "\t   Response: hasMore=${smallHasMore}, transactionCount=${smallTxCount} (expected ${expectedSmallCount}: ${preExistingCount} pre-existing + 10 synthetic)"

    if [[ "$smallHasMore" == "false" ]] && [[ "$smallTxCount" -eq "$expectedSmallCount" ]]; then
        printf "\t   Responder returns all when under chunk size ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Responder returns all when under chunk size ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
fi

# Cleanup
docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'small-sync-test-${timestamp2}-%'\");
" 2>/dev/null

# Test 2.3: Chunk cursor advances correctly (second request uses last txid from chunk 1)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing chunk cursor advancement"

timestamp3=$(date +%s%N)

# Insert 60 synthetic transactions, track the 50th txid (end of first chunk)
cursorResult=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();

    \$myPubkey = \$app->services->getCurrentUser()->getPublicKey();
    \$myPubkeyHash = hash('sha256', \$myPubkey);
    \$contactPubkey = base64_decode('${senderPubkeyB64}');
    \$contactPubkeyHash = hash('sha256', \$contactPubkey);
    \$myAddress = '${receiverAddress}';
    \$contactAddress = '${senderAddress}';

    \$prevTxid = null;
    \$txid50 = null;

    for (\$i = 1; \$i <= 60; \$i++) {
        \$txid = hash('sha256', 'cursor-sync-test-${timestamp3}-' . \$i);
        \$ts = time() - (60 - \$i);
        \$microtime = \$ts . '.' . str_pad(\$i, 4, '0', STR_PAD_LEFT);

        \$stmt = \$pdo->prepare('INSERT INTO transactions (
            txid, previous_txid, sender_address, sender_public_key, sender_public_key_hash,
            receiver_address, receiver_public_key, receiver_public_key_hash,
            amount, currency, memo, description, status, timestamp, time,
            sender_signature, signature_nonce, recipient_signature, type
        ) VALUES (
            :txid, :prev_txid, :sender_addr, :sender_pk, :sender_pk_hash,
            :recv_addr, :recv_pk, :recv_pk_hash,
            :amount, :currency, :memo, :description, :status, :timestamp, :time,
            :signature, :nonce, :recv_sig, :type
        )');

        \$stmt->execute([
            ':txid' => \$txid,
            ':prev_txid' => \$prevTxid,
            ':sender_addr' => \$contactAddress,
            ':sender_pk' => \$contactPubkey,
            ':sender_pk_hash' => \$contactPubkeyHash,
            ':recv_addr' => \$myAddress,
            ':recv_pk' => \$myPubkey,
            ':recv_pk_hash' => \$myPubkeyHash,
            ':amount' => 100,
            ':currency' => 'USD',
            ':memo' => 'standard',
            ':description' => 'cursor-sync-test-${timestamp3}-' . \$i,
            ':status' => 'completed',
            ':timestamp' => date('Y-m-d H:i:s', \$ts),
            ':time' => \$microtime,
            ':signature' => base64_encode('fake-sig-' . \$i),
            ':nonce' => \$ts,
            ':recv_sig' => base64_encode('fake-recv-sig-' . \$i),
            ':type' => 'received'
        ]);

        if (\$i === 50) {
            \$txid50 = \$txid;
        }

        \$prevTxid = \$txid;
    }

    echo \$txid50;
" 2>/dev/null || echo "ERROR")

echo -e "\t   50th txid (chunk boundary): ${cursorResult:0:40}..."

# First chunk request: lastKnownTxid=null, should get 50 transactions
# Then second request with lastKnownTxid=txid_50, should get remaining 10
secondChunkResponse=$(docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();

    \$senderPubkey = base64_decode('${senderPubkeyB64}');

    // Request second chunk using the 50th txid as cursor
    ob_start();
    \$app->services->getSyncService()->handleTransactionSyncRequest([
        'senderAddress' => '${senderAddress}',
        'senderPublicKey' => \$senderPubkey,
        'lastKnownTxid' => '${cursorResult}',
    ]);
    \$output = ob_get_clean();

    \$response = json_decode(\$output, true);
    if (\$response) {
        echo json_encode([
            'hasMore' => \$response['hasMore'] ?? 'MISSING',
            'totalTransactions' => \$response['totalTransactions'] ?? 'MISSING',
            'transactionCount' => \$response['transactionCount'] ?? 0,
        ]);
    } else {
        echo 'PARSE_ERROR';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$secondChunkResponse" == "ERROR" ]] || [[ "$secondChunkResponse" == "PARSE_ERROR" ]]; then
    printf "\t   Cursor advancement ${RED}FAILED${NC} (response: %s)\n" "$secondChunkResponse"
    failure=$(( failure + 1 ))
else
    cursorHasMore=$(echo "$secondChunkResponse" | docker exec -i ${receiver} php -r "
        \$json = json_decode(file_get_contents('php://stdin'), true);
        echo \$json['hasMore'] === false ? 'false' : 'true';
    " 2>/dev/null || echo "ERROR")

    cursorTxCount=$(echo "$secondChunkResponse" | docker exec -i ${receiver} php -r "
        \$json = json_decode(file_get_contents('php://stdin'), true);
        echo \$json['transactionCount'] ?? 0;
    " 2>/dev/null || echo "0")

    echo -e "\t   Second chunk: hasMore=${cursorHasMore}, transactionCount=${cursorTxCount}"

    if [[ "$cursorHasMore" == "false" ]] && [[ "$cursorTxCount" -eq 10 ]]; then
        printf "\t   Chunk cursor advancement ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Chunk cursor advancement ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
fi

# Cleanup
docker exec ${receiver} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$pdo->exec(\"DELETE FROM transactions WHERE description LIKE 'cursor-sync-test-${timestamp3}-%'\");
" 2>/dev/null

fi # end PUBKEYS_AVAILABLE check for section 2

##################### SECTION 3: Payload Fields #####################

echo -e "\n"
echo "========================================================================"
echo "Section 3: Payload Response Fields"
echo "========================================================================"

# Test 3.1: buildTransactionSyncResponse includes hasMore and totalTransactions fields
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing payload includes hasMore and totalTransactions fields"

payloadResult=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$payload = new \Eiou\Schemas\Payloads\MessagePayload(\$app->services->getCurrentUser(), \$app->services->getUtilityContainer());

    // Build a response with hasMore=true
    \$json = \$payload->buildTransactionSyncResponse(
        '${receiverAddress}',
        [['txid' => 'test1'], ['txid' => 'test2']],
        'test2',
        true,
        75
    );

    \$decoded = json_decode(\$json, true);
    \$hasField1 = array_key_exists('hasMore', \$decoded) ? 'yes' : 'no';
    \$hasField2 = array_key_exists('totalTransactions', \$decoded) ? 'yes' : 'no';
    \$hasMoreVal = \$decoded['hasMore'] === true ? 'true' : 'false';
    \$totalVal = \$decoded['totalTransactions'];

    echo \"{\$hasField1}|{\$hasField2}|{\$hasMoreVal}|{\$totalVal}\";
" 2>/dev/null || echo "ERROR")

if [[ "$payloadResult" == "yes|yes|true|75" ]]; then
    printf "\t   Payload fields present and correct ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Payload fields ${RED}FAILED${NC} (got: %s)\n" "$payloadResult"
    failure=$(( failure + 1 ))
fi

# Test 3.2: Default values (hasMore=false, totalTransactions=0) when not specified
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing payload defaults (backward compatibility)"

defaultResult=$(docker exec ${sender} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$payload = new \Eiou\Schemas\Payloads\MessagePayload(\$app->services->getCurrentUser(), \$app->services->getUtilityContainer());

    // Build with default parameters (no hasMore/totalTransactions)
    \$json = \$payload->buildTransactionSyncResponse(
        '${receiverAddress}',
        [['txid' => 'test1']],
        'test1'
    );

    \$decoded = json_decode(\$json, true);
    \$hasMoreVal = \$decoded['hasMore'] === false ? 'false' : 'true';
    \$totalVal = \$decoded['totalTransactions'];

    echo \"{\$hasMoreVal}|{\$totalVal}\";
" 2>/dev/null || echo "ERROR")

if [[ "$defaultResult" == "false|0" ]]; then
    printf "\t   Backward compatible defaults ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Backward compatible defaults ${RED}FAILED${NC} (got: %s)\n" "$defaultResult"
    failure=$(( failure + 1 ))
fi

##################### RESULTS #####################

echo -e "\n"
succesrate "${totaltests}" "${passed}" "${failure}" "'chunked sync test'"
