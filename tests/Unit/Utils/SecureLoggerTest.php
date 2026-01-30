<?php
/**
 * Unit Tests for SecureLogger
 *
 * Tests sensitive data masking in logs.
 */

namespace Eiou\Tests\Utils;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Utils\SecureLogger;

#[CoversClass(SecureLogger::class)]
class SecureLoggerTest extends TestCase
{
    private string $testLogFile;

    protected function setUp(): void
    {
        // Use a temporary file for testing
        $this->testLogFile = sys_get_temp_dir() . '/eiou-test-' . uniqid() . '.log';
        SecureLogger::init($this->testLogFile, 'DEBUG');
    }

    protected function tearDown(): void
    {
        // Clean up test log file
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }
    }

    /**
     * Test init creates log directory
     */
    public function testInitCreatesLogDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/eiou-test-dir-' . uniqid();
        $logFile = $tempDir . '/test.log';

        SecureLogger::init($logFile, 'INFO');

        // Write a log entry to trigger directory creation
        SecureLogger::info('test message');

        $this->assertDirectoryExists($tempDir);

        // Cleanup
        if (file_exists($logFile)) {
            unlink($logFile);
        }
        if (is_dir($tempDir)) {
            rmdir($tempDir);
        }
    }

    /**
     * Test log writes to file
     */
    public function testLogWritesToFile(): void
    {
        SecureLogger::info('Test message');

        $this->assertFileExists($this->testLogFile);
        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('Test message', $content);
    }

    /**
     * Test log includes level
     */
    public function testLogIncludesLevel(): void
    {
        SecureLogger::warning('Warning message');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('[WARNING]', $content);
    }

    /**
     * Test log includes PID
     */
    public function testLogIncludesPid(): void
    {
        SecureLogger::info('Test message');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('[PID:', $content);
    }

    /**
     * Test log masks passwords
     */
    public function testLogMasksPasswords(): void
    {
        SecureLogger::info('User login with password=secret123');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringNotContainsString('secret123', $content);
        $this->assertStringContainsString('***MASKED***', $content);
    }

    /**
     * Test log masks authcodes
     */
    public function testLogMasksAuthcodes(): void
    {
        SecureLogger::info('Request with authcode=abc123def456');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringNotContainsString('abc123def456', $content);
        $this->assertStringContainsString('***MASKED***', $content);
    }

    /**
     * Test log masks API keys
     */
    public function testLogMasksApiKeys(): void
    {
        SecureLogger::info('API request with api_key=eiou_test_key_12345');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringNotContainsString('eiou_test_key_12345', $content);
        $this->assertStringContainsString('***MASKED***', $content);
    }

    /**
     * Test log masks email addresses
     */
    public function testLogMasksEmailAddresses(): void
    {
        SecureLogger::info('User email is user@example.com');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringNotContainsString('user@example.com', $content);
        $this->assertStringContainsString('***EMAIL***', $content);
    }

    /**
     * Test log masks credit card numbers
     */
    public function testLogMasksCreditCardNumbers(): void
    {
        SecureLogger::info('Card number: 4111-1111-1111-1111');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringNotContainsString('4111-1111-1111-1111', $content);
        $this->assertStringContainsString('***CREDIT_CARD***', $content);
    }

    /**
     * Test log masks SSN
     */
    public function testLogMasksSsn(): void
    {
        SecureLogger::info('SSN: 123-45-6789');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringNotContainsString('123-45-6789', $content);
        $this->assertStringContainsString('***SSN***', $content);
    }

    /**
     * Test log masks context sensitive keys
     */
    public function testLogMasksContextSensitiveKeys(): void
    {
        SecureLogger::info('User action', ['username' => 'john', 'password' => 'secret']);

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('john', $content);
        $this->assertStringNotContainsString('secret', $content);
        $this->assertStringContainsString('***MASKED***', $content);
    }

    /**
     * Test log level filtering - debug not logged at INFO level
     */
    public function testLogLevelFiltering(): void
    {
        // Re-init with INFO level
        SecureLogger::init($this->testLogFile, 'INFO');

        SecureLogger::debug('This should not appear');
        SecureLogger::info('This should appear');

        $content = file_exists($this->testLogFile) ? file_get_contents($this->testLogFile) : '';
        $this->assertStringNotContainsString('This should not appear', $content);
        $this->assertStringContainsString('This should appear', $content);
    }

    /**
     * Test convenience methods
     */
    public function testConvenienceMethods(): void
    {
        SecureLogger::debug('Debug message');
        SecureLogger::info('Info message');
        SecureLogger::warning('Warning message');
        SecureLogger::error('Error message');
        SecureLogger::critical('Critical message');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('[DEBUG]', $content);
        $this->assertStringContainsString('[INFO]', $content);
        $this->assertStringContainsString('[WARNING]', $content);
        $this->assertStringContainsString('[ERROR]', $content);
        $this->assertStringContainsString('[CRITICAL]', $content);
    }

    /**
     * Test logException logs exception details
     */
    public function testLogExceptionLogsDetails(): void
    {
        $exception = new \Exception('Test exception message');
        SecureLogger::logException($exception);

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('Exception', $content);
        $this->assertStringContainsString('Test exception message', $content);
        $this->assertStringContainsString('file', $content);
        $this->assertStringContainsString('line', $content);
    }

    /**
     * Test logException masks sensitive data in stack trace
     */
    public function testLogExceptionMasksSensitiveData(): void
    {
        try {
            throw new \Exception('Error with password=secret123');
        } catch (\Exception $e) {
            SecureLogger::logException($e);
        }

        $content = file_get_contents($this->testLogFile);
        $this->assertStringNotContainsString('secret123', $content);
    }

    /**
     * Test pruneLogFile keeps only specified lines
     */
    public function testPruneLogFileKeepsSpecifiedLines(): void
    {
        // Write many log entries
        for ($i = 0; $i < 20; $i++) {
            SecureLogger::info("Log entry $i");
        }

        // Prune to keep only 5 lines
        SecureLogger::pruneLogFile(5);

        $content = file_get_contents($this->testLogFile);
        $lines = explode("\n", trim($content));

        $this->assertLessThanOrEqual(6, count($lines)); // 5 lines + possible empty
    }

    /**
     * Test masks 12-word mnemonic phrases
     */
    public function testMasks12WordMnemonicPhrases(): void
    {
        $mnemonic = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
        SecureLogger::info("Mnemonic: $mnemonic");

        $content = file_get_contents($this->testLogFile);
        $this->assertStringNotContainsString($mnemonic, $content);
        $this->assertStringContainsString('***MASKED', $content);
    }
}
