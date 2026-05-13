<?php
namespace Eiou\Tests\Services\Plugins;

use Eiou\Contracts\PluginCallable;
use Eiou\Contracts\PluginCallerAware;
use Eiou\Events\EventDispatcher;
use Eiou\Services\Plugins\PluginEventPublisher;
use Eiou\Utils\Logger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(PluginEventPublisher::class)]
class PluginEventPublisherTest extends TestCase
{
    /** @var array<int, array{event:string, data:array}> */
    private array $captured = [];

    private function svc(): PluginEventPublisher
    {
        EventDispatcher::resetInstance();
        $dispatcher = EventDispatcher::getInstance();
        // Wildcard listener — capture every event the publisher emits
        // so we can assert on name + payload without needing to know
        // the exact namespaced string up front. EventDispatcher subscribes
        // by exact name, so we subscribe to several candidates that the
        // tests below produce.
        $logger = $this->createMock(Logger::class);
        return new PluginEventPublisher($dispatcher, $logger);
    }

    private function subscribe(string $name, EventDispatcher $dispatcher): void
    {
        $dispatcher->subscribe($name, function (array $data) use ($name): void {
            $this->captured[] = ['event' => $name, 'data' => $data];
        });
    }

    // ===================================================================
    // Happy path
    // ===================================================================

    #[Test]
    public function publishesNamespacedEvent(): void
    {
        $svc = $this->svc();
        $this->subscribe('plugin.payback-btc.refund-issued', EventDispatcher::getInstance());
        $svc->setCallingPluginId('payback-btc');

        $svc->publish('refund-issued', ['txid' => 'abc123', 'amount' => '0.5']);

        $this->assertCount(1, $this->captured);
        $this->assertSame('plugin.payback-btc.refund-issued', $this->captured[0]['event']);
        $this->assertSame('abc123', $this->captured[0]['data']['txid']);
        $this->assertSame('0.5', $this->captured[0]['data']['amount']);
    }

    #[Test]
    public function appendsSourcePluginToPayload(): void
    {
        $svc = $this->svc();
        $this->subscribe('plugin.payback-btc.something', EventDispatcher::getInstance());
        $svc->setCallingPluginId('payback-btc');

        $svc->publish('something', ['k' => 'v']);

        $this->assertSame('payback-btc', $this->captured[0]['data']['_source_plugin']);
    }

    #[Test]
    public function publishWithEmptyPayloadOk(): void
    {
        $svc = $this->svc();
        $this->subscribe('plugin.demo.empty-event', EventDispatcher::getInstance());
        $svc->setCallingPluginId('demo');

        $this->assertTrue($svc->publish('empty-event', []));
        $this->assertSame('demo', $this->captured[0]['data']['_source_plugin']);
    }

    // ===================================================================
    // Validation
    // ===================================================================

    public static function badEventNameProvider(): array
    {
        return [
            'empty'             => [''],
            'starts with digit' => ['1abc'],
            'uppercase'         => ['MyEvent'],
            'spaces'            => ['has space'],
            'dot'               => ['has.dot'],
            'over 64 chars'     => [str_repeat('a', 65)],
            'null byte'         => ["a\0b"],
        ];
    }

    #[Test]
    #[DataProvider('badEventNameProvider')]
    public function rejectsBadEventName(string $bad): void
    {
        $svc = $this->svc();
        $svc->setCallingPluginId('demo');

        $this->expectException(InvalidArgumentException::class);
        $svc->publish($bad, []);
    }

    #[Test]
    public function rejectsOversizePayload(): void
    {
        $svc = $this->svc();
        $svc->setCallingPluginId('demo');
        $huge = ['blob' => str_repeat('x', PluginEventPublisher::MAX_PAYLOAD_BYTES + 1)];

        $this->expectException(InvalidArgumentException::class);
        $svc->publish('huge', $huge);
    }

    #[Test]
    public function rejectsNonJsonSerialisablePayload(): void
    {
        $svc = $this->svc();
        $svc->setCallingPluginId('demo');

        $this->expectException(InvalidArgumentException::class);
        // A resource doesn't survive json_encode → returns false.
        $svc->publish('boom', ['handle' => fopen('php://memory', 'r')]);
    }

    #[Test]
    public function refusesPublishWithoutCallerId(): void
    {
        $svc = $this->svc();
        // Note: setCallingPluginId not called — simulates an attacker
        // who somehow reached the method without going through the
        // gateway.
        $this->expectException(RuntimeException::class);
        $svc->publish('whatever', []);
    }

    #[Test]
    public function refusesPublishAfterCallerIdCleared(): void
    {
        $svc = $this->svc();
        $svc->setCallingPluginId('demo');
        $svc->setCallingPluginId(null);  // gateway cleared after a previous call

        $this->expectException(RuntimeException::class);
        $svc->publish('whatever', []);
    }

    // ===================================================================
    // Contract assertions
    // ===================================================================

    #[Test]
    public function publishMethodCarriesPluginCallableAttribute(): void
    {
        $reflection = new ReflectionMethod(PluginEventPublisher::class, 'publish');
        $attributes = $reflection->getAttributes(PluginCallable::class);
        $this->assertCount(1, $attributes,
            'publish() must carry exactly one #[PluginCallable] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertNotSame('', $instance->description ?? '');
    }

    #[Test]
    public function implementsPluginCallerAware(): void
    {
        $svc = $this->svc();
        $this->assertInstanceOf(PluginCallerAware::class, $svc);
    }

    protected function tearDown(): void
    {
        EventDispatcher::resetInstance();
        $this->captured = [];
    }
}
