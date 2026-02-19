<?php
/**
 * Unit Tests for CliJsonResponse
 *
 * Tests JSON response formatting for CLI commands (RFC 9457 compliant).
 */

namespace Eiou\Tests\Cli;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Cli\CliJsonResponse;
use Eiou\Core\ErrorCodes;

#[CoversClass(CliJsonResponse::class)]
class CliJsonResponseTest extends TestCase
{
    /**
     * Test success response structure
     */
    public function testSuccessResponseStructure(): void
    {
        $response = new CliJsonResponse('test-command');
        $json = $response->success(['key' => 'value'], 'Operation successful');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertEquals(['key' => 'value'], $data['data']);
        $this->assertEquals('Operation successful', $data['message']);
        $this->assertArrayHasKey('metadata', $data);
    }

    /**
     * Test success response metadata
     */
    public function testSuccessResponseMetadata(): void
    {
        $response = new CliJsonResponse('balance', 'node-123');
        $json = $response->success(['balance' => 100]);
        $data = json_decode($json, true);

        $this->assertArrayHasKey('timestamp', $data['metadata']);
        $this->assertArrayHasKey('version', $data['metadata']);
        $this->assertArrayHasKey('execution_time_ms', $data['metadata']);
        $this->assertEquals('balance', $data['metadata']['command']);
        $this->assertEquals('node-123', $data['metadata']['node_id']);
    }

    /**
     * Test error response RFC 9457 structure
     */
    public function testErrorResponseRfc9457Structure(): void
    {
        $response = new CliJsonResponse('send');
        $json = $response->error('Insufficient funds', ErrorCodes::INSUFFICIENT_FUNDS);
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);

        $error = $data['error'];
        $this->assertArrayHasKey('type', $error);
        $this->assertArrayHasKey('title', $error);
        $this->assertArrayHasKey('status', $error);
        $this->assertArrayHasKey('detail', $error);
        $this->assertArrayHasKey('code', $error);
        $this->assertArrayHasKey('timestamp', $error);

