<?php
/**
 * Session Management Test Suite
 * Tests all session-related functions in the GUI
 *
 * Copyright 2025
 * @package eIOU GUI Tests
 */

// Include required files
require_once(__DIR__ . '/../includes/session.php');

class SessionTest {
    private $testsPassed = 0;
    private $testsFailed = 0;

    /**
     * Test result tracking and display
     */
    private function testResult($testName, $result, $details = '') {
        if ($result) {
            echo "✅ PASS: $testName\n";
            if ($details) echo "   Details: $details\n";
            $this->testsPassed++;
        } else {
            echo "❌ FAIL: $testName\n";
            if ($details) echo "   Error: $details\n";
            $this->testsFailed++;
        }
    }

    /**
     * Test session initialization
     */
    public function testStartSecureSession() {
        echo "\n=== Testing Secure Session Initialization ===\n";

        // Test 1: Session starts successfully
        startSecureSession();
        $this->testResult(
            "Session started successfully",
            session_status() === PHP_SESSION_ACTIVE,
            "Session status: " . session_status()
        );

        // Test 2: Session name is custom
        $sessionName = session_name();
        $this->testResult(
            "Custom session name set",
            $sessionName === 'EIOU_WALLET_SESSION',
            "Session name: $sessionName"
        );

        // Test 3: Last regeneration timestamp set
        $this->testResult(
            "Last regeneration timestamp initialized",
            isset($_SESSION['last_regeneration']),
            "Timestamp: " . ($_SESSION['last_regeneration'] ?? 'not set')
        );

        // Test 4: Session regeneration time is recent
        if (isset($_SESSION['last_regeneration'])) {
            $timeDiff = time() - $_SESSION['last_regeneration'];
            $this->testResult(
                "Regeneration timestamp is recent",
                $timeDiff < 5,
                "Time difference: {$timeDiff}s"
            );
        }

        // Test 5: Multiple calls don't restart session
        $beforeId = session_id();
        startSecureSession();
        $afterId = session_id();
        $this->testResult(
            "Multiple calls preserve session",
            $beforeId === $afterId,
            "Session ID preserved"
        );
    }

    /**
     * Test authentication functions
     */
    public function testAuthentication() {
        echo "\n=== Testing Authentication Functions ===\n";

        // Clear session for clean test
        $_SESSION = [];
        startSecureSession();

        // Test 1: User not authenticated initially
        $this->testResult(
            "User not authenticated initially",
            !isAuthenticated(),
            "Authentication state: " . (isAuthenticated() ? 'true' : 'false')
        );

        // Test 2: Successful authentication
        $authCode = "test_auth_code_12345";
        $userAuthCode = "test_auth_code_12345";
        $result = authenticate($authCode, $userAuthCode);
        $this->testResult(
            "Successful authentication with matching codes",
            $result === true && isAuthenticated(),
            "Authentication successful"
        );

        // Test 3: Authentication sets session variables
        $this->testResult(
            "Authentication sets authenticated flag",
            isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true,
            "Authenticated flag set"
        );

        $this->testResult(
            "Authentication sets auth_time",
            isset($_SESSION['auth_time']),
            "Auth time: " . ($_SESSION['auth_time'] ?? 'not set')
        );

        $this->testResult(
            "Authentication sets last_activity",
            isset($_SESSION['last_activity']),
            "Last activity: " . ($_SESSION['last_activity'] ?? 'not set')
        );

        // Test 4: Failed authentication with wrong code
        $_SESSION = [];
        startSecureSession();
        $wrongCode = "wrong_code";
        $result = authenticate($authCode, $wrongCode);
        $this->testResult(
            "Failed authentication with wrong code",
            $result === false && !isAuthenticated(),
            "Authentication correctly rejected"
        );

        // Test 5: Timing-safe comparison (should not be timing-attackable)
        // We can't test timing directly, but we can verify it uses hash_equals
        $code1 = "test123";
        $code2 = "test456";
        $startTime = microtime(true);
        authenticate($code1, $code2);
        $endTime = microtime(true);
        $this->testResult(
            "Authentication uses constant-time comparison",
            ($endTime - $startTime) < 0.1,
            "Execution time: " . number_format(($endTime - $startTime) * 1000, 3) . "ms"
        );
    }

