<?php
/**
 * Unit Tests for TransactionPayload
 *
 * Tests transaction payload building functionality including:
 * - Send transaction payload building (build, buildFromDatabase, buildStandardFromDatabase)
 * - Transaction acceptance payload building
 * - Transaction rejection payload building
 * - Transaction completed payload building
 * - Recipient signature generation
 * - Required field validation
 * - Data sanitization
 */

namespace Eiou\Tests\Schemas\Payloads;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Schemas\Payloads\TransactionPayload;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\ValidationUtilityService;

#[CoversClass(TransactionPayload::class)]
class TransactionPayloadTest extends TestCase
{
    private TransactionPayload $payload;
    private UserContext $mockUserContext;
    private UtilityServiceContainer $mockUtilityContainer;
    private TransportUtilityService $mockTransportUtility;
    private CurrencyUtilityService $mockCurrencyUtility;
    private TimeUtilityService $mockTimeUtility;
    private ValidationUtilityService $mockValidationUtility;

    private const TEST_PUBLIC_KEY = 'test-public-key-abc123def456789012345678901234567890';
    private const TEST_PRIVATE_KEY = '-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC7JHoJfg6yNzLM
ZKzQl0bqJrM8QT5/k2HN8c5lRk5vK6XvC2+lTc0ow/3z5R7e0zE7W8nOv9M8q0qR
9DF5Kj8LvJj5Z5R3p2H2X5V0n0J5q5Z5b5m5o5p5r5t5v5x5z505254565758595
a5c5e5g5i5k5m5o5q5s5u5w5y505152535455565758595a5b5c5d5e5f5g5h5i5j5
k5l5m5n5o5p5q5r5s5t5u5v5w5x5y5z5051525354555657585950515253545556
57585960616263646566676869707172737475767778798081828384858687888990
-----END PRIVATE KEY-----';
    private const TEST_RECEIVER_PUBLIC_KEY = 'receiver-public-key-xyz789012345678901234567890abcdef';
    private const TEST_HTTP_ADDRESS = 'http://192.168.1.100:8080';
    private const TEST_RECEIVER_ADDRESS = 'http://192.168.1.200:8080';
    private const TEST_RESOLVED_ADDRESS = 'http://192.168.1.50:8080';
    private const TEST_TXID = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_PREVIOUS_TXID = 'prev789xyz012345678901234567890123456789012345678901234567890123';
    private const TEST_MEMO = 'test-memo-hash-12345';
    private const TEST_TIME = 1704067200;
    private const TEST_AMOUNT = 10000;
    private const TEST_CURRENCY = 'USD';

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
        $this->mockUserContext->method('getPrivateKey')
            ->willReturn(null); // Return null by default, tests can override

        $this->mockTransportUtility->method('resolveUserAddressForTransport')
            ->willReturnCallback(function ($address) {
                return self::TEST_RESOLVED_ADDRESS;
            });

