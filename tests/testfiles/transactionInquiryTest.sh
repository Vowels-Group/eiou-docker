#!/bin/bash

# Transaction Inquiry Test
# Tests the handleTransactionMessageInquiryRequest method to ensure it returns actual transaction status

source "$(dirname "$0")/../baseconfig/config.sh"

echo "=========================================="
echo "Transaction Inquiry Test"
echo "=========================================="

# Test 1: Inquiry for completed transaction
echo ""
echo "Test 1: Transaction inquiry for completed transaction"
echo "--------------------------------------------------"

# First, send a transaction from Alice to Bob
echo "Sending transaction from Alice to Bob..."
ALICE_PUBKEY=$(docker exec alice bash -c "php /etc/eiou/src/CLI.php -action=get -property=public_key" 2>/dev/null | jq -r '.public_key')
BOB_ADDRESS=$(docker exec bob bash -c "php /etc/eiou/src/CLI.php -action=get -property=address" 2>/dev/null | jq -r '.address')

SEND_RESULT=$(docker exec alice bash -c "php /etc/eiou/src/CLI.php -action=send -address=$BOB_ADDRESS -amount=1.00 -description='Test transaction for inquiry'" 2>/dev/null)
TXID=$(echo "$SEND_RESULT" | jq -r '.txid')

if [ "$TXID" != "null" ] && [ -n "$TXID" ]; then
    echo "✓ Transaction sent successfully: $TXID"

    # Wait for transaction to complete
    sleep 3

    # Now send an inquiry from Alice about this transaction
    echo "Sending inquiry for completed transaction..."
    INQUIRY_RESULT=$(docker exec alice bash -c "php -r \"
        require_once '/etc/eiou/src/context/UserContext.php';
        require_once '/etc/eiou/src/services/ServiceContainer.php';

        \\\$container = ServiceContainer::getInstance();
        \\\$messageService = \\\$container->getMessageService();
        \\\$transportUtility = \\\$container->getUtilityContainer()->getTransportUtility();

        \\\$inquiry = [
            'type' => 'message',
            'typeMessage' => 'transaction',
            'inquiry' => true,
            'hash' => '$TXID',
            'hashType' => 'txid',
            'senderAddress' => '$BOB_ADDRESS',
            'senderPublicKey' => (string) file_get_contents('/etc/eiou/nodeInfo/key.public')
        ];

        \\\$rawResponse = \\\$transportUtility->send('$BOB_ADDRESS', \\\$inquiry);
        echo \\\$rawResponse;
    \"" 2>/dev/null)

    STATUS=$(echo "$INQUIRY_RESULT" | jq -r '.status')

    if [ "$STATUS" = "completed" ]; then
        echo "✓ Test 1 PASSED: Received correct status 'completed' for completed transaction"
    else
        echo "✗ Test 1 FAILED: Expected status 'completed', got '$STATUS'"
        echo "Response: $INQUIRY_RESULT"
    fi
else
    echo "✗ Test 1 FAILED: Could not send initial transaction"
fi

# Test 2: Inquiry for pending transaction
echo ""
echo "Test 2: Transaction inquiry for pending transaction"
echo "--------------------------------------------------"

# Create a pending transaction by inserting directly into database
PENDING_TXID="test-pending-$(date +%s)"
docker exec alice bash -c "mysql -u root -ppassword eiou -e \"
    INSERT INTO transactions (txid, tx_type, type, status, sender_address, receiver_address,
        sender_public_key, receiver_public_key, sender_public_key_hash, receiver_public_key_hash,
        amount, currency, memo, timestamp)
    VALUES (
        '$PENDING_TXID',
        'standard',
        'sent',
        'pending',
        (SELECT http FROM addresses WHERE pubkey_hash = (SELECT pubkey_hash FROM users LIMIT 1) LIMIT 1),
        '$BOB_ADDRESS',
        '$ALICE_PUBKEY',
        (SELECT public_key FROM nodeInfo WHERE address = '$BOB_ADDRESS' LIMIT 1),
        SHA2('$ALICE_PUBKEY', 256),
        (SELECT SHA2(public_key, 256) FROM nodeInfo WHERE address = '$BOB_ADDRESS' LIMIT 1),
        100,
        'USD',
        'standard',
        NOW()
    )
\"" 2>/dev/null

