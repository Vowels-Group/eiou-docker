<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Cli\CliOutputManager;
use Eiou\Contracts\ContactServiceInterface;
use Eiou\Contracts\ContactManagementServiceInterface;
use Eiou\Contracts\ContactSyncServiceInterface;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Exceptions\ValidationServiceException; // Used by delegated services

/**
 * Contact Service Facade
 *
 * Thin facade that composes ContactManagementService and ContactSyncService.
 * Maintains backward compatibility with existing ContactServiceInterface.
 *
 * This facade delegates all contact operations to the appropriate service:
 * - ContactManagementService: CRUD operations, status management, lookups
 * - ContactSyncService: P2P exchange, message delivery, transaction chains
 *
 * @see ContactManagementServiceInterface for management operations
 * @see ContactSyncServiceInterface for sync/exchange operations
 */
class ContactService implements ContactServiceInterface
{
    /**
     * @var ContactManagementServiceInterface Contact management service
     */
    private ContactManagementServiceInterface $managementService;

    /**
     * @var ContactSyncServiceInterface Contact sync service
     */
    private ContactSyncServiceInterface $syncService;

    /**
     * Constructor
     *
     * @param ContactManagementServiceInterface $managementService Management service for CRUD operations
     * @param ContactSyncServiceInterface $syncService Sync service for P2P exchange operations
     */
    public function __construct(
        ContactManagementServiceInterface $managementService,
        ContactSyncServiceInterface $syncService
    ) {
        $this->managementService = $managementService;
        $this->syncService = $syncService;
    }

    // =========================================================================
    // SYNC SERVICE DEPENDENCY INJECTION
    // =========================================================================

    /**
     * Set the sync trigger (accepts interface for loose coupling)
     *
     * @param SyncTriggerInterface $sync Sync trigger (can be proxy or actual service)
     */
    public function setSyncTrigger(SyncTriggerInterface $sync): void
    {
        $this->syncService->setSyncTrigger($sync);
    }

    /**
     * Set the message delivery service (for lazy initialization)
     *
     * @param MessageDeliveryService $service Message delivery service
     */
    public function setMessageDeliveryService(MessageDeliveryService $service): void
    {
        $this->syncService->setMessageDeliveryService($service);
    }

    // =========================================================================
    // CONTACT ADDITION (Entry Point)
    // =========================================================================

    /**
     * Add a new contact.
     *
     * @param array $data The contact data
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function addContact(array $data, ?CliOutputManager $output = null): void
    {
        $this->managementService->addContact($data, $output);
    }

    /**
     * Accept a pending contact request.
     *
     * @param string $pubkey The public key of the contact
     * @param string $name The name to assign to the contact
     * @param float $fee The transaction fee for this contact
     * @param float $credit The credit limit for this contact
     * @param string $currency The currency code
     * @return bool True if contact was accepted successfully
     */
    public function acceptContact(string $pubkey, string $name, float $fee, float $credit, string $currency): bool
    {
        return $this->managementService->acceptContact($pubkey, $name, $fee, $credit, $currency);
    }

    // =========================================================================
    // LOOKUP OPERATIONS
    // =========================================================================

    /**
     * Lookup contact information from a request.
     *
     * @param mixed $request The lookup request data
     * @return array|null The contact information or null if not found
     */
    public function lookupContactInfo($request): ?array
    {
        return $this->managementService->lookupContactInfo($request);
    }

    /**
     * Lookup contact information with disambiguation for duplicate names.
     *
     * @param mixed $request The lookup request data (name or address)
     * @param CliOutputManager|null $output Output manager for interactive prompt / JSON error
     * @return array|null The contact information or null if not found/cancelled
     */
    public function lookupContactInfoWithDisambiguation($request, ?CliOutputManager $output = null): ?array
    {
        return $this->managementService->lookupContactInfoWithDisambiguation($request, $output);
    }

    /**
     * Lookup a contact by name.
     *
     * @param string $name The contact name to search for
     * @return array|null The contact data or null if not found
     */
    public function lookupByName(string $name): ?array
    {
        return $this->managementService->lookupByName($name);
    }

    /**
     * Lookup a contact by transport address.
     *
     * @param string $transportIndex The transport index type
     * @param string $address The address to search for
     * @return array|null The contact data or null if not found
     */
    public function lookupByAddress(string $transportIndex, string $address): ?array
    {
        return $this->managementService->lookupByAddress($transportIndex, $address);
    }

    /**
     * Search contacts with criteria.
     *
     * @param array $data Search criteria
     * @param CliOutputManager|null $output Optional CLI output manager for results
     * @return void
     */
    public function searchContacts(array $data, ?CliOutputManager $output = null): void
    {
        $this->managementService->searchContacts($data, $output);
    }

    /**
     * View detailed information about a contact.
     *
     * @param array $data Contact identifier data
     * @param CliOutputManager|null $output Optional CLI output manager for display
     * @return void
     */
    public function viewContact(array $data, ?CliOutputManager $output = null): void
    {
        $this->managementService->viewContact($data, $output);
    }

    // =========================================================================
    // EXISTENCE CHECKS
    // =========================================================================

    /**
     * Check if a contact exists by address.
     *
     * @param string $address The address to check
     * @return bool True if contact exists
     */
    public function contactExists(string $address): bool
    {
        return $this->managementService->contactExists($address);
    }

    /**
     * Check if a contact exists by public key.
     *
     * @param string $pubkey The public key to check
     * @return bool True if contact exists
     */
    public function contactExistsPubkey(string $pubkey): bool
    {
        return $this->managementService->contactExistsPubkey($pubkey);
    }

