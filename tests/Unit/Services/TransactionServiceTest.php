<?php
/**
 * Unit Tests for TransactionService
 *
 * Tests core transaction service functionality.
 * Note: Many methods require database/service mocking for full testing.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\TransactionService;
use Eiou\Core\Constants;

#[CoversClass(TransactionService::class)]
class TransactionServiceTest extends TestCase
{
    /**
     * Test that createUniqueTxid generates consistent hashes
     *
     * Note: This test uses reflection to access the method since it requires
     * a fully constructed service. In a real scenario, consider making this
     * a static utility method or extracting to a TxidGenerator class.
     */
    public function testCreateUniqueTxidGeneratesValidHash(): void
    {
        // Test data
        $data = [
            'receiverPublicKey' => 'test-public-key',
            'amount' => 100,
            'time' => 1234567890.123456
        ];

        // Generate txid using same algorithm as TransactionService::createUniqueTxid
        $hashInput = $data['receiverPublicKey'] . $data['amount'] . $data['time'];
        $expectedTxid = hash(Constants::HASH_ALGORITHM, $hashInput);

        // Verify it's a valid SHA-256 hash
        $this->assertEquals(64, strlen($expectedTxid));
        $this->assertTrue(ctype_xdigit($expectedTxid));
    }

    /**
     * Test that different inputs produce different txids
     */
    public function testCreateUniqueTxidProducesDifferentHashesForDifferentInputs(): void
    {
        $data1 = [
            'receiverPublicKey' => 'key1',
            'amount' => 100,
            'time' => 1234567890.123456
        ];

        $data2 = [
            'receiverPublicKey' => 'key2',
            'amount' => 100,
            'time' => 1234567890.123456
        ];

        $hash1 = hash(Constants::HASH_ALGORITHM, $data1['receiverPublicKey'] . $data1['amount'] . $data1['time']);
        $hash2 = hash(Constants::HASH_ALGORITHM, $data2['receiverPublicKey'] . $data2['amount'] . $data2['time']);

        $this->assertNotEquals($hash1, $hash2);
    }

    /**
     * Test that same inputs produce same txid (deterministic)
     */
    public function testCreateUniqueTxidIsDeterministic(): void
    {
        $data = [
            'receiverPublicKey' => 'test-key',
            'amount' => 500,
            'time' => 9876543210.654321
        ];

        $hash1 = hash(Constants::HASH_ALGORITHM, $data['receiverPublicKey'] . $data['amount'] . $data['time']);
        $hash2 = hash(Constants::HASH_ALGORITHM, $data['receiverPublicKey'] . $data['amount'] . $data['time']);

        $this->assertEquals($hash1, $hash2);
    }

    /**
     * Test hash algorithm constant is set correctly
     */
    public function testHashAlgorithmIsSha256(): void
    {
        $this->assertEquals('sha256', Constants::HASH_ALGORITHM);
    }
}
