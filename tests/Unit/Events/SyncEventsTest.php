<?php
/**
 * Unit Tests for SyncEvents
 *
 * Tests sync event constants and naming conventions.
 */

namespace Eiou\Tests\Events;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Events\SyncEvents;
use ReflectionClass;

#[CoversClass(SyncEvents::class)]
class SyncEventsTest extends TestCase
{
    /**
     * Test SYNC_COMPLETED constant has expected value
     */
    public function testSyncCompletedConstantHasExpectedValue(): void
    {
        $this->assertEquals('sync.completed', SyncEvents::SYNC_COMPLETED);
    }

    /**
     * Test SYNC_FAILED constant has expected value
     */
    public function testSyncFailedConstantHasExpectedValue(): void
    {
        $this->assertEquals('sync.failed', SyncEvents::SYNC_FAILED);
    }

    /**
     * Test CHAIN_GAP_DETECTED constant has expected value
     */
    public function testChainGapDetectedConstantHasExpectedValue(): void
    {
        $this->assertEquals('sync.chain_gap_detected', SyncEvents::CHAIN_GAP_DETECTED);
    }

    /**
     * Test CONTACT_SYNCED constant has expected value
     */
    public function testContactSyncedConstantHasExpectedValue(): void
    {
        $this->assertEquals('sync.contact_synced', SyncEvents::CONTACT_SYNCED);
    }

    /**
     * Test BALANCE_SYNCED constant has expected value
     */
    public function testBalanceSyncedConstantHasExpectedValue(): void
    {
        $this->assertEquals('sync.balance_synced', SyncEvents::BALANCE_SYNCED);
    }

    /**
     * Test CHAIN_CONFLICT_RESOLVED constant has expected value
     */
    public function testChainConflictResolvedConstantHasExpectedValue(): void
    {
        $this->assertEquals('sync.chain_conflict_resolved', SyncEvents::CHAIN_CONFLICT_RESOLVED);
    }

    /**
     * Test BIDIRECTIONAL_SYNC_STARTED constant has expected value
     */
    public function testBidirectionalSyncStartedConstantHasExpectedValue(): void
    {
        $this->assertEquals('sync.bidirectional_started', SyncEvents::BIDIRECTIONAL_SYNC_STARTED);
    }

    /**
     * Test BIDIRECTIONAL_SYNC_COMPLETED constant has expected value
     */
    public function testBidirectionalSyncCompletedConstantHasExpectedValue(): void
    {
        $this->assertEquals('sync.bidirectional_completed', SyncEvents::BIDIRECTIONAL_SYNC_COMPLETED);
    }

    /**
     * Test ALL_CONTACTS_SYNCED constant has expected value
     */
    public function testAllContactsSyncedConstantHasExpectedValue(): void
    {
        $this->assertEquals('sync.all_contacts_synced', SyncEvents::ALL_CONTACTS_SYNCED);
    }

    /**
     * Test ALL_TRANSACTIONS_SYNCED constant has expected value
     */
    public function testAllTransactionsSyncedConstantHasExpectedValue(): void
    {
        $this->assertEquals('sync.all_transactions_synced', SyncEvents::ALL_TRANSACTIONS_SYNCED);
    }

    /**
     * Test ALL_BALANCES_SYNCED constant has expected value
     */
    public function testAllBalancesSyncedConstantHasExpectedValue(): void
    {
        $this->assertEquals('sync.all_balances_synced', SyncEvents::ALL_BALANCES_SYNCED);
    }

    /**
     * Test all constants follow 'sync.*' naming convention
     */
    public function testAllConstantsFollowSyncNamingConvention(): void
    {
        $reflection = new ReflectionClass(SyncEvents::class);
        $constants = $reflection->getConstants();

        foreach ($constants as $name => $value) {
            $this->assertStringStartsWith(
                'sync.',
                $value,
                "Constant $name does not follow 'sync.*' naming convention"
            );
        }
    }

