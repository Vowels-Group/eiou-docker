<?php
/**
 * Unit Tests for Security Utility Class
 *
 * Tests XSS prevention, input sanitization, password hashing, and other security utilities.
 */

namespace Eiou\Tests\Utils;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Utils\Security;

#[CoversClass(Security::class)]
class SecurityTest extends TestCase
{
    /**
     * Test HTML encoding prevents XSS
     */
    public function testHtmlEncodePreventXss(): void
    {
        $malicious = '<script>alert("xss")</script>';
        $encoded = Security::htmlEncode($malicious);

        $this->assertStringNotContainsString('<script>', $encoded);
        $this->assertStringContainsString('&lt;script&gt;', $encoded);
    }

    /**
     * Test HTML encoding handles special characters
     */
    public function testHtmlEncodeSpecialCharacters(): void
    {
        $input = '"test" & <tag>';
        $encoded = Security::htmlEncode($input);

        $this->assertStringContainsString('&quot;', $encoded);
        $this->assertStringContainsString('&amp;', $encoded);
        $this->assertStringContainsString('&lt;', $encoded);
        $this->assertStringContainsString('&gt;', $encoded);
    }

    /**
     * Test JS encoding produces safe JSON
     */
    public function testJsEncodeSafeJson(): void
    {
        $data = ['key' => '<script>alert(1)</script>'];
        $encoded = Security::jsEncode($data);

        $this->assertStringNotContainsString('<script>', $encoded);
        $this->assertStringContainsString('\u003C', $encoded); // < encoded
    }

    /**
     * Test URL encoding
     */
    public function testUrlEncode(): void
    {
        $input = 'test value&param=1';
        $encoded = Security::urlEncode($input);

        $this->assertStringContainsString('%26', $encoded);
        $this->assertStringContainsString('%3D', $encoded);
    }

    /**
     * Test sanitize input removes null bytes
     */
    public function testSanitizeInputRemovesNullBytes(): void
    {
        $input = "test\x00injection";
        $sanitized = Security::sanitizeInput($input);

        $this->assertStringNotContainsString("\x00", $sanitized);
        $this->assertEquals('testinjection', $sanitized);
    }

    /**
     * Test sanitize input trims whitespace
     */
    public function testSanitizeInputTrimsWhitespace(): void
    {
        $input = '  test value  ';
        $sanitized = Security::sanitizeInput($input);

        $this->assertEquals('test value', $sanitized);
    }

    /**
     * Test mask sensitive data masks passwords
     */
    public function testMaskSensitiveDataMasksPasswords(): void
    {
        $data = ['username' => 'john', 'password' => 'secret123'];
        $masked = Security::maskSensitiveData($data);

        $this->assertEquals('john', $masked['username']);
        $this->assertEquals('***MASKED***', $masked['password']);
    }

    /**
     * Test mask sensitive data handles nested arrays
     */
    public function testMaskSensitiveDataHandlesNestedArrays(): void
    {
        $data = [
            'user' => [
                'name' => 'john',
                'private_key' => 'secret-key'
            ]
        ];
        $masked = Security::maskSensitiveData($data);

        $this->assertEquals('john', $masked['user']['name']);
        $this->assertEquals('***MASKED***', $masked['user']['private_key']);
    }

    /**
     * Test validate email with valid email
     */
    public function testValidateEmailWithValidEmail(): void
    {
        $this->assertTrue(Security::validateEmail('test@example.com'));
        $this->assertTrue(Security::validateEmail('user.name+tag@domain.co.uk'));
    }

    /**
     * Test validate email with invalid email
     */
    public function testValidateEmailWithInvalidEmail(): void
    {
        $this->assertFalse(Security::validateEmail('invalid'));
        $this->assertFalse(Security::validateEmail('test@'));
        $this->assertFalse(Security::validateEmail('@domain.com'));
    }

    /**
     * Test validate URL with valid URL
     */
    public function testValidateUrlWithValidUrl(): void
    {
        $this->assertTrue(Security::validateUrl('https://example.com'));
        $this->assertTrue(Security::validateUrl('http://localhost:8080/path'));
    }

    /**
     * Test validate URL with invalid URL
     */
    public function testValidateUrlWithInvalidUrl(): void
    {
        $this->assertFalse(Security::validateUrl('not-a-url'));
        $this->assertFalse(Security::validateUrl('ftp:/missing-slash'));
    }

