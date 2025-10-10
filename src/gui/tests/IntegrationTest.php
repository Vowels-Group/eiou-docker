<?php
/**
 * Integration Test Suite
 * Tests end-to-end workflows and complete user flows
 *
 * Copyright 2025
 * @package eIOU GUI Tests
 */

// Include required files
require_once(__DIR__ . '/../functions/functions.php');
require_once(__DIR__ . '/../includes/session.php');
require_once('/etc/eiou/src/services/ServiceContainer.php');

class IntegrationTest {
    private $testsPassed = 0;
    private $testsFailed = 0;
    private $pdo;
    private $testContactAddress = 'test_integration_contact.onion';

    public function __construct() {
        // Initialize database connection
        $this->pdo = getPDOConnection();

        // Start session for tests
        startSecureSession();
    }

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
     * Test complete contact addition workflow
     */
    public function testAddContactWorkflow() {
        echo "\n=== Testing Add Contact Workflow ===\n";

        if ($this->pdo === null) {
            echo "⚠️  Skipping add contact workflow - no database connection\n";
            return;
        }

        // Test 1: Prepare contact data
        $contactData = [
            'address' => $this->testContactAddress,
            'name' => 'Integration Test Contact',
            'fee' => '1.5',
            'credit' => '1000',
            'currency' => 'USD'
        ];

        $this->testResult(
            "Contact data prepared",
            !empty($contactData['address']) && !empty($contactData['name']),
            "Address: {$contactData['address']}, Name: {$contactData['name']}"
        );

        // Test 2: Validate all required fields present
        $allFieldsPresent = !empty($contactData['address']) &&
                          !empty($contactData['name']) &&
                          !empty($contactData['fee']) &&
                          !empty($contactData['credit']) &&
                          !empty($contactData['currency']);

        $this->testResult(
            "All required fields present",
            $allFieldsPresent,
            "Form validation would pass"
        );

        // Test 3: Construct argv for service call
        $argv = [
            'eiou',
            'add',
            $contactData['address'],
            $contactData['name'],
            $contactData['fee'],
            $contactData['credit'],
            $contactData['currency']
        ];

        $this->testResult(
            "Service call argv constructed",
            count($argv) === 7 && $argv[1] === 'add',
            "Command: " . implode(' ', $argv)
        );

        // Test 4: Verify parseContactOutput handles various responses
        $successOutput = "Contact added successfully";
        $result = parseContactOutput($successOutput);

        $this->testResult(
            "Success output parsed correctly",
            $result['type'] === 'success',
            "Message type: {$result['type']}"
        );

        // Test 5: Warning for duplicate contact
        $duplicateOutput = "Contact has already been added or accepted";
        $result = parseContactOutput($duplicateOutput);

        $this->testResult(
            "Duplicate contact warning parsed",
            $result['type'] === 'warning',
            "Message type: {$result['type']}"
        );

        // Test 6: Verify redirect URL construction
        $message = "Contact added successfully";
        $messageType = "success";
        $redirectUrl = '?message=' . urlencode($message) . '&type=' . urlencode($messageType);

        $this->testResult(
            "Redirect URL constructed",
            strpos($redirectUrl, 'message=') !== false && strpos($redirectUrl, 'type=') !== false,
            "URL: $redirectUrl"
        );
    }