    /**
     * Test all constants are string type
     */
    public function testAllConstantsAreStringType(): void
    {
        $reflection = new ReflectionClass(SyncEvents::class);
        $constants = $reflection->getConstants();

        foreach ($constants as $name => $value) {
            $this->assertIsString(
                $value,
                "Constant $name should be a string"
            );
        }
    }

    /**
     * Test all expected constants are defined
     */
    public function testAllExpectedConstantsAreDefined(): void
    {
        $expectedConstants = [
            'SYNC_COMPLETED',
            'SYNC_FAILED',
            'CHAIN_GAP_DETECTED',
            'CONTACT_SYNCED',
            'BALANCE_SYNCED',
            'CHAIN_CONFLICT_RESOLVED',
            'BIDIRECTIONAL_SYNC_STARTED',
            'BIDIRECTIONAL_SYNC_COMPLETED',
            'ALL_CONTACTS_SYNCED',
            'ALL_TRANSACTIONS_SYNCED',
            'ALL_BALANCES_SYNCED'
        ];

        $reflection = new ReflectionClass(SyncEvents::class);
        $actualConstants = array_keys($reflection->getConstants());

        foreach ($expectedConstants as $expected) {
            $this->assertContains(
                $expected,
                $actualConstants,
                "Expected constant $expected is not defined"
            );
        }
    }

    /**
     * Test reflection returns correct number of constants
     */
    public function testReflectionReturnsCorrectNumberOfConstants(): void
    {
        $reflection = new ReflectionClass(SyncEvents::class);
        $constants = $reflection->getConstants();

        $this->assertCount(
            11,
            $constants,
            'SyncEvents should define exactly 11 constants'
        );
    }

    /**
     * Test all public constants are accessible via reflection
     */
    public function testAllPublicConstantsAreAccessibleViaReflection(): void
    {
        $reflection = new ReflectionClass(SyncEvents::class);
        $constants = $reflection->getReflectionConstants();

        foreach ($constants as $constant) {
            $this->assertTrue(
                $constant->isPublic(),
                "Constant {$constant->getName()} should be public"
            );
        }
    }

    /**
     * Test constant values are unique
     */
    public function testConstantValuesAreUnique(): void
    {
        $reflection = new ReflectionClass(SyncEvents::class);
        $constants = $reflection->getConstants();
        $values = array_values($constants);
        $uniqueValues = array_unique($values);

        $this->assertCount(
            count($values),
            $uniqueValues,
            'All constant values should be unique'
        );
    }

    /**
     * Test constant values use lowercase with underscores
     */
    public function testConstantValuesUseLowercaseWithUnderscores(): void
    {
        $reflection = new ReflectionClass(SyncEvents::class);
        $constants = $reflection->getConstants();

        foreach ($constants as $name => $value) {
            $this->assertMatchesRegularExpression(
                '/^sync\.[a-z_]+$/',
                $value,
                "Constant $name value should use lowercase with underscores"
            );
        }
    }

    /**
     * Test constants can be used as event names
     */
    public function testConstantsCanBeUsedAsEventNames(): void
    {
        // Verify constants are non-empty strings suitable for event names
        $this->assertNotEmpty(SyncEvents::SYNC_COMPLETED);
        $this->assertNotEmpty(SyncEvents::SYNC_FAILED);
        $this->assertNotEmpty(SyncEvents::CHAIN_GAP_DETECTED);
        $this->assertNotEmpty(SyncEvents::CONTACT_SYNCED);
        $this->assertNotEmpty(SyncEvents::BALANCE_SYNCED);
        $this->assertNotEmpty(SyncEvents::CHAIN_CONFLICT_RESOLVED);
        $this->assertNotEmpty(SyncEvents::BIDIRECTIONAL_SYNC_STARTED);
        $this->assertNotEmpty(SyncEvents::BIDIRECTIONAL_SYNC_COMPLETED);
        $this->assertNotEmpty(SyncEvents::ALL_CONTACTS_SYNCED);
        $this->assertNotEmpty(SyncEvents::ALL_TRANSACTIONS_SYNCED);
        $this->assertNotEmpty(SyncEvents::ALL_BALANCES_SYNCED);
    }
}
