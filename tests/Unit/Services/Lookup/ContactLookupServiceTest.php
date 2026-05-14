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
    // listAccepted — paging + cap behaviour
    // =========================================================================

    public function testListAcceptedPassesLimitAndOffset(): void
    {
        $this->repo->expects($this->once())
            ->method('getAcceptedContactsPage')
            ->with(20, 40)
            ->willReturn([
                ['name' => 'Carol', 'http' => 'h', 'https' => null, 'tor' => null, 'pubkey_hash' => 'h-c'],
            ]);

        $result = $this->svc->listAccepted(20, 40);
        $this->assertCount(1, $result);
        $this->assertSame('Carol', $result[0]['name']);
    }

    public function testListAcceptedAppliesDefaults(): void
    {
        $this->repo->expects($this->once())
            ->method('getAcceptedContactsPage')
            ->with(50, 0)
            ->willReturn([]);

        $this->assertSame([], $this->svc->listAccepted());
    }

    public function testListAcceptedCapsLimitAtMaxPageLimit(): void
    {
        // Same anti-exfiltration cap as TransactionLookupService — a
        // plugin can't pull the entire contact list in one call.
        $this->repo->expects($this->once())
            ->method('getAcceptedContactsPage')
            ->with(ContactLookupService::MAX_PAGE_LIMIT, 0)
            ->willReturn([]);

        $this->svc->listAccepted(1_000_000);
    }

    public function testListAcceptedClampsNegativeBoundsToZero(): void
    {
        $this->repo->expects($this->once())
            ->method('getAcceptedContactsPage')
            ->with(0, 0)
            ->willReturn([]);

        $this->svc->listAccepted(-1, -100);
    }

    public function testListAcceptedProjectsEachRow(): void
    {
        $this->repo->method('getAcceptedContactsPage')->willReturn([
            [
                'name' => 'Alice', 'http' => 'h', 'https' => null,
                'tor' => null, 'pubkey_hash' => 'h-a',
                'pubkey' => 'SECRET', 'status' => 'accepted',
            ],
            [
                'name' => 'Bob', 'http' => null, 'https' => 's',
                'tor' => null, 'pubkey_hash' => 'h-b',
                'contact_id' => 'SECRET',
            ],
        ]);

        $result = $this->svc->listAccepted();
        foreach ($result as $row) {
            $this->assertSame(
                ['name', 'http', 'https', 'tor', 'pubkey_hash'],
                array_keys($row),
                'every row in listAccepted must carry exactly the documented shape'
            );
        }
    }

    // =========================================================================
    // #[PluginCallable] attribute coverage. Both methods MUST carry the
    // attribute — without it PluginGatewayController's reflection gate would
    // refuse the call even if a manifest allow-listed the method.
    // =========================================================================

    public static function pluginCallableMethodProvider(): array
    {
        return [
            'getByPubkeyHash' => ['getByPubkeyHash'],
            'listAccepted'    => ['listAccepted'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pluginCallableMethodProvider')]
    public function testMethodCarriesPluginCallableAttribute(string $method): void
    {
        $reflection = new ReflectionMethod(ContactLookupService::class, $method);
        $attributes = $reflection->getAttributes(PluginCallable::class);

        $this->assertCount(
            1,
            $attributes,
            "ContactLookupService::{$method}() must carry exactly one #[PluginCallable] attribute"
        );

        $instance = $attributes[0]->newInstance();
        $this->assertNotSame(
            '',
            $instance->description ?? '',
            "ContactLookupService::{$method}()'s #[PluginCallable] must have a non-empty description"
        );
    }
}