    /**
     * Check if a contact is accepted by public key.
     *
     * @param string $pubkey The public key to check
     * @return bool True if contact is accepted
     */
    public function isAcceptedContactPubkey(string $pubkey): bool
    {
        return $this->managementService->isAcceptedContactPubkey($pubkey);
    }

    /**
     * Check if a contact is not blocked.
     *
     * @param string $pubkey The public key to check
     * @return bool True if contact is not blocked
     */
    public function isNotBlocked(string $pubkey): bool
    {
        return $this->managementService->isNotBlocked($pubkey);
    }

    // =========================================================================
    // STATUS MANAGEMENT
    // =========================================================================

    /**
     * Block a contact.
     *
     * @param string|null $addressOrName The address or name of the contact to block
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return bool True if contact was blocked successfully
     */
    public function blockContact(?string $addressOrName, ?CliOutputManager $output = null): bool
    {
        return $this->managementService->blockContact($addressOrName, $output);
    }

    /**
     * Unblock a contact.
     *
     * @param string|null $addressOrName The address or name of the contact to unblock
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return bool True if contact was unblocked successfully
     */
    public function unblockContact(?string $addressOrName, ?CliOutputManager $output = null): bool
    {
        return $this->managementService->unblockContact($addressOrName, $output);
    }

    /**
     * Delete a contact.
     *
     * @param string|null $addressOrName The address or name of the contact to delete
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return bool True if contact was deleted successfully
     */
    public function deleteContact(?string $addressOrName, ?CliOutputManager $output = null): bool
    {
        return $this->managementService->deleteContact($addressOrName, $output);
    }

    /**
     * Update contact information.
     *
     * @param array $argv Update parameters
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function updateContact(array $argv, ?CliOutputManager $output = null): void
    {
        $this->managementService->updateContact($argv, $output);
    }

    /**
     * Update the status of a contact.
     *
     * @param string $address The contact address
     * @param string $status The new status value
     * @return bool True if status was updated successfully
     */
    public function updateStatus(string $address, string $status): bool
    {
        return $this->managementService->updateStatus($address, $status);
    }

    // =========================================================================
    // REPOSITORY WRAPPERS (MANAGEMENT SERVICE)
    // =========================================================================

    /**
     * Get all addresses, optionally excluding one.
     *
     * @param string|null $exclude Address to exclude from results
     * @return array List of all addresses
     */
    public function getAllAddresses(?string $exclude = null): array
    {
        return $this->managementService->getAllAddresses($exclude);
    }

    /**
     * Get the credit limit for a sender.
     *
     * @param string $senderPublicKey The sender's public key
     * @return float The credit limit
     */
    public function getCreditLimit(string $senderPublicKey): float
    {
        return $this->managementService->getCreditLimit($senderPublicKey);
    }

    /**
     * Get the public key for a contact by address.
     *
     * @param string $address The contact address
     * @return string|null The public key or null if not found
     */
    public function getContactPubkey(string $address): ?string
    {
        return $this->managementService->getContactPubkey($address);
    }

    /**
     * Check for new contact requests since a given time.
     *
     * @param mixed $lastCheckTime The timestamp of the last check
     * @return bool True if new requests exist
     */
    public function checkForNewContactRequests($lastCheckTime): bool
    {
        return $this->managementService->checkForNewContactRequests($lastCheckTime);
    }

    /**
     * Get all contacts.
     *
     * @return array List of all contacts
     */
    public function getAllContacts(): array
    {
        return $this->managementService->getAllContacts();
    }

    /**
     * Get all contact public keys.
     *
     * @return array List of all contact public keys
     */
    public function getAllContactsPubkeys(): array
    {
        return $this->managementService->getAllContactsPubkeys();
    }

    /**
     * Get all accepted contacts.
     *
     * @return array List of accepted contacts
     */
    public function getAcceptedContacts(): array
    {
        return $this->managementService->getAcceptedContacts();
    }

    /**
     * Get all pending contact requests.
     *
     * @return array List of pending contact requests
     */
    public function getPendingContactRequests(): array
    {
        return $this->managementService->getPendingContactRequests();
    }

    /**
     * Get pending contact requests initiated by the user.
     *
     * @return array List of user's pending contact requests
     */
    public function getUserPendingContactRequests(): array
    {
        return $this->managementService->getUserPendingContactRequests();
    }

    /**
     * Get all blocked contacts.
     *
     * @return array List of blocked contacts
     */
    public function getBlockedContacts(): array
    {
        return $this->managementService->getBlockedContacts();
    }

    /**
     * Lookup contact addresses by name.
     *
     * @param string $name Contact name
     * @return string|null Contact addresses or null if not found
     */
    public function lookupAddressesByName(string $name): ?string
    {
        return $this->managementService->lookupAddressesByName($name);
    }

    /**
     * Get all accepted contact addresses for P2P routing.
     *
     * @return array List of accepted contact addresses
     */
    public function getAllAcceptedAddresses(): array
    {
        return $this->managementService->getAllAcceptedAddresses();
    }

    /**
     * Lookup a contact name by their address.
     *
     * @param string|null $transportIndex The transport index type (optional)
     * @param string $address The address to lookup
     * @return string|null The contact name or null if not found
     */
    public function lookupNameByAddress(?string $transportIndex, string $address): ?string
    {
        return $this->managementService->lookupNameByAddress($transportIndex, $address);
    }

    /**
     * Get all available address types.
     *
     * @return array List of address types
     */
    public function getAllAddressTypes(): array
    {
        return $this->managementService->getAllAddressTypes();
    }

    // =========================================================================
    // SYNC SERVICE DELEGATIONS
    // =========================================================================

    /**
     * Handle an incoming contact creation request.
     *
     * @param array $request The contact creation request data
     * @return string Status message indicating the result
     */
    public function handleContactCreation(array $request): string
    {
        return $this->syncService->handleContactCreation($request);
    }
}
