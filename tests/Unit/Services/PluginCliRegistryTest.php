<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Eiou\Cli\CliOutputManager;
use Eiou\Core\ErrorCodes;
use Eiou\Services\PluginCliRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PluginCliRegistry::class)]
class PluginCliRegistryTest extends TestCase
{
    private PluginCliRegistry $registry;
    private MockObject|CliOutputManager $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new PluginCliRegistry();
        $this->output = $this->createMock(CliOutputManager::class);
    }

    public function testRegisterAndDispatch(): void
    {
        $called = null;
        $this->registry->register('myplugin', function (array $argv, CliOutputManager $out) use (&$called) {
            $called = $argv;
        });

        $this->assertTrue($this->registry->has('myplugin'));
        $this->registry->dispatch('myplugin', ['eiou', 'myplugin', 'status'], $this->output);
        $this->assertSame(['eiou', 'myplugin', 'status'], $called);
    }

    public function testRegisterNameCaseInsensitive(): void
    {
        $this->registry->register('CamelCase', fn() => null);
        $this->assertTrue($this->registry->has('camelcase'));
        $this->assertTrue($this->registry->has('CAMELCASE'));
    }

    public function testRegisterRejectsReservedName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/collides with a core command/');
        $this->registry->register('plugin', fn() => null);
    }

    public function testRegisterRejectsSecondReservedName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry->register('send', fn() => null);
    }

    public function testRegisterRejectsInvalidNameFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/kebab-case/');
        $this->registry->register('UPPERCASE_BAD', fn() => null);
    }

    public function testRegisterRejectsDuplicateRegistration(): void
    {
        $this->registry->register('myplugin', fn() => null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already registered/');
        $this->registry->register('myplugin', fn() => null);
    }

    public function testDispatchUnknownCommandEmitsError(): void
    {
        $this->output->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Unknown command'), ErrorCodes::COMMAND_NOT_FOUND, 404);

        $this->registry->dispatch('ghost', [], $this->output);
    }

    public function testDispatchSwallowsHandlerException(): void
    {
        $this->registry->register('explosive', function () {
            throw new \RuntimeException('kaboom');
        });

        $this->output->expects($this->once())
            ->method('error')
            ->with($this->stringContains('kaboom'), $this->anything(), 500);

        $this->registry->dispatch('explosive', [], $this->output);
    }

    public function testListRegisteredReturnsNamesInRegistrationOrder(): void
    {
        $this->registry->register('beta', fn() => null);
        $this->registry->register('alpha', fn() => null);
        $this->registry->register('gamma', fn() => null);

        $this->assertSame(['beta', 'alpha', 'gamma'], $this->registry->listRegistered());
    }
}
