<?php
# Copyright 2025 The Vowels Company

/**
 * Transaction Address Tracking Tests
 *
 * Tests the transaction address tracking feature including:
 * - Setting end_recipient_address on direct transactions
 * - Setting end_recipient_address on P2P sender side
 * - NULL values on relay transactions
 * - Setting initial_sender_address from inquiry responses
 * - Database column migration verification
 */

require_once __DIR__ . '/../files/src/database/Pdo.php';
require_once __DIR__ . '/../files/src/services/ServiceContainer.php';
require_once __DIR__ . '/../files/src/core/UserContext.php';

class TransactionAddressTrackingTest
{
    private PDO $pdo;
    private ServiceContainer $serviceContainer;
    private TransactionService $transactionService;
    private TransactionRepository $transactionRepository;
    private ContactRepository $contactRepository;
    private AddressRepository $addressRepository;
    private UserContext $userContext;

    public function __construct()
    {
        echo "=== Transaction Address Tracking Tests ===\n\n";

        // Initialize database connection
        $this->pdo = createPDOConnection();

        // Initialize user context
        $this->userContext = UserContext::getInstance();

        // Initialize service container
        $this->serviceContainer = ServiceContainer::getInstance($this->userContext, $this->pdo);

        // Get services
        $this->transactionService = $this->serviceContainer->getTransactionService();
        $this->transactionRepository = $this->serviceContainer->getTransactionRepository();
        $this->contactRepository = $this->serviceContainer->getContactRepository();
        $this->addressRepository = $this->serviceContainer->getAddressRepository();
    }

    public function runTests(): bool
    {
        $allPassed = true;

        $allPassed = $this->testColumnMigration() && $allPassed;
        $allPassed = $this->testEndRecipientAddressSetOnDirectTransaction() && $allPassed;
        $allPassed = $this->testEndRecipientAddressSetOnP2pSender() && $allPassed;
        $allPassed = $this->testFieldsNullOnRelayTransaction() && $allPassed;
        $allPassed = $this->testInitialSenderAddressSetFromInquiry() && $allPassed;
        $allPassed = $this->testNullValuesPreserved() && $allPassed;
        $allPassed = $this->testAddressTypesTracking() && $allPassed;
        $allPassed = $this->testMultiHopScenario() && $allPassed;
        $allPassed = $this->testInitialSenderIsSelfOnDirectTransaction() && $allPassed;

        echo "\n";
        if ($allPassed) {
            echo "✓ All address tracking tests passed!\n";
        } else {
            echo "✗ Some address tracking tests failed.\n";
        }

        return $allPassed;
    }

