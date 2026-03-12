<?php
/**
 * Unit Tests for TransportUtilityService
 *
 * Tests transport-related utilities including address type detection,
 * transport type determination, and address counting functions.
 */

namespace Eiou\Tests\Services\Utilities;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\ServiceContainer;
use Eiou\Core\UserContext;
use Eiou\Database\AddressRepository;
use Eiou\Database\RepositoryFactory;

#[CoversClass(TransportUtilityService::class)]
class TransportUtilityServiceTest extends TestCase
{
    private ServiceContainer $serviceContainer;
    private UserContext $userContext;
    private AddressRepository $addressRepository;
    private TransportUtilityService $service;

    protected function setUp(): void
    {
        // Create mock objects
        $this->serviceContainer = $this->createMock(ServiceContainer::class);
        $this->userContext = $this->createMock(UserContext::class);
        $this->addressRepository = $this->createMock(AddressRepository::class);

        // Configure service container to return the mock user context
        $this->serviceContainer->expects($this->any())
            ->method('getCurrentUser')
            ->willReturn($this->userContext);

        $mockRepoFactory = $this->createMock(RepositoryFactory::class);
        $mockRepoFactory->method('get')
            ->with(AddressRepository::class)
            ->willReturn($this->addressRepository);
        $this->serviceContainer->expects($this->any())
            ->method('getRepositoryFactory')
            ->willReturn($mockRepoFactory);

        // Create the service
        $this->service = new TransportUtilityService($this->serviceContainer);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor sets up service container dependency
     */
    public function testConstructorSetsServiceContainer(): void
    {
        $serviceContainer = $this->createMock(ServiceContainer::class);
        $userContext = $this->createMock(UserContext::class);

        $serviceContainer->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn($userContext);

        $service = new TransportUtilityService($serviceContainer);

        $this->assertInstanceOf(TransportUtilityService::class, $service);
    }

    // =========================================================================
    // Address Type Detection Tests
    // =========================================================================

    /**
     * Test isHttpsAddress with HTTPS address returns true
     */
    public function testIsHttpsAddressWithHttpsReturnsTrue(): void
    {
        $this->assertTrue($this->service->isHttpsAddress('https://example.com'));
    }

    /**
     * Test isHttpsAddress with HTTP address returns false
     */
    public function testIsHttpsAddressWithHttpReturnsFalse(): void
    {
        $this->assertFalse($this->service->isHttpsAddress('http://example.com'));
    }

    /**
     * Test isHttpsAddress with Tor address returns false
     */
    public function testIsHttpsAddressWithTorReturnsFalse(): void
    {
        $this->assertFalse($this->service->isHttpsAddress('abcdef.onion'));
    }

    /**
     * Test isHttpAddress with HTTP address returns true
     */
    public function testIsHttpAddressWithHttpReturnsTrue(): void
    {
        $this->assertTrue($this->service->isHttpAddress('http://example.com'));
    }

    /**
     * Test isHttpAddress with HTTPS address returns false
     */
    public function testIsHttpAddressWithHttpsReturnsFalse(): void
    {
        $this->assertFalse($this->service->isHttpAddress('https://example.com'));
    }

    /**
     * Test isHttpAddress with Tor address returns false
     */
    public function testIsHttpAddressWithTorReturnsFalse(): void
    {
        $this->assertFalse($this->service->isHttpAddress('abcdef.onion'));
    }

    /**
     * Test isTorAddress with Tor .onion address returns true
     */
    public function testIsTorAddressWithOnionReturnsTrue(): void
    {
        $this->assertTrue($this->service->isTorAddress('abcdef1234567890.onion'));
    }

    /**
     * Test isTorAddress with HTTP address returns false
     */
    public function testIsTorAddressWithHttpReturnsFalse(): void
    {
        $this->assertFalse($this->service->isTorAddress('http://example.com'));
    }

    /**
     * Test isTorAddress with HTTPS address returns false
     */
    public function testIsTorAddressWithHttpsReturnsFalse(): void
    {
        $this->assertFalse($this->service->isTorAddress('https://example.com'));
    }

    /**
     * Test isAddress with valid HTTP address returns true
     */
    public function testIsAddressWithHttpReturnsTrue(): void
    {
        $this->assertTrue($this->service->isAddress('http://example.com'));
    }

    /**
     * Test isAddress with valid HTTPS address returns true
     */
    public function testIsAddressWithHttpsReturnsTrue(): void
    {
        $this->assertTrue($this->service->isAddress('https://example.com'));
    }

    /**
     * Test isAddress with valid Tor address returns true
     */
    public function testIsAddressWithTorReturnsTrue(): void
    {
        $this->assertTrue($this->service->isAddress('abc123.onion'));
    }

    /**
     * Test isAddress with invalid address returns false
     */
    public function testIsAddressWithInvalidAddressReturnsFalse(): void
    {
        $this->assertFalse($this->service->isAddress('not-a-valid-address'));
    }

    /**
     * Test isAddress with empty string returns false
     */
    public function testIsAddressWithEmptyStringReturnsFalse(): void
    {
        $this->assertFalse($this->service->isAddress(''));
    }

    // =========================================================================
    // Transport Type Determination Tests
    // =========================================================================

    /**
     * Test determineTransportType with HTTP address returns http
     */
    public function testDetermineTransportTypeWithHttpReturnsHttp(): void
    {
        $result = $this->service->determineTransportType('http://example.com');

        $this->assertEquals('http', $result);
    }

    /**
     * Test determineTransportType with HTTPS address returns https
     */
    public function testDetermineTransportTypeWithHttpsReturnsHttps(): void
    {
        $result = $this->service->determineTransportType('https://example.com');

        $this->assertEquals('https', $result);
    }

    /**
     * Test determineTransportType with Tor address returns tor
     */
    public function testDetermineTransportTypeWithTorReturnsTor(): void
    {
        $result = $this->service->determineTransportType('xyz123abc.onion');

        $this->assertEquals('tor', $result);
    }

    /**
     * Test determineTransportType with unknown address returns null
     */
    public function testDetermineTransportTypeWithUnknownReturnsNull(): void
    {
        $result = $this->service->determineTransportType('just-a-hostname');

        $this->assertNull($result);
    }

    /**
     * Test determineTransportTypeAssociative with HTTP address
     */
    public function testDetermineTransportTypeAssociativeWithHttp(): void
    {
        $address = 'http://example.com';
        $result = $this->service->determineTransportTypeAssociative($address);

        $this->assertEquals(['http' => $address], $result);
    }

    /**
     * Test determineTransportTypeAssociative with HTTPS address
     */
    public function testDetermineTransportTypeAssociativeWithHttps(): void
    {
        $address = 'https://secure.example.com';
        $result = $this->service->determineTransportTypeAssociative($address);

        $this->assertEquals(['https' => $address], $result);
    }

    /**
     * Test determineTransportTypeAssociative with Tor address
     */
    public function testDetermineTransportTypeAssociativeWithTor(): void
    {
        $address = 'abcdefghij.onion';
        $result = $this->service->determineTransportTypeAssociative($address);

        $this->assertEquals(['tor' => $address], $result);
    }

    /**
     * Test determineTransportTypeAssociative with unknown address returns null
     */
    public function testDetermineTransportTypeAssociativeWithUnknownReturnsNull(): void
    {
        $result = $this->service->determineTransportTypeAssociative('unknown-format');

        $this->assertNull($result);
    }

    // =========================================================================
    // countTorAndHttpAddresses Tests
    // =========================================================================

    /**
     * Test countTorAndHttpAddresses with mixed address types
     */
    public function testCountTorAndHttpAddressesWithMixedTypes(): void
    {
        $addresses = [
            'http://site1.com',
            'https://site2.com',
            'https://site3.com',
            'abc123.onion',
            'def456.onion',
            'http://site4.com'
        ];

        $result = $this->service->countTorAndHttpAddresses($addresses);

        $this->assertEquals(2, $result['tor']);
        $this->assertEquals(2, $result['https']);
        $this->assertEquals(2, $result['http']);
        $this->assertEquals(6, $result['total']);
    }

    /**
     * Test countTorAndHttpAddresses with empty array
     */
    public function testCountTorAndHttpAddressesWithEmptyArray(): void
    {
        $result = $this->service->countTorAndHttpAddresses([]);

        $this->assertEquals(0, $result['tor']);
        $this->assertEquals(0, $result['https']);
        $this->assertEquals(0, $result['http']);
        $this->assertEquals(0, $result['total']);
    }

    /**
     * Test countTorAndHttpAddresses with only Tor addresses
     */
    public function testCountTorAndHttpAddressesWithOnlyTor(): void
    {
        $addresses = [
            'abc.onion',
            'def.onion',
            'ghi.onion'
        ];

        $result = $this->service->countTorAndHttpAddresses($addresses);

        $this->assertEquals(3, $result['tor']);
        $this->assertEquals(0, $result['https']);
        $this->assertEquals(0, $result['http']);
        $this->assertEquals(3, $result['total']);
    }

    /**
     * Test countTorAndHttpAddresses with only HTTPS addresses
     */
    public function testCountTorAndHttpAddressesWithOnlyHttps(): void
    {
        $addresses = [
            'https://site1.com',
            'https://site2.com'
        ];

        $result = $this->service->countTorAndHttpAddresses($addresses);

        $this->assertEquals(0, $result['tor']);
        $this->assertEquals(2, $result['https']);
        $this->assertEquals(0, $result['http']);
        $this->assertEquals(2, $result['total']);
    }

    // =========================================================================
    // getAllAddressTypes Tests
    // =========================================================================

    /**
     * Test getAllAddressTypes delegates to repository
     */
    public function testGetAllAddressTypesDelegatesToRepository(): void
    {
        $expectedTypes = ['http', 'https', 'tor'];

        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn($expectedTypes);

        $result = $this->service->getAllAddressTypes();

        $this->assertEquals($expectedTypes, $result);
    }

    // =========================================================================
    // resolveUserAddressForTransport Tests
    // =========================================================================

    /**
     * Test resolveUserAddressForTransport with Tor address returns user Tor address
     */
    public function testResolveUserAddressForTransportWithTorReturnsTorAddress(): void
    {
        $recipientAddress = 'recipient.onion';
        $userTorAddress = 'user.onion';

        $this->userContext->expects($this->once())
            ->method('getTorAddress')
            ->willReturn($userTorAddress);

        $result = $this->service->resolveUserAddressForTransport($recipientAddress);

        $this->assertEquals($userTorAddress, $result);
    }

    /**
     * Test resolveUserAddressForTransport with Tor address falls back to original if no user Tor
     */
    public function testResolveUserAddressForTransportWithTorFallsBackToOriginal(): void
    {
        $recipientAddress = 'recipient.onion';

        $this->userContext->expects($this->once())
            ->method('getTorAddress')
            ->willReturn(null);

        $result = $this->service->resolveUserAddressForTransport($recipientAddress);

        $this->assertEquals($recipientAddress, $result);
    }

    /**
     * Test resolveUserAddressForTransport with HTTPS address returns user HTTPS address
     */
    public function testResolveUserAddressForTransportWithHttpsReturnsHttpsAddress(): void
    {
        $recipientAddress = 'https://recipient.com';
        $userHttpsAddress = 'https://user.com';

        $this->userContext->expects($this->once())
            ->method('getHttpsAddress')
            ->willReturn($userHttpsAddress);

        $result = $this->service->resolveUserAddressForTransport($recipientAddress);

        $this->assertEquals($userHttpsAddress, $result);
    }

    /**
     * Test resolveUserAddressForTransport with HTTPS address falls back to Tor
     */
    public function testResolveUserAddressForTransportWithHttpsFallsBackToTor(): void
    {
        $recipientAddress = 'https://recipient.com';
        $userTorAddress = 'user.onion';

        $this->userContext->expects($this->once())
            ->method('getHttpsAddress')
            ->willReturn(null);

        $this->userContext->expects($this->once())
            ->method('getTorAddress')
            ->willReturn($userTorAddress);

        $result = $this->service->resolveUserAddressForTransport($recipientAddress);

        $this->assertEquals($userTorAddress, $result);
    }

    /**
     * Test resolveUserAddressForTransport with HTTPS address falls back to HTTP
     */
    public function testResolveUserAddressForTransportWithHttpsFallsBackToHttp(): void
    {
        $recipientAddress = 'https://recipient.com';
        $userHttpAddress = 'http://user.com';

        $this->userContext->expects($this->once())
            ->method('getHttpsAddress')
            ->willReturn(null);

        $this->userContext->expects($this->once())
            ->method('getTorAddress')
            ->willReturn(null);

        $this->userContext->expects($this->once())
            ->method('getHttpAddress')
            ->willReturn($userHttpAddress);

        $result = $this->service->resolveUserAddressForTransport($recipientAddress);

        $this->assertEquals($userHttpAddress, $result);
    }

    /**
     * Test resolveUserAddressForTransport with HTTP address returns user HTTP address
     */
    public function testResolveUserAddressForTransportWithHttpReturnsHttpAddress(): void
    {
        $recipientAddress = 'http://recipient.com';
        $userHttpAddress = 'http://user.com';

        $this->userContext->expects($this->once())
            ->method('getHttpAddress')
            ->willReturn($userHttpAddress);

        $result = $this->service->resolveUserAddressForTransport($recipientAddress);

        $this->assertEquals($userHttpAddress, $result);
    }

    /**
     * Test resolveUserAddressForTransport with HTTP address falls back to Tor
     */
    public function testResolveUserAddressForTransportWithHttpFallsBackToTor(): void
    {
        $recipientAddress = 'http://recipient.com';
        $userTorAddress = 'user.onion';

        $this->userContext->expects($this->once())
            ->method('getHttpAddress')
            ->willReturn(null);

        $this->userContext->expects($this->once())
            ->method('getTorAddress')
            ->willReturn($userTorAddress);

        $result = $this->service->resolveUserAddressForTransport($recipientAddress);

        $this->assertEquals($userTorAddress, $result);
    }

    /**
     * Test resolveUserAddressForTransport with unknown address returns original
     */
    public function testResolveUserAddressForTransportWithUnknownReturnsOriginal(): void
    {
        $unknownAddress = 'unknown-format-address';

        $result = $this->service->resolveUserAddressForTransport($unknownAddress);

        $this->assertEquals($unknownAddress, $result);
    }

    // =========================================================================
    // jitter Tests
    // =========================================================================

    /**
     * Test jitter returns value in expected range
     */
    public function testJitterReturnsValueInExpectedRange(): void
    {
        $originalValue = 100;

        // Run multiple times to verify randomness stays within bounds
        for ($i = 0; $i < 50; $i++) {
            $result = $this->service->jitter($originalValue);

            $this->assertGreaterThanOrEqual($originalValue, $result);
            $this->assertLessThanOrEqual($originalValue + 1, $result);
        }
    }

    /**
     * Test jitter with zero value
     */
    public function testJitterWithZeroValue(): void
    {
        $result = $this->service->jitter(0);

        $this->assertGreaterThanOrEqual(0, $result);
        $this->assertLessThanOrEqual(1, $result);
    }

    /**
     * Test jitter with negative value
     */
    public function testJitterWithNegativeValue(): void
    {
        $result = $this->service->jitter(-10);

        $this->assertGreaterThanOrEqual(-10, $result);
        $this->assertLessThanOrEqual(-9, $result);
    }

    // =========================================================================
    // fallbackTransportType Tests
    // =========================================================================

    /**
     * Test fallbackTransportType returns matching type when exists in contact info
     */
    public function testFallbackTransportTypeReturnsMatchingType(): void
    {
        $address = 'https://example.com';
        $contactInfo = [
            'https' => 'https://contact.com',
            'http' => 'http://contact.com'
        ];

        $result = $this->service->fallbackTransportType($address, $contactInfo);

        $this->assertEquals('https', $result);
    }

    /**
     * Test fallbackTransportType tries alternative types when primary not available
     */
    public function testFallbackTransportTypeTriesAlternatives(): void
    {
        $address = 'https://example.com'; // HTTPS address
        $contactInfo = [
            'http' => 'http://contact.com', // Only HTTP available
        ];

        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $result = $this->service->fallbackTransportType($address, $contactInfo);

        $this->assertEquals('http', $result);
    }

    // =========================================================================
    // fallbackTransportAddress Tests
    // =========================================================================

    /**
     * Test fallbackTransportAddress returns first available address
     */
    public function testFallbackTransportAddressReturnsFirstAvailable(): void
    {
        $contactInfo = [
            'http' => 'http://contact.com',
            'https' => 'https://contact.com'
        ];

        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $result = $this->service->fallbackTransportAddress($contactInfo);

        $this->assertEquals('http://contact.com', $result);
    }

    /**
     * Test fallbackTransportAddress skips unavailable types
     */
    public function testFallbackTransportAddressSkipsUnavailableTypes(): void
    {
        $contactInfo = [
            'tor' => 'contact.onion'
        ];

        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $result = $this->service->fallbackTransportAddress($contactInfo);

        $this->assertEquals('contact.onion', $result);
    }

    /**
     * Test fallbackTransportAddress returns null when no addresses available
     */
    public function testFallbackTransportAddressReturnsNullWhenNoneAvailable(): void
    {
        $contactInfo = [];

        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $result = $this->service->fallbackTransportAddress($contactInfo);

        $this->assertNull($result);
    }

    // =========================================================================
    // createCurlHandle Tests
    // =========================================================================

    /**
     * Test createCurlHandle returns a CurlHandle for HTTP recipient
     */
    public function testCreateCurlHandleReturnsHandleForHttp(): void
    {
        $this->userContext->method('getPrivateKey')
            ->willReturn(null);

        $handle = $this->service->createCurlHandle('http://example.com', '{"test":"data"}');

        $this->assertInstanceOf(\CurlHandle::class, $handle);
        curl_close($handle);
    }

    /**
     * Test createCurlHandle returns a CurlHandle for Tor recipient
     */
    public function testCreateCurlHandleReturnsHandleForTor(): void
    {
        $handle = $this->service->createCurlHandle('abcdef1234567890.onion', '{"test":"data"}');

        $this->assertInstanceOf(\CurlHandle::class, $handle);
        curl_close($handle);
    }

    /**
     * Test createCurlHandle returns a CurlHandle for HTTPS recipient
     */
    public function testCreateCurlHandleReturnsHandleForHttps(): void
    {
        $handle = $this->service->createCurlHandle('https://secure.example.com', '{"test":"data"}');

        $this->assertInstanceOf(\CurlHandle::class, $handle);
        curl_close($handle);
    }

    // =========================================================================
    // sendBatch Tests
    // =========================================================================

    /**
     * Test sendBatch returns empty array for empty recipients
     */
    public function testSendBatchReturnsEmptyForEmptyRecipients(): void
    {
        $result = $this->service->sendBatch([], ['type' => 'p2p']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // TOR Fallback Tests (isTorFailure via send behavior)
    // =========================================================================

    /**
     * Test isTorFailure detects TOR SOCKS5 error responses
     */
    public function testIsTorFailureDetectsTorErrors(): void
    {
        // Use reflection to test the private method
        $reflection = new \ReflectionMethod($this->service, 'isTorFailure');
        $reflection->setAccessible(true);

        // TOR SOCKS5 connection failure
        $torError = json_encode([
            'status' => 'error',
            'message' => "TOR request failed: Can't complete SOCKS5 connection to xyz.onion. (4)",
            'error_code' => 7
        ]);
        $this->assertTrue($reflection->invoke($this->service, $torError));

        // TOR timeout failure
        $torTimeout = json_encode([
            'status' => 'error',
            'message' => 'TOR request failed: Connection timed out',
            'error_code' => 28
        ]);
        $this->assertTrue($reflection->invoke($this->service, $torTimeout));
    }

    /**
     * Test isTorFailure returns false for successful responses
     */
    public function testIsTorFailureReturnsFalseForSuccess(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'isTorFailure');
        $reflection->setAccessible(true);

        // Successful response
        $success = json_encode(['status' => 'received', 'message' => 'ok']);
        $this->assertFalse($reflection->invoke($this->service, $success));

        // HTTP error (not TOR-specific)
        $httpError = json_encode([
            'status' => 'error',
            'message' => 'HTTP request failed: Connection refused',
            'error_code' => 7
        ]);
        $this->assertFalse($reflection->invoke($this->service, $httpError));

        // Non-JSON response
        $this->assertFalse($reflection->invoke($this->service, 'not json'));
    }

    /**
     * Test sendByTor writes tor-gui-status file on SOCKS5 failure
     *
     * sendByTor() calls curl against 127.0.0.1:9050 SOCKS5 proxy. When the
     * connection fails with errno 7 or a "SOCKS5" error string, it writes
     * a JSON status file to /tmp/tor-gui-status so the GUI can display a
     * notification to the user.
     *
     * This test invokes sendByTor() against an unreachable .onion address,
     * which triggers the SOCKS5 failure path (no Tor daemon running in test).
     */
    public function testSendByTorWritesGuiStatusFileOnSocks5Failure(): void
    {
        $statusFile = '/tmp/tor-gui-status';
        // Clean up any pre-existing status file
        @unlink($statusFile);

        // Use reflection to call the private sendByTor method
        $reflection = new \ReflectionMethod($this->service, 'sendByTor');
        $reflection->setAccessible(true);

        // Call sendByTor with unreachable .onion address — will fail at SOCKS5
        $result = $reflection->invoke($this->service, 'unreachable-test.onion', '{"test":"data"}');

        // The method should return a JSON error response
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('error', $decoded['status']);

        // Verify the GUI status file was written
        $this->assertFileExists($statusFile);

        $statusData = json_decode(file_get_contents($statusFile), true);
        $this->assertIsArray($statusData);
        $this->assertEquals('issue', $statusData['status']);
        $this->assertArrayHasKey('timestamp', $statusData);
        $this->assertArrayHasKey('message', $statusData);

        // Clean up
        @unlink($statusFile);
    }

    /**
     * Test attemptFallbackDelivery returns null when no pubkey hash found
     */
    public function testAttemptFallbackDeliveryReturnsNullWhenNoPubkeyHash(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'attemptFallbackDelivery');
        $reflection->setAccessible(true);

        $this->addressRepository->expects($this->once())
            ->method('getContactPubkeyHash')
            ->with('tor', 'unknown.onion')
            ->willReturn(null);

        $result = $reflection->invoke($this->service, 'unknown.onion', '{"test":"data"}');
        $this->assertNull($result);
    }

    /**
     * Test attemptFallbackDelivery returns null when no non-TOR addresses exist
     */
    public function testAttemptFallbackDeliveryReturnsNullWhenNoFallbackAddress(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'attemptFallbackDelivery');
        $reflection->setAccessible(true);

        $this->addressRepository->method('getContactPubkeyHash')
            ->willReturn('hash123');

        $this->addressRepository->method('lookupByPubkeyHash')
            ->willReturn(['tor' => 'contact.onion']); // Only TOR, no HTTP/HTTPS

        $this->addressRepository->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $result = $reflection->invoke($this->service, 'contact.onion', '{"test":"data"}');
        $this->assertNull($result);
    }

    /**
     * Test attemptFallbackDelivery excludes HTTP when torFallbackRequireEncrypted is true
     */
    public function testAttemptFallbackDeliveryExcludesHttpWhenRequireEncrypted(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'attemptFallbackDelivery');
        $reflection->setAccessible(true);

        $this->addressRepository->method('getContactPubkeyHash')
            ->willReturn('hash123');

        // Contact has only HTTP (no HTTPS) — should return null when require encrypted
        $this->addressRepository->method('lookupByPubkeyHash')
            ->willReturn(['tor' => 'contact.onion', 'http' => 'http://contact.com']);

        $this->addressRepository->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        // torFallbackRequireEncrypted defaults to true in Constants
        $result = $reflection->invoke($this->service, 'contact.onion', '{"test":"data"}');
        $this->assertNull($result);
    }
}
