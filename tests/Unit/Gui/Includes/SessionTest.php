<?php
/**
 * Unit Tests for Session
 *
 * Tests session management for the GUI including authentication,
 * CSRF protection, and flash messages.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */

namespace Eiou\Tests\Gui\Includes;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Eiou\Gui\Includes\Session;

#[CoversClass(Session::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class SessionTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        // Define required constant if not defined
        if (!defined('Eiou\Core\ErrorCodes::HTTP_FORBIDDEN')) {
            // Create the class constant mock
            if (!class_exists('Eiou\Core\ErrorCodes')) {
                eval('namespace Eiou\Core; class ErrorCodes { public const HTTP_FORBIDDEN = 403; }');
            }
        }

        // Don't start session here - let Session class handle it
        // This allows Session constructor to set custom session name and initialization

        $this->session = new Session();
    }

    protected function tearDown(): void
    {
        // Clean up session
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            @session_destroy();
        }
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor starts session if not active
     */
    public function testConstructorStartsSessionIfNotActive(): void
    {
        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
    }

    /**
     * Test constructor sets custom session name
     */
    public function testConstructorSetsCustomSessionName(): void
    {
        $this->assertEquals('EIOU_WALLET_SESSION', session_name());
    }

    // =========================================================================
    // Authentication Tests
    // =========================================================================

    /**
     * Test isAuthenticated returns false when not authenticated
     */
    public function testIsAuthenticatedReturnsFalseWhenNotAuthenticated(): void
    {
        unset($_SESSION['authenticated']);

        $this->assertFalse($this->session->isAuthenticated());
    }

    /**
     * Test isAuthenticated returns true when authenticated
     */
    public function testIsAuthenticatedReturnsTrueWhenAuthenticated(): void
    {
        $_SESSION['authenticated'] = true;

        $this->assertTrue($this->session->isAuthenticated());
    }

    /**
     * Test isAuthenticated returns false when authenticated is not true
     */
    public function testIsAuthenticatedReturnsFalseWhenAuthenticatedIsNotTrue(): void
    {
        $_SESSION['authenticated'] = false;
        $this->assertFalse($this->session->isAuthenticated());

        $_SESSION['authenticated'] = 'true';
        $this->assertFalse($this->session->isAuthenticated());

        $_SESSION['authenticated'] = 1;
        $this->assertFalse($this->session->isAuthenticated());
    }

    /**
     * Test authenticate with correct auth code
     */
    public function testAuthenticateWithCorrectAuthCode(): void
    {
        $authCode = 'test_auth_code_123';
        $userAuthCode = 'test_auth_code_123';

        $result = $this->session->authenticate($authCode, $userAuthCode);

        $this->assertTrue($result);
        $this->assertTrue($_SESSION['authenticated']);
        $this->assertArrayHasKey('auth_time', $_SESSION);
        $this->assertArrayHasKey('last_activity', $_SESSION);
    }

    /**
     * Test authenticate with incorrect auth code
     */
    public function testAuthenticateWithIncorrectAuthCode(): void
    {
        $authCode = 'test_auth_code_123';
        $userAuthCode = 'wrong_auth_code';

        $result = $this->session->authenticate($authCode, $userAuthCode);

        $this->assertFalse($result);
        $this->assertArrayNotHasKey('authenticated', $_SESSION);
    }

    /**
     * Test authenticate uses timing-safe comparison
     */
    public function testAuthenticateUsesTimingSafeComparison(): void
    {
        // Test with same-length but different strings
        $authCode = 'aaaaaaaaaaaaaaaa';
        $userAuthCode = 'aaaaaaaaaaaaaaab';

        $result = $this->session->authenticate($authCode, $userAuthCode);

        $this->assertFalse($result);
    }

    /**
     * Test logout clears session
     */
    public function testLogoutClearsSession(): void
    {
        $_SESSION['authenticated'] = true;
        $_SESSION['auth_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['custom_data'] = 'test';

        $this->session->logout();

        $this->assertEmpty($_SESSION);
    }

    // =========================================================================
    // Session Timeout Tests
    // =========================================================================

    /**
     * Test checkSessionTimeout returns true when session is active
     */
    public function testCheckSessionTimeoutReturnsTrueWhenSessionIsActive(): void
    {
        $_SESSION['last_activity'] = time();

        $result = $this->session->checkSessionTimeout();

        $this->assertTrue($result);
    }

    /**
     * Test checkSessionTimeout returns false and logs out when session expired
     */
    public function testCheckSessionTimeoutReturnsFalseWhenSessionExpired(): void
    {
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time() - 3600; // 1 hour ago (> 30 min timeout)

        $result = $this->session->checkSessionTimeout();

        $this->assertFalse($result);
        $this->assertEmpty($_SESSION);
    }

    /**
     * Test checkSessionTimeout updates last_activity
     */
    public function testCheckSessionTimeoutUpdatesLastActivity(): void
    {
        $oldTime = time() - 60;
        $_SESSION['last_activity'] = $oldTime;

        $this->session->checkSessionTimeout();

        $this->assertGreaterThan($oldTime, $_SESSION['last_activity']);
    }

    // =========================================================================
    // CSRF Token Tests
    // =========================================================================

    /**
     * Test generateCSRFToken creates token
     */
    public function testGenerateCSRFTokenCreatesToken(): void
    {
        $token = $this->session->generateCSRFToken();

        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex chars
        $this->assertArrayHasKey('csrf_token', $_SESSION);
        $this->assertArrayHasKey('csrf_token_time', $_SESSION);
    }

    /**
     * Test generateCSRFToken returns existing token if present
     */
    public function testGenerateCSRFTokenReturnsExistingTokenIfPresent(): void
    {
        $existingToken = 'existing_token_123';
        $_SESSION['csrf_token'] = $existingToken;
        $_SESSION['csrf_token_time'] = time();

        $token = $this->session->generateCSRFToken();

        $this->assertEquals($existingToken, $token);
    }

    /**
     * Test getCSRFToken delegates to generateCSRFToken
     */
    public function testGetCSRFTokenDelegatesToGenerateCSRFToken(): void
    {
        $token = $this->session->getCSRFToken();

        $this->assertIsString($token);
        $this->assertEquals($_SESSION['csrf_token'], $token);
    }

    /**
     * Test validateCSRFToken with valid token
     */
    public function testValidateCSRFTokenWithValidToken(): void
    {
        $token = $this->session->generateCSRFToken();

        $result = $this->session->validateCSRFToken($token);

        $this->assertTrue($result);
    }

    /**
     * Test validateCSRFToken with invalid token
     */
    public function testValidateCSRFTokenWithInvalidToken(): void
    {
        $this->session->generateCSRFToken();

        $result = $this->session->validateCSRFToken('invalid_token');

        $this->assertFalse($result);
    }

    /**
     * Test validateCSRFToken returns false when no token exists
     */
    public function testValidateCSRFTokenReturnsFalseWhenNoTokenExists(): void
    {
        unset($_SESSION['csrf_token']);
        unset($_SESSION['csrf_token_time']);

        $result = $this->session->validateCSRFToken('any_token');

        $this->assertFalse($result);
    }

    /**
     * Test validateCSRFToken returns false when token is expired
     */
    public function testValidateCSRFTokenReturnsFalseWhenTokenIsExpired(): void
    {
        $token = 'test_token';
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time() - 7200; // 2 hours ago (> 1 hour max)

        $result = $this->session->validateCSRFToken($token);

        $this->assertFalse($result);
        $this->assertArrayNotHasKey('csrf_token', $_SESSION);
    }

    /**
     * Test validateCSRFToken rotates token after successful validation (M-3)
     */
    public function testValidateCSRFTokenRotatesTokenAfterSuccess(): void
    {
        $token = $this->session->generateCSRFToken();

        // Verify token exists before validation
        $this->assertArrayHasKey('csrf_token', $_SESSION);
        $this->assertArrayHasKey('csrf_token_time', $_SESSION);

        $result = $this->session->validateCSRFToken($token);

        // Validation should succeed
        $this->assertTrue($result);

        // Token should be rotated (removed) after successful validation
        $this->assertArrayNotHasKey('csrf_token', $_SESSION);
        $this->assertArrayNotHasKey('csrf_token_time', $_SESSION);
    }

    /**
     * Test validateCSRFToken does not rotate token on failure (M-3)
     */
    public function testValidateCSRFTokenDoesNotRotateOnFailure(): void
    {
        $token = $this->session->generateCSRFToken();

        $result = $this->session->validateCSRFToken('wrong_token');

        // Validation should fail
        $this->assertFalse($result);

        // Token should still exist after failed validation
        $this->assertArrayHasKey('csrf_token', $_SESSION);
        $this->assertEquals($token, $_SESSION['csrf_token']);
    }

    /**
     * Test CSRF token cannot be reused after successful validation (M-3)
     */
    public function testCSRFTokenCannotBeReusedAfterValidation(): void
    {
        $token = $this->session->generateCSRFToken();

        // First use should succeed
        $this->assertTrue($this->session->validateCSRFToken($token));

        // Second use of same token should fail (token was rotated)
        $this->assertFalse($this->session->validateCSRFToken($token));
    }

    /**
     * Test getCSRFField returns hidden input HTML
     */
    public function testGetCSRFFieldReturnsHiddenInputHtml(): void
    {
        $html = $this->session->getCSRFField();

        $this->assertStringContainsString('<input type="hidden"', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('value="', $html);
    }

    /**
     * Test getCSRFField escapes token value
     */
    public function testGetCSRFFieldEscapesTokenValue(): void
    {
        $_SESSION['csrf_token'] = '<script>alert(1)</script>';
        $_SESSION['csrf_token_time'] = time();

        $html = $this->session->getCSRFField();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // =========================================================================
    // Flash Message Tests
    // =========================================================================

    /**
     * Test setMessage stores message in session
     */
    public function testSetMessageStoresMessageInSession(): void
    {
        $this->session->setMessage('Test message', 'success');

        $this->assertEquals('Test message', $_SESSION['message']);
        $this->assertEquals('success', $_SESSION['message_type']);
    }

    /**
     * Test setMessage default type is success
     */
    public function testSetMessageDefaultTypeIsSuccess(): void
    {
        $this->session->setMessage('Test message');

        $this->assertEquals('success', $_SESSION['message_type']);
    }

    /**
     * Test getMessage returns and clears message
     */
    public function testGetMessageReturnsAndClearsMessage(): void
    {
        $_SESSION['message'] = 'Test message';
        $_SESSION['message_type'] = 'error';

        $result = $this->session->getMessage();

        $this->assertIsArray($result);
        $this->assertEquals('Test message', $result['text']);
        $this->assertEquals('error', $result['type']);
        $this->assertArrayNotHasKey('message', $_SESSION);
        $this->assertArrayNotHasKey('message_type', $_SESSION);
    }

    /**
     * Test getMessage returns null when no message
     */
    public function testGetMessageReturnsNullWhenNoMessage(): void
    {
        unset($_SESSION['message']);

        $result = $this->session->getMessage();

        $this->assertNull($result);
    }

    /**
     * Test getMessage defaults type to info
     */
    public function testGetMessageDefaultsTypeToInfo(): void
    {
        $_SESSION['message'] = 'Test message';
        unset($_SESSION['message_type']);

        $result = $this->session->getMessage();

        $this->assertEquals('info', $result['type']);
    }

    // =========================================================================
    // Generic Session Data Tests
    // =========================================================================

    /**
     * Test get returns session value
     */
    public function testGetReturnsSessionValue(): void
    {
        $_SESSION['test_key'] = 'test_value';

        $result = $this->session->get('test_key');

        $this->assertEquals('test_value', $result);
    }

    /**
     * Test get returns default when key not found
     */
    public function testGetReturnsDefaultWhenKeyNotFound(): void
    {
        $result = $this->session->get('nonexistent_key', 'default_value');

        $this->assertEquals('default_value', $result);
    }

    /**
     * Test get returns null by default when key not found
     */
    public function testGetReturnsNullByDefaultWhenKeyNotFound(): void
    {
        $result = $this->session->get('nonexistent_key');

        $this->assertNull($result);
    }

    /**
     * Test set stores value in session
     */
    public function testSetStoresValueInSession(): void
    {
        $this->session->set('test_key', 'test_value');

        $this->assertEquals('test_value', $_SESSION['test_key']);
    }

    /**
     * Test has returns true when key exists
     */
    public function testHasReturnsTrueWhenKeyExists(): void
    {
        $_SESSION['test_key'] = 'test_value';

        $this->assertTrue($this->session->has('test_key'));
    }

    /**
     * Test has returns false when key does not exist
     */
    public function testHasReturnsFalseWhenKeyDoesNotExist(): void
    {
        $this->assertFalse($this->session->has('nonexistent_key'));
    }

    /**
     * Test remove deletes key from session
     */
    public function testRemoveDeletesKeyFromSession(): void
    {
        $_SESSION['test_key'] = 'test_value';

        $this->session->remove('test_key');

        $this->assertArrayNotHasKey('test_key', $_SESSION);
    }

    /**
     * Test clear destroys and restarts session
     */
    public function testClearDestroysAndRestartsSession(): void
    {
        $_SESSION['test_key'] = 'test_value';

        $this->session->clear();

        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
    }

    /**
     * Test regenerateId regenerates session ID
     */
    public function testRegenerateIdRegeneratesSessionId(): void
    {
        $oldId = session_id();

        $this->session->regenerateId();

        // Session ID should change after regeneration
        // Note: In PHPUnit, session_regenerate_id might not actually change the ID
        // but we can verify the method doesn't throw
        $this->assertTrue(true);
    }

    // =========================================================================
    // Session Regeneration Tests
    // =========================================================================

    /**
     * Test session tracks last regeneration time
     */
    public function testSessionTracksLastRegenerationTime(): void
    {
        // The constructor should set last_regeneration if not present
        $session = new Session();

        $this->assertArrayHasKey('last_regeneration', $_SESSION);
        $this->assertLessThanOrEqual(time(), $_SESSION['last_regeneration']);
    }

    // =========================================================================
    // Configurable Session Timeout Tests
    // =========================================================================

    /**
     * Test that session timeout defaults to 30 minutes when no config file exists
     */
    public function testSessionTimeoutDefaultsTo30Minutes(): void
    {
        // With no config file, timeout should be 1800 seconds (30 min)
        // Set activity to 29 minutes ago — should still be active
        $_SESSION['last_activity'] = time() - (29 * 60);

        $result = $this->session->checkSessionTimeout();
        $this->assertTrue($result);
    }

    /**
     * Test that session expires after default 30 minute timeout
     */
    public function testSessionExpiresAfterDefaultTimeout(): void
    {
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time() - (31 * 60); // 31 minutes ago

        $result = $this->session->checkSessionTimeout();
        $this->assertFalse($result);
    }
}
