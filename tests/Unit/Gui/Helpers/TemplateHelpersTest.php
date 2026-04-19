<?php
/**
 * Unit Tests for TemplateHelpers
 *
 * Tests template helper functions including:
 * - Gradient avatar SVG ID uniqueness across multiple calls
 */

namespace Eiou\Tests\Gui\Helpers;

use PHPUnit\Framework\TestCase;

class TemplateHelpersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Load template helpers (standalone functions, not a class)
        require_once __DIR__ . '/../../../../files/src/gui/functions/TemplateHelpers.php';
    }

    // =========================================================================
    // renderContactAvatar() — gradient ID uniqueness
    // =========================================================================

    /**
     * Test gradient avatar generates unique SVG gradient IDs for the same contact hash
     *
     * When the same contact appears in multiple tables (e.g. Your Contacts and
     * Recent Transactions), duplicate gradient IDs cause fill="url(#…)" to fail
     * in inline SVGs. Each call must produce a unique ID.
     */
    public function testGradientAvatarGeneratesUniqueIdsForSameHash(): void
    {
        $hash = 'c8d62b81dcfb0e277a2f7a0988b9354bd3422a6ae41ca9bcd704bf27a841c7ec';

        $svg1 = renderContactAvatar($hash, 'Alice', 'gradient');
        $svg2 = renderContactAvatar($hash, 'Alice', 'gradient');

        // Extract gradient IDs from the SVG output
        preg_match('/id="(cag[^"]+)"/', $svg1, $match1);
        preg_match('/id="(cag[^"]+)"/', $svg2, $match2);

        $this->assertNotEmpty($match1[1], 'First SVG should contain a gradient ID');
        $this->assertNotEmpty($match2[1], 'Second SVG should contain a gradient ID');
        $this->assertNotEquals($match1[1], $match2[1], 'Same contact rendered twice must get different gradient IDs');
    }

    /**
     * Test gradient avatar IDs are unique across different contacts too
     */
    public function testGradientAvatarGeneratesUniqueIdsForDifferentHashes(): void
    {
        $hash1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $hash2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        $svg1 = renderContactAvatar($hash1, 'Alice', 'gradient');
        $svg2 = renderContactAvatar($hash2, 'Bob', 'gradient');

        preg_match('/id="(cag[^"]+)"/', $svg1, $match1);
        preg_match('/id="(cag[^"]+)"/', $svg2, $match2);

        $this->assertNotEquals($match1[1], $match2[1]);
    }

    /**
     * Test gradient avatar renders valid SVG with circle and text
     */
    public function testGradientAvatarRendersValidSvg(): void
    {
        $svg = renderContactAvatar('', 'Test', 'gradient');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<circle', $svg);
        $this->assertStringContainsString('>T</text>', $svg);
        $this->assertStringContainsString('contact-avatar-sm', $svg);
    }

    /**
     * Test tile avatar does not use gradient IDs (no collision risk)
     */
    public function testTileAvatarDoesNotUseGradientIds(): void
    {
        $hash = 'c8d62b81dcfb0e277a2f7a0988b9354bd3422a6ae41ca9bcd704bf27a841c7ec';

        $svg = renderContactAvatar($hash, 'Alice', 'tile');

        // Tile avatars use direct fill attributes, no linearGradient defs
        $this->assertStringNotContainsString('linearGradient', $svg);
    }

    // =========================================================================
    // buildTxContactLookupMaps() — name/address/hash resolution priority
    // =========================================================================

    public function testBuildTxContactLookupMapsIndexesByAllThreeKeys(): void
    {
        $contact = [
            'contact_id' => 'cid-1',
            'name' => 'Alice',
            'pubkey_hash' => 'hash-alice',
            'http' => 'http://alice',
            'https' => '',
            'tor' => '',
        ];
        $maps = buildTxContactLookupMaps([$contact], [], []);

        $this->assertSame($contact, $maps['byName']['alice']);
        $this->assertSame($contact, $maps['byHash']['hash-alice']);
        $this->assertSame($contact, $maps['byAddress']['http://alice']);
    }

    public function testBuildTxContactLookupMapsAcceptedWinsOnCollision(): void
    {
        // Same address owned by a blocked contact (older) and an accepted
        // contact (current). The row renderer must resolve to the accepted
        // one so the display name / avatar match the current state.
        $blocked  = ['contact_id' => 'old', 'name' => 'Bob (old)', 'pubkey_hash' => 'h', 'http' => 'http://bob'];
        $accepted = ['contact_id' => 'new', 'name' => 'Bob',       'pubkey_hash' => 'h', 'http' => 'http://bob'];

        $maps = buildTxContactLookupMaps([$accepted], [], [$blocked]);

        $this->assertSame('new', $maps['byAddress']['http://bob']['contact_id']);
        $this->assertSame('new', $maps['byHash']['h']['contact_id']);
    }

    public function testBuildTxContactLookupMapsLowercasesNameKey(): void
    {
        // Transaction formatter lowercases counterparty_name when indexing —
        // verify buildTxContactLookupMaps does the same so key lookups match.
        $contact = ['contact_id' => '1', 'name' => 'CaRoL', 'pubkey_hash' => 'h2', 'http' => 'http://carol'];

        $maps = buildTxContactLookupMaps([$contact], [], []);

        $this->assertArrayHasKey('carol', $maps['byName']);
        $this->assertArrayNotHasKey('CaRoL', $maps['byName']);
    }

    public function testBuildTxContactLookupMapsSkipsEmptyFields(): void
    {
        // A contact with only a pubkey_hash (e.g. RestoredContact that lost
        // its transport addresses) should still be indexable by hash.
        $contact = ['contact_id' => 'r1', 'name' => '', 'pubkey_hash' => 'onlyhash', 'http' => '', 'https' => '', 'tor' => ''];

        $maps = buildTxContactLookupMaps([$contact], [], []);

        $this->assertArrayNotHasKey('', $maps['byName']);
        $this->assertArrayNotHasKey('', $maps['byAddress']);
        $this->assertSame($contact, $maps['byHash']['onlyhash']);
    }
}
