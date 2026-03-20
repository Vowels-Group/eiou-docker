<?php
/**
 * Unit Tests for Rp2pPayload
 *
 * Tests Return Peer-to-Peer (RP2P) payload building functionality including:
 * - Main RP2P payload building
 * - Database format payload building
 * - Acceptance status payloads
 * - Rejection status payloads with reason codes
 * - Forwarded status payloads
 * - Inserted status payloads
 */

namespace Eiou\Tests\Schemas\Payloads;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Schemas\Payloads\Rp2pPayload;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\ValidationUtilityService;

#[CoversClass(Rp2pPayload::class)]
class Rp2pPayloadTest extends TestCase
{
    private Rp2pPayload $payload;
    private UserContext $mockUserContext;
    private UtilityServiceContainer $mockUtilityContainer;
    private TransportUtilityService $mockTransportUtility;
    private CurrencyUtilityService $mockCurrencyUtility;
    private TimeUtilityService $mockTimeUtility;
    private ValidationUtilityService $mockValidationUtility;

    private const TEST_PUBLIC_KEY = 'test-public-key-abc123def456789012345678901234567890';
    private const TEST_SENDER_ADDRESS = 'http://192.168.1.100:8080';
    private const TEST_RESOLVED_ADDRESS = 'http://192.168.1.50:8080';
    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_SIGNATURE = 'test-signature-xyz789abc123def456789012345678901234567890abcdef';
    private const TEST_TIME = 1704067200;
    private const TEST_AMOUNT = 100.50;
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

        $this->mockTransportUtility->method('resolveUserAddressForTransport')
            ->willReturnCallback(function ($address) {
                return self::TEST_RESOLVED_ADDRESS;
            });

