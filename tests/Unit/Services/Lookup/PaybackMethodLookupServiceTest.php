<?php
namespace Eiou\Tests\Services\Lookup;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Contracts\PluginCallable;
use Eiou\Contracts\PluginCallerAware;
use Eiou\Database\PaybackMethodReceivedRepository;
use Eiou\Database\PaybackMethodRepository;
use Eiou\Services\Lookup\PaybackMethodLookupService;
use Eiou\Services\Plugins\PluginLoader;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(PaybackMethodLookupService::class)]
class PaybackMethodLookupServiceTest extends TestCase
{
    private $ownRepo;
    private $receivedRepo;
    /** @var PluginLoader&\PHPUnit\Framework\MockObject\MockObject */
    private $loader;
    private PaybackMethodLookupService $svc;

    protected function setUp(): void
    {
        $this->ownRepo = $this->createMock(PaybackMethodRepository::class);
        $this->receivedRepo = $this->createMock(PaybackMethodReceivedRepository::class);
        $this->loader = $this->createMock(PluginLoader::class);
        $this->svc = new PaybackMethodLookupService(
            $this->ownRepo,
            $this->receivedRepo,
            $this->loader
        );
    }

    private function pluginRowWithRailTypes(string $name, array $ids): array
    {
        return [
            'name' => $name,
            'payback_method_types' => array_map(
                fn(string $id): array => ['id' => $id, 'catalog' => []],
                $ids
            ),
        ];
    }

    // =========================================================================
    // PluginCallerAware contract
    // =========================================================================

    public function testImplementsPluginCallerAware(): void
    {
        $this->assertInstanceOf(PluginCallerAware::class, $this->svc);
    }

