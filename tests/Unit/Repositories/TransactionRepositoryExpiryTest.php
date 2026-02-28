<?php
/**
 * Unit Tests for TransactionRepository — Expiry / DLQ Lifecycle Methods
 *
 * Tests the new methods added for per-transaction delivery deadline support:
 * - cancelPendingByMemo()
 * - getExpiredTransactions()
 * - setExpiresAt()
 *
 * Note: Full database interaction tests require integration testing with Docker.
 * These unit tests verify schema constants, method signatures, and behavior using mocks.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\TransactionRepository;
use Eiou\Core\Constants;

#[CoversClass(TransactionRepository::class)]
class TransactionRepositoryExpiryTest extends TestCase
{
    // =========================================================================
    // Constants
    // =========================================================================

    /**
     * DIRECT_TX_DELIVERY_EXPIRATION_SECONDS constant is defined
     */
    public function testDirectTxDeliveryExpirationConstantIsDefined(): void
    {
        $this->assertSame(
            60,
            Constants::DIRECT_TX_DELIVERY_EXPIRATION_SECONDS,
            'Default delivery window should be 60s (one Tor round-trip)'
        );
    }

    /**
     * DIRECT_TX_DELIVERY_EXPIRATION_SECONDS is exactly twice TOR_TRANSPORT_TIMEOUT_SECONDS
     */
    public function testDirectTxExpirationIsDoubleTorTimeout(): void
    {
        $this->assertSame(
            2 * Constants::TOR_TRANSPORT_TIMEOUT_SECONDS,
            Constants::DIRECT_TX_DELIVERY_EXPIRATION_SECONDS
        );
    }

    /**
     * STATUS_CANCELLED and STATUS_SENDING constants are defined (used in expiry flows)
     */
    public function testRequiredStatusConstantsAreDefined(): void
    {
        $this->assertSame('cancelled', Constants::STATUS_CANCELLED);
        $this->assertSame('sending',   Constants::STATUS_SENDING);
        $this->assertSame('pending',   Constants::STATUS_PENDING);
    }

    // =========================================================================
    // Method existence (signature tests via Reflection)
    // =========================================================================

    /**
     * cancelPendingByMemo() exists and accepts a string memo parameter
     */
    public function testCancelPendingByMemoMethodExists(): void
    {
        $reflection = new \ReflectionClass(TransactionRepository::class);
        $this->assertTrue($reflection->hasMethod('cancelPendingByMemo'));

        $method = $reflection->getMethod('cancelPendingByMemo');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('memo', $params[0]->getName());
    }

    /**
     * getExpiredTransactions() exists and takes no parameters
     */
    public function testGetExpiredTransactionsMethodExists(): void
    {
        $reflection = new \ReflectionClass(TransactionRepository::class);
        $this->assertTrue($reflection->hasMethod('getExpiredTransactions'));

        $method = $reflection->getMethod('getExpiredTransactions');
        $this->assertCount(0, $method->getParameters());
    }

    /**
     * setExpiresAt() exists and accepts txid and expiresAt string parameters
     */
    public function testSetExpiresAtMethodExists(): void
    {
        $reflection = new \ReflectionClass(TransactionRepository::class);
        $this->assertTrue($reflection->hasMethod('setExpiresAt'));

        $method = $reflection->getMethod('setExpiresAt');
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('txid',      $params[0]->getName());
        $this->assertSame('expiresAt', $params[1]->getName());
    }

    // =========================================================================
    // expires_at datetime format helper
    // =========================================================================

    /**
     * A future expires_at value computed from DIRECT_TX_DELIVERY_EXPIRATION_SECONDS
     * produces a valid MySQL DATETIME(6) string
     */
    public function testExpiresAtDatetimeFormatIsValid(): void
    {
        $expiresAt = date('Y-m-d H:i:s', time() + Constants::DIRECT_TX_DELIVERY_EXPIRATION_SECONDS);

        // Must match MySQL DATETIME format: YYYY-MM-DD HH:MM:SS
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expiresAt);

        // Must be in the future
        $this->assertGreaterThan(time(), strtotime($expiresAt));
    }

    /**
     * expires_at value is DIRECT_TX_DELIVERY_EXPIRATION_SECONDS seconds in the future
     */
    public function testExpiresAtIsExpectedSecondsInFuture(): void
    {
        $before    = time();
        $expiresAt = date('Y-m-d H:i:s', time() + Constants::DIRECT_TX_DELIVERY_EXPIRATION_SECONDS);
        $after     = time();

        $expiresTs = strtotime($expiresAt);
        $this->assertGreaterThanOrEqual($before + Constants::DIRECT_TX_DELIVERY_EXPIRATION_SECONDS, $expiresTs);
        $this->assertLessThanOrEqual($after  + Constants::DIRECT_TX_DELIVERY_EXPIRATION_SECONDS, $expiresTs);
    }
}
