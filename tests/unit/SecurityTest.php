<?php
/**
 * Unit tests for Security utility class
 * Tests XSS prevention, CSRF tokens, output encoding, and security headers
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/utils/Security.php';

class SecurityTest extends TestCase {

    public function setUp() {
        parent::setUp();
        // Start session for CSRF tests
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function tearDown() {
        parent::tearDown();
        // Clean up session
        if (isset($_SESSION['csrf_token'])) {
            unset($_SESSION['csrf_token']);
        }
    }

    public function testHTMLEncoding() {
        $dangerous = '<script>alert("XSS")</script>';
        $encoded = Security::htmlEncode($dangerous);

        $this->assertNotContains('<script>', $encoded, "Script tags should be encoded");
        $this->assertContains('&lt;script&gt;', $encoded, "Should contain encoded tags");
    }

    public function testHTMLEncodingWithQuotes() {
        $dangerous = 'Hello "World" and \'User\'';
        $encoded = Security::htmlEncode($dangerous);

        $this->assertContains('&quot;', $encoded, "Double quotes should be encoded");
        $this->assertContains('&#039;', $encoded, "Single quotes should be encoded");
    }

    public function testJavaScriptEncoding() {
        $data = ['name' => 'Alice', 'message' => '<script>alert("XSS")</script>'];
        $encoded = Security::jsEncode($data);

        $this->assertNotContains('<script>', $encoded, "Script tags should be encoded for JS");
        $this->assertContains('\\u003C', $encoded, "Should use Unicode encoding");
    }

    public function testURLEncoding() {
        $param = 'hello world & special=chars';
        $encoded = Security::urlEncode($param);

        $this->assertNotContains(' ', $encoded, "Spaces should be encoded");
        $this->assertNotContains('&', $encoded, "Ampersands should be encoded");
        $this->assertContains('%20', $encoded, "Should contain encoded space");
    }

    public function testInputSanitization() {
        $input = "  test\x00string  ";
        $sanitized = Security::sanitizeInput($input);

        $this->assertNotContains("\x00", $sanitized, "Null bytes should be removed");
        $this->assertEquals('test string', $sanitized, "Should trim whitespace and remove null bytes");
    }

    public function testCSRFTokenGeneration() {
        $token1 = Security::generateCSRFToken();
        $this->assertNotNull($token1, "Should generate a token");
        $this->assertEquals(64, strlen($token1), "Token should be 64 characters (32 bytes hex)");

        // Second call should return the same token (from session)
        $token2 = Security::generateCSRFToken();
        $this->assertEquals($token1, $token2, "Should return same token from session");
    }

    public function testCSRFTokenValidation() {
        $token = Security::generateCSRFToken();

        // Valid token should pass
        $this->assertTrue(Security::validateCSRFToken($token), "Valid token should pass validation");

        // Invalid token should fail
        $this->assertFalse(Security::validateCSRFToken('invalid_token'), "Invalid token should fail validation");

        // Missing token should fail
        $this->assertFalse(Security::validateCSRFToken(''), "Empty token should fail validation");
    }

    public function testCSRFTokenTimingSafeComparison() {
        $token = Security::generateCSRFToken();

        // Create a slightly different token (same length)
        $wrongToken = substr($token, 0, -1) . 'x';

        $this->assertFalse(Security::validateCSRFToken($wrongToken), "Wrong token should fail");
        $this->assertTrue(Security::validateCSRFToken($token), "Correct token should pass");
    }

    public function testMaskSensitiveData() {
        $data = [
            'username' => 'alice',
            'password' => 'secret123',
            'api_key' => 'abc123def456',
            'public_info' => 'visible'
        ];

        $masked = Security::maskSensitiveData($data);

        $this->assertEquals('***MASKED***', $masked['password'], "Password should be masked");
        $this->assertEquals('alice', $masked['username'], "Username should not be masked");
        $this->assertEquals('visible', $masked['public_info'], "Public info should not be masked");
    }

    public function testMaskSensitiveDataNested() {
        $data = [
            'user' => [
                'name' => 'alice',
                'credentials' => [
                    'password' => 'secret',
                    'token' => 'abc123'
                ]
            ]
        ];

        $masked = Security::maskSensitiveData($data);

        $this->assertEquals('***MASKED***', $masked['user']['credentials']['password'], "Nested password should be masked");
        $this->assertEquals('***MASKED***', $masked['user']['credentials']['token'], "Nested token should be masked");
        $this->assertEquals('alice', $masked['user']['name'], "Nested name should not be masked");
    }

    public function testMaskSensitiveDataWithCustomKeys() {
        $data = [
            'ssn' => '123-45-6789',
            'credit_card' => '4111-1111-1111-1111',
            'name' => 'Alice'
        ];

        $masked = Security::maskSensitiveData($data, ['ssn', 'credit_card']);

        $this->assertEquals('***MASKED***', $masked['ssn'], "SSN should be masked");
        $this->assertEquals('***MASKED***', $masked['credit_card'], "Credit card should be masked");
        $this->assertEquals('Alice', $masked['name'], "Name should not be masked");
    }

    public function testSafeErrorMessageProduction() {
        $exception = new Exception("Database connection failed at /var/www/config.php:42");

        $safeMessage = Security::getSafeErrorMessage($exception, false);
        $this->assertEquals("An error occurred. Please try again later.", $safeMessage, "Should return generic message in production");
        $this->assertNotContains('Database', $safeMessage, "Should not expose details in production");
    }

    public function testSafeErrorMessageDevelopment() {
        putenv('APP_ENV=development');

        $exception = new Exception("Test error message");
        $debugMessage = Security::getSafeErrorMessage($exception, true);

        $this->assertContains("Test error message", $debugMessage, "Should include error details in debug mode");

        putenv('APP_ENV=testing');
    }

    public function testXSSPreventionInMultipleContexts() {
        $xssPayload = '"><script>alert(1)</script>';

        // HTML context
        $htmlSafe = Security::htmlEncode($xssPayload);
        $this->assertNotContains('<script>', $htmlSafe, "Should encode for HTML");

        // JavaScript context
        $jsSafe = Security::jsEncode($xssPayload);
        $this->assertNotContains('<script>', $jsSafe, "Should encode for JavaScript");

        // URL context
        $urlSafe = Security::urlEncode($xssPayload);
        $this->assertNotContains('<', $urlSafe, "Should encode for URL");
    }

    public function testEventHandlerXSS() {
        $payload = 'test" onload="alert(1)';
        $encoded = Security::htmlEncode($payload);

        $this->assertNotContains('onload=', $encoded, "Event handlers should be neutralized");
        $this->assertContains('&quot;', $encoded, "Quotes should be encoded");
    }

    public function testJavaScriptProtocolXSS() {
        $payload = 'javascript:alert(1)';
        $encoded = Security::htmlEncode($payload);

        $this->assertNotContains('javascript:', $encoded, "JavaScript protocol should be neutralized");
    }

    public function testUnicodeXSS() {
        $payload = "\u003Cscript\u003Ealert(1)\u003C/script\u003E";
        $encoded = Security::htmlEncode($payload);

        // The payload is already encoded, but should be double-encoded
        $this->assertNotContains('<script>', $encoded, "Unicode-encoded scripts should be safe");
    }

    public function testDataURIXSS() {
        $payload = 'data:text/html,<script>alert(1)</script>';
        $encoded = Security::htmlEncode($payload);

        $this->assertNotContains('<script>', $encoded, "Data URI scripts should be encoded");
    }

    public function testSVGXSS() {
        $payload = '<svg onload="alert(1)">';
        $encoded = Security::htmlEncode($payload);

        $this->assertNotContains('<svg', $encoded, "SVG tags should be encoded");
        $this->assertNotContains('onload=', $encoded, "Event handlers should be encoded");
    }

    public function testMultipleEncodingLayers() {
        $dangerous = '<script>alert("XSS")</script>';

        // Apply multiple encoding layers
        $encoded1 = Security::htmlEncode($dangerous);
        $encoded2 = Security::htmlEncode($encoded1);

        $this->assertNotContains('<script>', $encoded2, "Should handle multiple encoding layers");
        $this->assertContains('&amp;lt;', $encoded2, "Should show double encoding");
    }

    public function testEmptyAndNullValues() {
        $this->assertEquals('', Security::htmlEncode(''), "Empty string should remain empty");
        $this->assertEquals('', Security::htmlEncode(null), "Null should become empty string");
        $this->assertEquals('0', Security::htmlEncode('0'), "Zero should remain zero");
        $this->assertEquals('false', Security::htmlEncode('false'), "False string should remain");
    }

    public function testSpecialCharacterEncoding() {
        $input = "Line1\nLine2\rLine3\tTabbed";
        $encoded = Security::htmlEncode($input);

        // Newlines and tabs should be preserved in HTML encoding
        $this->assertContains("\n", $encoded, "Newlines should be preserved");
        $this->assertContains("\t", $encoded, "Tabs should be preserved");
    }

    public function testMaskSensitiveInObjects() {
        $obj = new stdClass();
        $obj->username = 'alice';
        $obj->password = 'secret';
        $obj->token = 'abc123';

        $masked = Security::maskSensitiveData($obj);

        $this->assertEquals('***MASKED***', $masked->password, "Object password should be masked");
        $this->assertEquals('***MASKED***', $masked->token, "Object token should be masked");
        $this->assertEquals('alice', $masked->username, "Object username should not be masked");
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new SecurityTest();

    SimpleTest::test('HTML encoding', function() use ($test) {
        $test->setUp();
        $test->testHTMLEncoding();
        $test->tearDown();
    });

    SimpleTest::test('HTML encoding with quotes', function() use ($test) {
        $test->setUp();
        $test->testHTMLEncodingWithQuotes();
        $test->tearDown();
    });

    SimpleTest::test('JavaScript encoding', function() use ($test) {
        $test->setUp();
        $test->testJavaScriptEncoding();
        $test->tearDown();
    });

    SimpleTest::test('URL encoding', function() use ($test) {
        $test->setUp();
        $test->testURLEncoding();
        $test->tearDown();
    });

    SimpleTest::test('Input sanitization', function() use ($test) {
        $test->setUp();
        $test->testInputSanitization();
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

    SimpleTest::test('Mask sensitive data', function() use ($test) {
        $test->setUp();
        $test->testMaskSensitiveData();
        $test->tearDown();
    });

    SimpleTest::test('Mask sensitive data nested', function() use ($test) {
        $test->setUp();
        $test->testMaskSensitiveDataNested();
        $test->tearDown();
    });

    SimpleTest::test('XSS prevention in multiple contexts', function() use ($test) {
        $test->setUp();
        $test->testXSSPreventionInMultipleContexts();
        $test->tearDown();
    });

    SimpleTest::run();
}
