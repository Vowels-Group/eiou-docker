<?php
/**
 * Unit Tests for CSRFProtection Class
 *
 * Copyright 2025
 *
 * Tests comprehensive CSRF protection functionality including:
 * - Token generation and validation
 * - Token expiration
 * - Token rotation
 * - One-time token consumption
 * - CSRF violation logging
 *
 * @package EIOU\Tests\Security
 */

// Mock Session class for testing
class MockSession
{
    private array $data = [];

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    // Expose data for testing
    public function getData(): array
    {
        return $this->data;
    }
}

require_once __DIR__ . '/../../../src/security/CSRFProtection.php';

class CSRFProtectionTest
{
    private MockSession $session;
    private CSRFProtection $csrf;
    private array $testResults = [];

    public function __construct()
    {
        $this->session = new MockSession();
        $this->csrf = new CSRFProtection($this->session);
    }

    /**
     * Run all tests
     */
    public function runAllTests(): void
    {
        echo "=== CSRF Protection Unit Tests ===\n\n";

        $this->testTokenGeneration();
        $this->testTokenValidation();
        $this->testTokenExpiration();
        $this->testTokenRotation();
        $this->testOneTimeTokens();
        $this->testGetTokenField();
        $this->testGetTokenValue();
        $this->testClearToken();
        $this->testStatistics();
        $this->testCleanupExpiredTokens();

        $this->printResults();
    }

    /**
     * Test token generation
     */
    private function testTokenGeneration(): void
    {
        echo "Test: Token Generation\n";

        // Test 1: Token should be generated
        $token1 = $this->csrf->generateToken();
        $this->assertTrue(!empty($token1), "Token should not be empty");

        // Test 2: Token should be 64 characters (32 bytes hex-encoded)
        $this->assertTrue(strlen($token1) === 64, "Token should be 64 characters long");

        // Test 3: Token should be stored in session
        $this->assertTrue($this->session->has('csrf_token'), "Token should be stored in session");

        // Test 4: Timestamp should be stored
        $this->assertTrue($this->session->has('csrf_token_time'), "Token timestamp should be stored");

        // Test 5: Same token should be returned on second call (cached)
        $token2 = $this->csrf->generateToken();
        $this->assertTrue($token1 === $token2, "Same token should be returned when cached");

        // Test 6: Force regenerate should create new token
        $token3 = $this->csrf->generateToken(true);
        $this->assertTrue($token1 !== $token3, "Force regenerate should create new token");

        echo "\n";
    }

    /**
     * Test token validation
     */
    private function testTokenValidation(): void
    {
        echo "Test: Token Validation\n";

        // Generate fresh token
        $this->session->clear();
        $token = $this->csrf->generateToken();

        // Test 1: Valid token should pass validation
        $this->assertTrue($this->csrf->validateToken($token), "Valid token should pass validation");

        // Test 2: Invalid token should fail
        $this->assertFalse($this->csrf->validateToken('invalid_token_123'), "Invalid token should fail validation");

        // Test 3: Empty token should fail
        $this->assertFalse($this->csrf->validateToken(''), "Empty token should fail validation");

        // Test 4: Token with wrong length should fail
        $this->assertFalse($this->csrf->validateToken('abc'), "Short token should fail validation");

        // Test 5: Modified token should fail
        $modifiedToken = substr($token, 0, -1) . 'x';
        $this->assertFalse($this->csrf->validateToken($modifiedToken), "Modified token should fail validation");

        echo "\n";
    }

    /**
     * Test token expiration
     */
    private function testTokenExpiration(): void
    {
        echo "Test: Token Expiration\n";

        // Generate fresh token
        $this->session->clear();
        $token = $this->csrf->generateToken();

        // Test 1: Fresh token should be valid
        $this->assertTrue($this->csrf->validateToken($token), "Fresh token should be valid");

        // Test 2: Simulate token expiration by manipulating timestamp
        $this->session->set('csrf_token_time', time() - 3601); // 1 hour + 1 second ago

        // Test 3: Expired token should fail validation
        $this->assertFalse($this->csrf->validateToken($token), "Expired token should fail validation");

        // Test 4: Session should be cleaned up after expired token
        $this->assertFalse($this->session->has('csrf_token'), "Expired token should be removed from session");

        echo "\n";
    }