# Send inquiry for pending transaction
PENDING_INQUIRY=$(docker exec alice bash -c "php -r \"
    require_once '/etc/eiou/src/context/UserContext.php';
    require_once '/etc/eiou/src/services/ServiceContainer.php';

    \\\$container = ServiceContainer::getInstance();
    \\\$transportUtility = \\\$container->getUtilityContainer()->getTransportUtility();

    \\\$inquiry = [
        'type' => 'message',
        'typeMessage' => 'transaction',
        'inquiry' => true,
        'hash' => '$PENDING_TXID',
        'hashType' => 'txid',
        'senderAddress' => '$BOB_ADDRESS',
        'senderPublicKey' => (string) file_get_contents('/etc/eiou/nodeInfo/key.public')
    ];

    \\\$rawResponse = \\\$transportUtility->send((SELECT http FROM addresses WHERE pubkey_hash = (SELECT pubkey_hash FROM users LIMIT 1) LIMIT 1), \\\$inquiry);
    echo \\\$rawResponse;
\"" 2>/dev/null)

PENDING_STATUS=$(echo "$PENDING_INQUIRY" | jq -r '.status')

if [ "$PENDING_STATUS" = "pending" ]; then
    echo "✓ Test 2 PASSED: Received correct status 'pending' for pending transaction"
else
    echo "✗ Test 2 FAILED: Expected status 'pending', got '$PENDING_STATUS'"
    echo "Response: $PENDING_INQUIRY"
fi

# Test 3: Inquiry for non-existent transaction
echo ""
echo "Test 3: Transaction inquiry for non-existent transaction"
echo "--------------------------------------------------"

FAKE_TXID="nonexistent-txid-12345"
NOTFOUND_INQUIRY=$(docker exec alice bash -c "php -r \"
    require_once '/etc/eiou/src/context/UserContext.php';
    require_once '/etc/eiou/src/services/ServiceContainer.php';

    \\\$container = ServiceContainer::getInstance();
    \\\$transportUtility = \\\$container->getUtilityContainer()->getTransportUtility();

    \\\$inquiry = [
        'type' => 'message',
        'typeMessage' => 'transaction',
        'inquiry' => true,
        'hash' => '$FAKE_TXID',
        'hashType' => 'txid',
        'senderAddress' => '$BOB_ADDRESS',
        'senderPublicKey' => (string) file_get_contents('/etc/eiou/nodeInfo/key.public')
    ];

    \\\$rawResponse = \\\$transportUtility->send('$BOB_ADDRESS', \\\$inquiry);
    echo \\\$rawResponse;
\"" 2>/dev/null)

NOTFOUND_STATUS=$(echo "$NOTFOUND_INQUIRY" | jq -r '.status')

if [ "$NOTFOUND_STATUS" = "not_found" ]; then
    echo "✓ Test 3 PASSED: Received correct status 'not_found' for non-existent transaction"
else
    echo "✗ Test 3 FAILED: Expected status 'not_found', got '$NOTFOUND_STATUS'"
    echo "Response: $NOTFOUND_INQUIRY"
fi

# Test 4: Inquiry using memo (for P2P transactions)
echo ""
echo "Test 4: Transaction inquiry using memo (P2P)"
echo "--------------------------------------------------"

# Send a P2P transaction from Alice to Daniel via Bob and Carol
DANIEL_ADDRESS=$(docker exec daniel bash -c "php /etc/eiou/src/CLI.php -action=get -property=address" 2>/dev/null | jq -r '.address')

