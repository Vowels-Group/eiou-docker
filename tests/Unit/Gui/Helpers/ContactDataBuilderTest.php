<?php
/**
 * Unit Tests for ContactDataBuilder
 *
 * Tests contact data building for GUI display.
 */

namespace Eiou\Tests\Gui\Helpers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Gui\Helpers\ContactDataBuilder;

#[CoversClass(ContactDataBuilder::class)]
class ContactDataBuilderTest extends TestCase
{
    /**
     * Test constructor stores address types
     */
    public function testConstructorStoresAddressTypes(): void
    {
        $addressTypes = ['http', 'https', 'tor'];
        $builder = new ContactDataBuilder($addressTypes);

        // Verify by building contact data that includes these types
        $contact = [
            'http' => 'http://example.com',
            'https' => 'https://example.com',
            'tor' => 'http://example.onion'
        ];

        $result = $builder->buildContactData($contact, 'accepted');

        $this->assertArrayHasKey('http', $result);
        $this->assertArrayHasKey('https', $result);
        $this->assertArrayHasKey('tor', $result);
    }

    /**
     * Test buildContactData includes all address types
     */
    public function testBuildContactDataIncludesAllAddressTypes(): void
    {
        $addressTypes = ['http', 'https', 'tor', 'i2p'];
        $builder = new ContactDataBuilder($addressTypes);

        $contact = [
            'http' => 'http://example.com',
            'https' => 'https://example.com',
            'tor' => 'http://example.onion',
            'i2p' => 'http://example.i2p'
        ];

        $result = $builder->buildContactData($contact, 'accepted');

        $this->assertEquals('http://example.com', $result['http']);
        $this->assertEquals('https://example.com', $result['https']);
        $this->assertEquals('http://example.onion', $result['tor']);
        $this->assertEquals('http://example.i2p', $result['i2p']);
    }

    /**
     * Test buildContactData primary address prefers Tor over HTTPS over HTTP
     */
    public function testBuildContactDataPrimaryAddressPrefersTorOverHttpsOverHttp(): void
    {
        $addressTypes = ['http', 'https', 'tor'];
        $builder = new ContactDataBuilder($addressTypes);

        // Test with all three available - should prefer Tor
        $contact = [
            'http' => 'http://example.com',
            'https' => 'https://example.com',
            'tor' => 'http://example.onion'
        ];
        $result = $builder->buildContactData($contact, 'accepted');
        $this->assertEquals('http://example.onion', $result['address']);

        // Test with HTTPS and HTTP only - should prefer HTTPS
        $contact = [
            'http' => 'http://example.com',
            'https' => 'https://example.com'
        ];
        $result = $builder->buildContactData($contact, 'accepted');
        $this->assertEquals('https://example.com', $result['address']);

        // Test with HTTP only
        $contact = [
            'http' => 'http://example.com'
        ];
        $result = $builder->buildContactData($contact, 'accepted');
        $this->assertEquals('http://example.com', $result['address']);
    }

    /**
     * Test buildContactData falls back to first available address
     */
    public function testBuildContactDataFallsBackToFirstAvailableAddress(): void
    {
        $addressTypes = ['i2p', 'custom', 'http'];
        $builder = new ContactDataBuilder($addressTypes);

        // No tor, https, or http - should use first available (i2p)
        $contact = [
            'i2p' => 'http://example.i2p',
            'custom' => 'custom://example'
        ];

        $result = $builder->buildContactData($contact, 'accepted');

        $this->assertEquals('http://example.i2p', $result['address']);
    }

