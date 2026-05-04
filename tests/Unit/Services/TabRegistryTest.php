<?php
/**
 * Unit Tests for TabRegistry
 *
 * The registry that drives the wallet GUI's tab nav + panels. The 5
 * core tabs are registered by the host on every request; plugins
 * register their own. wallet.html iterates `all()` to build the
 * desktop nav, the mobile nav, and the panel sections from a single
 * source of truth. See docs/PLUGINS.md "Extending the GUI".
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\TabRegistry;

#[CoversClass(TabRegistry::class)]
class TabRegistryTest extends TestCase
{
    private TabRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new TabRegistry();
    }

    private function tab(array $overrides = []): array
    {
        return array_merge([
            'id' => 'demo', 'label' => 'Demo', 'icon' => 'fas fa-flask',
            'order' => 10, 'include' => '/tmp/x.html',
        ], $overrides);
    }

    public function testRegisterAcceptsValidEntry(): void
    {
        $this->assertTrue($this->registry->register($this->tab()));
        $this->assertCount(1, $this->registry->all());
    }

    public function testRegisterRejectsMissingRequiredField(): void
    {
        $bad = $this->tab();
        unset($bad['icon']);
        $this->assertFalse($this->registry->register($bad));
        $this->assertEmpty($this->registry->all());
    }

    public function testRegisterRejectsInvalidId(): void
    {
        $this->assertFalse($this->registry->register($this->tab(['id' => 'Bad ID'])));
        $this->assertFalse($this->registry->register($this->tab(['id' => 'CamelCase'])));
        $this->assertFalse($this->registry->register($this->tab(['id' => ''])));
    }

    public function testRegisterRequiresIncludeOrRender(): void
    {
        $bad = $this->tab();
        unset($bad['include']);
        $this->assertFalse($this->registry->register($bad));

        $good = $this->tab();
        unset($good['include']);
        $good['render'] = fn() => '<div></div>';
        $this->assertTrue($this->registry->register($good));
    }

    public function testRegisterRejectsNonCallableRender(): void
    {
        $bad = $this->tab(['include' => null, 'render' => 'not-a-function']);
        $this->assertFalse($this->registry->register($bad));
    }

    /**
     * Sorting by `order` ascending. Critical for the wallet nav: core
     * tabs use 10 / 20 / 30 / 40 / 50 so plugins can slot at 25 to
     * land between Payment and Contacts, or at 100 to land after
     * Settings.
     */
    public function testAllSortsByOrderAscending(): void
    {
        $this->registry->register($this->tab(['id' => 'c', 'order' => 30]));
        $this->registry->register($this->tab(['id' => 'a', 'order' => 10]));
        $this->registry->register($this->tab(['id' => 'b', 'order' => 20]));

        $ids = array_column($this->registry->all(), 'id');
        $this->assertSame(['a', 'b', 'c'], $ids);
    }

    public function testAllPreservesRegistrationOrderAtSameOrder(): void
    {
        $this->registry->register($this->tab(['id' => 'first',  'order' => 10]));
        $this->registry->register($this->tab(['id' => 'second', 'order' => 10]));
        $this->registry->register($this->tab(['id' => 'third',  'order' => 10]));

        $ids = array_column($this->registry->all(), 'id');
        $this->assertSame(['first', 'second', 'third'], $ids);
    }

    /**
     * Re-registering an existing id replaces the prior entry. Lets a
     * plugin override a core tab (e.g. ship a richer Dashboard)
     * without forking the host.
     */
    public function testRegisterIdCollisionReplacesEntry(): void
    {
        $this->registry->register($this->tab(['id' => 'dashboard', 'label' => 'Original']));
        $this->registry->register($this->tab(['id' => 'dashboard', 'label' => 'Override']));

        $entries = $this->registry->all();
        $this->assertCount(1, $entries);
        $this->assertSame('Override', $entries[0]['label']);
    }

    public function testFindReturnsRegisteredEntry(): void
    {
        $this->registry->register($this->tab(['id' => 'demo']));
        $this->assertSame('demo', $this->registry->find('demo')['id']);
        $this->assertNull($this->registry->find('missing'));
    }

    public function testNormalizeFallsBackMobileLabelToLabel(): void
    {
        $this->registry->register($this->tab(['label' => 'Activity']));
        $this->assertSame('Activity', $this->registry->find('demo')['mobileLabel']);
    }

    public function testNormalizeKeepsExplicitMobileLabel(): void
    {
        $this->registry->register($this->tab(['label' => 'Dashboard', 'mobileLabel' => 'Home']));
        $this->assertSame('Home', $this->registry->find('demo')['mobileLabel']);
    }

    // =========================================================================
    // resolveBadge — int + callable + thrower handling
    // =========================================================================

    public function testResolveBadgeWithInt(): void
    {
        $this->assertSame(7, TabRegistry::resolveBadge(['badge' => 7, 'id' => 'x']));
    }

    public function testResolveBadgeWithCallable(): void
    {
        $this->assertSame(3, TabRegistry::resolveBadge([
            'badge' => fn() => 3,
            'id' => 'x',
        ]));
    }

    public function testResolveBadgeReturnsZeroOnThrower(): void
    {
        // A plugin's badge query that throws shouldn't crash the page.
        // resolveBadge logs + falls back to 0.
        $this->assertSame(0, TabRegistry::resolveBadge([
            'badge' => fn() => throw new \RuntimeException('x'),
            'id' => 'demo',
        ]));
    }

    public function testResolveBadgeReturnsZeroForMissingOrNonPositive(): void
    {
        $this->assertSame(0, TabRegistry::resolveBadge(['id' => 'x']));
        $this->assertSame(0, TabRegistry::resolveBadge(['id' => 'x', 'badge' => 0]));
        $this->assertSame(0, TabRegistry::resolveBadge(['id' => 'x', 'badge' => -1]));
        $this->assertSame(0, TabRegistry::resolveBadge(['id' => 'x', 'badge' => 'string']));
    }
}
