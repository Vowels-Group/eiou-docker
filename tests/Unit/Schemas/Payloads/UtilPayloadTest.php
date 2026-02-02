<?php
/**
 * Unit Tests for UtilPayload
 *
 * Tests utility payload building functionality including:
 * - Insufficient balance rejection payloads
 * - Invalid transaction ID rejection payloads
 * - Invalid request level rejection payloads
 * - Invalid source rejection payloads
 * - Generic error payloads
 * - Validation error payloads
 * - Timeout error payloads
 * - Rate limit exceeded payloads
 * - Maintenance mode payloads
 */

namespace Eiou\Tests\Schemas\Payloads;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Schemas\Payloads\UtilPayload;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Core\ErrorCodes;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\ValidationUtilityService;

#[CoversClass(UtilPayload::class)]
class UtilPayloadTest extends TestCase
{
    private UtilPayload $payload;
    private UserContext $mockUserContext;
    private UtilityServiceContainer $mockUtilityContainer;
    private TransportUtilityService $mockTransportUtility;
    private CurrencyUtilityService $mockCurrencyUtility;
    private TimeUtilityService $mockTimeUtility;
    private ValidationUtilityService $mockValidationUtility;

    private const TEST_PUBLIC_KEY = 'test-public-key-abc123def456789012345678901234567890';
    private const TEST_HTTP_ADDRESS = 'http://192.168.1.100:8080';
    private const TEST_RESOLVED_ADDRESS = 'http://192.168.1.50:8080';
    private const TEST_TXID = 'abc123def456789012345678901234567890123456789012345678901234abcd';

    protected function setUp(): void
    {
        $this->mockUserContext = $this->createMock(UserContext::class);
        $this->mockUtilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->mockTransportUtility = $this->createMock(TransportUtilityService::class);
        $this->mockCurrencyUtility = $this->createMock(CurrencyUtilityService::class);
        $this->mockTimeUtility = $this->createMock(TimeUtilityService::class);
        $this->mockValidationUtility = $this->createMock(ValidationUtilityService::class);

        // Configure utility container to return mock utilities
        $this->mockUtilityContainer->method('getCurrencyUtility')
            ->willReturn($this->mockCurrencyUtility);
        $this->mockUtilityContainer->method('getTimeUtility')
            ->willReturn($this->mockTimeUtility);
        $this->mockUtilityContainer->method('getValidationUtility')
            ->willReturn($this->mockValidationUtility);
        $this->mockUtilityContainer->method('getTransportUtility')
            ->willReturn($this->mockTransportUtility);

        // Default mock behaviors
        $this->mockUserContext->method('getPublicKey')
            ->willReturn(self::TEST_PUBLIC_KEY);

        $this->mockTransportUtility->method('resolveUserAddressForTransport')
            ->willReturnCallback(function ($address) {
                return self::TEST_RESOLVED_ADDRESS;
            });

        // Default currency formatting behavior
        $this->mockCurrencyUtility->method('formatCurrency')
            ->willReturnCallback(function ($amount) {
                return '$' . number_format($amount / 100, 2);
            });

        $this->payload = new UtilPayload(
            $this->mockUserContext,
            $this->mockUtilityContainer
        );
    }

    // =========================================================================
    // Tests for build() method
    // =========================================================================

