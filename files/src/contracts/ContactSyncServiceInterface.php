<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

use Eiou\Cli\CliOutputManager;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Core\SplitAmount;
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
     * @param string $currency The currency for the contact transaction
     * @return string The generated contact transaction ID
     */
    public function createContactTxid(string $receiverPublicKey, string $currency = 'USD'): string;

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
        int $fee,
        SplitAmount $credit,
        string $currency,
        ?CliOutputManager $output = null,
        ?string $description = null,
        ?SplitAmount $requestedCreditLimit = null
    ): void;

    /**
     * Handle exchange for a new contact.
     *
     * @param string $address The contact's address
     * @param string $name The name to assign to the contact
     * @param int $fee The transaction fee (scaled by FEE_CONVERSION_FACTOR)
     * @param SplitAmount $credit The credit limit
     * @param string $currency The currency code
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @param string|null $description Optional description
     * @param SplitAmount|null $requestedCreditLimit Optional credit limit to request from the contact
     * @return void
     */
    public function handleNewContact(
        string $address,
        string $name,
        int $fee,
        SplitAmount $credit,
        string $currency,
        ?CliOutputManager $output = null,
        ?string $description = null,
        ?SplitAmount $requestedCreditLimit = null
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
     * Check if a contact transaction exists for a given sender.
     *
     * Determines whether a contact transaction chain has been established
     * with the specified contact.
     *
     * @param string $senderPublicKey The remote sender's public key
     * @return bool True if a contact transaction exists
     */
    public function contactTransactionExists(string $senderPublicKey): bool;

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
        ?string $nonce = null
    ): ?string;

    /**
     * Complete a received contact transaction.
     *
     * Finalizes the contact transaction after the contact has been
     * accepted and verified. Updates the transaction status to complete.
     *
     * @param string $senderPublicKey The sender's public key
     * @param string|null $currency Optional per-currency filter
     * @return bool True if the transaction was completed successfully
     */
    public function completeReceivedContactTransaction(string $senderPublicKey, ?string $currency = null): bool;

    /**
     * Decline a received contact-currency request and flip the matching
     * tx record from 'accepted' to 'rejected'. Used by every decline
     * surface (CLI, GUI, API, batched-apply) so the tx ledger reflects
     * the decision instead of leaving the row stuck on 'accepted'.
     *
     * @param string $pubkeyHash The contact's pubkey hash
     * @param string $currency Currency being declined
     * @return bool True if the contact_currency row was deleted
     */
    public function declineReceivedContactCurrency(string $pubkeyHash, string $currency): bool;

    /**
     * Sender-side: flip the local 'sent' contact tx to 'rejected' when the
     * peer responds with STATUS_REJECTED during the contact handshake.
     *
     * @param string $contactPublicKey The peer's pubkey
     * @param string $currency Currency of the rejected request
     * @return bool True if a row was updated
     */
    public function rejectSentContactTransaction(string $contactPublicKey, string $currency): bool;

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

    /**
     * Send a currency acceptance notification to a remote contact.
     *
     * Called when a user accepts a pending incoming currency via the GUI.
     * Notifies the remote side and upgrades contact status if needed.
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency The currency that was accepted
     * @return bool True if notification was sent successfully
     */
    public function sendCurrencyAcceptanceNotification(string $pubkeyHash, string $currency): bool;
}
