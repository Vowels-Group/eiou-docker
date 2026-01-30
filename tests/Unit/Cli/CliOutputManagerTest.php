<?php
/**
 * Unit Tests for CliOutputManager
 *
 * Tests CLI output management, flag parsing, and argument cleaning.
 */

namespace Eiou\Tests\Cli;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Cli\CliOutputManager;

#[CoversClass(CliOutputManager::class)]
class CliOutputManagerTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset singleton between tests
        CliOutputManager::resetInstance();
    }

    protected function tearDown(): void
    {
        // Clean up after tests
        CliOutputManager::resetInstance();
    }

    /**
     * Test getInstance returns singleton
     */
    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = CliOutputManager::getInstance();
        $instance2 = CliOutputManager::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    /**
     * Test resetInstance creates new singleton
     */
    public function testResetInstanceCreatesNewSingleton(): void
    {
        $instance1 = CliOutputManager::getInstance();
        CliOutputManager::resetInstance();
        $instance2 = CliOutputManager::getInstance();

        $this->assertNotSame($instance1, $instance2);
    }

    /**
     * Test default mode is not JSON
     */
    public function testDefaultModeIsNotJson(): void
    {
        $manager = new CliOutputManager([]);

        $this->assertFalse($manager->isJsonMode());
    }

    /**
     * Test --json flag enables JSON mode
     */
    public function testJsonFlagEnablesJsonMode(): void
    {
        $manager = new CliOutputManager(['script.php', 'balance', '--json']);

        $this->assertTrue($manager->isJsonMode());
    }

    /**
     * Test -j flag enables JSON mode
     */
    public function testShortJsonFlagEnablesJsonMode(): void
    {
        $manager = new CliOutputManager(['script.php', 'send', '-j']);

        $this->assertTrue($manager->isJsonMode());
    }

    /**
     * Test setJsonMode enables JSON mode
     */
    public function testSetJsonModeEnables(): void
    {
        $manager = new CliOutputManager([]);
        $this->assertFalse($manager->isJsonMode());

        $manager->setJsonMode(true);
        $this->assertTrue($manager->isJsonMode());
    }

    /**
     * Test setJsonMode disables JSON mode
     */
    public function testSetJsonModeDisables(): void
    {
        $manager = new CliOutputManager(['script.php', '--json']);
        $this->assertTrue($manager->isJsonMode());

        $manager->setJsonMode(false);
        $this->assertFalse($manager->isJsonMode());
    }

    /**
     * Test setJsonMode returns self for fluent interface
     */
    public function testSetJsonModeReturnsSelf(): void
    {
        $manager = new CliOutputManager([]);
        $result = $manager->setJsonMode(true);

        $this->assertSame($manager, $result);
    }

    /**
     * Test setCommand returns self for fluent interface
     */
    public function testSetCommandReturnsSelf(): void
    {
        $manager = new CliOutputManager([]);
        $result = $manager->setCommand('balance');

        $this->assertSame($manager, $result);
    }

    /**
     * Test getJsonResponse returns CliJsonResponse instance
     */
    public function testGetJsonResponseReturnsInstance(): void
    {
        $manager = new CliOutputManager([]);
        $jsonResponse = $manager->getJsonResponse();

        $this->assertInstanceOf(\Eiou\Cli\CliJsonResponse::class, $jsonResponse);
    }

    /**
     * Test cleanArgv removes --json flag
     */
    public function testCleanArgvRemovesJsonFlag(): void
    {
        $argv = ['script.php', 'balance', '--json', 'extra'];
        $cleaned = CliOutputManager::cleanArgv($argv);

        $this->assertNotContains('--json', $cleaned);
        $this->assertContains('script.php', $cleaned);
        $this->assertContains('balance', $cleaned);
        $this->assertContains('extra', $cleaned);
    }

    /**
     * Test cleanArgv removes -j flag
     */
    public function testCleanArgvRemovesShortJsonFlag(): void
    {
        $argv = ['script.php', 'send', '-j', 'alice', '100'];
        $cleaned = CliOutputManager::cleanArgv($argv);

        $this->assertNotContains('-j', $cleaned);
        $this->assertContains('script.php', $cleaned);
        $this->assertContains('send', $cleaned);
        $this->assertContains('alice', $cleaned);
        $this->assertContains('100', $cleaned);
    }

    /**
     * Test cleanArgv removes --no-metadata flag
     */
    public function testCleanArgvRemovesNoMetadataFlag(): void
    {
        $argv = ['script.php', 'info', '--no-metadata'];
        $cleaned = CliOutputManager::cleanArgv($argv);

        $this->assertNotContains('--no-metadata', $cleaned);
        $this->assertContains('script.php', $cleaned);
        $this->assertContains('info', $cleaned);
    }

    /**
     * Test cleanArgv removes multiple flags
     */
    public function testCleanArgvRemovesMultipleFlags(): void
    {
        $argv = ['script.php', 'balance', '--json', '--no-metadata', '-j'];
        $cleaned = CliOutputManager::cleanArgv($argv);

        $this->assertNotContains('--json', $cleaned);
        $this->assertNotContains('--no-metadata', $cleaned);
        $this->assertNotContains('-j', $cleaned);
        $this->assertCount(2, $cleaned);
    }

    /**
     * Test cleanArgv preserves array values
     */
    public function testCleanArgvPreservesValues(): void
    {
        $argv = ['script.php', 'send', 'alice', '100', 'USD'];
        $cleaned = CliOutputManager::cleanArgv($argv);

        $this->assertEquals($argv, $cleaned);
    }

    /**
     * Test cleanArgv reindexes array
     */
    public function testCleanArgvReindexesArray(): void
    {
        $argv = ['script.php', '--json', 'balance'];
        $cleaned = CliOutputManager::cleanArgv($argv);

        // Should be reindexed: 0 => script.php, 1 => balance
        $this->assertEquals(0, array_key_first($cleaned));
        $this->assertEquals(['script.php', 'balance'], $cleaned);
    }

    /**
     * Test cleanArgv with empty array
     */
    public function testCleanArgvWithEmptyArray(): void
    {
        $cleaned = CliOutputManager::cleanArgv([]);

        $this->assertIsArray($cleaned);
        $this->assertEmpty($cleaned);
    }

    /**
     * Test cleanArgv with only flags
     */
    public function testCleanArgvWithOnlyFlags(): void
    {
        $argv = ['--json', '-j', '--no-metadata'];
        $cleaned = CliOutputManager::cleanArgv($argv);

        $this->assertEmpty($cleaned);
    }

    /**
     * Test command is parsed from argv
     */
    public function testCommandIsParsedFromArgv(): void
    {
        $manager = new CliOutputManager(['script.php', 'balance', '--json']);
        $jsonResponse = $manager->getJsonResponse();

        // The command should be set on the JSON response
        $json = $jsonResponse->success(['test' => true]);
        $data = json_decode($json, true);

        $this->assertEquals('balance', $data['metadata']['command']);
    }

    /**
     * Test command parsing is case insensitive
     */
    public function testCommandParsingIsCaseInsensitive(): void
    {
        $manager = new CliOutputManager(['script.php', 'BALANCE']);
        $jsonResponse = $manager->getJsonResponse();

        $json = $jsonResponse->success(['test' => true]);
        $data = json_decode($json, true);

        $this->assertEquals('balance', $data['metadata']['command']);
    }

    /**
     * Test flag at argv[1] does not become command
     */
    public function testFlagAtArgv1DoesNotBecomeCommand(): void
    {
        $manager = new CliOutputManager(['script.php', '--json', 'balance']);

        // When --json is at argv[1], it's not treated as a command
        $jsonResponse = $manager->getJsonResponse();
        $json = $jsonResponse->success(['test' => true]);
        $data = json_decode($json, true);

        // Command should be null or not set to --json
        $this->assertNotEquals('--json', $data['metadata']['command'] ?? null);
    }

    /**
     * Test constructor with node ID
     */
    public function testConstructorWithNodeId(): void
    {
        $manager = new CliOutputManager(['script.php', 'info'], 'node-123');
        $jsonResponse = $manager->getJsonResponse();

        $json = $jsonResponse->success(['test' => true]);
        $data = json_decode($json, true);

        $this->assertEquals('node-123', $data['metadata']['node_id']);
    }

    /**
     * Test getInstance with node ID
     */
    public function testGetInstanceWithNodeId(): void
    {
        $manager = CliOutputManager::getInstance(['script.php', 'balance'], 'test-node');
        $jsonResponse = $manager->getJsonResponse();

        $json = $jsonResponse->success(['data' => true]);
        $data = json_decode($json, true);

        $this->assertEquals('test-node', $data['metadata']['node_id']);
    }
}
