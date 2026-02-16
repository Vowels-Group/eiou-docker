<?php
/**
 * Unit Tests for ContactStatusPayload
 *
 * Tests contact status payload building functionality including:
 * - Ping request payload building (build method)
 * - Pong response payload building (buildResponse method)
 * - Rejection payload building (buildRejection method)
 * - Required field validation
 * - Default value handling
 */

namespace Eiou\Tests\Schemas\Payloads;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Schemas\Payloads\ContactStatusPayload;
use Eiou\Core\UserContext;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\ValidationUtilityService;

#[CoversClass(ContactStatusPayload::class)]
class ContactStatusPayloadTest extends TestCase
{
    private ContactStatusPayload $payload;
    private UserContext $mockUserContext;
    private UtilityServiceContainer $mockUtilityContainer;
    private TransportUtilityService $mockTransportUtility;
    private CurrencyUtilityService $mockCurrencyUtility;
    private TimeUtilityService $mockTimeUtility;
    private ValidationUtilityService $mockValidationUtility;

    private const TEST_PUBLIC_KEY = 'test-public-key-abc123def456789012345678901234567890';
    private const TEST_HTTP_ADDRESS = 'http://192.168.1.100:8080';
    private const TEST_HTTPS_ADDRESS = 'https://192.168.1.100:8443';
    private const TEST_TOR_ADDRESS = 'http://testaddress1234567890abcdefghijklmnopqrstuvwxyz12345.onion';
    private const TEST_RESOLVED_ADDRESS = 'http://192.168.1.50:8080';
    private const TEST_TXID = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_MICROTIME = 1706889600123456;

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

        $this->mockTimeUtility->method('getCurrentMicrotime')
            ->willReturn(self::TEST_MICROTIME);

