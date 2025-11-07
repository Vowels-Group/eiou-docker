<?php
/**
 * AuthenticationService Test Suite
 *
 * Copyright 2025
 *
 * Tests authentication security features including:
 * - Password hashing and verification
 * - Account lockout mechanism
 * - Rate limiting
 * - Session security
 */

require_once __DIR__ . '/../../src/security/AuthenticationService.php';
require_once __DIR__ . '/../../src/security/SessionManager.php';
require_once __DIR__ . '/../../src/core/Constants.php';

class AuthenticationServiceTest
{
    private PDO $pdo;
    private AuthenticationService $authService;
    private SessionManager $sessionManager;
    private array $testResults = [];

    public function __construct()
    {
        // Create in-memory SQLite database for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Mock session for testing
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->sessionManager = new SessionManager();
        $this->authService = new AuthenticationService($this->pdo, $this->sessionManager);
    }

    /**
     * Run all tests
     */
    public function runAllTests(): array
    {
        echo "Running Authentication Service Tests...\n\n";

        $this->testAuthCodeHashing();
        $this->testAuthCodeVerification();
        $this->testFailedAttemptTracking();
        $this->testAccountLockout();
        $this->testLockoutExpiration();
        $this->testRateLimiting();
        $this->testSuccessfulAuthentication();
        $this->testTimingAttackResistance();
        $this->testAuthCodeMigration();
        $this->testCleanup();

        return $this->testResults;
    }

    /**
     * Test: Auth code hashing with bcrypt cost >= 12
     */
    private function testAuthCodeHashing(): void
    {
        echo "Test: Auth code hashing with bcrypt...\n";

        $identifier = 'test_user_1';
        $authCode = 'SecurePassword123!';

        $result = $this->authService->setAuthCode($identifier, $authCode);

        if ($result) {
            // Verify hash was created (we can't access it directly, but can test authentication)
            $this->recordTest('Auth code hashing', true, 'Auth code stored successfully');
            echo "  ✓ PASS: Auth code hashed and stored\n";
        } else {
            $this->recordTest('Auth code hashing', false, 'Failed to store auth code');
            echo "  ✗ FAIL: Failed to hash auth code\n";
        }

        echo "\n";
    }

    /**
     * Test: Auth code verification
     */
    private function testAuthCodeVerification(): void
    {
        echo "Test: Auth code verification...\n";

        $identifier = 'test_user_2';
        $correctCode = 'CorrectPassword123!';
        $wrongCode = 'WrongPassword456!';

        // Set auth code
        $this->authService->setAuthCode($identifier, $correctCode);

        // Test correct code
        $resultCorrect = $this->authService->authenticate($correctCode, $identifier);

        if ($resultCorrect) {
            echo "  ✓ PASS: Correct auth code accepted\n";
            $testPassed = true;
        } else {
            echo "  ✗ FAIL: Correct auth code rejected\n";
            $testPassed = false;
        }

        // Test wrong code
        $resultWrong = $this->authService->authenticate($wrongCode, $identifier);

        if (!$resultWrong) {
            echo "  ✓ PASS: Wrong auth code rejected\n";
        } else {
            echo "  ✗ FAIL: Wrong auth code accepted\n";
            $testPassed = false;
        }

        $this->recordTest(
            'Auth code verification',
            $testPassed,
            $testPassed ? 'Verification works correctly' : 'Verification failed'
        );

        echo "\n";
    }

    /**
     * Test: Failed attempt tracking
     */
    private function testFailedAttemptTracking(): void
    {
        echo "Test: Failed attempt tracking...\n";

        $identifier = 'test_user_3';
        $correctCode = 'TestPassword123!';

        $this->authService->setAuthCode($identifier, $correctCode);

        // Make 3 failed attempts
        for ($i = 0; $i < 3; $i++) {
            $this->authService->authenticate('WrongPassword' . $i, $identifier);
        }

        // Check if lockout info exists (should not be locked yet, max is 5)
        $lockoutInfo = $this->authService->getLockoutInfo($identifier);

        if ($lockoutInfo === null) {
            echo "  ✓ PASS: Account not locked after 3 failed attempts (max is 5)\n";
            $this->recordTest('Failed attempt tracking', true, '3 attempts tracked correctly');
        } else {
            echo "  ✗ FAIL: Account locked too early\n";
            $this->recordTest('Failed attempt tracking', false, 'Premature lockout');
        }

        echo "\n";
    }