    public function testGetMyConfiguredMethodsRefusesWithoutCallerId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gateway-injected caller id');
        $this->svc->getMyConfiguredMethods();
    }

    public function testGetContactPaybackPreferenceRefusesWithoutCallerId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->getContactPaybackPreference('hash');
    }

    // =========================================================================
    // Type-scoping — a plugin only sees rows of rail types it declared
    // =========================================================================

    public function testGetMyConfiguredMethodsFiltersByDeclaredRailTypes(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRowWithRailTypes('btc-rail', ['btc']),
        ]);
        $this->ownRepo->method('listMethods')->with(null, true)->willReturn([
            ['method_id' => 'm1', 'type' => 'btc',       'label' => 'Cold', 'currency' => 'USD', 'priority' => 100, 'enabled' => 1, 'share_policy' => 'auto', 'settlement_min_unit' => 1, 'settlement_min_unit_exponent' => -8],
            ['method_id' => 'm2', 'type' => 'bank_wire', 'label' => 'BoA',  'currency' => 'USD', 'priority' => 100, 'enabled' => 1, 'share_policy' => 'auto', 'settlement_min_unit' => 1, 'settlement_min_unit_exponent' => -2],
            ['method_id' => 'm3', 'type' => 'btc',       'label' => 'Hot',  'currency' => 'USD', 'priority' => 50,  'enabled' => 1, 'share_policy' => 'auto', 'settlement_min_unit' => 1, 'settlement_min_unit_exponent' => -8],
        ]);
        $this->svc->setCallingPluginId('btc-rail');

        $result = $this->svc->getMyConfiguredMethods();
        $this->assertCount(2, $result);
        foreach ($result as $row) {
            $this->assertSame('btc', $row['type'], 'every returned row must be of a declared rail type');
        }
    }

    public function testPluginWithNoRailTypesGetsEmptyResults(): void
    {
        // No payback_method_types declared → effective scope is empty
        // → both methods return [] without hitting the repository.
        $this->loader->method('listAllPlugins')->willReturn([
            ['name' => 'no-rails'], // payback_method_types absent
        ]);
        $this->ownRepo->expects($this->never())->method('listMethods');
        $this->receivedRepo->expects($this->never())->method('listFreshForContact');
        $this->svc->setCallingPluginId('no-rails');

        $this->assertSame([], $this->svc->getMyConfiguredMethods());
        $this->assertSame([], $this->svc->getContactPaybackPreference('hash'));
    }

    public function testGetContactPaybackPreferenceFiltersByDeclaredRailTypes(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRowWithRailTypes('btc-rail', ['btc']),
        ]);
        $this->receivedRepo->method('listFreshForContact')
            ->with('hash-x', null)
            ->willReturn([
                ['remote_method_id' => 'rm1', 'contact_pubkey_hash' => 'hash-x', 'type' => 'btc',       'label' => 'BTC', 'currency' => 'USD', 'priority' => 1,  'settlement_min_unit' => 1, 'settlement_min_unit_exponent' => -8, 'received_at' => '2026-05-01 10:00:00', 'expires_at' => '2026-12-31 23:59:59'],
                ['remote_method_id' => 'rm2', 'contact_pubkey_hash' => 'hash-x', 'type' => 'bank_wire', 'label' => 'BoA', 'currency' => 'USD', 'priority' => 2,  'settlement_min_unit' => 1, 'settlement_min_unit_exponent' => -2, 'received_at' => '2026-05-01 10:00:00', 'expires_at' => '2026-12-31 23:59:59'],
            ]);
        $this->svc->setCallingPluginId('btc-rail');

        $r = $this->svc->getContactPaybackPreference('hash-x');
        $this->assertCount(1, $r);
        $this->assertSame('btc', $r[0]['type']);
        $this->assertArrayNotHasKey('fields_json', $r[0],
            'fields_json must NOT leak through the contact-side projection');
    }

    public function testGetContactPaybackPreferencePassesCurrencyToRepo(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRowWithRailTypes('btc-rail', ['btc']),
        ]);
        $this->receivedRepo->expects($this->once())
            ->method('listFreshForContact')
            ->with('hash-x', 'USD')
            ->willReturn([]);
        $this->svc->setCallingPluginId('btc-rail');
        $this->svc->getContactPaybackPreference('hash-x', 'USD');
    }

    public function testGetContactPaybackPreferenceShortCircuitsEmptyHashOrCurrency(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRowWithRailTypes('btc-rail', ['btc']),
        ]);
        $this->receivedRepo->expects($this->never())->method('listFreshForContact');
        $this->svc->setCallingPluginId('btc-rail');

        $this->assertSame([], $this->svc->getContactPaybackPreference(''));
        $this->assertSame([], $this->svc->getContactPaybackPreference('hash', '   '));
    }

    // =========================================================================
    // Projection contract — sensitive fields must not leak
    // =========================================================================

    public function testGetMyConfiguredMethodsOmitsEncryptedFields(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRowWithRailTypes('btc-rail', ['btc']),
        ]);
        $this->ownRepo->method('listMethods')->willReturn([
            [
                'method_id' => 'm1', 'type' => 'btc', 'label' => 'Cold',
                'currency' => 'USD', 'priority' => 100, 'enabled' => 1,
                'share_policy' => 'auto',
                'settlement_min_unit' => 1, 'settlement_min_unit_exponent' => -8,
                // MUST NOT leak
                'encrypted_fields' => '{"ciphertext":"SECRET"}',
                'fields_version' => 1,
            ],
        ]);
        $this->svc->setCallingPluginId('btc-rail');

        $r = $this->svc->getMyConfiguredMethods();
        $this->assertCount(1, $r);
        $this->assertArrayNotHasKey('encrypted_fields', $r[0]);
        $this->assertArrayNotHasKey('fields_version', $r[0]);
    }

    // =========================================================================
    // Permission-gate annotation
    // =========================================================================

    public function testGetMyConfiguredMethodsRequiresOwnPermission(): void
    {
        $reflection = new ReflectionMethod(PaybackMethodLookupService::class, 'getMyConfiguredMethods');
        $instance = $reflection->getAttributes(PluginCallable::class)[0]->newInstance();
        $this->assertSame('payback_method_read_own', $instance->permission);
    }

    public function testGetContactPaybackPreferenceRequiresContactPermission(): void
    {
        $reflection = new ReflectionMethod(PaybackMethodLookupService::class, 'getContactPaybackPreference');
        $instance = $reflection->getAttributes(PluginCallable::class)[0]->newInstance();
        $this->assertSame('payback_method_read_contact', $instance->permission);
    }
}
