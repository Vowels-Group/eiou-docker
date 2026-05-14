<?php
namespace Eiou\Tests\Services\Lookup;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Contracts\PluginCallable;
use Eiou\Database\ContactRepository;
use Eiou\Services\Lookup\ContactLookupService;
use ReflectionMethod;

#[CoversClass(ContactLookupService::class)]
class ContactLookupServiceTest extends TestCase
{
    private $repo;
    private ContactLookupService $svc;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(ContactRepository::class);
        $this->svc = new ContactLookupService($this->repo);
    }

    // =========================================================================
    // getByPubkeyHash — projection contract
    // =========================================================================

    public function testGetByPubkeyHashProjectsToTheNarrowContactShape(): void
    {
        // Repository row carries extra columns (status, pubkey, contact_id, etc.)
        // that must NOT leak into the plugin's view of a contact. The service
        // projects down to exactly {name, http, https, tor, pubkey_hash}.
        $this->repo->expects($this->once())
            ->method('lookupByPubkeyHash')
            ->with('abc123')
            ->willReturn([
                'name'        => 'Alice',
                'http'        => 'http://alice.example/',
                'https'       => 'https://alice.example/',
                'tor'         => 'http://aliceabcd.onion/',
                'pubkey_hash' => 'abc123',
                // Fields below must NOT appear in the returned row.
                'pubkey'      => 'long-pubkey-secret',
                'status'      => 'accepted',
                'contact_id'  => 'cid-secret',
                'online_status' => 'online',
            ]);

        $result = $this->svc->getByPubkeyHash('abc123');

        $this->assertNotNull($result);
        $this->assertSame(
            ['name', 'http', 'https', 'tor', 'pubkey_hash'],
            array_keys($result),
            'projection keys must be exactly the documented shape'
        );
        $this->assertSame('Alice', $result['name']);
        $this->assertSame('abc123', $result['pubkey_hash']);
    }

    public function testGetByPubkeyHashReturnsNullWhenContactMissing(): void
    {
        $this->repo->method('lookupByPubkeyHash')->willReturn(null);
        $this->assertNull($this->svc->getByPubkeyHash('missing'));
    }

    public function testGetByPubkeyHashReturnsNullForEmptyInput(): void
    {
        // Defensive guard — an empty hash would short-circuit the
        // repository query but the result would always be null anyway;
        // returning null at the surface avoids a wasted DB round-trip
        // and a misleading log line.
        $this->repo->expects($this->never())->method('lookupByPubkeyHash');
        $this->assertNull($this->svc->getByPubkeyHash(''));
        $this->assertNull($this->svc->getByPubkeyHash('   '));
    }

    public function testGetByPubkeyHashLowercasesAndTrimsInput(): void
    {
        $this->repo->expects($this->once())
            ->method('lookupByPubkeyHash')
            ->with('abc123')
            ->willReturn([
                'name' => 'Alice', 'http' => null, 'https' => null,
                'tor' => null, 'pubkey_hash' => 'abc123',
            ]);

        $this->svc->getByPubkeyHash('  ABC123  ');
    }

    public function testGetByPubkeyHashSurfacesNullsForMissingFields(): void
    {
        // Repository may return a row where some transport columns are
        // null (a contact known only via Tor, for example). The
        // projection must preserve those nulls rather than dropping the
        // key — plugins rely on key presence to mean "we checked".
        $this->repo->method('lookupByPubkeyHash')->willReturn([
            'name'        => 'Bob',
            'http'        => null,
            'https'       => null,
            'tor'         => 'http://bobxyz.onion/',
            'pubkey_hash' => 'hash-b',
        ]);

        $result = $this->svc->getByPubkeyHash('hash-b');
        $this->assertNull($result['http']);
        $this->assertNull($result['https']);
        $this->assertSame('http://bobxyz.onion/', $result['tor']);
    }

    // =========================================================================
    // No bulk-enumerate surface
    //
    // An earlier shape of the service exposed listAccepted() as a paginated
    // walk of the address book. That was removed before any plugin shipped
    // depending on it — a plugin allow-listed for "list" could enumerate the
    // operator's entire accepted-contacts list (with .onion addresses and
    // operator-private labels) regardless of any legitimate workflow. By
    // contrast, getByPubkeyHash() requires the plugin to first KNOW the hash
    // (typically learned from a transaction.received event), keeping the
    // contact-graph view demand-driven. This test pins the absence of the
    // bulk method so a future refactor doesn't quietly bring it back.
    // =========================================================================

    public function testNoBulkEnumerateMethodIsExposed(): void
    {
        $this->assertFalse(
            method_exists(ContactLookupService::class, 'listAccepted'),
            'listAccepted was removed for contact-graph privacy; do not reintroduce '
            . 'without operator-visible manifest gating distinguishing it from '
            . 'per-hash lookups (see class docblock for the rationale).'
        );
    }

    // =========================================================================
    // #[PluginCallable] attribute coverage. The method MUST carry the
    // attribute — without it PluginGatewayController's reflection gate would
    // refuse the call even if a manifest allow-listed the method.
    // =========================================================================

    public function testGetByPubkeyHashCarriesPluginCallableAttribute(): void
    {
        $reflection = new ReflectionMethod(ContactLookupService::class, 'getByPubkeyHash');
        $attributes = $reflection->getAttributes(PluginCallable::class);

        $this->assertCount(
            1,
            $attributes,
            'ContactLookupService::getByPubkeyHash() must carry exactly one #[PluginCallable] attribute'
        );

        $instance = $attributes[0]->newInstance();
        $this->assertNotSame(
            '',
            $instance->description ?? '',
            "getByPubkeyHash's #[PluginCallable] must have a non-empty description"
        );
    }
}