    /**
     * Test validate IP with valid IPs
     */
    public function testValidateIpWithValidIps(): void
    {
        $this->assertTrue(Security::validateIp('192.168.1.1'));
        $this->assertTrue(Security::validateIp('::1'));
        $this->assertTrue(Security::validateIp('2001:db8::1'));
    }

    /**
     * Test validate IP with invalid IPs
     */
    public function testValidateIpWithInvalidIps(): void
    {
        $this->assertFalse(Security::validateIp('999.999.999.999'));
        $this->assertFalse(Security::validateIp('not-an-ip'));
    }

    /**
     * Test sanitize filename removes directory traversal
     */
    public function testSanitizeFilenameRemovesDirectoryTraversal(): void
    {
        $malicious = '../../../etc/passwd';
        $sanitized = Security::sanitizeFilename($malicious);

        $this->assertStringNotContainsString('..', $sanitized);
        $this->assertStringNotContainsString('/', $sanitized);
    }

    /**
     * Test sanitize filename removes special characters
     */
    public function testSanitizeFilenameRemovesSpecialCharacters(): void
    {
        $filename = 'test<script>.txt';
        $sanitized = Security::sanitizeFilename($filename);

        $this->assertStringNotContainsString('<', $sanitized);
        $this->assertStringNotContainsString('>', $sanitized);
    }

    /**
     * Test generate secure token returns hex string
     */
    public function testGenerateSecureTokenReturnsHexString(): void
    {
        $token = Security::generateSecureToken(32);

        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex chars
        $this->assertTrue(ctype_xdigit($token));
    }

    /**
     * Test generate secure token produces unique tokens
     */
    public function testGenerateSecureTokenProducesUniqueTokens(): void
    {
        $token1 = Security::generateSecureToken();
        $token2 = Security::generateSecureToken();

        $this->assertNotEquals($token1, $token2);
    }

    /**
     * Test hash password produces valid hash
     */
    public function testHashPasswordProducesValidHash(): void
    {
        $password = 'mySecurePassword123';
        $hash = Security::hashPassword($password);

        $this->assertNotEquals($password, $hash);
        $this->assertTrue(Security::verifyPassword($password, $hash));
    }

    /**
     * Test verify password with wrong password
     */
    public function testVerifyPasswordWithWrongPassword(): void
    {
        $hash = Security::hashPassword('correctPassword');

        $this->assertFalse(Security::verifyPassword('wrongPassword', $hash));
    }

    /**
     * Test needs rehash detects old algorithm
     */
    public function testNeedsRehashWithCurrentAlgorithm(): void
    {
        $hash = Security::hashPassword('test');

        // Fresh hash shouldn't need rehashing
        $this->assertFalse(Security::needsRehash($hash));
    }

    /**
     * Test validate length with valid string
     */
    public function testValidateLengthWithValidString(): void
    {
        $this->assertTrue(Security::validateLength('test', 1, 10));
        $this->assertTrue(Security::validateLength('hello', 5, 5));
    }

    /**
     * Test validate length with invalid string
     */
    public function testValidateLengthWithInvalidString(): void
    {
        $this->assertFalse(Security::validateLength('', 1, 10)); // Too short
        $this->assertFalse(Security::validateLength('very long string', 1, 5)); // Too long
    }

    /**
     * Test sanitize array recursively sanitizes values
     */
    public function testSanitizeArrayRecursively(): void
    {
        $data = [
            'name' => "  test\x00value  ",
            'nested' => [
                'key' => "  nested\x00  "
            ]
        ];

        $sanitized = Security::sanitizeArray($data);

        $this->assertEquals('testvalue', $sanitized['name']);
        $this->assertEquals('nested', $sanitized['nested']['key']);
    }

    /**
     * Test timing safe equals with matching strings
     */
    public function testTimingSafeEqualsWithMatchingStrings(): void
    {
        $this->assertTrue(Security::timingSafeEquals('secret', 'secret'));
    }

    /**
     * Test timing safe equals with different strings
     */
    public function testTimingSafeEqualsWithDifferentStrings(): void
    {
        $this->assertFalse(Security::timingSafeEquals('secret', 'different'));
    }

