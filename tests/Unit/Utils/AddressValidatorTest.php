<?php
/**
 * Unit Tests for AddressValidator
 *
 * Tests network address validation for HTTP, HTTPS, and Tor addresses.
 */

namespace Eiou\Tests\Utils;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Utils\AddressValidator;

#[CoversClass(AddressValidator::class)]
class AddressValidatorTest extends TestCase
{
    /**
     * Test isHttpsAddress with valid HTTPS addresses
     */
    public function testIsHttpsAddressWithValidHttps(): void
    {
        $this->assertTrue(AddressValidator::isHttpsAddress('https://example.com'));
        $this->assertTrue(AddressValidator::isHttpsAddress('https://example.com:443'));
        $this->assertTrue(AddressValidator::isHttpsAddress('https://sub.domain.example.com/path'));
    }

    /**
     * Test isHttpsAddress with non-HTTPS addresses
     */
    public function testIsHttpsAddressWithNonHttps(): void
    {
        $this->assertFalse(AddressValidator::isHttpsAddress('http://example.com'));
        $this->assertFalse(AddressValidator::isHttpsAddress('ftp://example.com'));
        $this->assertFalse(AddressValidator::isHttpsAddress('example.com'));
    }

    /**
     * Test isHttpAddress with valid HTTP addresses
     */
    public function testIsHttpAddressWithValidHttp(): void
    {
        $this->assertTrue(AddressValidator::isHttpAddress('http://example.com'));
        $this->assertTrue(AddressValidator::isHttpAddress('http://localhost:8080'));
        $this->assertTrue(AddressValidator::isHttpAddress('http://192.168.1.1/api'));
    }

    /**
     * Test isHttpAddress excludes HTTPS
     */
    public function testIsHttpAddressExcludesHttps(): void
    {
        $this->assertFalse(AddressValidator::isHttpAddress('https://example.com'));
    }

    /**
     * Test isHttpAddress with non-HTTP addresses
     */
    public function testIsHttpAddressWithNonHttp(): void
    {
        $this->assertFalse(AddressValidator::isHttpAddress('ftp://example.com'));
        $this->assertFalse(AddressValidator::isHttpAddress('example.com'));
    }

    /**
     * Test isTorAddress with valid Tor addresses
     */
    public function testIsTorAddressWithValidTor(): void
    {
        $this->assertTrue(AddressValidator::isTorAddress('http://example.onion'));
        $this->assertTrue(AddressValidator::isTorAddress('https://abcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuv.onion'));
        $this->assertTrue(AddressValidator::isTorAddress('facebookcorewwwi.onion'));
    }

    /**
     * Test isTorAddress with non-Tor addresses
     */
    public function testIsTorAddressWithNonTor(): void
    {
        $this->assertFalse(AddressValidator::isTorAddress('https://example.com'));
        $this->assertFalse(AddressValidator::isTorAddress('example.onion.com'));
        $this->assertFalse(AddressValidator::isTorAddress('http://onion.example.com'));
    }

    /**
     * Test isAddress with valid addresses
     */
    public function testIsAddressWithValidAddresses(): void
    {
        $this->assertTrue(AddressValidator::isAddress('http://example.com'));
        $this->assertTrue(AddressValidator::isAddress('https://example.com'));
        $this->assertTrue(AddressValidator::isAddress('http://example.onion'));
    }

    /**
     * Test isAddress with invalid addresses
     */
    public function testIsAddressWithInvalidAddresses(): void
    {
        $this->assertFalse(AddressValidator::isAddress('ftp://example.com'));
        $this->assertFalse(AddressValidator::isAddress('just-a-string'));
        $this->assertFalse(AddressValidator::isAddress(''));
    }

    /**
     * Test getTransportType returns correct types
     */
    public function testGetTransportTypeReturnsCorrectTypes(): void
    {
        $this->assertEquals('https', AddressValidator::getTransportType('https://example.com'));
        $this->assertEquals('http', AddressValidator::getTransportType('http://example.com'));
        $this->assertEquals('tor', AddressValidator::getTransportType('http://example.onion'));
        $this->assertEquals('tor', AddressValidator::getTransportType('https://example.onion'));
    }

    /**
     * Test getTransportType returns null for unknown types
     */
    public function testGetTransportTypeReturnsNullForUnknown(): void
    {
        $this->assertNull(AddressValidator::getTransportType('ftp://example.com'));
        $this->assertNull(AddressValidator::getTransportType('invalid'));
    }

    /**
     * Test Tor takes precedence over HTTP/HTTPS in getTransportType
     */
    public function testTorTakesPrecedenceInGetTransportType(): void
    {
        // Even with http/https prefix, .onion should be detected as tor
        $this->assertEquals('tor', AddressValidator::getTransportType('http://hidden.onion'));
        $this->assertEquals('tor', AddressValidator::getTransportType('https://secure.onion'));
    }

    /**
     * Test categorizeAddress returns correct array
     */
    public function testCategorizeAddressReturnsCorrectArray(): void
    {
        $httpsResult = AddressValidator::categorizeAddress('https://example.com');
        $this->assertEquals(['https' => 'https://example.com'], $httpsResult);

        $httpResult = AddressValidator::categorizeAddress('http://example.com');
        $this->assertEquals(['http' => 'http://example.com'], $httpResult);

        $torResult = AddressValidator::categorizeAddress('http://hidden.onion');
        $this->assertEquals(['tor' => 'http://hidden.onion'], $torResult);
    }

    /**
     * Test categorizeAddress returns null for unknown types
     */
    public function testCategorizeAddressReturnsNullForUnknown(): void
    {
        $this->assertNull(AddressValidator::categorizeAddress('ftp://example.com'));
        $this->assertNull(AddressValidator::categorizeAddress('invalid-address'));
    }

    /**
     * Test addresses with ports
     */
    public function testAddressesWithPorts(): void
    {
        $this->assertTrue(AddressValidator::isHttpAddress('http://localhost:8080'));
        $this->assertTrue(AddressValidator::isHttpsAddress('https://api.example.com:443'));
        $this->assertEquals('http', AddressValidator::getTransportType('http://127.0.0.1:3000'));
    }

    /**
     * Test addresses with paths
     */
    public function testAddressesWithPaths(): void
    {
        $this->assertTrue(AddressValidator::isHttpsAddress('https://example.com/api/v1/users'));
        $this->assertTrue(AddressValidator::isHttpAddress('http://example.com/path?query=value'));
    }

    /**
     * Test case sensitivity of protocol detection
     */
    public function testCaseSensitivityOfProtocol(): void
    {
        // Protocol matching is case-insensitive based on regex
        $this->assertTrue(AddressValidator::isHttpsAddress('https://example.com'));
        // But uppercase would fail the current regex
        $this->assertFalse(AddressValidator::isHttpsAddress('HTTPS://example.com'));
    }

    /**
     * Test empty string handling
     */
    public function testEmptyStringHandling(): void
    {
        $this->assertFalse(AddressValidator::isHttpAddress(''));
        $this->assertFalse(AddressValidator::isHttpsAddress(''));
        $this->assertFalse(AddressValidator::isTorAddress(''));
        $this->assertFalse(AddressValidator::isAddress(''));
        $this->assertNull(AddressValidator::getTransportType(''));
        $this->assertNull(AddressValidator::categorizeAddress(''));
    }
}
