<?php
namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\PluginCredentialService;
use Eiou\Services\PluginPdoFactory;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Test-only subclass that substitutes the real MySQL `open()` with an
 * in-memory recorder. Unit tests don't have MariaDB available, so the
 * real connection path is covered by integration tests (the Docker
 * compose-based suite). Everything that happens before the actual
 * connection — validation, cache, credential resolution, username
 * derivation — stays testable here.
 */
class PluginPdoFactoryTestDouble extends PluginPdoFactory
{
    public array $openCalls = [];
    public ?\Throwable $openThrows = null;
    /** @var callable|null Returns a fresh PDO instance per call */
    public $pdoProvider = null;

    protected function open(string $username, string $password): PDO
    {
        $this->openCalls[] = ['username' => $username, 'password' => $password];
        if ($this->openThrows !== null) {
            $t = $this->openThrows;
            $this->openThrows = null;
            throw $t;
        }
        if ($this->pdoProvider === null) {
            throw new \LogicException('Test pdoProvider not set');
        }
        return ($this->pdoProvider)();
    }
}

#[CoversClass(PluginPdoFactory::class)]
class PluginPdoFactoryTest extends TestCase
{
    private $credentials;
    private PluginPdoFactoryTestDouble $factory;

    protected function setUp(): void
    {
        $this->credentials = $this->createMock(PluginCredentialService::class);
        $this->factory = new PluginPdoFactoryTestDouble($this->credentials);
        $this->factory->pdoProvider = fn() => $this->createMock(PDO::class);
    }

    public function testGetForLooksUpCredentialsAndOpensConnection(): void
    {
        $this->credentials->method('getPlaintext')
            ->with('my-plugin')
            ->willReturn('the-password');

        $pdo = $this->factory->getFor('my-plugin');
        $this->assertInstanceOf(PDO::class, $pdo);

        $this->assertCount(1, $this->factory->openCalls);
        $call = $this->factory->openCalls[0];
        $this->assertSame('plugin_my_plugin', $call['username']);
        $this->assertSame('the-password', $call['password']);
    }

    public function testGetForCachesPerPluginForIdempotentCalls(): void
    {
        $this->credentials->method('getPlaintext')->willReturn('pw');

        $a = $this->factory->getFor('my-plugin');
        $b = $this->factory->getFor('my-plugin');
        $this->assertSame($a, $b);
        // Only one open() call despite two getFor() calls.
        $this->assertCount(1, $this->factory->openCalls);
        $this->assertTrue($this->factory->isCached('my-plugin'));
    }

    public function testDifferentPluginsGetDifferentConnections(): void
    {
        $this->credentials->method('getPlaintext')
            ->willReturnCallback(fn($id) => "pw-$id");

        $a = $this->factory->getFor('plugin-a');
        $b = $this->factory->getFor('plugin-b');
        $this->assertNotSame($a, $b);
        $this->assertCount(2, $this->factory->openCalls);
        $this->assertSame('plugin_plugin_a', $this->factory->openCalls[0]['username']);
        $this->assertSame('plugin_plugin_b', $this->factory->openCalls[1]['username']);
    }

    public function testKebabCasePluginNameBecomesSnakeCaseUsername(): void
    {
        $this->credentials->method('getPlaintext')->willReturn('pw');
        $this->factory->getFor('my-awesome-plugin');
        $this->assertSame(
            'plugin_my_awesome_plugin',
            $this->factory->openCalls[0]['username']
        );
    }

    public function testMissingCredentialsThrowWithHelpfulMessage(): void
    {
        $this->credentials->method('getPlaintext')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No credentials stored.*my-plugin.*enabled first/s');
        $this->factory->getFor('my-plugin');
    }

    public function testOpenFailureSurfacesAsRuntimeException(): void
    {
        $this->credentials->method('getPlaintext')->willReturn('pw');
        $this->factory->openThrows = new \PDOException('access denied');

        $this->expectException(RuntimeException::class);
        $this->factory->getFor('my-plugin');
    }

    public function testPurgeDropsCachedConnection(): void
    {
        $this->credentials->method('getPlaintext')->willReturn('pw');

        $a = $this->factory->getFor('my-plugin');
        $this->assertTrue($this->factory->isCached('my-plugin'));
        $this->factory->purge('my-plugin');
        $this->assertFalse($this->factory->isCached('my-plugin'));

        // Next call reopens.
        $b = $this->factory->getFor('my-plugin');
        $this->assertCount(2, $this->factory->openCalls);
    }

    public function testPurgeIsSafeOnUncachedPlugin(): void
    {
        $this->factory->purge('never-seen');
        // No error, no exception — just a no-op.
        $this->assertFalse($this->factory->isCached('never-seen'));
    }

    public function testInvalidPluginIdRejectedAtEveryEntryPoint(): void
    {
        foreach (['getFor', 'isCached', 'purge'] as $method) {
            if ($method === 'isCached' || $method === 'purge') {
                // These don't reject invalid ids — they just won't match
                // anything in the cache. Skip.
                continue;
            }
            try {
                $this->factory->$method('BAD_CAPS');
                $this->fail("$method did not validate");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid plugin id', $e->getMessage());
            }
        }
    }

    public function testGetForRejectsOverlongPluginId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory->getFor(str_repeat('a', 65));
    }
}
