<?php
/**
 * Unit Tests for UpdateCheckService
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\UpdateCheckService;
use Eiou\Core\Constants;

#[CoversClass(UpdateCheckService::class)]
class UpdateCheckServiceTest extends TestCase
{
    /**
     * Test isNewerVersion with newer version
     */
    public function testIsNewerVersionReturnsTrueForNewerVersion(): void
    {
        $this->assertTrue(UpdateCheckService::isNewerVersion('0.2.0', '0.1.5-alpha'));
        $this->assertTrue(UpdateCheckService::isNewerVersion('0.1.6-alpha', '0.1.5-alpha'));
        $this->assertTrue(UpdateCheckService::isNewerVersion('1.0.0', '0.1.5-alpha'));
    }

    /**
     * Test isNewerVersion with same version
     */
    public function testIsNewerVersionReturnsFalseForSameVersion(): void
    {
        $this->assertFalse(UpdateCheckService::isNewerVersion('0.1.5-alpha', '0.1.5-alpha'));
    }

    /**
     * Test isNewerVersion with older version
     */
    public function testIsNewerVersionReturnsFalseForOlderVersion(): void
    {
        $this->assertFalse(UpdateCheckService::isNewerVersion('0.1.4-alpha', '0.1.5-alpha'));
        $this->assertFalse(UpdateCheckService::isNewerVersion('0.1.0', '0.1.5-alpha'));
    }

    /**
     * Test isNewerVersion strips v prefix
     */
    public function testIsNewerVersionStripsVPrefix(): void
    {
        $this->assertTrue(UpdateCheckService::isNewerVersion('v0.2.0', '0.1.5-alpha'));
        $this->assertTrue(UpdateCheckService::isNewerVersion('v0.2.0', 'v0.1.5-alpha'));
        $this->assertFalse(UpdateCheckService::isNewerVersion('v0.1.5-alpha', 'v0.1.5-alpha'));
    }

    /**
     * Test prerelease ordering (alpha < beta < stable)
     */
    public function testPrereleaseOrdering(): void
    {
        $this->assertTrue(UpdateCheckService::isNewerVersion('0.1.5-beta', '0.1.5-alpha'));
        $this->assertTrue(UpdateCheckService::isNewerVersion('0.1.5', '0.1.5-alpha'));
        $this->assertTrue(UpdateCheckService::isNewerVersion('0.1.5', '0.1.5-beta'));
    }

    /**
     * Test getStatus returns expected keys
     */
    public function testGetStatusReturnsExpectedKeys(): void
    {
        $status = UpdateCheckService::getStatus();

        $this->assertArrayHasKey('available', $status);
        $this->assertArrayHasKey('current_version', $status);
        $this->assertArrayHasKey('latest_version', $status);
        $this->assertArrayHasKey('last_checked', $status);
        $this->assertArrayHasKey('source', $status);
        $this->assertArrayHasKey('error', $status);
    }

    /**
     * Test getStatus returns current version
     */
    public function testGetStatusReturnsCurrentVersion(): void
    {
        $status = UpdateCheckService::getStatus();
        $this->assertEquals(Constants::APP_VERSION, $status['current_version']);
    }

    /**
     * Test getStatus returns boolean for available
     */
    public function testGetStatusAvailableIsBoolean(): void
    {
        $status = UpdateCheckService::getStatus();
        $this->assertIsBool($status['available']);
    }

    /**
     * Test getCached returns null when no cache file exists
     */
    public function testGetCachedReturnsNullWithoutCacheFile(): void
    {
        if (file_exists('/etc/eiou/config/update-check.json')) {
            $this->markTestSkipped('Cache file exists (running in Docker)');
        }

        $this->assertNull(UpdateCheckService::getCached());
    }

    // ==================== markdownToHtml tests ====================

    /**
     * Test headings are converted to HTML heading tags
     */
    public function testMarkdownToHtmlConvertsHeadings(): void
    {
        $this->assertSame('<h1>Title</h1>', UpdateCheckService::markdownToHtml('# Title'));
        $this->assertSame('<h2>Subtitle</h2>', UpdateCheckService::markdownToHtml('## Subtitle'));
        $this->assertSame('<h3>Section</h3>', UpdateCheckService::markdownToHtml('### Section'));
    }

    /**
     * Test unordered list items are wrapped in <ul>/<li>
     */
    public function testMarkdownToHtmlConvertsUnorderedLists(): void
    {
        $md = "- First\n- Second\n- Third";
        $expected = '<ul><li>First</li><li>Second</li><li>Third</li></ul>';
        $this->assertSame($expected, UpdateCheckService::markdownToHtml($md));
    }

    /**
     * Test ordered list items are wrapped in <ol>/<li>
     */
    public function testMarkdownToHtmlConvertsOrderedLists(): void
    {
        $md = "1. Alpha\n2. Beta\n3. Gamma";
        $expected = '<ol><li>Alpha</li><li>Beta</li><li>Gamma</li></ol>';
        $this->assertSame($expected, UpdateCheckService::markdownToHtml($md));
    }

    /**
     * Test bold text is converted to <strong>
     */
    public function testMarkdownToHtmlConvertsBold(): void
    {
        $this->assertSame('<p><strong>bold</strong> text</p>', UpdateCheckService::markdownToHtml('**bold** text'));
    }

    /**
     * Test italic text is converted to <em>
     */
    public function testMarkdownToHtmlConvertsItalic(): void
    {
        $this->assertSame('<p><em>italic</em> text</p>', UpdateCheckService::markdownToHtml('*italic* text'));
    }

    /**
     * Test inline code is converted to <code>
     */
    public function testMarkdownToHtmlConvertsInlineCode(): void
    {
        $this->assertSame('<p>Run <code>docker pull</code> now</p>', UpdateCheckService::markdownToHtml('Run `docker pull` now'));
    }

    /**
     * Test fenced code blocks are converted to <pre><code>
     */
    public function testMarkdownToHtmlConvertsCodeBlocks(): void
    {
        $md = "```\necho hello\necho world\n```";
        $expected = '<pre><code>echo hello' . "\n" . 'echo world' . "\n" . '</code></pre>';
        $this->assertSame($expected, UpdateCheckService::markdownToHtml($md));
    }

    /**
     * Test links are converted to anchor tags with target=_blank
     */
    public function testMarkdownToHtmlConvertsLinks(): void
    {
        $md = '[release notes](https://github.com/example)';
        $result = UpdateCheckService::markdownToHtml($md);
        $this->assertStringContainsString('href="https://github.com/example"', $result);
        $this->assertStringContainsString('target="_blank"', $result);
        $this->assertStringContainsString('rel="noopener noreferrer"', $result);
        $this->assertStringContainsString('>release notes</a>', $result);
    }

    /**
     * Test non-http links are stripped to plain text (XSS prevention)
     */
    public function testMarkdownToHtmlRejectsNonHttpLinks(): void
    {
        $md = '[click](javascript:alert(1))';
        $result = UpdateCheckService::markdownToHtml($md);
        $this->assertStringNotContainsString('<a ', $result);
        $this->assertStringNotContainsString('javascript:', $result);
    }

    /**
     * Test horizontal rules are converted to <hr>
     */
    public function testMarkdownToHtmlConvertsHorizontalRules(): void
    {
        $this->assertSame('<hr>', UpdateCheckService::markdownToHtml('---'));
        $this->assertSame('<hr>', UpdateCheckService::markdownToHtml('***'));
        $this->assertSame('<hr>', UpdateCheckService::markdownToHtml('___'));
    }

    /**
     * Test plain text is wrapped in <p> tags
     */
    public function testMarkdownToHtmlWrapsParagraphs(): void
    {
        $this->assertSame('<p>Hello world</p>', UpdateCheckService::markdownToHtml('Hello world'));
    }

    /**
     * Test HTML entities are escaped (XSS prevention)
     */
    public function testMarkdownToHtmlEscapesHtml(): void
    {
        $md = '<script>alert("xss")</script>';
        $result = UpdateCheckService::markdownToHtml($md);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    /**
     * Test a realistic GitHub release body with mixed elements
     */
    public function testMarkdownToHtmlHandlesRealisticReleaseNotes(): void
    {
        $md = "## What's Changed\n\n"
            . "### New Features\n"
            . "- **P2P routing**: Added multi-hop relay support\n"
            . "- Improved `docker-compose` startup time\n\n"
            . "### Bug Fixes\n"
            . "- Fixed chain gap detection\n\n"
            . "Full changelog: [v0.1.11...v0.1.12](https://github.com/example/compare)";

        $result = UpdateCheckService::markdownToHtml($md);

        $this->assertStringContainsString("<h2>What&#039;s Changed</h2>", $result);
        $this->assertStringContainsString('<h3>New Features</h3>', $result);
        $this->assertStringContainsString('<strong>P2P routing</strong>', $result);
        $this->assertStringContainsString('<code>docker-compose</code>', $result);
        $this->assertStringContainsString('<h3>Bug Fixes</h3>', $result);
        $this->assertStringContainsString('<li>Fixed chain gap detection</li>', $result);
        $this->assertStringContainsString('href="https://github.com/example/compare"', $result);
    }

    /**
     * Test empty input returns empty string
     */
    public function testMarkdownToHtmlHandlesEmptyInput(): void
    {
        $this->assertSame('', UpdateCheckService::markdownToHtml(''));
    }

    /**
     * Wrapped bullet — continuation lines must fold into the preceding <li>
     * rather than breaking off into their own paragraphs (which would also
     * close the enclosing <ul> prematurely).
     */
    public function testMarkdownToHtmlFoldsLazyContinuationIntoListItem(): void
    {
        $md = "- First bullet that wraps onto\n"
            . "  a second line of prose\n"
            . "- Second bullet";

        $result = UpdateCheckService::markdownToHtml($md);

        $this->assertSame(
            '<ul>'
            . '<li>First bullet that wraps onto a second line of prose</li>'
            . '<li>Second bullet</li>'
            . '</ul>',
            $result
        );
    }

    /**
     * Multi-line continuation — a long bullet with several wrapped lines stays
     * a single <li>; the <ul> is not closed until a real list-terminator
     * (blank line, heading, hr, end of input) is reached.
     */
    public function testMarkdownToHtmlFoldsMultipleContinuationLines(): void
    {
        $md = "- Payment requests archival — resolved payment requests older than\n"
            . "  the retention window move nightly to a payment_requests_archive table.\n"
            . "  Nightly backup excludes archive tables via mysqldump --ignore-table\n"
            . "- Next bullet";

        $result = UpdateCheckService::markdownToHtml($md);

        $this->assertStringContainsString(
            '<li>Payment requests archival — resolved payment requests older than '
            . 'the retention window move nightly to a payment_requests_archive table. '
            . 'Nightly backup excludes archive tables via mysqldump --ignore-table</li>',
            $result
        );
        $this->assertStringContainsString('<li>Next bullet</li>', $result);
        $this->assertSame(1, substr_count($result, '<ul>'));
        $this->assertSame(1, substr_count($result, '</ul>'));
    }

    /**
     * Blank line still terminates the list — a continuation line separated
     * from the bullet by an empty line is a new paragraph, not a fold.
     */
    public function testMarkdownToHtmlBlankLineEndsListBeforeContinuation(): void
    {
        $md = "- Bullet\n\nNext paragraph";

        $result = UpdateCheckService::markdownToHtml($md);

        $this->assertStringContainsString('<li>Bullet</li></ul>', $result);
        $this->assertStringContainsString('<p>Next paragraph</p>', $result);
    }

    /**
     * Test list type switch (ul → ol) closes the previous list
     */
    public function testMarkdownToHtmlHandlesListTypeSwitch(): void
    {
        $md = "- bullet\n\n1. numbered";
        $result = UpdateCheckService::markdownToHtml($md);
        $this->assertStringContainsString('</ul>', $result);
        $this->assertStringContainsString('<ol>', $result);
    }

    /**
     * Test unclosed code block is auto-closed
     */
    public function testMarkdownToHtmlClosesUnclosedCodeBlock(): void
    {
        $md = "```\nsome code";
        $result = UpdateCheckService::markdownToHtml($md);
        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('</code></pre>', $result);
    }

    // ==================== shouldShowWhatsNew / dismissWhatsNew tests ====================

    /**
     * Test shouldShowWhatsNew returns true on first run for a set-up node
     * whose seen-file doesn't exist yet (upgraded from a pre-feature
     * version, or freshly set up).
     */
    public function testShouldShowWhatsNewReturnsTrueWhenSeenFileMissingButNodeSetUp(): void
    {
        $seenFile = '/etc/eiou/config/whats-new-seen.json';
        $userConfig = '/etc/eiou/config/userconfig.json';
        if (!is_dir('/etc/eiou/config') || !is_writable('/etc/eiou/config')) {
            $this->markTestSkipped('Config directory not writable (not running in Docker)');
        }
        if (!file_exists($userConfig)) {
            $this->markTestSkipped('userconfig.json not present (node not set up in this test environment)');
        }

        $hadSeen = file_exists($seenFile);
        $backup = $hadSeen ? file_get_contents($seenFile) : null;
        @unlink($seenFile);

        try {
            $this->assertTrue(UpdateCheckService::shouldShowWhatsNew());
            // Must not auto-seed — that would hide the banner immediately
            $this->assertFileDoesNotExist($seenFile);
        } finally {
            if ($hadSeen && $backup !== null) {
                file_put_contents($seenFile, $backup);
            } else {
                @unlink($seenFile);
            }
        }
    }

    /**
     * Test shouldShowWhatsNew returns false for a pre-setup container
     * (neither the seen-file nor userconfig.json exist).
     */
    public function testShouldShowWhatsNewReturnsFalseWhenPreSetup(): void
    {
        $seenFile = '/etc/eiou/config/whats-new-seen.json';
        $userConfig = '/etc/eiou/config/userconfig.json';
        if (!is_dir('/etc/eiou/config') || !is_writable('/etc/eiou/config')) {
            $this->markTestSkipped('Config directory not writable (not running in Docker)');
        }
        if (file_exists($userConfig) && !is_writable($userConfig)) {
            $this->markTestSkipped('userconfig.json not writable');
        }

        $hadSeen = file_exists($seenFile);
        $seenBackup = $hadSeen ? file_get_contents($seenFile) : null;
        $hadUserConfig = file_exists($userConfig);
        $userConfigBackup = $hadUserConfig ? file_get_contents($userConfig) : null;

        @unlink($seenFile);
        if ($hadUserConfig) {
            @unlink($userConfig);
        }

        try {
            $this->assertFalse(UpdateCheckService::shouldShowWhatsNew());
            // Must not silently seed the seen-file either
            $this->assertFileDoesNotExist($seenFile);
        } finally {
            @unlink($seenFile);
            if ($hadSeen && $seenBackup !== null) {
                file_put_contents($seenFile, $seenBackup);
            }
            if ($hadUserConfig && $userConfigBackup !== null) {
                file_put_contents($userConfig, $userConfigBackup);
            }
        }
    }

    /**
     * Test shouldShowWhatsNew returns true when file has a different version
     */
    public function testShouldShowWhatsNewReturnsTrueAfterUpgrade(): void
    {
        $seenFile = '/etc/eiou/config/whats-new-seen.json';
        if (!is_dir('/etc/eiou/config') || !is_writable('/etc/eiou/config')) {
            $this->markTestSkipped('Config directory not writable (not running in Docker)');
        }

        // Simulate old version
        file_put_contents($seenFile, json_encode([
            'dismissed_version' => '0.0.1-fake',
            'dismissed_at' => date('c'),
        ]));

        $this->assertTrue(UpdateCheckService::shouldShowWhatsNew());

        // Clean up
        @unlink($seenFile);
    }

    /**
     * Test shouldShowWhatsNew returns false after dismissal
     */
    public function testShouldShowWhatsNewReturnsFalseAfterDismissal(): void
    {
        $seenFile = '/etc/eiou/config/whats-new-seen.json';
        if (!is_dir('/etc/eiou/config') || !is_writable('/etc/eiou/config')) {
            $this->markTestSkipped('Config directory not writable (not running in Docker)');
        }

        // Simulate old version
        file_put_contents($seenFile, json_encode([
            'dismissed_version' => '0.0.1-fake',
            'dismissed_at' => date('c'),
        ]));

        // Should show before dismissal
        $this->assertTrue(UpdateCheckService::shouldShowWhatsNew());

        // Dismiss
        UpdateCheckService::dismissWhatsNew();

        // Should not show after dismissal
        $this->assertFalse(UpdateCheckService::shouldShowWhatsNew());

        // Verify file contents
        $data = json_decode(file_get_contents($seenFile), true);
        $this->assertSame(Constants::APP_VERSION, $data['dismissed_version']);
        $this->assertArrayHasKey('dismissed_at', $data);

        // Clean up
        @unlink($seenFile);
    }

    /**
     * Test dismissWhatsNew creates the file with correct structure
     */
    public function testDismissWhatsNewWritesCorrectStructure(): void
    {
        $seenFile = '/etc/eiou/config/whats-new-seen.json';
        if (!is_dir('/etc/eiou/config') || !is_writable('/etc/eiou/config')) {
            $this->markTestSkipped('Config directory not writable (not running in Docker)');
        }

        // Remove if exists
        @unlink($seenFile);

        UpdateCheckService::dismissWhatsNew();

        $this->assertFileExists($seenFile);
        $data = json_decode(file_get_contents($seenFile), true);
        $this->assertIsArray($data);
        $this->assertSame(Constants::APP_VERSION, $data['dismissed_version']);
        $this->assertArrayHasKey('dismissed_at', $data);
        // dismissed_at should be a valid ISO 8601 date
        $this->assertNotFalse(strtotime($data['dismissed_at']));

        // Clean up
        @unlink($seenFile);
    }

    // ==================== getWhatsNewVariant tests ====================

    /**
     * Test getWhatsNewVariant returns 'fresh' when no seen file exists and
     * userconfig is present (node has never dismissed a banner).
     */
    public function testGetWhatsNewVariantReturnsFreshOnFirstRun(): void
    {
        $seenFile = '/etc/eiou/config/whats-new-seen.json';
        $userConfig = '/etc/eiou/config/userconfig.json';
        if (!is_dir('/etc/eiou/config') || !is_writable('/etc/eiou/config')) {
            $this->markTestSkipped('Config directory not writable (not running in Docker)');
        }
        if (!file_exists($userConfig)) {
            $this->markTestSkipped('userconfig.json not present (node not set up in this test environment)');
        }

        $hadSeen = file_exists($seenFile);
        $backup = $hadSeen ? file_get_contents($seenFile) : null;
        @unlink($seenFile);

        try {
            $this->assertSame('fresh', UpdateCheckService::getWhatsNewVariant());
            // Must stay a pure read — no side-effect file write.
            $this->assertFileDoesNotExist($seenFile);
        } finally {
            if ($hadSeen && $backup !== null) {
                file_put_contents($seenFile, $backup);
            } else {
                @unlink($seenFile);
            }
        }
    }

    /**
     * Test getWhatsNewVariant returns 'upgraded' when first_seen_version
     * predates the current APP_VERSION (node dismissed a previous version's
     * banner, now on a newer version).
     */
    public function testGetWhatsNewVariantReturnsUpgradedWhenFirstSeenOlder(): void
    {
        $seenFile = '/etc/eiou/config/whats-new-seen.json';
        $userConfig = '/etc/eiou/config/userconfig.json';
        if (!is_dir('/etc/eiou/config') || !is_writable('/etc/eiou/config')) {
            $this->markTestSkipped('Config directory not writable (not running in Docker)');
        }
        if (!file_exists($userConfig)) {
            $this->markTestSkipped('userconfig.json not present');
        }

        $hadSeen = file_exists($seenFile);
        $backup = $hadSeen ? file_get_contents($seenFile) : null;

        file_put_contents($seenFile, json_encode([
            'first_seen_version' => '0.0.1-fake-old',
            'dismissed_version' => '0.0.1-fake-old',
            'dismissed_at' => date('c'),
        ]));

        try {
            $this->assertSame('upgraded', UpdateCheckService::getWhatsNewVariant());
        } finally {
            if ($hadSeen && $backup !== null) {
                file_put_contents($seenFile, $backup);
            } else {
                @unlink($seenFile);
            }
        }
    }

    /**
     * Test getWhatsNewVariant returns null when the current version's
     * banner has already been dismissed.
     */
    public function testGetWhatsNewVariantReturnsNullWhenDismissed(): void
    {
        $seenFile = '/etc/eiou/config/whats-new-seen.json';
        $userConfig = '/etc/eiou/config/userconfig.json';
        if (!is_dir('/etc/eiou/config') || !is_writable('/etc/eiou/config')) {
            $this->markTestSkipped('Config directory not writable (not running in Docker)');
        }
        if (!file_exists($userConfig)) {
            $this->markTestSkipped('userconfig.json not present');
        }

        $hadSeen = file_exists($seenFile);
        $backup = $hadSeen ? file_get_contents($seenFile) : null;

        file_put_contents($seenFile, json_encode([
            'first_seen_version' => Constants::APP_VERSION,
            'dismissed_version' => Constants::APP_VERSION,
            'dismissed_at' => date('c'),
        ]));

        try {
            $this->assertNull(UpdateCheckService::getWhatsNewVariant());
        } finally {
            if ($hadSeen && $backup !== null) {
                file_put_contents($seenFile, $backup);
            } else {
                @unlink($seenFile);
            }
        }
    }

    /**
     * Test dismissWhatsNew backfills first_seen_version on a file that was
     * written by a pre-variant version of the code (no first_seen field).
     * This keeps later upgrades classifiable as 'upgraded' rather than
     * misclassified as 'fresh'.
     */
    public function testDismissWhatsNewBackfillsFirstSeenVersion(): void
    {
        $seenFile = '/etc/eiou/config/whats-new-seen.json';
        if (!is_dir('/etc/eiou/config') || !is_writable('/etc/eiou/config')) {
            $this->markTestSkipped('Config directory not writable (not running in Docker)');
        }

        $hadSeen = file_exists($seenFile);
        $backup = $hadSeen ? file_get_contents($seenFile) : null;

        @unlink($seenFile);

        try {
            UpdateCheckService::dismissWhatsNew();
            $data = json_decode(file_get_contents($seenFile), true);
            $this->assertSame(Constants::APP_VERSION, $data['first_seen_version']);
            $this->assertSame(Constants::APP_VERSION, $data['dismissed_version']);
        } finally {
            if ($hadSeen && $backup !== null) {
                file_put_contents($seenFile, $backup);
            } else {
                @unlink($seenFile);
            }
        }
    }

    // ==================== getReleaseNotes tests ====================

    /**
     * Test getReleaseNotes returns null when GitHub is unreachable (non-Docker environment)
     */
    public function testGetReleaseNotesReturnsNullWhenUnreachable(): void
    {
        // In test environment without network, this should return null gracefully
        // (no exception thrown)
        $result = UpdateCheckService::getReleaseNotes('99.99.99-nonexistent');
        // May return null (no network) or null (tag not found) — either is correct
        $this->assertTrue($result === null || is_array($result));
    }

    /**
     * Test getReleaseNotes strips v prefix from version
     */
    public function testGetReleaseNotesStripsVPrefix(): void
    {
        // Ensure v-prefixed and bare versions are treated the same
        // Both should fail gracefully for a nonexistent version
        $result1 = UpdateCheckService::getReleaseNotes('v99.99.99');
        $result2 = UpdateCheckService::getReleaseNotes('99.99.99');
        $this->assertSame($result1, $result2);
    }

    /**
     * Test getReleaseNotes returns correct structure when data is available
     * (runs only inside Docker with network access)
     */
    public function testGetReleaseNotesReturnsExpectedKeysWhenAvailable(): void
    {
        // Try to fetch notes for a known release
        $result = UpdateCheckService::getReleaseNotes(Constants::APP_VERSION);
        if ($result === null) {
            $this->markTestSkipped('Cannot reach GitHub (Tor-only or no network)');
        }

        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('body_html', $result);
        $this->assertArrayHasKey('published_at', $result);
        $this->assertArrayHasKey('html_url', $result);
        $this->assertNotEmpty($result['body_html']);
        $this->assertSame(ltrim(Constants::APP_VERSION, 'v'), $result['version']);
    }
}