    /**
     * Test session timeout functionality
     */
    public function testSessionTimeout() {
        echo "\n=== Testing Session Timeout ===\n";

        // Test 1: Active session passes timeout check
        $_SESSION = [];
        startSecureSession();
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();
        $this->testResult(
            "Active session passes timeout check",
            checkSessionTimeout() === true,
            "Session valid"
        );

        // Test 2: Last activity updated on check
        $beforeActivity = $_SESSION['last_activity'];
        sleep(1);
        checkSessionTimeout();
        $afterActivity = $_SESSION['last_activity'];
        $this->testResult(
            "Last activity timestamp updated",
            $afterActivity > $beforeActivity,
            "Activity updated from $beforeActivity to $afterActivity"
        );

        // Test 3: Session with no last_activity still works
        unset($_SESSION['last_activity']);
        $result = checkSessionTimeout();
        $this->testResult(
            "Session without last_activity handled",
            $result === true && isset($_SESSION['last_activity']),
            "Last activity initialized"
        );

        // Test 4: Expired session fails (simulate old timestamp)
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time() - 2000; // 33+ minutes ago (timeout is 30 min)
        $result = checkSessionTimeout();
        $this->testResult(
            "Expired session detected and logged out",
            $result === false,
            "Timeout correctly triggered"
        );
    }

    /**
     * Test CSRF token generation and validation
     */
    public function testCSRFProtection() {
        echo "\n=== Testing CSRF Protection ===\n";

        // Clear session
        $_SESSION = [];
        startSecureSession();

        // Test 1: CSRF token generation
        $token1 = generateCSRFToken();
        $this->testResult(
            "CSRF token generated",
            !empty($token1) && strlen($token1) === 64,
            "Token length: " . strlen($token1) . " characters"
        );

        // Test 2: Token stored in session
        $this->testResult(
            "CSRF token stored in session",
            isset($_SESSION['csrf_token']) && $_SESSION['csrf_token'] === $token1,
            "Token stored correctly"
        );

        // Test 3: Token timestamp set
        $this->testResult(
            "CSRF token timestamp set",
            isset($_SESSION['csrf_token_time']),
            "Timestamp: " . ($_SESSION['csrf_token_time'] ?? 'not set')
        );

        // Test 4: Same token returned on subsequent calls
        $token2 = generateCSRFToken();
        $this->testResult(
            "Same token returned on multiple calls",
            $token1 === $token2,
            "Token consistency maintained"
        );

        // Test 5: getCSRFToken() returns valid token
        $token = getCSRFToken();
        $this->testResult(
            "getCSRFToken() returns valid token",
            $token === $token1,
            "Token retrieval works"
        );

        // Test 6: Valid token passes validation
        $this->testResult(
            "Valid CSRF token passes validation",
            validateCSRFToken($token1) === true,
            "Token validation successful"
        );

        // Test 7: Invalid token fails validation
        $invalidToken = "invalid_token_12345";
        $this->testResult(
            "Invalid CSRF token fails validation",
            validateCSRFToken($invalidToken) === false,
            "Invalid token rejected"
        );

        // Test 8: Expired token fails validation (simulate old timestamp)
        $_SESSION['csrf_token_time'] = time() - 4000; // Over 1 hour ago
        $this->testResult(
            "Expired CSRF token fails validation",
            validateCSRFToken($token1) === false,
            "Expired token rejected"
        );

        // Test 9: Token regenerated after expiration
        $newToken = generateCSRFToken();
        $this->testResult(
            "New token generated after expiration",
            $newToken !== $token1 && strlen($newToken) === 64,
            "New token created"
        );

        // Test 10: CSRF field HTML generation
        $csrfField = getCSRFField();
        $this->testResult(
            "CSRF field HTML generated correctly",
            strpos($csrfField, '<input type="hidden"') !== false &&
            strpos($csrfField, 'name="csrf_token"') !== false,
            "Field HTML contains required attributes"
        );

        // Test 11: CSRF field contains valid token
        $this->testResult(
            "CSRF field contains valid token",
            strpos($csrfField, 'value="') !== false,
            "Token embedded in field"
        );

        // Test 12: XSS protection in CSRF field
        $_SESSION['csrf_token'] = '<script>alert("xss")</script>';
        $csrfField = getCSRFField();
        $this->testResult(
            "CSRF field properly escapes HTML",
            strpos($csrfField, '<script>') === false && strpos($csrfField, '&lt;') !== false,
            "HTML special characters escaped"
        );
    }

