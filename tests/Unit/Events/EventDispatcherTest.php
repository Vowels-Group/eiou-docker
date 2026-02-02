<?php
/**
 * Unit Tests for EventDispatcher
 *
 * Tests event subscription, dispatch, and listener management.
 */

namespace Eiou\Tests\Events;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Events\EventDispatcher;

#[CoversClass(EventDispatcher::class)]
class EventDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset singleton between tests
        EventDispatcher::resetInstance();
    }

    protected function tearDown(): void
    {
        // Clean up after tests
        EventDispatcher::resetInstance();
    }

    /**
     * Test getInstance returns singleton
     */
    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = EventDispatcher::getInstance();
        $instance2 = EventDispatcher::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    /**
     * Test subscribe adds listener
     */
    public function testSubscribeAddsListener(): void
    {
        $dispatcher = EventDispatcher::getInstance();
        $listener = function() {};

        $dispatcher->subscribe('test.event', $listener);

        $this->assertTrue($dispatcher->hasListeners('test.event'));
        $this->assertEquals(1, $dispatcher->getListenerCount('test.event'));
    }

    /**
     * Test subscribe allows multiple listeners
     */
    public function testSubscribeAllowsMultipleListeners(): void
    {
        $dispatcher = EventDispatcher::getInstance();

        $dispatcher->subscribe('test.event', function() {});
        $dispatcher->subscribe('test.event', function() {});
        $dispatcher->subscribe('test.event', function() {});

        $this->assertEquals(3, $dispatcher->getListenerCount('test.event'));
    }

    /**
     * Test dispatch invokes listeners
     */
    public function testDispatchInvokesListeners(): void
    {
        $dispatcher = EventDispatcher::getInstance();
        $called = false;

        $dispatcher->subscribe('test.event', function() use (&$called) {
            $called = true;
        });

        $dispatcher->dispatch('test.event');

        $this->assertTrue($called);
    }

    /**
     * Test dispatch passes data to listeners
     */
    public function testDispatchPassesDataToListeners(): void
    {
        $dispatcher = EventDispatcher::getInstance();
        $receivedData = null;

        $dispatcher->subscribe('test.event', function($data) use (&$receivedData) {
            $receivedData = $data;
        });

        $dispatcher->dispatch('test.event', ['key' => 'value']);

        $this->assertEquals(['key' => 'value'], $receivedData);
    }

    /**
     * Test dispatch invokes listeners in order
     */
    public function testDispatchInvokesListenersInOrder(): void
    {
        $dispatcher = EventDispatcher::getInstance();
        $callOrder = [];

        $dispatcher->subscribe('test.event', function() use (&$callOrder) {
            $callOrder[] = 'first';
        });
        $dispatcher->subscribe('test.event', function() use (&$callOrder) {
            $callOrder[] = 'second';
        });
        $dispatcher->subscribe('test.event', function() use (&$callOrder) {
            $callOrder[] = 'third';
        });

        $dispatcher->dispatch('test.event');

        $this->assertEquals(['first', 'second', 'third'], $callOrder);
    }

    /**
     * Test dispatch continues after listener exception
     */
    public function testDispatchContinuesAfterListenerException(): void
    {
        $dispatcher = EventDispatcher::getInstance();
        $secondCalled = false;

        $dispatcher->subscribe('test.event', function() {
            throw new \Exception('Test exception');
        });
        $dispatcher->subscribe('test.event', function() use (&$secondCalled) {
            $secondCalled = true;
        });

        $dispatcher->dispatch('test.event');

        $this->assertTrue($secondCalled);
    }

    /**
     * Test dispatch does nothing for unknown event
     */
    public function testDispatchDoesNothingForUnknownEvent(): void
    {
        $dispatcher = EventDispatcher::getInstance();

        // Should not throw
        $dispatcher->dispatch('unknown.event', ['data' => 'test']);

        $this->assertFalse($dispatcher->hasListeners('unknown.event'));
    }

    /**
     * Test unsubscribe removes listener
     */
    public function testUnsubscribeRemovesListener(): void
    {
        $dispatcher = EventDispatcher::getInstance();
        $listener = function() {};

        $dispatcher->subscribe('test.event', $listener);
        $this->assertTrue($dispatcher->hasListeners('test.event'));

        $result = $dispatcher->unsubscribe('test.event', $listener);

        $this->assertTrue($result);
        $this->assertEquals(0, $dispatcher->getListenerCount('test.event'));
    }

    /**
     * Test unsubscribe returns false for unknown listener
     */
    public function testUnsubscribeReturnsFalseForUnknownListener(): void
    {
        $dispatcher = EventDispatcher::getInstance();

        $result = $dispatcher->unsubscribe('test.event', function() {});

        $this->assertFalse($result);
    }

    /**
     * Test unsubscribe returns false for unknown event
     */
    public function testUnsubscribeReturnsFalseForUnknownEvent(): void
    {
        $dispatcher = EventDispatcher::getInstance();

        $result = $dispatcher->unsubscribe('unknown.event', function() {});

        $this->assertFalse($result);
    }

    /**
     * Test hasListeners returns false for unknown event
     */
    public function testHasListenersReturnsFalseForUnknownEvent(): void
    {
        $dispatcher = EventDispatcher::getInstance();

        $this->assertFalse($dispatcher->hasListeners('unknown.event'));
    }

    /**
     * Test getListeners returns array
     */
    public function testGetListenersReturnsArray(): void
    {
        $dispatcher = EventDispatcher::getInstance();
        $listener1 = function() {};
        $listener2 = function() {};

        $dispatcher->subscribe('test.event', $listener1);
        $dispatcher->subscribe('test.event', $listener2);

        $listeners = $dispatcher->getListeners('test.event');

        $this->assertIsArray($listeners);
        $this->assertCount(2, $listeners);
    }

    /**
     * Test getListeners returns empty array for unknown event
     */
    public function testGetListenersReturnsEmptyArrayForUnknownEvent(): void
    {
        $dispatcher = EventDispatcher::getInstance();

        $listeners = $dispatcher->getListeners('unknown.event');

        $this->assertIsArray($listeners);
        $this->assertCount(0, $listeners);
    }

    /**
     * Test clearListeners removes all listeners for event
     */
    public function testClearListenersRemovesAllListenersForEvent(): void
    {
        $dispatcher = EventDispatcher::getInstance();

        $dispatcher->subscribe('test.event', function() {});
        $dispatcher->subscribe('test.event', function() {});
        $dispatcher->subscribe('other.event', function() {});

        $dispatcher->clearListeners('test.event');

        $this->assertFalse($dispatcher->hasListeners('test.event'));
        $this->assertTrue($dispatcher->hasListeners('other.event'));
    }

    /**
     * Test clearAllListeners removes all listeners
     */
    public function testClearAllListenersRemovesAllListeners(): void
    {
        $dispatcher = EventDispatcher::getInstance();

        $dispatcher->subscribe('event1', function() {});
        $dispatcher->subscribe('event2', function() {});
        $dispatcher->subscribe('event3', function() {});

        $dispatcher->clearAllListeners();

        $this->assertFalse($dispatcher->hasListeners('event1'));
        $this->assertFalse($dispatcher->hasListeners('event2'));
        $this->assertFalse($dispatcher->hasListeners('event3'));
    }

    /**
     * Test getListenerCount returns correct count
     */
    public function testGetListenerCountReturnsCorrectCount(): void
    {
        $dispatcher = EventDispatcher::getInstance();

        $this->assertEquals(0, $dispatcher->getListenerCount('test.event'));

        $dispatcher->subscribe('test.event', function() {});
        $this->assertEquals(1, $dispatcher->getListenerCount('test.event'));

        $dispatcher->subscribe('test.event', function() {});
        $this->assertEquals(2, $dispatcher->getListenerCount('test.event'));
    }

    /**
     * Test resetInstance creates new singleton
     */
    public function testResetInstanceCreatesNewSingleton(): void
    {
        $instance1 = EventDispatcher::getInstance();
        $instance1->subscribe('test.event', function() {});

        EventDispatcher::resetInstance();

        $instance2 = EventDispatcher::getInstance();

        $this->assertNotSame($instance1, $instance2);
        $this->assertFalse($instance2->hasListeners('test.event'));
    }

    /**
     * Test __wakeup throws exception
     */
    public function testWakeupThrowsException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot unserialize singleton');

        $dispatcher = EventDispatcher::getInstance();
        $dispatcher->__wakeup();
    }

    /**
     * Test different events are isolated
     */
    public function testDifferentEventsAreIsolated(): void
    {
        $dispatcher = EventDispatcher::getInstance();
        $event1Called = false;
        $event2Called = false;

        $dispatcher->subscribe('event1', function() use (&$event1Called) {
            $event1Called = true;
        });
        $dispatcher->subscribe('event2', function() use (&$event2Called) {
            $event2Called = true;
        });

        $dispatcher->dispatch('event1');

        $this->assertTrue($event1Called);
        $this->assertFalse($event2Called);
    }
}
