<?php
/**
 * Unit tests for CliJsonResponse class
 *
 * Copyright 2025
 * Tests the standardized JSON response format for CLI commands.
 *
 * @package Tests\Unit\Cli
 */

require_once dirname(__DIR__, 3) . '/files/src/cli/CliJsonResponse.php';

/**
 * Test class for CliJsonResponse
 */
class CliJsonResponseTest
{
    /** @var CliJsonResponse */
    private CliJsonResponse $response;

    /** @var int Test counter */
    private int $testsPassed = 0;

    /** @var int Test counter */
    private int $testsFailed = 0;

    /** @var array Failed test messages */
    private array $failures = [];

    /**
     * Run all tests
     */
    public function runAll(): void
    {
        echo "Running CliJsonResponse Tests...\n";
        echo "================================\n\n";

        $this->setUp();
        $this->testSuccessResponse();

        $this->setUp();
        $this->testErrorResponse();

        $this->setUp();
        $this->testValidationError();

        $this->setUp();
        $this->testListResponse();

        $this->setUp();
        $this->testTableResponse();

        $this->setUp();
        $this->testTransactionResponse();

        $this->setUp();
        $this->testSettingsResponse();

        $this->setUp();
        $this->testUserInfoResponse();

        $this->setUp();
        $this->testContactResponse();

        $this->setUp();
        $this->testHelpResponse();

        $this->setUp();
        $this->testRateLimitResponse();

        $this->setUp();
        $this->testBalancesResponse();

        $this->setUp();
        $this->testTransactionHistoryResponse();

        $this->setUp();
        $this->testMetadataFields();

        $this->printSummary();
    }

    /**
     * Set up test fixture
     */
    private function setUp(): void
    {
        $this->response = new CliJsonResponse('test', 'node-test');
    }

    /**
     * Assert helper
     */
    private function assert(bool $condition, string $testName, string $message = ''): void
    {
        if ($condition) {
            echo "✓ $testName\n";
            $this->testsPassed++;
        } else {
            echo "✗ $testName" . ($message ? ": $message" : "") . "\n";
            $this->testsFailed++;
            $this->failures[] = $testName . ($message ? ": $message" : "");
        }
    }

    /**
     * Test success response structure
     */
    private function testSuccessResponse(): void
    {
        echo "\n[Test: Success Response]\n";

        $result = $this->response->success(['key' => 'value'], 'Operation completed');
        $decoded = json_decode($result, true);

        $this->assert($decoded !== null, 'JSON is valid');
        $this->assert($decoded['success'] === true, 'success field is true');
        $this->assert(isset($decoded['data']), 'data field exists');
        $this->assert($decoded['data']['key'] === 'value', 'data contains expected value');
        $this->assert($decoded['message'] === 'Operation completed', 'message field is set');
        $this->assert(isset($decoded['metadata']), 'metadata field exists');
    }

    /**
     * Test error response structure (RFC 9457)
     */
    private function testErrorResponse(): void
    {
        echo "\n[Test: Error Response (RFC 9457)]\n";

        $result = $this->response->error('Something went wrong', 'TEST_ERROR', 500, ['extra' => 'data']);
        $decoded = json_decode($result, true);

        $this->assert($decoded !== null, 'JSON is valid');
        $this->assert($decoded['success'] === false, 'success field is false');
        $this->assert(isset($decoded['error']), 'error field exists');
        $this->assert($decoded['error']['code'] === 'TEST_ERROR', 'error code is set');
        $this->assert($decoded['error']['status'] === 500, 'status code is set');
        $this->assert($decoded['error']['detail'] === 'Something went wrong', 'detail is set');
        $this->assert(isset($decoded['error']['type']), 'type URI is set');
        $this->assert(isset($decoded['error']['title']), 'title is set');
        $this->assert(isset($decoded['error']['timestamp']), 'timestamp is set');
        $this->assert($decoded['error']['extra'] === 'data', 'additional data is included');
    }

    /**
     * Test validation error response
     */
    private function testValidationError(): void
    {
        echo "\n[Test: Validation Error]\n";

        $errors = [
            ['field' => 'email', 'message' => 'Invalid email format'],
            ['field' => 'amount', 'message' => 'Must be positive']
        ];
        $result = $this->response->validationError($errors);
        $decoded = json_decode($result, true);

        $this->assert($decoded !== null, 'JSON is valid');
        $this->assert($decoded['success'] === false, 'success is false');
        $this->assert($decoded['error']['code'] === 'VALIDATION_ERROR', 'error code is VALIDATION_ERROR');
        $this->assert(isset($decoded['error']['validation_errors']), 'validation_errors field exists');
        $this->assert(count($decoded['error']['validation_errors']) === 2, 'correct number of validation errors');
    }

