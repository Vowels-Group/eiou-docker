<?php
namespace Eiou\Contracts;

use Eiou\Cli\CliOutputManager;
use Eiou\Exceptions\ValidationServiceException;

/**
 * Interface for contact management services.
 *
 * Defines the contract for managing contacts including adding, accepting,
 * blocking, and querying contact information.
 */
interface ContactServiceInterface
{
    /**
     * Add a new contact.
     *
     * @param array $data The contact data
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function addContact(array $data, ?CliOutputManager $output = null): void;

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
    public function acceptContact(string $pubkey, string $name, float $fee, float $credit, string $currency): bool;

    /**
     * Lookup contact information from a request.
     *
     * @param mixed $request The lookup request data
     * @return array|null The contact information or null if not found
     */
    public function lookupContactInfo($request): ?array;

    /**
     * Lookup contact information with disambiguation for duplicate names.
     *
     * @param mixed $request The lookup request data (name or address)
     * @param \Eiou\Cli\CliOutputManager|null $output Output manager
     * @return array|null The contact information or null if not found/cancelled
     */
    public function lookupContactInfoWithDisambiguation($request, ?\Eiou\Cli\CliOutputManager $output = null): ?array;

    /**
     * Lookup a contact by name.
     *
     * @param string $name The contact name to search for
     * @return array|null The contact data or null if not found
     */
    public function lookupByName(string $name): ?array;

    /**
     * Lookup a contact by transport address.
     *
     * @param string $transportIndex The transport index type
     * @param string $address The address to search for
     * @return array|null The contact data or null if not found
     */
    public function lookupByAddress(string $transportIndex, string $address): ?array;

    /**
     * Check if a contact exists by address.
     *
     * @param string $address The address to check
     * @return bool True if contact exists
     */
    public function contactExists(string $address): bool;

    /**
     * Check if a contact exists by public key.
     *
     * @param string $pubkey The public key to check
     * @return bool True if contact exists
     */
    public function contactExistsPubkey(string $pubkey): bool;

    /**
     * Check if a contact is accepted by public key.
     *
     * @param string $pubkey The public key to check
     * @return bool True if contact is accepted
     */
    public function isAcceptedContactPubkey(string $pubkey): bool;

    /**
     * Check if a contact is not blocked.
     *
     * @param string $pubkey The public key to check
     * @return bool True if contact is not blocked
     */
    public function isNotBlocked(string $pubkey): bool;

    /**
     * Block a contact.
     *
     * @param string|null $addressOrName The address or name of the contact to block
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return bool True if contact was blocked successfully
     */
    public function blockContact(?string $addressOrName, ?CliOutputManager $output = null): bool;

    /**
     * Unblock a contact.
     *
     * @param string|null $addressOrName The address or name of the contact to unblock
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return bool True if contact was unblocked successfully
     */
    public function unblockContact(?string $addressOrName, ?CliOutputManager $output = null): bool;

    /**
     * Delete a contact.
     *
     * @param string|null $addressOrName The address or name of the contact to delete
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return bool True if contact was deleted successfully
     */
    public function deleteContact(?string $addressOrName, ?CliOutputManager $output = null): bool;

    /**
     * Get all contacts.
     *
     * @return array List of all contacts
     */
    public function getAllContacts(): array;

    /**
     * Get all accepted contacts.
     *
     * @return array List of accepted contacts
     */
    public function getAcceptedContacts(): array;

    /**
     * Get all pending contact requests.
     *
     * @return array List of pending contact requests
     */
    public function getPendingContactRequests(): array;

    /**
     * Get all blocked contacts.
     *
     * @return array List of blocked contacts
     */
    public function getBlockedContacts(): array;

    /**
     * Get the credit limit for a sender.
     *
     * @param string $senderPublicKey The sender's public key
     * @param string $currency Currency code
     * @return float The credit limit
     */
    public function getCreditLimit(string $senderPublicKey, string $currency = \Eiou\Core\Constants::TRANSACTION_DEFAULT_CURRENCY): float;

    /**
     * Get the public key for a contact by address.
     *
     * @param string $address The contact address
     * @return string|null The public key or null if not found
     */
    public function getContactPubkey(string $address): ?string;

    /**
     * Get all addresses, optionally excluding one.
     *
     * @param string|null $exclude Address to exclude from results
     * @return array List of all addresses
     */
    public function getAllAddresses(?string $exclude = null): array;

    /**
     * Get all accepted contact addresses for P2P routing.
     *
     * Returns addresses of contacts with 'accepted' status for use in
     * P2P message broadcasting.
     *
     * @return array List of accepted contact addresses
     */
    public function getAllAcceptedAddresses(): array;

    /**
     * Lookup contact addresses by name.
     *
     * Used for address resolution when sending to a contact by name.
     *
     * @param string $name Contact name
     * @return string|null Contact addresses or null if not found
     */
    public function lookupAddressesByName(string $name): ?string;
}
