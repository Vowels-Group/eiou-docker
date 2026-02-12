<?php
/**
 * Unit Tests for P2pPayload
 *
 * Tests P2P (Peer-to-Peer) payload building functionality including:
 * - P2P request payload building (build method)
 * - P2P payload from database (buildFromDatabase)
 * - P2P acceptance payloads (buildAcceptance)
 * - P2P rejection payloads with various reason codes (buildRejection)
 * - P2P forwarded payloads (buildForwarded)
 * - P2P inserted payloads (buildInserted)
 * - Required field validation
 */

namespace Eiou\Tests\Schemas\Payloads;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Schemas\Payloads\P2pPayload;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\ValidationUtilityService;

#[CoversClass(P2pPayload::class)]
class P2pPayloadTest extends TestCase
{
    private P2pPayload $payload;
    private UserContext $mockUserContext;
    private UtilityServiceContainer $mockUtilityContainer;
    private TransportUtilityService $mockTransportUtility;
    private CurrencyUtilityService $mockCurrencyUtility;
    private TimeUtilityService $mockTimeUtility;
    private ValidationUtilityService $mockValidationUtility;

    private const TEST_PUBLIC_KEY = 'test-public-key-abc123def456789012345678901234567890';
    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_SALT = 'randomsalt12345';
    private const TEST_HTTP_ADDRESS = 'http://192.168.1.100:8080';
    private const TEST_RESOLVED_ADDRESS = 'http://192.168.1.50:8080';
    private const TEST_SENDER_ADDRESS = 'http://192.168.1.200:8080';
    private const TEST_TIME = 1700000000;
    private const TEST_CURRENCY = 'USD';
    private const TEST_AMOUNT = 100.50;
    private const TEST_MIN_REQUEST_LEVEL = 1;
    private const TEST_MAX_REQUEST_LEVEL = 6;
    private const TEST_EXPIRATION_TIME = 300;

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
        $this->mockUserContext->method('getP2pExpirationTime')
            ->willReturn(self::TEST_EXPIRATION_TIME);

        $this->mockTransportUtility->method('resolveUserAddressForTransport')
            ->willReturnCallback(function ($address) {
                return self::TEST_RESOLVED_ADDRESS;
            });

        $this->mockTimeUtility->method('convertMicrotimeToInt')
            ->willReturn(self::TEST_EXPIRATION_TIME);

