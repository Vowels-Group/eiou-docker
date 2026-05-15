<?php
/**
 * Unit Tests for Hooks
 *
 * Covers the WordPress-style render + filter registry that lets
 * plugins inject HTML and transform host-side values without
 * modifying core templates. See docs/PLUGINS.md "Extending the GUI" for the
 * full design.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Core\AppConfig;
use Eiou\Services\Hooks;

#[CoversClass(Hooks::class)]
class HooksTest extends TestCase
{
    private Hooks $hooks;

    protected function setUp(): void
    {
        $this->hooks = new Hooks(AppConfig::fromEnvironment());
    }

    // =========================================================================
    // Render hooks
    // =========================================================================

    /**
     * Empty hook fast-path returns '' immediately. The whole point of
     * the empty-hook check is to make scattering hook fires through
     * wallet.html cheap enough that we don't have to gate them at
     * call sites.
     */
    public function testDoRenderReturnsEmptyStringWhenNoListenersRegistered(): void
    {
        $this->assertSame('', $this->hooks->doRender('gui.dashboard.after'));
    }

    public function testDoRenderConcatenatesListenerOutputInRegistrationOrder(): void
    {
        $this->hooks->onRender('gui.x', fn() => '<a/>');
        $this->hooks->onRender('gui.x', fn() => '<b/>');
        $this->hooks->onRender('gui.x', fn() => '<c/>');

        $this->assertSame('<a/><b/><c/>', $this->hooks->doRender('gui.x'));
    }

    /**
     * Lower priority runs first. Critical for plugin authors who need
     * to inject before / after the host's own listeners (host registers
     * at priority 10, plugins can register at 5 to come before).
     */
    public function testDoRenderHonoursPriorityOrdering(): void
    {
        $this->hooks->onRender('gui.x', fn() => 'B', 10);
        $this->hooks->onRender('gui.x', fn() => 'A', 5);
        $this->hooks->onRender('gui.x', fn() => 'C', 20);

        $this->assertSame('ABC', $this->hooks->doRender('gui.x'));
    }

    /**
     * Within a single priority, registration order wins. Documented as
     * part of the contract — plugins relying on this can use a higher
     * priority instead, but stable ordering keeps the host's own
     * default-priority listeners predictable.
     */
    public function testDoRenderPreservesRegistrationOrderAtSamePriority(): void
    {
        $this->hooks->onRender('gui.x', fn() => '1', 10);
        $this->hooks->onRender('gui.x', fn() => '2', 10);
        $this->hooks->onRender('gui.x', fn() => '3', 10);

        $this->assertSame('123', $this->hooks->doRender('gui.x'));
    }

    /**
     * Listener context is forwarded verbatim. The host typically passes
     * the current user / row / request payload; listeners that don't
     * need it can ignore the parameter.
     */
    public function testDoRenderForwardsContextToListeners(): void
    {
        $captured = null;
        $this->hooks->onRender('gui.x', function (array $ctx) use (&$captured) {
            $captured = $ctx;
            return '';
        });

        $this->hooks->doRender('gui.x', ['user' => 'alice', 'count' => 3]);

        $this->assertSame(['user' => 'alice', 'count' => 3], $captured);
    }

    /**
     * Non-string return values are skipped — the listener's
     * misbehaviour isn't allowed to corrupt the output. The contract
     * is "return a string"; anything else logs (in production) and
     * is treated as an empty contribution.
     */
    public function testDoRenderSkipsListenersThatReturnNonString(): void
    {
        $this->hooks->onRender('gui.x', fn() => '<a/>');
        $this->hooks->onRender('gui.x', fn() => null);
        $this->hooks->onRender('gui.x', fn() => 42);
        $this->hooks->onRender('gui.x', fn() => '<b/>');

        $this->assertSame('<a/><b/>', $this->hooks->doRender('gui.x'));
    }

    /**
     * A listener that throws gets logged + skipped. The remaining
     * listeners still run; the host gets back whatever was
     * accumulated up to the point of the throw plus whatever later
     * listeners contribute. Symmetric with EventDispatcher.
     */
    public function testDoRenderContinuesAfterListenerException(): void
    {
        $this->hooks->onRender('gui.x', fn() => '<a/>');
        $this->hooks->onRender('gui.x', function () {
            throw new \RuntimeException('boom');
        });
        $this->hooks->onRender('gui.x', fn() => '<b/>');

        $out = $this->hooks->doRender('gui.x');
        $this->assertSame('<a/><b/>', $out);
    }

    // =========================================================================
    // Filter hooks
    // =========================================================================

    public function testApplyFilterReturnsValueUnchangedWhenNoListeners(): void
    {
        $this->assertSame(['x'], $this->hooks->applyFilter('gui.tabs', ['x']));
    }

    public function testApplyFilterChainsValueThroughListeners(): void
    {
        $this->hooks->onFilter('gui.tabs', fn(array $v) => array_merge($v, ['b']));
        $this->hooks->onFilter('gui.tabs', fn(array $v) => array_merge($v, ['c']));

        $this->assertSame(['a', 'b', 'c'], $this->hooks->applyFilter('gui.tabs', ['a']));
    }

    public function testApplyFilterHonoursPriority(): void
    {
        // Priority 5 runs first — gets ['x'], appends 'first'.
        // Priority 10 runs second — sees ['x','first'], appends 'second'.
        $this->hooks->onFilter('gui.x', fn(array $v) => array_merge($v, ['second']), 10);
        $this->hooks->onFilter('gui.x', fn(array $v) => array_merge($v, ['first']), 5);

        $this->assertSame(['x', 'first', 'second'], $this->hooks->applyFilter('gui.x', ['x']));
    }

    public function testApplyFilterForwardsContext(): void
    {
        $captured = null;
        $this->hooks->onFilter('gui.x', function ($v, array $ctx) use (&$captured) {
            $captured = $ctx;
            return $v;
        });

        $this->hooks->applyFilter('gui.x', 1, ['hint' => 'live']);
        $this->assertSame(['hint' => 'live'], $captured);
    }

    /**
     * If a filter listener throws, the value seen by the next listener
     * is the value from BEFORE the throwing listener ran — we can't
     * trust a half-mutated return from a thrower. Other listeners
     * aren't punished.
     */
    public function testApplyFilterSkipsThrowerAndKeepsPreviousValue(): void
    {
        $this->hooks->onFilter('gui.x', fn(array $v) => array_merge($v, ['ok']));
        $this->hooks->onFilter('gui.x', function (array $v) {
            // Doesn't matter what we return — exception aborts.
            throw new \RuntimeException('boom');
        });
        $this->hooks->onFilter('gui.x', fn(array $v) => array_merge($v, ['after']));

        $this->assertSame(['ok', 'after'], $this->hooks->applyFilter('gui.x', []));
    }

    // =========================================================================
    // Inspection helpers
    // =========================================================================

    public function testListRenderHooksReturnsSubscribedHookNames(): void
    {
        $this->hooks->onRender('gui.dashboard.after', fn() => '');
        $this->hooks->onRender('gui.head.styles', fn() => '');

        $names = $this->hooks->listRenderHooks();
        sort($names);
        $this->assertSame(['gui.dashboard.after', 'gui.head.styles'], $names);
    }

    public function testListFilterHooksReturnsSubscribedHookNames(): void
    {
        $this->hooks->onFilter('gui.tabs', fn($v) => $v);
        $names = $this->hooks->listFilterHooks();
        $this->assertSame(['gui.tabs'], $names);
    }

    /**
     * Render and filter registries don't bleed into each other —
     * registering a render listener under name X must not affect
     * applyFilter(X) and vice versa.
     */
    public function testRenderAndFilterRegistriesAreIndependent(): void
    {
        $this->hooks->onRender('shared.name', fn() => 'rendered');
        $this->hooks->onFilter('shared.name', fn($v) => 'filtered');

        $this->assertSame('rendered', $this->hooks->doRender('shared.name'));
        $this->assertSame('filtered', $this->hooks->applyFilter('shared.name', 'orig'));
    }

    // =========================================================================
    // Dev-mode tracing (PLUGIN_HOOKS_TRACE)
    // =========================================================================

    /**
     * Default state is opt-in: with the env flag off, the trace buffer
     * stays empty even when many fires happen. Plugin authors enable
     * the flag in dev mode without paying for it in production.
     */
    public function testTraceIsEmptyWhenFlagDisabled(): void
    {
        $this->hooks->setTraceEnabled(false);
        $this->hooks->onRender('gui.x', fn() => 'hi');
        $this->hooks->onFilter('gui.tabs', fn($v) => $v);

        $this->hooks->doRender('gui.x');
        $this->hooks->applyFilter('gui.tabs', []);
        $this->hooks->doRender('gui.empty');

        $this->assertSame([], $this->hooks->getTrace());
    }

    /**
     * With the flag on, every fire (including empty fast-path fires)
     * appends a trace entry. That's the whole point — plugin authors
     * grep the log for which hooks the host actually calls.
     */
    public function testTraceCapturesEveryFireWhenFlagEnabled(): void
    {
        $this->hooks->setTraceEnabled(true);
        $this->hooks->onRender('gui.x', fn() => 'hi');
        $this->hooks->onRender('gui.x', fn() => 'lo');
        $this->hooks->onFilter('gui.tabs', fn($v) => $v);

        $this->hooks->doRender('gui.x');
        $this->hooks->doRender('gui.empty');
        $this->hooks->applyFilter('gui.tabs', ['a']);

        $trace = $this->hooks->getTrace();
        $this->assertCount(3, $trace);
        $this->assertSame(['render', 'gui.x',     2, 0], [$trace[0]['kind'], $trace[0]['hook'], $trace[0]['listeners'], $trace[0]['errors']]);
        $this->assertSame(['render', 'gui.empty', 0, 0], [$trace[1]['kind'], $trace[1]['hook'], $trace[1]['listeners'], $trace[1]['errors']]);
        $this->assertSame(['filter', 'gui.tabs',  1, 0], [$trace[2]['kind'], $trace[2]['hook'], $trace[2]['listeners'], $trace[2]['errors']]);
    }

    /**
     * Trace counts errors separately from listeners so dev-mode logs
     * call attention to misbehaving plugins (errors > 0) without
     * obscuring the fact that the listener was wired up.
     */
    public function testTraceCountsListenerExceptionsAsErrors(): void
    {
        $this->hooks->setTraceEnabled(true);
        $this->hooks->onRender('gui.x', fn() => 'ok');
        $this->hooks->onRender('gui.x', function () { throw new \RuntimeException('boom'); });

        $this->hooks->doRender('gui.x');

        $trace = $this->hooks->getTrace();
        $this->assertCount(1, $trace);
        $this->assertSame(2, $trace[0]['listeners']);
        $this->assertSame(1, $trace[0]['errors']);
    }

    public function testClearTraceResetsBuffer(): void
    {
        $this->hooks->setTraceEnabled(true);
        $this->hooks->doRender('gui.x');
        $this->assertCount(1, $this->hooks->getTrace());

        $this->hooks->clearTrace();
        $this->assertSame([], $this->hooks->getTrace());
    }
}
