<?php

declare(strict_types=1);

namespace Tests\Unit\Schemas\Payloads;

use PHPUnit\Framework\TestCase;
use EIOU\Schemas\Payloads\ContactPayload;
use EIOU\Context\UserContext;

class ContactPayloadTest extends TestCase
{
    private ContactPayload $payload;
    private UserContext $mockContext;
    private $mockUser;

    protected function setUp(): void
    {
        // Create mock user
        $this->mockUser = $this->createMock(\stdClass::class);
        $this->mockUser->method('getAddress')->willReturn('test_address_123');
        $this->mockUser->method('getPublicKey')->willReturn('test_public_key_456');
        $this->mockUser->method('getName')->willReturn('Test User');

        // Create mock context
        $this->mockContext = $this->createMock(UserContext::class);
        $this->mockContext->method('getUser')->willReturn($this->mockUser);
        $this->mockContext->method('getNodeId')->willReturn('node_001');

        // Create payload instance
        $this->payload = new ContactPayload($this->mockContext);
    }

    public function testBuildCreateRequest(): void
    {
        $result = $this->payload->buildCreateRequest();

        $this->assertIsArray($result);
        $this->assertEquals('create', $result['type']);
        $this->assertEquals('test_public_key_456', $result['senderPublicKey']);
    }

    public function testBuildWithEmptyData(): void
    {
        $result = $this->payload->build([]);

        $this->assertIsArray($result);
        $this->assertEquals('create', $result['type']);
        $this->assertEquals('test_public_key_456', $result['senderPublicKey']);
    }

    public function testBuildAccepted(): void
    {
        $address = 'recipient_address';
        $result = $this->payload->buildAccepted($address);

        $this->assertIsArray($result);
        $this->assertEquals('message', $result['type']);
        $this->assertEquals('contact', $result['typeMessage']);
        $this->assertEquals('accepted', $result['status']);
        $this->assertEquals('test_address_123', $result['senderAddress']);
        $this->assertEquals('test_public_key_456', $result['senderPublicKey']);
        $this->assertStringContainsString('confirms that we are contacts', $result['message']);
    }

    public function testBuildAlreadyExists(): void
    {
        $result = $this->payload->buildAlreadyExists();

        $this->assertIsArray($result);
        $this->assertEquals('warning', $result['status']);
        $this->assertEquals('Contact already exists', $result['message']);
        $this->assertEquals('test_public_key_456', $result['myPublicKey']);
    }

    public function testBuildRejection(): void
    {
        $result = $this->payload->buildRejection();

        $this->assertIsArray($result);
        $this->assertEquals('message', $result['type']);
        $this->assertEquals('contact', $result['typeMessage']);
        $this->assertEquals('rejected', $result['status']);
        $this->assertEquals('test_address_123', $result['senderAddress']);
        $this->assertEquals('test_public_key_456', $result['senderPublicKey']);
        $this->assertEquals('Contact request rejected', $result['message']);
    }

    public function testBuildRejectionWithCustomReason(): void
    {
        $customReason = 'User is not verified';
        $result = $this->payload->buildRejection($customReason);

        $this->assertIsArray($result);
        $this->assertEquals('rejected', $result['status']);
        $this->assertEquals($customReason, $result['message']);
    }

    public function testBuildPending(): void
    {
        $address = 'pending_address';
        $result = $this->payload->buildPending($address);

        $this->assertIsArray($result);
        $this->assertEquals('message', $result['type']);
        $this->assertEquals('contact', $result['typeMessage']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('test_address_123', $result['senderAddress']);
        $this->assertStringContainsString($address, $result['message']);
        $this->assertStringContainsString('is pending', $result['message']);
    }

    public function testWithNullUser(): void
    {
        // Create context with null user
        $nullContext = $this->createMock(UserContext::class);
        $nullContext->method('getUser')->willReturn(null);

        $payload = new ContactPayload($nullContext);
        $result = $payload->build([]);

        $this->assertIsArray($result);
        $this->assertEquals('create', $result['type']);
        $this->assertNull($result['senderPublicKey']);
    }
}