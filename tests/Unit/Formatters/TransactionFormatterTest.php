<?php
/**
 * Unit Tests for TransactionFormatter
 *
 * Tests transaction data formatting for API responses.
 */

namespace Eiou\Tests\Formatters;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Formatters\TransactionFormatter;
use Eiou\Core\Constants;

#[CoversClass(TransactionFormatter::class)]
class TransactionFormatterTest extends TestCase
{
    /**
     * Test convertAmount with valid minor units
     */
    public function testConvertAmountWithValidMinorUnits(): void
    {
        $this->assertEquals(1.00, TransactionFormatter::convertAmount(100));
        $this->assertEquals(10.50, TransactionFormatter::convertAmount(1050));
        $this->assertEquals(0.01, TransactionFormatter::convertAmount(1));
    }

    /**
     * Test convertAmount with null returns null
     */
    public function testConvertAmountWithNullReturnsNull(): void
    {
        $this->assertNull(TransactionFormatter::convertAmount(null));
    }

    /**
     * Test convertAmount with zero
     */
    public function testConvertAmountWithZero(): void
    {
        $this->assertEquals(0.00, TransactionFormatter::convertAmount(0));
    }

    /**
     * Test formatSimple creates correct structure
     */
    public function testFormatSimpleCreatesCorrectStructure(): void
    {
        $tx = [
            'timestamp' => '2025-01-30 10:00:00',
            'amount' => 1000,
            'currency' => 'USD'
        ];

        $result = TransactionFormatter::formatSimple($tx, Constants::TX_TYPE_SENT, 'http://recipient.example');

        $this->assertArrayHasKey('date', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertArrayHasKey('counterparty', $result);

        $this->assertEquals('2025-01-30 10:00:00', $result['date']);
        $this->assertEquals(Constants::TX_TYPE_SENT, $result['type']);
        $this->assertEquals(10.00, $result['amount']);
        $this->assertEquals('USD', $result['currency']);
        $this->assertEquals('http://recipient.example', $result['counterparty']);
    }

    /**
     * Test formatSimple with received type
     */
    public function testFormatSimpleWithReceivedType(): void
    {
        $tx = [
            'timestamp' => '2025-01-30 10:00:00',
            'amount' => 500,
            'currency' => 'USD'
        ];

        $result = TransactionFormatter::formatSimple($tx, Constants::TX_TYPE_RECEIVED, 'http://sender.example');

        $this->assertEquals(Constants::TX_TYPE_RECEIVED, $result['type']);
        $this->assertEquals(5.00, $result['amount']);
    }

    /**
     * Test formatSimpleMany formats multiple transactions
     */
    public function testFormatSimpleManyFormatsMultipleTransactions(): void
    {
        $transactions = [
            ['timestamp' => '2025-01-30 10:00:00', 'amount' => 100, 'currency' => 'USD', 'receiver_address' => 'addr1'],
            ['timestamp' => '2025-01-30 11:00:00', 'amount' => 200, 'currency' => 'USD', 'receiver_address' => 'addr2'],
        ];

        $result = TransactionFormatter::formatSimpleMany($transactions, Constants::TX_TYPE_SENT, 'receiver_address');

        $this->assertCount(2, $result);
        $this->assertEquals('addr1', $result[0]['counterparty']);
        $this->assertEquals('addr2', $result[1]['counterparty']);
    }

    /**
     * Test formatSimpleMany with empty array
     */
    public function testFormatSimpleManyWithEmptyArray(): void
    {
        $result = TransactionFormatter::formatSimpleMany([], Constants::TX_TYPE_SENT, 'receiver_address');

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test formatHistory creates full structure
     */
    public function testFormatHistoryCreatesFullStructure(): void
    {
        $tx = [
            'id' => 1,
            'txid' => 'abc123',
            'tx_type' => 'standard',
            'direction' => 'outgoing',
            'status' => Constants::STATUS_COMPLETED,
            'timestamp' => '2025-01-30 10:00:00',
            'amount' => 1000,
            'currency' => 'USD',
            'sender_address' => 'http://sender.example',
            'receiver_address' => 'http://receiver.example',
            'sender_name' => 'Sender Name',
            'receiver_name' => 'Receiver Name',
            'sender_public_key' => 'pubkey1',
            'receiver_public_key' => 'pubkey2',
            'memo' => 'Test memo',
            'description' => 'Test description',
            'previous_txid' => null,
            'end_recipient_address' => null,
            'initial_sender_address' => null,
            'p2p_destination' => null,
            'p2p_amount' => null,
            'p2p_fee' => null
        ];

        $userAddresses = ['http://sender.example'];

        $result = TransactionFormatter::formatHistory($tx, $userAddresses);

        // Check all expected keys
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('txid', $result);
        $this->assertArrayHasKey('tx_type', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('counterparty', $result);
        $this->assertArrayHasKey('counterparty_address', $result);
        $this->assertArrayHasKey('counterparty_name', $result);

        // Verify direction detection
        $this->assertEquals(Constants::TX_TYPE_SENT, $result['type']);
        $this->assertEquals('http://receiver.example', $result['counterparty_address']);
        $this->assertEquals('Receiver Name', $result['counterparty_name']);
    }

    /**
     * Test formatHistory detects received transaction
     */
    public function testFormatHistoryDetectsReceivedTransaction(): void
    {
        $tx = [
            'id' => 1,
            'txid' => 'abc123',
            'tx_type' => 'standard',
            'status' => Constants::STATUS_COMPLETED,
            'timestamp' => '2025-01-30 10:00:00',
            'amount' => 1000,
            'currency' => 'USD',
            'sender_address' => 'http://other.example',
            'receiver_address' => 'http://myaddress.example',
            'sender_name' => 'Sender Name',
            'receiver_name' => 'My Name'
        ];

        $userAddresses = ['http://myaddress.example'];

        $result = TransactionFormatter::formatHistory($tx, $userAddresses);

        $this->assertEquals(Constants::TX_TYPE_RECEIVED, $result['type']);
        $this->assertEquals('http://other.example', $result['counterparty_address']);
        $this->assertEquals('Sender Name', $result['counterparty_name']);
    }

    /**
     * Test formatHistory counterparty display with name
     */
    public function testFormatHistoryCounterpartyDisplayWithName(): void
    {
        $tx = [
            'id' => 1,
            'txid' => 'abc123',
            'tx_type' => 'standard',
            'status' => Constants::STATUS_COMPLETED,
            'timestamp' => '2025-01-30 10:00:00',
            'amount' => 1000,
            'currency' => 'USD',
            'sender_address' => 'http://sender.example',
            'receiver_address' => 'http://receiver.example',
            'receiver_name' => 'Bob'
        ];

        $result = TransactionFormatter::formatHistory($tx, ['http://sender.example']);

        // Should be "Name (address)" format
        $this->assertStringContainsString('Bob', $result['counterparty']);
        $this->assertStringContainsString('http://receiver.example', $result['counterparty']);
    }

    /**
     * Test formatHistory counterparty display without name
     */
    public function testFormatHistoryCounterpartyDisplayWithoutName(): void
    {
        $tx = [
            'id' => 1,
            'txid' => 'abc123',
            'tx_type' => 'standard',
            'status' => Constants::STATUS_COMPLETED,
            'timestamp' => '2025-01-30 10:00:00',
            'amount' => 1000,
            'currency' => 'USD',
            'sender_address' => 'http://sender.example',
            'receiver_address' => 'http://receiver.example',
            'receiver_name' => null
        ];

        $result = TransactionFormatter::formatHistory($tx, ['http://sender.example']);

        // Should be just the address
        $this->assertEquals('http://receiver.example', $result['counterparty']);
    }

    /**
     * Test formatHistoryMany formats multiple transactions
     */
    public function testFormatHistoryManyFormatsMultipleTransactions(): void
    {
        $transactions = [
            [
                'id' => 1, 'txid' => 'tx1', 'tx_type' => 'standard', 'status' => Constants::STATUS_COMPLETED,
                'timestamp' => '2025-01-30 10:00:00', 'amount' => 100, 'currency' => 'USD',
                'sender_address' => 'http://me.example', 'receiver_address' => 'http://other.example'
            ],
            [
                'id' => 2, 'txid' => 'tx2', 'tx_type' => 'standard', 'status' => Constants::STATUS_COMPLETED,
                'timestamp' => '2025-01-30 11:00:00', 'amount' => 200, 'currency' => 'USD',
                'sender_address' => 'http://other.example', 'receiver_address' => 'http://me.example'
            ],
        ];

        $result = TransactionFormatter::formatHistoryMany($transactions, ['http://me.example']);

        $this->assertCount(2, $result);
        $this->assertEquals(Constants::TX_TYPE_SENT, $result[0]['type']);
        $this->assertEquals(Constants::TX_TYPE_RECEIVED, $result[1]['type']);
    }

    /**
     * Test formatContact creates correct structure
     */
    public function testFormatContactCreatesCorrectStructure(): void
    {
        $tx = [
            'txid' => 'abc123',
            'tx_type' => 'standard',
            'status' => Constants::STATUS_COMPLETED,
            'timestamp' => '2025-01-30 10:00:00',
            'amount' => 500,
            'currency' => 'USD',
            'sender_address' => 'http://me.example',
            'receiver_address' => 'http://contact.example',
            'memo' => 'Test memo',
            'description' => 'Test description'
        ];

        $result = TransactionFormatter::formatContact($tx, ['http://me.example']);

        $this->assertArrayHasKey('txid', $result);
        $this->assertArrayHasKey('tx_type', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('date', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('memo', $result);

        $this->assertEquals(Constants::TX_TYPE_SENT, $result['type']);
        $this->assertEquals(5.00, $result['amount']);
    }

    /**
     * Test formatContactMany formats multiple transactions
     */
    public function testFormatContactManyFormatsMultipleTransactions(): void
    {
        $transactions = [
            [
                'txid' => 'tx1', 'tx_type' => 'standard', 'status' => Constants::STATUS_COMPLETED,
                'timestamp' => '2025-01-30 10:00:00', 'amount' => 100, 'currency' => 'USD',
                'sender_address' => 'http://me.example', 'receiver_address' => 'http://contact.example'
            ],
            [
                'txid' => 'tx2', 'tx_type' => 'standard', 'status' => Constants::STATUS_COMPLETED,
                'timestamp' => '2025-01-30 11:00:00', 'amount' => 200, 'currency' => 'USD',
                'sender_address' => 'http://contact.example', 'receiver_address' => 'http://me.example'
            ],
        ];

        $result = TransactionFormatter::formatContactMany($transactions, ['http://me.example']);

        $this->assertCount(2, $result);
    }
}