        $this->assertStringContainsString('eiou.org/docs/errors', $error['type']);
        $this->assertEquals(ErrorCodes::INSUFFICIENT_FUNDS, $error['code']);
        $this->assertEquals('Insufficient funds', $error['detail']);
    }

    /**
     * Test error response auto-detects HTTP status
     */
    public function testErrorResponseAutoDetectsHttpStatus(): void
    {
        $response = new CliJsonResponse();

        // 404 errors
        $json = $response->error('Not found', ErrorCodes::NOT_FOUND);
        $data = json_decode($json, true);
        $this->assertEquals(404, $data['error']['status']);

        // 401 errors
        $json = $response->error('Auth required', ErrorCodes::AUTH_REQUIRED);
        $data = json_decode($json, true);
        $this->assertEquals(401, $data['error']['status']);

        // 403 errors
        $json = $response->error('Permission denied', ErrorCodes::PERMISSION_DENIED);
        $data = json_decode($json, true);
        $this->assertEquals(403, $data['error']['status']);
    }

    /**
     * Test validation error includes field errors
     */
    public function testValidationErrorIncludesFieldErrors(): void
    {
        $response = new CliJsonResponse();
        $errors = [
            'amount' => 'Amount must be positive',
            'recipient' => 'Recipient address is invalid'
        ];

        $json = $response->validationError($errors);
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertEquals(ErrorCodes::VALIDATION_ERROR, $data['error']['code']);
        $this->assertArrayHasKey('validation_errors', $data['error']);
        $this->assertEquals($errors, $data['error']['validation_errors']);
    }

    /**
     * Test list response without pagination
     */
    public function testListResponseWithoutPagination(): void
    {
        $response = new CliJsonResponse();
        $items = [['id' => 1], ['id' => 2], ['id' => 3]];

        $json = $response->list($items);
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertEquals($items, $data['data']);
        $this->assertArrayNotHasKey('pagination', $data);
    }

    /**
     * Test list response with pagination
     */
    public function testListResponseWithPagination(): void
    {
        $response = new CliJsonResponse();
        $items = [['id' => 1], ['id' => 2]];

        $json = $response->list($items, 10, 1, 2);
        $data = json_decode($json, true);

        $this->assertArrayHasKey('pagination', $data);
        $this->assertEquals(10, $data['pagination']['total']);
        $this->assertEquals(2, $data['pagination']['count']);
        $this->assertEquals(1, $data['pagination']['page']);
        $this->assertEquals(2, $data['pagination']['per_page']);
        $this->assertTrue($data['pagination']['has_more']);
    }

    /**
     * Test table response
     */
    public function testTableResponse(): void
    {
        $response = new CliJsonResponse();
        $headers = ['Name', 'Balance', 'Status'];
        $rows = [
            ['Alice', '$100.00', 'Active'],
            ['Bob', '$50.00', 'Pending']
        ];

        $json = $response->table($headers, $rows, 'User Balances');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertEquals($headers, $data['data']['headers']);
        $this->assertEquals($rows, $data['data']['rows']);
        $this->assertEquals(2, $data['data']['row_count']);
        $this->assertEquals('User Balances', $data['data']['title']);
    }

    /**
     * Test transaction response with success status
     */
    public function testTransactionResponseSuccess(): void
    {
        $response = new CliJsonResponse();
        $txData = ['txid' => 'abc123', 'amount' => 100];

        $json = $response->transaction('success', 'Transaction completed', $txData);
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('Transaction completed', $data['message']);
        $this->assertEquals($txData, $data['data']);
    }

    /**
     * Test transaction response with rejected status
     */
    public function testTransactionResponseRejected(): void
    {
        $response = new CliJsonResponse();

        $json = $response->transaction('rejected', 'Transaction rejected');
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertEquals('rejected', $data['status']);
    }

    /**
     * Test settings response
     */
    public function testSettingsResponse(): void
    {
        $response = new CliJsonResponse();
        $settings = ['defaultFee' => 1.5, 'maxFee' => 5.0];

        $json = $response->settings($settings, 'Settings retrieved');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertEquals($settings, $data['data']['settings']);
        $this->assertEquals('Settings retrieved', $data['message']);
    }

    /**
     * Test userInfo response
     */
    public function testUserInfoResponse(): void
    {
        $response = new CliJsonResponse();
        $userInfo = ['name' => 'Alice', 'balance' => 100];

        $json = $response->userInfo($userInfo);
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertEquals($userInfo, $data['data']);
    }

    /**
     * Test contact response with contact data
     */
    public function testContactResponseWithData(): void
    {
        $response = new CliJsonResponse();
        $contact = ['name' => 'Bob', 'address' => 'http://bob.example'];

        $json = $response->contact($contact, 'Contact found');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertEquals($contact, $data['data']);
    }

    /**
     * Test contact response with null returns error
     */
    public function testContactResponseWithNullReturnsError(): void
    {
        $response = new CliJsonResponse();

        $json = $response->contact(null);
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertEquals(ErrorCodes::CONTACT_NOT_FOUND, $data['error']['code']);
    }

    /**
     * Test help response
     */
    public function testHelpResponse(): void
    {
        $response = new CliJsonResponse();
        $commands = [
            'send' => 'Send funds to a contact',
            'balance' => 'Check your balance'
        ];

        $json = $response->help($commands, 'send');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertEquals($commands, $data['data']['commands']);
        $this->assertEquals('send', $data['data']['requested_command']);
    }

    /**
     * Test rateLimitExceeded response
     */
    public function testRateLimitExceededResponse(): void
    {
        $response = new CliJsonResponse();

        $json = $response->rateLimitExceeded(300, 'send');
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertEquals(ErrorCodes::RATE_LIMIT_EXCEEDED, $data['error']['code']);
        $this->assertEquals(300, $data['error']['retry_after']);
        $this->assertEquals('send', $data['error']['command']);
    }

    /**
     * Test walletExists response
     */
    public function testWalletExistsResponse(): void
    {
        $response = new CliJsonResponse();

        $json = $response->walletExists();
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertEquals(ErrorCodes::WALLET_EXISTS, $data['error']['code']);
    }

    /**
     * Test walletRequired response
     */
    public function testWalletRequiredResponse(): void
    {
        $response = new CliJsonResponse();

        $json = $response->walletRequired();
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertEquals(ErrorCodes::WALLET_NOT_FOUND, $data['error']['code']);
    }

    /**
     * Test balances response
     */
    public function testBalancesResponse(): void
    {
        $response = new CliJsonResponse();
        $balances = [['currency' => 'USD', 'amount' => 100]];

        $json = $response->balances($balances, 'http://me.example');
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertEquals($balances, $data['data']['balances']);
        $this->assertEquals('http://me.example', $data['data']['filter']['address']);
    }

    /**
     * Test transactionHistory response
     */
    public function testTransactionHistoryResponse(): void
    {
        $response = new CliJsonResponse();
        $transactions = [['txid' => 'tx1'], ['txid' => 'tx2']];

        $json = $response->transactionHistory($transactions, 'all', 100, 2);
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertEquals($transactions, $data['data']['transactions']);
        $this->assertEquals('all', $data['data']['direction']);
        $this->assertEquals(100, $data['data']['total']);
        $this->assertEquals(2, $data['data']['displayed']);
    }

    /**
     * Test setCommand fluent interface
     */
    public function testSetCommandFluentInterface(): void
    {
        $response = new CliJsonResponse();
        $result = $response->setCommand('balance');

        $this->assertInstanceOf(CliJsonResponse::class, $result);
    }

    /**
     * Test setNodeId fluent interface
     */
    public function testSetNodeIdFluentInterface(): void
    {
        $response = new CliJsonResponse();
        $result = $response->setNodeId('node-456');

        $this->assertInstanceOf(CliJsonResponse::class, $result);
    }

    /**
     * Test create factory method
     */
    public function testCreateFactoryMethod(): void
    {
        $response = CliJsonResponse::create('test', 'node-1');

        $this->assertInstanceOf(CliJsonResponse::class, $response);
    }

    /**
     * Test JSON output is valid
     */
    public function testJsonOutputIsValid(): void
    {
        $response = new CliJsonResponse();
        $json = $response->success(['test' => true]);

        $decoded = json_decode($json);
        $this->assertNotNull($decoded);
        $this->assertEquals(JSON_ERROR_NONE, json_last_error());
    }

    /**
     * Test setIncludeMetadata excludes metadata from success response
     */
    public function testNoMetadataExcludesFromSuccessResponse(): void
    {
        $response = new CliJsonResponse('test');
        $response->setIncludeMetadata(false);

        $json = $response->success(['key' => 'value']);
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertEquals(['key' => 'value'], $data['data']);
        $this->assertArrayNotHasKey('metadata', $data);
    }

    /**
     * Test setIncludeMetadata excludes metadata from error response
     */
    public function testNoMetadataExcludesFromErrorResponse(): void
    {
        $response = new CliJsonResponse('test');
        $response->setIncludeMetadata(false);

        $json = $response->error('Something failed', ErrorCodes::GENERAL_ERROR);
        $data = json_decode($json, true);

        $this->assertFalse($data['success']);
        $this->assertArrayNotHasKey('metadata', $data);
    }

    /**
     * Test setIncludeMetadata excludes metadata from list response
     */
    public function testNoMetadataExcludesFromListResponse(): void
    {
        $response = new CliJsonResponse();
        $response->setIncludeMetadata(false);

        $json = $response->list([['id' => 1]], 1);
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertArrayNotHasKey('metadata', $data);
    }

    /**
     * Test setIncludeMetadata excludes metadata from table response
     */
    public function testNoMetadataExcludesFromTableResponse(): void
    {
        $response = new CliJsonResponse();
        $response->setIncludeMetadata(false);

        $json = $response->table(['Name'], [['Alice']]);
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertArrayNotHasKey('metadata', $data);
    }

    /**
     * Test setIncludeMetadata excludes metadata from transaction response
     */
    public function testNoMetadataExcludesFromTransactionResponse(): void
    {
        $response = new CliJsonResponse();
        $response->setIncludeMetadata(false);

        $json = $response->transaction('success', 'Done', ['txid' => 'abc']);
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertArrayNotHasKey('metadata', $data);
    }

    /**
     * Test setIncludeMetadata excludes metadata from settings response
     */
    public function testNoMetadataExcludesFromSettingsResponse(): void
    {
        $response = new CliJsonResponse();
        $response->setIncludeMetadata(false);

        $json = $response->settings(['fee' => 1.5]);
        $data = json_decode($json, true);

        $this->assertTrue($data['success']);
        $this->assertArrayNotHasKey('metadata', $data);
    }

    /**
     * Test setIncludeMetadata returns self for fluent interface
     */
    public function testSetIncludeMetadataFluentInterface(): void
    {
        $response = new CliJsonResponse();
        $result = $response->setIncludeMetadata(false);

        $this->assertInstanceOf(CliJsonResponse::class, $result);
    }

    /**
     * Test metadata is included by default
     */
    public function testMetadataIncludedByDefault(): void
    {
        $response = new CliJsonResponse('test');
        $json = $response->success(['key' => 'value']);
        $data = json_decode($json, true);

        $this->assertArrayHasKey('metadata', $data);
    }
}
