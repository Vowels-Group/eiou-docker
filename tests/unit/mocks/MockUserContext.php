<?php
/**
 * Mock UserContext class for unit testing
 *
 * Provides a simplified UserContext implementation for testing services
 * that depend on user context without requiring full initialization.
 */

namespace Tests\Unit\Mocks;

class MockUserContext
{
    private string $currentAddress;
    private array $addresses = [];
    private array $contacts = [];

    public function __construct(string $defaultAddress = 'test-address-123')
    {
        $this->currentAddress = $defaultAddress;
        $this->addresses = [$defaultAddress];
    }

    public function getCurrentAddress(): string
    {
        return $this->currentAddress;
    }

    public function setCurrentAddress(string $address): void
    {
        $this->currentAddress = $address;
        if (!in_array($address, $this->addresses)) {
            $this->addresses[] = $address;
        }
    }

    public function getAddresses(): array
    {
        return $this->addresses;
    }

    public function addAddress(string $address): void
    {
        if (!in_array($address, $this->addresses)) {
            $this->addresses[] = $address;
        }
    }

    public function addContact(string $address, string $name): void
    {
        $this->contacts[$address] = [
            'address' => $address,
            'name' => $name,
            'added_at' => time()
        ];
    }

    public function getContact(string $address): ?array
    {
        return $this->contacts[$address] ?? null;
    }

    public function getAllContacts(): array
    {
        return array_values($this->contacts);
    }

    public function hasContact(string $address): bool
    {
        return isset($this->contacts[$address]);
    }
}