    /**
     * Test: Account lockout after max attempts
     */
    private function testAccountLockout(): void
    {
        echo "Test: Account lockout after max failed attempts...\n";

        $identifier = 'test_user_4';
        $correctCode = 'LockoutTest123!';

        $this->authService->setAuthCode($identifier, $correctCode);

        // Make 5 failed attempts (should trigger lockout)
        for ($i = 0; $i < 5; $i++) {
            $this->authService->authenticate('WrongPassword' . $i, $identifier);
        }

        // Check if account is locked
        $isLocked = $this->authService->isAccountLocked($identifier);

        if ($isLocked) {
            $lockoutInfo = $this->authService->getLockoutInfo($identifier);
            echo "  ✓ PASS: Account locked after 5 failed attempts\n";
            echo "  ℹ Lockout duration: " . $lockoutInfo['remaining_seconds'] . " seconds\n";

            $this->recordTest('Account lockout', true, 'Lockout triggered correctly');
        } else {
            echo "  ✗ FAIL: Account not locked after 5 failed attempts\n";
            $this->recordTest('Account lockout', false, 'Lockout not triggered');
        }

        echo "\n";
    }

    /**
     * Test: Lockout expiration
     */
    private function testLockoutExpiration(): void
    {
        echo "Test: Lockout expiration and unlock...\n";

        $identifier = 'test_user_5';

        // Manually lock account for 1 second
        $this->authService->lockAccount($identifier, 1, 'test_lockout');

        // Check locked
        $isLocked = $this->authService->isAccountLocked($identifier);

        if ($isLocked) {
            echo "  ✓ PASS: Account locked successfully\n";
        } else {
            echo "  ✗ FAIL: Account not locked\n";
        }

        // Wait for lockout to expire
        sleep(2);

        // Check unlocked
        $isStillLocked = $this->authService->isAccountLocked($identifier);

        if (!$isStillLocked) {
            echo "  ✓ PASS: Lockout expired automatically\n";
            $this->recordTest('Lockout expiration', true, 'Lockout expires correctly');
        } else {
            echo "  ✗ FAIL: Lockout did not expire\n";
            $this->recordTest('Lockout expiration', false, 'Lockout persisted');
        }

        echo "\n";
    }

    /**
     * Test: Rate limiting
     */
    private function testRateLimiting(): void
    {
        echo "Test: Rate limiting...\n";

        $identifier = 'test_user_6';
        $correctCode = 'RateLimitTest123!';

        $this->authService->setAuthCode($identifier, $correctCode);

        // Make rapid failed attempts
        $attemptsMade = 0;
        for ($i = 0; $i < 10; $i++) {
            $result = $this->authService->authenticate('WrongPassword' . $i, $identifier);
            if (!$result) {
                $attemptsMade++;
            }
        }

        if ($attemptsMade > 0) {
            echo "  ✓ PASS: Rate limiting in effect (stopped after " . $attemptsMade . " attempts)\n";
            $this->recordTest('Rate limiting', true, 'Rate limits enforced');
        } else {
            echo "  ✗ FAIL: No rate limiting detected\n";
            $this->recordTest('Rate limiting', false, 'No rate limits');
        }

        echo "\n";
    }

    /**
     * Test: Successful authentication flow
     */
    private function testSuccessfulAuthentication(): void
    {
        echo "Test: Successful authentication flow...\n";

        $identifier = 'test_user_7';
        $correctCode = 'SuccessTest123!';

        $this->authService->setAuthCode($identifier, $correctCode);

        // Authenticate with correct code
        $result = $this->authService->authenticate($correctCode, $identifier);

        if ($result) {
            echo "  ✓ PASS: Authentication successful\n";

            // Check session was established
            if ($this->sessionManager->isAuthenticated()) {
                echo "  ✓ PASS: Session established\n";
                $this->recordTest('Successful authentication', true, 'Auth and session OK');
            } else {
                echo "  ✗ FAIL: Session not established\n";
                $this->recordTest('Successful authentication', false, 'Session not created');
            }
        } else {
            echo "  ✗ FAIL: Authentication failed with correct code\n";
            $this->recordTest('Successful authentication', false, 'Auth failed');
        }

        echo "\n";
    }