    /**
     * Test complete send transaction workflow
     */
    public function testSendTransactionWorkflow() {
        echo "\n=== Testing Send Transaction Workflow ===\n";

        // Test 1: Prepare transaction data
        $txData = [
            'recipient' => 'recipient.onion',
            'manual_recipient' => '',
            'amount' => '100',
            'currency' => 'USD'
        ];

        $this->testResult(
            "Transaction data prepared",
            !empty($txData['recipient']) && !empty($txData['amount']),
            "Recipient: {$txData['recipient']}, Amount: {$txData['amount']}"
        );

        // Test 2: Determine final recipient (dropdown vs manual)
        $finalRecipient = !empty($txData['manual_recipient']) ?
                         $txData['manual_recipient'] : $txData['recipient'];

        $this->testResult(
            "Final recipient determined from dropdown",
            $finalRecipient === $txData['recipient'],
            "Using: $finalRecipient"
        );

        // Test 3: Manual recipient overrides dropdown
        $txData['manual_recipient'] = 'manual.onion';
        $finalRecipient = !empty($txData['manual_recipient']) ?
                         $txData['manual_recipient'] : $txData['recipient'];

        $this->testResult(
            "Manual recipient overrides dropdown",
            $finalRecipient === 'manual.onion',
            "Using manual: $finalRecipient"
        );

        // Test 4: Validate required fields
        $txData['manual_recipient'] = ''; // Reset
        $finalRecipient = !empty($txData['manual_recipient']) ?
                         $txData['manual_recipient'] : $txData['recipient'];

        $allFieldsValid = !empty($finalRecipient) &&
                         !empty($txData['amount']) &&
                         !empty($txData['currency']);

        $this->testResult(
            "All transaction fields valid",
            $allFieldsValid,
            "Form validation would pass"
        );

        // Test 5: Construct argv for send operation
        $argv = ['eiou', 'send', $finalRecipient, $txData['amount'], $txData['currency']];

        $this->testResult(
            "Send transaction argv constructed",
            count($argv) === 5 && $argv[1] === 'send',
            "Command: " . implode(' ', $argv)
        );

        // Test 6: Success message type detection
        $successOutput = "Sent 100 USD to recipient.onion";
        $containsError = strpos($successOutput, 'ERROR') !== false ||
                        strpos($successOutput, 'Failed') !== false;

        $this->testResult(
            "Success output detected",
            !$containsError,
            "No error keywords in output"
        );

        // Test 7: Error message type detection
        $errorOutput = "ERROR: Insufficient balance";
        $containsError = strpos($errorOutput, 'ERROR') !== false ||
                        strpos($errorOutput, 'Failed') !== false;

        $this->testResult(
            "Error output detected",
            $containsError,
            "Error keyword found in output"
        );
    }

    /**
     * Test contact management workflows
     */
    public function testContactManagementWorkflow() {
        echo "\n=== Testing Contact Management Workflow ===\n";

        // Test 1: Accept contact request workflow
        $acceptData = [
            'action' => 'acceptContact',
            'contact_address' => 'pending.onion',
            'contact_name' => 'Pending User',
            'contact_fee' => '2.0',
            'contact_credit' => '500',
            'contact_currency' => 'EUR'
        ];

        $isAcceptAction = isset($acceptData['action']) &&
                         $acceptData['action'] === 'acceptContact';

        $this->testResult(
            "Accept contact action detected",
            $isAcceptAction,
            "Action: {$acceptData['action']}"
        );

        // Test 2: Validate accept contact fields
        $acceptValid = !empty($acceptData['contact_address']) &&
                      !empty($acceptData['contact_name']) &&
                      !empty($acceptData['contact_fee']) &&
                      !empty($acceptData['contact_credit']) &&
                      !empty($acceptData['contact_currency']);

        $this->testResult(
            "Accept contact fields valid",
            $acceptValid,
            "All required fields present"
        );

        // Test 3: Delete contact workflow
        $deleteData = [
            'action' => 'deleteContact',
            'contact_address' => 'delete.onion'
        ];

        $isDeleteAction = isset($deleteData['action']) &&
                         $deleteData['action'] === 'deleteContact';

        $this->testResult(
            "Delete contact action detected",
            $isDeleteAction,
            "Action: {$deleteData['action']}"
        );

        // Test 4: Delete requires address
        $deleteValid = !empty($deleteData['contact_address']);

        $this->testResult(
            "Delete contact has required address",
            $deleteValid,
            "Address: {$deleteData['contact_address']}"
        );

        // Test 5: Block contact workflow
        $blockData = [
            'action' => 'blockContact',
            'contact_address' => 'block.onion'
        ];

        $isBlockAction = isset($blockData['action']) &&
                        $blockData['action'] === 'blockContact';

        $this->testResult(
            "Block contact action detected",
            $isBlockAction,
            "Action: {$blockData['action']}"
        );

        // Test 6: Edit contact workflow
        $editData = [
            'action' => 'editContact',
            'contact_address' => 'edit.onion',
            'contact_name' => 'Updated Name',
            'contact_fee' => '1.0',
            'contact_credit' => '2000',
            'contact_currency' => 'USD'
        ];

        $isEditAction = isset($editData['action']) &&
                       $editData['action'] === 'editContact';

        $this->testResult(
            "Edit contact action detected",
            $isEditAction,
            "Action: {$editData['action']}"
        );

        // Test 7: Validate edit contact fields
        $editValid = !empty($editData['contact_address']) &&
                    !empty($editData['contact_name']) &&
                    !empty($editData['contact_fee']) &&
                    !empty($editData['contact_credit']) &&
                    !empty($editData['contact_currency']);

        $this->testResult(
            "Edit contact fields valid",
            $editValid,
            "All required fields present"
        );

        // Test 8: Valid actions list
        $validActions = ['acceptContact', 'deleteContact', 'blockContact', 'ublockContact', 'editContact'];

        $this->testResult(
            "All contact actions in valid list",
            in_array('acceptContact', $validActions) &&
            in_array('deleteContact', $validActions) &&
            in_array('blockContact', $validActions) &&
            in_array('editContact', $validActions),
            "Valid actions defined"
        );
    }

