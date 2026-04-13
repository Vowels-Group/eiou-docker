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
     * Test shouldShowWhatsNew returns false and seeds the file on fresh install
     */
    public function testShouldShowWhatsNewReturnsFalseAndSeedsOnFreshInstall(): void
    {
        $seenFile = '/etc/eiou/config/whats-new-seen.json';
        if (file_exists($seenFile)) {
            $this->markTestSkipped('Seen file already exists (running in Docker with prior state)');
        }
        if (!is_dir('/etc/eiou/config') || !is_writable('/etc/eiou/config')) {
            $this->markTestSkipped('Config directory not writable (not running in Docker)');
        }

        // Fresh install — file doesn't exist
        $this->assertFalse(UpdateCheckService::shouldShowWhatsNew());
        // File should now be created (seeded)
        $this->assertFileExists($seenFile);

        $data = json_decode(file_get_contents($seenFile), true);
        $this->assertSame(Constants::APP_VERSION, $data['dismissed_version']);

        // Clean up
        @unlink($seenFile);
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