        $this->payload = new P2pPayload(
            $this->mockUserContext,
            $this->mockUtilityContainer
        );
    }

    // ============================================================
    // Tests for build() method
    // ============================================================

    /**
     * Test build creates proper payload structure
     */
    public function testBuildCreatesProperPayloadStructure(): void
    {
        $data = $this->getValidBuildData();
        $result = $this->payload->build($data);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('salt', $result);
        $this->assertArrayHasKey('time', $result);
        $this->assertArrayHasKey('expiration', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('requestLevel', $result);
        $this->assertArrayHasKey('maxRequestLevel', $result);
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

        $this->assertEquals('p2p', $result['type']);
    }

    /**
     * Test build uses provided hash, salt, and time
     */
    public function testBuildUsesProvidedHashSaltAndTime(): void
    {
        $data = $this->getValidBuildData();
        $result = $this->payload->build($data);

        $this->assertEquals(self::TEST_HASH, $result['hash']);
        $this->assertEquals(self::TEST_SALT, $result['salt']);
        $this->assertEquals(self::TEST_TIME, $result['time']);
    }

    /**
     * Test build calculates expiration correctly
     */
    public function testBuildCalculatesExpirationCorrectly(): void
    {
        $data = $this->getValidBuildData();
        $result = $this->payload->build($data);

        $expectedExpiration = self::TEST_TIME + self::TEST_EXPIRATION_TIME;
        $this->assertEquals($expectedExpiration, $result['expiration']);
    }

    /**
     * Test build sanitizes currency and amount
     */
    public function testBuildSanitizesCurrencyAndAmount(): void
    {
        $data = $this->getValidBuildData();
        $data['currency'] = '  USD  '; // With whitespace
        $data['amount'] = '100.50'; // As string

        $result = $this->payload->build($data);

        $this->assertEquals('USD', $result['currency']);
        $this->assertEquals(100.50, $result['amount']);
    }

    /**
     * Test build sets request levels as integers
     */
    public function testBuildSetsRequestLevelsAsIntegers(): void
    {
        $data = $this->getValidBuildData();
        $data['minRequestLevel'] = '3'; // String
        $data['maxRequestLevel'] = '10'; // String

        $result = $this->payload->build($data);

        $this->assertIsInt($result['requestLevel']);
        $this->assertIsInt($result['maxRequestLevel']);
        $this->assertEquals(3, $result['requestLevel']);
        $this->assertEquals(10, $result['maxRequestLevel']);
    }

    /**
     * Test build resolves user address for transport
     */
    public function testBuildResolvesUserAddressForTransport(): void
    {
        $this->mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_HTTP_ADDRESS)
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        $data = $this->getValidBuildData();
        $result = $this->payload->build($data);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
    }

    /**
     * Test build sets sender public key
     */
    public function testBuildSetsSenderPublicKey(): void
    {
        $data = $this->getValidBuildData();
        $result = $this->payload->build($data);

        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
    }

    /**
     * Test build throws exception for missing required fields
     */
    public function testBuildThrowsExceptionForMissingRequiredFields(): void
    {
        $requiredFields = ['hash', 'salt', 'time', 'currency', 'amount', 'minRequestLevel', 'maxRequestLevel', 'receiverAddress'];

        foreach ($requiredFields as $field) {
            $data = $this->getValidBuildData();
            unset($data[$field]);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage("Required field '{$field}' is missing");
            $this->payload->build($data);
        }
    }

    /**
     * Test build throws exception when hash is missing
     */
    public function testBuildThrowsExceptionWhenHashIsMissing(): void
    {
        $data = $this->getValidBuildData();
        unset($data['hash']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'hash' is missing");
        $this->payload->build($data);
    }

    /**
     * Test build throws exception when receiverAddress is missing
     */
    public function testBuildThrowsExceptionWhenReceiverAddressIsMissing(): void
    {
        $data = $this->getValidBuildData();
        unset($data['receiverAddress']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'receiverAddress' is missing");
        $this->payload->build($data);
    }

    // ============================================================
    // Tests for buildFromDatabase() method
    // ============================================================

    /**
     * Test buildFromDatabase creates proper payload structure
     */
    public function testBuildFromDatabaseCreatesProperPayloadStructure(): void
    {
        $data = $this->getValidDatabaseData();
        $result = $this->payload->buildFromDatabase($data);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('salt', $result);
        $this->assertArrayHasKey('time', $result);
        $this->assertArrayHasKey('expiration', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('requestLevel', $result);
        $this->assertArrayHasKey('maxRequestLevel', $result);
        $this->assertArrayHasKey('senderAddress', $result);
        $this->assertArrayHasKey('senderPublicKey', $result);
    }

    /**
     * Test buildFromDatabase increments request level for forwarding
     */
    public function testBuildFromDatabaseIncrementsRequestLevelForForwarding(): void
    {
        $data = $this->getValidDatabaseData();
        $data['request_level'] = 3;

        $result = $this->payload->buildFromDatabase($data);

        $this->assertEquals(4, $result['requestLevel']); // Incremented by 1
    }

    /**
     * Test buildFromDatabase uses expiration from database
     */
    public function testBuildFromDatabaseUsesExpirationFromDatabase(): void
    {
        $data = $this->getValidDatabaseData();
        $expectedExpiration = 1700000500;
        $data['expiration'] = $expectedExpiration;

        $result = $this->payload->buildFromDatabase($data);

        $this->assertEquals($expectedExpiration, $result['expiration']);
    }

    /**
     * Test buildFromDatabase uses snake_case keys
     */
    public function testBuildFromDatabaseUsesSnakeCaseKeys(): void
    {
        $data = $this->getValidDatabaseData();
        $result = $this->payload->buildFromDatabase($data);

        // These fields come from snake_case database columns
        $this->assertIsInt($result['requestLevel']); // from request_level
        $this->assertIsInt($result['maxRequestLevel']); // from max_request_level
        $this->assertIsString($result['senderAddress']); // from sender_address
    }

    /**
     * Test buildFromDatabase sanitizes currency and amount
     */
    public function testBuildFromDatabaseSanitizesCurrencyAndAmount(): void
    {
        $data = $this->getValidDatabaseData();
        $data['currency'] = '  EUR  ';
        $data['amount'] = '250.75';

        $result = $this->payload->buildFromDatabase($data);

        $this->assertEquals('EUR', $result['currency']);
        $this->assertEquals(250.75, $result['amount']);
    }

    /**
     * Test buildFromDatabase throws exception for missing required fields
     */
    public function testBuildFromDatabaseThrowsExceptionForMissingHash(): void
    {
        $data = $this->getValidDatabaseData();
        unset($data['hash']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'hash' is missing");
        $this->payload->buildFromDatabase($data);
    }

    /**
     * Test buildFromDatabase throws exception when request_level is missing
     */
    public function testBuildFromDatabaseThrowsExceptionWhenRequestLevelIsMissing(): void
    {
        $data = $this->getValidDatabaseData();
        unset($data['request_level']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'request_level' is missing");
        $this->payload->buildFromDatabase($data);
    }

    // ============================================================
    // Tests for buildAcceptance() method
    // ============================================================

    /**
     * Test buildAcceptance returns JSON string
     */
    public function testBuildAcceptanceReturnsJsonString(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildAcceptance($request);

        $this->assertIsString($result);
        $this->assertJson($result);
    }

    /**
     * Test buildAcceptance includes status RECEIVED
     */
    public function testBuildAcceptanceIncludesStatusReceived(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(Constants::DELIVERY_RECEIVED, $decoded['status']);
    }

    /**
     * Test buildAcceptance message includes hash
     */
    public function testBuildAcceptanceMessageIncludesHash(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertStringContainsString(self::TEST_HASH, $decoded['message']);
        $this->assertStringContainsString('P2P received', $decoded['message']);
    }

    /**
     * Test buildAcceptance includes sender address and public key
     */
    public function testBuildAcceptanceIncludesSenderAddressAndPublicKey(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('senderAddress', $decoded);
        $this->assertArrayHasKey('senderPublicKey', $decoded);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    /**
     * Test buildAcceptance throws exception when hash is missing
     */
    public function testBuildAcceptanceThrowsExceptionWhenHashIsMissing(): void
    {
        $request = $this->getValidRequestData();
        unset($request['hash']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'hash' is missing");
        $this->payload->buildAcceptance($request);
    }

    /**
     * Test buildAcceptance throws exception when senderAddress is missing
     */
    public function testBuildAcceptanceThrowsExceptionWhenSenderAddressIsMissing(): void
    {
        $request = $this->getValidRequestData();
        unset($request['senderAddress']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'senderAddress' is missing");
        $this->payload->buildAcceptance($request);
    }

    // ============================================================
    // Tests for buildRejection() method
    // ============================================================

    /**
     * Test buildRejection returns JSON string
     */
    public function testBuildRejectionReturnsJsonString(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildRejection($request);

        $this->assertIsString($result);
        $this->assertJson($result);
    }

    /**
     * Test buildRejection includes status REJECTED
     */
    public function testBuildRejectionIncludesStatusRejected(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildRejection($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
    }

    /**
     * Test buildRejection default reason is duplicate
     */
    public function testBuildRejectionDefaultReasonIsDuplicate(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildRejection($request);
        $decoded = json_decode($result, true);

        $this->assertEquals('duplicate', $decoded['reason']);
        $this->assertStringContainsString('already exists', $decoded['message']);
    }

    /**
     * Test buildRejection with insufficient_funds reason
     */
    public function testBuildRejectionWithInsufficientFundsReason(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildRejection($request, 'insufficient_funds');
        $decoded = json_decode($result, true);

        $this->assertEquals('insufficient_funds', $decoded['reason']);
        $this->assertStringContainsString('insufficient funds', $decoded['message']);
    }

    /**
     * Test buildRejection with contact_blocked reason
     */
    public function testBuildRejectionWithContactBlockedReason(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildRejection($request, 'contact_blocked');
        $decoded = json_decode($result, true);

        $this->assertEquals('contact_blocked', $decoded['reason']);
        $this->assertStringContainsString('contact is blocked', $decoded['message']);
    }

    /**
     * Test buildRejection with credit_limit_exceeded reason
     */
    public function testBuildRejectionWithCreditLimitExceededReason(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildRejection($request, 'credit_limit_exceeded');
        $decoded = json_decode($result, true);

        $this->assertEquals('credit_limit_exceeded', $decoded['reason']);
        $this->assertStringContainsString('credit limit exceeded', $decoded['message']);
    }

    /**
     * Test buildRejection with custom reason uses fallback message
     */
    public function testBuildRejectionWithCustomReasonUsesFallbackMessage(): void
    {
        $request = $this->getValidRequestData();
        $customReason = 'custom_error';
        $result = $this->payload->buildRejection($request, $customReason);
        $decoded = json_decode($result, true);

        $this->assertEquals($customReason, $decoded['reason']);
        $this->assertStringContainsString($customReason, $decoded['message']);
        $this->assertStringContainsString('rejected by', $decoded['message']);
    }

    /**
     * Test buildRejection includes sender address and public key
     */
    public function testBuildRejectionIncludesSenderAddressAndPublicKey(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildRejection($request);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('senderAddress', $decoded);
        $this->assertArrayHasKey('senderPublicKey', $decoded);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    /**
     * Test buildRejection throws exception when hash is missing
     */
    public function testBuildRejectionThrowsExceptionWhenHashIsMissing(): void
    {
        $request = $this->getValidRequestData();
        unset($request['hash']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'hash' is missing");
        $this->payload->buildRejection($request);
    }

    // ============================================================
    // Tests for buildForwarded() method
    // ============================================================

    /**
     * Test buildForwarded returns JSON string
     */
    public function testBuildForwardedReturnsJsonString(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildForwarded($request);

        $this->assertIsString($result);
        $this->assertJson($result);
    }

    /**
     * Test buildForwarded includes status forwarded
     */
    public function testBuildForwardedIncludesStatusForwarded(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildForwarded($request);
        $decoded = json_decode($result, true);

        $this->assertEquals('forwarded', $decoded['status']);
    }

    /**
     * Test buildForwarded message includes hash
     */
    public function testBuildForwardedMessageIncludesHash(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildForwarded($request);
        $decoded = json_decode($result, true);

        $this->assertStringContainsString(self::TEST_HASH, $decoded['message']);
        $this->assertStringContainsString('P2P forwarded', $decoded['message']);
    }

    /**
     * Test buildForwarded without nextHop
     */
    public function testBuildForwardedWithoutNextHop(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildForwarded($request);
        $decoded = json_decode($result, true);

        $this->assertStringNotContainsString('to next hop', $decoded['message']);
    }

    /**
     * Test buildForwarded with nextHop appends message
     */
    public function testBuildForwardedWithNextHopAppendsMessage(): void
    {
        $request = $this->getValidRequestData();
        $nextHop = 'http://192.168.1.150:8080';
        $result = $this->payload->buildForwarded($request, $nextHop);
        $decoded = json_decode($result, true);

        $this->assertStringContainsString('to next hop', $decoded['message']);
    }

    /**
     * Test buildForwarded includes sender address and public key
     */
    public function testBuildForwardedIncludesSenderAddressAndPublicKey(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildForwarded($request);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('senderAddress', $decoded);
        $this->assertArrayHasKey('senderPublicKey', $decoded);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    /**
     * Test buildForwarded throws exception when hash is missing
     */
    public function testBuildForwardedThrowsExceptionWhenHashIsMissing(): void
    {
        $request = $this->getValidRequestData();
        unset($request['hash']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'hash' is missing");
        $this->payload->buildForwarded($request);
    }

    // ============================================================
    // Tests for buildInserted() method
    // ============================================================

    /**
     * Test buildInserted returns JSON string
     */
    public function testBuildInsertedReturnsJsonString(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildInserted($request);

        $this->assertIsString($result);
        $this->assertJson($result);
    }

    /**
     * Test buildInserted includes status inserted
     */
    public function testBuildInsertedIncludesStatusInserted(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildInserted($request);
        $decoded = json_decode($result, true);

        $this->assertEquals('inserted', $decoded['status']);
    }

    /**
     * Test buildInserted message includes hash and database info
     */
    public function testBuildInsertedMessageIncludesHashAndDatabaseInfo(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildInserted($request);
        $decoded = json_decode($result, true);

        $this->assertStringContainsString(self::TEST_HASH, $decoded['message']);
        $this->assertStringContainsString('P2P stored in database', $decoded['message']);
    }

    /**
     * Test buildInserted includes sender address and public key
     */
    public function testBuildInsertedIncludesSenderAddressAndPublicKey(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildInserted($request);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('senderAddress', $decoded);
        $this->assertArrayHasKey('senderPublicKey', $decoded);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    /**
     * Test buildInserted throws exception when hash is missing
     */
    public function testBuildInsertedThrowsExceptionWhenHashIsMissing(): void
    {
        $request = $this->getValidRequestData();
        unset($request['hash']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'hash' is missing");
        $this->payload->buildInserted($request);
    }

    /**
     * Test buildInserted throws exception when senderAddress is missing
     */
    public function testBuildInsertedThrowsExceptionWhenSenderAddressIsMissing(): void
    {
        $request = $this->getValidRequestData();
        unset($request['senderAddress']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'senderAddress' is missing");
        $this->payload->buildInserted($request);
    }

    // ============================================================
    // Tests for all methods using resolveUserAddressForTransport
    // ============================================================

    /**
     * Test all methods use resolveUserAddressForTransport correctly
     */
    public function testAllMethodsUseResolveUserAddressForTransportCorrectly(): void
    {
        $this->mockTransportUtility->expects($this->exactly(6))
            ->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        // Each method should call resolveUserAddressForTransport
        $this->payload->build($this->getValidBuildData());
        $this->payload->buildFromDatabase($this->getValidDatabaseData());
        $this->payload->buildAcceptance($this->getValidRequestData());
        $this->payload->buildRejection($this->getValidRequestData());
        $this->payload->buildForwarded($this->getValidRequestData());
        $this->payload->buildInserted($this->getValidRequestData());
    }

    // ============================================================
    // Tests for payload JSON encoding validation
    // ============================================================

    /**
     * Test all JSON payloads are valid JSON
     */
    public function testAllJsonPayloadsAreValidJson(): void
    {
        $request = $this->getValidRequestData();

        $this->assertJson($this->payload->buildAcceptance($request));
        $this->assertJson($this->payload->buildRejection($request));
        $this->assertJson($this->payload->buildForwarded($request));
        $this->assertJson($this->payload->buildInserted($request));
    }

    /**
     * Test all status payloads include required fields
     */
    public function testAllStatusPayloadsIncludeRequiredFields(): void
    {
        $requiredFields = ['status', 'message', 'senderAddress', 'senderPublicKey'];
        $request = $this->getValidRequestData();

        // buildAcceptance
        $acceptance = json_decode($this->payload->buildAcceptance($request), true);
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $acceptance, "buildAcceptance missing field: $field");
        }

        // buildRejection
        $rejection = json_decode($this->payload->buildRejection($request), true);
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $rejection, "buildRejection missing field: $field");
        }

        // buildForwarded
        $forwarded = json_decode($this->payload->buildForwarded($request), true);
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $forwarded, "buildForwarded missing field: $field");
        }

        // buildInserted
        $inserted = json_decode($this->payload->buildInserted($request), true);
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $inserted, "buildInserted missing field: $field");
        }
    }

    /**
     * Test buildRejection includes reason field
     */
    public function testBuildRejectionIncludesReasonField(): void
    {
        $request = $this->getValidRequestData();
        $result = $this->payload->buildRejection($request);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('reason', $decoded);
    }

    // ============================================================
    // Tests for edge cases and boundary conditions
    // ============================================================

    /**
     * Test build with zero amount
     */
    public function testBuildWithZeroAmount(): void
    {
        $data = $this->getValidBuildData();
        $data['amount'] = 0;

        $result = $this->payload->build($data);

        $this->assertEquals(0, $result['amount']);
    }

    /**
     * Test build with large amount
     */
    public function testBuildWithLargeAmount(): void
    {
        $data = $this->getValidBuildData();
        $data['amount'] = 999999999.99;

        $result = $this->payload->build($data);

        $this->assertEquals(999999999.99, $result['amount']);
    }

    /**
     * Test build with minimum request level
     */
    public function testBuildWithMinimumRequestLevel(): void
    {
        $data = $this->getValidBuildData();
        $data['minRequestLevel'] = 0;

        $result = $this->payload->build($data);

        $this->assertEquals(0, $result['requestLevel']);
    }

    /**
     * Test build with maximum request level
     */
    public function testBuildWithMaximumRequestLevel(): void
    {
        $data = $this->getValidBuildData();
        $data['maxRequestLevel'] = 1000;

        $result = $this->payload->build($data);

        $this->assertEquals(1000, $result['maxRequestLevel']);
    }

    /**
     * Test buildFromDatabase request level increment at boundary
     */
    public function testBuildFromDatabaseRequestLevelIncrementAtBoundary(): void
    {
        $data = $this->getValidDatabaseData();
        $data['request_level'] = 0;

        $result = $this->payload->buildFromDatabase($data);

        $this->assertEquals(1, $result['requestLevel']);
    }

    // ============================================================
    // Tests for buildCancelled() method
    // ============================================================

    /**
     * Test buildCancelled returns array with correct type
     */
    public function testBuildCancelledReturnsArrayWithCorrectType(): void
    {
        $result = $this->payload->buildCancelled(self::TEST_HASH, self::TEST_HTTP_ADDRESS);

        $this->assertIsArray($result);
        $this->assertEquals('rp2p', $result['type']);
    }

    /**
     * Test buildCancelled includes cancelled flag set to true
     */
    public function testBuildCancelledIncludesCancelledFlag(): void
    {
        $result = $this->payload->buildCancelled(self::TEST_HASH, self::TEST_HTTP_ADDRESS);

        $this->assertArrayHasKey('cancelled', $result);
        $this->assertTrue($result['cancelled']);
    }

    /**
     * Test buildCancelled sets amount to zero
     */
    public function testBuildCancelledSetsAmountToZero(): void
    {
        $result = $this->payload->buildCancelled(self::TEST_HASH, self::TEST_HTTP_ADDRESS);

        $this->assertArrayHasKey('amount', $result);
        $this->assertEquals(0, $result['amount']);
    }

    /**
     * Test buildCancelled includes hash
     */
    public function testBuildCancelledIncludesHash(): void
    {
        $result = $this->payload->buildCancelled(self::TEST_HASH, self::TEST_HTTP_ADDRESS);

        $this->assertArrayHasKey('hash', $result);
        $this->assertEquals(self::TEST_HASH, $result['hash']);
    }

    /**
     * Test buildCancelled includes senderAddress resolved via transport utility
     */
    public function testBuildCancelledResolvesSenderAddress(): void
    {
        $this->mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_HTTP_ADDRESS)
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        $result = $this->payload->buildCancelled(self::TEST_HASH, self::TEST_HTTP_ADDRESS);

        $this->assertArrayHasKey('senderAddress', $result);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
    }

    /**
     * Test buildCancelled includes senderPublicKey from user context
     */
    public function testBuildCancelledIncludesSenderPublicKey(): void
    {
        $result = $this->payload->buildCancelled(self::TEST_HASH, self::TEST_HTTP_ADDRESS);

        $this->assertArrayHasKey('senderPublicKey', $result);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
    }

    /**
     * Test buildCancelled includes time from time utility
     */
    public function testBuildCancelledIncludesTime(): void
    {
        $this->mockTimeUtility->method('getCurrentMicrotime')
            ->willReturn(1700000000000000);

        $result = $this->payload->buildCancelled(self::TEST_HASH, self::TEST_HTTP_ADDRESS);

        $this->assertArrayHasKey('time', $result);
        $this->assertEquals(1700000000000000, $result['time']);
    }

    /**
     * Test buildCancelled includes currency from Constants default
     */
    public function testBuildCancelledIncludesCurrency(): void
    {
        $result = $this->payload->buildCancelled(self::TEST_HASH, self::TEST_HTTP_ADDRESS);

        $this->assertArrayHasKey('currency', $result);
        $this->assertEquals(Constants::TRANSACTION_DEFAULT_CURRENCY, $result['currency']);
    }

    /**
     * Test buildCancelled contains all required keys
     */
    public function testBuildCancelledContainsAllRequiredKeys(): void
    {
        $this->mockTimeUtility->method('getCurrentMicrotime')
            ->willReturn(1700000000000000);

        $result = $this->payload->buildCancelled(self::TEST_HASH, self::TEST_HTTP_ADDRESS);

        $expectedKeys = ['type', 'hash', 'cancelled', 'amount', 'time', 'currency', 'senderAddress', 'senderPublicKey'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "buildCancelled missing key: $key");
        }
        $this->assertCount(count($expectedKeys), $result, "buildCancelled should have exactly " . count($expectedKeys) . " keys");
    }

    // ============================================================
    // Helper methods for creating test data
    // ============================================================

    /**
     * Get valid build data for P2P payload
     *
     * @return array
     */
    private function getValidBuildData(): array
    {
        return [
            'hash' => self::TEST_HASH,
            'salt' => self::TEST_SALT,
            'time' => self::TEST_TIME,
            'currency' => self::TEST_CURRENCY,
            'amount' => self::TEST_AMOUNT,
            'minRequestLevel' => self::TEST_MIN_REQUEST_LEVEL,
            'maxRequestLevel' => self::TEST_MAX_REQUEST_LEVEL,
            'receiverAddress' => self::TEST_HTTP_ADDRESS,
        ];
    }

    /**
     * Get valid database data with snake_case keys
     *
     * @return array
     */
    private function getValidDatabaseData(): array
    {
        return [
            'hash' => self::TEST_HASH,
            'salt' => self::TEST_SALT,
            'time' => self::TEST_TIME,
            'expiration' => self::TEST_TIME + self::TEST_EXPIRATION_TIME,
            'currency' => self::TEST_CURRENCY,
            'amount' => self::TEST_AMOUNT,
            'request_level' => self::TEST_MIN_REQUEST_LEVEL,
            'max_request_level' => self::TEST_MAX_REQUEST_LEVEL,
            'sender_address' => self::TEST_SENDER_ADDRESS,
        ];
    }

    /**
     * Get valid request data for status payloads
     *
     * @return array
     */
    private function getValidRequestData(): array
    {
        return [
            'hash' => self::TEST_HASH,
            'senderAddress' => self::TEST_SENDER_ADDRESS,
        ];
    }
}