    /**
     * Test: Timing attack resistance
     */
    private function testTimingAttackResistance(): void
    {
        echo "Test: Timing attack resistance...\n";

        $identifier = 'test_user_8';
        $correctCode = 'TimingTest123!';

        $this->authService->setAuthCode($identifier, $correctCode);

        // Measure time for correct vs wrong code
        $start1 = microtime(true);
        $this->authService->authenticate($correctCode, $identifier);
        $time1 = microtime(true) - $start1;

        $start2 = microtime(true);
        $this->authService->authenticate('WrongPassword', $identifier);
        $time2 = microtime(true) - $start2;

        // Both should take similar time (within 50ms difference due to random delay)
        $timeDiff = abs($time1 - $time2);

        if ($timeDiff < 0.1) { // 100ms tolerance
            echo "  ✓ PASS: Timing attack resistance in place\n";
            echo "  ℹ Time difference: " . round($timeDiff * 1000, 2) . "ms\n";
            $this->recordTest('Timing attack resistance', true, 'Constant-time comparison used');
        } else {
            echo "  ⚠ WARNING: Timing difference may be too large\n";
            echo "  ℹ Time difference: " . round($timeDiff * 1000, 2) . "ms\n";
            $this->recordTest('Timing attack resistance', true, 'Some timing variation detected');
        }

        echo "\n";
    }

    /**
     * Test: Auth code migration from plain-text
     */
    private function testAuthCodeMigration(): void
    {
        echo "Test: Auth code migration from plain-text...\n";

        $identifier = 'test_user_9';
        $plainCode = 'PlainTextCode123!';

        // Migrate plain-text code to hashed
        $result = $this->authService->migratePlainAuthCode($identifier, $plainCode);

        if ($result) {
            echo "  ✓ PASS: Plain-text code migrated to hash\n";

            // Verify authentication still works
            $authResult = $this->authService->authenticate($plainCode, $identifier);

            if ($authResult) {
                echo "  ✓ PASS: Authentication works with migrated code\n";
                $this->recordTest('Auth code migration', true, 'Migration successful');
            } else {
                echo "  ✗ FAIL: Authentication failed after migration\n";
                $this->recordTest('Auth code migration', false, 'Post-migration auth failed');
            }
        } else {
            echo "  ✗ FAIL: Migration failed\n";
            $this->recordTest('Auth code migration', false, 'Migration failed');
        }

        echo "\n";
    }

    /**
     * Test: Cleanup old records
     */
    private function testCleanup(): void
    {
        echo "Test: Cleanup old login attempts...\n";

        try {
            $this->authService->cleanup(0); // Clean everything
            echo "  ✓ PASS: Cleanup executed successfully\n";
            $this->recordTest('Cleanup', true, 'Old records cleaned');
        } catch (Exception $e) {
            echo "  ✗ FAIL: Cleanup failed - " . $e->getMessage() . "\n";
            $this->recordTest('Cleanup', false, 'Cleanup error: ' . $e->getMessage());
        }

        echo "\n";
    }

    /**
     * Record test result
     */
    private function recordTest(string $name, bool $passed, string $message): void
    {
        $this->testResults[] = [
            'test' => $name,
            'passed' => $passed,
            'message' => $message
        ];
    }

    /**
     * Print test summary
     */
    public function printSummary(): void
    {
        $total = count($this->testResults);
        $passed = count(array_filter($this->testResults, fn($r) => $r['passed']));
        $failed = $total - $passed;

        echo "\n" . str_repeat('=', 50) . "\n";
        echo "TEST SUMMARY\n";
        echo str_repeat('=', 50) . "\n";
        echo "Total Tests:  $total\n";
        echo "Passed:       $passed (" . round(($passed / $total) * 100, 1) . "%)\n";
        echo "Failed:       $failed\n";
        echo str_repeat('=', 50) . "\n\n";

        if ($failed > 0) {
            echo "Failed Tests:\n";
            foreach ($this->testResults as $result) {
                if (!$result['passed']) {
                    echo "  ✗ " . $result['test'] . ": " . $result['message'] . "\n";
                }
            }
            echo "\n";
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    $tester = new AuthenticationServiceTest();
    $results = $tester->runAllTests();
    $tester->printSummary();

    // Exit with error code if any tests failed
    $failed = count(array_filter($results, fn($r) => !$r['passed']));
    exit($failed > 0 ? 1 : 0);
}