    /**
     * Test build returns empty array as per implementation
     */
    public function testBuildReturnsEmptyArray(): void
    {
        $result = $this->payload->build([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test build with data still returns empty array
     */
    public function testBuildWithDataReturnsEmptyArray(): void
    {
        $result = $this->payload->build([
            'key' => 'value',
            'nested' => ['data' => 'here']
        ]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // Tests for buildInsufficientBalance() method
    // =========================================================================

    /**
     * Test buildInsufficientBalance returns JSON with correct structure
     */
    public function testBuildInsufficientBalanceReturnsJsonWithCorrectStructure(): void
    {
        $availableFunds = 5000; // $50.00 in cents
        $requestedAmount = 10000; // $100.00 in cents
        $creditLimit = 2500; // $25.00 in cents
        $fundsOnHold = 1000; // $10.00 in cents

        $result = $this->payload->buildInsufficientBalance(
            $availableFunds,
            $requestedAmount,
            $creditLimit,
            $fundsOnHold
        );

        $this->assertIsString($result);
        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertArrayHasKey('credit_limit', $decoded);
        $this->assertArrayHasKey('current_balance', $decoded);
        $this->assertArrayHasKey('funds_on_hold', $decoded);
        $this->assertArrayHasKey('requested_amount', $decoded);
    }

    /**
     * Test buildInsufficientBalance returns rejected status
     */
    public function testBuildInsufficientBalanceReturnsRejectedStatus(): void
    {
        $result = $this->payload->buildInsufficientBalance(5000, 10000, 2500, 1000);
        $decoded = json_decode($result, true);

        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
        $this->assertEquals('Insufficient balance or credit', $decoded['message']);
    }

    /**
     * Test buildInsufficientBalance uses currency utility for formatting
     */
    public function testBuildInsufficientBalanceUsesCurrencyUtility(): void
    {
        $this->mockCurrencyUtility->expects($this->exactly(4))
            ->method('formatCurrency')
            ->willReturnCallback(function ($amount) {
                return '$' . number_format($amount / 100, 2);
            });

        $this->payload->buildInsufficientBalance(5000, 10000, 2500, 1000);
    }

    /**
     * Test buildInsufficientBalance formats amounts correctly
     */
    public function testBuildInsufficientBalanceFormatsAmountsCorrectly(): void
    {
        $result = $this->payload->buildInsufficientBalance(5000, 10000, 2500, 1000);
        $decoded = json_decode($result, true);

        $this->assertEquals('$50.00', $decoded['current_balance']);
        $this->assertEquals('$100.00', $decoded['requested_amount']);
        $this->assertEquals('$25.00', $decoded['credit_limit']);
        $this->assertEquals('$10.00', $decoded['funds_on_hold']);
    }

    /**
     * Test buildInsufficientBalance with zero values
     */
    public function testBuildInsufficientBalanceWithZeroValues(): void
    {
        $result = $this->payload->buildInsufficientBalance(0, 0, 0, 0);

        $this->assertIsString($result);
        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
        $this->assertEquals('$0.00', $decoded['current_balance']);
        $this->assertEquals('$0.00', $decoded['requested_amount']);
        $this->assertEquals('$0.00', $decoded['credit_limit']);
        $this->assertEquals('$0.00', $decoded['funds_on_hold']);
    }

    /**
     * Test buildInsufficientBalance with float values
     */
    public function testBuildInsufficientBalanceWithFloatValues(): void
    {
        $result = $this->payload->buildInsufficientBalance(5050.50, 10099.99, 2575.25, 1025.75);
        $decoded = json_decode($result, true);

        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
        $this->assertArrayHasKey('current_balance', $decoded);
        $this->assertArrayHasKey('requested_amount', $decoded);
    }

    // =========================================================================
    // Tests for buildInvalidTransactionId() static method
    // =========================================================================

    /**
     * Test buildInvalidTransactionId returns JSON with correct structure
     */
    public function testBuildInvalidTransactionIdReturnsJsonWithCorrectStructure(): void
    {
        $previousTxResult = ['txid' => self::TEST_TXID];
        $request = ['previousTxid' => 'wrong-txid-value'];

        $result = UtilPayload::buildInvalidTransactionId($previousTxResult, $request);

        $this->assertIsString($result);
        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertArrayHasKey('expected', $decoded);
        $this->assertArrayHasKey('received', $decoded);
    }

    /**
     * Test buildInvalidTransactionId returns rejected status
     */
    public function testBuildInvalidTransactionIdReturnsRejectedStatus(): void
    {
        $previousTxResult = ['txid' => self::TEST_TXID];
        $request = ['previousTxid' => 'wrong-txid'];

        $result = UtilPayload::buildInvalidTransactionId($previousTxResult, $request);
        $decoded = json_decode($result, true);

        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
    }

    /**
     * Test buildInvalidTransactionId includes expected and received values
     */
    public function testBuildInvalidTransactionIdIncludesExpectedAndReceivedValues(): void
    {
        $expectedTxid = self::TEST_TXID;
        $receivedTxid = 'received-txid-different';

        $previousTxResult = ['txid' => $expectedTxid];
        $request = ['previousTxid' => $receivedTxid];

        $result = UtilPayload::buildInvalidTransactionId($previousTxResult, $request);
        $decoded = json_decode($result, true);

        $this->assertEquals($expectedTxid, $decoded['expected']);
        $this->assertEquals($receivedTxid, $decoded['received']);
        $this->assertStringContainsString($expectedTxid, $decoded['message']);
        $this->assertStringContainsString($receivedTxid, $decoded['message']);
    }

    /**
     * Test buildInvalidTransactionId handles missing txid in previous result
     */
    public function testBuildInvalidTransactionIdHandlesMissingTxidInPreviousResult(): void
    {
        $previousTxResult = []; // No txid
        $request = ['previousTxid' => 'some-txid'];

        $result = UtilPayload::buildInvalidTransactionId($previousTxResult, $request);
        $decoded = json_decode($result, true);

        $this->assertEquals('unknown', $decoded['expected']);
        $this->assertEquals('some-txid', $decoded['received']);
    }

    /**
     * Test buildInvalidTransactionId handles missing previousTxid in request
     */
    public function testBuildInvalidTransactionIdHandlesMissingPreviousTxidInRequest(): void
    {
        $previousTxResult = ['txid' => self::TEST_TXID];
        $request = []; // No previousTxid

        $result = UtilPayload::buildInvalidTransactionId($previousTxResult, $request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_TXID, $decoded['expected']);
        $this->assertEquals('none', $decoded['received']);
    }

    /**
     * Test buildInvalidTransactionId handles both missing values
     */
    public function testBuildInvalidTransactionIdHandlesBothMissingValues(): void
    {
        $previousTxResult = [];
        $request = [];

        $result = UtilPayload::buildInvalidTransactionId($previousTxResult, $request);
        $decoded = json_decode($result, true);

        $this->assertEquals('unknown', $decoded['expected']);
        $this->assertEquals('none', $decoded['received']);
        $this->assertStringContainsString('Expecting: unknown', $decoded['message']);
        $this->assertStringContainsString('Received: none', $decoded['message']);
    }

    // =========================================================================
    // Tests for buildInvalidRequestLevel() static method
    // =========================================================================

    /**
     * Test buildInvalidRequestLevel returns JSON with correct structure
     */
    public function testBuildInvalidRequestLevelReturnsJsonWithCorrectStructure(): void
    {
        $request = [
            'requestLevel' => 5,
            'maxRequestLevel' => 3
        ];

        $result = UtilPayload::buildInvalidRequestLevel($request);

        $this->assertIsString($result);
        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertArrayHasKey('request_level', $decoded);
        $this->assertArrayHasKey('max_request_level', $decoded);
    }

    /**
     * Test buildInvalidRequestLevel returns rejected status
     */
    public function testBuildInvalidRequestLevelReturnsRejectedStatus(): void
    {
        $request = [
            'requestLevel' => 10,
            'maxRequestLevel' => 6
        ];

        $result = UtilPayload::buildInvalidRequestLevel($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
        $this->assertEquals('Invalid request level', $decoded['message']);
    }

    /**
     * Test buildInvalidRequestLevel includes request level values
     */
    public function testBuildInvalidRequestLevelIncludesRequestLevelValues(): void
    {
        $request = [
            'requestLevel' => 7,
            'maxRequestLevel' => 5
        ];

        $result = UtilPayload::buildInvalidRequestLevel($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(7, $decoded['request_level']);
        $this->assertEquals(5, $decoded['max_request_level']);
    }

    /**
     * Test buildInvalidRequestLevel handles missing requestLevel
     */
    public function testBuildInvalidRequestLevelHandlesMissingRequestLevel(): void
    {
        $request = ['maxRequestLevel' => 6];

        $result = UtilPayload::buildInvalidRequestLevel($request);
        $decoded = json_decode($result, true);

        $this->assertNull($decoded['request_level']);
        $this->assertEquals(6, $decoded['max_request_level']);
    }

    /**
     * Test buildInvalidRequestLevel handles missing maxRequestLevel
     */
    public function testBuildInvalidRequestLevelHandlesMissingMaxRequestLevel(): void
    {
        $request = ['requestLevel' => 10];

        $result = UtilPayload::buildInvalidRequestLevel($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(10, $decoded['request_level']);
        $this->assertNull($decoded['max_request_level']);
    }

    /**
     * Test buildInvalidRequestLevel handles empty request
     */
    public function testBuildInvalidRequestLevelHandlesEmptyRequest(): void
    {
        $request = [];

        $result = UtilPayload::buildInvalidRequestLevel($request);
        $decoded = json_decode($result, true);

        $this->assertNull($decoded['request_level']);
        $this->assertNull($decoded['max_request_level']);
    }

    // =========================================================================
    // Tests for buildInvalidSource() method
    // =========================================================================

    /**
     * Test buildInvalidSource returns JSON with correct structure
     */
    public function testBuildInvalidSourceReturnsJsonWithCorrectStructure(): void
    {
        $message = ['senderAddress' => self::TEST_HTTP_ADDRESS];

        $result = $this->payload->buildInvalidSource($message);

        $this->assertIsString($result);
        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertArrayHasKey('sender_address', $decoded);
    }

    /**
     * Test buildInvalidSource returns rejected status
     */
    public function testBuildInvalidSourceReturnsRejectedStatus(): void
    {
        $message = ['senderAddress' => self::TEST_HTTP_ADDRESS];

        $result = $this->payload->buildInvalidSource($message);
        $decoded = json_decode($result, true);

        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
    }

    /**
     * Test buildInvalidSource message includes receiver address
     */
    public function testBuildInvalidSourceMessageIncludesReceiverAddress(): void
    {
        $message = ['senderAddress' => self::TEST_HTTP_ADDRESS];

        $result = $this->payload->buildInvalidSource($message);
        $decoded = json_decode($result, true);

        $this->assertStringContainsString('unknown source', $decoded['message']);
        $this->assertStringContainsString('receiver', $decoded['message']);
        $this->assertStringContainsString(self::TEST_RESOLVED_ADDRESS, $decoded['message']);
    }

    /**
     * Test buildInvalidSource uses transport utility for address resolution
     */
    public function testBuildInvalidSourceUsesTransportUtility(): void
    {
        $this->mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_HTTP_ADDRESS)
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        $message = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $this->payload->buildInvalidSource($message);
    }

    /**
     * Test buildInvalidSource handles missing senderAddress
     */
    public function testBuildInvalidSourceHandlesMissingSenderAddress(): void
    {
        $message = [];

        $result = $this->payload->buildInvalidSource($message);
        $decoded = json_decode($result, true);

        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
        $this->assertNull($decoded['sender_address']);
    }

    /**
     * Test buildInvalidSource includes sender_address in output
     */
    public function testBuildInvalidSourceIncludesSenderAddressInOutput(): void
    {
        $message = ['senderAddress' => self::TEST_HTTP_ADDRESS];

        $result = $this->payload->buildInvalidSource($message);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_HTTP_ADDRESS, $decoded['sender_address']);
    }

    // =========================================================================
    // Tests for buildError() static method
    // =========================================================================

    /**
     * Test buildError returns array with correct structure
     */
    public function testBuildErrorReturnsArrayWithCorrectStructure(): void
    {
        $result = UtilPayload::buildError('Test error message');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Test buildError returns error status
     */
    public function testBuildErrorReturnsErrorStatus(): void
    {
        $result = UtilPayload::buildError('Test error');

        $this->assertEquals('error', $result['status']);
    }

    /**
     * Test buildError uses default error code
     */
    public function testBuildErrorUsesDefaultErrorCode(): void
    {
        $result = UtilPayload::buildError('Test error');

        $this->assertEquals(ErrorCodes::GENERAL_ERROR, $result['code']);
    }

    /**
     * Test buildError uses provided error code
     */
    public function testBuildErrorUsesProvidedErrorCode(): void
    {
        $result = UtilPayload::buildError('Test error', ErrorCodes::NOT_FOUND);

        $this->assertEquals(ErrorCodes::NOT_FOUND, $result['code']);
    }

    /**
     * Test buildError includes error message
     */
    public function testBuildErrorIncludesErrorMessage(): void
    {
        $errorMessage = 'This is a custom error message';
        $result = UtilPayload::buildError($errorMessage);

        $this->assertEquals($errorMessage, $result['message']);
    }

    /**
     * Test buildError includes timestamp
     */
    public function testBuildErrorIncludesTimestamp(): void
    {
        $beforeTime = time();
        $result = UtilPayload::buildError('Test error');
        $afterTime = time();

        $this->assertGreaterThanOrEqual($beforeTime, $result['timestamp']);
        $this->assertLessThanOrEqual($afterTime, $result['timestamp']);
    }

    /**
     * Test buildError merges additional data
     */
    public function testBuildErrorMergesAdditionalData(): void
    {
        $additionalData = [
            'field' => 'username',
            'details' => 'Username is too short',
            'min_length' => 3
        ];

        $result = UtilPayload::buildError('Validation failed', ErrorCodes::VALIDATION_ERROR, $additionalData);

        $this->assertEquals('username', $result['field']);
        $this->assertEquals('Username is too short', $result['details']);
        $this->assertEquals(3, $result['min_length']);
        // Original fields still present
        $this->assertEquals('error', $result['status']);
        $this->assertEquals(ErrorCodes::VALIDATION_ERROR, $result['code']);
    }

    /**
     * Test buildError additional data can override default fields
     */
    public function testBuildErrorAdditionalDataCanOverrideDefaultFields(): void
    {
        $additionalData = [
            'status' => 'custom_status',
            'custom_field' => 'value'
        ];

        $result = UtilPayload::buildError('Test error', ErrorCodes::GENERAL_ERROR, $additionalData);

        // Additional data fields override default fields
        $this->assertEquals('custom_status', $result['status']);
        $this->assertEquals('value', $result['custom_field']);
    }

    /**
     * Test buildError with empty additional data
     */
    public function testBuildErrorWithEmptyAdditionalData(): void
    {
        $result = UtilPayload::buildError('Test error', ErrorCodes::GENERAL_ERROR, []);

        $this->assertCount(4, $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('timestamp', $result);
    }

    // =========================================================================
    // Tests for buildValidationError() static method
    // =========================================================================

    /**
     * Test buildValidationError returns array with correct structure
     */
    public function testBuildValidationErrorReturnsArrayWithCorrectStructure(): void
    {
        $errors = ['email' => 'Invalid email format'];

        $result = UtilPayload::buildValidationError($errors);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Test buildValidationError returns error status
     */
    public function testBuildValidationErrorReturnsErrorStatus(): void
    {
        $result = UtilPayload::buildValidationError([]);

        $this->assertEquals('error', $result['status']);
    }

    /**
     * Test buildValidationError uses validation error code
     */
    public function testBuildValidationErrorUsesValidationErrorCode(): void
    {
        $result = UtilPayload::buildValidationError([]);

        $this->assertEquals(ErrorCodes::VALIDATION_ERROR, $result['code']);
    }

    /**
     * Test buildValidationError includes validation failed message
     */
    public function testBuildValidationErrorIncludesValidationFailedMessage(): void
    {
        $result = UtilPayload::buildValidationError([]);

        $this->assertEquals('Validation failed', $result['message']);
    }

    /**
     * Test buildValidationError includes validation errors
     */
    public function testBuildValidationErrorIncludesValidationErrors(): void
    {
        $errors = [
            'email' => 'Invalid email format',
            'username' => 'Username is required',
            'password' => 'Password must be at least 8 characters'
        ];

        $result = UtilPayload::buildValidationError($errors);

        $this->assertEquals($errors, $result['errors']);
        $this->assertCount(3, $result['errors']);
    }

    /**
     * Test buildValidationError with empty errors array
     */
    public function testBuildValidationErrorWithEmptyErrorsArray(): void
    {
        $result = UtilPayload::buildValidationError([]);

        $this->assertIsArray($result['errors']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test buildValidationError with nested error structure
     */
    public function testBuildValidationErrorWithNestedErrorStructure(): void
    {
        $errors = [
            'address' => [
                'street' => 'Street is required',
                'city' => 'City must not be empty'
            ],
            'phone' => 'Invalid phone number'
        ];

        $result = UtilPayload::buildValidationError($errors);

        $this->assertEquals($errors, $result['errors']);
        $this->assertIsArray($result['errors']['address']);
    }

    /**
     * Test buildValidationError includes timestamp
     */
    public function testBuildValidationErrorIncludesTimestamp(): void
    {
        $beforeTime = time();
        $result = UtilPayload::buildValidationError([]);
        $afterTime = time();

        $this->assertGreaterThanOrEqual($beforeTime, $result['timestamp']);
        $this->assertLessThanOrEqual($afterTime, $result['timestamp']);
    }

    // =========================================================================
    // Tests for buildTimeout() static method
    // =========================================================================

    /**
     * Test buildTimeout returns array with correct structure
     */
    public function testBuildTimeoutReturnsArrayWithCorrectStructure(): void
    {
        $result = UtilPayload::buildTimeout('database_query', 30);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('operation', $result);
        $this->assertArrayHasKey('timeout', $result);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Test buildTimeout returns error status
     */
    public function testBuildTimeoutReturnsErrorStatus(): void
    {
        $result = UtilPayload::buildTimeout('test_operation', 60);

        $this->assertEquals('error', $result['status']);
    }

    /**
     * Test buildTimeout uses timeout error code
     */
    public function testBuildTimeoutUsesTimeoutErrorCode(): void
    {
        $result = UtilPayload::buildTimeout('test_operation', 60);

        $this->assertEquals(ErrorCodes::TIMEOUT, $result['code']);
    }

    /**
     * Test buildTimeout message includes operation and timeout
     */
    public function testBuildTimeoutMessageIncludesOperationAndTimeout(): void
    {
        $operation = 'external_api_call';
        $timeoutSeconds = 45;

        $result = UtilPayload::buildTimeout($operation, $timeoutSeconds);

        $this->assertStringContainsString($operation, $result['message']);
        $this->assertStringContainsString((string)$timeoutSeconds, $result['message']);
        $this->assertStringContainsString('timed out', $result['message']);
    }

    /**
     * Test buildTimeout includes operation name
     */
    public function testBuildTimeoutIncludesOperationName(): void
    {
        $operation = 'sync_contacts';
        $result = UtilPayload::buildTimeout($operation, 30);

        $this->assertEquals($operation, $result['operation']);
    }

    /**
     * Test buildTimeout includes timeout value
     */
    public function testBuildTimeoutIncludesTimeoutValue(): void
    {
        $timeoutSeconds = 120;
        $result = UtilPayload::buildTimeout('test', $timeoutSeconds);

        $this->assertEquals($timeoutSeconds, $result['timeout']);
    }

    /**
     * Test buildTimeout includes timestamp
     */
    public function testBuildTimeoutIncludesTimestamp(): void
    {
        $beforeTime = time();
        $result = UtilPayload::buildTimeout('test', 30);
        $afterTime = time();

        $this->assertGreaterThanOrEqual($beforeTime, $result['timestamp']);
        $this->assertLessThanOrEqual($afterTime, $result['timestamp']);
    }

    /**
     * Test buildTimeout with zero timeout
     */
    public function testBuildTimeoutWithZeroTimeout(): void
    {
        $result = UtilPayload::buildTimeout('instant_operation', 0);

        $this->assertEquals(0, $result['timeout']);
        $this->assertStringContainsString('0 seconds', $result['message']);
    }

    // =========================================================================
    // Tests for buildRateLimitExceeded() static method
    // =========================================================================

    /**
     * Test buildRateLimitExceeded returns array with correct structure
     */
    public function testBuildRateLimitExceededReturnsArrayWithCorrectStructure(): void
    {
        $result = UtilPayload::buildRateLimitExceeded(100, 60);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('window', $result);
        $this->assertArrayHasKey('retry_after', $result);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Test buildRateLimitExceeded returns error status
     */
    public function testBuildRateLimitExceededReturnsErrorStatus(): void
    {
        $result = UtilPayload::buildRateLimitExceeded(100, 60);

        $this->assertEquals('error', $result['status']);
    }

    /**
     * Test buildRateLimitExceeded uses rate limit error code
     */
    public function testBuildRateLimitExceededUsesRateLimitErrorCode(): void
    {
        $result = UtilPayload::buildRateLimitExceeded(100, 60);

        $this->assertEquals(ErrorCodes::RATE_LIMIT_EXCEEDED, $result['code']);
    }

    /**
     * Test buildRateLimitExceeded message includes limit and window
     */
    public function testBuildRateLimitExceededMessageIncludesLimitAndWindow(): void
    {
        $limit = 50;
        $window = 300;

        $result = UtilPayload::buildRateLimitExceeded($limit, $window);

        $this->assertStringContainsString((string)$limit, $result['message']);
        $this->assertStringContainsString((string)$window, $result['message']);
        $this->assertStringContainsString('requests per', $result['message']);
        $this->assertStringContainsString('exceeded', $result['message']);
    }

    /**
     * Test buildRateLimitExceeded includes limit and window values
     */
    public function testBuildRateLimitExceededIncludesLimitAndWindowValues(): void
    {
        $limit = 200;
        $window = 3600;

        $result = UtilPayload::buildRateLimitExceeded($limit, $window);

        $this->assertEquals($limit, $result['limit']);
        $this->assertEquals($window, $result['window']);
    }

    /**
     * Test buildRateLimitExceeded without retry_after
     */
    public function testBuildRateLimitExceededWithoutRetryAfter(): void
    {
        $result = UtilPayload::buildRateLimitExceeded(100, 60);

        $this->assertNull($result['retry_after']);
    }

    /**
     * Test buildRateLimitExceeded with retry_after
     */
    public function testBuildRateLimitExceededWithRetryAfter(): void
    {
        $retryAfter = 45;
        $result = UtilPayload::buildRateLimitExceeded(100, 60, $retryAfter);

        $this->assertEquals($retryAfter, $result['retry_after']);
    }

    /**
     * Test buildRateLimitExceeded includes timestamp
     */
    public function testBuildRateLimitExceededIncludesTimestamp(): void
    {
        $beforeTime = time();
        $result = UtilPayload::buildRateLimitExceeded(100, 60);
        $afterTime = time();

        $this->assertGreaterThanOrEqual($beforeTime, $result['timestamp']);
        $this->assertLessThanOrEqual($afterTime, $result['timestamp']);
    }

    /**
     * Test buildRateLimitExceeded with zero retry_after
     */
    public function testBuildRateLimitExceededWithZeroRetryAfter(): void
    {
        $result = UtilPayload::buildRateLimitExceeded(100, 60, 0);

        $this->assertEquals(0, $result['retry_after']);
    }

    // =========================================================================
    // Tests for buildMaintenanceMode() static method
    // =========================================================================

    /**
     * Test buildMaintenanceMode returns array with correct structure
     */
    public function testBuildMaintenanceModeReturnsArrayWithCorrectStructure(): void
    {
        $result = UtilPayload::buildMaintenanceMode();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('estimated_end_time', $result);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Test buildMaintenanceMode returns maintenance status
     */
    public function testBuildMaintenanceModeReturnsMaintenanceStatus(): void
    {
        $result = UtilPayload::buildMaintenanceMode();

        $this->assertEquals('maintenance', $result['status']);
    }

    /**
     * Test buildMaintenanceMode uses default message
     */
    public function testBuildMaintenanceModeUsesDefaultMessage(): void
    {
        $result = UtilPayload::buildMaintenanceMode();

        $this->assertEquals('System is currently under maintenance', $result['message']);
    }

    /**
     * Test buildMaintenanceMode with custom message
     */
    public function testBuildMaintenanceModeWithCustomMessage(): void
    {
        $customMessage = 'Scheduled maintenance in progress. Please try again later.';
        $result = UtilPayload::buildMaintenanceMode(null, $customMessage);

        $this->assertEquals($customMessage, $result['message']);
    }

    /**
     * Test buildMaintenanceMode without estimated end time
     */
    public function testBuildMaintenanceModeWithoutEstimatedEndTime(): void
    {
        $result = UtilPayload::buildMaintenanceMode();

        $this->assertNull($result['estimated_end_time']);
    }

    /**
     * Test buildMaintenanceMode with estimated end time
     */
    public function testBuildMaintenanceModeWithEstimatedEndTime(): void
    {
        $estimatedEndTime = '2026-02-02 18:00:00 UTC';
        $result = UtilPayload::buildMaintenanceMode($estimatedEndTime);

        $this->assertEquals($estimatedEndTime, $result['estimated_end_time']);
    }

    /**
     * Test buildMaintenanceMode with both parameters
     */
    public function testBuildMaintenanceModeWithBothParameters(): void
    {
        $estimatedEndTime = '2026-02-02 20:00:00 UTC';
        $customMessage = 'Database migration in progress';

        $result = UtilPayload::buildMaintenanceMode($estimatedEndTime, $customMessage);

        $this->assertEquals($estimatedEndTime, $result['estimated_end_time']);
        $this->assertEquals($customMessage, $result['message']);
    }

    /**
     * Test buildMaintenanceMode includes timestamp
     */
    public function testBuildMaintenanceModeIncludesTimestamp(): void
    {
        $beforeTime = time();
        $result = UtilPayload::buildMaintenanceMode();
        $afterTime = time();

        $this->assertGreaterThanOrEqual($beforeTime, $result['timestamp']);
        $this->assertLessThanOrEqual($afterTime, $result['timestamp']);
    }

    /**
     * Test buildMaintenanceMode with empty string message uses default
     */
    public function testBuildMaintenanceModeWithEmptyStringMessageUsesDefault(): void
    {
        // Empty string is falsy but not null, so it won't use default
        $result = UtilPayload::buildMaintenanceMode(null, '');

        // Empty string evaluates to false in ?? operator, so default is used
        $this->assertEquals('System is currently under maintenance', $result['message']);
    }

    // =========================================================================
    // Integration and Edge Case Tests
    // =========================================================================

    /**
     * Test all static methods work without instance
     */
    public function testAllStaticMethodsWorkWithoutInstance(): void
    {
        // These should not throw any exceptions
        $result1 = UtilPayload::buildInvalidTransactionId(['txid' => 'test'], ['previousTxid' => 'other']);
        $this->assertIsString($result1);

        $result2 = UtilPayload::buildInvalidRequestLevel(['requestLevel' => 1]);
        $this->assertIsString($result2);

        $result3 = UtilPayload::buildError('test');
        $this->assertIsArray($result3);

        $result4 = UtilPayload::buildValidationError([]);
        $this->assertIsArray($result4);

        $result5 = UtilPayload::buildTimeout('test', 30);
        $this->assertIsArray($result5);

        $result6 = UtilPayload::buildRateLimitExceeded(100, 60);
        $this->assertIsArray($result6);

        $result7 = UtilPayload::buildMaintenanceMode();
        $this->assertIsArray($result7);
    }

    /**
     * Test JSON methods return valid JSON
     */
    public function testJsonMethodsReturnValidJson(): void
    {
        // buildInsufficientBalance
        $json1 = $this->payload->buildInsufficientBalance(1000, 2000, 500, 100);
        $this->assertJson($json1);
        $this->assertNotNull(json_decode($json1, true));

        // buildInvalidTransactionId
        $json2 = UtilPayload::buildInvalidTransactionId(['txid' => 'test'], []);
        $this->assertJson($json2);
        $this->assertNotNull(json_decode($json2, true));

        // buildInvalidRequestLevel
        $json3 = UtilPayload::buildInvalidRequestLevel([]);
        $this->assertJson($json3);
        $this->assertNotNull(json_decode($json3, true));

        // buildInvalidSource
        $json4 = $this->payload->buildInvalidSource(['senderAddress' => 'test']);
        $this->assertJson($json4);
        $this->assertNotNull(json_decode($json4, true));
    }

    /**
     * Test array methods do not return JSON
     */
    public function testArrayMethodsDoNotReturnJson(): void
    {
        $result1 = UtilPayload::buildError('test');
        $this->assertIsArray($result1);
        $this->assertIsNotString($result1);

        $result2 = UtilPayload::buildValidationError([]);
        $this->assertIsArray($result2);
        $this->assertIsNotString($result2);

        $result3 = UtilPayload::buildTimeout('test', 30);
        $this->assertIsArray($result3);
        $this->assertIsNotString($result3);

        $result4 = UtilPayload::buildRateLimitExceeded(100, 60);
        $this->assertIsArray($result4);
        $this->assertIsNotString($result4);

        $result5 = UtilPayload::buildMaintenanceMode();
        $this->assertIsArray($result5);
        $this->assertIsNotString($result5);
    }

    /**
     * Test build method returns empty array as documented
     */
    public function testBuildMethodReturnsEmptyArrayAsDocumented(): void
    {
        // The build method is documented to return empty array
        // as Util payloads use specific methods instead
        $result = $this->payload->build(['any' => 'data']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
        $this->assertSame([], $result);
    }

    /**
     * Test all error codes used are valid ErrorCodes constants
     */
    public function testAllErrorCodesUsedAreValidConstants(): void
    {
        // Verify the error codes used in UtilPayload exist
        $this->assertTrue(defined(ErrorCodes::class . '::GENERAL_ERROR'));
        $this->assertTrue(defined(ErrorCodes::class . '::VALIDATION_ERROR'));
        $this->assertTrue(defined(ErrorCodes::class . '::TIMEOUT'));
        $this->assertTrue(defined(ErrorCodes::class . '::RATE_LIMIT_EXCEEDED'));
    }

    /**
     * Test all status values used are valid Constants values
     */
    public function testAllStatusValuesUsedAreValidConstants(): void
    {
        // Verify the status values used in UtilPayload exist
        $this->assertTrue(defined(Constants::class . '::STATUS_REJECTED'));
    }
}
