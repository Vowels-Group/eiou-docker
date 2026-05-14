<?php
namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\PluginsTabPanelRegistry;

#[CoversClass(PluginsTabPanelRegistry::class)]
class PluginsTabPanelRegistryTest extends TestCase
{
    private PluginsTabPanelRegistry $svc;

    protected function setUp(): void
    {
        $this->svc = new PluginsTabPanelRegistry();
    }

    private function validEntry(string $pluginId, string $label = 'Demo', ?int $order = null, ?string $icon = null): array
    {
        $e = [
            'plugin_id' => $pluginId,
            'label'     => $label,
            'render'    => fn(): string => "<p>{$pluginId} body</p>",
        ];
        if ($order !== null) $e['order'] = $order;
        if ($icon !== null)  $e['icon']  = $icon;
        return $e;
    }

    // =========================================================================
    // register() — happy path + validation
    // =========================================================================

    public function testRegisterAcceptsValidEntry(): void
    {
        $this->assertTrue($this->svc->register($this->validEntry('hello-eiou')));
        $this->assertSame('hello-eiou', $this->svc->find('hello-eiou')['plugin_id']);
    }

    public function testRegisterDefaultsIconAndOrder(): void
    {
        $this->svc->register($this->validEntry('demo'));
        $row = $this->svc->find('demo');
        $this->assertSame('fas fa-puzzle-piece', $row['icon']);
        $this->assertSame(100, $row['order']);
    }

    public function testRegisterRejectsMissingPluginId(): void
    {
        $entry = $this->validEntry('demo');
        unset($entry['plugin_id']);
        $this->assertFalse($this->svc->register($entry));
        $this->assertTrue($this->svc->isEmpty());
    }

    public function testRegisterRejectsInvalidPluginIdShape(): void
    {
        $this->assertFalse($this->svc->register($this->validEntry('Bad-ID')));
        $this->assertFalse($this->svc->register($this->validEntry('-leading-dash')));
        $this->assertFalse($this->svc->register($this->validEntry('contains space')));
    }

    public function testRegisterRejectsEmptyLabel(): void
    {
        $this->assertFalse($this->svc->register($this->validEntry('demo', '')));
    }

    public function testRegisterRejectsNonCallableRender(): void
    {
        $entry = $this->validEntry('demo');
        $entry['render'] = 'not-callable';
        $this->assertFalse($this->svc->register($entry));
    }

    public function testRegisterRejectsNonIntOrder(): void
    {
        $entry = $this->validEntry('demo');
        $entry['order'] = '100';
        $this->assertFalse($this->svc->register($entry));
    }

    // =========================================================================
    // last-write-wins on plugin_id collision
    // =========================================================================

    public function testRegisterReplacesPriorEntryOnSamePluginId(): void
    {
        $this->svc->register($this->validEntry('demo', 'First'));
        $this->svc->register($this->validEntry('demo', 'Second'));
        $this->assertCount(1, $this->svc->all());
        $this->assertSame('Second', $this->svc->find('demo')['label']);
    }

    // =========================================================================
    // all() — sorted by order asc, then plugin_id asc for stable ties
    // =========================================================================

    public function testAllReturnsEntriesSortedByOrderThenPluginId(): void
    {
        // Register out-of-order; assert the sort puts them right.
        $this->svc->register($this->validEntry('zeta', 'Zeta', 200));
        $this->svc->register($this->validEntry('alpha', 'Alpha', 50));
        $this->svc->register($this->validEntry('mid-b', 'Mid-B', 100));
        $this->svc->register($this->validEntry('mid-a', 'Mid-A', 100));

        $ids = array_column($this->svc->all(), 'plugin_id');
        $this->assertSame(
            ['alpha', 'mid-a', 'mid-b', 'zeta'],
            $ids,
            'order: alpha(50) → mid-a(100) → mid-b(100) → zeta(200); within order=100 sort by plugin_id ascending'
        );
    }

    // =========================================================================
    // renderPanel() — closure invocation + throw isolation
    // =========================================================================

    public function testRenderPanelReturnsClosureOutput(): void
    {
        $this->svc->register([
            'plugin_id' => 'demo',
            'label'     => 'Demo',
            'render'    => fn(): string => '<p>hi</p>',
        ]);
        $this->assertSame('<p>hi</p>', $this->svc->renderPanel('demo'));
    }

    public function testRenderPanelReturnsEmptyOnUnknownId(): void
    {
        $this->assertSame('', $this->svc->renderPanel('not-registered'));
    }

    public function testRenderPanelSwallowsClosureThrows(): void
    {
        // A buggy plugin's render closure must not take down the
        // host's render path. Match the resolveBadge posture from
        // TabRegistry — swallow + log + return empty.
        $this->svc->register([
            'plugin_id' => 'broken',
            'label'     => 'Broken',
            'render'    => function (): string {
                throw new \RuntimeException('boom');
            },
        ]);
        $this->assertSame('', $this->svc->renderPanel('broken'));
    }

    public function testRenderPanelCoercesNonStringReturnToEmpty(): void
    {
        // A closure returning the wrong type would otherwise emit
        // gibberish into the page HTML. Coerce to empty so the
        // dispatch contract is enforced.
        $this->svc->register([
            'plugin_id' => 'wrong-type',
            'label'     => 'Wrong',
            'render'    => fn(): array => ['not-a-string'],
        ]);
        $this->assertSame('', $this->svc->renderPanel('wrong-type'));
    }

    // =========================================================================
    // isEmpty() — drives the empty-state in the Plugins tab
    // =========================================================================

    public function testIsEmptyReflectsRegistrationState(): void
    {
        $this->assertTrue($this->svc->isEmpty());
        $this->svc->register($this->validEntry('demo'));
        $this->assertFalse($this->svc->isEmpty());
    }
}
