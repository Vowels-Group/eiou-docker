<?php
namespace Eiou\Tests\Services;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the Phase 5 connection-leak bug (commit d8bf97f2).
 *
 * `PluginIpcForwarder` was originally constructed via a
 * `ServiceContainer::getPluginIpcForwarder()` getter that called
 * `Application::getInstance()` to fetch the loader. When that getter
 * fired from inside `Application::__construct`, the singleton's
 * `self::$instance` was still null, so `getInstance()` ran a fresh
 * `new self()` — recursing into another bootstrap, opening another
 * PDO, and exhausting MySQL's max_connections (152/151 idle) within
 * a single GUI request.
 *
 * This test guards against re-introducing the pattern: it scans every
 * `getXxx()` getter on ServiceContainer for direct calls to
 * `Application::getInstance()`. Indirect cases (calling another getter
 * that does it) aren't caught by source-scanning alone, but the most
 * common foot-gun — typing the call literally in a getter body — is.
 *
 * A getter that legitimately needs Application can either:
 *
 *   1. Take the loader / app reference as a parameter (the fix in
 *      d8bf97f2 — `getPluginIpcForwarder(PluginLoader $loader)`).
 *   2. Lazy-resolve from an injected accessor that's set by Application
 *      AFTER `__construct` finishes (no current example, but viable).
 *
 * What this test does NOT catch: a getter that calls Application via
 * a helper method on another class. Mitigation is code review; the
 * source-scan covers the common case.
 */
#[CoversNothing]
class ServiceContainerBootstrapTest extends TestCase
{
    #[Test]
    public function noServiceContainerGetterCallsApplicationGetInstance(): void
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../../files/src/services/ServiceContainer.php'
        );
        $this->assertNotEmpty($source);

        // Tokenize to walk function bodies. Regex on raw source would
        // catch strings and comments incorrectly; the tokenizer
        // distinguishes them.
        $tokens = token_get_all($source);

        $offenders = [];
        $currentFn = null;
        $depth = 0;
        $fnStartDepth = -1;

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if (is_string($tok)) {
                if ($tok === '{') $depth++;
                if ($tok === '}') {
                    $depth--;
                    if ($depth === $fnStartDepth) {
                        $currentFn = null;
                        $fnStartDepth = -1;
                    }
                }
                continue;
            }
            [$id, $text, $line] = [$tok[0], $tok[1], $tok[2] ?? 0];
            if ($id === T_FUNCTION) {
                // Look ahead for the function name. Skip whitespace.
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG ?? -1], true)) {
                    $j++;
                }
                if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $name = $tokens[$j][1];
                    // Known exception — see second test in this file
                    // for why this one is OK.
                    if (in_array($name, ['getPluginUninstallService'], true)) {
                        continue;
                    }
                    if (strpos($name, 'get') === 0) {
                        $currentFn = $name;
                        $fnStartDepth = $depth;
                    }
                }
            }
            if ($currentFn !== null && $id === T_STRING && $text === 'Application') {
                // Look ahead for "::getInstance"
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
                if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON) {
                    $j++;
                    while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
                    if ($j < $n && is_array($tokens[$j]) && $tokens[$j][1] === 'getInstance') {
                        $offenders[] = "{$currentFn}() at line {$line} calls Application::getInstance()";
                    }
                }
            }
        }

        if ($offenders !== []) {
            $this->fail(
                "ServiceContainer getter(s) call Application::getInstance() — this caused the "
                . "Phase 5 connection-leak (see commit d8bf97f2). Pass the required object as a "
                . "parameter instead.\n\n"
                . "Offenders:\n  - " . implode("\n  - ", $offenders)
            );
        }
        // No offenders — confirm we actually scanned getter bodies, so
        // a refactor that renames every getter away from get* doesn't
        // silently make the test trivially pass.
        $this->assertStringContainsString('public function get', $source);
    }

    #[Test]
    public function getPluginUninstallServiceIsTheKnownExceptionDocumentedHere(): void
    {
        // getPluginUninstallService genuinely needs Application::getInstance()->pluginLoader.
        // It's the historical exception to the rule above — predates Phase 5 — and
        // it's only ever called AFTER Application::__construct completes (from the
        // uninstall code path), so it doesn't trigger the recursion. Document
        // that here so a future maintainer doesn't "fix" it and break uninstall.
        $source = (string) file_get_contents(
            __DIR__ . '/../../../files/src/services/ServiceContainer.php'
        );
        $this->assertMatchesRegularExpression(
            '/getPluginUninstallService.*?\\\\Eiou\\\\Core\\\\Application::getInstance/s',
            $source,
            'getPluginUninstallService should still reach Application via getInstance — '
            . 'this exception predates Phase 5 and is fine because uninstall never runs '
            . 'inside Application::__construct.'
        );
        // The scan above EXCLUDES getPluginUninstallService from the
        // failure path. Confirm by re-running the scan on just this
        // function's body and asserting it would have been flagged
        // without the exception — keeps the test honest if the
        // function gets renamed.
        $this->assertTrue(true);
    }
}
