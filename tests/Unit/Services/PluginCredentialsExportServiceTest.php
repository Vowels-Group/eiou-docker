<?php
namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\PluginCredentialsExportService;

#[CoversClass(PluginCredentialsExportService::class)]
class PluginCredentialsExportServiceTest extends TestCase
{
    /**
     * Build a service with stubbed action executor + database-context
     * reader. Returns [service, $holder] where $holder->calls accumulates
     * each executor invocation — using a holder object avoids the
     * reference-through-destructuring pitfall (`[$x, &$y] = ...` doesn't
     * propagate the reference in PHP).
     *
     * @return array{0:PluginCredentialsExportService, 1:object}
     */
    private function makeService(
        string $dbHost = '127.0.0.1',
        ?string $dbName = 'eiou',
        string $executorStatus = 'ok'
    ): array {
        $holder = new \stdClass();
        $holder->calls = [];
        $svc = new PluginCredentialsExportService(
            null,
            function (string $action, array $payload) use ($holder, $executorStatus): array {
                $holder->calls[] = ['action' => $action, 'payload' => $payload];
                return ['status' => $executorStatus];
            },
            function () use ($dbHost, $dbName): array {
                return ['host' => $dbHost, 'name' => $dbName];
            }
        );
        return [$svc, $holder];
    }

    // =========================================================================
    // credentialsPath()
    // =========================================================================

    public function testCredentialsPathRendersExpectedShape(): void
    {
        [$svc] = $this->makeService();
        $this->assertSame(
            '/etc/eiou/credentials/plugin-my-plugin.json',
            $svc->credentialsPath('my-plugin')
        );
    }

    public function testCredentialsPathRejectsBadPluginId(): void
    {
        [$svc] = $this->makeService();
        $this->expectException(\InvalidArgumentException::class);
        $svc->credentialsPath('Bad_Plugin'); // uppercase + underscore not allowed
    }

    // =========================================================================
    // groupNameFor()
    // =========================================================================

    public function testGroupNameForUsesEiouPcPrefixAndEightHexHash(): void
    {
        [$svc] = $this->makeService();
        $name = $svc->groupNameFor('my-plugin');
        $this->assertMatchesRegularExpression(
            '/^eiou-pc-[a-f0-9]{8}$/',
            $name,
            'group name must match eiou-pc-<8hex> shape so the supervisor admits it'
        );
    }

    public function testGroupNameForIsDeterministic(): void
    {
        [$svc] = $this->makeService();
        $this->assertSame($svc->groupNameFor('my-plugin'), $svc->groupNameFor('my-plugin'));
    }

    public function testGroupNameForDiffersBetweenPlugins(): void
    {
        [$svc] = $this->makeService();
        $this->assertNotSame(
            $svc->groupNameFor('plugin-a'),
            $svc->groupNameFor('plugin-b')
        );
    }

    // =========================================================================
    // export()
    // =========================================================================

    public function testExportCallsApplyCredentialsWithExpectedPayload(): void
    {
        [$svc, $h] = $this->makeService('127.0.0.1', 'eiou');
        $this->assertTrue($svc->export('my-plugin', 'super-secret-pw'));

        $this->assertCount(1, $h->calls);
        $this->assertSame('apply-credentials', $h->calls[0]['action']);
        $this->assertSame('my-plugin', $h->calls[0]['payload']['plugin_id']);
        $this->assertSame(
            '/etc/eiou/credentials/plugin-my-plugin.json',
            $h->calls[0]['payload']['target_path']
        );
        $this->assertMatchesRegularExpression(
            '/^eiou-pc-[a-f0-9]{8}$/',
            $h->calls[0]['payload']['group_name']
        );

        $body = json_decode($h->calls[0]['payload']['body'], true);
        $this->assertIsArray($body);
        $this->assertSame('127.0.0.1', $body['host']);
        $this->assertSame(3306, $body['port']);
        $this->assertSame('eiou', $body['database']);
        $this->assertSame('plugin_my_plugin', $body['username']);
        $this->assertSame('super-secret-pw', $body['password']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $body['issued_at']
        );
    }

    public function testExportSnakeCasesPluginIdForMysqlUsername(): void
    {
        [$svc, $h] = $this->makeService();
        $svc->export('multi-word-id', 'pw');

        $body = json_decode($h->calls[0]['payload']['body'], true);
        $this->assertSame('plugin_multi_word_id', $body['username']);
    }

    public function testExportReturnsFalseAndDoesNotCallExecutorOnBadId(): void
    {
        [$svc, $h] = $this->makeService();
        $this->assertFalse($svc->export('Invalid-ID', 'pw'));
        $this->assertCount(0, $h->calls);
    }

    public function testExportReturnsFalseWithEmptyPassword(): void
    {
        [$svc, $h] = $this->makeService();
        $this->assertFalse($svc->export('my-plugin', ''));
        $this->assertCount(0, $h->calls);
    }

    public function testExportReturnsFalseWhenDatabaseContextUnavailable(): void
    {
        [$svc, $h] = $this->makeService('127.0.0.1', null);
        $this->assertFalse($svc->export('my-plugin', 'pw'));
        $this->assertCount(0, $h->calls);
    }

    public function testExportSurfacesExecutorFailure(): void
    {
        [$svc] = $this->makeService('127.0.0.1', 'eiou', 'failed');
        $this->assertFalse($svc->export('my-plugin', 'pw'));
    }

    public function testExportFallsBackToLocalhostWhenDbHostMissing(): void
    {
        [$svc, $h] = $this->makeService('', 'eiou');
        $svc->export('my-plugin', 'pw');

        $body = json_decode($h->calls[0]['payload']['body'], true);
        $this->assertSame('127.0.0.1', $body['host']);
    }

    // =========================================================================
    // revoke()
    // =========================================================================

    public function testRevokeCallsDropCredentialsWithExpectedPath(): void
    {
        [$svc, $h] = $this->makeService();
        $this->assertTrue($svc->revoke('my-plugin'));

        $this->assertCount(1, $h->calls);
        $this->assertSame('drop-credentials', $h->calls[0]['action']);
        $this->assertSame('my-plugin', $h->calls[0]['payload']['plugin_id']);
        $this->assertSame(
            '/etc/eiou/credentials/plugin-my-plugin.json',
            $h->calls[0]['payload']['target_path']
        );
        $this->assertMatchesRegularExpression(
            '/^eiou-pc-[a-f0-9]{8}$/',
            $h->calls[0]['payload']['group_name']
        );
    }

    public function testRevokeReturnsFalseOnBadId(): void
    {
        [$svc, $h] = $this->makeService();
        $this->assertFalse($svc->revoke('UPPERCASE-id'));
        $this->assertCount(0, $h->calls);
    }

    public function testRevokeSurfacesExecutorFailure(): void
    {
        [$svc] = $this->makeService('127.0.0.1', 'eiou', 'failed');
        $this->assertFalse($svc->revoke('my-plugin'));
    }
}
