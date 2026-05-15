<?php
namespace Eiou\Tests\Services\Lookup;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Contracts\PluginCallable;
use Eiou\Core\AppConfig;
use Eiou\Core\UserContext;
use Eiou\Services\Lookup\NodeInfoLookupService;
use ReflectionMethod;

#[CoversClass(NodeInfoLookupService::class)]
class NodeInfoLookupServiceTest extends TestCase
{
    private $userContext;

    protected function setUp(): void
    {
        $this->userContext = $this->createMock(UserContext::class);
    }

    private function svcWith(array $configOverrides = []): NodeInfoLookupService
    {
        $config = AppConfig::fromEnvironment()->withOverrides(array_merge([
            'appEnv'   => 'production',
            'appDebug' => false,
        ], $configOverrides));
        return new NodeInfoLookupService($config, $this->userContext);
    }

    public function testGetAppEnvReturnsConfigValue(): void
    {
        $svc = $this->svcWith(['appEnv' => 'development']);
        $this->assertSame('development', $svc->getAppEnv());
    }

    public function testIsDebugReturnsConfigValue(): void
    {
        $svc = $this->svcWith(['appDebug' => true]);
        $this->assertTrue($svc->isDebug());
    }

    public function testIsDebugDefaultsFalse(): void
    {
        $svc = $this->svcWith();
        $this->assertFalse($svc->isDebug());
    }

    public function testGetHttpsAddressDelegates(): void
    {
        $this->userContext->method('getHttpsAddress')
            ->willReturn('https://wallet.example.com');
        $svc = $this->svcWith();
        $this->assertSame('https://wallet.example.com', $svc->getHttpsAddress());
    }

    public function testGetHttpsAddressReturnsNullOnTorOnlyNode(): void
    {
        $this->userContext->method('getHttpsAddress')->willReturn(null);
        $svc = $this->svcWith();
        $this->assertNull($svc->getHttpsAddress());
    }

    public function testGetTorAddressDelegates(): void
    {
        $this->userContext->method('getTorAddress')
            ->willReturn('abc123def456.onion');
        $svc = $this->svcWith();
        $this->assertSame('abc123def456.onion', $svc->getTorAddress());
    }

    public static function pluginCallableMethodProvider(): array
    {
        return [
            'getAppEnv'        => ['getAppEnv'],
            'isDebug'          => ['isDebug'],
            'getHttpsAddress'  => ['getHttpsAddress'],
            'getTorAddress'    => ['getTorAddress'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pluginCallableMethodProvider')]
    public function testMethodCarriesPluginCallableAttribute(string $method): void
    {
        $reflection = new ReflectionMethod(NodeInfoLookupService::class, $method);
        $attributes = $reflection->getAttributes(PluginCallable::class);

        $this->assertCount(
            1,
            $attributes,
            "NodeInfoLookupService::{$method}() must carry exactly one #[PluginCallable] attribute"
        );

        $instance = $attributes[0]->newInstance();
        $this->assertNotSame(
            '',
            $instance->description ?? '',
            "NodeInfoLookupService::{$method}()'s #[PluginCallable] must have a non-empty description"
        );
    }
}