    /**
     * Test list response with pagination
     */
    private function testListResponse(): void
    {
        echo "\n[Test: List Response with Pagination]\n";

        $items = [
            ['id' => 1, 'name' => 'Item 1'],
            ['id' => 2, 'name' => 'Item 2']
        ];
        $result = $this->response->list($items, 100, 1, 50);
        $decoded = json_decode($result, true);

        $this->assert($decoded !== null, 'JSON is valid');
        $this->assert($decoded['success'] === true, 'success is true');
        $this->assert(count($decoded['data']) === 2, 'data contains items');
        $this->assert(isset($decoded['pagination']), 'pagination field exists');
        $this->assert($decoded['pagination']['total'] === 100, 'pagination total is correct');
        $this->assert($decoded['pagination']['page'] === 1, 'pagination page is correct');
        $this->assert($decoded['pagination']['per_page'] === 50, 'pagination per_page is correct');
        $this->assert($decoded['pagination']['has_more'] === true, 'has_more is correct');
    }

    /**
     * Test table response
     */
    private function testTableResponse(): void
    {
        echo "\n[Test: Table Response]\n";

        $headers = ['Name', 'Amount', 'Currency'];
        $rows = [
            ['Alice', '100.00', 'USD'],
            ['Bob', '50.00', 'EUR']
        ];
        $result = $this->response->table($headers, $rows, 'Transaction Table');
        $decoded = json_decode($result, true);

        $this->assert($decoded !== null, 'JSON is valid');
        $this->assert($decoded['success'] === true, 'success is true');
        $this->assert($decoded['data']['title'] === 'Transaction Table', 'title is set');
        $this->assert($decoded['data']['headers'] === $headers, 'headers are correct');
        $this->assert($decoded['data']['rows'] === $rows, 'rows are correct');
        $this->assert($decoded['data']['row_count'] === 2, 'row_count is correct');
    }

    /**
     * Test transaction response
     */
    private function testTransactionResponse(): void
    {
        echo "\n[Test: Transaction Response]\n";

        $txData = ['hash' => 'abc123', 'amount' => 100, 'currency' => 'USD'];
        $result = $this->response->transaction('success', 'Transaction completed', $txData);
        $decoded = json_decode($result, true);

        $this->assert($decoded !== null, 'JSON is valid');
        $this->assert($decoded['success'] === true, 'success is true for successful transaction');
        $this->assert($decoded['status'] === 'success', 'status is success');
        $this->assert($decoded['message'] === 'Transaction completed', 'message is correct');
        $this->assert($decoded['data']['hash'] === 'abc123', 'transaction data is included');

        // Test rejected transaction
        $result2 = $this->response->transaction('rejected', 'Insufficient funds');
        $decoded2 = json_decode($result2, true);
        $this->assert($decoded2['success'] === false, 'success is false for rejected transaction');
    }

    /**
     * Test settings response
     */
    private function testSettingsResponse(): void
    {
        echo "\n[Test: Settings Response]\n";

        $settings = [
            'default_currency' => 'USD',
            'max_fee' => 5.0,
            'transport_mode' => 'http'
        ];
        $result = $this->response->settings($settings, 'Settings updated');
        $decoded = json_decode($result, true);

        $this->assert($decoded !== null, 'JSON is valid');
        $this->assert($decoded['success'] === true, 'success is true');
        $this->assert($decoded['data']['settings'] === $settings, 'settings are correct');
        $this->assert($decoded['message'] === 'Settings updated', 'message is set');
    }

    /**
     * Test user info response
     */
    private function testUserInfoResponse(): void
    {
        echo "\n[Test: User Info Response]\n";

        $userInfo = [
            'locators' => ['http' => 'http://localhost:8080'],
            'authentication_code' => 'ABC123',
            'public_key' => '-----BEGIN PUBLIC KEY-----'
        ];
        $result = $this->response->userInfo($userInfo);
        $decoded = json_decode($result, true);

        $this->assert($decoded !== null, 'JSON is valid');
        $this->assert($decoded['success'] === true, 'success is true');
        $this->assert($decoded['data'] === $userInfo, 'user info is correct');
    }

    /**
     * Test contact response
     */
    private function testContactResponse(): void
    {
        echo "\n[Test: Contact Response]\n";

        $contact = ['name' => 'Alice', 'address' => 'http://alice:8080', 'fee' => 2.5];
        $result = $this->response->contact($contact);
        $decoded = json_decode($result, true);

        $this->assert($decoded !== null, 'JSON is valid');
        $this->assert($decoded['success'] === true, 'success is true');
        $this->assert($decoded['data'] === $contact, 'contact data is correct');

        // Test not found
        $result2 = $this->response->contact(null, 'Contact not found');
        $decoded2 = json_decode($result2, true);
        $this->assert($decoded2['success'] === false, 'success is false for not found');
        $this->assert($decoded2['error']['code'] === 'NOT_FOUND', 'error code is NOT_FOUND');
    }