    /**
     * Test sanitize int with valid integer
     */
    public function testSanitizeIntWithValidInteger(): void
    {
        $this->assertEquals(42, Security::sanitizeInt('42'));
        $this->assertEquals(-10, Security::sanitizeInt('-10'));
    }

    /**
     * Test sanitize int with invalid input
     */
    public function testSanitizeIntWithInvalidInput(): void
    {
        $this->assertNull(Security::sanitizeInt('not-a-number'));
        $this->assertNull(Security::sanitizeInt('12.34'));
    }

    /**
     * Test sanitize int respects min/max bounds
     */
    public function testSanitizeIntRespectsBounds(): void
    {
        $this->assertNull(Security::sanitizeInt('5', 10, 20)); // Below min
        $this->assertNull(Security::sanitizeInt('25', 10, 20)); // Above max
        $this->assertEquals(15, Security::sanitizeInt('15', 10, 20)); // Within bounds
    }

    /**
     * Test sanitize float with valid float
     */
    public function testSanitizeFloatWithValidFloat(): void
    {
        $this->assertEquals(3.14, Security::sanitizeFloat('3.14'));
        $this->assertEquals(-2.5, Security::sanitizeFloat('-2.5'));
    }

    /**
     * Test sanitize float with invalid input
     */
    public function testSanitizeFloatWithInvalidInput(): void
    {
        $this->assertNull(Security::sanitizeFloat('not-a-number'));
    }

    /**
     * Test sanitize float respects bounds
     */
    public function testSanitizeFloatRespectsBounds(): void
    {
        $this->assertNull(Security::sanitizeFloat('1.0', 5.0, 10.0)); // Below min
        $this->assertNull(Security::sanitizeFloat('15.0', 5.0, 10.0)); // Above max
        $this->assertEquals(7.5, Security::sanitizeFloat('7.5', 5.0, 10.0)); // Within bounds
    }

    /**
     * Test mask sensitive data with custom keys
     */
    public function testMaskSensitiveDataWithCustomKeys(): void
    {
        $data = ['api_key' => '12345', 'public_data' => 'visible'];
        $masked = Security::maskSensitiveData($data, ['api_key']);

        $this->assertEquals('***MASKED***', $masked['api_key']);
        $this->assertEquals('visible', $masked['public_data']);
    }

    // =========================================================================
    // M-7: stripNullBytes() rename tests
    // =========================================================================

    /**
     * Test stripNullBytes removes null bytes (M-7)
     */
    public function testStripNullBytesRemovesNullBytes(): void
    {
        $input = "test\x00injection";
        $result = Security::stripNullBytes($input);

        $this->assertStringNotContainsString("\x00", $result);
        $this->assertEquals('testinjection', $result);
    }

    /**
     * Test stripNullBytes trims whitespace (M-7)
     */
    public function testStripNullBytesTrimsWhitespace(): void
    {
        $input = '  test value  ';
        $result = Security::stripNullBytes($input);

        $this->assertEquals('test value', $result);
    }

    /**
     * Test deprecated sanitizeInput alias still works (M-7)
     */
    public function testSanitizeInputAliasCallsStripNullBytes(): void
    {
        $input = "alias\x00test";
        $direct = Security::stripNullBytes($input);
        $alias = Security::sanitizeInput($input);

        $this->assertEquals($direct, $alias);
        $this->assertEquals('aliastest', $alias);
    }

    /**
     * Test sanitizeArray uses stripNullBytes internally (M-7)
     */
    public function testSanitizeArrayUsesStripNullBytesInternally(): void
    {
        $data = [
            'key1' => "val\x00ue1",
            'nested' => ['key2' => "val\x00ue2"]
        ];

        $result = Security::sanitizeArray($data);

        $this->assertEquals('value1', $result['key1']);
        $this->assertEquals('value2', $result['nested']['key2']);
    }

    // =========================================================================
    // C-2: getClientIp() centralized IP resolution tests
    // =========================================================================