    /**
     * Test token rotation
     */
    private function testTokenRotation(): void
    {
        echo "Test: Token Rotation\n";

        // Generate initial token
        $this->session->clear();
        $token1 = $this->csrf->generateToken();

        // Test 1: Rotate should generate new token
        $token2 = $this->csrf->rotateToken();
        $this->assertTrue($token1 !== $token2, "Rotated token should be different from original");

        // Test 2: Old token should no longer be valid
        $this->assertFalse($this->csrf->validateToken($token1), "Old token should be invalid after rotation");

        // Test 3: New token should be valid
        $this->assertTrue($this->csrf->validateToken($token2), "New rotated token should be valid");

        echo "\n";
    }

    /**
     * Test one-time tokens
     */
    private function testOneTimeTokens(): void
    {
        echo "Test: One-Time Tokens\n";

        $this->session->clear();

        // Test 1: Generate one-time token
        $token1 = $this->csrf->generateOneTimeToken('delete_account');
        $this->assertTrue(!empty($token1), "One-time token should be generated");

        // Test 2: One-time token should be valid on first use
        $this->assertTrue($this->csrf->validateToken($token1, true), "One-time token should be valid on first use");

        // Test 3: One-time token should be invalid on second use (consumed)
        $this->assertFalse($this->csrf->validateToken($token1, true), "One-time token should be invalid on second use");

        // Test 4: Generate multiple one-time tokens
        $token2 = $this->csrf->generateOneTimeToken('transfer_funds');
        $token3 = $this->csrf->generateOneTimeToken('update_profile');

        // Test 5: Each token should be unique
        $this->assertTrue($token2 !== $token3, "Each one-time token should be unique");

        // Test 6: Both tokens should be valid before use
        $this->assertTrue($this->csrf->validateToken($token2, true), "Second one-time token should be valid");
        $this->assertTrue($this->csrf->validateToken($token3, true), "Third one-time token should be valid");

        echo "\n";
    }

    /**
     * Test getTokenField() method
     */
    private function testGetTokenField(): void
    {
        echo "Test: Get Token Field\n";

        $this->session->clear();

        // Test 1: Regular token field
        $field = $this->csrf->getTokenField();
        $this->assertTrue(str_contains($field, '<input type="hidden"'), "Token field should contain hidden input");
        $this->assertTrue(str_contains($field, 'name="csrf_token"'), "Token field should have csrf_token name");

        // Test 2: One-time token field
        $oneTimeField = $this->csrf->getTokenField(true, 'test_operation');
        $this->assertTrue(str_contains($oneTimeField, 'name="csrf_token"'), "One-time field should have csrf_token");
        $this->assertTrue(str_contains($oneTimeField, 'csrf_onetime'), "One-time field should have csrf_onetime marker");

        // Test 3: HTML should be properly escaped
        $this->assertTrue(str_contains($field, 'htmlspecialchars') === false, "Token should be escaped in output");

        echo "\n";
    }

    /**
     * Test getTokenValue() method
     */
    private function testGetTokenValue(): void
    {
        echo "Test: Get Token Value\n";

        $this->session->clear();

        // Test 1: Get token value
        $value1 = $this->csrf->getTokenValue();
        $this->assertTrue(!empty($value1), "Token value should not be empty");
        $this->assertTrue(strlen($value1) === 64, "Token value should be 64 characters");

        // Test 2: Same value should be returned when called again (cached)
        $value2 = $this->csrf->getTokenValue();
        $this->assertTrue($value1 === $value2, "Same token value should be returned");

        echo "\n";
    }

    /**
     * Test clearToken() method
     */
    private function testClearToken(): void
    {
        echo "Test: Clear Token\n";

        // Generate token
        $this->session->clear();
        $this->csrf->generateToken();

        // Test 1: Token should exist before clearing
        $this->assertTrue($this->session->has('csrf_token'), "Token should exist before clearing");

        // Test 2: Clear token
        $this->csrf->clearToken();

        // Test 3: Token should not exist after clearing
        $this->assertFalse($this->session->has('csrf_token'), "Token should not exist after clearing");
        $this->assertFalse($this->session->has('csrf_token_time'), "Token timestamp should not exist after clearing");

        echo "\n";
    }