    /**
     * Test help response
     */
    private function testHelpResponse(): void
    {
        echo "\n[Test: Help Response]\n";

        $commands = [
            'send' => ['description' => 'Send eIOU', 'usage' => 'send [address] [amount]'],
            'help' => ['description' => 'Show help', 'usage' => 'help']
        ];
        $result = $this->response->help($commands, 'send');
        $decoded = json_decode($result, true);

        $this->assert($decoded !== null, 'JSON is valid');
        $this->assert($decoded['success'] === true, 'success is true');
        $this->assert(isset($decoded['data']['commands']), 'commands field exists');
        $this->assert($decoded['data']['requested_command'] === 'send', 'requested_command is set');
    }

    /**
     * Test rate limit exceeded response
     */
    private function testRateLimitResponse(): void
    {
        echo "\n[Test: Rate Limit Response]\n";

        $result = $this->response->rateLimitExceeded(60, 'send');
        $decoded = json_decode($result, true);

        $this->assert($decoded !== null, 'JSON is valid');
        $this->assert($decoded['success'] === false, 'success is false');
        $this->assert($decoded['error']['code'] === 'RATE_LIMIT_EXCEEDED', 'error code is correct');
        $this->assert($decoded['error']['retry_after'] === 60, 'retry_after is set');
        $this->assert($decoded['error']['command'] === 'send', 'command is set');
    }

    /**
     * Test balances response
     */
    private function testBalancesResponse(): void
    {
        echo "\n[Test: Balances Response]\n";

        $balances = [
            'user' => ['balances' => [['currency' => 'USD', 'total_balance' => '100.00']]],
            'contacts' => [['name' => 'Alice', 'received' => '50.00', 'sent' => '25.00']]
        ];
        $result = $this->response->balances($balances, 'http://alice:8080');
        $decoded = json_decode($result, true);

        $this->assert($decoded !== null, 'JSON is valid');
        $this->assert($decoded['success'] === true, 'success is true');
        $this->assert(isset($decoded['data']['balances']), 'balances field exists');
        $this->assert($decoded['data']['filter']['address'] === 'http://alice:8080', 'filter is set');
    }

    /**
     * Test transaction history response
     */
    private function testTransactionHistoryResponse(): void
    {
        echo "\n[Test: Transaction History Response]\n";

        $transactions = [
            ['timestamp' => '2025-01-01 10:00:00', 'amount' => 100, 'currency' => 'USD'],
            ['timestamp' => '2025-01-02 11:00:00', 'amount' => 50, 'currency' => 'USD']
        ];
        $result = $this->response->transactionHistory($transactions, 'sent', 100, 2);
        $decoded = json_decode($result, true);

        $this->assert($decoded !== null, 'JSON is valid');
        $this->assert($decoded['success'] === true, 'success is true');
        $this->assert($decoded['data']['direction'] === 'sent', 'direction is set');
        $this->assert($decoded['data']['total'] === 100, 'total is set');
        $this->assert($decoded['data']['displayed'] === 2, 'displayed count is set');
        $this->assert(count($decoded['data']['transactions']) === 2, 'transactions are included');
    }

    /**
     * Test metadata fields
     */
    private function testMetadataFields(): void
    {
        echo "\n[Test: Metadata Fields]\n";

        $result = $this->response->success(['test' => 'data']);
        $decoded = json_decode($result, true);

        $this->assert(isset($decoded['metadata']['timestamp']), 'timestamp is present');
        $this->assert(isset($decoded['metadata']['version']), 'version is present');
        $this->assert(isset($decoded['metadata']['execution_time_ms']), 'execution_time_ms is present');
        $this->assert($decoded['metadata']['command'] === 'test', 'command is correct');
        $this->assert($decoded['metadata']['node_id'] === 'node-test', 'node_id is correct');

        // Validate timestamp format (ISO 8601)
        $timestamp = $decoded['metadata']['timestamp'];
        $parsed = strtotime($timestamp);
        $this->assert($parsed !== false, 'timestamp is valid ISO 8601');
    }

    /**
     * Print test summary
     */
    private function printSummary(): void
    {
        echo "\n================================\n";
        echo "Test Summary\n";
        echo "================================\n";
        echo "Passed: {$this->testsPassed}\n";
        echo "Failed: {$this->testsFailed}\n";
        echo "Total:  " . ($this->testsPassed + $this->testsFailed) . "\n";

        if ($this->testsFailed > 0) {
            echo "\nFailures:\n";
            foreach ($this->failures as $failure) {
                echo "  - $failure\n";
            }
            exit(1);
        }

        echo "\nAll tests passed!\n";
        exit(0);
    }
}

// Run tests if executed directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $test = new CliJsonResponseTest();
    $test->runAll();
}
