<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Eiou\Services\PluginApiRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PluginApiRegistry::class)]
class PluginApiRegistryTest extends TestCase
{
    private PluginApiRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new PluginApiRegistry();
    }

    public function testRegisterAndDispatchHappyPath(): void
    {
        $this->registry->register('myplugin', 'GET', 'status', function (string $method, array $params, string $body) {
            return ['status' => 'ok', 'method' => $method];
        });

        $result = $this->registry->dispatch('myplugin', 'GET', 'status', [], '');

        $this->assertSame(200, $result['status']);
        $this->assertSame('ok', $result['payload']['status']);
        $this->assertSame('GET', $result['payload']['method']);
    }

    public function testMethodAndPluginCaseNormalized(): void
    {
        $this->registry->register('MYPLUGIN', 'get', 'Status', fn() => ['ok' => true]);

        $this->assertTrue($this->registry->has('myplugin', 'GET', 'status'));
        $result = $this->registry->dispatch('myplugin', 'get', 'status', [], '');
        $this->assertSame(200, $result['status']);
    }

    public function testScalarReturnIsWrappedAsResult(): void
    {
        $this->registry->register('p', 'GET', 'pi', fn() => 3.14);

        $result = $this->registry->dispatch('p', 'GET', 'pi', [], '');
        $this->assertSame(['result' => 3.14], $result['payload']);
    }

    public function testDispatchUnknownRouteReturns404(): void
    {
        $result = $this->registry->dispatch('ghost', 'GET', 'status', [], '');

        $this->assertSame(404, $result['status']);
        $this->assertSame('plugin_route_not_found', $result['payload']['error']);
        $this->assertFalse($result['payload']['success']);
    }

    public function testHandlerExceptionBecomesStructuredError(): void
    {
        $this->registry->register('p', 'POST', 'bang', function () {
            throw new \RuntimeException('boom');
        });

        $result = $this->registry->dispatch('p', 'POST', 'bang', [], '');

        $this->assertSame(500, $result['status']);
        $this->assertSame('plugin_handler_error', $result['payload']['error']);
        $this->assertSame('boom', $result['payload']['message']);
        $this->assertSame('p', $result['payload']['plugin']);
    }

    // -- Collision / validation guards -------------------------------------

    public function testRegisterRejectsReservedActionEnable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reserved/');
        $this->registry->register('p', 'POST', 'enable', fn() => null);
    }

    public function testRegisterRejectsReservedActionDisable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry->register('p', 'POST', 'disable', fn() => null);
    }

    public function testRegisterRejectsInvalidMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/HTTP method/');
        $this->registry->register('p', 'TRACE', 'status', fn() => null);
    }

    public function testRegisterRejectsBadPluginName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Plugin name/');
        $this->registry->register('Bad_Name!', 'GET', 'status', fn() => null);
    }

    public function testRegisterRejectsBadActionName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/action/');
        $this->registry->register('p', 'GET', 'UPPERCASE/BAD', fn() => null);
    }

    public function testRegisterRejectsDuplicate(): void
    {
        $this->registry->register('p', 'GET', 'status', fn() => null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already has a handler/');
        $this->registry->register('p', 'GET', 'status', fn() => null);
    }

    public function testDifferentMethodsSamePathAllowed(): void
    {
        $this->registry->register('p', 'GET', 'status', fn() => ['get' => true]);
        $this->registry->register('p', 'POST', 'status', fn() => ['post' => true]);

        $this->assertTrue($this->registry->dispatch('p', 'GET', 'status', [], '')['payload']['get']);
        $this->assertTrue($this->registry->dispatch('p', 'POST', 'status', [], '')['payload']['post']);
    }

    public function testListRegistered(): void
    {
        $this->registry->register('alpha', 'GET', 'x', fn() => null);
        $this->registry->register('alpha', 'POST', 'y', fn() => null);
        $this->registry->register('beta', 'GET', 'z', fn() => null);

        $list = $this->registry->listRegistered();
        $this->assertCount(3, $list);
        $this->assertContains(['plugin' => 'alpha', 'method' => 'GET',  'action' => 'x'], $list);
        $this->assertContains(['plugin' => 'alpha', 'method' => 'POST', 'action' => 'y'], $list);
        $this->assertContains(['plugin' => 'beta',  'method' => 'GET',  'action' => 'z'], $list);
    }
}
