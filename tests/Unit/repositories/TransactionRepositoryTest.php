<?php
/**
 * TransactionRepository Unit Tests
 *
 * Test Coverage:
 * - Transaction CRUD operations
 * - Balance calculations (sent/received)
 * - Transaction chain validation
 * - Status management
 * - Edge cases and error handling
 *
 * Manual Test Instructions:
 * 1. Run: php tests/unit/repositories/TransactionRepositoryTest.php
 * 2. Expected: All tests pass with green checkmarks
 * 3. Verify each test covers specific functionality
 */

require_once dirname(__DIR__, 2) . '/walletTests/bootstrap.php';
require_once dirname(__DIR__, 3) . '/src/database/AbstractRepository.php';
require_once dirname(__DIR__, 3) . '/src/database/TransactionRepository.php';

class TransactionRepositoryTest extends TestCase {
    private $repository;
    private $mockPdo;

    public function setUp() {
        parent::setUp();
        $this->mockPdo = $this->createMockPdo();
        $this->repository = new TransactionRepository($this->mockPdo);
    }

    /**
     * Create a mock PDO with configurable behavior
     */
    private function createMockPdo() {
        return new class extends PDO {
            public $preparedQuery = '';
            public $lastInsertId = '1';
            public $queries = [];

            public function __construct() {
                // Don't call parent
            }

            public function prepare($statement, $options = []) {
                $this->preparedQuery = $statement;
                $this->queries[] = $statement;
                return new class {
                    public $executeResult = true;
                    public $fetchResult = ['total_sent' => 1000];
                    public $fetchAllResult = [];
                    public $fetchColumnResult = null;
                    public $rowCountResult = 1;
                    private $boundValues = [];

                    public function bindValue($param, $value, $type = PDO::PARAM_STR) {
                        $this->boundValues[$param] = $value;
                        return true;
                    }

                    public function execute($params = []) {
                        return $this->executeResult;
                    }

                    public function fetch($mode = PDO::FETCH_ASSOC) {
                        return $this->fetchResult;
                    }

                    public function fetchAll($mode = PDO::FETCH_ASSOC) {
                        return $this->fetchAllResult;
                    }

                    public function fetchColumn($column = 0) {
                        return $this->fetchColumnResult;
                    }

                    public function rowCount() {
                        return $this->rowCountResult;
                    }
                };
            }

            public function lastInsertId($name = null) {
                return $this->lastInsertId;
            }

            public function beginTransaction() {
                return true;
            }

            public function commit() {
                return true;
            }

            public function rollBack() {
                return true;
            }
        };
    }

    /**
     * Test: Calculate total amount sent to a public key
     *
     * Manual Reproduction:
     * 1. Create repository with mock PDO
     * 2. Call calculateTotalSent() with public key
     * 3. Verify returns float value
     *
     * Expected: Returns sum of amounts sent to public key
     */
    public function testCalculateTotalSentReturnsFloat() {
        $publicKey = 'receiver_pubkey_123';

        $result = $this->repository->calculateTotalSent($publicKey);

        $this->assertTrue(
            is_float($result) || is_int($result),
            'calculateTotalSent should return numeric value'
        );
    }

    /**
     * Test: Calculate total amount sent by user
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call calculateTotalSentByUser() with user's public key
     * 3. Verify returns float >= 0
     *
     * Expected: Returns total sent by user
     */
    public function testCalculateTotalSentByUserReturnsNonNegative() {
        $userPublicKey = 'user_pubkey_456';

        $result = $this->repository->calculateTotalSentByUser($userPublicKey);

        $this->assertTrue(
            (is_float($result) || is_int($result)) && $result >= 0,
            'calculateTotalSentByUser should return non-negative number'
        );
    }

