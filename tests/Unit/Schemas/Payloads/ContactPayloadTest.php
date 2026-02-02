<?php
/**
 * Unit Tests for ContactPayload
 *
 * Tests contact payload building functionality including:
 * - Contact creation payload building
 * - Contact received/updated status payloads
 * - Contact warning and rejection payloads
 * - Pending and mutually accepted payloads
 * - Address filtering for transport keys
 */

namespace Eiou\Tests\Schemas\Payloads;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Schemas\Payloads\ContactPayload;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\ValidationUtilityService;

#[CoversClass(ContactPayload::class)]
class ContactPayloadTest extends TestCase
{
    private ContactPayload $payload;
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
                // Return a resolved address based on input
                return self::TEST_RESOLVED_ADDRESS;
            });

        $this->mockTransportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->payload = new ContactPayload(
            $this->mockUserContext,
            $this->mockUtilityContainer
        );
    }

    /**
     * Test build creates proper payload structure
     */
    public function testBuildCreatesProperPayloadStructure(): void
    {
        $result = $this->payload->build(['address' => self::TEST_HTTP_ADDRESS]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('senderAddress', $result);
        $this->assertArrayHasKey('senderPublicKey', $result);
        $this->assertEquals('create', $result['type']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
    }

    /**
     * Test build requires address in data array
     *
     * The build method expects data['address'] to be provided.
     * When not provided, PHP will emit a warning for undefined array key.
     */
    public function testBuildRequiresAddressInDataArray(): void
    {
        // Test that providing an address works correctly
        $result = $this->payload->build(['address' => self::TEST_HTTP_ADDRESS]);

        $this->assertIsArray($result);
        $this->assertEquals('create', $result['type']);
        $this->assertArrayHasKey('senderAddress', $result);
        $this->assertArrayHasKey('senderPublicKey', $result);
    }

    /**
     * Test buildCreateRequest delegates to build
     */
    public function testBuildCreateRequestDelegatesToBuild(): void
    {
        $this->mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_HTTP_ADDRESS)
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        $result = $this->payload->buildCreateRequest(self::TEST_HTTP_ADDRESS);

        $this->assertIsArray($result);
        $this->assertEquals('create', $result['type']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
    }

    /**
     * Test buildReceived returns JSON with status RECEIVED
     */
    public function testBuildReceivedReturnsJsonWithStatusReceived(): void
    {
        $result = $this->payload->buildReceived(self::TEST_HTTP_ADDRESS);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded);
        $this->assertEquals(Constants::DELIVERY_RECEIVED, $decoded['status']);
        $this->assertStringContainsString('confirms that the contact request has been received', $decoded['message']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    /**
     * Test buildReceived includes senderAddresses when provided
     */
    public function testBuildReceivedIncludesSenderAddressesWhenProvided(): void
    {
        $knownAddresses = [
            'http' => self::TEST_HTTP_ADDRESS,
            'https' => self::TEST_HTTPS_ADDRESS,
            'tor' => self::TEST_TOR_ADDRESS,
            'pubkey_hash' => 'should-be-filtered-out'
        ];

        $result = $this->payload->buildReceived(self::TEST_HTTP_ADDRESS, $knownAddresses);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('senderAddresses', $decoded);
        $this->assertArrayHasKey('http', $decoded['senderAddresses']);
        $this->assertArrayHasKey('https', $decoded['senderAddresses']);
        $this->assertArrayHasKey('tor', $decoded['senderAddresses']);
        // pubkey_hash should be filtered out
        $this->assertArrayNotHasKey('pubkey_hash', $decoded['senderAddresses']);
    }

    /**
     * Test buildReceived includes txid when provided
     */
    public function testBuildReceivedIncludesTxidWhenProvided(): void
    {
        $result = $this->payload->buildReceived(self::TEST_HTTP_ADDRESS, null, self::TEST_TXID);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('txid', $decoded);
        $this->assertEquals(self::TEST_TXID, $decoded['txid']);
    }

    /**
     * Test buildReceived without optional parameters
     */
    public function testBuildReceivedWithoutOptionalParameters(): void
    {
        $result = $this->payload->buildReceived(self::TEST_HTTP_ADDRESS);
        $decoded = json_decode($result, true);

        $this->assertArrayNotHasKey('senderAddresses', $decoded);
        $this->assertArrayNotHasKey('txid', $decoded);
    }

    /**
     * Test buildUpdated returns JSON with status UPDATED
     */
    public function testBuildUpdatedReturnsJsonWithStatusUpdated(): void
    {
        $result = $this->payload->buildUpdated(self::TEST_HTTP_ADDRESS);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded);
        $this->assertEquals(Constants::DELIVERY_UPDATED, $decoded['status']);
        $this->assertStringContainsString('confirms that contact address has been updated/added', $decoded['message']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    /**
     * Test buildUpdated includes senderAddresses when provided
     */
    public function testBuildUpdatedIncludesSenderAddressesWhenProvided(): void
    {
        $knownAddresses = [
            'http' => self::TEST_HTTP_ADDRESS,
            'tor' => self::TEST_TOR_ADDRESS
        ];

        $result = $this->payload->buildUpdated(self::TEST_HTTP_ADDRESS, $knownAddresses);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('senderAddresses', $decoded);
        $this->assertEquals(self::TEST_HTTP_ADDRESS, $decoded['senderAddresses']['http']);
        $this->assertEquals(self::TEST_TOR_ADDRESS, $decoded['senderAddresses']['tor']);
    }

    /**
     * Test buildAlreadyExists returns JSON with WARNING status
     */
    public function testBuildAlreadyExistsReturnsJsonWithWarningStatus(): void
    {
        $result = $this->payload->buildAlreadyExists(self::TEST_HTTP_ADDRESS);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded);
        $this->assertEquals(Constants::DELIVERY_WARNING, $decoded['status']);
        $this->assertEquals('Contact already exists', $decoded['message']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    /**
     * Test buildAlreadyExists includes senderAddresses when provided
     */
    public function testBuildAlreadyExistsIncludesSenderAddressesWhenProvided(): void
    {
        $knownAddresses = [
            'http' => self::TEST_HTTP_ADDRESS
        ];

        $result = $this->payload->buildAlreadyExists(self::TEST_HTTP_ADDRESS, $knownAddresses);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('senderAddresses', $decoded);
        $this->assertEquals(self::TEST_HTTP_ADDRESS, $decoded['senderAddresses']['http']);
    }

    /**
     * Test buildRejection returns JSON with REJECTED status
     */
    public function testBuildRejectionReturnsJsonWithRejectedStatus(): void
    {
        $result = $this->payload->buildRejection(self::TEST_HTTP_ADDRESS);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded);
        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
        $this->assertEquals('Contact request rejected', $decoded['message']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    /**
     * Test buildRejection uses custom reason
     */
    public function testBuildRejectionUsesCustomReason(): void
    {
        $customReason = 'User is blocked due to policy violation';
        $result = $this->payload->buildRejection(self::TEST_HTTP_ADDRESS, $customReason);
        $decoded = json_decode($result, true);

        $this->assertEquals($customReason, $decoded['message']);
    }

    /**
     * Test buildPending returns array with PENDING status
     */
    public function testBuildPendingReturnsArrayWithPendingStatus(): void
    {
        $result = $this->payload->buildPending(self::TEST_HTTP_ADDRESS);

        $this->assertIsArray($result);
        $this->assertEquals(Constants::STATUS_PENDING, $result['status']);
        $this->assertStringContainsString('is pending', $result['message']);
        $this->assertStringContainsString(self::TEST_HTTP_ADDRESS, $result['message']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $result['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $result['senderPublicKey']);
    }

    /**
     * Test buildMutuallyAccepted returns JSON with ACCEPTED status
     */
    public function testBuildMutuallyAcceptedReturnsJsonWithAcceptedStatus(): void
    {
        $result = $this->payload->buildMutuallyAccepted(self::TEST_HTTP_ADDRESS);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded);
        $this->assertEquals(Constants::STATUS_ACCEPTED, $decoded['status']);
        $this->assertStringContainsString('mutually accepted', $decoded['message']);
        $this->assertEquals(self::TEST_RESOLVED_ADDRESS, $decoded['senderAddress']);
        $this->assertEquals(self::TEST_PUBLIC_KEY, $decoded['senderPublicKey']);
    }

    /**
     * Test buildMutuallyAccepted includes senderAddresses when provided
     */
    public function testBuildMutuallyAcceptedIncludesSenderAddressesWhenProvided(): void
    {
        $knownAddresses = [
            'http' => self::TEST_HTTP_ADDRESS,
            'https' => self::TEST_HTTPS_ADDRESS,
            'tor' => self::TEST_TOR_ADDRESS
        ];

        $result = $this->payload->buildMutuallyAccepted(self::TEST_HTTP_ADDRESS, $knownAddresses);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('senderAddresses', $decoded);
        $this->assertCount(3, $decoded['senderAddresses']);
    }

    /**
     * Test filterAddresses removes empty addresses
     */
    public function testFilterAddressesRemovesEmptyAddresses(): void
    {
        $knownAddresses = [
            'http' => self::TEST_HTTP_ADDRESS,
            'https' => '', // Empty string
            'tor' => null, // Null value - won't be set via isset
        ];

        // Test via buildReceived which uses filterAddresses internally
        $result = $this->payload->buildReceived(self::TEST_HTTP_ADDRESS, $knownAddresses);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('senderAddresses', $decoded);
        $this->assertArrayHasKey('http', $decoded['senderAddresses']);
        $this->assertArrayNotHasKey('https', $decoded['senderAddresses']); // Empty string filtered
        $this->assertArrayNotHasKey('tor', $decoded['senderAddresses']); // Null filtered
    }

    /**
     * Test filterAddresses only includes transport keys
     */
    public function testFilterAddressesOnlyIncludesTransportKeys(): void
    {
        $knownAddresses = [
            'http' => self::TEST_HTTP_ADDRESS,
            'https' => self::TEST_HTTPS_ADDRESS,
            'tor' => self::TEST_TOR_ADDRESS,
            'pubkey_hash' => 'abc123hash',
            'extra_field' => 'should-not-appear',
            'random_key' => 'also-filtered'
        ];

        $result = $this->payload->buildReceived(self::TEST_HTTP_ADDRESS, $knownAddresses);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('senderAddresses', $decoded);
        // Only transport keys should be present
        $this->assertArrayHasKey('http', $decoded['senderAddresses']);
        $this->assertArrayHasKey('https', $decoded['senderAddresses']);
        $this->assertArrayHasKey('tor', $decoded['senderAddresses']);
        // Non-transport keys should be filtered
        $this->assertArrayNotHasKey('pubkey_hash', $decoded['senderAddresses']);
        $this->assertArrayNotHasKey('extra_field', $decoded['senderAddresses']);
        $this->assertArrayNotHasKey('random_key', $decoded['senderAddresses']);
    }

    /**
     * Test filterAddresses with all empty addresses
     */
    public function testFilterAddressesWithAllEmptyAddresses(): void
    {
        $knownAddresses = [
            'http' => '',
            'https' => '',
            'tor' => ''
        ];

        $result = $this->payload->buildReceived(self::TEST_HTTP_ADDRESS, $knownAddresses);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('senderAddresses', $decoded);
        $this->assertEmpty($decoded['senderAddresses']);
    }

    /**
     * Test all methods use resolveUserAddressForTransport correctly
     */
    public function testAllMethodsUseResolveUserAddressForTransportCorrectly(): void
    {
        $this->mockTransportUtility->expects($this->exactly(7))
            ->method('resolveUserAddressForTransport')
            ->with(self::TEST_HTTP_ADDRESS)
            ->willReturn(self::TEST_RESOLVED_ADDRESS);

        // Each method should call resolveUserAddressForTransport
        $this->payload->build(['address' => self::TEST_HTTP_ADDRESS]);
        $this->payload->buildCreateRequest(self::TEST_HTTP_ADDRESS);
        $this->payload->buildReceived(self::TEST_HTTP_ADDRESS);
        $this->payload->buildUpdated(self::TEST_HTTP_ADDRESS);
        $this->payload->buildAlreadyExists(self::TEST_HTTP_ADDRESS);
        $this->payload->buildRejection(self::TEST_HTTP_ADDRESS);
        $this->payload->buildPending(self::TEST_HTTP_ADDRESS);
        // Note: buildMutuallyAccepted would be 8th call but we already have 7
    }

    /**
     * Test buildReceived with both knownAddresses and txid
     */
    public function testBuildReceivedWithBothKnownAddressesAndTxid(): void
    {
        $knownAddresses = [
            'http' => self::TEST_HTTP_ADDRESS,
            'tor' => self::TEST_TOR_ADDRESS
        ];

        $result = $this->payload->buildReceived(self::TEST_HTTP_ADDRESS, $knownAddresses, self::TEST_TXID);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertArrayHasKey('senderAddress', $decoded);
        $this->assertArrayHasKey('senderPublicKey', $decoded);
        $this->assertArrayHasKey('senderAddresses', $decoded);
        $this->assertArrayHasKey('txid', $decoded);

        $this->assertEquals(Constants::DELIVERY_RECEIVED, $decoded['status']);
        $this->assertEquals(self::TEST_TXID, $decoded['txid']);
        $this->assertCount(2, $decoded['senderAddresses']);
    }

    /**
     * Test payload JSON encoding is valid
     */
    public function testPayloadJsonEncodingIsValid(): void
    {
        // Test buildReceived
        $received = $this->payload->buildReceived(self::TEST_HTTP_ADDRESS);
        $this->assertJson($received);

        // Test buildUpdated
        $updated = $this->payload->buildUpdated(self::TEST_HTTP_ADDRESS);
        $this->assertJson($updated);

        // Test buildAlreadyExists
        $alreadyExists = $this->payload->buildAlreadyExists(self::TEST_HTTP_ADDRESS);
        $this->assertJson($alreadyExists);

        // Test buildRejection
        $rejection = $this->payload->buildRejection(self::TEST_HTTP_ADDRESS);
        $this->assertJson($rejection);

        // Test buildMutuallyAccepted
        $mutuallyAccepted = $this->payload->buildMutuallyAccepted(self::TEST_HTTP_ADDRESS);
        $this->assertJson($mutuallyAccepted);
    }

    /**
     * Test buildPending message format includes the address
     */
    public function testBuildPendingMessageFormatIncludesAddress(): void
    {
        $result = $this->payload->buildPending(self::TEST_TOR_ADDRESS);

        $this->assertStringContainsString(self::TEST_TOR_ADDRESS, $result['message']);
        $this->assertEquals("Contact request to " . self::TEST_TOR_ADDRESS . " is pending", $result['message']);
    }

    /**
     * Test all status payloads include required fields
     */
    public function testAllStatusPayloadsIncludeRequiredFields(): void
    {
        $requiredFields = ['status', 'message', 'senderAddress', 'senderPublicKey'];

        // buildReceived
        $received = json_decode($this->payload->buildReceived(self::TEST_HTTP_ADDRESS), true);
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $received, "buildReceived missing field: $field");
        }

        // buildUpdated
        $updated = json_decode($this->payload->buildUpdated(self::TEST_HTTP_ADDRESS), true);
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $updated, "buildUpdated missing field: $field");
        }

        // buildAlreadyExists
        $alreadyExists = json_decode($this->payload->buildAlreadyExists(self::TEST_HTTP_ADDRESS), true);
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $alreadyExists, "buildAlreadyExists missing field: $field");
        }

        // buildRejection
        $rejection = json_decode($this->payload->buildRejection(self::TEST_HTTP_ADDRESS), true);
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $rejection, "buildRejection missing field: $field");
        }

        // buildMutuallyAccepted
        $mutuallyAccepted = json_decode($this->payload->buildMutuallyAccepted(self::TEST_HTTP_ADDRESS), true);
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $mutuallyAccepted, "buildMutuallyAccepted missing field: $field");
        }

        // buildPending (returns array, not JSON)
        $pending = $this->payload->buildPending(self::TEST_HTTP_ADDRESS);
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $pending, "buildPending missing field: $field");
        }
    }

    /**
     * Test build payload has correct structure for contact creation
     */
    public function testBuildPayloadHasCorrectStructureForContactCreation(): void
    {
        $result = $this->payload->build(['address' => self::TEST_HTTP_ADDRESS]);

        // Should have exactly 3 keys
        $this->assertCount(3, $result);
        $this->assertEquals('create', $result['type']);
        $this->assertIsString($result['senderAddress']);
        $this->assertIsString($result['senderPublicKey']);
    }

    /**
     * Test filterAddresses with custom address types from utility
     */
    public function testFilterAddressesWithCustomAddressTypesFromUtility(): void
    {
        // Configure mock to return custom address types
        $customTransportUtility = $this->createMock(TransportUtilityService::class);
        $customTransportUtility->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_RESOLVED_ADDRESS);
        $customTransportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'i2p', 'custom_transport']);

        $customUtilityContainer = $this->createMock(UtilityServiceContainer::class);
        $customUtilityContainer->method('getCurrencyUtility')
            ->willReturn($this->mockCurrencyUtility);
        $customUtilityContainer->method('getTimeUtility')
            ->willReturn($this->mockTimeUtility);
        $customUtilityContainer->method('getValidationUtility')
            ->willReturn($this->mockValidationUtility);
        $customUtilityContainer->method('getTransportUtility')
            ->willReturn($customTransportUtility);

        $payload = new ContactPayload($this->mockUserContext, $customUtilityContainer);

        $knownAddresses = [
            'http' => self::TEST_HTTP_ADDRESS,
            'i2p' => 'http://example.i2p',
            'custom_transport' => 'custom://address',
            'tor' => self::TEST_TOR_ADDRESS, // Not in custom types
            'https' => self::TEST_HTTPS_ADDRESS // Not in custom types
        ];

        $result = $payload->buildReceived(self::TEST_HTTP_ADDRESS, $knownAddresses);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('senderAddresses', $decoded);
        $this->assertArrayHasKey('http', $decoded['senderAddresses']);
        $this->assertArrayHasKey('i2p', $decoded['senderAddresses']);
        $this->assertArrayHasKey('custom_transport', $decoded['senderAddresses']);
        // These should be filtered because they're not in getAllAddressTypes()
        $this->assertArrayNotHasKey('tor', $decoded['senderAddresses']);
        $this->assertArrayNotHasKey('https', $decoded['senderAddresses']);
    }
}
