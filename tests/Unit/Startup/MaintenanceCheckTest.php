<?php
/**
 * Unit Tests for MaintenanceCheck content negotiation.
 *
 * Covers the two helper functions that decide between the HTML
 * maintenance page and the JSON maintenance response based on the
 * request's Accept header. The lockfile-guarded response emission
 * itself (headers + exit) is verified via integration/manual testing
 * — exit is not unit-testable.
 */

namespace Eiou\Tests\Startup;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNothing]
class MaintenanceCheckTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Ensure the lockfile does NOT exist before we require the file —
        // the require triggers the entry-point branch when the lockfile is
        // present and would call exit(). Functions are defined
        // unconditionally so requiring without the lockfile just registers
        // the helpers.
        if (file_exists('/tmp/eiou_maintenance.lock')) {
            self::markTestSkippedForClass('Maintenance lockfile present — cannot load MaintenanceCheck safely in a unit test process');
        }
        require_once __DIR__ . '/../../../files/src/startup/MaintenanceCheck.php';
    }

    private static function markTestSkippedForClass(string $reason): void
    {
        // PHPUnit 11's TestCase::markTestSkipped is instance-only. We use
        // a skipped phpt-style exit here by throwing a skip exception.
        throw new \PHPUnit\Framework\SkippedTestSuiteError($reason);
    }

    // ---------- eiou_maintenance_prefers_html ----------

    public static function acceptHeaderProvider(): array
    {
        return [
            'empty header falls to JSON' => ['', false],
            'wildcard only falls to JSON' => ['*/*', false],
            'explicit application/json' => ['application/json', false],
            'typical API client' => ['application/json, */*', false],
            'typical browser (Chrome)' => [
                'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                true,
            ],
            'typical browser (Firefox)' => [
                'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                true,
            ],
            'case-insensitive text/HTML' => ['TEXT/HTML', true],
            'curl default (missing)' => ['', false],
            'html only' => ['text/html', true],
        ];
    }

    #[DataProvider('acceptHeaderProvider')]
    public function testPrefersHtml(string $accept, bool $expected): void
    {
        $this->assertSame($expected, eiou_maintenance_prefers_html($accept));
    }

    // ---------- eiou_maintenance_html_page ----------

    public function testHtmlPageContainsMaintenanceHeading(): void
    {
        $html = eiou_maintenance_html_page();
        $this->assertStringContainsString('Under Maintenance', $html);
    }

    public function testHtmlPageIncludesUserFacingExplanation(): void
    {
        // The user asked for copy along the lines of "starting up or going
        // through update, check back in a little bit". Lock both phrases
        // in so a future tweak of the copy doesn't silently lose them.
        $html = eiou_maintenance_html_page();
        $this->assertStringContainsString('starting up or going through an update', $html);
        $this->assertStringContainsString('Check back in a little bit', $html);
    }

    public function testHtmlPageAutoRefreshes(): void
    {
        $html = eiou_maintenance_html_page();
        $this->assertMatchesRegularExpression(
            '/<meta\s+http-equiv="refresh"\s+content="15">/i',
            $html,
            'Page must auto-refresh so the user doesn\'t have to manually reload'
        );
    }

    public function testHtmlPageIsFullySelfContained(): void
    {
        // No external CSS/JS/fonts — the app may be mid-rebuild so none of
        // its own assets are guaranteed resolvable during maintenance.
        $html = eiou_maintenance_html_page();
        $this->assertStringNotContainsString('<link rel="stylesheet"', $html);
        $this->assertStringNotContainsString('<script src=', $html);
        $this->assertStringNotContainsString('@import', $html);
        // Inline <style> is expected and fine.
        $this->assertStringContainsString('<style>', $html);
    }

    public function testHtmlPageIsAccessible(): void
    {
        $html = eiou_maintenance_html_page();
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('role="main"', $html);
        $this->assertStringContainsString('aria-labelledby="title"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        // Respect prefers-reduced-motion for the spinner.
        $this->assertStringContainsString('prefers-reduced-motion', $html);
    }

    public function testHtmlPageIsMobileFriendly(): void
    {
        $html = eiou_maintenance_html_page();
        $this->assertStringContainsString(
            'name="viewport"',
            $html,
            'Must declare a viewport for mobile browsers'
        );
    }
}