        $this->payload = new Rp2pPayload(
            $this->mockUserContext,
            $this->mockUtilityContainer
        );
    }

    /**
     * Helper method to create standard RP2P request data
     */
    private function createStandardRequestData(): array
    {
        return [
            'hash' => self::TEST_HASH,
            'time' => self::TEST_TIME,
            'amount' => self::TEST_AMOUNT,
            'currency' => self::TEST_CURRENCY,
            'signature' => self::TEST_SIGNATURE,
            'senderAddress' => self::TEST_SENDER_ADDRESS,
        ];
    }

    /**
     * Helper method to create database format RP2P data
     */
    private function createDatabaseFormatData(): array
    {
        return [
            'hash' => self::TEST_HASH,
            'time' => self::TEST_TIME,
            'amount' => self::TEST_AMOUNT,
            'currency' => self::TEST_CURRENCY,
            'signature' => self::TEST_SIGNATURE,
            'sender_address' => self::TEST_SENDER_ADDRESS,
        ];
    }

    // ========================================
    // build() Tests
    // ========================================

    /**
     * Test build creates proper payload structure
     */
    public function testBuildCreatesProperPayloadStructure(): void
    {
        $data = $this->createStandardRequestData();
        $result = $this->payload->build($data);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('time', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertArrayHasKey('signature', $result);
        $this->assertArrayHasKey('senderAddress', $result);
        $this->assertArrayHasKey('senderPublicKey', $result);
    }

    /**
     * Test build sets correct type value
     */
    public function testBuildSetsCorrectTypeValue(): void
    {
        $data = $this->createStandardRequestData();
        $result = $this->payload->build($data);

        $this->assertEquals('rp2p', $result['type']);
    }

    /**
     * Test build includes all transaction details
     */
    public function testBuildIncludesAllTransactionDetails(): void
    {
        $data = $this->createStandardRequestData();
        $result = $this->payload->build($data);

        $this->assertEquals(self::TEST_HASH, $result['hash']);
        $this->assertEquals(self::TEST_TIME, $result['time']);
        // Amount is now serialized to split format {whole, frac}
        // 100.50 (float) -> fromMinorUnits((int)100) -> whole=0, frac=100
        $this->assertIsArray($result['amount']);
        $this->assertArrayHasKey('whole', $result['amount']);
        $this->assertArrayHasKey('frac', $result['amount']);
        $this->assertEquals(self::TEST_CURRENCY, $result['currency']);
        $this->assertEquals(self::TEST_SIGNATURE, $result['signature']);
    }

    /**
     * Test build resolves sender address via transport utility
     */
    public function testBuildResolvesSenderAddressViaTransportUtility(): void
    {
        $this->mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_SENDER_ADDRESS)
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        $data = $this->createStandardRequestData();
        $result = $this->payload->build($data);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
    }

    /**
     * Test build includes sender public key from user context
     */
    public function testBuildIncludesSenderPublicKeyFromUserContext(): void
    {
        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_PUBLIC_KEY);

        $data = $this->createStandardRequestData();
        $result = $this->payload->build($data);

        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
    }

    /**
     * Test build payload has exactly 8 keys
     */
    public function testBuildPayloadHasExactlyEightKeys(): void
    {
        $data = $this->createStandardRequestData();
        $result = $this->payload->build($data);

        $this->assertCount(8, $result);
    }

    // ========================================
    // buildFromDatabase() Tests
    // ========================================

    /**
     * Test buildFromDatabase creates proper payload structure
     */
    public function testBuildFromDatabaseCreatesProperPayloadStructure(): void
    {
        $data = $this->createDatabaseFormatData();
        $result = $this->payload->buildFromDatabase($data);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('time', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertArrayHasKey('signature', $result);
        $this->assertArrayHasKey('senderAddress', $result);
        $this->assertArrayHasKey('senderPublicKey', $result);
    }

    /**
     * Test buildFromDatabase uses snake_case sender_address key
     */
    public function testBuildFromDatabaseUsesSnakeCaseSenderAddressKey(): void
    {
        $this->mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_SENDER_ADDRESS)
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        $data = $this->createDatabaseFormatData();
        $result = $this->payload->buildFromDatabase($data);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
    }

    /**
     * Test buildFromDatabase sets correct type value
     */
    public function testBuildFromDatabaseSetsCorrectTypeValue(): void
    {
        $data = $this->createDatabaseFormatData();
        $result = $this->payload->buildFromDatabase($data);

        $this->assertEquals('rp2p', $result['type']);
    }

    /**
     * Test buildFromDatabase includes all transaction details
     */
    public function testBuildFromDatabaseIncludesAllTransactionDetails(): void
    {
        $data = $this->createDatabaseFormatData();
        $result = $this->payload->buildFromDatabase($data);

        $this->assertEquals(self::TEST_HASH, $result['hash']);
        $this->assertEquals(self::TEST_TIME, $result['time']);
        // Amount is now serialized to split format {whole, frac}
        $this->assertIsArray($result['amount']);
        $this->assertArrayHasKey('whole', $result['amount']);
        $this->assertArrayHasKey('frac', $result['amount']);
        $this->assertEquals(self::TEST_CURRENCY, $result['currency']);
        $this->assertEquals(self::TEST_SIGNATURE, $result['signature']);
    }

    /**
     * Test buildFromDatabase includes sender public key
     */
    public function testBuildFromDatabaseIncludesSenderPublicKey(): void
    {
        $data = $this->createDatabaseFormatData();
        $result = $this->payload->buildFromDatabase($data);

        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
    }

    // ========================================
    // buildAcceptance() Tests
    // ========================================

    /**
     * Test buildAcceptance returns JSON string
     */
    public function testBuildAcceptanceReturnsJsonString(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildAcceptance($request);

        $this->assertIsString($result);
        $this->assertJson($result);
    }

    /**
     * Test buildAcceptance returns status RECEIVED
     */
    public function testBuildAcceptanceReturnsStatusReceived(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(Constants::DELIVERY_RECEIVED, $decoded['status']);
    }

    /**
     * Test buildAcceptance includes hash in message
     */
    public function testBuildAcceptanceIncludesHashInMessage(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertStringContainsString(self::TEST_HASH, $decoded['message']);
        $this->assertStringContainsString('RP2P received by', $decoded['message']);
    }

    /**
     * Test buildAcceptance includes resolved sender address
     */
    public function testBuildAcceptanceIncludesResolvedSenderAddress(): void
    {
        $this->mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_SENDER_ADDRESS)
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        $request = $this->createStandardRequestData();
        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
    }

    /**
     * Test buildAcceptance includes sender public key
     */
    public function testBuildAcceptanceIncludesSenderPublicKey(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    /**
     * Test buildAcceptance payload contains required fields
     */
    public function testBuildAcceptancePayloadContainsRequiredFields(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        $requiredFields = ['status', 'message', 'senderAddress', 'senderPublicKey'];
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $decoded, "buildAcceptance missing field: $field");
        }
    }

    // ========================================
    // buildRejection() Tests
    // ========================================

    /**
     * Test buildRejection returns JSON string
     */
    public function testBuildRejectionReturnsJsonString(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildRejection($request);

        $this->assertIsString($result);
        $this->assertJson($result);
    }

    /**
     * Test buildRejection returns status REJECTED
     */
    public function testBuildRejectionReturnsStatusRejected(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildRejection($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
    }

    /**
     * Test buildRejection uses default reason of duplicate
     */
    public function testBuildRejectionUsesDefaultReasonOfDuplicate(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildRejection($request);
        $decoded = json_decode($result, true);

        $this->assertEquals('duplicate', $decoded['reason']);
    }

    /**
     * Test buildRejection uses custom reason code
     */
    public function testBuildRejectionUsesCustomReasonCode(): void
    {
        $request = $this->createStandardRequestData();
        $customReason = 'insufficient_funds';
        $result = $this->payload->buildRejection($request, $customReason);
        $decoded = json_decode($result, true);

        $this->assertEquals($customReason, $decoded['reason']);
    }

    /**
     * Test buildRejection message for duplicate reason
     */
    public function testBuildRejectionMessageForDuplicateReason(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildRejection($request, 'duplicate');
        $decoded = json_decode($result, true);

        $this->assertStringContainsString(self::TEST_HASH, $decoded['message']);
        $this->assertStringContainsString('already exists in database', $decoded['message']);
    }

    /**
     * Test buildRejection message for unknown reason uses default format
     */
    public function testBuildRejectionMessageForUnknownReasonUsesDefaultFormat(): void
    {
        $request = $this->createStandardRequestData();
        $unknownReason = 'custom_error_code';
        $result = $this->payload->buildRejection($request, $unknownReason);
        $decoded = json_decode($result, true);

        $this->assertStringContainsString(self::TEST_HASH, $decoded['message']);
        $this->assertStringContainsString('rejected by', $decoded['message']);
        $this->assertStringContainsString($unknownReason, $decoded['message']);
    }

    /**
     * Test buildRejection includes resolved sender address
     */
    public function testBuildRejectionIncludesResolvedSenderAddress(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildRejection($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
    }

    /**
     * Test buildRejection includes sender public key
     */
    public function testBuildRejectionIncludesSenderPublicKey(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildRejection($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    /**
     * Test buildRejection payload contains required fields
     */
    public function testBuildRejectionPayloadContainsRequiredFields(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildRejection($request);
        $decoded = json_decode($result, true);

        $requiredFields = ['status', 'reason', 'message', 'senderAddress', 'senderPublicKey'];
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $decoded, "buildRejection missing field: $field");
        }
    }

    /**
     * Test buildRejection with various reason codes
     */
    public function testBuildRejectionWithVariousReasonCodes(): void
    {
        $request = $this->createStandardRequestData();
        $reasonCodes = ['duplicate', 'insufficient_funds', 'contact_blocked', 'invalid_signature', 'expired'];

        foreach ($reasonCodes as $reason) {
            $result = $this->payload->buildRejection($request, $reason);
            $decoded = json_decode($result, true);

            $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
            $this->assertEquals($reason, $decoded['reason']);
            $this->assertNotEmpty($decoded['message']);
        }
    }

    // ========================================
    // buildForwarded() Tests
    // ========================================

    /**
     * Test buildForwarded returns JSON string
     */
    public function testBuildForwardedReturnsJsonString(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildForwarded($request);

        $this->assertIsString($result);
        $this->assertJson($result);
    }

    /**
     * Test buildForwarded returns status forwarded
     */
    public function testBuildForwardedReturnsStatusForwarded(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildForwarded($request);
        $decoded = json_decode($result, true);

        $this->assertEquals('forwarded', $decoded['status']);
    }

    /**
     * Test buildForwarded includes hash in message
     */
    public function testBuildForwardedIncludesHashInMessage(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildForwarded($request);
        $decoded = json_decode($result, true);

        $this->assertStringContainsString(self::TEST_HASH, $decoded['message']);
        $this->assertStringContainsString('RP2P forwarded by', $decoded['message']);
    }

    /**
     * Test buildForwarded without next hop
     */
    public function testBuildForwardedWithoutNextHop(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildForwarded($request);
        $decoded = json_decode($result, true);

        $this->assertStringNotContainsString('next hop', $decoded['message']);
    }

    /**
     * Test buildForwarded with next hop
     */
    public function testBuildForwardedWithNextHop(): void
    {
        $request = $this->createStandardRequestData();
        $nextHop = 'http://192.168.1.200:8080';
        $result = $this->payload->buildForwarded($request, $nextHop);
        $decoded = json_decode($result, true);

        $this->assertStringContainsString('to next hop', $decoded['message']);
    }

    /**
     * Test buildForwarded includes resolved sender address
     */
    public function testBuildForwardedIncludesResolvedSenderAddress(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildForwarded($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
    }

    /**
     * Test buildForwarded includes sender public key
     */
    public function testBuildForwardedIncludesSenderPublicKey(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildForwarded($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    /**
     * Test buildForwarded payload contains required fields
     */
    public function testBuildForwardedPayloadContainsRequiredFields(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildForwarded($request);
        $decoded = json_decode($result, true);

        $requiredFields = ['status', 'message', 'senderAddress', 'senderPublicKey'];
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $decoded, "buildForwarded missing field: $field");
        }
    }

    // ========================================
    // buildInserted() Tests
    // ========================================

    /**
     * Test buildInserted returns JSON string
     */
    public function testBuildInsertedReturnsJsonString(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildInserted($request);

        $this->assertIsString($result);
        $this->assertJson($result);
    }

    /**
     * Test buildInserted returns status inserted
     */
    public function testBuildInsertedReturnsStatusInserted(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildInserted($request);
        $decoded = json_decode($result, true);

        $this->assertEquals('inserted', $decoded['status']);
    }

    /**
     * Test buildInserted includes hash in message
     */
    public function testBuildInsertedIncludesHashInMessage(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildInserted($request);
        $decoded = json_decode($result, true);

        $this->assertStringContainsString(self::TEST_HASH, $decoded['message']);
        $this->assertStringContainsString('stored in database', $decoded['message']);
    }

    /**
     * Test buildInserted includes resolved sender address
     */
    public function testBuildInsertedIncludesResolvedSenderAddress(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildInserted($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
    }

    /**
     * Test buildInserted includes sender public key
     */
    public function testBuildInsertedIncludesSenderPublicKey(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildInserted($request);
        $decoded = json_decode($result, true);

        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    /**
     * Test buildInserted payload contains required fields
     */
    public function testBuildInsertedPayloadContainsRequiredFields(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildInserted($request);
        $decoded = json_decode($result, true);

        $requiredFields = ['status', 'message', 'senderAddress', 'senderPublicKey'];
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $decoded, "buildInserted missing field: $field");
        }
    }

    // ========================================
    // buildCancelled() Tests
    // ========================================

    /**
     * Test buildCancelled creates proper payload structure
     */
    public function testBuildCancelledCreatesProperPayloadStructure(): void
    {
        $this->mockTimeUtility->method('getCurrentMicrotime')
            ->willReturn(1704067200);

        $result = $this->payload->buildCancelled(self::TEST_HASH, self::TEST_SENDER_ADDRESS);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('cancelled', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('time', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertArrayHasKey('senderAddress', $result);
        $this->assertArrayHasKey('senderPublicKey', $result);
    }

    /**
     * Test buildCancelled sets correct values
     */
    public function testBuildCancelledSetsCorrectValues(): void
    {
        $this->mockTimeUtility->method('getCurrentMicrotime')
            ->willReturn(1704067200);

        $result = $this->payload->buildCancelled(self::TEST_HASH, self::TEST_SENDER_ADDRESS);

        $this->assertEquals('rp2p', $result['type']);
        $this->assertEquals(self::TEST_HASH, $result['hash']);
        $this->assertTrue($result['cancelled']);
        $this->assertEquals(['whole' => 0, 'frac' => 0], $result['amount']);
        $this->assertEquals(1704067200, $result['time']);
        $this->assertEquals(Constants::TRANSACTION_DEFAULT_CURRENCY, $result['currency']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
    }

    /**
     * Test buildCancelled resolves sender address via transport utility
     */
    public function testBuildCancelledResolvesSenderAddressViaTransportUtility(): void
    {
        $this->mockTimeUtility->method('getCurrentMicrotime')
            ->willReturn(1704067200);

        $this->mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_SENDER_ADDRESS)
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        $result = $this->payload->buildCancelled(self::TEST_HASH, self::TEST_SENDER_ADDRESS);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
    }

    /**
     * Test buildCancelled has exactly 8 keys
     */
    public function testBuildCancelledHasExactlyEightKeys(): void
    {
        $this->mockTimeUtility->method('getCurrentMicrotime')
            ->willReturn(1704067200);

        $result = $this->payload->buildCancelled(self::TEST_HASH, self::TEST_SENDER_ADDRESS);

        $this->assertCount(8, $result);
    }

    // ========================================
    // Cross-method Tests
    // ========================================

    /**
     * Test all methods use resolveUserAddressForTransport correctly
     */
    public function testAllMethodsUseResolveUserAddressForTransportCorrectly(): void
    {
        $this->mockTimeUtility->method('getCurrentMicrotime')
            ->willReturn(1704067200);

        $this->mockTransportUtility->expects($this->exactly(7))
            ->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        $requestData = $this->createStandardRequestData();
        $databaseData = $this->createDatabaseFormatData();

        // Each method should call resolveUserAddressForTransport
        $this->payload->build($requestData);
        $this->payload->buildFromDatabase($databaseData);
        $this->payload->buildAcceptance($requestData);
        $this->payload->buildRejection($requestData);
        $this->payload->buildForwarded($requestData);
        $this->payload->buildInserted($requestData);
        $this->payload->buildCancelled(self::TEST_HASH, self::TEST_SENDER_ADDRESS);
    }

    /**
     * Test all methods include sender public key from user context
     */
    public function testAllMethodsIncludeSenderPublicKeyFromUserContext(): void
    {
        $this->mockTimeUtility->method('getCurrentMicrotime')
            ->willReturn(1704067200);

        $requestData = $this->createStandardRequestData();
        $databaseData = $this->createDatabaseFormatData();

        // build()
        $result = $this->payload->build($requestData);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);

        // buildFromDatabase()
        $result = $this->payload->buildFromDatabase($databaseData);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);

        // buildAcceptance()
        $decoded = json_decode($this->payload->buildAcceptance($requestData), true);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);

        // buildRejection()
        $decoded = json_decode($this->payload->buildRejection($requestData), true);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);

        // buildForwarded()
        $decoded = json_decode($this->payload->buildForwarded($requestData), true);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);

        // buildInserted()
        $decoded = json_decode($this->payload->buildInserted($requestData), true);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);

        // buildCancelled()
        $result = $this->payload->buildCancelled(self::TEST_HASH, self::TEST_SENDER_ADDRESS);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
    }

    /**
     * Test all JSON-returning methods produce valid JSON
     */
    public function testAllJsonReturningMethodsProduceValidJson(): void
    {
        $request = $this->createStandardRequestData();

        // buildAcceptance
        $result = $this->payload->buildAcceptance($request);
        $this->assertJson($result);
        $this->assertNotNull(json_decode($result, true));

        // buildRejection
        $result = $this->payload->buildRejection($request);
        $this->assertJson($result);
        $this->assertNotNull(json_decode($result, true));

        // buildForwarded
        $result = $this->payload->buildForwarded($request);
        $this->assertJson($result);
        $this->assertNotNull(json_decode($result, true));

        // buildInserted
        $result = $this->payload->buildInserted($request);
        $this->assertJson($result);
        $this->assertNotNull(json_decode($result, true));
    }

    /**
     * Test build and buildFromDatabase produce identical output for same data
     */
    public function testBuildAndBuildFromDatabaseProduceIdenticalOutputForSameData(): void
    {
        $camelCaseData = [
            'hash' => self::TEST_HASH,
            'time' => self::TEST_TIME,
            'amount' => self::TEST_AMOUNT,
            'currency' => self::TEST_CURRENCY,
            'signature' => self::TEST_SIGNATURE,
            'senderAddress' => self::TEST_SENDER_ADDRESS,
        ];

        $snakeCaseData = [
            'hash' => self::TEST_HASH,
            'time' => self::TEST_TIME,
            'amount' => self::TEST_AMOUNT,
            'currency' => self::TEST_CURRENCY,
            'signature' => self::TEST_SIGNATURE,
            'sender_address' => self::TEST_SENDER_ADDRESS,
        ];

        $result1 = $this->payload->build($camelCaseData);
        $result2 = $this->payload->buildFromDatabase($snakeCaseData);

        $this->assertEquals($result1, $result2);
    }

    // ========================================
    // Edge Case Tests
    // ========================================

    /**
     * Test build with numeric string amount falls through to zero in split format
     *
     * String amounts are not recognized by serializeAmount (only SplitAmount,
     * array, int, or float), so they produce a zero split amount.
     */
    public function testBuildWithNumericStringAmount(): void
    {
        $data = $this->createStandardRequestData();
        $data['amount'] = '150.75';
        $result = $this->payload->build($data);

        // String values are not handled as numeric by serializeAmount
        $this->assertEquals(['whole' => 0, 'frac' => 0], $result['amount']);
    }

    /**
     * Test build with integer amount serializes to split format
     */
    public function testBuildWithIntegerAmount(): void
    {
        $data = $this->createStandardRequestData();
        $data['amount'] = new \Eiou\Core\SplitAmount(100, 0);
        $result = $this->payload->build($data);

        $this->assertEquals(['whole' => 100, 'frac' => 0], $result['amount']);
    }

    /**
     * Test build with zero amount
     */
    public function testBuildWithZeroAmount(): void
    {
        $data = $this->createStandardRequestData();
        $data['amount'] = 0;
        $result = $this->payload->build($data);

        $this->assertEquals(['whole' => 0, 'frac' => 0], $result['amount']);
    }

    /**
     * Test build with different currency codes
     */
    public function testBuildWithDifferentCurrencyCodes(): void
    {
        $currencies = ['USD', 'EUR', 'GBP', 'JPY', 'BTC'];

        foreach ($currencies as $currency) {
            $data = $this->createStandardRequestData();
            $data['currency'] = $currency;
            $result = $this->payload->build($data);

            $this->assertEquals($currency, $result['currency']);
        }
    }

    /**
     * Test buildRejection message contains receiver address
     */
    public function testBuildRejectionMessageContainsReceiverAddress(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildRejection($request, 'duplicate');
        $decoded = json_decode($result, true);

        $this->assertStringContainsString(self::TEST_RESOLVED_ADDRESS, $decoded['message']);
    }

    /**
     * Test buildForwarded message format without next hop
     */
    public function testBuildForwardedMessageFormatWithoutNextHop(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildForwarded($request, null);
        $decoded = json_decode($result, true);

        $expectedMessage = 'hash ' . self::TEST_HASH . ' for RP2P forwarded by ' . self::TEST_RESOLVED_ADDRESS;
        $this->assertEquals($expectedMessage, $decoded['message']);
    }

    /**
     * Test buildForwarded message format with next hop
     */
    public function testBuildForwardedMessageFormatWithNextHop(): void
    {
        $request = $this->createStandardRequestData();
        $nextHop = 'http://next.hop.address:8080';
        $result = $this->payload->buildForwarded($request, $nextHop);
        $decoded = json_decode($result, true);

        $expectedMessage = 'hash ' . self::TEST_HASH . ' for RP2P forwarded by ' . self::TEST_RESOLVED_ADDRESS . ' to next hop';
        $this->assertEquals($expectedMessage, $decoded['message']);
    }

    /**
     * Test buildInserted message format
     */
    public function testBuildInsertedMessageFormat(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildInserted($request);
        $decoded = json_decode($result, true);

        $expectedMessage = 'hash ' . self::TEST_HASH . ' for RP2P stored in database of ' . self::TEST_RESOLVED_ADDRESS;
        $this->assertEquals($expectedMessage, $decoded['message']);
    }

    /**
     * Test buildAcceptance message format
     */
    public function testBuildAcceptanceMessageFormat(): void
    {
        $request = $this->createStandardRequestData();
        $result = $this->payload->buildAcceptance($request);
        $decoded = json_decode($result, true);

        // The message uses print_r which adds extra formatting
        $this->assertStringContainsString('hash', $decoded['message']);
        $this->assertStringContainsString(self::TEST_HASH, $decoded['message']);
        $this->assertStringContainsString('RP2P received by', $decoded['message']);
    }
}