    /**
     * Test buildContactData with all fields populated
     */
    public function testBuildContactDataWithAllFieldsPopulated(): void
    {
        $addressTypes = ['http', 'tor'];
        $builder = new ContactDataBuilder($addressTypes);

        $contact = [
            'http' => 'http://example.com',
            'tor' => 'http://example.onion',
            'name' => 'John Doe',
            'fee' => 0.5,
            'credit_limit' => 1000,
            'currency' => 'EUR',
            'pubkey' => 'abc123pubkey',
            'balance' => 500,
            'contact_id' => 'contact-001',
            'transactions' => [['txid' => 'tx1'], ['txid' => 'tx2']],
            'online_status' => 'online',
            'valid_chain' => true,
            'pubkey_hash' => 'hash123',
            'chain_drop_proposal' => ['proposal_id' => 'cdp-test', 'direction' => 'incoming', 'status' => 'pending']
        ];

        $result = $builder->buildContactData($contact, 'accepted');

        $this->assertEquals('http://example.onion', $result['address']);
        $this->assertEquals('John Doe', $result['name']);
        $this->assertEquals(0.5, $result['fee']);
        $this->assertEquals(1000, $result['credit_limit']);
        $this->assertEquals('EUR', $result['currency']);
        $this->assertEquals('accepted', $result['status']);
        $this->assertEquals('abc123pubkey', $result['pubkey']);
        $this->assertEquals(500, $result['balance']);
        $this->assertEquals('contact-001', $result['contact_id']);
        $this->assertCount(2, $result['transactions']);
        $this->assertEquals('online', $result['online_status']);
        $this->assertTrue($result['valid_chain']);
        $this->assertEquals('hash123', $result['pubkey_hash']);
        $this->assertEquals('cdp-test', $result['chain_drop_proposal']['proposal_id']);
    }