        $this->payload = new ContactStatusPayload(
            $this->mockUserContext,
            $this->mockUtilityContainer
        );
    }

    // =========================================================================
    // Tests for build() method - Ping Request
    // =========================================================================

    /**
     * Test build creates proper ping payload structure
     */
    public function testBuildCreatesProperPingPayloadStructure(): void
    {
        $result = $this->payload->build(['receiverAddress' => self::TEST_HTTP_ADDRESS]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('senderAddress', $result);
        $this->assertArrayHasKey('senderPublicKey', $result);
        $this->assertArrayHasKey('prevTxid', $result);
        $this->assertArrayHasKey('requestSync', $result);
        $this->assertArrayHasKey('time', $result);
    }

    /**
     * Test build returns correct type value
     */
    public function testBuildReturnsCorrectTypeValue(): void
    {
        $result = $this->payload->build(['receiverAddress' => self::TEST_HTTP_ADDRESS]);

        $this->assertEquals('ping', $result['type']);
    }

    /**
     * Test build uses resolved address for senderAddress
     */
    public function testBuildUsesResolvedAddressForSenderAddress(): void
    {
        $this->mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_HTTP_ADDRESS)
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        $result = $this->payload->build(['receiverAddress' => self::TEST_HTTP_ADDRESS]);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
    }

    /**
     * Test build includes senderPublicKey from UserContext
     */
    public function testBuildIncludesSenderPublicKeyFromUserContext(): void
    {
        $result = $this->payload->build(['receiverAddress' => self::TEST_HTTP_ADDRESS]);

        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
    }

    /**
     * Test build includes prevTxid when provided
     */
    public function testBuildIncludesPrevTxidWhenProvided(): void
    {
        $result = $this->payload->build([
            'receiverAddress' => self::TEST_HTTP_ADDRESS,
            'prevTxid' => self::TEST_TXID
        ]);

        $this->assertEquals(self::TEST_TXID, $result['prevTxid']);
    }

    /**
     * Test build sets prevTxid to null when not provided
     */
    public function testBuildSetsPrevTxidToNullWhenNotProvided(): void
    {
        $result = $this->payload->build(['receiverAddress' => self::TEST_HTTP_ADDRESS]);

        $this->assertNull($result['prevTxid']);
    }

    /**
     * Test build includes requestSync when provided as true
     */
    public function testBuildIncludesRequestSyncWhenProvidedAsTrue(): void
    {
        $result = $this->payload->build([
            'receiverAddress' => self::TEST_HTTP_ADDRESS,
            'requestSync' => true
        ]);

        $this->assertTrue($result['requestSync']);
    }

    /**
     * Test build includes requestSync when provided as false
     */
    public function testBuildIncludesRequestSyncWhenProvidedAsFalse(): void
    {
        $result = $this->payload->build([
            'receiverAddress' => self::TEST_HTTP_ADDRESS,
            'requestSync' => false
        ]);

        $this->assertFalse($result['requestSync']);
    }

    /**
     * Test build sets requestSync to false when not provided
     */
    public function testBuildSetsRequestSyncToFalseWhenNotProvided(): void
    {
        $result = $this->payload->build(['receiverAddress' => self::TEST_HTTP_ADDRESS]);

        $this->assertFalse($result['requestSync']);
    }

    /**
     * Test build includes time from TimeUtilityService
     */
    public function testBuildIncludesTimeFromTimeUtilityService(): void
    {
        $result = $this->payload->build(['receiverAddress' => self::TEST_HTTP_ADDRESS]);

        $this->assertEquals(self::TEST_MICROTIME, $result['time']);
    }

    /**
     * Test build throws exception when receiverAddress is missing
     */
    public function testBuildThrowsExceptionWhenReceiverAddressIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'receiverAddress' is missing from payload data");

        $this->payload->build([]);
    }

    /**
     * Test build with all optional parameters
     */
    public function testBuildWithAllOptionalParameters(): void
    {
        $result = $this->payload->build([
            'receiverAddress' => self::TEST_HTTP_ADDRESS,
            'prevTxid' => self::TEST_TXID,
            'requestSync' => true
        ]);

        $this->assertCount(6, $result);
        $this->assertEquals('ping', $result['type']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
        $this->assertEquals(self::TEST_TXID, $result['prevTxid']);
        $this->assertTrue($result['requestSync']);
        $this->assertEquals(self::TEST_MICROTIME, $result['time']);
    }

    // =========================================================================
    // Tests for buildResponse() method - Pong Response
    // =========================================================================

    /**
     * Test buildResponse returns JSON encoded string
     */
    public function testBuildResponseReturnsJsonEncodedString(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = $this->payload->buildResponse($request);

        $this->assertIsString($result);
        $this->assertJson($result);
    }

    /**
     * Test buildResponse creates proper pong payload structure
     */
    public function testBuildResponseCreatesProperPongPayloadStructure(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildResponse($request), true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('senderAddress', $result);
        $this->assertArrayHasKey('senderPublicKey', $result);
        $this->assertArrayHasKey('prevTxid', $result);
        $this->assertArrayHasKey('chainValid', $result);
        $this->assertArrayHasKey('time', $result);
    }

    /**
     * Test buildResponse returns correct status value
     */
    public function testBuildResponseReturnsCorrectStatusValue(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildResponse($request), true);

        $this->assertEquals('pong', $result['status']);
    }

    /**
     * Test buildResponse uses resolved address for senderAddress
     */
    public function testBuildResponseUsesResolvedAddressForSenderAddress(): void
    {
        $this->mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_HTTP_ADDRESS)
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildResponse($request), true);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
    }

    /**
     * Test buildResponse includes senderPublicKey from UserContext
     */
    public function testBuildResponseIncludesSenderPublicKeyFromUserContext(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildResponse($request), true);

        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
    }

    /**
     * Test buildResponse includes localPrevTxid when provided
     */
    public function testBuildResponseIncludesLocalPrevTxidWhenProvided(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildResponse($request, self::TEST_TXID), true);

        $this->assertEquals(self::TEST_TXID, $result['prevTxid']);
    }

    /**
     * Test buildResponse sets prevTxid to null when not provided
     */
    public function testBuildResponseSetsPrevTxidToNullWhenNotProvided(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildResponse($request), true);

        $this->assertNull($result['prevTxid']);
    }

    /**
     * Test buildResponse includes chainValid as true by default
     */
    public function testBuildResponseIncludesChainValidAsTrueByDefault(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildResponse($request), true);

        $this->assertTrue($result['chainValid']);
    }

    /**
     * Test buildResponse includes chainValid as false when specified
     */
    public function testBuildResponseIncludesChainValidAsFalseWhenSpecified(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildResponse($request, null, false), true);

        $this->assertFalse($result['chainValid']);
    }

    /**
     * Test buildResponse includes chainValid as true when specified
     */
    public function testBuildResponseIncludesChainValidAsTrueWhenSpecified(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildResponse($request, self::TEST_TXID, true), true);

        $this->assertTrue($result['chainValid']);
    }

    /**
     * Test buildResponse includes time from TimeUtilityService
     */
    public function testBuildResponseIncludesTimeFromTimeUtilityService(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildResponse($request), true);

        $this->assertEquals(self::TEST_MICROTIME, $result['time']);
    }

    /**
     * Test buildResponse throws exception when senderAddress is missing
     */
    public function testBuildResponseThrowsExceptionWhenSenderAddressIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'senderAddress' is missing from payload data");

        $this->payload->buildResponse([]);
    }

    /**
     * Test buildResponse with all parameters
     */
    public function testBuildResponseWithAllParameters(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildResponse($request, self::TEST_TXID, false), true);

        $this->assertCount(8, $result);
        $this->assertEquals('pong', $result['status']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
        $this->assertEquals(self::TEST_TXID, $result['prevTxid']);
        $this->assertFalse($result['chainValid']);
        $this->assertNull($result['availableCredit']);
        $this->assertNull($result['currency']);
        $this->assertEquals(self::TEST_MICROTIME, $result['time']);
    }

    // =========================================================================
    // Tests for buildRejection() method
    // =========================================================================

    /**
     * Test buildRejection returns JSON encoded string
     */
    public function testBuildRejectionReturnsJsonEncodedString(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = $this->payload->buildRejection($request);

        $this->assertIsString($result);
        $this->assertJson($result);
    }

    /**
     * Test buildRejection creates proper rejection payload structure
     */
    public function testBuildRejectionCreatesProperRejectionPayloadStructure(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildRejection($request), true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('senderAddress', $result);
        $this->assertArrayHasKey('senderPublicKey', $result);
    }

    /**
     * Test buildRejection returns correct status value
     */
    public function testBuildRejectionReturnsCorrectStatusValue(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildRejection($request), true);

        $this->assertEquals('rejected', $result['status']);
    }

    /**
     * Test buildRejection uses default reason 'blocked'
     */
    public function testBuildRejectionUsesDefaultReasonBlocked(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildRejection($request), true);

        $this->assertEquals('blocked', $result['reason']);
        $this->assertEquals('Contact is blocked', $result['message']);
    }

    /**
     * Test buildRejection handles 'blocked' reason
     */
    public function testBuildRejectionHandlesBlockedReason(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildRejection($request, 'blocked'), true);

        $this->assertEquals('blocked', $result['reason']);
        $this->assertEquals('Contact is blocked', $result['message']);
    }

    /**
     * Test buildRejection handles 'disabled' reason
     */
    public function testBuildRejectionHandlesDisabledReason(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildRejection($request, 'disabled'), true);

        $this->assertEquals('disabled', $result['reason']);
        $this->assertEquals('Contact status feature is disabled', $result['message']);
    }

    /**
     * Test buildRejection handles 'unknown_contact' reason
     */
    public function testBuildRejectionHandlesUnknownContactReason(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildRejection($request, 'unknown_contact'), true);

        $this->assertEquals('unknown_contact', $result['reason']);
        $this->assertEquals('Contact not found', $result['message']);
    }

    /**
     * Test buildRejection handles custom unknown reason with fallback message
     */
    public function testBuildRejectionHandlesCustomUnknownReasonWithFallbackMessage(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildRejection($request, 'custom_reason'), true);

        $this->assertEquals('custom_reason', $result['reason']);
        $this->assertEquals('Ping rejected: custom_reason', $result['message']);
    }

    /**
     * Test buildRejection uses resolved address for senderAddress
     */
    public function testBuildRejectionUsesResolvedAddressForSenderAddress(): void
    {
        $this->mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_HTTP_ADDRESS)
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildRejection($request), true);

        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
    }

    /**
     * Test buildRejection includes senderPublicKey from UserContext
     */
    public function testBuildRejectionIncludesSenderPublicKeyFromUserContext(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildRejection($request), true);

        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
    }

    /**
     * Test buildRejection throws exception when senderAddress is missing
     */
    public function testBuildRejectionThrowsExceptionWhenSenderAddressIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'senderAddress' is missing from payload data");

        $this->payload->buildRejection([]);
    }

    /**
     * Test buildRejection does not include time field
     */
    public function testBuildRejectionDoesNotIncludeTimeField(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildRejection($request), true);

        $this->assertArrayNotHasKey('time', $result);
    }

    // =========================================================================
    // Tests for all methods using different address types
    // =========================================================================

    /**
     * Test build with TOR address
     */
    public function testBuildWithTorAddress(): void
    {
        // Create fresh mocks for this test to override default behavior
        $mockTransportUtility = $this->createMock(TransportUtilityService::class);
        $mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_TOR_ADDRESS)
            ->willReturn(self::TEST_TOR_ADDRESS);

        $mockUtilityContainer = $this->createMock(UtilityServiceContainer::class);
        $mockUtilityContainer->method('getCurrencyUtility')
            ->willReturn($this->mockCurrencyUtility);
        $mockUtilityContainer->method('getTimeUtility')
            ->willReturn($this->mockTimeUtility);
        $mockUtilityContainer->method('getValidationUtility')
            ->willReturn($this->mockValidationUtility);
        $mockUtilityContainer->method('getTransportUtility')
            ->willReturn($mockTransportUtility);

        $payload = new ContactStatusPayload($this->mockUserContext, $mockUtilityContainer);
        $result = $payload->build(['receiverAddress' => self::TEST_TOR_ADDRESS]);

        $this->assertEquals(self::TEST_TOR_ADDRESS, $result['senderAddress']);
    }

    /**
     * Test buildResponse with HTTPS address
     */
    public function testBuildResponseWithHttpsAddress(): void
    {
        // Create fresh mocks for this test to override default behavior
        $mockTransportUtility = $this->createMock(TransportUtilityService::class);
        $mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_HTTPS_ADDRESS)
            ->willReturn(self::TEST_HTTPS_ADDRESS);

        $mockUtilityContainer = $this->createMock(UtilityServiceContainer::class);
        $mockUtilityContainer->method('getCurrencyUtility')
            ->willReturn($this->mockCurrencyUtility);
        $mockUtilityContainer->method('getTimeUtility')
            ->willReturn($this->mockTimeUtility);
        $mockUtilityContainer->method('getValidationUtility')
            ->willReturn($this->mockValidationUtility);
        $mockUtilityContainer->method('getTransportUtility')
            ->willReturn($mockTransportUtility);

        $payload = new ContactStatusPayload($this->mockUserContext, $mockUtilityContainer);
        $request = ['senderAddress' => self::TEST_HTTPS_ADDRESS];
        $result = json_decode($payload->buildResponse($request), true);

        $this->assertEquals(self::TEST_HTTPS_ADDRESS, $result['senderAddress']);
    }

    /**
     * Test buildRejection with TOR address
     */
    public function testBuildRejectionWithTorAddress(): void
    {
        // Create fresh mocks for this test to override default behavior
        $mockTransportUtility = $this->createMock(TransportUtilityService::class);
        $mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_TOR_ADDRESS)
            ->willReturn(self::TEST_TOR_ADDRESS);

        $mockUtilityContainer = $this->createMock(UtilityServiceContainer::class);
        $mockUtilityContainer->method('getCurrencyUtility')
            ->willReturn($this->mockCurrencyUtility);
        $mockUtilityContainer->method('getTimeUtility')
            ->willReturn($this->mockTimeUtility);
        $mockUtilityContainer->method('getValidationUtility')
            ->willReturn($this->mockValidationUtility);
        $mockUtilityContainer->method('getTransportUtility')
            ->willReturn($mockTransportUtility);

        $payload = new ContactStatusPayload($this->mockUserContext, $mockUtilityContainer);
        $request = ['senderAddress' => self::TEST_TOR_ADDRESS];
        $result = json_decode($payload->buildRejection($request), true);

        $this->assertEquals(self::TEST_TOR_ADDRESS, $result['senderAddress']);
    }

    // =========================================================================
    // Tests for JSON encoding validity
    // =========================================================================

    /**
     * Test all JSON payloads are valid JSON
     */
    public function testAllJsonPayloadsAreValidJson(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];

        // buildResponse
        $response = $this->payload->buildResponse($request);
        $this->assertJson($response);
        $decoded = json_decode($response, true);
        $this->assertNotNull($decoded);

        // buildRejection
        $rejection = $this->payload->buildRejection($request);
        $this->assertJson($rejection);
        $decoded = json_decode($rejection, true);
        $this->assertNotNull($decoded);
    }

    // =========================================================================
    // Tests for mock interaction verification
    // =========================================================================

    /**
     * Test all methods call resolveUserAddressForTransport
     */
    public function testAllMethodsCallResolveUserAddressForTransport(): void
    {
        $this->mockTransportUtility->expects($this->exactly(3))
            ->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        // build
        $this->payload->build(['receiverAddress' => self::TEST_HTTP_ADDRESS]);

        // buildResponse
        $this->payload->buildResponse(['senderAddress' => self::TEST_HTTP_ADDRESS]);

        // buildRejection
        $this->payload->buildRejection(['senderAddress' => self::TEST_HTTP_ADDRESS]);
    }

    /**
     * Test build and buildResponse call getCurrentMicrotime
     */
    public function testBuildAndBuildResponseCallGetCurrentMicrotime(): void
    {
        $this->mockTimeUtility->expects($this->exactly(2))
            ->method('getCurrentMicrotime')
            ->willReturn(self::TEST_MICROTIME);

        // build
        $this->payload->build(['receiverAddress' => self::TEST_HTTP_ADDRESS]);

        // buildResponse
        $this->payload->buildResponse(['senderAddress' => self::TEST_HTTP_ADDRESS]);
    }

    /**
     * Test all methods call getPublicKey from UserContext
     */
    public function testAllMethodsCallGetPublicKeyFromUserContext(): void
    {
        $this->mockUserContext->expects($this->exactly(3))
            ->method('getPublicKey')
            ->willReturn(self::TEST_PUBLIC_KEY);

        // build
        $this->payload->build(['receiverAddress' => self::TEST_HTTP_ADDRESS]);

        // buildResponse
        $this->payload->buildResponse(['senderAddress' => self::TEST_HTTP_ADDRESS]);

        // buildRejection
        $this->payload->buildRejection(['senderAddress' => self::TEST_HTTP_ADDRESS]);
    }

    // =========================================================================
    // Tests for edge cases
    // =========================================================================

    /**
     * Test build with empty string prevTxid
     */
    public function testBuildWithEmptyStringPrevTxid(): void
    {
        $result = $this->payload->build([
            'receiverAddress' => self::TEST_HTTP_ADDRESS,
            'prevTxid' => ''
        ]);

        $this->assertEquals('', $result['prevTxid']);
    }

    /**
     * Test buildResponse with empty string localPrevTxid
     */
    public function testBuildResponseWithEmptyStringLocalPrevTxid(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildResponse($request, ''), true);

        $this->assertEquals('', $result['prevTxid']);
    }

    /**
     * Test buildRejection with empty string reason uses fallback
     */
    public function testBuildRejectionWithEmptyStringReasonUsesFallback(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildRejection($request, ''), true);

        $this->assertEquals('', $result['reason']);
        $this->assertEquals('Ping rejected: ', $result['message']);
    }

    /**
     * Test build payload has exactly 6 keys
     */
    public function testBuildPayloadHasExactlySixKeys(): void
    {
        $result = $this->payload->build(['receiverAddress' => self::TEST_HTTP_ADDRESS]);

        $this->assertCount(6, $result);
    }

    /**
     * Test buildResponse payload has exactly 8 keys (includes availableCredit and currency)
     */
    public function testBuildResponsePayloadHasExactlyEightKeys(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildResponse($request), true);

        $this->assertCount(8, $result);
    }

    /**
     * Test buildRejection payload has exactly 5 keys
     */
    public function testBuildRejectionPayloadHasExactlyFiveKeys(): void
    {
        $request = ['senderAddress' => self::TEST_HTTP_ADDRESS];
        $result = json_decode($this->payload->buildRejection($request), true);

        $this->assertCount(5, $result);
    }

    /**
     * Test build with request containing extra fields ignores them
     */
    public function testBuildWithRequestContainingExtraFieldsIgnoresThem(): void
    {
        $result = $this->payload->build([
            'receiverAddress' => self::TEST_HTTP_ADDRESS,
            'extraField' => 'should be ignored',
            'anotherField' => 12345
        ]);

        $this->assertArrayNotHasKey('extraField', $result);
        $this->assertArrayNotHasKey('anotherField', $result);
        $this->assertCount(6, $result);
    }

    /**
     * Test buildResponse with request containing extra fields ignores them
     */
    public function testBuildResponseWithRequestContainingExtraFieldsIgnoresThem(): void
    {
        $request = [
            'senderAddress' => self::TEST_HTTP_ADDRESS,
            'extraField' => 'should be ignored',
            'anotherField' => 12345
        ];
        $result = json_decode($this->payload->buildResponse($request), true);

        $this->assertArrayNotHasKey('extraField', $result);
        $this->assertArrayNotHasKey('anotherField', $result);
        $this->assertCount(8, $result);
    }

    /**
     * Test buildRejection with request containing extra fields ignores them
     */
    public function testBuildRejectionWithRequestContainingExtraFieldsIgnoresThem(): void
    {
        $request = [
            'senderAddress' => self::TEST_HTTP_ADDRESS,
            'extraField' => 'should be ignored',
            'anotherField' => 12345
        ];
        $result = json_decode($this->payload->buildRejection($request), true);

        $this->assertArrayNotHasKey('extraField', $result);
        $this->assertArrayNotHasKey('anotherField', $result);
        $this->assertCount(5, $result);
    }
}
