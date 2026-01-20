#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

# Test MessageDeliveryService and Dead Letter Queue functionality
echo -e "\nTesting MessageDeliveryService and DLQ..."

testname="messageDeliveryTest"
totaltests=0
passed=0
failure=0

# ============================================================================
# Test 1: Message Delivery Table Exists
# ============================================================================
echo -e "\n[Test 1: Message Delivery Table Exists]"
for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))

    tableCheck=$(docker exec ${container} php -r "
        require_once('${PDO_FILE}');
        \$pdo = createPDOConnection();
        \$stmt = \$pdo->query('SHOW TABLES LIKE \"message_delivery\"');
        echo \$stmt->rowCount() > 0 ? 'EXISTS' : 'NOT_FOUND';
    " 2>/dev/null || echo "ERROR")

    if [[ "$tableCheck" == "EXISTS" ]]; then
        printf "\t   message_delivery table in %s ${GREEN}PASSED${NC}\n" ${container}
        passed=$(( passed + 1 ))
    else
        printf "\t   message_delivery table in %s ${RED}FAILED${NC} (%s)\n" ${container} "${tableCheck}"
        failure=$(( failure + 1 ))
    fi
done

# ============================================================================
# Test 2: Dead Letter Queue Table Exists
# ============================================================================
echo -e "\n[Test 2: Dead Letter Queue Table Exists]"
for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))

    tableCheck=$(docker exec ${container} php -r "
        require_once('${PDO_FILE}');
        \$pdo = createPDOConnection();
        \$stmt = \$pdo->query('SHOW TABLES LIKE \"dead_letter_queue\"');
        echo \$stmt->rowCount() > 0 ? 'EXISTS' : 'NOT_FOUND';
    " 2>/dev/null || echo "ERROR")

    if [[ "$tableCheck" == "EXISTS" ]]; then
        printf "\t   dead_letter_queue table in %s ${GREEN}PASSED${NC}\n" ${container}
        passed=$(( passed + 1 ))
    else
        printf "\t   dead_letter_queue table in %s ${RED}FAILED${NC} (%s)\n" ${container} "${tableCheck}"
        failure=$(( failure + 1 ))
    fi
done

# ============================================================================
# Test 3: Delivery Metrics Table Exists
# ============================================================================
echo -e "\n[Test 3: Delivery Metrics Table Exists]"
for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))

    tableCheck=$(docker exec ${container} php -r "
        require_once('${PDO_FILE}');
        \$pdo = createPDOConnection();
        \$stmt = \$pdo->query('SHOW TABLES LIKE \"delivery_metrics\"');
        echo \$stmt->rowCount() > 0 ? 'EXISTS' : 'NOT_FOUND';
    " 2>/dev/null || echo "ERROR")

    if [[ "$tableCheck" == "EXISTS" ]]; then
        printf "\t   delivery_metrics table in %s ${GREEN}PASSED${NC}\n" ${container}
        passed=$(( passed + 1 ))
    else
        printf "\t   delivery_metrics table in %s ${RED}FAILED${NC} (%s)\n" ${container} "${tableCheck}"
        failure=$(( failure + 1 ))
    fi
done

# ============================================================================
# Test 4: MessageDeliveryRepository Can Create Delivery Record
# ============================================================================
echo -e "\n[Test 4: MessageDeliveryRepository Create Delivery]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

createResult=$(docker exec ${container} php -r "
    require_once('${DATABASE_DIR}//MessageDeliveryRepository.php');

    \$repo = new MessageDeliveryRepository();

    // Create a test delivery record
    \$testId = 'test-' . time() . '-' . uniqid();
    \$result = \$repo->createDelivery(
        'transaction',
        \$testId,
        'http://test.local:8080',
        'pending',
        5,
        ['test' => 'payload']
    );

    // Verify it was created
    \$delivery = \$repo->getByMessage('transaction', \$testId);

    if (\$delivery && \$delivery['delivery_stage'] === 'pending') {
        echo 'SUCCESS';
    } else {
        echo 'FAILED';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$createResult" == "SUCCESS" ]]; then
    printf "\t   MessageDeliveryRepository createDelivery ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   MessageDeliveryRepository createDelivery ${RED}FAILED${NC} (%s)\n" "${createResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 5: MessageDeliveryRepository Can Update Stage
# ============================================================================
echo -e "\n[Test 5: MessageDeliveryRepository Update Stage]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

updateResult=$(docker exec ${container} php -r "
    require_once('${DATABASE_DIR}//MessageDeliveryRepository.php');

    \$repo = new MessageDeliveryRepository();

    // Create a test delivery record
    \$testId = 'test-update-' . time() . '-' . uniqid();
    \$repo->createDelivery('p2p', \$testId, 'http://test.local:8080', 'pending', 5);

    // Update the stage
    \$updated = \$repo->updateStage('p2p', \$testId, 'sent');

    // Verify the update
    \$delivery = \$repo->getByMessage('p2p', \$testId);

    if (\$updated && \$delivery && \$delivery['delivery_stage'] === 'sent') {
        echo 'SUCCESS';
    } else {
        echo 'FAILED';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$updateResult" == "SUCCESS" ]]; then
    printf "\t   MessageDeliveryRepository updateStage ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   MessageDeliveryRepository updateStage ${RED}FAILED${NC} (%s)\n" "${updateResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 6: DeadLetterQueueRepository Can Add To Queue
# ============================================================================
echo -e "\n[Test 6: DeadLetterQueueRepository Add To Queue]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

dlqResult=$(docker exec ${container} php -r "
    require_once('${DATABASE_DIR}//DeadLetterQueueRepository.php');

    \$repo = new DeadLetterQueueRepository();

    // Add a test item to DLQ
    \$testId = 'dlq-test-' . time() . '-' . uniqid();
    \$result = \$repo->addToQueue(
        'rp2p',
        \$testId,
        ['hash' => 'test-hash'],
        'http://test.local:8080',
        5,
        'Test failure reason'
    );

    // Verify it was added
    \$count = \$repo->getPendingCount();

    if (\$result && \$count > 0) {
        echo 'SUCCESS';
    } else {
        echo 'FAILED';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$dlqResult" == "SUCCESS" ]]; then
    printf "\t   DeadLetterQueueRepository addToQueue ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   DeadLetterQueueRepository addToQueue ${RED}FAILED${NC} (%s)\n" "${dlqResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 7: DeadLetterQueueRepository Statistics
# ============================================================================
echo -e "\n[Test 7: DeadLetterQueueRepository Statistics]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

statsResult=$(docker exec ${container} php -r "
    require_once('${DATABASE_DIR}//DeadLetterQueueRepository.php');

    \$repo = new DeadLetterQueueRepository();

    \$stats = \$repo->getStatistics();

    if (is_array(\$stats) && isset(\$stats['total_count'])) {
        echo 'SUCCESS:' . \$stats['total_count'];
    } else {
        echo 'FAILED';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$statsResult" =~ ^SUCCESS ]]; then
    printf "\t   DeadLetterQueueRepository getStatistics ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   DeadLetterQueueRepository getStatistics ${RED}FAILED${NC} (%s)\n" "${statsResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 8: DeliveryMetricsRepository Record Event
# ============================================================================
echo -e "\n[Test 8: DeliveryMetricsRepository Record Event]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

metricsResult=$(docker exec ${container} php -r "
    require_once('${DATABASE_DIR}//DeliveryMetricsRepository.php');

    \$repo = new DeliveryMetricsRepository();

    // Record a test delivery event
    \$result = \$repo->recordDeliveryEvent('transaction', true, 150, 0);

    // Get recent metrics
    \$metrics = \$repo->getRecentMetrics('transaction');

    if (\$result && is_array(\$metrics) && isset(\$metrics['total_sent'])) {
        echo 'SUCCESS:' . \$metrics['total_sent'];
    } else {
        echo 'FAILED';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$metricsResult" =~ ^SUCCESS ]]; then
    printf "\t   DeliveryMetricsRepository recordDeliveryEvent ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   DeliveryMetricsRepository recordDeliveryEvent ${RED}FAILED${NC} (%s)\n" "${metricsResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 9: MessageDeliveryService Initialization
# ============================================================================
echo -e "\n[Test 9: MessageDeliveryService Initialization]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

serviceResult=$(docker exec ${container} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();
    \$service = \$app->services->getMessageDeliveryService();

    if (\$service !== null && is_object(\$service)) {
        echo 'SUCCESS';
    } else {
        echo 'FAILED';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$serviceResult" == "SUCCESS" ]]; then
    printf "\t   MessageDeliveryService initialization ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   MessageDeliveryService initialization ${RED}FAILED${NC} (%s)\n" "${serviceResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 10: MessageDeliveryService Statistics
# ============================================================================
echo -e "\n[Test 10: MessageDeliveryService Statistics]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

serviceStatsResult=$(docker exec ${container} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();
    \$service = \$app->services->getMessageDeliveryService();

    \$stats = \$service->getDeliveryStatistics();

    if (is_array(\$stats) && isset(\$stats['total_count'])) {
        echo 'SUCCESS:total=' . \$stats['total_count'] . ',completed=' . (\$stats['completed_count'] ?? 0);
    } else {
        echo 'FAILED';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$serviceStatsResult" =~ ^SUCCESS ]]; then
    printf "\t   MessageDeliveryService getDeliveryStatistics ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   MessageDeliveryService getDeliveryStatistics ${RED}FAILED${NC} (%s)\n" "${serviceStatsResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 11: Message Delivery Debug Output in Log
# ============================================================================
echo -e "\n[Test 11: Check Debug Table for Delivery Logs]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

debugCheck=$(docker exec ${container} php -r "
    require_once('${PDO_FILE}');
    \$pdo = createPDOConnection();

    // Check for MessageDelivery related log entries
    \$stmt = \$pdo->prepare('SELECT COUNT(*) as count FROM debug WHERE message LIKE \"%MessageDelivery%\" OR message LIKE \"%[DLQ]%\"');
    \$stmt->execute();
    \$result = \$stmt->fetch(PDO::FETCH_ASSOC);

    echo 'COUNT:' . \$result['count'];
" 2>/dev/null || echo "ERROR")

if [[ "$debugCheck" =~ ^COUNT ]]; then
    count=$(echo "$debugCheck" | cut -d: -f2)
    printf "\t   Debug output logging ${GREEN}PASSED${NC} (%s entries found)\n" "${count}"
    passed=$(( passed + 1 ))
else
    printf "\t   Debug output logging ${RED}FAILED${NC} (%s)\n" "${debugCheck}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 12: DLQ Alert Status
# ============================================================================
echo -e "\n[Test 12: DLQ Alert Status]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

dlqAlertResult=$(docker exec ${container} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();
    \$service = \$app->services->getMessageDeliveryService();

    \$alert = \$service->getDlqAlertStatus(100); // High threshold for test

    if (is_array(\$alert) && isset(\$alert['pending_count']) && isset(\$alert['alert_triggered'])) {
        echo 'SUCCESS:pending=' . \$alert['pending_count'] . ',triggered=' . (\$alert['alert_triggered'] ? 'true' : 'false');
    } else {
        echo 'FAILED';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$dlqAlertResult" =~ ^SUCCESS ]]; then
    printf "\t   DLQ Alert Status ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   DLQ Alert Status ${RED}FAILED${NC} (%s)\n" "${dlqAlertResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 13: Delivery Stage Progression
# ============================================================================
echo -e "\n[Test 13: Delivery Stage Progression]"
container="${containers[0]}"
totaltests=$(( totaltests + 1 ))

stageResult=$(docker exec ${container} php -r "
    require_once('${DATABASE_DIR}//MessageDeliveryRepository.php');

    \$repo = new MessageDeliveryRepository();

    // Create and progress through stages
    \$testId = 'stage-test-' . time() . '-' . uniqid();
    \$repo->createDelivery('contact', \$testId, 'http://test.local:8080', 'pending', 5);

    // Progress through stages
    \$stages = ['sent', 'received', 'inserted', 'forwarded', 'completed'];
    \$allPassed = true;

    foreach (\$stages as \$stage) {
        if (\$stage === 'completed') {
            \$repo->markCompleted('contact', \$testId);
        } else {
            \$repo->updateStage('contact', \$testId, \$stage);
        }

        \$delivery = \$repo->getByMessage('contact', \$testId);
        if (\$delivery['delivery_stage'] !== \$stage) {
            \$allPassed = false;
            break;
        }
    }

    echo \$allPassed ? 'SUCCESS' : 'FAILED';
" 2>/dev/null || echo "ERROR")

if [[ "$stageResult" == "SUCCESS" ]]; then
    printf "\t   Delivery stage progression ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Delivery stage progression ${RED}FAILED${NC} (%s)\n" "${stageResult}"
    failure=$(( failure + 1 ))
fi

# Print summary
succesrate "${totaltests}" "${passed}" "${failure}" "'message delivery operations'"
