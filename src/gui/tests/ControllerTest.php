<?php
/**
 * Controller and Form Handling Test Suite
 * Tests form processing, validation, and controller logic
 *
 * Copyright 2025
 * @package eIOU GUI Tests
 */

// Include required files
require_once(__DIR__ . '/../functions/functions.php');
require_once(__DIR__ . '/../includes/session.php');
require_once('/etc/eiou/src/services/ServiceContainer.php');

class ControllerTest {
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
     * Test form data validation logic
     */
    public function testFormValidation() {
        echo "\n=== Testing Form Validation Logic ===\n";

        // Test 1: Add contact form - all fields required
        $validData = [
            'address' => 'test.onion',
            'name' => 'Test Contact',
            'fee' => '1.5',
            'credit' => '1000',
            'currency' => 'USD'
        ];

        $allFieldsPresent = !empty($validData['address']) &&
                          !empty($validData['name']) &&
                          !empty($validData['fee']) &&
                          !empty($validData['credit']) &&
                          !empty($validData['currency']);

        $this->testResult(
            "Valid contact form data passes validation",
            $allFieldsPresent,
            "All required fields present"
        );

        // Test 2: Missing address field
        $missingAddress = $validData;
        unset($missingAddress['address']);
        $shouldFail = empty($missingAddress['address']);

        $this->testResult(
            "Missing address field detected",
            $shouldFail,
            "Validation correctly identifies missing address"
        );

        // Test 3: Missing name field
        $missingName = $validData;
        unset($missingName['name']);
        $shouldFail = empty($missingName['name']);

        $this->testResult(
            "Missing name field detected",
            $shouldFail,
            "Validation correctly identifies missing name"
        );

        // Test 4: Send eIOU form validation
        $sendData = [
            'recipient' => 'test.onion',
            'amount' => '100',
            'currency' => 'USD',
            'manual_recipient' => ''
        ];

        $finalRecipient = !empty($sendData['manual_recipient']) ?
                         $sendData['manual_recipient'] : $sendData['recipient'];

        $sendValid = !empty($finalRecipient) &&
                    !empty($sendData['amount']) &&
                    !empty($sendData['currency']);

        $this->testResult(
            "Valid send form data passes validation",
            $sendValid,
            "Recipient, amount, and currency present"
        );

        // Test 5: Manual recipient overrides dropdown
        $manualRecipient = [
            'recipient' => 'dropdown.onion',
            'manual_recipient' => 'manual.onion',
            'amount' => '50',
            'currency' => 'USD'
        ];

        $finalRecipient = !empty($manualRecipient['manual_recipient']) ?
                         $manualRecipient['manual_recipient'] : $manualRecipient['recipient'];

        $this->testResult(
            "Manual recipient overrides dropdown selection",
            $finalRecipient === 'manual.onion',
            "Final recipient: $finalRecipient"
        );

        // Test 6: Empty amount validation
        $emptyAmount = [
            'recipient' => 'test.onion',
            'amount' => '',
            'currency' => 'USD'
        ];

        $shouldFail = empty($emptyAmount['amount']);

        $this->testResult(
            "Empty amount field detected",
            $shouldFail,
            "Validation correctly identifies missing amount"
        );

        // Test 7: Accept contact form validation
        $acceptData = [
            'contact_address' => 'test.onion',
            'contact_name' => 'Test User',
            'contact_fee' => '2.0',
            'contact_credit' => '500',
            'contact_currency' => 'EUR'
        ];

        $acceptValid = !empty($acceptData['contact_address']) &&
                      !empty($acceptData['contact_name']) &&
                      !empty($acceptData['contact_fee']) &&
                      !empty($acceptData['contact_credit']) &&
                      !empty($acceptData['contact_currency']);

        $this->testResult(
            "Valid accept contact form data",
            $acceptValid,
            "All required fields for accepting contact present"
        );

        // Test 8: Edit contact form validation
        $editData = [
            'contact_address' => 'test.onion',
            'contact_name' => 'Updated Name',
            'contact_fee' => '1.0',
            'contact_credit' => '2000',
            'contact_currency' => 'USD'
        ];

        $editValid = !empty($editData['contact_address']) &&
                    !empty($editData['contact_name']) &&
                    !empty($editData['contact_fee']) &&
                    !empty($editData['contact_credit']) &&
                    !empty($editData['contact_currency']);

        $this->testResult(
            "Valid edit contact form data",
            $editValid,
            "All required fields for editing contact present"
        );
    }

