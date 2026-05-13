<?php
namespace Eiou\Tests\Services\Lookup;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Contracts\PluginCallable;
use Eiou\Core\UserContext;
use Eiou\Services\Lookup\IdentityLookupService;
use ReflectionMethod;

#[CoversClass(IdentityLookupService::class)]
class IdentityLookupServiceTest extends TestCase
{
    private $userContext;
    private IdentityLookupService $svc;

    protected function setUp(): void
    {
        $this->userContext = $this->createMock(UserContext::class);
        $this->svc = new IdentityLookupService($this->userContext);
    }

    public function testGetPublicKeyDelegates(): void
    {
        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('04abcd...');

        $this->assertSame('04abcd...', $this->svc->getPublicKey());
    }

    public function testGetPublicKeyReturnsNullPreWallet(): void
    {
        $this->userContext->method('getPublicKey')->willReturn(null);
        $this->assertNull($this->svc->getPublicKey());
    }

    public function testGetPublicKeyHashDelegates(): void
    {
        $this->userContext->expects($this->once())
            ->method('getPublicKeyHash')
            ->willReturn('hash-hex');

        $this->assertSame('hash-hex', $this->svc->getPublicKeyHash());
    }

    public function testGetNameDelegates(): void
    {
        $this->userContext->expects($this->once())
            ->method('getName')
            ->willReturn('alice');

        $this->assertSame('alice', $this->svc->getName());
    }

    public function testGetNameReturnsNullWhenUnset(): void
    {
        $this->userContext->method('getName')->willReturn(null);
        $this->assertNull($this->svc->getName());
    }

    public static function pluginCallableMethodProvider(): array
    {
        return [
            'getPublicKey'     => ['getPublicKey'],
            'getPublicKeyHash' => ['getPublicKeyHash'],
            'getName'          => ['getName'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pluginCallableMethodProvider')]
    public function testMethodCarriesPluginCallableAttribute(string $method): void
    {
        $reflection = new ReflectionMethod(IdentityLookupService::class, $method);
        $attributes = $reflection->getAttributes(PluginCallable::class);

        $this->assertCount(
            1,
            $attributes,
            "IdentityLookupService::{$method}() must carry exactly one #[PluginCallable] attribute"
        );

        $instance = $attributes[0]->newInstance();
        $this->assertNotSame(
            '',
            $instance->description ?? '',
            "IdentityLookupService::{$method}()'s #[PluginCallable] must have a non-empty description"
        );
    }
}