if [ -n "$DANIEL_ADDRESS" ] && [ "$DANIEL_ADDRESS" != "null" ]; then
    P2P_RESULT=$(docker exec alice bash -c "php /etc/eiou/src/CLI.php -action=send -address=$DANIEL_ADDRESS -amount=0.50 -description='P2P test transaction'" 2>/dev/null)
    P2P_MEMO=$(echo "$P2P_RESULT" | jq -r '.memo')

    if [ "$P2P_MEMO" != "null" ] && [ -n "$P2P_MEMO" ]; then
        echo "✓ P2P transaction initiated with memo: $P2P_MEMO"

        # Wait for P2P transaction to complete
        sleep 5

        # Send inquiry using memo
        MEMO_INQUIRY=$(docker exec alice bash -c "php -r \"
            require_once '/etc/eiou/src/context/UserContext.php';
            require_once '/etc/eiou/src/services/ServiceContainer.php';

            \\\$container = ServiceContainer::getInstance();
            \\\$transportUtility = \\\$container->getUtilityContainer()->getTransportUtility();

            \\\$inquiry = [
                'type' => 'message',
                'typeMessage' => 'transaction',
                'inquiry' => true,
                'hash' => '$P2P_MEMO',
                'hashType' => 'memo',
                'senderAddress' => '$DANIEL_ADDRESS',
                'senderPublicKey' => (string) file_get_contents('/etc/eiou/nodeInfo/key.public')
            ];

            \\\$rawResponse = \\\$transportUtility->send('$DANIEL_ADDRESS', \\\$inquiry);
            echo \\\$rawResponse;
        \"" 2>/dev/null)

        MEMO_STATUS=$(echo "$MEMO_INQUIRY" | jq -r '.status')

        if [ "$MEMO_STATUS" = "completed" ] || [ "$MEMO_STATUS" = "pending" ] || [ "$MEMO_STATUS" = "sent" ]; then
            echo "✓ Test 4 PASSED: Received valid status '$MEMO_STATUS' for P2P transaction inquiry"
        else
            echo "✗ Test 4 FAILED: Expected valid transaction status, got '$MEMO_STATUS'"
            echo "Response: $MEMO_INQUIRY"
        fi
    else
        echo "⊘ Test 4 SKIPPED: Could not initiate P2P transaction"
    fi
else
    echo "⊘ Test 4 SKIPPED: Daniel container not available for P2P testing"
fi

# Test 5: Verify description is stored during inquiry
echo ""
echo "Test 5: Verify description storage during inquiry"
echo "--------------------------------------------------"

TEST_DESC="Test description from inquiry"
DESC_TXID="test-desc-$(date +%s)"

# Create transaction without description
docker exec bob bash -c "mysql -u root -ppassword eiou -e \"
    INSERT INTO transactions (txid, tx_type, type, status, sender_address, receiver_address,
        sender_public_key, receiver_public_key, sender_public_key_hash, receiver_public_key_hash,
        amount, currency, memo, timestamp)
    VALUES (
        '$DESC_TXID',
        'standard',
        'received',
        'completed',
        (SELECT http FROM addresses WHERE pubkey_hash != (SELECT pubkey_hash FROM users LIMIT 1) LIMIT 1),
        (SELECT http FROM addresses WHERE pubkey_hash = (SELECT pubkey_hash FROM users LIMIT 1) LIMIT 1),
        '$ALICE_PUBKEY',
        (SELECT public_key FROM users LIMIT 1),
        SHA2('$ALICE_PUBKEY', 256),
        (SELECT SHA2(public_key, 256) FROM users LIMIT 1),
        100,
        'USD',
        'standard',
        NOW()
    )
\"" 2>/dev/null

# Send inquiry with description
docker exec alice bash -c "php -r \"
    require_once '/etc/eiou/src/context/UserContext.php';
    require_once '/etc/eiou/src/services/ServiceContainer.php';

    \\\$container = ServiceContainer::getInstance();
    \\\$transportUtility = \\\$container->getUtilityContainer()->getTransportUtility();
    \\\$bobAddress = (string) shell_exec('php /etc/eiou/src/CLI.php -action=get -property=address 2>/dev/null | jq -r .address');

    \\\$inquiry = [
        'type' => 'message',
        'typeMessage' => 'transaction',
        'inquiry' => true,
        'hash' => '$DESC_TXID',
        'hashType' => 'txid',
        'description' => '$TEST_DESC',
        'senderAddress' => trim(\\\$bobAddress),
        'senderPublicKey' => (string) file_get_contents('/etc/eiou/nodeInfo/key.public')
    ];

    \\\$rawResponse = \\\$transportUtility->send(trim(\\\$bobAddress), \\\$inquiry);
\"" 2>/dev/null

# Check if description was stored
STORED_DESC=$(docker exec bob bash -c "mysql -u root -ppassword eiou -se \"SELECT description FROM transactions WHERE txid = '$DESC_TXID'\"" 2>/dev/null)

if [ "$STORED_DESC" = "$TEST_DESC" ]; then
    echo "✓ Test 5 PASSED: Description was stored correctly during inquiry"
else
    echo "✗ Test 5 FAILED: Description not stored correctly"
    echo "Expected: '$TEST_DESC'"
    echo "Got: '$STORED_DESC'"
fi

echo ""
echo "=========================================="
echo "Transaction Inquiry Test Complete"
echo "=========================================="
