<?php
namespace Eiou\Tests\Services\Plugins;

use Eiou\Services\Plugins\PluginPermissionCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PluginPermissionCatalog::class)]
class PluginPermissionCatalogTest extends TestCase
{
    #[Test]
    public function knownReturnsTrueForCatalogedKey(): void
    {
        $this->assertTrue(PluginPermissionCatalog::isKnown('contact_address_book_enumerate'));
    }

    #[Test]
    public function knownReturnsFalseForUnknownKey(): void
    {
        $this->assertFalse(PluginPermissionCatalog::isKnown('not_in_catalog'));
    }

    #[Test]
    public function getReturnsLabelAndDescriptionForKnownKey(): void
    {
        $entry = PluginPermissionCatalog::get('contact_address_book_enumerate');
        $this->assertIsArray($entry);
        $this->assertNotSame('', $entry['label']);
        $this->assertNotSame('', $entry['description']);
    }

    #[Test]
    public function getReturnsNullForUnknownKey(): void
    {
        $this->assertNull(PluginPermissionCatalog::get('not_in_catalog'));
    }

    #[Test]
    public function describeReturnsRowsForKnownKeysOnly(): void
    {
        $rows = PluginPermissionCatalog::describe([
            'contact_address_book_enumerate',
            'not_a_real_key',
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame('contact_address_book_enumerate', $rows[0]['key']);
        $this->assertArrayHasKey('label', $rows[0]);
        $this->assertArrayHasKey('description', $rows[0]);
    }

    #[Test]
    public function describeReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame([], PluginPermissionCatalog::describe([]));
    }

    #[Test]
    public function describeIgnoresNonStringEntries(): void
    {
        // PluginLoader filters defensively but the catalog must be
        // self-defending too — a row that drifted through filtering
        // should not crash the GUI list endpoint.
        $rows = PluginPermissionCatalog::describe([
            'contact_address_book_enumerate',
            42,
            null,
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame('contact_address_book_enumerate', $rows[0]['key']);
    }

    #[Test]
    public function knownKeysIncludesAllCatalogedEntries(): void
    {
        $keys = PluginPermissionCatalog::knownKeys();
        $expected = [
            'contact_address_book_enumerate',
            'transaction_history_enumerate',
            'wallet_balance_read',
            'wallet_outbound_send',
            'transaction_history_aggregate',
            'contact_pending_enumerate',
            'contact_credit_read',
            'payment_request_enumerate',
            'payback_method_read_own',
            'payback_method_read_contact',
            'plugin_inventory_read',
        ];
        foreach ($expected as $key) {
            $this->assertContains($key, $keys, "catalog missing expected key: {$key}");
        }
        // Every reported key must round-trip through isKnown — guards
        // against describe() and isKnown() drifting apart.
        foreach ($keys as $key) {
            $this->assertTrue(PluginPermissionCatalog::isKnown($key));
        }
    }

    #[Test]
    public function eachCatalogedEntryHasNonEmptyLabelAndDescription(): void
    {
        // Defence against a future catalog addition that forgets to
        // fill in the operator-facing copy — without these, the GUI
        // would render an empty Permissions row.
        foreach (PluginPermissionCatalog::knownKeys() as $key) {
            $entry = PluginPermissionCatalog::get($key);
            $this->assertNotSame('', $entry['label'] ?? '', "{$key} must have a non-empty label");
            $this->assertNotSame('', $entry['description'] ?? '', "{$key} must have a non-empty description");
        }
    }
}
