<?php
namespace Eiou\Tests\Services\Lookup;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Contracts\PluginCallable;
use Eiou\Contracts\PluginCallerAware;
use Eiou\Services\Lookup\PluginLookupService;
use Eiou\Services\Plugins\PluginLoader;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(PluginLookupService::class)]
class PluginLookupServiceTest extends TestCase
{
    /** @var PluginLoader&\PHPUnit\Framework\MockObject\MockObject */
    private $loader;
    private PluginLookupService $svc;

    protected function setUp(): void
    {
        $this->loader = $this->createMock(PluginLoader::class);
        $this->svc = new PluginLookupService($this->loader);
    }

    // =========================================================================
    // PluginCallerAware contract — methods require gateway-injected caller id
    // =========================================================================

    public function testImplementsPluginCallerAware(): void
    {
        $this->assertInstanceOf(PluginCallerAware::class, $this->svc);
    }

    public function testGetOwnPermissionsRefusesWithoutCallerId(): void
    {
        // Default caller id is null. Defence in depth against any
        // path that reaches the method outside the gateway.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gateway-injected caller id');
        $this->svc->getOwnPermissions();
    }

    public function testGetOwnManifestRefusesWithoutCallerId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gateway-injected caller id');
        $this->svc->getOwnManifest();
    }

    // =========================================================================
    // getOwnPermissions — returns the calling plugin's own permissions list
    // =========================================================================

    public function testGetOwnPermissionsReturnsRowPermissions(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            ['name' => 'other',  'permissions' => ['contact_address_book_enumerate']],
            ['name' => 'caller', 'permissions' => ['wallet_balance_read', 'transaction_history_enumerate']],
        ]);
        $this->svc->setCallingPluginId('caller');

        $this->assertSame(
            ['wallet_balance_read', 'transaction_history_enumerate'],
            $this->svc->getOwnPermissions()
        );
    }

    public function testGetOwnPermissionsReturnsEmptyWhenRowMissing(): void
    {
        // Edge case — gateway resolved a plugin id that the loader's
        // listAllPlugins doesn't return (shouldn't happen in practice
        // but guarded so a stale fixture doesn't crash the call path).
        $this->loader->method('listAllPlugins')->willReturn([
            ['name' => 'other', 'permissions' => ['x']],
        ]);
        $this->svc->setCallingPluginId('missing');

        $this->assertSame([], $this->svc->getOwnPermissions());
    }

    public function testGetOwnPermissionsReturnsEmptyWhenRowHasNonePersisted(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            ['name' => 'caller'], // permissions key absent
        ]);
        $this->svc->setCallingPluginId('caller');

        $this->assertSame([], $this->svc->getOwnPermissions());
    }

    // =========================================================================
    // getOwnManifest — returns only the allow-listed manifest fields
    // =========================================================================

    public function testGetOwnManifestProjectsAllowListedFields(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            [
                'name'          => 'caller',
                'version'       => '1.2.3',
                'enabled'       => true,
                'sandboxed'     => true,
                'description'   => 'demo plugin',
                'core_services' => ['Logger.info'],
                'permissions'   => ['wallet_balance_read'],
                'subscribes_to' => ['transaction.received'],
                // Host-injected fields below — MUST NOT appear in the
                // projection (a plugin reading its own row shouldn't
                // learn host-internal state).
                'gateway_token' => 'SECRET',
                'system_user'   => 'eiou-p-deadbeef',
                'status'        => 'sandboxed',
            ],
        ]);
        $this->svc->setCallingPluginId('caller');

        $manifest = $this->svc->getOwnManifest();
        $this->assertSame('caller', $manifest['name']);
        $this->assertSame('1.2.3', $manifest['version']);
        $this->assertSame(['Logger.info'], $manifest['core_services']);
        $this->assertSame(['wallet_balance_read'], $manifest['permissions']);
        $this->assertSame(['transaction.received'], $manifest['subscribes_to']);

        $this->assertArrayNotHasKey('gateway_token', $manifest);
        $this->assertArrayNotHasKey('system_user', $manifest);
        $this->assertArrayNotHasKey('status', $manifest);
    }

    public function testGetOwnManifestReturnsEmptyArrayWhenRowMissing(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([]);
        $this->svc->setCallingPluginId('orphan');

        $this->assertSame([], $this->svc->getOwnManifest());
    }

    // =========================================================================
    // Cross-plugin isolation — a plugin cannot read another's row
    // =========================================================================

    public function testCannotReadAnotherPluginsRow(): void
    {
        // The service is wired with PluginCallerAware — the gateway sets
        // the caller id from the bearer token, not from method args.
        // There is no codepath through which a plugin can pass another
        // plugin's name into either method. This test pins that absence:
        // the only id consumed is what setCallingPluginId installs, and
        // each call resolves against THAT id alone.
        $this->loader->method('listAllPlugins')->willReturn([
            ['name' => 'alpha', 'permissions' => ['p_alpha']],
            ['name' => 'beta',  'permissions' => ['p_beta']],
        ]);
        $this->svc->setCallingPluginId('alpha');
        $this->assertSame(['p_alpha'], $this->svc->getOwnPermissions());

        $this->svc->setCallingPluginId('beta');
        $this->assertSame(['p_beta'], $this->svc->getOwnPermissions());
    }

    // =========================================================================
    // PluginCallable attribute coverage — no permission key (self-introspection)
    // =========================================================================

    public function testGetOwnPermissionsHasNoPermissionRequirement(): void
    {
        $reflection = new ReflectionMethod(PluginLookupService::class, 'getOwnPermissions');
        $instance = $reflection->getAttributes(PluginCallable::class)[0]->newInstance();
        $this->assertNull(
            $instance->permission,
            'getOwnPermissions reads the plugin\'s own data only — no permission gate needed'
        );
    }

    public function testGetOwnManifestHasNoPermissionRequirement(): void
    {
        $reflection = new ReflectionMethod(PluginLookupService::class, 'getOwnManifest');
        $instance = $reflection->getAttributes(PluginCallable::class)[0]->newInstance();
        $this->assertNull(
            $instance->permission,
            'getOwnManifest reads the plugin\'s own data only — no permission gate needed'
        );
    }

    // =========================================================================
    // listEnabledPluginIds — cross-plugin inventory, gated
    // =========================================================================

    public function testListEnabledPluginIdsReturnsOtherEnabledPlugins(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            ['name' => 'caller', 'version' => '1.0.0', 'enabled' => true],
            ['name' => 'alpha',  'version' => '2.3.4', 'enabled' => true],
            ['name' => 'beta',   'version' => '0.1.0', 'enabled' => false],
            ['name' => 'gamma',  'version' => '5.0.0', 'enabled' => true],
        ]);
        $this->svc->setCallingPluginId('caller');

        $result = $this->svc->listEnabledPluginIds();
        // Caller's own row excluded; disabled rows excluded.
        $this->assertSame(
            [
                ['name' => 'alpha', 'version' => '2.3.4'],
                ['name' => 'gamma', 'version' => '5.0.0'],
            ],
            $result
        );
    }

    public function testListEnabledPluginIdsExcludesSelfRegardlessOfArguments(): void
    {
        // No method arg can override the self-exclusion — the caller id
        // is the only id the service ever consumes. Pin this so a
        // future refactor doesn't quietly let a plugin enumerate "all
        // plugins including itself" via some clever argument shape.
        $this->loader->method('listAllPlugins')->willReturn([
            ['name' => 'caller', 'version' => '1.0.0', 'enabled' => true],
        ]);
        $this->svc->setCallingPluginId('caller');
        $this->assertSame([], $this->svc->listEnabledPluginIds());
    }

    public function testListEnabledPluginIdsRefusesWithoutCallerId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gateway-injected caller id');
        $this->svc->listEnabledPluginIds();
    }

    public function testListEnabledPluginIdsRequiresPluginInventoryReadPermission(): void
    {
        $reflection = new ReflectionMethod(PluginLookupService::class, 'listEnabledPluginIds');
        $instance = $reflection->getAttributes(PluginCallable::class)[0]->newInstance();
        $this->assertSame(
            'plugin_inventory_read',
            $instance->permission,
            'listEnabledPluginIds discloses operator-environment plugin choices; must gate'
        );
    }
}