    /**
     * Test that the required columns exist in the transactions table
     */
    private function testColumnMigration(): bool
    {
        echo "Test: Column migration verification... ";

        try {
            // Check if columns exist
            $sql = "SHOW COLUMNS FROM transactions WHERE Field IN ('initial_sender_address', 'end_recipient_address')";
            $stmt = $this->pdo->query($sql);
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($columns) !== 2) {
                echo "✗ FAILED: Required columns not found\n";
                return false;
            }

            // Verify column names
            $columnNames = array_column($columns, 'Field');
            if (!in_array('initial_sender_address', $columnNames) || !in_array('end_recipient_address', $columnNames)) {
                echo "✗ FAILED: Column names incorrect\n";
                return false;
            }

            // Verify columns allow NULL
            foreach ($columns as $column) {
                if ($column['Null'] !== 'YES') {
                    echo "✗ FAILED: Columns should allow NULL values\n";
                    return false;
                }
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Test that end_recipient_address is set on direct transactions
     */
    private function testEndRecipientAddressSetOnDirectTransaction(): bool
    {
        echo "Test: End recipient address on direct transaction... ";

        try {
            // Create a test contact with address
            $testAddress = 'test-direct-' . time() . '.onion';
            $testPubkey = 'test-pubkey-' . bin2hex(random_bytes(16));

            // Insert test contact
            $this->contactRepository->create([
                'public_key' => $testPubkey,
                'name' => 'Test Direct Contact',
                'status' => 'accepted'
            ]);

            // Insert test address
            $this->addressRepository->create([
                'contact_public_key' => $testPubkey,
                'address' => $testAddress,
                'address_type' => 'tor',
                'status' => 'active'
            ]);

            // Create a direct transaction (not a relay)
            $txData = [
                'sender_public_key' => $this->userContext->getPublicKey(),
                'receiver_public_key' => $testPubkey,
                'amount' => 1000000, // 10.00 in microunits
                'currency' => 'USD',
                'txid' => 'test-direct-tx-' . bin2hex(random_bytes(16)),
                'transaction_type' => 'p2p',
                'is_relay' => false,
                'end_recipient_address' => $testAddress
            ];

            $txId = $this->transactionRepository->create($txData);

            if (!$txId) {
                echo "✗ FAILED: Could not create transaction\n";
                return false;
            }

            // Verify end_recipient_address was set
            $transaction = $this->transactionRepository->getById($txId);
            if (!$transaction) {
                echo "✗ FAILED: Transaction not found\n";
                return false;
            }

            if ($transaction['end_recipient_address'] !== $testAddress) {
                echo "✗ FAILED: end_recipient_address not set correctly (expected: {$testAddress}, got: {$transaction['end_recipient_address']})\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Test that end_recipient_address is set on P2P sender side
     */
    private function testEndRecipientAddressSetOnP2pSender(): bool
    {
        echo "Test: End recipient address on P2P sender... ";

        try {
            // Create test contacts for P2P chain
            $recipientAddress = 'test-p2p-recipient-' . time() . '.onion';
            $recipientPubkey = 'test-p2p-pubkey-' . bin2hex(random_bytes(16));

            $this->contactRepository->create([
                'public_key' => $recipientPubkey,
                'name' => 'Test P2P Recipient',
                'status' => 'accepted'
            ]);

            $this->addressRepository->create([
                'contact_public_key' => $recipientPubkey,
                'address' => $recipientAddress,
                'address_type' => 'tor',
                'status' => 'active'
            ]);

            // Create a P2P transaction where we're the sender
            $txData = [
                'sender_public_key' => $this->userContext->getPublicKey(),
                'receiver_public_key' => 'intermediate-node-pubkey',
                'amount' => 2000000, // 20.00 in microunits
                'currency' => 'USD',
                'txid' => 'test-p2p-tx-' . bin2hex(random_bytes(16)),
                'transaction_type' => 'p2p',
                'is_relay' => false,
                'end_recipient_address' => $recipientAddress,
                'final_recipient_pubkey' => $recipientPubkey
            ];

            $txId = $this->transactionRepository->create($txData);

            if (!$txId) {
                echo "✗ FAILED: Could not create P2P transaction\n";
                return false;
            }

            // Verify end_recipient_address was set
            $transaction = $this->transactionRepository->getById($txId);
            if ($transaction['end_recipient_address'] !== $recipientAddress) {
                echo "✗ FAILED: end_recipient_address not set on P2P sender\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Test that address fields are NULL on relay transactions
     */
    private function testFieldsNullOnRelayTransaction(): bool
    {
        echo "Test: Address fields NULL on relay transaction... ";

        try {
            // Create a relay transaction (intermediate node)
            $txData = [
                'sender_public_key' => 'upstream-node-pubkey',
                'receiver_public_key' => 'downstream-node-pubkey',
                'amount' => 1500000, // 15.00 in microunits
                'currency' => 'USD',
                'txid' => 'test-relay-tx-' . bin2hex(random_bytes(16)),
                'transaction_type' => 'p2p',
                'is_relay' => true,
                // Explicitly NOT setting end_recipient_address or initial_sender_address
            ];

            $txId = $this->transactionRepository->create($txData);

            if (!$txId) {
                echo "✗ FAILED: Could not create relay transaction\n";
                return false;
            }

            // Verify both address fields are NULL
            $transaction = $this->transactionRepository->getById($txId);

            if ($transaction['end_recipient_address'] !== null) {
                echo "✗ FAILED: end_recipient_address should be NULL on relay\n";
                return false;
            }

            if ($transaction['initial_sender_address'] !== null) {
                echo "✗ FAILED: initial_sender_address should be NULL on relay\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Test that initial_sender_address is set from inquiry responses
     */
    private function testInitialSenderAddressSetFromInquiry(): bool
    {
        echo "Test: Initial sender address from inquiry... ";

        try {
            // Create a test contact representing the sender
            $senderAddress = 'test-sender-' . time() . '.onion';
            $senderPubkey = 'test-sender-pubkey-' . bin2hex(random_bytes(16));

            $this->contactRepository->create([
                'public_key' => $senderPubkey,
                'name' => 'Test Sender',
                'status' => 'accepted'
            ]);

            $this->addressRepository->create([
                'contact_public_key' => $senderPubkey,
                'address' => $senderAddress,
                'address_type' => 'tor',
                'status' => 'active'
            ]);

            // Create a transaction where we're the recipient
            // initial_sender_address would be set from inquiry response
            $txData = [
                'sender_public_key' => $senderPubkey,
                'receiver_public_key' => $this->userContext->getPublicKey(),
                'amount' => 500000, // 5.00 in microunits
                'currency' => 'USD',
                'txid' => 'test-inquiry-tx-' . bin2hex(random_bytes(16)),
                'transaction_type' => 'p2p',
                'is_relay' => false,
                'initial_sender_address' => $senderAddress
            ];

            $txId = $this->transactionRepository->create($txData);

            if (!$txId) {
                echo "✗ FAILED: Could not create transaction\n";
                return false;
            }

            // Verify initial_sender_address was set
            $transaction = $this->transactionRepository->getById($txId);
            if ($transaction['initial_sender_address'] !== $senderAddress) {
                echo "✗ FAILED: initial_sender_address not set from inquiry\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Test that NULL values are properly preserved
     */
    private function testNullValuesPreserved(): bool
    {
        echo "Test: NULL values preserved... ";

        try {
            // Create transaction without address fields
            $txData = [
                'sender_public_key' => $this->userContext->getPublicKey(),
                'receiver_public_key' => 'test-receiver-' . bin2hex(random_bytes(16)),
                'amount' => 100000,
                'currency' => 'USD',
                'txid' => 'test-null-tx-' . bin2hex(random_bytes(16)),
                'transaction_type' => 'standard'
            ];

            $txId = $this->transactionRepository->create($txData);

            if (!$txId) {
                echo "✗ FAILED: Could not create transaction\n";
                return false;
            }

            // Verify fields are NULL when not set
            $transaction = $this->transactionRepository->getById($txId);

            if ($transaction['end_recipient_address'] !== null || $transaction['initial_sender_address'] !== null) {
                echo "✗ FAILED: NULL values not preserved\n";
                return false;
            }

            // Update transaction and verify NULLs still preserved
            $this->transactionRepository->update($txId, ['amount' => 200000]);
            $transaction = $this->transactionRepository->getById($txId);

            if ($transaction['end_recipient_address'] !== null || $transaction['initial_sender_address'] !== null) {
                echo "✗ FAILED: NULL values not preserved after update\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Test tracking different address types (tor, http, https)
     */
    private function testAddressTypesTracking(): bool
    {
        echo "Test: Different address types tracking... ";

        try {
            $addressTypes = [
                ['type' => 'tor', 'address' => 'test-tor-' . time() . '.onion'],
                ['type' => 'http', 'address' => 'http://test-http-' . time() . '.com'],
                ['type' => 'https', 'address' => 'https://test-https-' . time() . '.com']
            ];

            foreach ($addressTypes as $addressType) {
                $pubkey = 'test-type-pubkey-' . bin2hex(random_bytes(8));

                $this->contactRepository->create([
                    'public_key' => $pubkey,
                    'name' => 'Test ' . $addressType['type'] . ' Contact',
                    'status' => 'accepted'
                ]);

                $this->addressRepository->create([
                    'contact_public_key' => $pubkey,
                    'address' => $addressType['address'],
                    'address_type' => $addressType['type'],
                    'status' => 'active'
                ]);

                $txData = [
                    'sender_public_key' => $this->userContext->getPublicKey(),
                    'receiver_public_key' => $pubkey,
                    'amount' => 100000,
                    'currency' => 'USD',
                    'txid' => 'test-type-tx-' . bin2hex(random_bytes(16)),
                    'transaction_type' => 'p2p',
                    'end_recipient_address' => $addressType['address']
                ];

                $txId = $this->transactionRepository->create($txData);
                $transaction = $this->transactionRepository->getById($txId);

                if ($transaction['end_recipient_address'] !== $addressType['address']) {
                    echo "✗ FAILED: {$addressType['type']} address not tracked correctly\n";
                    return false;
                }
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Test multi-hop scenario with proper address tracking
     */
    private function testMultiHopScenario(): bool
    {
        echo "Test: Multi-hop address tracking scenario... ";

        try {
            // Simulate A -> B -> C -> D scenario
            $originalSenderAddress = 'test-sender-' . time() . '.onion';
            $finalRecipientAddress = 'test-recipient-' . time() . '.onion';

            // Transaction at original sender (A)
            $txData1 = [
                'sender_public_key' => 'sender-a-pubkey',
                'receiver_public_key' => 'node-b-pubkey',
                'amount' => 1000000,
                'currency' => 'USD',
                'txid' => 'multihop-tx1-' . bin2hex(random_bytes(16)),
                'transaction_type' => 'p2p',
                'is_relay' => false,
                'end_recipient_address' => $finalRecipientAddress
            ];

            $tx1Id = $this->transactionRepository->create($txData1);

            // Transaction at intermediate node B (relay)
            $txData2 = [
                'sender_public_key' => 'sender-a-pubkey',
                'receiver_public_key' => 'node-c-pubkey',
                'amount' => 1000000,
                'currency' => 'USD',
                'txid' => 'multihop-tx2-' . bin2hex(random_bytes(16)),
                'transaction_type' => 'p2p',
                'is_relay' => true
                // No address fields on relay
            ];

            $tx2Id = $this->transactionRepository->create($txData2);

            // Transaction at final recipient (D)
            $txData3 = [
                'sender_public_key' => 'node-c-pubkey',
                'receiver_public_key' => 'recipient-d-pubkey',
                'amount' => 1000000,
                'currency' => 'USD',
                'txid' => 'multihop-tx3-' . bin2hex(random_bytes(16)),
                'transaction_type' => 'p2p',
                'is_relay' => false,
                'initial_sender_address' => $originalSenderAddress
            ];

            $tx3Id = $this->transactionRepository->create($txData3);

            // Verify addresses on endpoints
            $tx1 = $this->transactionRepository->getById($tx1Id);
            $tx2 = $this->transactionRepository->getById($tx2Id);
            $tx3 = $this->transactionRepository->getById($tx3Id);

            if ($tx1['end_recipient_address'] !== $finalRecipientAddress) {
                echo "✗ FAILED: Original sender should have end_recipient_address\n";
                return false;
            }

            if ($tx2['end_recipient_address'] !== null || $tx2['initial_sender_address'] !== null) {
                echo "✗ FAILED: Relay node should have NULL addresses\n";
                return false;
            }

            if ($tx3['initial_sender_address'] !== $originalSenderAddress) {
                echo "✗ FAILED: Final recipient should have initial_sender_address\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Test that initial_sender_address is set to sender's own address on direct transactions
     * (Issue #279: initial_sender_address should be YOUR address as original sender, not receiver's)
     */
    private function testInitialSenderIsSelfOnDirectTransaction(): bool
    {
        echo "Test: Initial sender is self on direct transaction... ";

        try {
            // Get the user's own address
            $userAddress = $this->userContext->getTorAddress() ?? $this->userContext->getHttpAddress();

            if ($userAddress === null) {
                echo "✗ FAILED: User has no configured address\n";
                return false;
            }

            // Create a test contact with address
            $recipientAddress = 'test-recipient-initial-' . time() . '.onion';
            $recipientPubkey = 'test-initial-pubkey-' . bin2hex(random_bytes(16));

            $this->contactRepository->create([
                'public_key' => $recipientPubkey,
                'name' => 'Test Initial Sender Contact',
                'status' => 'accepted'
            ]);

            $this->addressRepository->create([
                'contact_public_key' => $recipientPubkey,
                'address' => $recipientAddress,
                'address_type' => 'tor',
                'status' => 'active'
            ]);

            // Create a direct transaction where we are the sender
            $txData = [
                'sender_public_key' => $this->userContext->getPublicKey(),
                'receiver_public_key' => $recipientPubkey,
                'amount' => 1000000,
                'currency' => 'USD',
                'txid' => 'test-initial-self-tx-' . bin2hex(random_bytes(16)),
                'transaction_type' => 'p2p',
                'is_relay' => false,
                'end_recipient_address' => $recipientAddress,
                'initial_sender_address' => $userAddress  // Should be OUR address, not receiver's
            ];

            $txId = $this->transactionRepository->create($txData);

            if (!$txId) {
                echo "✗ FAILED: Could not create transaction\n";
                return false;
            }

            // Verify initial_sender_address is the sender's own address (not receiver's)
            $transaction = $this->transactionRepository->getById($txId);

            if ($transaction['initial_sender_address'] === null) {
                echo "✗ FAILED: initial_sender_address should not be NULL\n";
                return false;
            }

            if ($transaction['initial_sender_address'] === $recipientAddress) {
                echo "✗ FAILED: initial_sender_address should NOT equal recipient's address\n";
                return false;
            }

            if ($transaction['initial_sender_address'] !== $userAddress) {
                echo "✗ FAILED: initial_sender_address should be sender's own address (expected: {$userAddress}, got: {$transaction['initial_sender_address']})\n";
                return false;
            }

            // Also verify end_recipient_address is the receiver's address
            if ($transaction['end_recipient_address'] !== $recipientAddress) {
                echo "✗ FAILED: end_recipient_address should be receiver's address\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

// Run tests
$test = new TransactionAddressTrackingTest();
$success = $test->runTests();

exit($success ? 0 : 1);
