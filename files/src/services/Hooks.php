<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\AppConfig;
use Eiou\Utils\Logger;

/**
 * Hooks — WordPress-style render + filter registry for the GUI.
 *
 * Two complementary primitives:
 *
 *   - Render hooks (do_action style): fire-and-collect. Each listener
 *     returns an HTML string; the host concatenates them and emits the
 *     result at the hook fire site. Used for plugins to inject sections
 *     into existing templates.
 *
 *   - Filter hooks (apply_filters style): value pipeline. Each listener
 *     receives the (possibly already-modified) value from the previous
 *     listener and returns the next stage. The host gets back the final
 *     value to render. Used for plugins to mutate lists / shape host
 *     output without raw HTML.
 *
 * Listeners register at a numeric priority (default 10). Lower priority
 * runs first; ties fall back to registration order.
 *
 * Listener exceptions are logged and skipped. Remaining listeners run
 * normally — symmetric with EventDispatcher's contract so plugin
 * authors aren't surprised.
 *
 * Empty-hook fast path: doRender / applyFilter are O(1) when no
 * listener is registered (single array_key_exists check). This matters
 * because the host will pepper wallet.html with hook fires that most
 * plugins won't subscribe to.
 *
 * The service is intentionally separate from EventDispatcher even
 * though both register named callbacks: EventDispatcher's listeners
 * are fire-and-forget (return values ignored), while hooks rely on
 * return values for their entire usefulness. Mixing the two would
 * surprise existing event subscribers.
 *
 * See docs/PLUGIN_GUI_HOOKS.md for the full design.
 */
class Hooks
{
    /** @var array<string, array<int, callable[]>> render-hook listeners keyed by hook name then priority */
    private array $renderListeners = [];

    /** @var array<string, array<int, callable[]>> filter-hook listeners keyed by hook name then priority */
    private array $filterListeners = [];

    /**
     * Opt-in trace log (env: PLUGIN_HOOKS_TRACE=1). Each fire appends
     * an entry — useful for plugin authors discovering which hooks
     * exist + their order without reading templates. Kept request-
     * scoped so tests can assert on it; never persisted.
     *
     * @var array<int, array{kind:string,hook:string,listeners:int,errors:int}>
     */
    private array $trace = [];

    /** Cached PLUGIN_HOOKS_TRACE check — read once per instance. */
    private ?bool $traceEnabled = null;

    /** Source of truth for the trace flag — read once at first call. */
    private AppConfig $appConfig;

    public function __construct(AppConfig $appConfig)
    {
        $this->appConfig = $appConfig;
    }

    /**
     * Register a render-hook listener. Listener signature:
     *   function (array $context): string
     *
     * The context array carries whatever the host passed at the fire
     * site — typically the current user, the row being rendered, or
     * the request payload. Listeners that don't need it can ignore it.
     *
     * Returning '' (or anything non-string that casts to empty) is a
     * no-op for that listener; useful when conditionally injecting.
     *
     * @param string   $hook     Dot-separated hook name (e.g. gui.dashboard.after)
     * @param callable $listener Returns HTML string
     * @param int      $priority Lower runs first (default 10)
     */
    public function onRender(string $hook, callable $listener, int $priority = 10): void
    {
        $this->renderListeners[$hook][$priority][] = $listener;
    }

    /**
     * Register a filter-hook listener. Listener signature:
     *   function (mixed $value, array $context): mixed
     *
     * Each listener receives the value from the previous stage and
     * must return the value for the next stage. Type discipline is
     * the listener's responsibility — if the host fires with an
     * array, returning a string would break downstream listeners
     * (and probably the host too).
     *
     * @param string   $hook
     * @param callable $listener
     * @param int      $priority
     */
    public function onFilter(string $hook, callable $listener, int $priority = 10): void
    {
        $this->filterListeners[$hook][$priority][] = $listener;
    }