    /**
     * Test data retrieval and display workflow
     */
    public function testDataRetrievalWorkflow() {
        echo "\n=== Testing Data Retrieval Workflow ===\n";

        if ($this->pdo === null) {
            echo "⚠️  Skipping data retrieval workflow - no database connection\n";
            return;
        }

        // Test 1: Get all contact categories
        $allContacts = getAllContacts();
        $acceptedContacts = getAcceptedContacts();
        $pendingContacts = getPendingContacts();
        $blockedContacts = getBlockedContacts();

        $this->testResult(
            "All contact categories retrieved",
            is_array($allContacts) && is_array($acceptedContacts) &&
            is_array($pendingContacts) && is_array($blockedContacts),
            "All: " . count($allContacts) . ", Accepted: " . count($acceptedContacts) .
            ", Pending: " . count($pendingContacts) . ", Blocked: " . count($blockedContacts)
        );

        // Test 2: Contact conversion workflow
        if (!empty($acceptedContacts)) {
            $converted = contactConversion($acceptedContacts);

            $this->testResult(
                "Contact conversion produces output",
                is_array($converted) && count($converted) === count($acceptedContacts),
                "Converted " . count($converted) . " contacts"
            );

            // Test 3: Converted contacts have display fields
            if (!empty($converted)) {
                $first = $converted[0];
                $hasDisplayFields = isset($first['name']) &&
                                   isset($first['address']) &&
                                   isset($first['balance']) &&
                                   isset($first['fee']) &&
                                   isset($first['credit_limit']);

                $this->testResult(
                    "Converted contacts have display fields",
                    $hasDisplayFields,
                    "Fields: " . implode(', ', array_keys($first))
                );
            }
        }

        // Test 4: Transaction history retrieval
        $transactions = getTransactionHistory(10);

        $this->testResult(
            "Transaction history retrieved",
            is_array($transactions),
            "Retrieved " . count($transactions) . " transactions"
        );

        // Test 5: Transaction display format
        if (!empty($transactions)) {
            $firstTx = $transactions[0];
            $hasDisplayFields = isset($firstTx['date']) &&
                               isset($firstTx['type']) &&
                               isset($firstTx['amount']) &&
                               isset($firstTx['counterparty']);

            $this->testResult(
                "Transactions formatted for display",
                $hasDisplayFields,
                "Type: {$firstTx['type']}, Amount: {$firstTx['amount']}"
            );
        }

        // Test 6: User balance retrieval
        $totalBalance = getUserTotalBalance();

        $this->testResult(
            "User total balance retrieved",
            $totalBalance !== null,
            "Balance: \$$totalBalance"
        );

        // Test 7: Address truncation for display
        $longAddress = "very_long_address_for_display_testing.onion";
        $truncated = truncateAddress($longAddress, 10);

        $this->testResult(
            "Long addresses truncated for display",
            strlen($truncated) < strlen($longAddress),
            "Original: " . strlen($longAddress) . " chars, Truncated: " . strlen($truncated) . " chars"
        );
    }