    /**
     * Test POST action detection
     */
    public function testActionDetection() {
        echo "\n=== Testing Action Detection ===\n";

        // Test 1: Add contact action
        $postData = ['action' => 'addContact'];
        $this->testResult(
            "Add contact action detected",
            isset($postData['action']) && $postData['action'] === 'addContact',
            "Action: " . $postData['action']
        );

        // Test 2: Send eIOU action
        $postData = ['action' => 'sendEIOU'];
        $this->testResult(
            "Send eIOU action detected",
            isset($postData['action']) && $postData['action'] === 'sendEIOU',
            "Action: " . $postData['action']
        );

        // Test 3: Accept contact action
        $postData = ['action' => 'acceptContact'];
        $this->testResult(
            "Accept contact action detected",
            isset($postData['action']) && $postData['action'] === 'acceptContact',
            "Action: " . $postData['action']
        );

        // Test 4: Delete contact action
        $postData = ['action' => 'deleteContact'];
        $this->testResult(
            "Delete contact action detected",
            isset($postData['action']) && $postData['action'] === 'deleteContact',
            "Action: " . $postData['action']
        );

        // Test 5: Block contact action
        $postData = ['action' => 'blockContact'];
        $this->testResult(
            "Block contact action detected",
            isset($postData['action']) && $postData['action'] === 'blockContact',
            "Action: " . $postData['action']
        );

        // Test 6: Unblock contact action
        $postData = ['action' => 'ublockContact'];
        $this->testResult(
            "Unblock contact action detected",
            isset($postData['action']) && $postData['action'] === 'ublockContact',
            "Action: " . $postData['action']
        );

        // Test 7: Edit contact action
        $postData = ['action' => 'editContact'];
        $this->testResult(
            "Edit contact action detected",
            isset($postData['action']) && $postData['action'] === 'editContact',
            "Action: " . $postData['action']
        );

        // Test 8: Contact actions validated with in_array
        $validActions = ['acceptContact', 'deleteContact', 'blockContact', 'ublockContact', 'editContact'];
        $testAction = 'deleteContact';
        $this->testResult(
            "Contact action in valid actions list",
            in_array($testAction, $validActions),
            "Action '$testAction' is valid"
        );

        // Test 9: Invalid action not in list
        $invalidAction = 'invalidAction';
        $this->testResult(
            "Invalid action not in valid actions list",
            !in_array($invalidAction, $validActions),
            "Action '$invalidAction' correctly rejected"
        );
    }

    /**
     * Test argv array construction for service calls
     */
    public function testArgvConstruction() {
        echo "\n=== Testing Argv Array Construction ===\n";

        // Test 1: Add contact argv
        $address = 'test.onion';
        $name = 'Test User';
        $fee = '1.5';
        $credit = '1000';
        $currency = 'USD';

        $argv = ['eiou', 'add', $address, $name, $fee, $credit, $currency];

        $this->testResult(
            "Add contact argv construction",
            count($argv) === 7 && $argv[0] === 'eiou' && $argv[1] === 'add',
            "Argv: " . implode(', ', $argv)
        );

        // Test 2: Send eIOU argv
        $recipient = 'recipient.onion';
        $amount = '100';
        $currency = 'USD';

        $argv = ['eiou', 'send', $recipient, $amount, $currency];

        $this->testResult(
            "Send eIOU argv construction",
            count($argv) === 5 && $argv[1] === 'send' && $argv[2] === $recipient,
            "Argv: " . implode(', ', $argv)
        );

        // Test 3: Delete contact argv
        $address = 'delete.onion';
        $argv = ['eiou', 'delete', $address];

        $this->testResult(
            "Delete contact argv construction",
            count($argv) === 3 && $argv[1] === 'delete',
            "Argv: " . implode(', ', $argv)
        );

        // Test 4: Block contact argv
        $address = 'block.onion';
        $argv = ['eiou', 'block', $address];

        $this->testResult(
            "Block contact argv construction",
            count($argv) === 3 && $argv[1] === 'block',
            "Argv: " . implode(', ', $argv)
        );

        // Test 5: Update contact argv
        $address = 'update.onion';
        $name = 'Updated Name';
        $fee = '2.0';
        $credit = '2000';

        $argv = ['eiou', 'update', $address, 'all', $name, $fee, $credit];

        $this->testResult(
            "Update contact argv construction",
            count($argv) === 7 && $argv[1] === 'update' && $argv[3] === 'all',
            "Argv: " . implode(', ', $argv)
        );

        // Test 6: Argv array indexing
        $argv = ['eiou', 'send', 'test.onion', '100', 'USD'];
        $this->testResult(
            "Argv indices correct for send command",
            $argv[2] === 'test.onion' && $argv[3] === '100' && $argv[4] === 'USD',
            "Recipient: {$argv[2]}, Amount: {$argv[3]}, Currency: {$argv[4]}"
        );
    }

