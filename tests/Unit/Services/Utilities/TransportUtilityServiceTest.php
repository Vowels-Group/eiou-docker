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

        $this->serviceContainer->expects($this->any())
            ->method('getAddressRepository')
            ->willReturn($this->addressRepository);

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
}
