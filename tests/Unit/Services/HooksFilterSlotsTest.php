<?php
/**
 * Unit Tests for the Phase-5 filter slots
 *
 * Phase 5 introduces four named filter slots the host fires inside
 * wallet templates. The slots themselves are just strings, but the
 * host code that consumes each one has shape contracts plugins must
 * meet (id/label for tabs, id/html for modal body, label/icon/action
 * for contact actions, id/html/order for dashboard widgets). These
 * tests exercise the registry behavior at each slot's call signature
 * so a contract regression breaks here, not in the browser.
 *
 * See docs/PLUGIN_GUI_HOOKS.md.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\Hooks;

#[CoversClass(Hooks::class)]
class HooksFilterSlotsTest extends TestCase
{
    private Hooks $hooks;

    protected function setUp(): void
    {
        $this->hooks = new Hooks();
    }

    // =========================================================================
    // gui.tabs — array-of-tab-entries pipeline
    // =========================================================================

    public function testGuiTabsFilterCanAddEntry(): void
    {
        $core = [
            ['id' => 'dashboard', 'label' => 'Dashboard', 'order' => 10, 'icon' => 'fas fa-home', 'include' => '/x'],
            ['id' => 'send',      'label' => 'Send',      'order' => 20, 'icon' => 'fas fa-paper-plane', 'include' => '/x'],
        ];

        $this->hooks->onFilter('gui.tabs', function (array $tabs): array {
            $tabs[] = ['id' => 'plugin', 'label' => 'Plugin', 'order' => 25, 'icon' => 'fas fa-puzzle-piece', 'include' => '/x'];
            return $tabs;
        });

        $result = $this->hooks->applyFilter('gui.tabs', $core);
        $this->assertCount(3, $result);
        $this->assertSame('plugin', $result[2]['id']);
    }

    public function testGuiTabsFilterCanRemoveEntry(): void
    {
        $core = [
            ['id' => 'dashboard', 'label' => 'Dashboard', 'order' => 10],
            ['id' => 'settings',  'label' => 'Settings',  'order' => 50],
        ];

        $this->hooks->onFilter('gui.tabs', function (array $tabs): array {
            return array_values(array_filter($tabs, fn($t) => $t['id'] !== 'settings'));
        });

        $result = $this->hooks->applyFilter('gui.tabs', $core);
        $this->assertCount(1, $result);
        $this->assertSame('dashboard', $result[0]['id']);
    }

    public function testGuiTabsFilterReturnsInputUnchangedWhenNoListeners(): void
    {
        $core = [['id' => 'dashboard', 'label' => 'Dashboard', 'order' => 10]];
        $this->assertSame($core, $this->hooks->applyFilter('gui.tabs', $core));
    }

    // =========================================================================
    // gui.contact.actions — array of {label, icon, action} entries
    // =========================================================================

    public function testGuiContactActionsFilterCollectsButtonEntries(): void
    {
        $this->hooks->onFilter('gui.contact.actions', function (array $a): array {
            $a[] = ['label' => 'Bookmark', 'icon' => 'fas fa-star', 'action' => 'myPluginBookmark'];
            return $a;
        });
        $this->hooks->onFilter('gui.contact.actions', function (array $a): array {
            $a[] = ['label' => 'Audit', 'icon' => 'fas fa-eye', 'action' => 'myPluginAudit'];
            return $a;
        });

        $result = $this->hooks->applyFilter('gui.contact.actions', []);
        $this->assertSame(['Bookmark', 'Audit'], array_column($result, 'label'));
        $this->assertSame(['myPluginBookmark', 'myPluginAudit'], array_column($result, 'action'));
    }

    public function testGuiContactActionsFilterPriorityControlsOrder(): void
    {
        $this->hooks->onFilter('gui.contact.actions', function (array $a): array {
            $a[] = ['label' => 'Late',  'icon' => 'x', 'action' => 'a'];
            return $a;
        }, 20);
        $this->hooks->onFilter('gui.contact.actions', function (array $a): array {
            $a[] = ['label' => 'Early', 'icon' => 'x', 'action' => 'b'];
            return $a;
        }, 5);

        $result = $this->hooks->applyFilter('gui.contact.actions', []);
        $this->assertSame(['Early', 'Late'], array_column($result, 'label'));
    }

    // =========================================================================
    // gui.contact_modal.tabs — paired with gui.contact_modal.body
    // =========================================================================

    public function testGuiContactModalTabsAndBodyShareSameId(): void
    {
        $this->hooks->onFilter('gui.contact_modal.tabs', function (array $t): array {
            $t[] = ['id' => 'notes', 'label' => 'Notes', 'icon' => 'fas fa-sticky-note'];
            return $t;
        });
        $this->hooks->onFilter('gui.contact_modal.body', function (array $b): array {
            $b[] = ['id' => 'notes', 'html' => '<p>my notes</p>'];
            return $b;
        });

        $tabs = $this->hooks->applyFilter('gui.contact_modal.tabs', []);
        $body = $this->hooks->applyFilter('gui.contact_modal.body', []);

        $this->assertSame('notes', $tabs[0]['id']);
        $this->assertSame('notes', $body[0]['id']);
        $this->assertStringContainsString('my notes', $body[0]['html']);
    }

    // =========================================================================
    // gui.dashboard.widgets — array of {id, html, order}, host sorts by order
    // =========================================================================

    public function testGuiDashboardWidgetsRetainsAllListenerContributions(): void
    {
        $this->hooks->onFilter('gui.dashboard.widgets', function (array $w): array {
            $w[] = ['id' => 'plugin-a', 'html' => '<div>A</div>', 'order' => 50];
            return $w;
        });
        $this->hooks->onFilter('gui.dashboard.widgets', function (array $w): array {
            $w[] = ['id' => 'plugin-b', 'html' => '<div>B</div>', 'order' => 10];
            return $w;
        });

        $widgets = $this->hooks->applyFilter('gui.dashboard.widgets', []);
        $this->assertCount(2, $widgets);

        // Host sorts by order ascending; emulate the dashboardTab.html
        // sort to verify the sort key the tests document is honored.
        usort($widgets, fn($a, $b) => (int)($a['order'] ?? 100) <=> (int)($b['order'] ?? 100));
        $this->assertSame(['plugin-b', 'plugin-a'], array_column($widgets, 'id'));
    }

    public function testGuiDashboardWidgetsContextCarriesUser(): void
    {
        $captured = null;
        $this->hooks->onFilter('gui.dashboard.widgets', function (array $w, array $ctx) use (&$captured): array {
            $captured = $ctx;
            return $w;
        });

        $this->hooks->applyFilter('gui.dashboard.widgets', [], ['user' => 'alice']);
        $this->assertSame('alice', $captured['user'] ?? null);
    }

    /**
     * A plugin's filter throwing must not poison the pipeline — the
     * value the throwing listener received is the value forwarded to
     * the next listener. This is the same contract Hooks::applyFilter
     * already enforces; the slot tests document it lives at every
     * Phase-5 site.
     */
    public function testGuiTabsFilterListenerThrowsKeepsPriorValue(): void
    {
        $this->hooks->onFilter('gui.tabs', function (array $t): array {
            $t[] = ['id' => 'first', 'label' => 'First', 'order' => 1];
            return $t;
        }, 5);
        $this->hooks->onFilter('gui.tabs', function (array $t): array {
            throw new \RuntimeException('boom');
        }, 10);
        $this->hooks->onFilter('gui.tabs', function (array $t): array {
            $t[] = ['id' => 'last', 'label' => 'Last', 'order' => 99];
            return $t;
        }, 15);

        $result = $this->hooks->applyFilter('gui.tabs', []);
        $this->assertSame(['first', 'last'], array_column($result, 'id'));
    }
}