    /**
     * Fire a render hook: collect HTML strings from each listener (in
     * priority order), concatenate, and return. Empty listener-list
     * returns '' immediately.
     *
     * Listener exceptions are caught + logged + skipped; a single
     * misbehaving plugin can't take down the page.
     */
    public function doRender(string $hook, array $context = []): string
    {
        if (!isset($this->renderListeners[$hook])) {
            if ($this->isTraceEnabled()) {
                $this->recordTrace('render', $hook, 0, 0);
            }
            return '';
        }
        $out = '';
        $count = 0;
        $errors = 0;
        foreach ($this->iterateByPriority($this->renderListeners[$hook]) as $listener) {
            $count++;
            try {
                $piece = $listener($context);
                if (is_string($piece) && $piece !== '') {
                    $out .= $piece;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->logListenerError('render', $hook, $e);
            }
        }
        if ($this->isTraceEnabled()) {
            $this->recordTrace('render', $hook, $count, $errors);
        }
        return $out;
    }

    /**
     * Fire a filter hook: chain the value through each listener (in
     * priority order) and return the final value. Empty listener-list
     * returns the input value unchanged.
     *
     * If a listener throws, its proposed transformation is discarded
     * (exception logged) and the chain continues with the previous
     * value. Other listeners aren't punished for one's misbehavior.
     *
     * @param string $hook
     * @param mixed  $value   Initial value
     * @param array  $context
     * @return mixed Final value after the pipeline
     */
    public function applyFilter(string $hook, mixed $value, array $context = []): mixed
    {
        if (!isset($this->filterListeners[$hook])) {
            if ($this->isTraceEnabled()) {
                $this->recordTrace('filter', $hook, 0, 0);
            }
            return $value;
        }
        $count = 0;
        $errors = 0;
        foreach ($this->iterateByPriority($this->filterListeners[$hook]) as $listener) {
            $count++;
            try {
                $value = $listener($value, $context);
            } catch (\Throwable $e) {
                $errors++;
                $this->logListenerError('filter', $hook, $e);
                // Keep $value at the pre-listener state — can't trust
                // a half-mutated return from a thrower.
            }
        }
        if ($this->isTraceEnabled()) {
            $this->recordTrace('filter', $hook, $count, $errors);
        }
        return $value;
    }

    /**
     * Inspection helper for tests / dev-mode tooling. Not for hot path.
     *
     * @return string[] Hook names with at least one render listener
     */
    public function listRenderHooks(): array
    {
        return array_keys($this->renderListeners);
    }

    /**
     * @return string[] Hook names with at least one filter listener
     */
    public function listFilterHooks(): array
    {
        return array_keys($this->filterListeners);
    }

    /**
     * Opt-in dev-mode trace of every fire (set PLUGIN_HOOKS_TRACE=1).
     * Each entry records `kind` (render|filter), `hook`, `listeners`
     * (count actually invoked), and `errors` (how many threw). Use
     * this to discover hooks at runtime instead of grepping templates.
     *
     * Empty when the env flag is off — costs zero.
     *
     * @return array<int, array{kind:string,hook:string,listeners:int,errors:int}>
     */
    public function getTrace(): array
    {
        return $this->trace;
    }

    /**
     * Test-only: clear the trace buffer between assertions. Production
     * code shouldn't need this — the trace is request-scoped and dies
     * with the process.
     */
    public function clearTrace(): void
    {
        $this->trace = [];
    }

    /**
     * Force-set the trace flag (test seam). NULL re-evaluates the env
     * variable on next fire.
     */
    public function setTraceEnabled(?bool $enabled): void
    {
        $this->traceEnabled = $enabled;
    }

    private function isTraceEnabled(): bool
    {
        if ($this->traceEnabled !== null) {
            return $this->traceEnabled;
        }
        $this->traceEnabled = $this->appConfig->pluginHooksTrace;
        return $this->traceEnabled;
    }

    private function recordTrace(string $kind, string $hook, int $listeners, int $errors): void
    {
        $this->trace[] = [
            'kind'      => $kind,
            'hook'      => $hook,
            'listeners' => $listeners,
            'errors'    => $errors,
        ];
        // Mirror to the logger so plugin authors can `tail -f` the
        // wallet log instead of plumbing a debug page. info-level so
        // production logs aren't noisy unless the operator opts in.
        try {
            Logger::getInstance()->info(
                "Hooks: {$kind} fire '{$hook}' ({$listeners} listeners, {$errors} errors)",
                ['hook' => $hook, 'kind' => $kind, 'listeners' => $listeners, 'errors' => $errors]
            );
        } catch (\Throwable $_) {
            // Logger unavailable in test scaffolding — swallow.
        }
    }

    /**
     * Yields each listener in priority order (ascending). Listeners
     * within the same priority preserve registration order.
     *
     * @param array<int, callable[]> $byPriority
     * @return \Generator
     */
    private function iterateByPriority(array $byPriority): \Generator
    {
        ksort($byPriority);
        foreach ($byPriority as $listeners) {
            foreach ($listeners as $listener) {
                yield $listener;
            }
        }
    }

    private function logListenerError(string $kind, string $hook, \Throwable $e): void
    {
        try {
            Logger::getInstance()->warning(
                "Hooks: {$kind} listener threw on '{$hook}'",
                ['error' => $e->getMessage(), 'hook' => $hook, 'kind' => $kind]
            );
        } catch (\Throwable $_) {
            // Logger itself unavailable (test scaffolding) — swallow.
        }
    }
}