    /**
     * Test getClientIp returns REMOTE_ADDR when no trusted proxies (C-2)
     */
    public function testGetClientIpReturnsRemoteAddrWhenNoTrustedProxies(): void
    {
        $originalServer = $_SERVER;
        $originalEnv = getenv('TRUSTED_PROXIES');

        try {
            putenv('TRUSTED_PROXIES=');
            $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';

            $result = Security::getClientIp();

            $this->assertEquals('203.0.113.50', $result);
        } finally {
            $_SERVER = $originalServer;
            if ($originalEnv !== false) {
                putenv("TRUSTED_PROXIES=$originalEnv");
            } else {
                putenv('TRUSTED_PROXIES');
            }
        }
    }

    /**
     * Test getClientIp trusts proxy headers from trusted proxy (C-2)
     */
    public function testGetClientIpTrustsHeadersFromTrustedProxy(): void
    {
        $originalServer = $_SERVER;
        $originalEnv = getenv('TRUSTED_PROXIES');

        try {
            putenv('TRUSTED_PROXIES=10.0.0.1');
            $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';

            $result = Security::getClientIp();

            $this->assertEquals('203.0.113.99', $result);
        } finally {
            $_SERVER = $originalServer;
            if ($originalEnv !== false) {
                putenv("TRUSTED_PROXIES=$originalEnv");
            } else {
                putenv('TRUSTED_PROXIES');
            }
        }
    }

    /**
     * Test getClientIp ignores proxy headers from untrusted source (C-2)
     */
    public function testGetClientIpIgnoresHeadersFromUntrustedSource(): void
    {
        $originalServer = $_SERVER;
        $originalEnv = getenv('TRUSTED_PROXIES');

        try {
            putenv('TRUSTED_PROXIES=10.0.0.1');
            $_SERVER['REMOTE_ADDR'] = '192.168.1.100'; // Not in trusted list
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

            $result = Security::getClientIp();

            // Should return REMOTE_ADDR, not the spoofed header
            $this->assertEquals('192.168.1.100', $result);
        } finally {
            $_SERVER = $originalServer;
            if ($originalEnv !== false) {
                putenv("TRUSTED_PROXIES=$originalEnv");
            } else {
                putenv('TRUSTED_PROXIES');
            }
        }
    }

    /**
     * Test getClientIp prefers CF-Connecting-IP over X-Forwarded-For (C-2)
     */
    public function testGetClientIpPrefersCfConnectingIp(): void
    {
        $originalServer = $_SERVER;
        $originalEnv = getenv('TRUSTED_PROXIES');

        try {
            putenv('TRUSTED_PROXIES=10.0.0.1');
            $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
            $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.10';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';

            $result = Security::getClientIp();

            $this->assertEquals('198.51.100.10', $result);
        } finally {
            $_SERVER = $originalServer;
            if ($originalEnv !== false) {
                putenv("TRUSTED_PROXIES=$originalEnv");
            } else {
                putenv('TRUSTED_PROXIES');
            }
        }
    }

    /**
     * Test getClientIp takes first IP from X-Forwarded-For chain (C-2)
     */
    public function testGetClientIpTakesFirstIpFromForwardedFor(): void
    {
        $originalServer = $_SERVER;
        $originalEnv = getenv('TRUSTED_PROXIES');

        try {
            putenv('TRUSTED_PROXIES=10.0.0.1');
            $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
            unset($_SERVER['HTTP_CF_CONNECTING_IP']);
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 10.0.0.2, 10.0.0.3';

            $result = Security::getClientIp();

            $this->assertEquals('203.0.113.50', $result);
        } finally {
            $_SERVER = $originalServer;
            if ($originalEnv !== false) {
                putenv("TRUSTED_PROXIES=$originalEnv");
            } else {
                putenv('TRUSTED_PROXIES');
            }
        }
    }

    /**
     * Test getClientIp returns default when REMOTE_ADDR missing (C-2)
     */
    public function testGetClientIpReturnsDefaultWhenRemoteAddrMissing(): void
    {
        $originalServer = $_SERVER;
        $originalEnv = getenv('TRUSTED_PROXIES');

        try {
            putenv('TRUSTED_PROXIES=');
            unset($_SERVER['REMOTE_ADDR']);

            $result = Security::getClientIp();

            $this->assertEquals('0.0.0.0', $result);
        } finally {
            $_SERVER = $originalServer;
            if ($originalEnv !== false) {
                putenv("TRUSTED_PROXIES=$originalEnv");
            } else {
                putenv('TRUSTED_PROXIES');
            }
        }
    }
}
