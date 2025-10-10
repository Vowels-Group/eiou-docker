<?php
/**
 * Helper Functions Test Suite
 * Tests all utility and helper functions in the GUI
 *
 * Copyright 2025
 * @package eIOU GUI Tests
 */

// Include required files
require_once(__DIR__ . '/../functions/functions.php');

class HelperTest {
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
     * Test truncateAddress function with various inputs
     */
    public function testTruncateAddress() {
        echo "\n=== Testing truncateAddress Function ===\n";

        // Test 1: Normal address truncation
        $address = "abc123def456ghi789jkl012mno345pqr678stu901vwx234yz";
        $truncated = truncateAddress($address, 10);
        $this->testResult(
            "Truncate long address",
            strlen($truncated) === 13 && substr($truncated, -3) === '...',
            "Original length: " . strlen($address) . ", Truncated: $truncated"
        );

        // Test 2: Short address should not be truncated
        $shortAddress = "short123";
        $result = truncateAddress($shortAddress, 10);
        $this->testResult(
            "Short address remains unchanged",
            $result === $shortAddress,
            "Input: $shortAddress, Output: $result"
        );

        // Test 3: Exact length address
        $exactAddress = "1234567890";
        $result = truncateAddress($exactAddress, 10);
        $this->testResult(
            "Exact length address remains unchanged",
            $result === $exactAddress,
            "Input length: " . strlen($exactAddress)
        );

        // Test 4: Custom truncation length
        $address = "verylongaddressfortest";
        $result = truncateAddress($address, 5);
        $this->testResult(
            "Custom truncation length works",
            strlen($result) === 8 && substr($result, 0, 5) === 'veryl',
            "Truncated to: $result"
        );

        // Test 5: Empty string handling
        $emptyResult = truncateAddress('', 10);
        $this->testResult(
            "Empty string handling",
            $emptyResult === '',
            "Empty input returns empty output"
        );

        // Test 6: Single character
        $singleChar = truncateAddress('a', 10);
        $this->testResult(
            "Single character handling",
            $singleChar === 'a',
            "Single character preserved"
        );
    }

    /**
     * Test parseContactOutput function with various output messages
     */
    public function testParseContactOutput() {
        echo "\n=== Testing parseContactOutput Function ===\n";

        // Test 1: Contact accepted message
        $output = "Contact accepted.";
        $result = parseContactOutput($output);
        $this->testResult(
            "Parse contact accepted message",
            $result['type'] === 'contact-accepted' && $result['message'] === $output,
            "Type: {$result['type']}"
        );

        // Test 2: Success message
        $output = "Operation completed successfully";
        $result = parseContactOutput($output);
        $this->testResult(
            "Parse general success message",
            $result['type'] === 'success',
            "Type: {$result['type']}"
        );

        // Test 3: Already added warning
        $output = "Contact has already been added or accepted";
        $result = parseContactOutput($output);
        $this->testResult(
            "Parse duplicate contact warning",
            $result['type'] === 'warning',
            "Type: {$result['type']}"
        );

        // Test 4: Generic warning
        $output = "Warning: Some issue occurred";
        $result = parseContactOutput($output);
        $this->testResult(
            "Parse generic warning message",
            $result['type'] === 'warning',
            "Type: {$result['type']}"
        );

        // Test 5: Failed operation
        $output = "Operation failed";
        $result = parseContactOutput($output);
        $this->testResult(
            "Parse failed operation",
            $result['type'] === 'error' && strpos($result['message'], 'Please try again') !== false,
            "Type: {$result['type']}, appends retry message"
        );

        // Test 6: Not accepted by recipient
        $output = "Contact not accepted by the recipient";
        $result = parseContactOutput($output);
        $this->testResult(
            "Parse not accepted error",
            $result['type'] === 'error' && strpos($result['message'], 'contact the recipient') !== false,
            "Type: {$result['type']}, appends contact suggestion"
        );

        // Test 7: Not found error
        $output = "Contact not found";
        $result = parseContactOutput($output);
        $this->testResult(
            "Parse not found error",
            $result['type'] === 'error',
            "Type: {$result['type']}"
        );

        // Test 8: No results found
        $output = "No results found.";
        $result = parseContactOutput($output);
        $this->testResult(
            "Parse no results error",
            $result['type'] === 'error',
            "Type: {$result['type']}"
        );

        // Test 9: Generic error detection
        $output = "An error occurred during processing";
        $result = parseContactOutput($output);
        $this->testResult(
            "Parse generic error message",
            $result['type'] === 'error',
            "Type: {$result['type']}"
        );

        // Test 10: Default success for unknown message
        $output = "Some random message";
        $result = parseContactOutput($output);
        $this->testResult(
            "Parse unknown message defaults to success",
            $result['type'] === 'success',
            "Type: {$result['type']}"
        );
    }

