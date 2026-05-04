<?php
/**
 * Unit Tests for GuiActionRegistry
 *
 * Covers the registry that replaces Functions.php's hardcoded action
 * whitelist. Plugins register POST handlers here; Functions.php
 * dispatches via has() + the per-tier gates the registry exposes.
 * See docs/PLUGINS.md "Extending the GUI".
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\GuiActionRegistry;

#[CoversClass(GuiActionRegistry::class)]
class GuiActionRegistryTest extends TestCase
{
    private GuiActionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new GuiActionRegistry();
    }

    // =========================================================================
    // register() — validation + storage
    // =========================================================================

    public function testRegisterAcceptsValidEntry(): void
    {
        $ok = $this->registry->register('myPluginPing', fn() => null);
        $this->assertTrue($ok);
        $this->assertTrue($this->registry->has('myPluginPing'));
    }

    /**
     * Action names must be camelCase (matching existing convention:
     * `addContact`, `sendEIOU`, `getDebugReportJson`). The registry
     * rejects shapes that look like attribute selectors or smuggled
     * tokens so a malicious plugin can't pollute the namespace.
     */
    public function testRegisterRejectsInvalidActionNames(): void
    {
        $h = fn() => null;
        $this->assertFalse($this->registry->register('',           $h));
        $this->assertFalse($this->registry->register('Bad Name',   $h));
        $this->assertFalse($this->registry->register('UpperFirst', $h));
        $this->assertFalse($this->registry->register('with-dash',  $h));
        $this->assertFalse($this->registry->register('with_underscore', $h));
        $this->assertFalse($this->registry->register('1leadingDigit',   $h));
        $this->assertFalse($this->registry->register('with.dot',   $h));

        $this->assertEmpty($this->registry->listActions());
    }

    public function testRegisterRejectsInvalidTier(): void
    {
        $this->assertFalse($this->registry->register('foo', fn() => null, 'admin'));
        $this->assertFalse($this->registry->register('foo', fn() => null, ''));
        $this->assertFalse($this->registry->register('foo', fn() => null, 'CSRF'));
    }

    public function testRegisterAcceptsAllValidTiers(): void
    {
        $h = fn() => null;
        $this->assertTrue($this->registry->register('aPub',   $h, GuiActionRegistry::TIER_PUBLIC));
        $this->assertTrue($this->registry->register('aAuth',  $h, GuiActionRegistry::TIER_AUTH));
        $this->assertTrue($this->registry->register('aCsrf',  $h, GuiActionRegistry::TIER_CSRF));
        $this->assertTrue($this->registry->register('aSens',  $h, GuiActionRegistry::TIER_SENSITIVE));
    }

    public function testRegisterDefaultsToCsrfTier(): void
    {
        $this->registry->register('foo', fn() => null);
        $this->assertSame(GuiActionRegistry::TIER_CSRF, $this->registry->getTier('foo'));
    }

    /**
     * Re-registering an existing action replaces the prior entry. Lets
     * a plugin override a core action (e.g. ship a richer sendEIOU
     * flow) without forking the host.
     */
    public function testRegisterCollisionReplacesEntry(): void
    {
        $first  = fn() => 'first';
        $second = fn() => 'second';

        $this->registry->register('addContact', $first, GuiActionRegistry::TIER_CSRF, 'core');
        $this->registry->register('addContact', $second, GuiActionRegistry::TIER_SENSITIVE, 'plugin-a');

        $this->assertSame('second', ($this->registry->getHandler('addContact'))());
        $this->assertSame(GuiActionRegistry::TIER_SENSITIVE, $this->registry->getTier('addContact'));
        $this->assertSame('plugin-a', $this->registry->getPluginId('addContact'));
        $this->assertSame(['addContact'], $this->registry->listActions());
    }

    // =========================================================================
    // Lookups
    // =========================================================================

    public function testHasReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->registry->has('nope'));
    }

    public function testGetTierAndHandlerReturnNullForUnknown(): void
    {
        $this->assertNull($this->registry->getTier('nope'));
        $this->assertNull($this->registry->getHandler('nope'));
        $this->assertNull($this->registry->getPluginId('nope'));
    }

    public function testGetPluginIdNullWhenNotProvided(): void
    {
        $this->registry->register('foo', fn() => null);
        $this->assertNull($this->registry->getPluginId('foo'));
    }

    public function testListActionsPreservesRegistrationOrder(): void
    {
        $h = fn() => null;
        $this->registry->register('alpha', $h);
        $this->registry->register('bravo', $h);
        $this->registry->register('charlie', $h);

        $this->assertSame(['alpha', 'bravo', 'charlie'], $this->registry->listActions());
    }

    // =========================================================================
    // Tier-gate predicates — what the dispatcher actually consumes
    // =========================================================================

    public function testRequiresCsrfMatchesCsrfAndSensitiveTiers(): void
    {
        $h = fn() => null;
        $this->registry->register('aPub',  $h, GuiActionRegistry::TIER_PUBLIC);
        $this->registry->register('aAuth', $h, GuiActionRegistry::TIER_AUTH);
        $this->registry->register('aCsrf', $h, GuiActionRegistry::TIER_CSRF);
        $this->registry->register('aSens', $h, GuiActionRegistry::TIER_SENSITIVE);

        $this->assertFalse($this->registry->requiresCsrf('aPub'));
        $this->assertFalse($this->registry->requiresCsrf('aAuth'));
        $this->assertTrue($this->registry->requiresCsrf('aCsrf'));
        $this->assertTrue($this->registry->requiresCsrf('aSens'));
    }

    public function testRequiresSensitiveAccessMatchesOnlySensitiveTier(): void
    {
        $h = fn() => null;
        $this->registry->register('aPub',  $h, GuiActionRegistry::TIER_PUBLIC);
        $this->registry->register('aAuth', $h, GuiActionRegistry::TIER_AUTH);
        $this->registry->register('aCsrf', $h, GuiActionRegistry::TIER_CSRF);
        $this->registry->register('aSens', $h, GuiActionRegistry::TIER_SENSITIVE);

        $this->assertFalse($this->registry->requiresSensitiveAccess('aPub'));
        $this->assertFalse($this->registry->requiresSensitiveAccess('aAuth'));
        $this->assertFalse($this->registry->requiresSensitiveAccess('aCsrf'));
        $this->assertTrue($this->registry->requiresSensitiveAccess('aSens'));
    }

    /**
     * For unknown actions both predicates return false — the dispatcher
     * is expected to gate on has() first, but a defensive false is
     * safer than a null-deref or throw.
     */
    public function testTierPredicatesReturnFalseForUnknownAction(): void
    {
        $this->assertFalse($this->registry->requiresCsrf('nope'));
        $this->assertFalse($this->registry->requiresSensitiveAccess('nope'));
    }

    // =========================================================================
    // Handler invocation — the registry doesn't run handlers itself,
    // but the handler stored under getHandler() must be the one passed
    // in (callable preserved as-is, no wrapping).
    // =========================================================================

    public function testGetHandlerInvokesOriginalCallableWithRequest(): void
    {
        $captured = null;
        $this->registry->register('myPing', function (array $req) use (&$captured) {
            $captured = $req;
        });

        ($this->registry->getHandler('myPing'))(['action' => 'myPing', 'foo' => 'bar']);
        $this->assertSame(['action' => 'myPing', 'foo' => 'bar'], $captured);
    }
}