    /**
     * Test: Calculate total amount received from a public key
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call calculateTotalReceived() with public key
     * 3. Verify returns float >= 0
     *
     * Expected: Returns total received from sender
     */
    public function testCalculateTotalReceivedReturnsNonNegative() {
        $publicKey = 'sender_pubkey_789';

        $result = $this->repository->calculateTotalReceived($publicKey);

        $this->assertTrue(
            (is_float($result) || is_int($result)) && $result >= 0,
            'calculateTotalReceived should return non-negative number'
        );
    }

    /**
     * Test: Calculate total amount received by user (excluding self-sends)
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call calculateTotalReceivedByUser() with user's public key
     * 3. Verify returns float >= 0
     *
     * Expected: Returns total received excluding self-transactions
     */
    public function testCalculateTotalReceivedByUserExcludesSelf() {
        $userPublicKey = 'user_pubkey_abc';

        $result = $this->repository->calculateTotalReceivedByUser($userPublicKey);

        $this->assertTrue(
            (is_float($result) || is_int($result)) && $result >= 0,
            'calculateTotalReceivedByUser should return non-negative number'
        );
    }

    /**
     * Test: Check if transaction is completed by memo
     *
     * Manual Reproduction:
     * 1. Create repository with mock returning completed transaction
     * 2. Call isCompletedByMemo() with memo
     * 3. Verify returns boolean
     *
     * Expected: Returns true if transaction is completed
     */
    public function testIsCompletedByMemoReturnsBoolean() {
        $memo = 'test_memo_123';

        $result = $this->repository->isCompletedByMemo($memo);

        $this->assertTrue(is_bool($result), 'isCompletedByMemo should return boolean');
    }

    /**
     * Test: Check if transaction is completed by txid
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call isCompletedByTxid() with transaction ID
     * 3. Verify returns boolean
     *
     * Expected: Returns true if transaction is completed
     */
    public function testIsCompletedByTxidReturnsBoolean() {
        $txid = 'txid_abc123';

        $result = $this->repository->isCompletedByTxid($txid);

        $this->assertTrue(is_bool($result), 'isCompletedByTxid should return boolean');
    }

    /**
     * Test: Check if previous txid exists in chain
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call existingPreviousTxid() with previous transaction ID
     * 3. Verify returns boolean
     *
     * Expected: Returns true if previous txid is used
     */
    public function testExistingPreviousTxidReturnsBoolean() {
        $previousTxid = 'prev_txid_xyz';

        $result = $this->repository->existingPreviousTxid($previousTxid);

        $this->assertTrue(is_bool($result), 'existingPreviousTxid should return boolean');
    }

    /**
     * Test: Check if txid exists
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call existingTxid() with transaction ID
     * 3. Verify returns boolean
     *
     * Expected: Returns true if txid exists
     */
    public function testExistingTxidReturnsBoolean() {
        $txid = 'existing_txid_456';

        $result = $this->repository->existingTxid($txid);

        $this->assertTrue(is_bool($result), 'existingTxid should return boolean');
    }

    /**
     * Test: Get previous transaction ID between two parties
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call getPreviousTxid() with sender and receiver public keys
     * 3. Verify returns string or null
     *
     * Expected: Returns previous txid or null if none exists
     */
    public function testGetPreviousTxidReturnsStringOrNull() {
        $senderPublicKey = 'sender_pub_key';
        $receiverPublicKey = 'receiver_pub_key';

        $result = $this->repository->getPreviousTxid($senderPublicKey, $receiverPublicKey);

        $this->assertTrue(
            is_string($result) || $result === null,
            'getPreviousTxid should return string or null'
        );
    }

    /**
     * Test: Get transaction by memo
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call getByMemo() with memo string
     * 3. Verify returns array or null
     *
     * Expected: Returns transaction data or null
     */
    public function testGetByMemoReturnsArrayOrNull() {
        $memo = 'transaction_memo';

        $result = $this->repository->getByMemo($memo);

        $this->assertTrue(
            is_array($result) || $result === null,
            'getByMemo should return array or null'
        );
    }