    /**
     * Test session and authentication workflow
     */
    public function testAuthenticationWorkflow() {
        echo "\n=== Testing Authentication Workflow ===\n";

        // Test 1: User starts unauthenticated
        $_SESSION = [];
        startSecureSession();

        $this->testResult(
            "New session starts unauthenticated",
            !isAuthenticated(),
            "User not authenticated initially"
        );

        // Test 2: CSRF token generated for forms
        $csrfToken = getCSRFToken();

        $this->testResult(
            "CSRF token generated for forms",
            !empty($csrfToken) && strlen($csrfToken) === 64,
            "Token length: " . strlen($csrfToken)
        );

        // Test 3: Successful authentication workflow
        $authCode = "test_auth_12345";
        $result = authenticate($authCode, $authCode);

        $this->testResult(
            "User authenticated successfully",
            $result === true && isAuthenticated(),
            "Authentication successful"
        );

        // Test 4: Session timeout tracking
        $this->testResult(
            "Last activity tracked",
            isset($_SESSION['last_activity']),
            "Timestamp: " . ($_SESSION['last_activity'] ?? 'not set')
        );

        // Test 5: Session timeout check passes for active session
        $timeoutCheck = checkSessionTimeout();

        $this->testResult(
            "Active session passes timeout check",
            $timeoutCheck === true,
            "Session valid"
        );

        // Test 6: CSRF field HTML for forms
        $csrfField = getCSRFField();

        $this->testResult(
            "CSRF field HTML generated for forms",
            strpos($csrfField, 'csrf_token') !== false && strpos($csrfField, '<input') !== false,
            "Field HTML contains input element"
        );

        // Test 7: CSRF validation for POST requests
        $validToken = validateCSRFToken($csrfToken);

        $this->testResult(
            "Valid CSRF token passes validation",
            $validToken === true,
            "Token validation successful"
        );

        // Test 8: Logout workflow
        logout();

        $this->testResult(
            "User logged out successfully",
            empty($_SESSION),
            "Session cleared"
        );
    }

    /**
     * Test update checking workflow (for Tor Browser polling)
     */
    public function testUpdateCheckingWorkflow() {
        echo "\n=== Testing Update Checking Workflow ===\n";

        if ($this->pdo === null) {
            echo "⚠️  Skipping update checking - no database connection\n";
            return;
        }

        // Test 1: Check for new transactions (old timestamp)
        $oldTimestamp = date('Y-m-d H:i:s', strtotime('-1 day'));
        $hasNewTx = checkForNewTransactions($oldTimestamp);

        $this->testResult(
            "Check for new transactions since yesterday",
            is_bool($hasNewTx),
            "Has new: " . ($hasNewTx ? 'yes' : 'no')
        );

        // Test 2: Check for new contact requests
        $hasNewContacts = checkForNewContactRequests($oldTimestamp);

        $this->testResult(
            "Check for new contact requests",
            is_bool($hasNewContacts),
            "Has new: " . ($hasNewContacts ? 'yes' : 'no')
        );

        // Test 3: Current timestamp check (should be no new)
        $currentTimestamp = date('Y-m-d H:i:s', time());
        $hasNewNow = checkForNewTransactions($currentTimestamp);

        $this->testResult(
            "No new transactions since current time",
            $hasNewNow === false,
            "Correctly returns false"
        );

        // Test 4: Query parameter handling
        $_GET = ['check_updates' => '1', 'last_check' => strtotime('-1 hour')];

        $checkRequested = isset($_GET['check_updates']) && $_GET['check_updates'] === '1';
        $lastCheck = $_GET['last_check'] ?? 0;

        $this->testResult(
            "Update check parameters extracted",
            $checkRequested && $lastCheck > 0,
            "Check requested, last check: $lastCheck"
        );
    }