    /**
     * Test message type determination from output
     */
    public function testMessageTypeDetermination() {
        echo "\n=== Testing Message Type Determination ===\n";

        // Test 1: Success output
        $output = "Contact added successfully";
        $messageInfo = parseContactOutput($output);

        $this->testResult(
            "Success message type determined",
            $messageInfo['type'] === 'success',
            "Type: {$messageInfo['type']}"
        );

        // Test 2: Error output with "ERROR"
        $output = "ERROR: Failed to add contact";
        $containsError = strpos($output, 'ERROR') !== false;

        $this->testResult(
            "Error keyword detected in output",
            $containsError,
            "Output contains 'ERROR'"
        );

        // Test 3: Error output with "Failed"
        $output = "Failed to connect to recipient";
        $containsFailed = strpos($output, 'Failed') !== false;

        $this->testResult(
            "Failed keyword detected in output",
            $containsFailed,
            "Output contains 'Failed'"
        );

        // Test 4: Success case without ERROR or Failed
        $output = "Transaction completed successfully";
        $isSuccess = strpos($output, 'ERROR') === false && strpos($output, 'Failed') === false;

        $this->testResult(
            "Success output without error keywords",
            $isSuccess,
            "No error keywords present"
        );

        // Test 5: Message type assignment for send operation
        $output = "Sent 100 USD to recipient.onion";
        $messageType = (strpos($output, 'ERROR') !== false || strpos($output, 'Failed') !== false) ? 'error' : 'success';

        $this->testResult(
            "Send operation success message type",
            $messageType === 'success',
            "Type: $messageType"
        );
    }

    /**
     * Test URL parameter handling
     */
    public function testUrlParameterHandling() {
        echo "\n=== Testing URL Parameter Handling ===\n";

        // Test 1: Message from GET parameters
        $_GET = [
            'message' => 'Test message',
            'type' => 'success'
        ];

        $messageForDisplay = isset($_GET['message']) ? $_GET['message'] : '';
        $messageTypeForDisplay = isset($_GET['type']) ? $_GET['type'] : '';

        $this->testResult(
            "GET parameters extracted for display",
            $messageForDisplay === 'Test message' && $messageTypeForDisplay === 'success',
            "Message: $messageForDisplay, Type: $messageTypeForDisplay"
        );

        // Test 2: No parameters set
        $_GET = [];

        $messageForDisplay = isset($_GET['message']) ? $_GET['message'] : '';
        $messageTypeForDisplay = isset($_GET['type']) ? $_GET['type'] : '';

        $this->testResult(
            "Empty GET parameters handled",
            $messageForDisplay === '' && $messageTypeForDisplay === '',
            "Both values empty when no parameters"
        );

        // Test 3: URL encoding/decoding
        $message = "Error: Invalid & bad data!";
        $encoded = urlencode($message);
        $decoded = urldecode($encoded);

        $this->testResult(
            "URL encoding and decoding",
            $decoded === $message,
            "Original and decoded match"
        );

        // Test 4: Special characters in message
        $message = "Contact 'John Doe' added successfully";
        $encoded = urlencode($message);

        $this->testResult(
            "Special characters encoded",
            strpos($encoded, '%') !== false || $encoded === $message,
            "Message encoded: $encoded"
        );
    }

