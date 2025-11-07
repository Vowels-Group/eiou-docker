<?php
/**
 * Contact Model Unit Tests
 *
 * Copyright 2025
 */

namespace Eiou\Tests\Unit\Gui\Models;

use PHPUnit\Framework\TestCase;
use Eiou\Gui\Models\Contact;

/**
 * Test Contact Model
 */
class ContactTest extends TestCase
{
    private $serviceContainerMock;
    private Contact $contactModel;

    protected function setUp(): void
    {
        // Mock ServiceContainer
        $this->serviceContainerMock = $this->createMock(\ServiceContainer::class);

        // Create Contact model with mocked container
        $this->contactModel = new Contact($this->serviceContainerMock);
    }

    /**
     * Test getAllContacts returns array of contacts
     */
    public function testGetAllContactsReturnsArray(): void
    {
        $expectedContacts = [
            [
                'address' => 'test-address-1',
                'name' => 'Alice',
                'fee' => 1.5,
                'credit' => 1000.00,
                'blocked' => false
            ],
            [
                'address' => 'test-address-2',
                'name' => 'Bob',
                'fee' => 2.0,
                'credit' => 500.00,
                'blocked' => false
            ]
        ];

        $contactServiceMock = $this->createMock(\ContactService::class);
        $contactServiceMock->method('getAllContacts')
            ->willReturn($expectedContacts);

        $this->serviceContainerMock->method('getContactService')
            ->willReturn($contactServiceMock);

        $contacts = $this->contactModel->getAllContacts();

        $this->assertCount(2, $contacts);
        $this->assertEquals($expectedContacts, $contacts);
    }

    /**
     * Test getAllContacts filters blocked contacts by default
     */
    public function testGetAllContactsFiltersBlockedByDefault(): void
    {
        $allContacts = [
            [
                'address' => 'test-address-1',
                'name' => 'Alice',
                'blocked' => false
            ],
            [
                'address' => 'test-address-2',
                'name' => 'Bob',
                'blocked' => true
            ]
        ];

        $contactServiceMock = $this->createMock(\ContactService::class);
        $contactServiceMock->method('getAllContacts')
            ->willReturn($allContacts);

        $this->serviceContainerMock->method('getContactService')
            ->willReturn($contactServiceMock);

        $contacts = $this->contactModel->getAllContacts(false);

        $this->assertCount(1, $contacts);
        $this->assertEquals('Alice', $contacts[0]['name']);
    }

    /**
     * Test validate returns valid for correct data
     */
    public function testValidateReturnsValidForCorrectData(): void
    {
        $data = [
            'address' => 'http://example.onion',
            'name' => 'Test Contact',
            'fee' => 1.5,
            'credit' => 1000.00,
            'currency' => 'USD'
        ];

        $result = $this->contactModel->validate($data);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test validate returns errors for missing required fields
     */
    public function testValidateReturnsErrorsForMissingFields(): void
    {
        $data = [
            'address' => 'http://example.onion'
            // Missing: name, fee, credit, currency
        ];

        $result = $this->contactModel->validate($data);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertArrayHasKey('name', $result['errors']);
        $this->assertArrayHasKey('fee', $result['errors']);
        $this->assertArrayHasKey('credit', $result['errors']);
        $this->assertArrayHasKey('currency', $result['errors']);
    }

    /**
     * Test getCount returns correct contact count
     */
    public function testGetCountReturnsCorrectCount(): void
    {
        $contacts = [
            ['address' => 'addr1', 'name' => 'Alice', 'blocked' => false],
            ['address' => 'addr2', 'name' => 'Bob', 'blocked' => false],
            ['address' => 'addr3', 'name' => 'Carol', 'blocked' => true]
        ];

        $contactServiceMock = $this->createMock(\ContactService::class);
        $contactServiceMock->method('getAllContacts')
            ->willReturn($contacts);

        $this->serviceContainerMock->method('getContactService')
            ->willReturn($contactServiceMock);

        // Count without blocked
        $count = $this->contactModel->getCount(false);
        $this->assertEquals(2, $count);

        // Count with blocked
        $count = $this->contactModel->getCount(true);
        $this->assertEquals(3, $count);
    }

    /**
     * Test search finds contacts by name
     */
    public function testSearchFindsContactsByName(): void
    {
        $contacts = [
            ['address' => 'addr1', 'name' => 'Alice Smith', 'blocked' => false],
            ['address' => 'addr2', 'name' => 'Bob Jones', 'blocked' => false],
            ['address' => 'addr3', 'name' => 'Carol Smith', 'blocked' => false]
        ];

        $contactServiceMock = $this->createMock(\ContactService::class);
        $contactServiceMock->method('getAllContacts')
            ->willReturn($contacts);

        $this->serviceContainerMock->method('getContactService')
            ->willReturn($contactServiceMock);

        $results = $this->contactModel->search('Smith');

        $this->assertCount(2, $results);
    }

    /**
     * Test search finds contacts by address
     */
    public function testSearchFindsContactsByAddress(): void
    {
        $contacts = [
            ['address' => 'http://alice.onion', 'name' => 'Alice', 'blocked' => false],
            ['address' => 'http://bob.onion', 'name' => 'Bob', 'blocked' => false],
            ['address' => 'http://carol.onion', 'name' => 'Carol', 'blocked' => false]
        ];

        $contactServiceMock = $this->createMock(\ContactService::class);
        $contactServiceMock->method('getAllContacts')
            ->willReturn($contacts);

        $this->serviceContainerMock->method('getContactService')
            ->willReturn($contactServiceMock);

        $results = $this->contactModel->search('alice');

        $this->assertCount(1, $results);
        $this->assertEquals('Alice', $results[0]['name']);
    }
}
