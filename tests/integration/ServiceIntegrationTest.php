<?php
/**
 * Service Integration Tests
 *
 * Test Coverage:
 * - Repository and Service integration
 * - Cross-service interactions
 * - End-to-end workflows
 * - Data flow validation
 *
 * Manual Test Instructions:
 * 1. Run: php tests/integration/ServiceIntegrationTest.php
 * 2. Expected: All integration tests pass
 * 3. Tests verify services work together correctly
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/database/AbstractRepository.php';
require_once dirname(__DIR__, 2) . '/src/database/ContactRepository.php';
require_once dirname(__DIR__, 2) . '/src/database/TransactionRepository.php';
require_once dirname(__DIR__, 2) . '/src/database/P2pRepository.php';
require_once dirname(__DIR__, 2) . '/src/services/WalletService.php';

class ServiceIntegrationTest extends TestCase {
    private $testPdo;
    private $contactRepo;
    private $transactionRepo;
    private $p2pRepo;
    private $walletService;

    /**
     * Create a mock PDO for testing when SQLite is not available
     */
    private function createMockPdo() {
        return new class extends PDO {
            private $data = [];
            public function __construct() {}
            public function exec($statement) { return 0; }
            public function prepare($statement, $options = []) {
                return new class {
                    public $fetchResult = ['name' => 'Test', 'address' => 'addr123'];
                    public function bindValue($p, $v, $t = PDO::PARAM_STR) { return true; }
                    public function execute($params = []) { return true; }
                    public function fetch($mode = PDO::FETCH_ASSOC) { return $this->fetchResult; }
                    public function fetchAll($mode = PDO::FETCH_ASSOC) { return [$this->fetchResult]; }
                    public function rowCount() { return 1; }
                    public function fetchColumn($col = 0) { return null; }
                };
            }
            public function lastInsertId($name = null) { return '1'; }
        };
    }

    public function setUp() {
        parent::setUp();

        // Try to create in-memory SQLite database for integration testing
        try {
            $this->testPdo = createTestDatabase();
            if ($this->testPdo) {
                // Create actual schema for testing
                $this->createTestSchema();
            }
        } catch (Exception $e) {
            // SQLite not available, use mock PDO for basic testing
            $this->testPdo = $this->createMockPdo();
        }

        // Initialize repositories with test database (passing PDO to avoid DatabaseConnection)
        // Note: We must pass PDO directly to bypass DatabaseConnection::getConnection()
        try {
            $this->contactRepo = new ContactRepository($this->testPdo);
            $this->transactionRepo = new TransactionRepository($this->testPdo);
            $this->p2pRepo = new P2pRepository($this->testPdo);
        } catch (Exception $e) {
            // If constructor fails, tests will skip database operations
            echo "Note: Using mock PDO for basic integration testing\n";
        }

        // Initialize wallet service
        $testUser = [
            'public' => 'integration_test_public_key',
            'private' => 'integration_test_private_key',
            'authcode' => 'test_auth_12345',
            'torAddress' => 'testintegration.onion',
            'hostname' => 'https://integration-test.com'
        ];
        $this->walletService = new WalletService($testUser);
    }

    /**
     * Create test database schema
     */
    private function createTestSchema() {
        // Contacts table
        $this->testPdo->exec("
            CREATE TABLE IF NOT EXISTS contacts (
                address TEXT PRIMARY KEY,
                pubkey TEXT,
                pubkey_hash TEXT,
                name TEXT,
                status TEXT DEFAULT 'pending',
                fee_percent REAL,
                credit_limit REAL,
                currency TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Transactions table
        $this->testPdo->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tx_type TEXT,
                sender_address TEXT,
                sender_public_key TEXT,
                sender_public_key_hash TEXT,
                receiver_address TEXT,
                receiver_public_key TEXT,
                receiver_public_key_hash TEXT,
                amount INTEGER,
                currency TEXT,
                txid TEXT UNIQUE,
                previous_txid TEXT,
                sender_signature TEXT,
                memo TEXT,
                status TEXT DEFAULT 'pending',
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // P2P table
        $this->testPdo->exec("
            CREATE TABLE IF NOT EXISTS p2p (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hash TEXT UNIQUE,
                salt TEXT,
                time INTEGER,
                expiration INTEGER,
                currency TEXT,
                amount INTEGER,
                my_fee_amount REAL,
                request_level INTEGER,
                max_request_level INTEGER,
                sender_public_key TEXT,
                sender_address TEXT,
                sender_signature TEXT,
                destination_address TEXT,
                incoming_txid TEXT,
                outgoing_txid TEXT,
                status TEXT DEFAULT 'initial',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP
            )
        ");
    }

    /**
     * Test: Integration - Contact creation and lookup workflow
     *
     * Manual Reproduction:
     * 1. Insert a contact
     * 2. Verify contact exists
     * 3. Lookup contact by name
     * 4. Lookup contact by address
     *
     * Expected: Full workflow completes successfully
     */
    public function testContactCreationAndLookupWorkflow() {
        $address = 'integration_test_address_12345678901234567890123456789012';
        $publicKey = 'integration_test_public_key';
        $name = 'Integration Test Contact';

        // Step 1: Insert contact
        $inserted = $this->contactRepo->insertContact(
            $address,
            $publicKey,
            $name,
            1.5,
            100.0,
            'USD'
        );
        $this->assertTrue($inserted, 'Contact should be inserted');

        // Step 2: Verify exists
        $exists = $this->contactRepo->contactExists($address);
        $this->assertTrue($exists, 'Contact should exist after insertion');

        // Step 3: Lookup by name
        $byName = $this->contactRepo->lookupByName($name);
        $this->assertNotNull($byName, 'Should find contact by name');
        $this->assertEquals($name, $byName['name'], 'Name should match');

        // Step 4: Lookup by address
        $byAddress = $this->contactRepo->lookupByAddress($address);
        $this->assertNotNull($byAddress, 'Should find contact by address');
        $this->assertEquals($address, $byAddress['address'], 'Address should match');
    }

    /**
     * Test: Integration - Contact status lifecycle
     *
     * Manual Reproduction:
     * 1. Create pending contact
     * 2. Accept contact
     * 3. Block contact
     * 4. Unblock contact
     *
     * Expected: Status changes correctly at each step
     */
    public function testContactStatusLifecycle() {
        $address = 'status_test_address_123456789012345678901234567890';
        $publicKey = 'status_test_pubkey';
        $name = 'Status Test';

        // Create pending contact
        $this->contactRepo->insertContact($address, $publicKey, $name, 1.0, 50.0, 'USD');

        // Accept contact
        $accepted = $this->contactRepo->acceptContact($address, $name, 2.0, 100.0, 'USD');
        $this->assertTrue($accepted, 'Contact should be accepted');

        // Block contact
        $blocked = $this->contactRepo->blockContact($address);
        $this->assertTrue($blocked, 'Contact should be blocked');

        // Unblock contact
        $unblocked = $this->contactRepo->unblockContact($address);
        $this->assertTrue($unblocked, 'Contact should be unblocked');
    }

    /**
     * Test: Integration - Transaction insertion and retrieval
     *
     * Manual Reproduction:
     * 1. Insert a transaction
     * 2. Retrieve by txid
     * 3. Verify data matches
     *
     * Expected: Transaction data persists correctly
     */
    public function testTransactionInsertionAndRetrieval() {
        $txid = 'integration_test_txid_' . time();

        $request = [
            'senderAddress' => 'sender_addr',
            'senderPublicKey' => 'sender_pub',
            'receiverAddress' => 'receiver_addr',
            'receiverPublicKey' => 'receiver_pub',
            'amount' => 150,
            'currency' => 'USD',
            'txid' => $txid,
            'previousTxid' => null,
            'signature' => 'signature_data',
            'memo' => 'standard'
        ];

        // Insert transaction
        $result = $this->transactionRepo->insertTransaction($request);
        $decoded = json_decode($result, true);
        $this->assertEquals('accepted', $decoded['status'], 'Transaction should be accepted');

        // Retrieve transaction
        $retrieved = $this->transactionRepo->getByTxid($txid);
        $this->assertNotNull($retrieved, 'Transaction should be retrievable');
        $this->assertEquals($txid, $retrieved['txid'], 'Txid should match');
        $this->assertEquals(150, $retrieved['amount'], 'Amount should match');
    }

    /**
     * Test: Integration - Wallet validation with complete data
     *
     * Manual Reproduction:
     * 1. Create WalletService with complete user data
     * 2. Validate wallet
     * 3. Verify all keys are accessible
     *
     * Expected: Wallet is valid and all data accessible
     */
    public function testWalletValidationWithCompleteData() {
        // Validate wallet
        $validation = $this->walletService->validateWallet();
        $this->assertTrue($validation['valid'], 'Wallet should be valid');
        $this->assertEquals(0, count($validation['errors']), 'Should have no errors');

        // Verify keys accessible
        $this->assertNotNull($this->walletService->getPublicKey(), 'Public key should be accessible');
        $this->assertNotNull($this->walletService->getPrivateKey(), 'Private key should be accessible');
        $this->assertNotNull($this->walletService->getAuthCode(), 'Auth code should be accessible');
        $this->assertTrue($this->walletService->hasKeys(), 'Should have keys');
    }

    /**
     * Test: Integration - P2P request lifecycle
     *
     * Manual Reproduction:
     * 1. Insert P2P request
     * 2. Update status to queued
     * 3. Update incoming txid
     * 4. Update outgoing txid
     * 5. Complete P2P
     *
     * Expected: P2P request progresses through states
     */
    public function testP2pRequestLifecycle() {
        $hash = 'p2p_integration_test_' . time();

        $request = [
            'hash' => $hash,
            'salt' => 'test_salt',
            'time' => time(),
            'expiration' => time() + 3600,
            'currency' => 'USD',
            'amount' => 200,
            'requestLevel' => 2,
            'maxRequestLevel' => 5,
            'senderPublicKey' => 'p2p_sender_pub',
            'senderAddress' => 'p2p_sender_addr',
            'signature' => 'p2p_signature'
        ];

        // Insert P2P request
        $result = $this->p2pRepo->insertP2pRequest($request, null);
        $decoded = json_decode($result, true);
        $this->assertEquals('received', $decoded['status'], 'P2P should be received');

        // Update to queued
        $queued = $this->p2pRepo->updateStatus($hash, 'queued');
        $this->assertTrue($queued, 'Status should update to queued');

        // Update incoming txid
        $incomingUpdated = $this->p2pRepo->updateIncomingTxid($hash, 'incoming_tx_123');
        $this->assertTrue($incomingUpdated, 'Incoming txid should be updated');

        // Update outgoing txid
        $outgoingUpdated = $this->p2pRepo->updateOutgoingTxid($hash, 'outgoing_tx_456');
        $this->assertTrue($outgoingUpdated, 'Outgoing txid should be updated');

        // Complete P2P
        $completed = $this->p2pRepo->updateStatus($hash, 'completed', true);
        $this->assertTrue($completed, 'P2P should be completed');
    }

    /**
     * Test: Integration - Multiple contacts and search
     *
     * Manual Reproduction:
     * 1. Insert multiple contacts
     * 2. Search with partial name
     * 3. Get all addresses
     *
     * Expected: All operations work with multiple records
     */
    public function testMultipleContactsAndSearch() {
        $contacts = [
            ['addr1' . str_repeat('x', 50), 'pubkey1', 'Alice Johnson', 1.0],
            ['addr2' . str_repeat('x', 50), 'pubkey2', 'Bob Smith', 1.5],
            ['addr3' . str_repeat('x', 50), 'pubkey3', 'Alice Williams', 2.0]
        ];

        // Insert contacts
        foreach ($contacts as $contact) {
            $this->contactRepo->insertContact(
                $contact[0],
                $contact[1],
                $contact[2],
                $contact[3],
                100.0,
                'USD'
            );
        }

        // Search for "Alice"
        $searchResults = $this->contactRepo->searchContacts('Alice');
        $this->assertTrue(
            count($searchResults) >= 2,
            'Should find at least 2 contacts with Alice'
        );

        // Get all addresses
        $allAddresses = $this->contactRepo->getAllAddresses();
        $this->assertTrue(
            count($allAddresses) >= 3,
            'Should have at least 3 addresses'
        );
    }

    /**
     * Test: Integration - Transaction statistics calculation
     *
     * Manual Reproduction:
     * 1. Insert multiple transactions
     * 2. Get statistics
     * 3. Verify counts are correct
     *
     * Expected: Statistics reflect inserted data
     */
    public function testTransactionStatisticsCalculation() {
        $transactions = [
            ['txid1_' . time(), 100, 'pending'],
            ['txid2_' . time(), 200, 'completed'],
            ['txid3_' . time(), 150, 'completed']
        ];

        // Insert transactions
        foreach ($transactions as $i => $tx) {
            $request = [
                'senderAddress' => 'sender_' . $i,
                'senderPublicKey' => 'pub_' . $i,
                'receiverAddress' => 'receiver_' . $i,
                'receiverPublicKey' => 'rpub_' . $i,
                'amount' => $tx[1],
                'currency' => 'USD',
                'txid' => $tx[0],
                'previousTxid' => null,
                'signature' => 'sig_' . $i,
                'memo' => 'standard'
            ];
            $this->transactionRepo->insertTransaction($request);

            // Update status if needed
            if ($tx[2] === 'completed') {
                $this->transactionRepo->updateStatus($tx[0], 'completed', true);
            }
        }

        // Get statistics
        $stats = $this->transactionRepo->getStatistics();
        $this->assertNotNull($stats, 'Statistics should not be null');
        $this->assertTrue(
            isset($stats['total_count']) && $stats['total_count'] >= 3,
            'Should have at least 3 transactions in stats'
        );
    }
}

// Run tests
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new ServiceIntegrationTest();
    echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║         Service Integration Tests                                ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";

    $tests = [
        'Contact creation and lookup workflow' => 'testContactCreationAndLookupWorkflow',
        'Contact status lifecycle' => 'testContactStatusLifecycle',
        'Transaction insertion and retrieval' => 'testTransactionInsertionAndRetrieval',
        'Wallet validation with complete data' => 'testWalletValidationWithCompleteData',
        'P2P request lifecycle' => 'testP2pRequestLifecycle',
        'Multiple contacts and search' => 'testMultipleContactsAndSearch',
        'Transaction statistics calculation' => 'testTransactionStatisticsCalculation',
    ];

    $passed = $failed = 0;
    foreach ($tests as $name => $method) {
        $test->setUp();
        try {
            $test->$method();
            echo "✓ $name\n";
            $passed++;
        } catch (Exception $e) {
            echo "✗ $name: " . $e->getMessage() . "\n";
            $failed++;
        }
        $test->tearDown();
    }

    echo "\n" . str_repeat('─', 70) . "\n";
    echo "Results: $passed passed, $failed failed\n";
    echo ($failed === 0) ? "✅ ALL TESTS PASSED\n" : "❌ SOME TESTS FAILED\n";
    exit($failed > 0 ? 1 : 0);
}
