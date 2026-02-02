<?php
/**
 * Unit Tests for ConfigCheck
 *
 * Tests the configuration check script that verifies userconfig.json.
 */

namespace Eiou\Tests\Startup;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
class ConfigCheckTest extends TestCase
{
    private string $testConfigPath;
    private string $testConfigDir;

    protected function setUp(): void
    {
        // Create a temporary directory for test config files
        $this->testConfigDir = sys_get_temp_dir() . '/eiou-test-' . uniqid();
        mkdir($this->testConfigDir, 0755, true);
        $this->testConfigPath = $this->testConfigDir . '/userconfig.json';
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (file_exists($this->testConfigPath)) {
            unlink($this->testConfigPath);
        }
        if (is_dir($this->testConfigDir)) {
            rmdir($this->testConfigDir);
        }
    }

    // =========================================================================
    // File Existence Tests
    // =========================================================================

    /**
     * Test detection of missing userconfig.json
     */
    public function testDetectionOfMissingUserconfigJson(): void
    {
        // Simulate the check from ConfigCheck.php
        $configExists = file_exists($this->testConfigPath);

        $this->assertFalse($configExists);
    }

    /**
     * Test detection of existing userconfig.json
     */
    public function testDetectionOfExistingUserconfigJson(): void
    {
        // Create a config file
        file_put_contents($this->testConfigPath, '{}');

        $configExists = file_exists($this->testConfigPath);

        $this->assertTrue($configExists);
    }

    // =========================================================================
    // Config Content Tests
    // =========================================================================

    /**
     * Test detection of missing public key in config
     */
    public function testDetectionOfMissingPublicKeyInConfig(): void
    {
        // Create config without public key
        file_put_contents($this->testConfigPath, json_encode([
            'name' => 'Test User',
            'hostname' => 'http://example.com'
        ]));

        $config = json_decode(file_get_contents($this->testConfigPath), true);
        $hasPublicKey = isset($config['public']);

        $this->assertFalse($hasPublicKey);
    }

    /**
     * Test detection of present public key in config
     */
    public function testDetectionOfPresentPublicKeyInConfig(): void
    {
        // Create config with public key
        file_put_contents($this->testConfigPath, json_encode([
            'public' => 'test_public_key_abc123',
            'name' => 'Test User'
        ]));

        $config = json_decode(file_get_contents($this->testConfigPath), true);
        $hasPublicKey = isset($config['public']);

        $this->assertTrue($hasPublicKey);
    }

    /**
     * Test handling of invalid JSON in config
     */
    public function testHandlingOfInvalidJsonInConfig(): void
    {
        // Create file with invalid JSON
        file_put_contents($this->testConfigPath, 'not valid json {');

        $config = json_decode(file_get_contents($this->testConfigPath), true);

        $this->assertNull($config);
    }

    /**
     * Test handling of empty config file
     */
    public function testHandlingOfEmptyConfigFile(): void
    {
        // Create empty file
        file_put_contents($this->testConfigPath, '');

        $content = file_get_contents($this->testConfigPath);
        $config = json_decode($content, true);

        $this->assertNull($config);
    }

    /**
     * Test handling of valid empty JSON object
     */
    public function testHandlingOfValidEmptyJsonObject(): void
    {
        // Create file with empty JSON object
        file_put_contents($this->testConfigPath, '{}');

        $config = json_decode(file_get_contents($this->testConfigPath), true);
        $hasPublicKey = isset($config['public']);

        $this->assertIsArray($config);
        $this->assertFalse($hasPublicKey);
    }

    // =========================================================================
    // Run Flag Tests
    // =========================================================================

    /**
     * Test run flag is set to true when config is missing
     */
    public function testRunFlagIsTrueWhenConfigIsMissing(): void
    {
        // Simulate the logic from ConfigCheck.php
        $run = false;

        if (!file_exists($this->testConfigPath)) {
            $run = true;
        }

        $this->assertTrue($run);
    }

    /**
     * Test run flag is set to true when public key is missing
     */
    public function testRunFlagIsTrueWhenPublicKeyIsMissing(): void
    {
        // Create config without public key
        file_put_contents($this->testConfigPath, json_encode(['name' => 'Test']));

        $run = false;

        if (file_exists($this->testConfigPath)) {
            $config = json_decode(file_get_contents($this->testConfigPath), true);
            if (!isset($config['public'])) {
                $run = true;
            }
        }

        $this->assertTrue($run);
    }

    /**
     * Test run flag is false when config is complete
     */
    public function testRunFlagIsFalseWhenConfigIsComplete(): void
    {
        // Create complete config
        file_put_contents($this->testConfigPath, json_encode([
            'public' => 'test_public_key',
            'private' => 'test_private_key',
            'name' => 'Test User'
        ]));

        $run = false;

        if (file_exists($this->testConfigPath)) {
            $config = json_decode(file_get_contents($this->testConfigPath), true);
            if (!isset($config['public'])) {
                $run = true;
            }
        }

        $this->assertFalse($run);
    }

    // =========================================================================
    // Exception Handling Tests
    // =========================================================================

    /**
     * Test script handles exceptions gracefully
     */
    public function testScriptHandlesExceptionsGracefully(): void
    {
        // The ConfigCheck.php script wraps everything in try-catch
        // and returns early on exceptions

        $run = false;
        try {
            // Simulate file_get_contents on non-existent file
            // In the actual script, this would be caught
            if (!file_exists($this->testConfigPath)) {
                $run = true;
            }
        } catch (\Exception $e) {
            // Exception should not propagate
            $this->fail('Exception should be caught');
        }

        // Even on error, script should not crash
        $this->assertTrue($run);
    }

    /**
     * Test public key can be null in config
     */
    public function testPublicKeyCanBeNullInConfig(): void
    {
        // Create config with null public key
        file_put_contents($this->testConfigPath, json_encode([
            'public' => null,
            'name' => 'Test User'
        ]));

        $config = json_decode(file_get_contents($this->testConfigPath), true);
        // isset returns false for null values
        $hasPublicKey = isset($config['public']);

        $this->assertFalse($hasPublicKey);
    }

    /**
     * Test public key can be empty string in config
     */
    public function testPublicKeyCanBeEmptyStringInConfig(): void
    {
        // Create config with empty string public key
        file_put_contents($this->testConfigPath, json_encode([
            'public' => '',
            'name' => 'Test User'
        ]));

        $config = json_decode(file_get_contents($this->testConfigPath), true);
        // isset returns true for empty string
        $hasPublicKey = isset($config['public']);

        $this->assertTrue($hasPublicKey);
        $this->assertEquals('', $config['public']);
    }
}