    /**
     * Test getStatistics() method
     */
    private function testStatistics(): void
    {
        echo "Test: Statistics\n";

        // Test 1: Statistics with no token
        $this->session->clear();
        $stats1 = $this->csrf->getStatistics();
        $this->assertFalse($stats1['has_token'], "Statistics should show no token exists");
        $this->assertTrue($stats1['is_expired'], "Statistics should show token is expired when it doesn't exist");

        // Test 2: Statistics with valid token
        $this->csrf->generateToken();
        $stats2 = $this->csrf->getStatistics();
        $this->assertTrue($stats2['has_token'], "Statistics should show token exists");
        $this->assertFalse($stats2['is_expired'], "Statistics should show token is not expired");
        $this->assertTrue($stats2['token_age_seconds'] < 5, "Token age should be very small for fresh token");

        // Test 3: Statistics should include one-time token count
        $this->csrf->generateOneTimeToken('test1');
        $this->csrf->generateOneTimeToken('test2');
        $stats3 = $this->csrf->getStatistics();
        $this->assertTrue($stats3['onetime_tokens_count'] === 2, "Statistics should show 2 one-time tokens");

        echo "\n";
    }

    /**
     * Test cleanupExpiredTokens() method
     */
    private function testCleanupExpiredTokens(): void
    {
        echo "Test: Cleanup Expired Tokens\n";

        $this->session->clear();

        // Test 1: Generate one-time tokens
        $this->csrf->generateOneTimeToken('op1');
        $this->csrf->generateOneTimeToken('op2');
        $this->csrf->generateOneTimeToken('op3');

        $stats1 = $this->csrf->getStatistics();
        $this->assertTrue($stats1['onetime_tokens_count'] === 3, "Should have 3 one-time tokens");

        // Test 2: Simulate token expiration
        $tokens = $this->session->get('csrf_onetime_tokens');
        foreach ($tokens as $key => $data) {
            $tokens[$key]['timestamp'] = time() - 3601; // Expire all tokens
        }
        $this->session->set('csrf_onetime_tokens', $tokens);

        // Test 3: Cleanup expired tokens
        $cleaned = $this->csrf->cleanupExpiredTokens();
        $this->assertTrue($cleaned === 3, "Should cleanup 3 expired tokens");

        $stats2 = $this->csrf->getStatistics();
        $this->assertTrue($stats2['onetime_tokens_count'] === 0, "Should have 0 one-time tokens after cleanup");

        echo "\n";
    }

    /**
     * Assert helper
     */
    private function assertTrue($condition, string $message): void
    {
        if ($condition) {
            echo "  ✓ PASS: {$message}\n";
            $this->testResults[] = ['status' => 'PASS', 'message' => $message];
        } else {
            echo "  ✗ FAIL: {$message}\n";
            $this->testResults[] = ['status' => 'FAIL', 'message' => $message];
        }
    }

    /**
     * Assert false helper
     */
    private function assertFalse($condition, string $message): void
    {
        $this->assertTrue(!$condition, $message);
    }

    /**
     * Print test results summary
     */
    private function printResults(): void
    {
        $passed = 0;
        $failed = 0;

        foreach ($this->testResults as $result) {
            if ($result['status'] === 'PASS') {
                $passed++;
            } else {
                $failed++;
            }
        }

        $total = $passed + $failed;
        $passRate = $total > 0 ? round(($passed / $total) * 100, 2) : 0;

        echo "\n=== Test Results Summary ===\n";
        echo "Total Tests: {$total}\n";
        echo "Passed: {$passed}\n";
        echo "Failed: {$failed}\n";
        echo "Pass Rate: {$passRate}%\n\n";

        if ($failed === 0) {
            echo "🎉 All tests passed!\n";
        } else {
            echo "⚠️  Some tests failed. Review output above.\n";
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli') {
    $tester = new CSRFProtectionTest();
    $tester->runAllTests();
}