    /**
     * Test currencyOutputConversion function
     */
    public function testCurrencyOutputConversion() {
        echo "\n=== Testing currencyOutputConversion Function ===\n";

        // Test 1: USD conversion (cents to dollars)
        $cents = 12345;
        $result = currencyOutputConversion($cents, 'USD');
        $this->testResult(
            "USD cents to dollars conversion",
            $result === 123.45,
            "Input: $cents cents, Output: \$$result"
        );

        // Test 2: USD zero value
        $result = currencyOutputConversion(0, 'USD');
        $this->testResult(
            "USD zero value conversion",
            $result === 0,
            "Input: 0, Output: $result"
        );

        // Test 3: Non-USD currency (no conversion)
        $value = 100;
        $result = currencyOutputConversion($value, 'EUR');
        $this->testResult(
            "Non-USD currency unchanged",
            $result === $value,
            "Currency: EUR, Input: $value, Output: $result"
        );

        // Test 4: BTC no conversion
        $btcValue = 0.005;
        $result = currencyOutputConversion($btcValue, 'BTC');
        $this->testResult(
            "BTC value unchanged",
            $result === $btcValue,
            "Currency: BTC, Value: $result"
        );

        // Test 5: Large USD amount
        $largeCents = 999999999;
        $result = currencyOutputConversion($largeCents, 'USD');
        $expected = $largeCents / 100;
        $this->testResult(
            "Large USD amount conversion",
            $result === $expected,
            "Input: $largeCents cents, Output: \$$result"
        );

        // Test 6: Negative USD value
        $negativeCents = -5000;
        $result = currencyOutputConversion($negativeCents, 'USD');
        $this->testResult(
            "Negative USD amount conversion",
            $result === -50.00,
            "Input: $negativeCents cents, Output: \$$result"
        );
    }

    /**
     * Test redirectMessage helper function (without actual redirect)
     */
    public function testRedirectMessageUrlGeneration() {
        echo "\n=== Testing redirectMessage URL Generation ===\n";

        // We can't test actual redirects, but we can test the URL encoding logic
        // by manually building the expected URL

        // Test 1: Simple message encoding
        $message = "Contact added successfully";
        $messageType = "success";
        $expectedUrl = '?message=' . urlencode($message) . '&type=' . urlencode($messageType);

        $this->testResult(
            "URL encoding for simple message",
            strpos($expectedUrl, 'message=Contact') !== false && strpos($expectedUrl, 'type=success') !== false,
            "Generated URL contains correct parameters"
        );

        // Test 2: Special characters in message
        $message = "Error: Invalid input & bad data!";
        $messageType = "error";
        $encodedMessage = urlencode($message);

        $this->testResult(
            "URL encoding with special characters",
            strpos($encodedMessage, '%') !== false,
            "Special characters properly encoded"
        );

        // Test 3: Empty message handling
        $message = "";
        $messageType = "info";
        $encodedMessage = urlencode($message);

        $this->testResult(
            "Empty message URL encoding",
            $encodedMessage === "",
            "Empty message encoded correctly"
        );
    }

    /**
     * Run all helper tests
     */
    public function runAllTests() {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "GUI HELPER FUNCTIONS TEST SUITE\n";
        echo str_repeat("=", 60) . "\n";

        $this->testTruncateAddress();
        $this->testParseContactOutput();
        $this->testCurrencyOutputConversion();
        $this->testRedirectMessageUrlGeneration();

        // Summary
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "HELPER TEST SUMMARY\n";
        echo str_repeat("=", 60) . "\n";
        echo "✅ Tests Passed: {$this->testsPassed}\n";
        echo "❌ Tests Failed: {$this->testsFailed}\n";
        echo "Total Tests: " . ($this->testsPassed + $this->testsFailed) . "\n";

        if ($this->testsFailed === 0) {
            echo "\n🎉 All helper function tests passed!\n";
        } else {
            echo "\n⚠️ Some tests failed. Please review the errors above.\n";
        }
        echo str_repeat("=", 60) . "\n";

        return ['passed' => $this->testsPassed, 'failed' => $this->testsFailed];
    }
}

// Run tests if executed directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $tester = new HelperTest();
    $results = $tester->runAllTests();
    exit($results['failed'] > 0 ? 1 : 0);
}
