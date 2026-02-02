<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

use Eiou\Cli\CliOutputManager;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Services\MessageDeliveryService;

/**
 * Interface for contact synchronization and P2P exchange operations.
 *
 * Handles contact request sending, receiving, message delivery, and
 * transaction chain management for contacts. This interface separates
 * the sync/exchange concerns from the core contact management in
 * ContactServiceInterface.
 *
 * Used by:
 * - ContactService (implements the full contact sync functionality)
 * - MessageService (for contact request handling)
 * - SyncService (for contact transaction chain operations)
 *
 * @see ContactServiceInterface for contact management operations
 * @see SyncTriggerInterface for transaction chain sync operations
 */
interface ContactSyncServiceInterface
{
    // =========================================================================
    // DEPENDENCY INJECTION
    // =========================================================================

    /**
     * Set the sync trigger for chain synchronization operations.
     *
     * @param SyncTriggerInterface $sync The sync trigger implementation
     * @return void
     */
    public function setSyncTrigger(SyncTriggerInterface $sync): void;

    /**
     * Get the current sync trigger.
     *
     * @return SyncTriggerInterface|null The sync trigger or null if not set
     */
    public function getSyncTrigger(): ?SyncTriggerInterface;

    /**
     * Set the message delivery service for sending contact messages.
     *
     * @param MessageDeliveryService $service The message delivery service
     * @return void
     */
    public function setMessageDeliveryService(MessageDeliveryService $service): void;

    // =========================================================================
    // CONTACT TRANSACTION ID
    // =========================================================================

    /**
     * Create a unique contact transaction ID for a new contact relationship.
     *
     * Generates a deterministic transaction ID based on the receiver's
     * public key and the current user's credentials.
     *
     * @param string $receiverPublicKey The receiver's public key
     * @return string The generated contact transaction ID
     */
    public function createContactTxid(string $receiverPublicKey): string;

    // =========================================================================
    // CONTACT EXCHANGE HANDLERS
    // =========================================================================

    /**
     * Handle exchange for an existing contact.
     *
     * Called when accepting or updating a contact that already exists
     * in the local database. Updates contact settings and sends
     * acceptance message.
     *
     * @param array $contact The existing contact data
     * @param string $address The contact's address
     * @param string $name The name to assign to the contact
     * @param float $fee The transaction fee for this contact
     * @param float $credit The credit limit for this contact
     * @param string $currency The currency code
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function handleExistingContact(
        array $contact,
        string $address,
        string $name,
        float $fee,
        float $credit,
        string $currency,
        ?CliOutputManager $output = null
    ): void;

    /**
     * Handle exchange for a new contact.
     *
     * Called when initiating a new contact request. Creates the local
     * contact record and sends the contact request message to the
     * target address.
     *
     * @param string $address The contact's address
     * @param string $name The name to assign to the contact
     * @param float $fee The transaction fee for this contact
     * @param float $credit The credit limit for this contact
     * @param string $currency The currency code
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function handleNewContact(
        string $address,
        string $name,
        float $fee,
        float $credit,
        string $currency,
        ?CliOutputManager $output = null
    ): void;

    /**
     * Handle an incoming contact creation request.
     *
     * Processes a contact request received from another node. Creates
     * or updates the local contact record based on the request data.
     *
     * @param array $request The contact creation request data containing:
     *   - sender_pubkey: string - The sender's public key
     *   - sender_address: string - The sender's address
     *   - currency: string - The currency code (optional)
     *   - signature: string - Request signature (optional)
     *   - nonce: int - Signature nonce (optional)
     * @return string Status message indicating the result
     */
    public function handleContactCreation(array $request): string;

    // =========================================================================
    // CONTACT TRANSACTION OPERATIONS
    // =========================================================================

    /**
     * Check if a contact transaction exists for a receiver.
     *
     * Determines whether a contact transaction chain has been established
     * with the specified contact.
     *
     * @param string $receiverPublicKey The receiver's public key
     * @return bool True if a contact transaction exists
     */
    public function contactTransactionExists(string $receiverPublicKey): bool;

    /**
     * Insert a new outgoing contact transaction.
     *
     * Creates a contact transaction record when initiating a new contact
     * relationship. This establishes the local side of the contact chain.
     *
     * @param string $receiverPublicKey The receiver's public key
     * @param string $receiverAddress The receiver's address
     * @param string $currency The currency code
     * @param string|null $txid Optional transaction ID (generated if not provided)
     * @return string|null The transaction ID or null on failure
     */
    public function insertContactTransaction(
        string $receiverPublicKey,
        string $receiverAddress,
        string $currency,
        ?string $txid = null
    ): ?string;

    /**
     * Insert a received contact transaction.
     *
     * Creates a contact transaction record when receiving a contact request.
     * This establishes the remote side of the contact chain.
     *
     * @param string $senderPublicKey The sender's public key
     * @param string $senderAddress The sender's address
     * @param string $currency The currency code (default: 'USD')
     * @param string|null $signature Optional signature for verification
     * @param int|null $nonce Optional nonce for signature verification
     * @return string|null The transaction ID or null on failure
     */
    public function insertReceivedContactTransaction(
        string $senderPublicKey,
        string $senderAddress,
        string $currency = 'USD',
        ?string $signature = null,
        ?int $nonce = null
    ): ?string;

    /**
     * Complete a received contact transaction.
     *
     * Finalizes the contact transaction after the contact has been
     * accepted and verified. Updates the transaction status to complete.
     *
     * @param string $senderPublicKey The sender's public key
     * @return bool True if the transaction was completed successfully
     */
    public function completeReceivedContactTransaction(string $senderPublicKey): bool;

    // =========================================================================
    // MESSAGE SENDING
    // =========================================================================

    /**
     * Send a message to a contact.
     *
     * Delivers a message payload to the specified contact address.
     * Used for contact request messages, acceptance messages, and
     * other P2P communication.
     *
     * @param string $contactAddress The recipient's address
     * @param string $payload The message payload (typically JSON-encoded)
     * @param string $description A description of the message for logging
     * @return array Result array containing:
     *   - success: bool - Whether the message was sent successfully
     *   - error: string|null - Error message if failed
     *   - response: mixed - Response from the recipient (if any)
     */
    public function sendContactMessage(
        string $contactAddress,
        string $payload,
        string $description
    ): array;
}