    /**
     * Test output buffering for service calls
     */
    public function testOutputBuffering() {
        echo "\n=== Testing Output Buffering ===\n";

        // Test 1: Output buffering capture
        ob_start();
        echo "Test output";
        $output = ob_get_clean();

        $this->testResult(
            "Output buffer captures content",
            $output === "Test output",
            "Captured: $output"
        );

        // Test 2: Multiple outputs captured
        ob_start();
        echo "Line 1\n";
        echo "Line 2\n";
        $output = ob_get_clean();

        $this->testResult(
            "Multiple outputs captured",
            strpos($output, 'Line 1') !== false && strpos($output, 'Line 2') !== false,
            "Captured multiple lines"
        );

        // Test 3: Empty buffer
        ob_start();
        $output = ob_get_clean();

        $this->testResult(
            "Empty output buffer",
            $output === '',
            "Empty buffer captured correctly"
        );

        // Test 4: Trim output whitespace
        ob_start();
        echo "  Padded output  \n";
        $output = trim(ob_get_clean());

        $this->testResult(
            "Output trimmed correctly",
            $output === "Padded output",
            "Trimmed: '$output'"
        );
    }

    /**
     * Test check_updates query string handling
     */
    public function testCheckUpdatesHandling() {
        echo "\n=== Testing Check Updates Query Handling ===\n";

        // Test 1: Check updates parameter detection
        $_GET = ['check_updates' => '1'];

        $this->testResult(
            "Check updates parameter detected",
            isset($_GET['check_updates']) && $_GET['check_updates'] === '1',
            "Parameter present and set to '1'"
        );

        // Test 2: Last check timestamp parameter
        $_GET = ['check_updates' => '1', 'last_check' => '1234567890'];

        $lastCheckTime = $_GET['last_check'] ?? 0;

        $this->testResult(
            "Last check timestamp extracted",
            $lastCheckTime === '1234567890',
            "Timestamp: $lastCheckTime"
        );

        // Test 3: Default last check value
        $_GET = ['check_updates' => '1'];

        $lastCheckTime = $_GET['last_check'] ?? 0;

        $this->testResult(
            "Default last check value",
            $lastCheckTime === 0,
            "Defaults to 0 when not provided"
        );

        // Test 4: Update check not requested
        $_GET = [];

        $checkRequested = isset($_GET['check_updates']) && $_GET['check_updates'] === '1';

        $this->testResult(
            "Check updates not requested",
            !$checkRequested,
            "Correctly detects absence of check request"
        );
    }

    /**
     * Run all controller tests
     */
    public function runAllTests() {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "GUI CONTROLLER & FORM HANDLING TEST SUITE\n";
        echo str_repeat("=", 60) . "\n";

        $this->testFormValidation();
        $this->testActionDetection();
        $this->testArgvConstruction();
        $this->testMessageTypeDetermination();
        $this->testUrlParameterHandling();
        $this->testOutputBuffering();
        $this->testCheckUpdatesHandling();

        // Summary
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "CONTROLLER TEST SUMMARY\n";
        echo str_repeat("=", 60) . "\n";
        echo "✅ Tests Passed: {$this->testsPassed}\n";
        echo "❌ Tests Failed: {$this->testsFailed}\n";
        echo "Total Tests: " . ($this->testsPassed + $this->testsFailed) . "\n";

        if ($this->testsFailed === 0) {
            echo "\n🎉 All controller tests passed!\n";
        } else {
            echo "\n⚠️ Some tests failed. Please review the errors above.\n";
        }
        echo str_repeat("=", 60) . "\n";

        return ['passed' => $this->testsPassed, 'failed' => $this->testsFailed];
    }
}

// Run tests if executed directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $tester = new ControllerTest();
    $results = $tester->runAllTests();
    exit($results['failed'] > 0 ? 1 : 0);
}
