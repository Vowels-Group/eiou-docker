<?php
/**
 * Unit Tests for SecureSeedphraseDisplay
 *
 * Tests secure seedphrase display functionality for Docker environments.
 */

namespace Eiou\Tests\Utils;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Utils\SecureSeedphraseDisplay;

#[CoversClass(SecureSeedphraseDisplay::class)]
class SecureSeedphraseDisplayTest extends TestCase
{
    private string $testSeedphrase;
    private string $testAuthcode;

    protected function setUp(): void
    {
        // Standard 24-word test seedphrase
        $this->testSeedphrase = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon art';
        $this->testAuthcode = 'test_auth_code_12345';
    }

    protected function tearDown(): void
    {
        // Clean up any test files
        SecureSeedphraseDisplay::cleanup();
    }

    // =========================================================================
    // checkAvailability Tests
    // =========================================================================

    /**
     * Test checkAvailability returns array with expected keys
     */
    public function testCheckAvailabilityReturnsArrayWithExpectedKeys(): void
    {
        $result = SecureSeedphraseDisplay::checkAvailability();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('tty_available', $result);
        $this->assertArrayHasKey('shm_available', $result);
        $this->assertArrayHasKey('preferred_method', $result);
        $this->assertArrayHasKey('security_level', $result);
    }

    /**
     * Test checkAvailability returns boolean for tty_available
     */
    public function testCheckAvailabilityReturnsBooleanForTtyAvailable(): void
    {
        $result = SecureSeedphraseDisplay::checkAvailability();

        $this->assertIsBool($result['tty_available']);
    }

    /**
     * Test checkAvailability returns boolean for shm_available
     */
    public function testCheckAvailabilityReturnsBooleanForShmAvailable(): void
    {
        $result = SecureSeedphraseDisplay::checkAvailability();

        $this->assertIsBool($result['shm_available']);
    }

    /**
     * Test checkAvailability preferred_method is valid value
     */
    public function testCheckAvailabilityPreferredMethodIsValidValue(): void
    {
        $result = SecureSeedphraseDisplay::checkAvailability();

        $validMethods = ['tty', 'shm_file', 'tmp_file'];
        $this->assertContains($result['preferred_method'], $validMethods);
    }

    /**
     * Test checkAvailability security_level is valid value
     */
    public function testCheckAvailabilitySecurityLevelIsValidValue(): void
    {
        $result = SecureSeedphraseDisplay::checkAvailability();

        $validLevels = ['high', 'medium', 'low'];
        $this->assertContains($result['security_level'], $validLevels);
    }

    // =========================================================================
    // display Method Tests
    // =========================================================================

    /**
     * Test display returns array with expected keys
     */
    public function testDisplayReturnsArrayWithExpectedKeys(): void
    {
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('method', $result);
    }

    /**
     * Test display with authcode returns array
     */
    public function testDisplayWithAuthcodeReturnsArray(): void
    {
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false, $this->testAuthcode);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    /**
     * Test display method is either tty or file
     */
    public function testDisplayMethodIsEitherTtyOrFile(): void
    {
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false);

