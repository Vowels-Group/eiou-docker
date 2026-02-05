<?php
/**
 * Unit Tests for Logger Facade
 *
 * Tests the unified Logger routes to SecureLogger and optionally to DebugService.
 */

namespace Eiou\Tests\Utils;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Utils\Logger;
use Eiou\Utils\SecureLogger;
use Eiou\Contracts\DebugServiceInterface;
use Eiou\Contracts\LoggerInterface;

#[CoversClass(Logger::class)]
class LoggerTest extends TestCase
{
    private string $testLogFile;

    protected function setUp(): void
    {
        $this->testLogFile = sys_get_temp_dir() . '/eiou-logger-test-' . uniqid() . '.log';
        Logger::init($this->testLogFile, 'DEBUG');
        // Reset debug service registration between tests
        Logger::registerDebugService($this->createMock(DebugServiceInterface::class));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }
    }

    public function testImplementsLoggerInterface(): void
    {
        $logger = Logger::getInstance();
        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testGetInstanceReturnsSingleton(): void
    {
        $a = Logger::getInstance();
        $b = Logger::getInstance();
        $this->assertSame($a, $b);
    }

    public function testInfoWritesToLogFile(): void
    {
        $logger = Logger::getInstance();
        $logger->info('Test info message');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('Test info message', $content);
        $this->assertStringContainsString('[INFO]', $content);
    }

    public function testDebugWritesToLogFile(): void
    {
        $logger = Logger::getInstance();
        $logger->debug('Debug trace message');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('Debug trace message', $content);
        $this->assertStringContainsString('[DEBUG]', $content);
    }

    public function testWarningWritesToLogFile(): void
    {
        $logger = Logger::getInstance();
        $logger->warning('Something looks wrong');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('Something looks wrong', $content);
        $this->assertStringContainsString('[WARNING]', $content);
    }

    public function testErrorWritesToLogFile(): void
    {
        $logger = Logger::getInstance();
        $logger->error('Connection failed');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('Connection failed', $content);
        $this->assertStringContainsString('[ERROR]', $content);
    }

    public function testCriticalWritesToLogFile(): void
    {
        $logger = Logger::getInstance();
        $logger->critical('Database unavailable');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('Database unavailable', $content);
        $this->assertStringContainsString('[CRITICAL]', $content);
    }

    public function testLogWithExplicitLevel(): void
    {
        $logger = Logger::getInstance();
        $logger->log('WARNING', 'Explicit level test');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('Explicit level test', $content);
        $this->assertStringContainsString('[WARNING]', $content);
    }

    public function testLogExceptionWritesToFile(): void
    {
        $logger = Logger::getInstance();
        $exception = new \RuntimeException('Something broke');
        $logger->logException($exception);

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('RuntimeException', $content);
        $this->assertStringContainsString('Something broke', $content);
    }

    public function testContextIsIncludedInLogFile(): void
    {
        $logger = Logger::getInstance();
        $logger->info('With context', ['txid' => 'abc123']);

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('abc123', $content);
    }

    public function testDebugServiceReceivesMessages(): void
    {
        $mockDebug = $this->createMock(DebugServiceInterface::class);
        $mockDebug->expects($this->once())
            ->method('output')
            ->with('Forwarded message', 'INFO');

        Logger::registerDebugService($mockDebug);

        $logger = Logger::getInstance();
        $logger->info('Forwarded message');
    }

    public function testDebugServiceExceptionDoesNotPropagate(): void
    {
        $mockDebug = $this->createMock(DebugServiceInterface::class);
        $mockDebug->method('output')
            ->willThrowException(new \RuntimeException('DB unavailable'));

        Logger::registerDebugService($mockDebug);

        // Should not throw
        $logger = Logger::getInstance();
        $logger->info('Still works');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('Still works', $content);
    }

    public function testDebugServiceNotCalledWhenNotRegistered(): void
    {
        // Simulate production: no DebugService registered
        // Use reflection to set debugService to null
        $ref = new \ReflectionClass(Logger::class);
        $prop = $ref->getProperty('debugService');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // Create a mock that should NEVER be called
        $mockDebug = $this->createMock(DebugServiceInterface::class);
        $mockDebug->expects($this->never())->method('output');

        // Log a message — file logging works, but debug panel is skipped
        $logger = Logger::getInstance();
        $logger->info('Production message');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('Production message', $content);
    }

    public function testDebugServiceStopsReceivingAfterDeregistration(): void
    {
        // Register a debug service, then deregister it (simulate switching to production)
        $mockDebug = $this->createMock(DebugServiceInterface::class);
        $mockDebug->expects($this->once())
            ->method('output')
            ->with('Before deregistration', 'INFO');

        Logger::registerDebugService($mockDebug);

        $logger = Logger::getInstance();
        $logger->info('Before deregistration');

        // Deregister by setting to null via reflection
        $ref = new \ReflectionClass(Logger::class);
        $prop = $ref->getProperty('debugService');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // This message should only go to file, not debug service
        $logger->info('After deregistration');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('After deregistration', $content);
    }

    public function testSensitiveDataIsMaskedInLogFile(): void
    {
        $logger = Logger::getInstance();
        $logger->info('User auth', ['password' => 'secret123']);

        $content = file_get_contents($this->testLogFile);
        $this->assertStringNotContainsString('secret123', $content);
        $this->assertStringContainsString('MASKED', $content);
    }
}
