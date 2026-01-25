<?php

namespace Eiou\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Eiou\Contracts\TransportServiceInterface;
use Eiou\Contracts\ContactServiceInterface;

/**
 * Demonstrates mock injection capability with service interfaces.
 * This test validates that interfaces enable proper dependency injection for testing.
 */
class ServiceInterfaceTest extends TestCase
{
    /**
     * Test that TransportServiceInterface can be mocked and injected.
     */
    public function testTransportServiceInterfaceCanBeMocked(): void
    {
        $mockTransport = $this->createMock(TransportServiceInterface::class);

        // Configure mock to return expected values
        $mockTransport->method('isHttpAddress')
            ->with('http://example.com')
            ->willReturn(true);

        $mockTransport->method('isTorAddress')
            ->with('http://example.onion')
            ->willReturn(true);

        $mockTransport->method('determineTransportType')
            ->with('http://example.com')
            ->willReturn('http');

        // Assert mock behaves as expected
        $this->assertTrue($mockTransport->isHttpAddress('http://example.com'));
        $this->assertTrue($mockTransport->isTorAddress('http://example.onion'));
        $this->assertEquals('http', $mockTransport->determineTransportType('http://example.com'));
    }

    /**
     * Test that ContactServiceInterface can be mocked.
     */
    public function testContactServiceInterfaceCanBeMocked(): void
    {
        $mockContact = $this->createMock(ContactServiceInterface::class);

        $mockContact->method('contactExists')
            ->with('http://test-address')
            ->willReturn(true);

        $mockContact->method('getAllContacts')
            ->willReturn([
                ['name' => 'Alice', 'address' => 'http://alice'],
                ['name' => 'Bob', 'address' => 'http://bob']
            ]);

        $this->assertTrue($mockContact->contactExists('http://test-address'));
        $this->assertCount(2, $mockContact->getAllContacts());
    }

    /**
     * Test interface type hints work correctly.
     */
    public function testInterfaceTypeHintsWork(): void
    {
        $mockTransport = $this->createMock(TransportServiceInterface::class);

        // This method accepts the interface, proving type compatibility
        $result = $this->processWithTransport($mockTransport);

        $this->assertTrue($result);
    }

    /**
     * Helper method that type-hints the interface.
     */
    private function processWithTransport(TransportServiceInterface $transport): bool
    {
        // This would fail if the mock doesn't implement the interface
        return $transport instanceof TransportServiceInterface;
    }
}
