<?php
/**
 * Unit tests for RateLimiter class
 * Tests rate limiting logic, blocking behavior, and IP handling
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/utils/RateLimiter.php';

class RateLimiterTest extends TestCase {

    private $pdo;
    private $rateLimiter;

    public function setUp() {
        parent::setUp();
        $this->pdo = createTestDatabase();
        $this->createRateLimitTable();
        $this->rateLimiter = new RateLimiter($this->pdo);
    }

    private function createRateLimitTable() {
        $sql = "CREATE TABLE IF NOT EXISTS rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier VARCHAR(255) NOT NULL,
            action VARCHAR(100) NOT NULL,
            attempts INTEGER DEFAULT 0,
            first_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            blocked_until TIMESTAMP NULL
        )";
        $this->pdo->exec($sql);
    }

    public function testFirstAttemptAllowed() {
        $result = $this->rateLimiter->checkLimit('192.168.1.1', 'login', 5, 60, 300);

        $this->assertTrue($result['allowed'], "First attempt should be allowed");
        $this->assertEquals(4, $result['remaining'], "Should have 4 remaining attempts");
    }

    public function testMultipleAttemptsWithinLimit() {
        $ip = '192.168.1.2';
        $action = 'api_call';

        // Make 3 attempts (limit is 5)
        for ($i = 0; $i < 3; $i++) {
            $result = $this->rateLimiter->checkLimit($ip, $action, 5, 60, 300);
            $this->assertTrue($result['allowed'], "Attempt " . ($i + 1) . " should be allowed");
        }

        // Check the remaining count
        $result = $this->rateLimiter->checkLimit($ip, $action, 5, 60, 300);
        $this->assertEquals(1, $result['remaining'], "Should have 1 remaining attempt");
    }

    public function testBlockingAfterExceedingLimit() {
        $ip = '192.168.1.3';
        $action = 'login';
        $maxAttempts = 3;

        // Exceed the limit
        for ($i = 0; $i < $maxAttempts + 2; $i++) {
            $result = $this->rateLimiter->checkLimit($ip, $action, $maxAttempts, 60, 300);
        }

        // Should be blocked now
        $this->assertFalse($result['allowed'], "Should be blocked after exceeding limit");
        $this->assertEquals(0, $result['remaining'], "Should have 0 remaining attempts");
        $this->assertArrayHasKey('retry_after', $result, "Should include retry_after");
    }

    public function testDifferentActionsIndependent() {
        $ip = '192.168.1.4';

        // Max out one action
        for ($i = 0; $i < 6; $i++) {
            $this->rateLimiter->checkLimit($ip, 'login', 5, 60, 300);
        }

        // Different action should still be allowed
        $result = $this->rateLimiter->checkLimit($ip, 'register', 5, 60, 300);
        $this->assertTrue($result['allowed'], "Different action should be independent");
    }

    public function testDifferentIPsIndependent() {
        // Max out one IP
        for ($i = 0; $i < 6; $i++) {
            $this->rateLimiter->checkLimit('192.168.1.5', 'login', 5, 60, 300);
        }

        // Different IP should still be allowed
        $result = $this->rateLimiter->checkLimit('192.168.1.6', 'login', 5, 60, 300);
        $this->assertTrue($result['allowed'], "Different IP should be independent");
    }

    public function testResetFunctionality() {
        $ip = '192.168.1.7';
        $action = 'reset_test';

        // Make some attempts
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->checkLimit($ip, $action, 5, 60, 300);
        }

        // Reset the rate limit
        $this->rateLimiter->reset($ip, $action);

        // Should be back to full attempts
        $result = $this->rateLimiter->checkLimit($ip, $action, 5, 60, 300);
        $this->assertEquals(4, $result['remaining'], "Should have full attempts after reset");
    }

    public function testBlockedUntilTimestamp() {
        $ip = '192.168.1.8';
        $action = 'blocked_test';
        $blockSeconds = 300;

        // Exceed limit to get blocked
        for ($i = 0; $i < 6; $i++) {
            $result = $this->rateLimiter->checkLimit($ip, $action, 3, 60, $blockSeconds);
        }

        // Verify blocked_until is set correctly
        $this->assertArrayHasKey('reset_at', $result, "Should have reset_at timestamp");
        $this->assertFalse($result['allowed'], "Should be blocked");

        // Verify retry_after is reasonable (should be around blockSeconds)
        $this->assertTrue($result['retry_after'] > 0, "Retry after should be positive");
        $this->assertTrue($result['retry_after'] <= $blockSeconds, "Retry after should not exceed block time");
    }

    public function testGetClientIP() {
        // Test with REMOTE_ADDR
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $ip = RateLimiter::getClientIp();
        $this->assertEquals('10.0.0.1', $ip, "Should get IP from REMOTE_ADDR");

        // Test with X-Forwarded-For
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '11.0.0.1, 12.0.0.1';
        $ip = RateLimiter::getClientIp();
        $this->assertEquals('11.0.0.1', $ip, "Should get first IP from X-Forwarded-For");

        // Clean up
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function testGetClientIPWithCloudflare() {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '13.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        $ip = RateLimiter::getClientIp();
        $this->assertEquals('13.0.0.1', $ip, "Should prioritize Cloudflare IP");

        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function testCleanupOldRecords() {
        $ip = '192.168.1.9';

        // Create old record by direct insert
        $stmt = $this->pdo->prepare("
            INSERT INTO rate_limits (identifier, action, attempts, last_attempt)
            VALUES (?, ?, ?, datetime('now', '-2 hours'))
        ");
        $stmt->execute([$ip, 'old_action', 5]);

        // Trigger cleanup via checkLimit
        $this->rateLimiter->checkLimit($ip, 'new_action', 5, 3600, 300);

        // Old record should be cleaned up
        $count = $this->pdo->query("SELECT COUNT(*) FROM rate_limits WHERE action = 'old_action'")->fetchColumn();
        $this->assertEquals(0, $count, "Old records should be cleaned up");
    }

    public function testConcurrentRequests() {
        $ip = '192.168.1.10';
        $action = 'concurrent_test';
        $results = [];

        // Simulate rapid concurrent requests
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->rateLimiter->checkLimit($ip, $action, 5, 60, 300);
        }

        // All should be allowed
        foreach ($results as $i => $result) {
            $this->assertTrue($result['allowed'], "Request $i should be allowed");
        }

        // Next one should exceed
        $result = $this->rateLimiter->checkLimit($ip, $action, 5, 60, 300);
        $this->assertFalse($result['allowed'], "Should be blocked after 5 requests");
    }

    public function testWindowExpiration() {
        $ip = '192.168.1.11';
        $action = 'window_test';
        $windowSeconds = 1; // 1 second window

        // Make 3 attempts
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->checkLimit($ip, $action, 3, $windowSeconds, 300);
        }

        // Should be at limit
        $result = $this->rateLimiter->checkLimit($ip, $action, 3, $windowSeconds, 300);
        $this->assertFalse($result['allowed'], "Should be blocked");

        // Wait for window to expire
        sleep(2);

        // Should be allowed again (new window)
        $result = $this->rateLimiter->checkLimit($ip, $action, 3, $windowSeconds, 300);
        $this->assertTrue($result['allowed'], "Should be allowed after window expires");
    }

    public function testVaryingLimits() {
        $ip = '192.168.1.12';

        // Test strict limit
        $result = $this->rateLimiter->checkLimit($ip, 'strict', 1, 60, 300);
        $this->assertTrue($result['allowed'], "First strict request allowed");
        $result = $this->rateLimiter->checkLimit($ip, 'strict', 1, 60, 300);
        $this->assertFalse($result['allowed'], "Second strict request blocked");

        // Test lenient limit
        $result = $this->rateLimiter->checkLimit($ip, 'lenient', 100, 60, 300);
        $this->assertEquals(99, $result['remaining'], "Lenient limit has high remaining count");
    }

    public function testBlockDuration() {
        $ip = '192.168.1.13';
        $action = 'duration_test';
        $blockSeconds = 2;

        // Exceed limit
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->checkLimit($ip, $action, 2, 60, $blockSeconds);
        }

        // Should be blocked
        $result = $this->rateLimiter->checkLimit($ip, $action, 2, 60, $blockSeconds);
        $this->assertFalse($result['allowed'], "Should be blocked");

        // Wait for block to expire
        sleep($blockSeconds + 1);

        // Should be allowed again
        $result = $this->rateLimiter->checkLimit($ip, $action, 2, 60, $blockSeconds);
        $this->assertTrue($result['allowed'], "Should be allowed after block expires");
    }

    public function testZeroOrNegativeValues() {
        $ip = '192.168.1.14';

        // Test with 0 max attempts (should block immediately)
        $result = $this->rateLimiter->checkLimit($ip, 'zero_limit', 0, 60, 300);
        $this->assertFalse($result['allowed'], "Should block with 0 max attempts");

        // Test with very short window
        $result = $this->rateLimiter->checkLimit($ip, 'short_window', 5, 1, 300);
        $this->assertTrue($result['allowed'], "Should work with 1 second window");
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new RateLimiterTest();

    SimpleTest::test('First attempt allowed', function() use ($test) {
        $test->setUp();
        $test->testFirstAttemptAllowed();
    });

    SimpleTest::test('Multiple attempts within limit', function() use ($test) {
        $test->setUp();
        $test->testMultipleAttemptsWithinLimit();
    });

    SimpleTest::test('Blocking after exceeding limit', function() use ($test) {
        $test->setUp();
        $test->testBlockingAfterExceedingLimit();
    });

    SimpleTest::test('Different actions independent', function() use ($test) {
        $test->setUp();
        $test->testDifferentActionsIndependent();
    });

    SimpleTest::test('Different IPs independent', function() use ($test) {
        $test->setUp();
        $test->testDifferentIPsIndependent();
    });

    SimpleTest::test('Reset functionality', function() use ($test) {
        $test->setUp();
        $test->testResetFunctionality();
    });

    SimpleTest::test('Blocked until timestamp', function() use ($test) {
        $test->setUp();
        $test->testBlockedUntilTimestamp();
    });

    SimpleTest::test('Get client IP', function() use ($test) {
        $test->setUp();
        $test->testGetClientIP();
    });

    SimpleTest::test('Get client IP with Cloudflare', function() use ($test) {
        $test->setUp();
        $test->testGetClientIPWithCloudflare();
    });

    SimpleTest::test('Cleanup old records', function() use ($test) {
        $test->setUp();
        $test->testCleanupOldRecords();
    });

    SimpleTest::test('Concurrent requests', function() use ($test) {
        $test->setUp();
        $test->testConcurrentRequests();
    });

    SimpleTest::test('Varying limits', function() use ($test) {
        $test->setUp();
        $test->testVaryingLimits();
    });

    SimpleTest::test('Zero or negative values', function() use ($test) {
        $test->setUp();
        $test->testZeroOrNegativeValues();
    });

    SimpleTest::run();
}
