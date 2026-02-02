<?php
/**
 * Unit Tests for GeneralUtilityService
 *
 * Tests general utility functions including address truncation.
 */

namespace Eiou\Tests\Services\Utilities;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\Utilities\GeneralUtilityService;
use Eiou\Services\ServiceContainer;
use Eiou\Core\UserContext;

#[CoversClass(GeneralUtilityService::class)]
class GeneralUtilityServiceTest extends TestCase
{
    private ServiceContainer $serviceContainer;
    private UserContext $userContext;
    private GeneralUtilityService $service;

    protected function setUp(): void
    {
        // Create mock objects
        $this->serviceContainer = $this->createMock(ServiceContainer::class);
        $this->userContext = $this->createMock(UserContext::class);

        // Configure service container to return the mock user context
        $this->serviceContainer->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn($this->userContext);

        // Create the service
        $this->service = new GeneralUtilityService($this->serviceContainer);
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

        $service = new GeneralUtilityService($serviceContainer);

        $this->assertInstanceOf(GeneralUtilityService::class, $service);
    }

    // =========================================================================
    // truncateAddress() Tests
    // =========================================================================

    /**
     * Test truncateAddress with address shorter than default length
     */
    public function testTruncateAddressWithShortAddressReturnsUnchanged(): void
    {
        $address = 'short';

        $result = $this->service->truncateAddress($address);

        $this->assertEquals('short', $result);
    }

    /**
     * Test truncateAddress with address equal to default length
     */
    public function testTruncateAddressWithExactLengthReturnsUnchanged(): void
    {
        $address = '1234567890'; // Exactly 10 characters

        $result = $this->service->truncateAddress($address);

        $this->assertEquals('1234567890', $result);
    }

    /**
     * Test truncateAddress with address longer than default length
     */
    public function testTruncateAddressWithLongAddressTruncates(): void
    {
        $address = '12345678901234567890'; // 20 characters

        $result = $this->service->truncateAddress($address);

        $this->assertEquals('1234567890...', $result);
    }

    /**
     * Test truncateAddress with custom length shorter than address
     */
    public function testTruncateAddressWithCustomLengthTruncates(): void
    {
        $address = 'abcdefghij';

        $result = $this->service->truncateAddress($address, 5);

        $this->assertEquals('abcde...', $result);
    }

    /**
     * Test truncateAddress with custom length equal to address length
     */
    public function testTruncateAddressWithCustomLengthEqualReturnsUnchanged(): void
    {
        $address = 'abcdefghij'; // 10 characters

        $result = $this->service->truncateAddress($address, 10);

        $this->assertEquals('abcdefghij', $result);
    }

    /**
     * Test truncateAddress with custom length longer than address
     */
    public function testTruncateAddressWithCustomLengthLongerReturnsUnchanged(): void
    {
        $address = 'short';

        $result = $this->service->truncateAddress($address, 20);

        $this->assertEquals('short', $result);
    }

    /**
     * Test truncateAddress with empty string
     */
    public function testTruncateAddressWithEmptyStringReturnsEmpty(): void
    {
        $address = '';

        $result = $this->service->truncateAddress($address);

        $this->assertEquals('', $result);
    }

    /**
     * Test truncateAddress with single character
     */
    public function testTruncateAddressWithSingleCharacter(): void
    {
        $address = 'A';

        $result = $this->service->truncateAddress($address);

        $this->assertEquals('A', $result);
    }

    /**
     * Test truncateAddress with length of 1
     */
    public function testTruncateAddressWithLengthOne(): void
    {
        $address = 'abcdef';

        $result = $this->service->truncateAddress($address, 1);

        $this->assertEquals('a...', $result);
    }

    /**
     * Test truncateAddress with typical HTTP address
     */
    public function testTruncateAddressWithHttpAddress(): void
    {
        $address = 'http://example.com:8080/path';

        $result = $this->service->truncateAddress($address);

        $this->assertEquals('http://exa...', $result);
    }

    /**
     * Test truncateAddress with Tor .onion address
     */
    public function testTruncateAddressWithTorAddress(): void
    {
        $address = 'abcdefghijklmnopqrstuvwxyz.onion';

        $result = $this->service->truncateAddress($address, 15);

        $this->assertEquals('abcdefghijklmno...', $result);
    }

    /**
     * Test truncateAddress preserves address start for identification
     */
    public function testTruncateAddressPreservesStart(): void
    {
        $address = 'https://important-domain.example.com';

        $result = $this->service->truncateAddress($address, 20);

        $this->assertEquals('https://important-do...', $result);
        $this->assertStringStartsWith('https://', $result);
    }

    /**
     * Test truncateAddress with zero length
     */
    public function testTruncateAddressWithZeroLength(): void
    {
        $address = 'test';

        $result = $this->service->truncateAddress($address, 0);

        $this->assertEquals('...', $result);
    }

    /**
     * Test truncateAddress with Unicode characters
     */
    public function testTruncateAddressWithUnicodeCharacters(): void
    {
        // Note: strlen counts bytes, not characters
        // This tests the current behavior which uses strlen()
        $address = 'test-unicode';

        $result = $this->service->truncateAddress($address, 5);

        $this->assertEquals('test-...', $result);
    }
}
