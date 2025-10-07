<?php
/**
 * P2pRepository Unit Tests
 *
 * Test Coverage:
 * - P2P request CRUD operations
 * - Status management
 * - Txid tracking (incoming/outgoing)
 * - Credit calculations
 * - Expiration handling
 *
 * Manual Test Instructions:
 * 1. Run: php tests/unit/repositories/P2pRepositoryTest.php
 * 2. Expected: All tests pass
 * 3. Tests verify P2P routing functionality
 */

require_once dirname(__DIR__, 2) . '/walletTests/bootstrap.php';
require_once dirname(__DIR__, 3) . '/src/database/AbstractRepository.php';
require_once dirname(__DIR__, 3) . '/src/database/P2pRepository.php';

class P2pRepositoryTest extends TestCase {
    private $repository;
    private $mockPdo;

    public function setUp() {
        parent::setUp();
        $this->mockPdo = $this->createMockPdo();
        $this->repository = new P2pRepository($this->mockPdo);
    }

    private function createMockPdo() {
        return new class extends PDO {
            public function __construct() {}
            public function prepare($statement, $options = []) {
                return new class {
                    public $executeResult = true;
                    public $fetchResult = false;
                    public $fetchAllResult = [];
                    public $rowCountResult = 1;
                    public function bindValue($p, $v, $t = PDO::PARAM_STR) { return true; }
                    public function execute($params = []) { return $this->executeResult; }
                    public function fetch($mode = PDO::FETCH_ASSOC) { return $this->fetchResult; }
                    public function fetchAll($mode = PDO::FETCH_ASSOC) { return $this->fetchAllResult; }
                    public function rowCount() { return $this->rowCountResult; }
                };
            }
            public function lastInsertId($name = null) { return '1'; }
        };
    }

    public function testInsertP2pRequestSuccess() {
        $request = [
            'hash' => 'p2p_hash_123',
            'salt' => 'random_salt',
            'time' => time(),
            'expiration' => time() + 3600,
            'currency' => 'USD',
            'amount' => 100,
            'requestLevel' => 3,
            'maxRequestLevel' => 5,
            'senderPublicKey' => 'sender_pub',
            'senderAddress' => 'sender_addr',
            'signature' => 'signature_data'
        ];

        $result = $this->repository->insertP2pRequest($request, 'dest_addr');
        $this->assertTrue(is_string($result), 'insertP2pRequest should return JSON string');
        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded, 'Should return valid JSON');
    }

    public function testGetByHashReturnsArrayOrNull() {
        $hash = 'test_hash_456';
        $result = $this->repository->getByHash($hash);
        $this->assertTrue(
            is_array($result) || $result === null,
            'getByHash should return array or null'
        );
    }

    public function testIsCompletedByHashReturnsBoolean() {
        $hash = 'completed_hash';
        $result = $this->repository->isCompletedByHash($hash);
        $this->assertTrue(is_bool($result), 'isCompletedByHash should return boolean');
    }

    public function testUpdateStatusSuccess() {
        $hash = 'p2p_hash';
        $status = 'sent';
        $result = $this->repository->updateStatus($hash, $status, false);
        $this->assertTrue(is_bool($result), 'updateStatus should return boolean');
    }

    public function testUpdateIncomingTxidSuccess() {
        $hash = 'p2p_hash';
        $txid = 'incoming_txid_123';
        $result = $this->repository->updateIncomingTxid($hash, $txid);
        $this->assertTrue(is_bool($result), 'updateIncomingTxid should return boolean');
    }

    public function testUpdateOutgoingTxidSuccess() {
        $hash = 'p2p_hash';
        $txid = 'outgoing_txid_456';
        $result = $this->repository->updateOutgoingTxid($hash, $txid);
        $this->assertTrue(is_bool($result), 'updateOutgoingTxid should return boolean');
    }

    public function testGetCreditInP2pReturnsFloat() {
        $address = 'test_address';
        $result = $this->repository->getCreditInP2p($address);
        $this->assertTrue(
            is_float($result) || is_int($result),
            'getCreditInP2p should return numeric value'
        );
    }

    public function testGetQueuedP2pMessagesReturnsArray() {
        $result = $this->repository->getQueuedP2pMessages('queued', 10);
        $this->assertTrue(is_array($result), 'getQueuedP2pMessages should return array');
    }

    public function testGetExpiringP2pMessagesReturnsArray() {
        $result = $this->repository->getExpiringP2pMessages(5);
        $this->assertTrue(is_array($result), 'getExpiringP2pMessages should return array');
    }

    public function testGetByStatusReturnsArray() {
        $result = $this->repository->getByStatus('queued');
        $this->assertTrue(is_array($result), 'getByStatus should return array');
    }

    public function testGetStatisticsReturnsArray() {
        $result = $this->repository->getStatistics();
        $this->assertTrue(is_array($result), 'getStatistics should return array');
    }

    public function testUpdateDestinationAddressSuccess() {
        $hash = 'p2p_hash';
        $destAddr = 'new_dest_addr';
        $result = $this->repository->updateDestinationAddress($hash, $destAddr);
        $this->assertTrue(is_bool($result), 'updateDestinationAddress should return boolean');
    }

    public function testUpdateFeeAmountSuccess() {
        $hash = 'p2p_hash';
        $feeAmount = 2.5;
        $result = $this->repository->updateFeeAmount($hash, $feeAmount);
        $this->assertTrue(is_bool($result), 'updateFeeAmount should return boolean');
    }

    public function testDeleteOldExpiredReturnsInt() {
        $result = $this->repository->deleteOldExpired(30);
        $this->assertTrue(
            is_int($result) && $result >= 0,
            'deleteOldExpired should return non-negative integer'
        );
    }
}

// Run tests
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new P2pRepositoryTest();
    echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║           P2pRepository Unit Tests                               ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";

    $tests = [
        'Insert P2P request' => 'testInsertP2pRequestSuccess',
        'Get by hash' => 'testGetByHashReturnsArrayOrNull',
        'Is completed by hash' => 'testIsCompletedByHashReturnsBoolean',
        'Update status' => 'testUpdateStatusSuccess',
        'Update incoming txid' => 'testUpdateIncomingTxidSuccess',
        'Update outgoing txid' => 'testUpdateOutgoingTxidSuccess',
        'Get credit in P2P' => 'testGetCreditInP2pReturnsFloat',
        'Get queued messages' => 'testGetQueuedP2pMessagesReturnsArray',
        'Get expiring messages' => 'testGetExpiringP2pMessagesReturnsArray',
        'Get by status' => 'testGetByStatusReturnsArray',
        'Get statistics' => 'testGetStatisticsReturnsArray',
        'Update destination address' => 'testUpdateDestinationAddressSuccess',
        'Update fee amount' => 'testUpdateFeeAmountSuccess',
        'Delete old expired' => 'testDeleteOldExpiredReturnsInt',
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
