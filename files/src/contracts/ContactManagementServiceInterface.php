<?php
namespace Eiou\Contracts;

use Eiou\Cli\CliOutputManager;

/**
 * Interface for contact CRUD and status management operations.
 *
 * Handles adding, accepting, blocking, deleting, and querying contacts.
 * Does NOT handle P2P exchange or synchronization logic - those concerns
 * belong to ContactP2PExchangeServiceInterface.
 */
interface ContactManagementServiceInterface
{
    // =========================================================================
    // CONTACT ADDITION (Entry Point)
    // =========================================================================

    /**
     * Add a new contact.
     *
     * Entry point for creating a contact record. Validates input data
     * and creates the contact in pending state awaiting acceptance.
     *
     * @param array $data The contact data including name, address, etc.
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function addContact(array $data, ?CliOutputManager $output = null): void;

    /**
     * Accept a pending contact request.
     *
     * Transitions a contact from pending to accepted status, setting
     * the fee and credit limit parameters for the relationship.
     *
     * @param string $pubkey The public key of the contact
     * @param string $name The name to assign to the contact
     * @param float $fee The transaction fee for this contact
     * @param float $credit The credit limit for this contact
     * @param string $currency The currency code
     * @return bool True if contact was accepted successfully
     */
    public function acceptContact(string $pubkey, string $name, float $fee, float $credit, string $currency, ?int $minFeeAmount = null): bool;

    // =========================================================================
    // LOOKUP OPERATIONS
    // =========================================================================

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
     * When multiple contacts share the same name, prompts for selection
     * (CLI) or returns a multiple_matches error (JSON mode).
     *
     * @param mixed $request The lookup request data (name or address)
     * @param CliOutputManager|null $output Output manager for interactive prompt / JSON error
     * @return array|null The contact information or null if not found/cancelled
     */
    public function lookupContactInfoWithDisambiguation($request, ?CliOutputManager $output = null): ?array;

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
     * Search contacts with criteria.
     *
     * Searches the contact database based on provided criteria
     * and outputs results via CLI output manager.
     *
     * @param array $data Search criteria
     * @param CliOutputManager|null $output Optional CLI output manager for results
     * @return void
     */
    public function searchContacts(array $data, ?CliOutputManager $output = null): void;

    /**
     * View detailed information about a contact.
     *
     * @param array $data Contact identifier data
     * @param CliOutputManager|null $output Optional CLI output manager for display
     * @return void
     */
    public function viewContact(array $data, ?CliOutputManager $output = null): void;

    // =========================================================================
    // EXISTENCE CHECKS
    // =========================================================================

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

    // =========================================================================
    // STATUS MANAGEMENT
    // =========================================================================

    /**
     * Block a contact.
     *
     * Sets the contact status to blocked, preventing further communication.
     *
     * @param string|null $addressOrName The address or name of the contact to block
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return bool True if contact was blocked successfully
     */
    public function blockContact(?string $addressOrName, ?CliOutputManager $output = null): bool;

    /**
     * Unblock a contact.
     *
     * Removes the blocked status from a contact, restoring communication.
     *
     * @param string|null $addressOrName The address or name of the contact to unblock
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return bool True if contact was unblocked successfully
     */
    public function unblockContact(?string $addressOrName, ?CliOutputManager $output = null): bool;

    /**
     * Delete a contact.
     *
     * Removes the contact from the database entirely.
     *
     * @param string|null $addressOrName The address or name of the contact to delete
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return bool True if contact was deleted successfully
     */
    public function deleteContact(?string $addressOrName, ?CliOutputManager $output = null): bool;

    /**
     * Update contact information.
     *
     * Updates contact fields such as name, fee, credit limit, etc.
     *
     * @param array $argv Update parameters
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function updateContact(array $argv, ?CliOutputManager $output = null): void;

    /**
     * Update the status of a contact.
     *
     * Changes the contact status (pending, accepted, blocked, etc.).
     *
     * @param string $address The contact address
     * @param string $status The new status value
     * @return bool True if status was updated successfully
     */
    public function updateStatus(string $address, string $status): bool;

    // =========================================================================
    // REPOSITORY WRAPPERS
    // =========================================================================

    /**
     * Get all addresses, optionally excluding one.
     *
     * @param string|null $exclude Address to exclude from results
     * @return array List of all addresses
     */
    public function getAllAddresses(?string $exclude = null): array;

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
     * Check for new contact requests since a given time.
     *
     * @param mixed $lastCheckTime The timestamp of the last check
     * @return bool True if new requests exist
     */
    public function checkForNewContactRequests($lastCheckTime): bool;

    /**
     * Get all contacts.
     *
     * @return array List of all contacts
     */
    public function getAllContacts(): array;

    /**
     * Get all contact public keys.
     *
     * @return array List of all contact public keys
     */
    public function getAllContactsPubkeys(): array;

    /**
     * Get all accepted contacts.
     *
     * @return array List of accepted contacts
     */
    public function getAcceptedContacts(): array;

    /**
     * Get all pending contact requests.
     *
     * Returns contacts in pending state awaiting acceptance or rejection.
     *
     * @return array List of pending contact requests
     */
    public function getPendingContactRequests(): array;

    /**
     * Get pending contact requests initiated by the user.
     *
     * Returns outgoing contact requests the user has sent that are
     * awaiting acceptance by the recipient.
     *
     * @return array List of user's pending contact requests
     */
    public function getUserPendingContactRequests(): array;

    /**
     * Get all blocked contacts.
     *
     * @return array List of blocked contacts
     */
    public function getBlockedContacts(): array;

    /**
     * Lookup contact addresses by name.
     *
     * Used for address resolution when sending to a contact by name.
     *
     * @param string $name Contact name
     * @return array|null Contact addresses or null if not found
     */
    public function lookupAddressesByName(string $name): ?array;

    /**
     * Get all accepted contact addresses for P2P routing.
     *
     * Returns addresses of contacts with 'accepted' status for use in
     * P2P message broadcasting.
     *
     * @return array List of accepted contact addresses
     */
    public function getAllAcceptedAddresses(?string $currency = null): array;

    /**
     * Lookup a contact name by their address.
     *
     * @param string|null $transportIndex The transport index type (optional)
     * @param string $address The address to lookup
     * @return string|null The contact name or null if not found
     */
    public function lookupNameByAddress(?string $transportIndex, string $address): ?string;

    /**
     * Get all available address types.
     *
     * Returns the list of supported address/transport types in the system.
     *
     * @return array List of address types
     */
    public function getAllAddressTypes(): array;
}
