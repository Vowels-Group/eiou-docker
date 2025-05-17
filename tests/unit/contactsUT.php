<?php

require_once(__DIR__ . '/../../src/functions/contacts.php');

class ContactsTest extends PHPUnit\Framework\TestCase {

    protected function setUp(): void {
        // Set up test database connection
        global $user;
        $user = [
            'public' => 'test_public_key',
            'torAddress' => 'test.onion',
            'hostname' => 'http://test.local'
        ];
    }

    public function testAddContactValidatesInput() {
        $data = [
            'add',
            'contact', 
            'invalid address',
            'Test Name',
            '1.5',
            '100',
            'USD'
        ];

        $this->expectOutputString('ERROR: Invalid input parameters for adding contact');
        $this->expectException(Exception::class);
        addContact($data);
    }

    public function testHandleContactCreation() {
        $request = [
            'senderAddress' => 'test.onion',
            'senderPublicKey' => 'test_public_key'
        ];

        $result = handleContactCreation($request);
        $this->assertJson($result);
        
        $decoded = json_decode($result, true);
        $this->assertArrayHasKey('status', $decoded);
    }

    public function testLookupContactInfo() {
        $request = [
            'name' => 'Test Contact',
            'address' => 'test.onion'
        ];

        $result = lookupContactInfo($request);
        $this->assertIsArray($result);
    }

    public function testReadContactValidatesInput() {
        $data = ['read', 'contact'];
        
        $this->expectOutputString('ERROR: Invalid input parameters for viewing contact');
        viewContact($data);
    }

    public function testSearchContacts() {
        $data = ['search', 'contacts', 'test'];
        
        $this->expectOutputRegex('/Contact search results/');
        searchContacts($data);
    }

}