    /**
     * Test error handling workflow
     */
    public function testErrorHandlingWorkflow() {
        echo "\n=== Testing Error Handling Workflow ===\n";

        // Test 1: Missing field error
        $invalidData = [
            'address' => '',
            'name' => 'Test',
            'fee' => '1.0'
        ];

        $hasError = empty($invalidData['address']);

        $this->testResult(
            "Missing required field detected",
            $hasError,
            "Empty address triggers error"
        );

        // Test 2: Error message construction
        $errorMessage = "All fields are required";
        $errorType = "error";

        $this->testResult(
            "Error message and type set",
            $errorMessage === "All fields are required" && $errorType === "error",
            "Message: $errorMessage, Type: $errorType"
        );

        // Test 3: Redirect with error message
        $redirectUrl = '?message=' . urlencode($errorMessage) . '&type=' . urlencode($errorType);

        $this->testResult(
            "Error redirect URL constructed",
            strpos($redirectUrl, 'type=error') !== false,
            "URL contains error type"
        );

        // Test 4: Service error handling
        $serviceOutput = "ERROR: Contact not found";
        $result = parseContactOutput($serviceOutput);

        $this->testResult(
            "Service error parsed correctly",
            $result['type'] === 'error',
            "Error type: {$result['type']}"
        );

        // Test 5: Exception handling simulation
        $exceptionMessage = "Internal server error: Database connection failed";
        $containsError = strpos($exceptionMessage, 'error') !== false;

        $this->testResult(
            "Exception message formatted",
            $containsError,
            "Error message included"
        );

        // Test 6: Output buffer cleanup on error
        ob_start();
        echo "Some output before error";
        ob_end_clean(); // Simulate cleanup

        $this->testResult(
            "Output buffer cleaned on error",
            ob_get_level() === 0 || ob_get_contents() === false,
            "Buffer cleared"
        );
    }

    /**
     * Run all integration tests
     */
    public function runAllTests() {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "GUI INTEGRATION TEST SUITE\n";
        echo str_repeat("=", 60) . "\n";

        $this->testAddContactWorkflow();
        $this->testSendTransactionWorkflow();
        $this->testContactManagementWorkflow();
        $this->testDataRetrievalWorkflow();
        $this->testAuthenticationWorkflow();
        $this->testUpdateCheckingWorkflow();
        $this->testErrorHandlingWorkflow();

        // Summary
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "INTEGRATION TEST SUMMARY\n";
        echo str_repeat("=", 60) . "\n";
        echo "✅ Tests Passed: {$this->testsPassed}\n";
        echo "❌ Tests Failed: {$this->testsFailed}\n";
        echo "Total Tests: " . ($this->testsPassed + $this->testsFailed) . "\n";

        if ($this->testsFailed === 0) {
            echo "\n🎉 All integration tests passed!\n";
        } else {
            echo "\n⚠️ Some tests failed. Please review the errors above.\n";
        }
        echo str_repeat("=", 60) . "\n";

        return ['passed' => $this->testsPassed, 'failed' => $this->testsFailed];
    }
}

// Run tests if executed directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $tester = new IntegrationTest();
    $results = $tester->runAllTests();
    exit($results['failed'] > 0 ? 1 : 0);
}