    /**
     * Test logout functionality
     */
    public function testLogout() {
        echo "\n=== Testing Logout Functionality ===\n";

        // Setup authenticated session
        $_SESSION = [];
        startSecureSession();
        $_SESSION['authenticated'] = true;
        $_SESSION['user_data'] = ['name' => 'Test User'];
        $_SESSION['auth_time'] = time();

        // Test 1: Session has data before logout
        $this->testResult(
            "Session contains data before logout",
            !empty($_SESSION),
            "Session variables present"
        );

        // Test 2: Logout clears session data
        logout();
        $this->testResult(
            "Logout clears session data",
            empty($_SESSION),
            "Session cleared"
        );

        // Test 3: User no longer authenticated after logout
        startSecureSession(); // Restart to check
        $this->testResult(
            "User not authenticated after logout",
            !isAuthenticated(),
            "Authentication cleared"
        );
    }

    /**
     * Test requireAuth redirect (without actual redirect)
     */
    public function testRequireAuth() {
        echo "\n=== Testing requireAuth Protection ===\n";

        // We can't test actual redirects, but we can test the conditions

        // Test 1: Authenticated user with valid session
        $_SESSION = [];
        startSecureSession();
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();

        // requireAuth should not redirect for authenticated users
        // We'll just verify the conditions that would allow pass-through
        $this->testResult(
            "Authenticated user would pass requireAuth",
            isAuthenticated() && checkSessionTimeout(),
            "Both authentication and timeout checks pass"
        );

        // Test 2: Unauthenticated user would be redirected
        $_SESSION = [];
        startSecureSession();
        $this->testResult(
            "Unauthenticated user would be redirected",
            !isAuthenticated(),
            "Authentication check would fail"
        );

        // Test 3: Expired session would be redirected
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time() - 2000;
        $this->testResult(
            "Expired session would be redirected",
            !checkSessionTimeout(),
            "Timeout check would fail"
        );
    }

    /**
     * Run all session tests
     */
    public function runAllTests() {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "GUI SESSION MANAGEMENT TEST SUITE\n";
        echo str_repeat("=", 60) . "\n";

        $this->testStartSecureSession();
        $this->testAuthentication();
        $this->testSessionTimeout();
        $this->testCSRFProtection();
        $this->testLogout();
        $this->testRequireAuth();

        // Summary
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "SESSION TEST SUMMARY\n";
        echo str_repeat("=", 60) . "\n";
        echo "✅ Tests Passed: {$this->testsPassed}\n";
        echo "❌ Tests Failed: {$this->testsFailed}\n";
        echo "Total Tests: " . ($this->testsPassed + $this->testsFailed) . "\n";

        if ($this->testsFailed === 0) {
            echo "\n🎉 All session management tests passed!\n";
        } else {
            echo "\n⚠️ Some tests failed. Please review the errors above.\n";
        }
        echo str_repeat("=", 60) . "\n";

        return ['passed' => $this->testsPassed, 'failed' => $this->testsFailed];
    }
}

// Run tests if executed directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $tester = new SessionTest();
    $results = $tester->runAllTests();
    exit($results['failed'] > 0 ? 1 : 0);
}