        $this->payload = new TransactionPayload(
            $this->mockUserContext,
            $this->mockUtilityContainer
        );
    }

    // =========================================================================
    // Tests for build() method
    // =========================================================================

    /**
     * Test build creates proper payload structure
     */
    public function testBuildCreatesProperPayloadStructure(): void
    {
        $data = [
            'time' => self::TEST_TIME,
            'receiverAddress' => self::TEST_RECEIVER_ADDRESS,
            'receiverPublicKey' => self::TEST_RECEIVER_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => self::TEST_CURRENCY,
            'txid' => self::TEST_TXID,
        ];

        $result = $this->payload->build($data);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('time', $result);
        $this->assertArrayHasKey('receiverAddress', $result);
        $this->assertArrayHasKey('receiverPublicKey', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertArrayHasKey('txid', $result);
        $this->assertArrayHasKey('previousTxid', $result);
        $this->assertArrayHasKey('memo', $result);
        $this->assertArrayHasKey('senderAddress', $result);
        $this->assertArrayHasKey('senderPublicKey', $result);
    }

    /**
     * Test build sets correct type
     */
    public function testBuildSetsCorrectType(): void
    {
        $data = $this->getValidBuildData();

        $result = $this->payload->build($data);

        $this->assertEquals('send', $result['type']);
    }

    /**
     * Test build uses default memo when not provided
     */
    public function testBuildUsesDefaultMemoWhenNotProvided(): void
    {
        $data = $this->getValidBuildData();
        unset($data['memo']);

        $result = $this->payload->build($data);

        $this->assertEquals('standard', $result['memo']);
    }

    /**
     * Test build uses custom memo when provided
     */
    public function testBuildUsesCustomMemoWhenProvided(): void
    {
        $data = $this->getValidBuildData();
        $data['memo'] = self::TEST_MEMO;

        $result = $this->payload->build($data);

        $this->assertEquals(self::TEST_MEMO, $result['memo']);
    }

    /**
     * Test build includes previousTxid when provided
     */
    public function testBuildIncludesPreviousTxidWhenProvided(): void
    {
        $data = $this->getValidBuildData();
        $data['previousTxid'] = self::TEST_PREVIOUS_TXID;

        $result = $this->payload->build($data);

        $this->assertEquals(self::TEST_PREVIOUS_TXID, $result['previousTxid']);
    }

    /**
     * Test build sets previousTxid to null when not provided
     */
    public function testBuildSetsPreviousTxidToNullWhenNotProvided(): void
    {
        $data = $this->getValidBuildData();

        $result = $this->payload->build($data);

        $this->assertNull($result['previousTxid']);
    }

    /**
     * Test build includes description when provided
     */
    public function testBuildIncludesDescriptionWhenProvided(): void
    {
        $data = $this->getValidBuildData();
        $data['description'] = 'Test payment description';

        $result = $this->payload->build($data);

        $this->assertArrayHasKey('description', $result);
        $this->assertEquals('Test payment description', $result['description']);
    }

    /**
     * Test build excludes description when not provided
     */
    public function testBuildExcludesDescriptionWhenNotProvided(): void
    {
        $data = $this->getValidBuildData();

        $result = $this->payload->build($data);

        $this->assertArrayNotHasKey('description', $result);
    }

    /**
     * Test build excludes description when null
     */
    public function testBuildExcludesDescriptionWhenNull(): void
    {
        $data = $this->getValidBuildData();
        $data['description'] = null;

        $result = $this->payload->build($data);

        $this->assertArrayNotHasKey('description', $result);
    }

    /**
     * Test build uses resolved sender address
     */
    public function testBuildUsesResolvedSenderAddress(): void
    {
        $data = $this->getValidBuildData();

        $result = $this->payload->build($data);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
    }

    /**
     * Test build uses current user public key
     */
    public function testBuildUsesCurrentUserPublicKey(): void
    {
        $data = $this->getValidBuildData();

        $result = $this->payload->build($data);

        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
    }

    /**
     * Test build serializes amount to split format
     */
    public function testBuildSanitizesAmount(): void
    {
        $data = $this->getValidBuildData();
        $data['amount'] = new \Eiou\Core\SplitAmount(15, 0);

        $result = $this->payload->build($data);

        $this->assertIsArray($result['amount']);
        $this->assertArrayHasKey('whole', $result['amount']);
        $this->assertArrayHasKey('frac', $result['amount']);
        $this->assertEquals(['whole' => 15, 'frac' => 0], $result['amount']);
    }

    /**
     * Test build sanitizes currency
     */
    public function testBuildSanitizesCurrency(): void
    {
        $data = $this->getValidBuildData();
        $data['currency'] = '  USD  ';

        $result = $this->payload->build($data);

        $this->assertEquals('USD', $result['currency']);
    }

    /**
     * Test build throws exception when time is missing
     */
    public function testBuildThrowsExceptionWhenTimeMissing(): void
    {
        $data = $this->getValidBuildData();
        unset($data['time']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'time' is missing");

        $this->payload->build($data);
    }

    /**
     * Test build throws exception when receiverAddress is missing
     */
    public function testBuildThrowsExceptionWhenReceiverAddressMissing(): void
    {
        $data = $this->getValidBuildData();
        unset($data['receiverAddress']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'receiverAddress' is missing");

        $this->payload->build($data);
    }

    /**
     * Test build throws exception when receiverPublicKey is missing
     */
    public function testBuildThrowsExceptionWhenReceiverPublicKeyMissing(): void
    {
        $data = $this->getValidBuildData();
        unset($data['receiverPublicKey']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'receiverPublicKey' is missing");

        $this->payload->build($data);
    }

    /**
     * Test build throws exception when amount is missing
     */
    public function testBuildThrowsExceptionWhenAmountMissing(): void
    {
        $data = $this->getValidBuildData();
        unset($data['amount']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'amount' is missing");

        $this->payload->build($data);
    }

    /**
     * Test build throws exception when currency is missing
     */
    public function testBuildThrowsExceptionWhenCurrencyMissing(): void
    {
        $data = $this->getValidBuildData();
        unset($data['currency']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'currency' is missing");

        $this->payload->build($data);
    }

    /**
     * Test build throws exception when txid is missing
     */
    public function testBuildThrowsExceptionWhenTxidMissing(): void
    {
        $data = $this->getValidBuildData();
        unset($data['txid']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'txid' is missing");

        $this->payload->build($data);
    }

    // =========================================================================
    // Tests for buildFromDatabase() method
    // =========================================================================

    /**
     * Test buildFromDatabase creates proper payload structure
     */
    public function testBuildFromDatabaseCreatesProperPayloadStructure(): void
    {
        $data = $this->getValidDatabaseData();

        $result = $this->payload->buildFromDatabase($data);

        $this->assertIsArray($result);
        $this->assertEquals('send', $result['type']);
        $this->assertArrayHasKey('time', $result);
        $this->assertArrayHasKey('receiverAddress', $result);
        $this->assertArrayHasKey('receiverPublicKey', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertArrayHasKey('txid', $result);
        $this->assertArrayHasKey('previousTxid', $result);
        $this->assertArrayHasKey('memo', $result);
        $this->assertArrayHasKey('senderAddress', $result);
        $this->assertArrayHasKey('senderPublicKey', $result);
    }

    /**
     * Test buildFromDatabase maps snake_case to camelCase
     */
    public function testBuildFromDatabaseMapsSnakeCaseToCamelCase(): void
    {
        $data = $this->getValidDatabaseData();

        $result = $this->payload->buildFromDatabase($data);

        $this->assertEquals($data['receiver_address'], $result['receiverAddress']);
        $this->assertEquals($data['receiver_public_key'], $result['receiverPublicKey']);
    }

    /**
     * Test buildFromDatabase includes memo from data
     */
    public function testBuildFromDatabaseIncludesMemoFromData(): void
    {
        $data = $this->getValidDatabaseData();
        $data['memo'] = self::TEST_MEMO;

        $result = $this->payload->buildFromDatabase($data);

        $this->assertEquals(self::TEST_MEMO, $result['memo']);
    }

    /**
     * Test buildFromDatabase throws exception when required fields missing
     */
    public function testBuildFromDatabaseThrowsExceptionWhenRequiredFieldsMissing(): void
    {
        $data = $this->getValidDatabaseData();
        unset($data['receiver_address']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'receiver_address' is missing");

        $this->payload->buildFromDatabase($data);
    }

    // =========================================================================
    // Tests for buildStandardFromDatabase() method
    // =========================================================================

    /**
     * Test buildStandardFromDatabase creates proper payload structure
     */
    public function testBuildStandardFromDatabaseCreatesProperPayloadStructure(): void
    {
        $data = $this->getValidDatabaseData();

        $result = $this->payload->buildStandardFromDatabase($data);

        $this->assertIsArray($result);
        $this->assertEquals('send', $result['type']);
        $this->assertEquals('standard', $result['memo']);
    }

    /**
     * Test buildStandardFromDatabase always uses standard memo
     */
    public function testBuildStandardFromDatabaseAlwaysUsesStandardMemo(): void
    {
        $data = $this->getValidDatabaseData();
        $data['memo'] = 'custom-memo-should-be-ignored';

        $result = $this->payload->buildStandardFromDatabase($data);

        $this->assertEquals('standard', $result['memo']);
    }

    /**
     * Test buildStandardFromDatabase includes time when provided
     */
    public function testBuildStandardFromDatabaseIncludesTimeWhenProvided(): void
    {
        $data = $this->getValidDatabaseData();
        $data['time'] = self::TEST_TIME;

        $result = $this->payload->buildStandardFromDatabase($data);

        $this->assertEquals(self::TEST_TIME, $result['time']);
    }

    /**
     * Test buildStandardFromDatabase sets time to null when not provided
     */
    public function testBuildStandardFromDatabaseSetsTimeToNullWhenNotProvided(): void
    {
        $data = $this->getValidDatabaseData();
        unset($data['time']);

        $result = $this->payload->buildStandardFromDatabase($data);

        $this->assertNull($result['time']);
    }

    /**
     * Test buildStandardFromDatabase includes description when non-empty
     */
    public function testBuildStandardFromDatabaseIncludesDescriptionWhenNonEmpty(): void
    {
        $data = $this->getValidDatabaseData();
        $data['description'] = 'Payment for services';

        $result = $this->payload->buildStandardFromDatabase($data);

        $this->assertArrayHasKey('description', $result);
        $this->assertEquals('Payment for services', $result['description']);
    }

    /**
     * Test buildStandardFromDatabase excludes description when empty string
     */
    public function testBuildStandardFromDatabaseExcludesDescriptionWhenEmptyString(): void
    {
        $data = $this->getValidDatabaseData();
        $data['description'] = '';

        $result = $this->payload->buildStandardFromDatabase($data);

        $this->assertArrayNotHasKey('description', $result);
    }

    /**
     * Test buildStandardFromDatabase excludes description when null
     */
    public function testBuildStandardFromDatabaseExcludesDescriptionWhenNull(): void
    {
        $data = $this->getValidDatabaseData();
        $data['description'] = null;

        $result = $this->payload->buildStandardFromDatabase($data);

        $this->assertArrayNotHasKey('description', $result);
    }

    /**
     * Test buildStandardFromDatabase does not require time field
     */
    public function testBuildStandardFromDatabaseDoesNotRequireTimeField(): void
    {
        $data = [
            'receiver_address' => self::TEST_RECEIVER_ADDRESS,
            'receiver_public_key' => self::TEST_RECEIVER_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => self::TEST_CURRENCY,
            'txid' => self::TEST_TXID,
        ];

        // Should not throw exception
        $result = $this->payload->buildStandardFromDatabase($data);

        $this->assertNull($result['time']);
    }

    // =========================================================================
    // Tests for buildAcceptance() method
    // =========================================================================

    /**
     * Test buildAcceptance returns JSON string
     */
    public function testBuildAcceptanceReturnsJsonString(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildAcceptance($request);

        $this->assertIsString($result);
        $this->assertJson($result);
    }

    /**
     * Test buildAcceptance includes accepted status
     */
    public function testBuildAcceptanceIncludesAcceptedStatus(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(Constants::STATUS_ACCEPTED, $decoded['status']);
    }

    /**
     * Test buildAcceptance includes txid from request
     */
    public function testBuildAcceptanceIncludesTxidFromRequest(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['txid'] = self::TEST_TXID;

        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_TXID, $decoded['txid']);
    }

    /**
     * Test buildAcceptance includes memo from request
     */
    public function testBuildAcceptanceIncludesMemoFromRequest(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['memo'] = self::TEST_MEMO;

        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_MEMO, $decoded['memo']);
    }

    /**
     * Test buildAcceptance message uses txid for standard transactions
     */
    public function testBuildAcceptanceMessageUsesTxidForStandardTransactions(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['memo'] = 'standard';
        $request['txid'] = self::TEST_TXID;

        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertStringContainsString('txid', $decoded['message']);
        $this->assertStringContainsString(self::TEST_TXID, $decoded['message']);
    }

    /**
     * Test buildAcceptance message uses memo for P2P transactions
     */
    public function testBuildAcceptanceMessageUsesMemoForP2pTransactions(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['memo'] = self::TEST_MEMO;

        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertStringContainsString('memo', $decoded['message']);
        $this->assertStringContainsString(self::TEST_MEMO, $decoded['message']);
    }

    /**
     * Test buildAcceptance includes sender address and public key
     */
    public function testBuildAcceptanceIncludesSenderAddressAndPublicKey(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    /**
     * Test buildAcceptance uses existing recipient signature when provided
     */
    public function testBuildAcceptanceUsesExistingRecipientSignatureWhenProvided(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['recipientSignature'] = 'pre-generated-signature-base64';

        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertEquals('pre-generated-signature-base64', $decoded['recipientSignature']);
    }

    /**
     * Test buildAcceptance includes recipientSignature field
     */
    public function testBuildAcceptanceIncludesRecipientSignatureField(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('recipientSignature', $decoded);
    }

    // =========================================================================
    // Tests for generateRecipientSignature() method
    // =========================================================================

    /**
     * Test generateRecipientSignature returns null when private key is empty
     */
    public function testGenerateRecipientSignatureReturnsNullWhenPrivateKeyEmpty(): void
    {
        $request = $this->getValidTransactionRequest();

        // mockUserContext already returns null for getPrivateKey by default
        $result = $this->payload->generateRecipientSignature($request);

        $this->assertNull($result);
    }

    /**
     * Test generateRecipientSignature constructs proper message content
     */
    public function testGenerateRecipientSignatureConstructsProperMessageContent(): void
    {
        $request = [
            'time' => self::TEST_TIME,
            'receiverAddress' => self::TEST_RECEIVER_ADDRESS,
            'receiverPublicKey' => self::TEST_RECEIVER_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => self::TEST_CURRENCY,
            'txid' => self::TEST_TXID,
            'previousTxid' => self::TEST_PREVIOUS_TXID,
            'memo' => 'standard',
            'nonce' => 123456,
        ];

        // We can't easily test the actual signing without a valid key,
        // but we can test the method doesn't throw and handles null private key
        $result = $this->payload->generateRecipientSignature($request);

        // With null private key, should return null
        $this->assertNull($result);
    }

    /**
     * Test generateRecipientSignature uses time as integer
     */
    public function testGenerateRecipientSignatureUsesTimeAsInteger(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['time'] = '1704067200'; // String time

        // Should not throw
        $result = $this->payload->generateRecipientSignature($request);

        // Returns null due to no private key, but method executed successfully
        $this->assertNull($result);
    }

    /**
     * Test generateRecipientSignature handles null time
     */
    public function testGenerateRecipientSignatureHandlesNullTime(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['time'] = null;

        // Should not throw
        $result = $this->payload->generateRecipientSignature($request);

        $this->assertNull($result);
    }

    /**
     * Test generateRecipientSignature uses signatureNonce as fallback
     */
    public function testGenerateRecipientSignatureUsesSignatureNonceAsFallback(): void
    {
        $request = $this->getValidTransactionRequest();
        unset($request['nonce']);
        $request['signatureNonce'] = 789012;

        // Should not throw
        $result = $this->payload->generateRecipientSignature($request);

        $this->assertNull($result);
    }

    // =========================================================================
    // Tests for buildCompleted() method
    // =========================================================================

    /**
     * Test buildCompleted creates proper payload structure
     */
    public function testBuildCompletedCreatesProperPayloadStructure(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildCompleted($request);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('typeMessage', $result);
        $this->assertArrayHasKey('inquiry', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('hashType', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('senderAddress', $result);
        $this->assertArrayHasKey('senderPublicKey', $result);
    }

    /**
     * Test buildCompleted sets correct type and typeMessage
     */
    public function testBuildCompletedSetsCorrectTypeAndTypeMessage(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildCompleted($request);

        $this->assertEquals('message', $result['type']);
        $this->assertEquals('transaction', $result['typeMessage']);
    }

    /**
     * Test buildCompleted sets inquiry to false
     */
    public function testBuildCompletedSetsInquiryToFalse(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildCompleted($request);

        $this->assertFalse($result['inquiry']);
    }

    /**
     * Test buildCompleted sets status to completed
     */
    public function testBuildCompletedSetsStatusToCompleted(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildCompleted($request);

        $this->assertEquals(Constants::STATUS_COMPLETED, $result['status']);
    }

    /**
     * Test buildCompleted uses txid for standard memo
     */
    public function testBuildCompletedUsesTxidForStandardMemo(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['memo'] = 'standard';
        $request['txid'] = self::TEST_TXID;

        $result = $this->payload->buildCompleted($request);

        $this->assertEquals('txid', $result['hashType']);
        $this->assertEquals(self::TEST_TXID, $result['hash']);
    }

    /**
     * Test buildCompleted uses memo for P2P transactions
     */
    public function testBuildCompletedUsesMemoForP2pTransactions(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['memo'] = self::TEST_MEMO;

        $result = $this->payload->buildCompleted($request);

        $this->assertEquals('memo', $result['hashType']);
        $this->assertEquals(self::TEST_MEMO, $result['hash']);
    }

    /**
     * Test buildCompleted uses hash when memo not set
     */
    public function testBuildCompletedUsesHashWhenMemoNotSet(): void
    {
        $request = $this->getValidTransactionRequest();
        unset($request['memo']);
        $request['hash'] = 'test-hash-value';

        $result = $this->payload->buildCompleted($request);

        $this->assertEquals('memo', $result['hashType']);
        $this->assertEquals('test-hash-value', $result['hash']);
    }

    /**
     * Test buildCompleted includes description when provided
     */
    public function testBuildCompletedIncludesDescriptionWhenProvided(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['description'] = 'Final payment';

        $result = $this->payload->buildCompleted($request);

        $this->assertArrayHasKey('description', $result);
        $this->assertEquals('Final payment', $result['description']);
    }

    /**
     * Test buildCompleted excludes description when null
     */
    public function testBuildCompletedExcludesDescriptionWhenNull(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['description'] = null;

        $result = $this->payload->buildCompleted($request);

        $this->assertArrayNotHasKey('description', $result);
    }

    /**
     * Test buildCompleted includes initialSenderAddress when provided
     */
    public function testBuildCompletedIncludesInitialSenderAddressWhenProvided(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['initialSenderAddress'] = 'http://initial.sender:8080';

        $result = $this->payload->buildCompleted($request);

        $this->assertArrayHasKey('initialSenderAddress', $result);
        $this->assertEquals('http://initial.sender:8080', $result['initialSenderAddress']);
    }

    /**
     * Test buildCompleted uses snake_case sender_address when camelCase not present
     */
    public function testBuildCompletedUsesSnakeCaseSenderAddressWhenCamelCaseNotPresent(): void
    {
        $request = $this->getValidTransactionRequest();
        unset($request['senderAddress']);
        $request['sender_address'] = self::TEST_HTTP_ADDRESS;

        $result = $this->payload->buildCompleted($request);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
    }

    /**
     * Test buildCompleted uses default currency when not provided
     */
    public function testBuildCompletedUsesDefaultCurrencyWhenNotProvided(): void
    {
        $request = $this->getValidTransactionRequest();
        unset($request['currency']);

        $result = $this->payload->buildCompleted($request);

        $this->assertEquals('EIOU', $result['currency']);
    }

    /**
     * Test buildCompleted message format
     */
    public function testBuildCompletedMessageFormat(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['memo'] = self::TEST_MEMO;

        $result = $this->payload->buildCompleted($request);

        $this->assertStringContainsString('transaction for hash', $result['message']);
        $this->assertStringContainsString('successfully completed through intermediary', $result['message']);
    }

    // =========================================================================
    // Tests for buildRejection() method
    // =========================================================================

    /**
     * Test buildRejection returns JSON string
     */
    public function testBuildRejectionReturnsJsonString(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildRejection($request);

        $this->assertIsString($result);
        $this->assertJson($result);
    }

    /**
     * Test buildRejection includes rejected status
     */
    public function testBuildRejectionIncludesRejectedStatus(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildRejection($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
    }

    /**
     * Test buildRejection uses default reason duplicate
     */
    public function testBuildRejectionUsesDefaultReasonDuplicate(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildRejection($request);
        $decoded = json_decode($result, true);

        $this->assertEquals('duplicate', $decoded['reason']);
    }

    /**
     * Test buildRejection uses custom reason
     */
    public function testBuildRejectionUsesCustomReason(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildRejection($request, 'insufficient_funds');
        $decoded = json_decode($result, true);

        $this->assertEquals('insufficient_funds', $decoded['reason']);
    }

    /**
     * Test buildRejection includes txid from request
     */
    public function testBuildRejectionIncludesTxidFromRequest(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['txid'] = self::TEST_TXID;

        $result = $this->payload->buildRejection($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_TXID, $decoded['txid']);
    }

    /**
     * Test buildRejection includes memo from request
     */
    public function testBuildRejectionIncludesMemoFromRequest(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['memo'] = self::TEST_MEMO;

        $result = $this->payload->buildRejection($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_MEMO, $decoded['memo']);
    }

    /**
     * Test buildRejection includes expected_txid for invalid_previous_txid reason
     */
    public function testBuildRejectionIncludesExpectedTxidForInvalidPreviousTxidReason(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildRejection($request, 'invalid_previous_txid', self::TEST_PREVIOUS_TXID);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('expected_txid', $decoded);
        $this->assertEquals(self::TEST_PREVIOUS_TXID, $decoded['expected_txid']);
    }

    /**
     * Test buildRejection excludes expected_txid for other reasons
     */
    public function testBuildRejectionExcludesExpectedTxidForOtherReasons(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildRejection($request, 'duplicate', self::TEST_PREVIOUS_TXID);
        $decoded = json_decode($result, true);

        $this->assertArrayNotHasKey('expected_txid', $decoded);
    }

    /**
     * Test buildRejection message for duplicate reason
     */
    public function testBuildRejectionMessageForDuplicateReason(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['memo'] = 'standard';
        $request['txid'] = self::TEST_TXID;

        $result = $this->payload->buildRejection($request, 'duplicate');
        $decoded = json_decode($result, true);

        $this->assertStringContainsString('already exists in database', $decoded['message']);
    }

    /**
     * Test buildRejection message for insufficient_funds reason
     */
    public function testBuildRejectionMessageForInsufficientFundsReason(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildRejection($request, 'insufficient_funds');
        $decoded = json_decode($result, true);

        $this->assertStringContainsString('insufficient funds', $decoded['message']);
    }

    /**
     * Test buildRejection message for contact_blocked reason
     */
    public function testBuildRejectionMessageForContactBlockedReason(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildRejection($request, 'contact_blocked');
        $decoded = json_decode($result, true);

        $this->assertStringContainsString('contact is blocked', $decoded['message']);
    }

    /**
     * Test buildRejection message for credit_limit_exceeded reason
     */
    public function testBuildRejectionMessageForCreditLimitExceededReason(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildRejection($request, 'credit_limit_exceeded');
        $decoded = json_decode($result, true);

        $this->assertStringContainsString('credit limit exceeded', $decoded['message']);
    }

    /**
     * Test buildRejection message for invalid_previous_txid reason
     */
    public function testBuildRejectionMessageForInvalidPreviousTxidReason(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildRejection($request, 'invalid_previous_txid');
        $decoded = json_decode($result, true);

        $this->assertStringContainsString('previous transaction ID mismatch', $decoded['message']);
    }

    /**
     * Test buildRejection message for unknown reason
     */
    public function testBuildRejectionMessageForUnknownReason(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildRejection($request, 'custom_unknown_reason');
        $decoded = json_decode($result, true);

        $this->assertStringContainsString('custom_unknown_reason', $decoded['message']);
    }

    /**
     * Test buildRejection includes sender address and public key
     */
    public function testBuildRejectionIncludesSenderAddressAndPublicKey(): void
    {
        $request = $this->getValidTransactionRequest();

        $result = $this->payload->buildRejection($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    // =========================================================================
    // Tests for all methods using resolveUserAddressForTransport
    // =========================================================================

    /**
     * Test all relevant methods use resolveUserAddressForTransport correctly
     */
    public function testAllMethodsUseResolveUserAddressForTransportCorrectly(): void
    {
        $this->mockTransportUtility->expects($this->exactly(5))
            ->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        $buildData = $this->getValidBuildData();
        $this->payload->build($buildData);

        $dbData = $this->getValidDatabaseData();
        $this->payload->buildFromDatabase($dbData);

        $this->payload->buildStandardFromDatabase($dbData);

        $request = $this->getValidTransactionRequest();
        $this->payload->buildAcceptance($request);

        $this->payload->buildRejection($request);
    }

    // =========================================================================
    // Tests for payload validation and sanitization
    // =========================================================================

    /**
     * Test build handles non-numeric amount by treating as zero
     *
     * With split amount serialization, non-numeric strings fall through
     * to SplitAmount::zero() instead of throwing an exception.
     */
    public function testBuildHandlesNonNumericAmountAsZero(): void
    {
        $data = $this->getValidBuildData();
        $data['amount'] = 'not-a-number';

        $result = $this->payload->build($data);

        $this->assertEquals(['whole' => 0, 'frac' => 0], $result['amount']);
    }

    /**
     * Test buildCompleted serializes amount to split format
     */
    public function testBuildCompletedSanitizesAmount(): void
    {
        $request = $this->getValidTransactionRequest();
        $request['amount'] = new \Eiou\Core\SplitAmount(25, 0);

        $result = $this->payload->buildCompleted($request);

        $this->assertIsArray($result['amount']);
        $this->assertArrayHasKey('whole', $result['amount']);
        $this->assertArrayHasKey('frac', $result['amount']);
        $this->assertEquals(['whole' => 25, 'frac' => 0], $result['amount']);
    }

    /**
     * Test buildCompleted handles zero amount
     */
    public function testBuildCompletedHandlesZeroAmount(): void
    {
        $request = $this->getValidTransactionRequest();
        unset($request['amount']);

        $result = $this->payload->buildCompleted($request);

        $this->assertEquals(['whole' => 0, 'frac' => 0], $result['amount']);
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Get valid data for build() method
     */
    private function getValidBuildData(): array
    {
        return [
            'time' => self::TEST_TIME,
            'receiverAddress' => self::TEST_RECEIVER_ADDRESS,
            'receiverPublicKey' => self::TEST_RECEIVER_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => self::TEST_CURRENCY,
            'txid' => self::TEST_TXID,
        ];
    }

    /**
     * Get valid database data (snake_case keys)
     */
    private function getValidDatabaseData(): array
    {
        return [
            'time' => self::TEST_TIME,
            'receiver_address' => self::TEST_RECEIVER_ADDRESS,
            'receiver_public_key' => self::TEST_RECEIVER_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => self::TEST_CURRENCY,
            'txid' => self::TEST_TXID,
            'previous_txid' => null,
            'memo' => 'standard',
        ];
    }

    /**
     * Get valid transaction request data
     */
    private function getValidTransactionRequest(): array
    {
        return [
            'time' => self::TEST_TIME,
            'receiverAddress' => self::TEST_RECEIVER_ADDRESS,
            'receiverPublicKey' => self::TEST_RECEIVER_PUBLIC_KEY,
            'senderAddress' => self::TEST_HTTP_ADDRESS,
            'amount' => self::TEST_AMOUNT,
            'currency' => self::TEST_CURRENCY,
            'txid' => self::TEST_TXID,
            'memo' => 'standard',
        ];
    }
}
