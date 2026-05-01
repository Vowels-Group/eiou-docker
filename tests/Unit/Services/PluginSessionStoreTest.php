<?php
/**
 * Unit tests for PluginSessionStore — the namespaced session API
 * for plugin code. Verifies key-namespace enforcement, validation,
 * and that the store can't reach core SessionKeys::* fields.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\PluginSessionStore;

#[CoversClass(PluginSessionStore::class)]
class PluginSessionStoreTest extends TestCase
{
    protected function setUp(): void
    {
        // Fake an active session so set/get write to $_SESSION.
        // Use $_SESSION superglobal directly without session_start()
        // since unit tests can't rely on a real PHP session.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testValidPluginIdAccepted(): void
    {
        $store = new PluginSessionStore('hello-eiou');
        $this->assertSame('plugin_hello-eiou_', $store->getPrefix());
    }

    public function testValidPluginIdWithUnderscores(): void
    {
        $store = new PluginSessionStore('my_plugin');
        $this->assertSame('plugin_my_plugin_', $store->getPrefix());
    }

    public function testInvalidPluginIdRejected(): void
    {
        // Empty / leading hyphen / period / uppercase / path-traversal
        // attempts. Any one of these would let a plugin reshape its
        // namespace.
        foreach (['', '-bad', '.bad', 'Bad', 'foo.bar', 'foo/bar', '../auth'] as $bad) {
            try {
                new PluginSessionStore($bad);
                $this->fail("Should have rejected plugin id: '{$bad}'");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('invalid plugin id', $e->getMessage());
            }
        }
    }

    public function testSetAndGetRoundTripStoresUnderPrefixedKey(): void
    {
        $store = new PluginSessionStore('hello-eiou');
        $store->set('last_fortune', 'A balanced ledger is a quiet ledger.');

        // Internal: stored under the prefix
        $this->assertSame('A balanced ledger is a quiet ledger.', $_SESSION['plugin_hello-eiou_last_fortune']);
        // External: fetched via get() returns the same value
        $this->assertSame('A balanced ledger is a quiet ledger.', $store->get('last_fortune'));
    }

    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $store = new PluginSessionStore('hello-eiou');
        $this->assertSame('fallback', $store->get('missing', 'fallback'));
        $this->assertNull($store->get('missing'));
    }

    public function testUnsetRemovesNamespacedKey(): void
    {
        $store = new PluginSessionStore('hello-eiou');
        $store->set('x', 1);
        $this->assertSame(1, $store->get('x'));

        $store->unset('x');
        $this->assertNull($store->get('x'));
        $this->assertArrayNotHasKey('plugin_hello-eiou_x', $_SESSION);
    }

    public function testClearAllRemovesOnlyOwnNamespacedKeys(): void
    {
        $store = new PluginSessionStore('hello-eiou');
        $store->set('a', 1);
        $store->set('b', 2);

        // Other plugin's keys + core keys must NOT be cleared.
        $_SESSION['plugin_other-plugin_x'] = 99;
        $_SESSION['authenticated'] = true;
        $_SESSION['csrf_token'] = 'abc';

        $store->clearAll();

        $this->assertArrayNotHasKey('plugin_hello-eiou_a', $_SESSION);
        $this->assertArrayNotHasKey('plugin_hello-eiou_b', $_SESSION);
        $this->assertSame(99, $_SESSION['plugin_other-plugin_x'], "must not touch other plugins");
        $this->assertTrue($_SESSION['authenticated'], "must not touch core auth field");
        $this->assertSame('abc', $_SESSION['csrf_token'], "must not touch core CSRF field");
    }

    public function testReservedSuffixesRejected(): void
    {
        $store = new PluginSessionStore('hello-eiou');
        // Even within the plugin's own namespace, suffixes that shadow
        // core session fields are rejected. A confused plugin author
        // can't accidentally set `plugin_hello-eiou_csrf_token` and
        // think they're protecting their flow with an isolated CSRF.
        foreach (['authenticated', 'csrf_token', 'sensitive_access_until', 'auth_time', 'last_regeneration'] as $reserved) {
            try {
                $store->set($reserved, 'x');
                $this->fail("Should have rejected reserved suffix: '{$reserved}'");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('reserved', $e->getMessage());
            }
        }
    }

    public function testInvalidKeyShapeRejected(): void
    {
        $store = new PluginSessionStore('hello-eiou');
        // Path-traversal / SESSION-key-control-char attempts
        foreach (['', '../auth', 'a.b', 'a[b]', 'a b', "\0null"] as $bad) {
            try {
                $store->set($bad, 'x');
                $this->fail("Should have rejected key: '" . bin2hex($bad) . "'");
            } catch (\InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function testKeyTooLongRejected(): void
    {
        $store = new PluginSessionStore('hello-eiou');
        $tooLong = str_repeat('a', 65);

        $this->expectException(\InvalidArgumentException::class);
        $store->set($tooLong, 'x');
    }

    public function testStoreCannotReachCoreSessionKeys(): void
    {
        // Demonstrates the contract: even if a plugin tried every key
        // in the validation surface, none would land outside the
        // plugin_<id>_ namespace. Set a sentinel core value first;
        // try to scribble over it via every legal store key; assert
        // sentinel survives.
        $_SESSION['authenticated'] = 'core-value';

        $store = new PluginSessionStore('attacker');
        $store->set('foo', 'plugin-write');
        $store->set('bar_baz', 'plugin-write-2');
        $store->set('a-b-c', 'plugin-write-3');

        $this->assertSame('core-value', $_SESSION['authenticated']);
        $this->assertArrayHasKey('plugin_attacker_foo', $_SESSION);
        $this->assertArrayHasKey('plugin_attacker_bar_baz', $_SESSION);
        $this->assertArrayHasKey('plugin_attacker_a-b-c', $_SESSION);
    }
}
