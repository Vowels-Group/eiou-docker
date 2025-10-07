<?php
/**
 * Unit tests for SecureLogger class
 * Tests sensitive data masking, log levels, and log rotation
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/utils/SecureLogger.php';

class SecureLoggerTest extends TestCase {

    private $testLogFile;

    public function setUp() {
        parent::setUp();
        $this->testLogFile = sys_get_temp_dir() . '/test_secure_' . uniqid() . '.log';
        SecureLogger::init($this->testLogFile, 'DEBUG');
    }

    public function tearDown() {
        parent::tearDown();
        if (file_exists($this->testLogFile)) {
            @unlink($this->testLogFile);
        }
        // Clean up rotated logs
        foreach (glob($this->testLogFile . '*') as $file) {
            @unlink($file);
        }
    }

    public function testBasicLogging() {
        SecureLogger::info('Test message');

        $this->assertTrue(file_exists($this->testLogFile), "Log file should be created");

        $content = file_get_contents($this->testLogFile);
        $this->assertContains('Test message', $content, "Should log the message");
        $this->assertContains('[INFO]', $content, "Should include log level");
    }

    public function testPasswordMasking() {
        SecureLogger::info('User login with password=secret123');

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('secret123', $content, "Password should be masked");
        $this->assertContains('***MASKED***', $content, "Should show masked placeholder");
    }

    public function testAuthcodeMasking() {
        SecureLogger::info('Redirect to /wallet?authcode=abc123def456');

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('abc123def456', $content, "Authcode should be masked");
        $this->assertContains('authcode=***MASKED***', $content, "Should mask authcode");
    }

    public function testPrivateKeyMasking() {
        SecureLogger::debug('Generated private_key=ABCDEF123456789');

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('ABCDEF123456789', $content, "Private key should be masked");
        $this->assertContains('private_key=***MASKED***', $content, "Should mask private key");
    }

    public function testTokenMasking() {
        SecureLogger::warning('API request with token=Bearer_xyz789');

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('Bearer_xyz789', $content, "Token should be masked");
        $this->assertContains('token=***MASKED***', $content, "Should mask token");
    }

    public function testEmailMasking() {
        SecureLogger::info('User email: alice@example.com');

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('alice@example.com', $content, "Email should be masked");
        $this->assertContains('***EMAIL***', $content, "Should show email placeholder");
    }

    public function testCreditCardMasking() {
        SecureLogger::error('Payment failed for card 4111-1111-1111-1111');

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('4111-1111-1111-1111', $content, "Credit card should be masked");
        $this->assertContains('***CREDIT_CARD***', $content, "Should show credit card placeholder");
    }

    public function testSSNMasking() {
        SecureLogger::info('SSN verification: 123-45-6789');

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('123-45-6789', $content, "SSN should be masked");
        $this->assertContains('***SSN***', $content, "Should show SSN placeholder");
    }

    public function testContextMasking() {
        $context = [
            'user_id' => 123,
            'password' => 'secret',
            'api_key' => 'key123',
            'action' => 'login'
        ];

        SecureLogger::info('User action', $context);

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('secret', $content, "Context password should be masked");
        $this->assertNotContains('key123', $content, "Context API key should be masked");
        $this->assertContains('123', $content, "User ID should be logged");
        $this->assertContains('login', $content, "Action should be logged");
    }

    public function testNestedContextMasking() {
        $context = [
            'request' => [
                'headers' => [
                    'Authorization' => 'Bearer token123'
                ],
                'body' => [
                    'username' => 'alice',
                    'password' => 'pass123'
                ]
            ]
        ];

        SecureLogger::debug('API request', $context);

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('token123', $content, "Nested token should be masked");
        $this->assertNotContains('pass123', $content, "Nested password should be masked");
        $this->assertContains('alice', $content, "Username should be logged");
    }

    public function testLogLevels() {
        SecureLogger::init($this->testLogFile, 'WARNING');

        SecureLogger::debug('Debug message');
        SecureLogger::info('Info message');
        SecureLogger::warning('Warning message');
        SecureLogger::error('Error message');

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('Debug message', $content, "Debug should not be logged at WARNING level");
        $this->assertNotContains('Info message', $content, "Info should not be logged at WARNING level");
        $this->assertContains('Warning message', $content, "Warning should be logged");
        $this->assertContains('Error message', $content, "Error should be logged");
    }

    public function testCriticalLevel() {
        SecureLogger::critical('System failure');

        $content = file_get_contents($this->testLogFile);
        $this->assertContains('[CRITICAL]', $content, "Should include CRITICAL level");
        $this->assertContains('System failure', $content, "Should log critical message");
    }

    public function testExceptionLogging() {
        $exception = new Exception('Test exception', 500);

        SecureLogger::logException($exception);

        $content = file_get_contents($this->testLogFile);
        $this->assertContains('Exception', $content, "Should log exception class");
        $this->assertContains('Test exception', $content, "Should log exception message");
        $this->assertContains('[ERROR]', $content, "Should use ERROR level by default");
    }

    public function testExceptionWithSensitiveData() {
        $exception = new Exception('Database error: password=secret123');

        SecureLogger::logException($exception);

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('secret123', $content, "Exception message should be masked");
        $this->assertContains('***MASKED***', $content, "Should mask sensitive data in exceptions");
    }

    public function testPIDInclusion() {
        SecureLogger::info('Test with PID');

        $content = file_get_contents($this->testLogFile);
        $this->assertContains('[PID:', $content, "Should include process ID");
    }

    public function testTimestampFormat() {
        SecureLogger::info('Timestamp test');

        $content = file_get_contents($this->testLogFile);
        // Check for timestamp format: [YYYY-MM-DD HH:MM:SS]
        $this->assertTrue(preg_match('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $content) === 1,
            "Should include proper timestamp format");
    }

    public function testMultipleLogEntries() {
        SecureLogger::info('Entry 1');
        SecureLogger::warning('Entry 2');
        SecureLogger::error('Entry 3');

        $content = file_get_contents($this->testLogFile);
        $lines = explode("\n", trim($content));

        $this->assertEquals(3, count($lines), "Should have 3 log entries");
        $this->assertContains('Entry 1', $lines[0], "First entry should be present");
        $this->assertContains('Entry 2', $lines[1], "Second entry should be present");
        $this->assertContains('Entry 3', $lines[2], "Third entry should be present");
    }

    public function testLogRotation() {
        // Write enough data to trigger rotation (>10MB)
        $largeString = str_repeat('X', 1024 * 1024); // 1MB string

        for ($i = 0; $i < 12; $i++) {
            SecureLogger::info($largeString);
        }

        SecureLogger::rotate();

        // Check if rotation occurred
        $rotatedFiles = glob($this->testLogFile . '*');
        $this->assertTrue(count($rotatedFiles) > 0, "Should create rotated log files");
    }

    public function testAPIKeyMasking() {
        SecureLogger::info('Request with api_key=sk_live_abc123');

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('sk_live_abc123', $content, "API key should be masked");
        $this->assertContains('api_key=***MASKED***', $content, "Should mask API key");
    }

    public function testSecretMasking() {
        SecureLogger::debug('Config: secret=mysecretvalue');

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('mysecretvalue', $content, "Secret should be masked");
        $this->assertContains('secret=***MASKED***', $content, "Should mask secret");
    }

    public function testMixedSensitiveData() {
        $message = 'User alice@example.com logged in with password=secret123 and token=Bearer_xyz';

        SecureLogger::info($message);

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('alice@example.com', $content, "Email should be masked");
        $this->assertNotContains('secret123', $content, "Password should be masked");
        $this->assertNotContains('Bearer_xyz', $content, "Token should be masked");
    }

    public function testContextArrayWithMultipleSensitiveKeys() {
        $context = [
            'username' => 'alice',
            'passwd' => 'secret1',
            'pwd' => 'secret2',
            'user_password' => 'secret3',
            'normal_field' => 'visible'
        ];

        SecureLogger::info('User update', $context);

        $content = file_get_contents($this->testLogFile);
        $this->assertNotContains('secret1', $content, "passwd should be masked");
        $this->assertNotContains('secret2', $content, "pwd should be masked");
        $this->assertNotContains('secret3', $content, "user_password should be masked");
        $this->assertContains('alice', $content, "Username should be visible");
        $this->assertContains('visible', $content, "Normal field should be visible");
    }

    public function testEmptyMessage() {
        SecureLogger::info('');

        $content = file_get_contents($this->testLogFile);
        $this->assertContains('[INFO]', $content, "Should log even with empty message");
    }

    public function testNullContext() {
        SecureLogger::info('Message with null context', null);

        $content = file_get_contents($this->testLogFile);
        $this->assertContains('Message with null context', $content, "Should handle null context");
    }

    public function testLogFileCreation() {
        $newLogFile = sys_get_temp_dir() . '/new_log_' . uniqid() . '.log';
        SecureLogger::init($newLogFile, 'INFO');

        SecureLogger::info('Test');

        $this->assertTrue(file_exists($newLogFile), "Should create log file if it doesn't exist");

        @unlink($newLogFile);
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new SecureLoggerTest();

    SimpleTest::test('Basic logging', function() use ($test) {
        $test->setUp();
        $test->testBasicLogging();
        $test->tearDown();
    });

    SimpleTest::test('Password masking', function() use ($test) {
        $test->setUp();
        $test->testPasswordMasking();
        $test->tearDown();
    });

    SimpleTest::test('Authcode masking', function() use ($test) {
        $test->setUp();
        $test->testAuthcodeMasking();
        $test->tearDown();
    });

    SimpleTest::test('Private key masking', function() use ($test) {
        $test->setUp();
        $test->testPrivateKeyMasking();
        $test->tearDown();
    });

    SimpleTest::test('Token masking', function() use ($test) {
        $test->setUp();
        $test->testTokenMasking();
        $test->tearDown();
    });

    SimpleTest::test('Email masking', function() use ($test) {
        $test->setUp();
        $test->testEmailMasking();
        $test->tearDown();
    });

    SimpleTest::test('Context masking', function() use ($test) {
        $test->setUp();
        $test->testContextMasking();
        $test->tearDown();
    });

    SimpleTest::test('Log levels', function() use ($test) {
        $test->setUp();
        $test->testLogLevels();
        $test->tearDown();
    });

    SimpleTest::test('Exception logging', function() use ($test) {
        $test->setUp();
        $test->testExceptionLogging();
        $test->tearDown();
    });

    SimpleTest::test('Mixed sensitive data', function() use ($test) {
        $test->setUp();
        $test->testMixedSensitiveData();
        $test->tearDown();
    });

    SimpleTest::run();
}
