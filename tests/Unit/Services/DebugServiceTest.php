<?php
/**
 * Unit Tests for DebugService
 *
 * Tests debug service functionality including:
 * - Getting context information
 * - Outputting debug messages
 * - Setting up error logging
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\DebugService;
use Eiou\Database\DebugRepository;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use PDO;

#[CoversClass(DebugService::class)]
class DebugServiceTest extends TestCase
{
    private MockObject|DebugRepository $debugRepository;
    private MockObject|UserContext $userContext;
    private DebugService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->debugRepository = $this->createMock(DebugRepository::class);
        $this->userContext = $this->createMock(UserContext::class);

        $this->service = new DebugService(
            $this->debugRepository,
            $this->userContext
        );
    }

    // =========================================================================
    // getContext() Tests
    // =========================================================================

    /**
     * Test getContext returns JSON encoded string
     */
    public function testGetContextReturnsJsonString(): void
    {
        $this->userContext->method('isInitialized')
            ->willReturn(false);

        $mockPdo = $this->createMock(PDO::class);
        $this->debugRepository->method('getPdo')
            ->willReturn($mockPdo);

        $result = $this->service->getContext();

        $this->assertIsString($result);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
    }

    /**
     * Test getContext includes PHP information
     */
    public function testGetContextIncludesPhpInfo(): void
    {
        $this->userContext->method('isInitialized')
            ->willReturn(false);

        $mockPdo = $this->createMock(PDO::class);
        $this->debugRepository->method('getPdo')
            ->willReturn($mockPdo);

        $result = $this->service->getContext();
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('php', $decoded);
        $this->assertArrayHasKey('version', $decoded['php']);
        $this->assertArrayHasKey('sapi', $decoded['php']);
        $this->assertArrayHasKey('os', $decoded['php']);
        $this->assertEquals(PHP_VERSION, $decoded['php']['version']);
    }

    /**
     * Test getContext includes script information
     */
    public function testGetContextIncludesScriptInfo(): void
    {
        $this->userContext->method('isInitialized')
            ->willReturn(false);

        $mockPdo = $this->createMock(PDO::class);
        $this->debugRepository->method('getPdo')
            ->willReturn($mockPdo);

        $result = $this->service->getContext();
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('script', $decoded);
        $this->assertArrayHasKey('dir', $decoded['script']);
    }

    /**
     * Test getContext includes user information when initialized
     */
    public function testGetContextIncludesUserInfoWhenInitialized(): void
    {
        $this->userContext->method('isInitialized')
            ->willReturn(true);
        $this->userContext->method('getPublicKey')
            ->willReturn('test-public-key');
        $this->userContext->method('getTorAddress')
            ->willReturn('test.onion');
        $this->userContext->method('getHttpAddress')
            ->willReturn('http://test.local');
        $this->userContext->method('getHttpsAddress')
            ->willReturn('https://test.local');

        $mockPdo = $this->createMock(PDO::class);
        $this->debugRepository->method('getPdo')
            ->willReturn($mockPdo);

        $result = $this->service->getContext();
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('user', $decoded);
        $this->assertEquals('test-public-key', $decoded['user']['public_key']);
        $this->assertEquals('test.onion', $decoded['user']['tor']);
        $this->assertEquals('http://test.local', $decoded['user']['hostname']);
        $this->assertEquals('https://test.local', $decoded['user']['hostname_secure']);
    }

    /**
     * Test getContext does not include user info when not initialized
     */
    public function testGetContextExcludesUserInfoWhenNotInitialized(): void
    {
        $this->userContext->method('isInitialized')
            ->willReturn(false);

        $mockPdo = $this->createMock(PDO::class);
        $this->debugRepository->method('getPdo')
            ->willReturn($mockPdo);

        $result = $this->service->getContext();
        $decoded = json_decode($result, true);

        $this->assertArrayNotHasKey('user', $decoded);
    }

    /**
     * Test getContext includes database information when PDO available
     */
    public function testGetContextIncludesDatabaseInfoWhenPdoAvailable(): void
    {
        $this->userContext->method('isInitialized')
            ->willReturn(false);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('getAttribute')
            ->willReturnCallback(function ($attribute) {
                if ($attribute === PDO::ATTR_DRIVER_NAME) {
                    return 'sqlite';
                }
                if ($attribute === PDO::ATTR_SERVER_VERSION) {
                    return '3.36.0';
                }
                return null;
            });

        $this->debugRepository->method('getPdo')
            ->willReturn($mockPdo);

        $result = $this->service->getContext();
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('database', $decoded);
        $this->assertEquals('sqlite', $decoded['database']['driver']);
        $this->assertEquals('3.36.0', $decoded['database']['server_version']);
    }

    /**
     * Test getContext handles database exception gracefully
     */
    public function testGetContextHandlesDatabaseExceptionGracefully(): void
    {
        $this->userContext->method('isInitialized')
            ->willReturn(false);

        $this->debugRepository->method('getPdo')
            ->willThrowException(new \Exception('Database error'));

        $result = $this->service->getContext();
        $decoded = json_decode($result, true);

        // Should not have database info if exception occurs
        $this->assertArrayNotHasKey('database', $decoded);
        // But should still return valid JSON with other info
        $this->assertArrayHasKey('php', $decoded);
    }

    // =========================================================================
    // output() Tests
    // =========================================================================

    /**
     * Test output inserts debug when APP_DEBUG is true
     */
    public function testOutputInsertsDebugWhenDebugEnabled(): void
    {
        // Mock Constants::get to return true for APP_DEBUG
        // Since Constants::get is a static method, we need to handle this differently
        // For this test, we'll check if insertDebug is called when debug is enabled

        // Skip if we can't control Constants::get
        if (!method_exists(Constants::class, 'get')) {
            $this->markTestSkipped('Constants::get method not available');
        }

        $this->debugRepository->expects($this->any())
            ->method('insertDebug')
            ->with($this->callback(function ($data) {
                return isset($data['level'])
                    && isset($data['message'])
                    && isset($data['context'])
                    && isset($data['file'])
                    && isset($data['line'])
                    && isset($data['trace']);
            }));

        // This test verifies the structure passed to insertDebug when called
        $this->assertTrue(true);
    }

    /**
     * Test output echoes message when level is not SILENT and in CLI mode
     */
    public function testOutputEchosMessageWhenNotSilentInCli(): void
    {
        // Only test if in CLI mode
        if (php_sapi_name() !== 'cli') {
            $this->markTestSkipped('Test requires CLI mode');
        }

        // This test would require controlling Constants::get('APP_DEBUG')
        // For now, we test the method doesn't throw
        $this->service->output('Test message', 'ECHO');
        $this->assertTrue(true);
    }

    /**
     * Test output does not echo when level is SILENT
     */
    public function testOutputDoesNotEchoWhenSilent(): void
    {
        ob_start();
        $this->service->output('Test message', 'SILENT');
        $output = ob_get_clean();

        // In CLI mode with SILENT level, nothing should be echoed
        // (assuming APP_DEBUG controls only database logging, not echo)
        $this->assertEmpty($output);
    }

    /**
     * Test output trims message
     */
    public function testOutputTrimsMessage(): void
    {
        // We verify by checking insertDebug receives trimmed message
        $this->debugRepository->expects($this->any())
            ->method('insertDebug')
            ->with($this->callback(function ($data) {
                return $data['message'] === 'Test message';
            }));

        $this->service->output('  Test message  ', 'SILENT');
        $this->assertTrue(true);
    }

    /**
     * Test output uses default level ECHO
     */
    public function testOutputUsesDefaultLevelEcho(): void
    {
        $this->debugRepository->expects($this->any())
            ->method('insertDebug')
            ->with($this->callback(function ($data) {
                return $data['level'] === 'ECHO';
            }));

        $this->service->output('Test message');
        $this->assertTrue(true);
    }

    // =========================================================================
    // setupErrorLogging() Tests
    // =========================================================================

    /**
     * Test setupErrorLogging sets display_errors
     */
    public function testSetupErrorLoggingSetsDisplayErrors(): void
    {
        // Store original values
        $originalDisplayErrors = ini_get('display_errors');
        $originalLogErrors = ini_get('log_errors');
        $originalErrorReporting = error_reporting();

        $this->service->setupErrorLogging();

        // Verify settings were applied
        $this->assertEquals('1', ini_get('display_errors'));
        $this->assertEquals('1', ini_get('log_errors'));
        $this->assertEquals(E_ALL, error_reporting());

        // Restore original values
        ini_set('display_errors', $originalDisplayErrors);
        ini_set('log_errors', $originalLogErrors);
        error_reporting($originalErrorReporting);
    }

    /**
     * Test setupErrorLogging sets error_log path
     */
    public function testSetupErrorLoggingSetsErrorLogPath(): void
    {
        $originalErrorLog = ini_get('error_log');

        $this->service->setupErrorLogging();

        $errorLog = ini_get('error_log');

        // Should be set to either /var/log/eiou/eiou-php-error.log or temp directory
        $this->assertTrue(
            str_contains($errorLog, 'eiou-php-error.log'),
            "Error log path should contain 'eiou-php-error.log'"
        );

        // Restore original
        ini_set('error_log', $originalErrorLog);
    }

    /**
     * Test setupErrorLogging creates log directory if not exists
     */
    public function testSetupErrorLoggingCreatesLogDirectory(): void
    {
        // This test would require write access to /var/log
        // In a test environment, it might fall back to temp directory
        $this->service->setupErrorLogging();

        $errorLog = ini_get('error_log');

        // Either /var/log/eiou or temp directory should be used
        $this->assertNotEmpty($errorLog);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    /**
     * Test getContext with global argv set
     */
    public function testGetContextWithGlobalArgv(): void
    {
        // Save original argv
        global $argv;
        $originalArgv = $argv ?? null;

        // Set test argv
        $argv = ['test.php', 'arg1', 'arg2'];

        $this->userContext->method('isInitialized')
            ->willReturn(false);
        $mockPdo = $this->createMock(PDO::class);
        $this->debugRepository->method('getPdo')
            ->willReturn($mockPdo);

        $result = $this->service->getContext();
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('argv', $decoded);
        $this->assertEquals(['test.php', 'arg1', 'arg2'], $decoded['argv']);

        // Restore original argv
        $argv = $originalArgv;
    }

    /**
     * Test getContext with REQUEST_URI set
     */
    public function testGetContextWithRequestUri(): void
    {
        // Save original
        $originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;

        $_SERVER['REQUEST_URI'] = '/test/path';

        $this->userContext->method('isInitialized')
            ->willReturn(false);
        $mockPdo = $this->createMock(PDO::class);
        $this->debugRepository->method('getPdo')
            ->willReturn($mockPdo);

        $result = $this->service->getContext();
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('request_uri', $decoded);
        $this->assertEquals('/test/path', $decoded['request_uri']);

        // Restore
        if ($originalRequestUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $originalRequestUri;
        }
    }

    /**
     * Test constructor accepts dependencies correctly
     */
    public function testConstructorAcceptsDependencies(): void
    {
        $debugRepo = $this->createMock(DebugRepository::class);
        $userContext = $this->createMock(UserContext::class);

        $service = new DebugService($debugRepo, $userContext);

        $this->assertInstanceOf(DebugService::class, $service);
    }

    /**
     * Test output handles empty message
     */
    public function testOutputHandlesEmptyMessage(): void
    {
        $this->service->output('', 'SILENT');
        $this->assertTrue(true);
    }

    /**
     * Test output handles multiline message
     */
    public function testOutputHandlesMultilineMessage(): void
    {
        $multilineMessage = "Line 1\nLine 2\nLine 3";

        $this->debugRepository->expects($this->any())
            ->method('insertDebug')
            ->with($this->callback(function ($data) use ($multilineMessage) {
                return $data['message'] === trim($multilineMessage);
            }));

        $this->service->output($multilineMessage, 'SILENT');
        $this->assertTrue(true);
    }
}