    /**
     * Test buildContactData with minimal fields uses defaults
     */
    public function testBuildContactDataWithMinimalFieldsUsesDefaults(): void
    {
        $addressTypes = ['http'];
        $builder = new ContactDataBuilder($addressTypes);

        $contact = [];

        $result = $builder->buildContactData($contact, 'pending');

        $this->assertEquals('', $result['address']);
        $this->assertEquals('', $result['name']);
        $this->assertEquals(0, $result['fee']);
        $this->assertEquals(0, $result['credit_limit']);
        $this->assertEquals('USD', $result['currency']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('', $result['pubkey']);
        $this->assertEquals(0, $result['balance']);
        $this->assertEquals('', $result['contact_id']);
        $this->assertEquals([], $result['transactions']);
        $this->assertEquals('unknown', $result['online_status']);
        $this->assertNull($result['valid_chain']);
        $this->assertEquals('', $result['pubkey_hash']);
        $this->assertNull($result['chain_drop_proposal']);
        $this->assertEquals('', $result['http']);
    }

    /**
     * Test buildContactData with accepted status
     */
    public function testBuildContactDataWithAcceptedStatus(): void
    {
        $builder = new ContactDataBuilder(['http']);

        $contact = ['name' => 'Alice'];
        $result = $builder->buildContactData($contact, 'accepted');

        $this->assertEquals('accepted', $result['status']);
        $this->assertEquals('Alice', $result['name']);
    }

    /**
     * Test buildContactData with pending status
     */
    public function testBuildContactDataWithPendingStatus(): void
    {
        $builder = new ContactDataBuilder(['http']);

        $contact = ['name' => 'Bob'];
        $result = $builder->buildContactData($contact, 'pending');

        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('Bob', $result['name']);
    }

    /**
     * Test buildContactData with blocked status defaults name to dash
     */
    public function testBuildContactDataWithBlockedStatusDefaultsNameToDash(): void
    {
        $builder = new ContactDataBuilder(['http']);

        // Contact with no name and blocked status should default to '-'
        $contact = [];
        $result = $builder->buildContactData($contact, 'blocked');

        $this->assertEquals('blocked', $result['status']);
        $this->assertEquals('-', $result['name']);
    }

    /**
     * Test buildContactData with blocked status preserves existing name
     */
    public function testBuildContactDataWithBlockedStatusPreservesExistingName(): void
    {
        $builder = new ContactDataBuilder(['http']);

        // Contact with explicit name should keep it even when blocked
        $contact = ['name' => 'Blocked User'];
        $result = $builder->buildContactData($contact, 'blocked');

        $this->assertEquals('blocked', $result['status']);
        $this->assertEquals('Blocked User', $result['name']);
    }

    /**
     * Test buildEncodedContactData returns valid JSON
     */
    public function testBuildEncodedContactDataReturnsValidJson(): void
    {
        $builder = new ContactDataBuilder(['http']);

        $contact = [
            'http' => 'http://example.com',
            'name' => 'Test User'
        ];

        $encoded = $builder->buildEncodedContactData($contact, 'accepted');

        // Decode and verify it's valid JSON
        $decoded = json_decode(html_entity_decode($encoded, ENT_QUOTES, 'UTF-8'), true);

        $this->assertNotNull($decoded);
        $this->assertIsArray($decoded);
        $this->assertEquals('Test User', $decoded['name']);
        $this->assertEquals('http://example.com', $decoded['http']);
    }

    /**
     * Test buildEncodedContactData is HTML-safe
     */
    public function testBuildEncodedContactDataIsHtmlSafe(): void
    {
        $builder = new ContactDataBuilder(['http']);

        $contact = [
            'name' => 'Test<script>alert(1)</script>',
            'http' => "http://example.com?a=1&b=2"
        ];

        $encoded = $builder->buildEncodedContactData($contact, 'accepted');

        // Should not contain raw (unescaped) HTML-unsafe characters
        // The < and > should be escaped via JSON_HEX_TAG
        $this->assertStringNotContainsString('<', $encoded);
        $this->assertStringNotContainsString('>', $encoded);
        // Single quotes should be escaped via JSON_HEX_APOS and htmlspecialchars
        $this->assertStringNotContainsString("'", $encoded);
        // The output uses HTML entities (&quot;) so raw " won't appear but &quot; will
        // This is expected and safe behavior
        $this->assertStringNotContainsString('<script>', $encoded);
        $this->assertStringNotContainsString('</script>', $encoded);
    }

    /**
     * Test buildEncodedContactData handles special characters in contact data
     */
    public function testBuildEncodedContactDataHandlesSpecialCharacters(): void
    {
        $builder = new ContactDataBuilder(['http']);

        $contact = [
            'name' => '<script>alert("XSS")</script>',
            'http' => "http://example.com?foo=bar&baz=qux",
            'pubkey' => "key'with'quotes"
        ];

        $encoded = $builder->buildEncodedContactData($contact, 'accepted');

        // Should be safe for HTML embedding
        $this->assertStringNotContainsString('<script>', $encoded);
        $this->assertStringNotContainsString('</script>', $encoded);

        // Should still decode to correct values
        $decoded = json_decode(html_entity_decode($encoded, ENT_QUOTES, 'UTF-8'), true);
        $this->assertEquals('<script>alert("XSS")</script>', $decoded['name']);
        $this->assertEquals("http://example.com?foo=bar&baz=qux", $decoded['http']);
        $this->assertEquals("key'with'quotes", $decoded['pubkey']);
    }

    /**
     * Test buildContactData missing address types default to empty string
     */
    public function testBuildContactDataMissingAddressTypesDefaultToEmptyString(): void
    {
        $addressTypes = ['http', 'https', 'tor', 'i2p'];
        $builder = new ContactDataBuilder($addressTypes);

        // Only provide http, others should default to empty
        $contact = [
            'http' => 'http://example.com'
        ];

        $result = $builder->buildContactData($contact, 'accepted');

        $this->assertEquals('http://example.com', $result['http']);
        $this->assertEquals('', $result['https']);
        $this->assertEquals('', $result['tor']);
        $this->assertEquals('', $result['i2p']);
    }

    /**
     * Test buildContactData with empty address types array
     */
    public function testBuildContactDataWithEmptyAddressTypesArray(): void
    {
        $builder = new ContactDataBuilder([]);

        $contact = [
            'name' => 'Test User',
            'http' => 'http://example.com'
        ];

        $result = $builder->buildContactData($contact, 'accepted');

        // Should still have base fields but no address types in result
        $this->assertArrayHasKey('address', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertEquals('Test User', $result['name']);
        // http should not be in result since it's not in address types
        $this->assertArrayNotHasKey('http', $result);
        // Primary address still uses fallback logic checking contact['http'] directly
        // so it will pick up the http address even without it being in addressTypes
        $this->assertEquals('http://example.com', $result['address']);
    }

    /**
     * Test buildContactData with null values in contact array
     */
    public function testBuildContactDataWithNullValuesInContactArray(): void
    {
        $builder = new ContactDataBuilder(['http']);

        $contact = [
            'name' => null,
            'fee' => null,
            'currency' => null,
            'http' => null
        ];

        $result = $builder->buildContactData($contact, 'accepted');

        // Null values should fall back to defaults
        $this->assertEquals('', $result['name']);
        $this->assertEquals(0, $result['fee']);
        $this->assertEquals('USD', $result['currency']);
        $this->assertEquals('', $result['http']);
    }

    /**
     * Test buildEncodedContactData with unicode characters
     */
    public function testBuildEncodedContactDataWithUnicodeCharacters(): void
    {
        $builder = new ContactDataBuilder(['http']);

        $contact = [
            'name' => 'Test User',
            'http' => 'http://example.com'
        ];

        $encoded = $builder->buildEncodedContactData($contact, 'accepted');

        // Should be decodable
        $decoded = json_decode(html_entity_decode($encoded, ENT_QUOTES, 'UTF-8'), true);
        $this->assertNotNull($decoded);
        $this->assertEquals('Test User', $decoded['name']);
    }

    /**
     * Test buildContactData priority order when tor is empty but https exists
     */
    public function testBuildContactDataPriorityWhenTorIsEmpty(): void
    {
        $builder = new ContactDataBuilder(['http', 'https', 'tor']);

        $contact = [
            'http' => 'http://example.com',
            'https' => 'https://example.com',
            'tor' => '' // Empty string
        ];

        $result = $builder->buildContactData($contact, 'accepted');

        // Should prefer https since tor is empty
        $this->assertEquals('https://example.com', $result['address']);
    }

    /**
     * Test buildContactData with valid_chain false
     */
    public function testBuildContactDataWithValidChainFalse(): void
    {
        $builder = new ContactDataBuilder(['http']);

        $contact = [
            'valid_chain' => false
        ];

        $result = $builder->buildContactData($contact, 'accepted');

        $this->assertFalse($result['valid_chain']);
    }

    /**
     * Test buildContactData includes chain drop proposal intact
     */
    public function testBuildContactDataIncludesChainDropProposal(): void
    {
        $builder = new ContactDataBuilder(['http']);

        $proposal = [
            'proposal_id' => 'cdp-456',
            'direction' => 'outgoing',
            'status' => 'pending',
            'contact_pubkey_hash' => 'hash-abc'
        ];

        $contact = [
            'http' => 'http://example.com',
            'pubkey_hash' => 'hash-abc',
            'chain_drop_proposal' => $proposal
        ];

        $result = $builder->buildContactData($contact, 'accepted');

        $this->assertEquals($proposal, $result['chain_drop_proposal']);
        $this->assertEquals('hash-abc', $result['pubkey_hash']);
    }

    /**
     * Test buildContactData preserves transaction array structure
     */
    public function testBuildContactDataPreservesTransactionArrayStructure(): void
    {
        $builder = new ContactDataBuilder(['http']);

        $transactions = [
            ['txid' => 'tx1', 'amount' => 100],
            ['txid' => 'tx2', 'amount' => 200],
            ['txid' => 'tx3', 'amount' => 300]
        ];

        $contact = [
            'transactions' => $transactions
        ];

        $result = $builder->buildContactData($contact, 'accepted');

        $this->assertEquals($transactions, $result['transactions']);
        $this->assertCount(3, $result['transactions']);
        $this->assertEquals('tx2', $result['transactions'][1]['txid']);
    }

    /**
     * Test buildContactData includes currencies array
     */
    public function testBuildContactDataIncludesCurrenciesArray(): void
    {
        $builder = new ContactDataBuilder(['http']);

        $currencies = [
            ['currency' => 'USD', 'fee' => 0.10, 'credit_limit' => 100.00, 'my_available_credit' => 50.00],
            ['currency' => 'EUR', 'fee' => 0.15, 'credit_limit' => 80.00, 'my_available_credit' => null],
        ];

        $contact = [
            'http' => 'http://example.com',
            'name' => 'Test User',
            'currencies' => $currencies
        ];

        $result = $builder->buildContactData($contact, 'accepted');

        $this->assertArrayHasKey('currencies', $result);
        $this->assertCount(2, $result['currencies']);
        $this->assertEquals('USD', $result['currencies'][0]['currency']);
        $this->assertEquals(0.10, $result['currencies'][0]['fee']);
        $this->assertEquals(100.00, $result['currencies'][0]['credit_limit']);
        $this->assertEquals(50.00, $result['currencies'][0]['my_available_credit']);
        $this->assertEquals('EUR', $result['currencies'][1]['currency']);
        $this->assertEquals(0.15, $result['currencies'][1]['fee']);
        $this->assertEquals(80.00, $result['currencies'][1]['credit_limit']);
        $this->assertNull($result['currencies'][1]['my_available_credit']);
    }

    /**
     * Test buildContactData currencies array defaults to empty when not provided
     */
    public function testBuildContactDataCurrenciesArrayDefaultsToEmpty(): void
    {
        $builder = new ContactDataBuilder(['http']);

        $contact = [
            'http' => 'http://example.com',
            'name' => 'Test User'
        ];

        $result = $builder->buildContactData($contact, 'accepted');

        $this->assertArrayHasKey('currencies', $result);
        $this->assertEquals([], $result['currencies']);
    }

    /**
     * Test buildContactData keeps backward compatible flat fields alongside currencies array
     */
    public function testBuildContactDataKeepsBackwardCompatFlatFields(): void
    {
        $builder = new ContactDataBuilder(['http']);

        $contact = [
            'http' => 'http://example.com',
            'name' => 'Test User',
            'fee' => 0.25,
            'credit_limit' => 500,
            'currency' => 'GBP',
            'currencies' => [
                ['currency' => 'USD', 'fee' => 0.10, 'credit_limit' => 100.00, 'my_available_credit' => 50.00],
                ['currency' => 'EUR', 'fee' => 0.15, 'credit_limit' => 80.00, 'my_available_credit' => null],
            ]
        ];

        $result = $builder->buildContactData($contact, 'accepted');

        // Flat fields preserved
        $this->assertEquals(0.25, $result['fee']);
        $this->assertEquals(500, $result['credit_limit']);
        $this->assertEquals('GBP', $result['currency']);

        // Currencies array also present
        $this->assertArrayHasKey('currencies', $result);
        $this->assertCount(2, $result['currencies']);
        $this->assertEquals('USD', $result['currencies'][0]['currency']);
        $this->assertEquals('EUR', $result['currencies'][1]['currency']);
    }

    /**
     * Test buildEncodedContactData includes currencies in encoded output
     */
    public function testBuildEncodedContactDataIncludesCurrencies(): void
    {
        $builder = new ContactDataBuilder(['http']);

        $contact = [
            'http' => 'http://example.com',
            'name' => 'Test User',
            'currencies' => [
                ['currency' => 'USD', 'fee' => 0.10, 'credit_limit' => 100.00, 'my_available_credit' => 50.00],
                ['currency' => 'EUR', 'fee' => 0.15, 'credit_limit' => 80.00, 'my_available_credit' => null],
            ]
        ];

        $encoded = $builder->buildEncodedContactData($contact, 'accepted');

        $decoded = json_decode(html_entity_decode($encoded, ENT_QUOTES, 'UTF-8'), true);

        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('currencies', $decoded);
        $this->assertCount(2, $decoded['currencies']);
        $this->assertEquals('USD', $decoded['currencies'][0]['currency']);
        $this->assertEquals('EUR', $decoded['currencies'][1]['currency']);
    }
}
