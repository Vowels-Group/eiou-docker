#!/bin/sh
# Copyright 2025 The Vowels Company

# Test held transaction functionality for invalid_previous_txid handling
# Verifies that transactions are held when receiving invalid_previous_txid rejection,
# maintains per-contact isolation, and resumes correctly after sync
# Issue #338 - Feature to hold transactions until resync completes

echo -e "\nTesting held transaction functionality..."

testname="heldTransactionTest"
totaltests=0
passed=0
failure=0

# Use first two connected containers for testing
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
testPair="${containersLinkKeys[0]}"

if [[ -z "$testPair" ]]; then
    echo -e "${YELLOW}Warning: No container links defined, skipping held transaction test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'held transaction'"
    exit 0
fi

containerKeys=(${testPair//,/ })
sender="${containerKeys[0]}"
receiver="${containerKeys[1]}"

# Get addresses from containerAddresses (populated by hostnameTest/torAddressTest)
senderAddress="${containerAddresses[${sender}]}"
receiverAddress="${containerAddresses[${receiver}]}"

# Get third container for isolation test
if [[ ${#containersLinkKeys[@]} -ge 2 ]]; then
    thirdPair="${containersLinkKeys[1]}"
    thirdKeys=(${thirdPair//,/ })
    thirdContact="${thirdKeys[1]}"
    thirdAddress="${containerAddresses[${thirdContact}]}"
fi

echo -e "\n[Held Transaction Test Setup]"
echo -e "\t   Sender: ${sender} (${senderAddress})"
echo -e "\t   Receiver: ${receiver} (${receiverAddress})"
if [[ -n "$thirdAddress" ]]; then
    echo -e "\t   Third Contact: ${thirdContact} (${thirdAddress})"
fi

if [[ -z "$senderAddress" ]] || [[ -z "$receiverAddress" ]]; then
    echo -e "${YELLOW}Warning: Container addresses not populated, skipping test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'held transaction'"
    exit 0
fi

############################ Ensure Contacts Exist ############################

echo -e "\n[Ensuring contacts exist between nodes]"

# Add contacts if they don't exist
docker exec ${sender} eiou add ${receiverAddress} ${receiver} 0 0 USD 2>&1 || true
docker exec ${receiver} eiou add ${senderAddress} ${sender} 0 0 USD 2>&1 || true

if [[ -n "$thirdAddress" ]]; then
    docker exec ${sender} eiou add ${thirdAddress} ${thirdContact} 0 0 USD 2>&1 || true
    docker exec ${thirdContact} eiou add ${senderAddress} ${sender} 0 0 USD 2>&1 || true
fi

# Wait briefly for contacts to be established
sleep 2

############################ TEST 1: Create held_transactions table ############################

echo -e "\n[Test 1: Create held_transactions table if not exists]"
totaltests=$(( totaltests + 1 ))

tableResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();

    // Create the held_transactions table
    \$sql = \"CREATE TABLE IF NOT EXISTS held_transactions (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        contact_pubkey_hash VARCHAR(64) NOT NULL,
        txid VARCHAR(255) NOT NULL,
        original_previous_txid VARCHAR(255),
        expected_previous_txid VARCHAR(255),
        transaction_type ENUM('standard', 'p2p') DEFAULT 'standard',
        hold_reason ENUM('invalid_previous_txid', 'sync_in_progress') DEFAULT 'invalid_previous_txid',
        sync_status ENUM('not_started', 'in_progress', 'completed', 'failed') DEFAULT 'not_started',
        retry_count INT DEFAULT 0,
        max_retries INT DEFAULT 3,
        held_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        last_sync_attempt TIMESTAMP(6) NULL,
        next_retry_at TIMESTAMP(6) NULL,
        resolved_at TIMESTAMP(6) NULL,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_held_contact (contact_pubkey_hash),
        INDEX idx_held_txid (txid),
        INDEX idx_held_status (sync_status),
        INDEX idx_held_contact_status (contact_pubkey_hash, sync_status)
    )\";

    try {
        \$pdo->exec(\$sql);
        echo 'TABLE_CREATED';
    } catch (Exception \$e) {
        echo 'ERROR:' . \$e->getMessage();
    }
" 2>/dev/null || echo "ERROR")

if [[ "$tableResult" == "TABLE_CREATED" ]]; then
    printf "\t   held_transactions table ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   held_transactions table ${RED}FAILED${NC} (%s)\n" "${tableResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 2: Verify HeldTransactionRepository exists ############################

echo -e "\n[Test 2: Verify HeldTransactionRepository exists and can be instantiated]"
totaltests=$(( totaltests + 1 ))

repoExists=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();

    try {
        \$repo = \$app->services->getHeldTransactionRepository();
        echo 'EXISTS';
    } catch (Exception \$e) {
        echo 'MISSING:' . \$e->getMessage();
    }
" 2>/dev/null || echo "ERROR")

if [[ "$repoExists" == "EXISTS" ]]; then
    printf "\t   HeldTransactionRepository ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   HeldTransactionRepository ${RED}FAILED${NC} (%s)\n" "${repoExists}"
    failure=$(( failure + 1 ))
fi

############################ TEST 3: Verify HeldTransactionService exists ############################

echo -e "\n[Test 3: Verify HeldTransactionService exists and can be instantiated]"
totaltests=$(( totaltests + 1 ))

serviceExists=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();

    try {
        \$service = \$app->services->getHeldTransactionService();
        echo 'EXISTS';
    } catch (Exception \$e) {
        echo 'MISSING:' . \$e->getMessage();
    }
" 2>/dev/null || echo "ERROR")

if [[ "$serviceExists" == "EXISTS" ]]; then
    printf "\t   HeldTransactionService ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   HeldTransactionService ${RED}FAILED${NC} (%s)\n" "${serviceExists}"
    failure=$(( failure + 1 ))
fi

############################ TEST 4: Test holdTransaction method ############################

echo -e "\n[Test 4: Test holdTransaction repository method]"
totaltests=$(( totaltests + 1 ))

holdResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();

    try {
        \$repo = \$app->services->getHeldTransactionRepository();

        // Test inserting a held transaction
        \$testTxid = 'test-txid-' . time();
        \$testContactHash = hash('sha256', 'test-contact-pubkey');

        \$result = \$repo->holdTransaction(
            \$testContactHash,
            \$testTxid,
            'original-prev-txid',
            'expected-prev-txid',
            'standard'
        );

        if (\$result !== false && \$result > 0) {
            // Verify it was inserted
            \$held = \$repo->getByTxid(\$testTxid);
            if (\$held && \$held['txid'] === \$testTxid) {
                // Cleanup
                \$repo->releaseTransaction(\$testTxid);
                echo 'SUCCESS';
            } else {
                echo 'VERIFY_FAILED';
            }
        } else {
            echo 'INSERT_FAILED';
        }
    } catch (Exception \$e) {
        echo 'ERROR:' . \$e->getMessage();
    }
" 2>/dev/null || echo "ERROR")

if [[ "$holdResult" == "SUCCESS" ]]; then
    printf "\t   holdTransaction method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   holdTransaction method ${RED}FAILED${NC} (%s)\n" "${holdResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 5: Test sync status tracking ############################

echo -e "\n[Test 5: Test sync status tracking methods]"
totaltests=$(( totaltests + 1 ))

syncStatusResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();

    try {
        \$repo = \$app->services->getHeldTransactionRepository();

        // Insert a test transaction
        \$testTxid = 'sync-test-' . time();
        \$testContactHash = hash('sha256', 'sync-test-contact');

        \$repo->holdTransaction(
            \$testContactHash,
            \$testTxid,
            null,
            null,
            'standard'
        );

        // Test sync status transitions
        \$repo->markSyncStarted(\$testContactHash);
        \$inProgress = \$repo->isSyncInProgress(\$testContactHash);

        if (!\$inProgress) {
            echo 'SYNC_START_FAILED';
            \$repo->releaseTransaction(\$testTxid);
            exit;
        }

        \$repo->markSyncCompleted(\$testContactHash);
        \$completed = \$repo->getHeldTransactionsForContact(\$testContactHash, 'completed');

        // Cleanup
        \$repo->releaseTransaction(\$testTxid);

        if (!empty(\$completed)) {
            echo 'SUCCESS';
        } else {
            echo 'SYNC_COMPLETE_FAILED';
        }
    } catch (Exception \$e) {
        echo 'ERROR:' . \$e->getMessage();
    }
" 2>/dev/null || echo "ERROR")

if [[ "$syncStatusResult" == "SUCCESS" ]]; then
    printf "\t   Sync status tracking ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Sync status tracking ${RED}FAILED${NC} (%s)\n" "${syncStatusResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 6: Test per-contact isolation ############################

echo -e "\n[Test 6: Test per-contact isolation]"
totaltests=$(( totaltests + 1 ))

isolationResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();

    try {
        \$repo = \$app->services->getHeldTransactionRepository();

        // Insert transactions for two different contacts
        \$contact1Hash = hash('sha256', 'isolation-contact-1');
        \$contact2Hash = hash('sha256', 'isolation-contact-2');

        \$repo->holdTransaction(\$contact1Hash, 'iso-tx-1', null, null, 'standard');
        \$repo->holdTransaction(\$contact2Hash, 'iso-tx-2', null, null, 'standard');

        // Mark sync in progress for contact 1 only
        \$repo->markSyncStarted(\$contact1Hash);

        // Verify contact 1 shows sync in progress
        \$c1InProgress = \$repo->isSyncInProgress(\$contact1Hash);

        // Verify contact 2 does NOT show sync in progress
        \$c2InProgress = \$repo->isSyncInProgress(\$contact2Hash);

        // Cleanup
        \$repo->releaseTransaction('iso-tx-1');
        \$repo->releaseTransaction('iso-tx-2');

        if (\$c1InProgress && !\$c2InProgress) {
            echo 'SUCCESS';
        } else {
            echo 'ISOLATION_FAILED:c1=' . (\$c1InProgress ? 'true' : 'false') . ',c2=' . (\$c2InProgress ? 'true' : 'false');
        }
    } catch (Exception \$e) {
        echo 'ERROR:' . \$e->getMessage();
    }
" 2>/dev/null || echo "ERROR")

if [[ "$isolationResult" == "SUCCESS" ]]; then
    printf "\t   Per-contact isolation ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Per-contact isolation ${RED}FAILED${NC} (%s)\n" "${isolationResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 7: Test HeldTransactionService holdTransactionForSync ############################

echo -e "\n[Test 7: Test HeldTransactionService holdTransactionForSync]"
totaltests=$(( totaltests + 1 ))

serviceHoldResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();

    try {
        \$service = \$app->services->getHeldTransactionService();

        // Create a mock transaction
        \$mockTx = [
            'txid' => 'service-test-' . time(),
            'receiver_public_key' => 'mock-receiver-pubkey-test',
            'receiver_address' => '"'${receiverAddress}'"',
            'previous_txid' => 'mock-previous-txid',
            'memo' => 'standard'
        ];

        // Test holding the transaction
        \$result = \$service->holdTransactionForSync(\$mockTx, \$mockTx['receiver_public_key'], 'expected-txid');

        // Cleanup
        \$repo = \$app->services->getHeldTransactionRepository();
        \$repo->releaseTransaction(\$mockTx['txid']);

        if (\$result['held'] === true) {
            echo 'SUCCESS';
        } else {
            echo 'HOLD_FAILED:' . (\$result['error'] ?? 'unknown');
        }
    } catch (Exception \$e) {
        echo 'ERROR:' . \$e->getMessage();
    }
" 2>/dev/null || echo "ERROR")

if [[ "$serviceHoldResult" == "SUCCESS" ]]; then
    printf "\t   holdTransactionForSync ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   holdTransactionForSync ${RED}FAILED${NC} (%s)\n" "${serviceHoldResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 8: Test shouldHoldTransactions ############################

echo -e "\n[Test 8: Test shouldHoldTransactions check]"
totaltests=$(( totaltests + 1 ))

shouldHoldResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();

    try {
        \$service = \$app->services->getHeldTransactionService();
        \$repo = \$app->services->getHeldTransactionRepository();

        \$testContactPubkey = 'should-hold-test-contact';
        \$testContactHash = hash('sha256', \$testContactPubkey);

        // Initially should NOT hold (no sync in progress)
        \$shouldHold1 = \$service->shouldHoldTransactions(\$testContactPubkey);

        // Create a held transaction and mark sync as in progress
        \$repo->holdTransaction(\$testContactHash, 'should-hold-tx', null, null, 'standard');
        \$repo->markSyncStarted(\$testContactHash);

        // Now SHOULD hold
        \$shouldHold2 = \$service->shouldHoldTransactions(\$testContactPubkey);

        // Cleanup
        \$repo->releaseTransaction('should-hold-tx');

        if (!\$shouldHold1 && \$shouldHold2) {
            echo 'SUCCESS';
        } else {
            echo 'FAILED:before=' . (\$shouldHold1 ? 'true' : 'false') . ',after=' . (\$shouldHold2 ? 'true' : 'false');
        }
    } catch (Exception \$e) {
        echo 'ERROR:' . \$e->getMessage();
    }
" 2>/dev/null || echo "ERROR")

if [[ "$shouldHoldResult" == "SUCCESS" ]]; then
    printf "\t   shouldHoldTransactions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   shouldHoldTransactions ${RED}FAILED${NC} (%s)\n" "${shouldHoldResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 9: Test getStatistics ############################

echo -e "\n[Test 9: Test getStatistics method]"
totaltests=$(( totaltests + 1 ))

statsResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();

    try {
        \$service = \$app->services->getHeldTransactionService();

        \$stats = \$service->getStatistics();

        if (isset(\$stats['total']) && isset(\$stats['by_status']) && isset(\$stats['by_reason'])) {
            echo 'SUCCESS';
        } else {
            echo 'INVALID_STATS:' . json_encode(array_keys(\$stats));
        }
    } catch (Exception \$e) {
        echo 'ERROR:' . \$e->getMessage();
    }
" 2>/dev/null || echo "ERROR")

if [[ "$statsResult" == "SUCCESS" ]]; then
    printf "\t   getStatistics ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   getStatistics ${RED}FAILED${NC} (%s)\n" "${statsResult}"
    failure=$(( failure + 1 ))
fi

############################ TEST 10: Test cleanup methods ############################

echo -e "\n[Test 10: Test cleanup and release methods]"
totaltests=$(( totaltests + 1 ))

cleanupResult=$(docker exec ${sender} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();

    try {
        \$repo = \$app->services->getHeldTransactionRepository();

        \$testContactHash = hash('sha256', 'cleanup-test-contact');

        // Insert multiple transactions
        \$repo->holdTransaction(\$testContactHash, 'cleanup-tx-1', null, null, 'standard');
        \$repo->holdTransaction(\$testContactHash, 'cleanup-tx-2', null, null, 'standard');

        // Verify they exist
        \$before = \$repo->hasHeldTransactions(\$testContactHash);

        // Release all for contact
        \$released = \$repo->releaseAllForContact(\$testContactHash);

        // Verify they're gone
        \$after = \$repo->hasHeldTransactions(\$testContactHash);

        if (\$before && !\$after && \$released >= 2) {
            echo 'SUCCESS';
        } else {
            echo 'FAILED:before=' . (\$before ? 'true' : 'false') . ',after=' . (\$after ? 'true' : 'false') . ',released=' . \$released;
        }
    } catch (Exception \$e) {
        echo 'ERROR:' . \$e->getMessage();
    }
" 2>/dev/null || echo "ERROR")

if [[ "$cleanupResult" == "SUCCESS" ]]; then
    printf "\t   Cleanup methods ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Cleanup methods ${RED}FAILED${NC} (%s)\n" "${cleanupResult}"
    failure=$(( failure + 1 ))
fi

############################ Summary ############################

succesrate "${totaltests}" "${passed}" "${failure}" "'held transaction'"
