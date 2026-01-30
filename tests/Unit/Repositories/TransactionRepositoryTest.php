<?php
/**
 * Unit Tests for TransactionRepository
 *
 * Tests transaction repository constants and configuration.
 * Note: Full database tests require integration testing with Docker.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\TransactionRepository;
use Eiou\Core\Constants;

#[CoversClass(TransactionRepository::class)]
class TransactionRepositoryTest extends TestCase
{
    /**
     * Test transaction status constants are defined
     */
    public function testTransactionStatusConstantsAreDefined(): void
    {
        $this->assertEquals('pending', Constants::STATUS_PENDING);
        $this->assertEquals('completed', Constants::STATUS_COMPLETED);
        $this->assertEquals('accepted', Constants::STATUS_ACCEPTED);
        $this->assertEquals('rejected', Constants::STATUS_REJECTED);
    }

    /**
     * Test transaction type constants
     */
    public function testTransactionTypeConstants(): void
    {
        $this->assertEquals('sent', Constants::TX_TYPE_SENT);
        $this->assertEquals('received', Constants::TX_TYPE_RECEIVED);
    }

    /**
     * Test hash length constant for txid validation
     */
    public function testHashLengthConstant(): void
    {
        // SHA-256 produces 64 character hex string
        $this->assertEquals(64, Constants::VALIDATION_HASH_LENGTH_SHA256);
    }

    /**
     * Test transaction max amount constant
     */
    public function testTransactionMaxAmountConstant(): void
    {
        $this->assertIsInt(Constants::TRANSACTION_MAX_AMOUNT);
        $this->assertGreaterThan(0, Constants::TRANSACTION_MAX_AMOUNT);
        // Should be large enough for practical use
        $this->assertGreaterThanOrEqual(1000000, Constants::TRANSACTION_MAX_AMOUNT);
    }

    /**
     * Test display date format constant
     */
    public function testDisplayDateFormatConstant(): void
    {
        $this->assertNotEmpty(Constants::DISPLAY_DATE_FORMAT);

        // Verify it's a valid date format by testing it
        $testDate = date(Constants::DISPLAY_DATE_FORMAT);
        $this->assertNotEmpty($testDate);
    }
}