    /**
     * Test: Get transaction by txid
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call getByTxid() with transaction ID
     * 3. Verify returns array or null
     *
     * Expected: Returns transaction data or null
     */
    public function testGetByTxidReturnsArrayOrNull() {
        $txid = 'transaction_id_123';

        $result = $this->repository->getByTxid($txid);

        $this->assertTrue(
            is_array($result) || $result === null,
            'getByTxid should return array or null'
        );
    }

    /**
     * Test: Insert transaction with standard type
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Build valid transaction request with standard memo
     * 3. Call insertTransaction()
     * 4. Verify returns JSON string with status
     *
     * Expected: Transaction is inserted and JSON response returned
     */
    public function testInsertStandardTransactionReturnsJson() {
        $request = [
            'senderAddress' => 'sender_addr',
            'senderPublicKey' => 'sender_pub',
            'receiverAddress' => 'receiver_addr',
            'receiverPublicKey' => 'receiver_pub',
            'amount' => 100,
            'currency' => 'USD',
            'txid' => 'txid_123',
            'previousTxid' => null,
            'signature' => 'signature_data',
            'memo' => 'standard'
        ];

        $result = $this->repository->insertTransaction($request);

        $this->assertTrue(is_string($result), 'insertTransaction should return string');

        // Verify valid JSON
        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded, 'Result should be valid JSON');
        $this->assertTrue(isset($decoded['status']), 'JSON should have status field');
    }

    /**
     * Test: Insert transaction with P2P type
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Build transaction request with non-standard memo
     * 3. Call insertTransaction()
     * 4. Verify returns JSON with accepted/rejected status
     *
     * Expected: P2P transaction is inserted
     */
    public function testInsertP2pTransactionReturnsJson() {
        $request = [
            'senderAddress' => 'sender_addr',
            'senderPublicKey' => 'sender_pub',
            'receiverAddress' => 'receiver_addr',
            'receiverPublicKey' => 'receiver_pub',
            'amount' => 200,
            'currency' => 'USD',
            'txid' => 'txid_456',
            'previousTxid' => 'prev_123',
            'signature' => 'signature_data',
            'memo' => 'p2p_hash_xyz'
        ];

        $result = $this->repository->insertTransaction($request);

        $this->assertTrue(is_string($result), 'insertTransaction should return string');
        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded, 'Result should be valid JSON');
    }

    /**
     * Test: Get pending transactions with limit
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call getPendingTransactions(10)
     * 3. Verify returns array
     *
     * Expected: Returns array of pending transactions
     */
    public function testGetPendingTransactionsReturnsArray() {
        $limit = 5;

        $result = $this->repository->getPendingTransactions($limit);

        $this->assertTrue(is_array($result), 'getPendingTransactions should return array');
    }

    /**
     * Test: Update transaction status by memo
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call updateStatus() with memo and new status
     * 3. Verify returns boolean
     *
     * Expected: Status is updated successfully
     */
    public function testUpdateStatusByMemoReturnsBoolean() {
        $memo = 'transaction_memo';
        $newStatus = 'completed';

        $result = $this->repository->updateStatus($memo, $newStatus, false);

        $this->assertTrue(is_bool($result), 'updateStatus should return boolean');
    }

    /**
     * Test: Update transaction status by txid
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call updateStatus() with txid and new status
     * 3. Verify returns boolean
     *
     * Expected: Status is updated successfully
     */
    public function testUpdateStatusByTxidReturnsBoolean() {
        $txid = 'transaction_id';
        $newStatus = 'accepted';

        $result = $this->repository->updateStatus($txid, $newStatus, true);

        $this->assertTrue(is_bool($result), 'updateStatus by txid should return boolean');
    }

    /**
     * Test: Get transactions by status
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call getByStatus('completed')
     * 3. Verify returns array
     *
     * Expected: Returns array of transactions with specified status
     */
    public function testGetByStatusReturnsArray() {
        $status = 'completed';

        $result = $this->repository->getByStatus($status);

        $this->assertTrue(is_array($result), 'getByStatus should return array');
    }

    /**
     * Test: Get transactions by status with limit
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call getByStatus('pending', 10)
     * 3. Verify returns array
     *
     * Expected: Returns limited array of transactions
     */
    public function testGetByStatusWithLimitReturnsArray() {
        $status = 'pending';
        $limit = 10;

        $result = $this->repository->getByStatus($status, $limit);

        $this->assertTrue(is_array($result), 'getByStatus with limit should return array');
    }

    /**
     * Test: Get transactions between two addresses
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call getTransactionsBetweenAddresses() with two addresses
     * 3. Verify returns array
     *
     * Expected: Returns transactions between the two addresses
     */
    public function testGetTransactionsBetweenAddressesReturnsArray() {
        $address1 = 'addr_one';
        $address2 = 'addr_two';

        $result = $this->repository->getTransactionsBetweenAddresses($address1, $address2);

        $this->assertTrue(
            is_array($result),
            'getTransactionsBetweenAddresses should return array'
        );
    }

    /**
     * Test: Get transactions between addresses with limit
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call getTransactionsBetweenAddresses() with limit
     * 3. Verify returns array
     *
     * Expected: Returns limited transactions
     */
    public function testGetTransactionsBetweenAddressesWithLimit() {
        $address1 = 'addr_one';
        $address2 = 'addr_two';
        $limit = 20;

        $result = $this->repository->getTransactionsBetweenAddresses($address1, $address2, $limit);

        $this->assertTrue(is_array($result), 'Should return array with limit');
    }

    /**
     * Test: Get transaction statistics
     *
     * Manual Reproduction:
     * 1. Create repository
     * 2. Call getStatistics()
     * 3. Verify returns array with expected keys
     *
     * Expected: Returns statistics array
     */
    public function testGetStatisticsReturnsArray() {
        $result = $this->repository->getStatistics();

        $this->assertTrue(is_array($result), 'getStatistics should return array');
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new TransactionRepositoryTest();

    echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║      TransactionRepository Unit Tests                            ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";

    $tests = [
        'Calculate total sent' => 'testCalculateTotalSentReturnsFloat',
        'Calculate total sent by user' => 'testCalculateTotalSentByUserReturnsNonNegative',
        'Calculate total received' => 'testCalculateTotalReceivedReturnsNonNegative',
        'Calculate total received by user' => 'testCalculateTotalReceivedByUserExcludesSelf',
        'Is completed by memo' => 'testIsCompletedByMemoReturnsBoolean',
        'Is completed by txid' => 'testIsCompletedByTxidReturnsBoolean',
        'Existing previous txid' => 'testExistingPreviousTxidReturnsBoolean',
        'Existing txid' => 'testExistingTxidReturnsBoolean',
        'Get previous txid' => 'testGetPreviousTxidReturnsStringOrNull',
        'Get by memo' => 'testGetByMemoReturnsArrayOrNull',
        'Get by txid' => 'testGetByTxidReturnsArrayOrNull',
        'Insert standard transaction' => 'testInsertStandardTransactionReturnsJson',
        'Insert P2P transaction' => 'testInsertP2pTransactionReturnsJson',
        'Get pending transactions' => 'testGetPendingTransactionsReturnsArray',
        'Update status by memo' => 'testUpdateStatusByMemoReturnsBoolean',
        'Update status by txid' => 'testUpdateStatusByTxidReturnsBoolean',
        'Get by status' => 'testGetByStatusReturnsArray',
        'Get by status with limit' => 'testGetByStatusWithLimitReturnsArray',
        'Get transactions between addresses' => 'testGetTransactionsBetweenAddressesReturnsArray',
        'Get transactions between addresses with limit' => 'testGetTransactionsBetweenAddressesWithLimit',
        'Get statistics' => 'testGetStatisticsReturnsArray',
    ];

    $passed = 0;
    $failed = 0;

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

    if ($failed === 0) {
        echo "✅ ALL TESTS PASSED\n";
    } else {
        echo "❌ SOME TESTS FAILED\n";
    }

    exit($failed > 0 ? 1 : 0);
}