        $validMethods = ['tty', 'file'];
        $this->assertContains($result['method'], $validMethods);
    }

    /**
     * Test display via file creates temp file when tty not available
     */
    public function testDisplayViaFileCreatesTempFileWhenTtyNotAvailable(): void
    {
        // In test environment, TTY is typically not available
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false);

        if ($result['method'] === 'file') {
            $this->assertTrue($result['success']);
            $this->assertArrayHasKey('filepath', $result);
            $this->assertArrayHasKey('ttl', $result);
        } else {
            // If TTY is available, that's fine too
            $this->assertTrue($result['success']);
        }
    }

    /**
     * Test display file result includes instructions
     */
    public function testDisplayFileResultIncludesInstructions(): void
    {
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false);

        if ($result['method'] === 'file') {
            $this->assertArrayHasKey('instructions', $result);
            $this->assertIsArray($result['instructions']);
            $this->assertNotEmpty($result['instructions']);
        }
    }

    // =========================================================================
    // displayAuthcode Method Tests
    // =========================================================================

    /**
     * Test displayAuthcode returns array with expected keys
     */
    public function testDisplayAuthcodeReturnsArrayWithExpectedKeys(): void
    {
        $result = SecureSeedphraseDisplay::displayAuthcode($this->testAuthcode);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('method', $result);
    }

    /**
     * Test displayAuthcode method is either tty or file
     */
    public function testDisplayAuthcodeMethodIsEitherTtyOrFile(): void
    {
        $result = SecureSeedphraseDisplay::displayAuthcode($this->testAuthcode);

        $validMethods = ['tty', 'file'];
        $this->assertContains($result['method'], $validMethods);
    }

    // =========================================================================
    // cleanup Method Tests
    // =========================================================================

    /**
     * Test cleanup returns integer
     */
    public function testCleanupReturnsInteger(): void
    {
        $result = SecureSeedphraseDisplay::cleanup();

        $this->assertIsInt($result);
    }

    /**
     * Test cleanup returns zero when no files to clean
     */
    public function testCleanupReturnsZeroWhenNoFilesToClean(): void
    {
        // First cleanup removes any existing files
        SecureSeedphraseDisplay::cleanup();

        // Second cleanup should find nothing
        $result = SecureSeedphraseDisplay::cleanup();

        $this->assertEquals(0, $result);
    }

    /**
     * Test cleanup removes orphaned seedphrase files
     */
    public function testCleanupRemovesOrphanedSeedphraseFiles(): void
    {
        // Create a test file that matches the cleanup pattern
        $testDir = '/dev/shm';
        if (!is_dir($testDir) || !is_writable($testDir)) {
            $testDir = '/tmp';
        }

        $testFile = $testDir . '/eiou_wallet_info_' . bin2hex(random_bytes(16));
        file_put_contents($testFile, 'test content');

        // Verify file exists
        $this->assertFileExists($testFile);

        // Run cleanup
        $result = SecureSeedphraseDisplay::cleanup();

        // File should be removed
        $this->assertFileDoesNotExist($testFile);
        $this->assertGreaterThanOrEqual(1, $result);
    }

    // =========================================================================
    // formatSeedphrase Tests (via display output)
    // =========================================================================

    /**
     * Test seedphrase is formatted with numbered words
     */
    public function testSeedphraseIsFormattedWithNumberedWords(): void
    {
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false);

        if ($result['method'] === 'file' && isset($result['filepath']) && file_exists($result['filepath'])) {
            $content = file_get_contents($result['filepath']);

            // Should contain numbered words like "1. abandon"
            $this->assertStringContainsString('1.', $content);
            $this->assertStringContainsString('abandon', $content);
        }
    }

    // =========================================================================
    // File Security Tests
    // =========================================================================

    /**
     * Test temp file has restrictive permissions
     */
    public function testTempFileHasRestrictivePermissions(): void
    {
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false);

        if ($result['method'] === 'file' && isset($result['filepath']) && file_exists($result['filepath'])) {
            $perms = fileperms($result['filepath']) & 0777;
            // Should be 0400 (owner read only)
            $this->assertEquals(0400, $perms);
        }
    }

    /**
     * Test TTL constant is 300 seconds (5 minutes)
     */
    public function testTtlConstantIs300Seconds(): void
    {
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false);

        if ($result['method'] === 'file') {
            $this->assertEquals(300, $result['ttl']);
        }
    }

    // =========================================================================
    // Content Verification Tests
    // =========================================================================

    /**
     * Test file content includes security warnings
     */
    public function testFileContentIncludesSecurityWarnings(): void
    {
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false);

        if ($result['method'] === 'file' && isset($result['filepath']) && file_exists($result['filepath'])) {
            $content = file_get_contents($result['filepath']);

            $this->assertStringContainsString('IMPORTANT', $content);
            $this->assertStringContainsString('WRITE DOWN', $content);
            $this->assertStringContainsString('Never share', $content);
        }
    }

    /**
     * Test file content includes restore instructions
     */
    public function testFileContentIncludesRestoreInstructions(): void
    {
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false);

        if ($result['method'] === 'file' && isset($result['filepath']) && file_exists($result['filepath'])) {
            $content = file_get_contents($result['filepath']);

            $this->assertStringContainsString('WALLET RESTORATION', $content);
            $this->assertStringContainsString('RESTORE', $content);
            $this->assertStringContainsString('docker run', $content);
        }
    }

    /**
     * Test file content includes authcode when provided
     */
    public function testFileContentIncludesAuthcodeWhenProvided(): void
    {
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false, $this->testAuthcode);

        if ($result['method'] === 'file' && isset($result['filepath']) && file_exists($result['filepath'])) {
            $content = file_get_contents($result['filepath']);

            $this->assertStringContainsString('AUTHENTICATION CODE', $content);
            $this->assertStringContainsString($this->testAuthcode, $content);
        }
    }

    /**
     * Test file content includes deletion instructions
     */
    public function testFileContentIncludesDeletionInstructions(): void
    {
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false);

        if ($result['method'] === 'file' && isset($result['filepath']) && file_exists($result['filepath'])) {
            $content = file_get_contents($result['filepath']);

            $this->assertStringContainsString('automatically deleted', $content);
            $this->assertStringContainsString('rm', $content);
        }
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    /**
     * Test display handles short seedphrase
     */
    public function testDisplayHandlesShortSeedphrase(): void
    {
        $shortSeedphrase = 'word1 word2 word3';

        $result = SecureSeedphraseDisplay::display($shortSeedphrase, false);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    /**
     * Test display handles long seedphrase
     */
    public function testDisplayHandlesLongSeedphrase(): void
    {
        // Create a 48-word seedphrase
        $longSeedphrase = implode(' ', array_fill(0, 48, 'abandon'));

        $result = SecureSeedphraseDisplay::display($longSeedphrase, false);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    /**
     * Test display handles special characters in authcode
     */
    public function testDisplayHandlesSpecialCharactersInAuthcode(): void
    {
        $specialAuthcode = 'auth-code_123!@#$%';

        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false, $specialAuthcode);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    /**
     * Test display handles empty authcode
     */
    public function testDisplayHandlesEmptyAuthcode(): void
    {
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false, '');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    /**
     * Test display handles null authcode
     */
    public function testDisplayHandlesNullAuthcode(): void
    {
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false, null);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    // =========================================================================
    // Directory Fallback Tests
    // =========================================================================

    /**
     * Test uses /tmp when /dev/shm is not available
     */
    public function testUsesTmpWhenDevShmIsNotAvailable(): void
    {
        // This tests that the fallback logic exists
        // We can't easily test the actual fallback without mocking is_dir/is_writable

        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false);

        if ($result['method'] === 'file' && isset($result['filepath'])) {
            // Filepath should be in either /dev/shm or /tmp
            $this->assertTrue(
                str_starts_with($result['filepath'], '/dev/shm/') ||
                str_starts_with($result['filepath'], '/tmp/')
            );
        }
    }

    /**
     * Test warning is set when using /tmp fallback
     */
    public function testWarningIsSetWhenUsingTmpFallback(): void
    {
        $result = SecureSeedphraseDisplay::display($this->testSeedphrase, false);

        if ($result['method'] === 'file') {
            // warning key exists (may be null if /dev/shm is available)
            $this->assertArrayHasKey('warning', $result);

            // If warning is set, it should mention /dev/shm
            if ($result['warning'] !== null) {
                $this->assertStringContainsString('/dev/shm', $result['warning']);
            }
        }
    }
}
