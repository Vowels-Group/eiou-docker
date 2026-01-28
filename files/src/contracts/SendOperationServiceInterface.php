<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Send Operation Service Interface
 *
 * Defines the contract for high-level send operation orchestration.
 * Handles direct sends, P2P routing, and transaction message delivery
 * with distributed locking support for concurrent send prevention.
 *
 * This interface is part of the TransactionService refactoring (Issue #512)
 * to split the God Class into focused, single-responsibility services.
 *
 * @package Contracts
 */
interface SendOperationServiceInterface
{
    /**
     * Send eIOU transaction
     *
     * Main entry point for sending an eIOU transaction.
     * Validates input, determines routing (direct or P2P), and initiates send.
     *
     * @param array $request Request data (argv-style array with recipient, amount, currency)
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function sendEiou(array $request, ?CliOutputManager $output = null): void;

    /**
     * Handle direct transaction route
     *
     * Sends transaction directly to an accepted contact.
     * Uses per-contact locking to serialize simultaneous sends.
     * Verifies sender-side chain integrity before sending.
     *
     * @param array $request Request data
     * @param array $contactInfo Contact information including receiverPublicKey
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function handleDirectRoute(array $request, array $contactInfo, ?CliOutputManager $output = null): void;

    /**
     * Handle P2P transaction route
     *
     * Initiates P2P route discovery when contact is not directly reachable.
     * Used when contact is not in address book or is unreachable.
     *
     * @param array $request Request data
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function handleP2pRoute(array $request, ?CliOutputManager $output = null): void;

    /**
     * Send P2P eIOU
     *
     * Handler for sending transactions upon successfully receiving route to end-recipient.
     * Called when a P2P route has been discovered and transaction can be sent.
     *
     * @param array $request Request data including route information
     * @return void
     */
    public function sendP2pEiou(array $request): void;

    /**
     * Send a transaction message with optional delivery tracking
     *
     * Uses MessageDeliveryService.sendMessage() when available for reliable delivery
     * with retry logic and dead letter queue support. Falls back to direct transport
     * if delivery service is not configured.
     *
     * Message ID format varies by transaction type:
     * - Original send: send-{txid}-{timestamp} (user initiated the transaction)
     * - Relay: relay-{txid}-{timestamp} (user is forwarding for another party)
     * - Special formats: {prefix}-{txid}-{timestamp} (e.g., completion-response)
     *
     * @param string $address Recipient address
     * @param array $payload Message payload
     * @param string $txid Transaction ID for tracking
     * @param bool $isRelay Whether this is a relay (forwarding) vs original send
     * @return array Response with 'success', 'response', 'raw', and 'messageId' keys
     */
    public function sendTransactionMessage(string $address, array $payload, string $txid, bool $isRelay = false): array;

    /**
     * Acquire a lock for sending to a specific contact
     *
     * Prevents race conditions when sending multiple transactions
     * simultaneously to the same contact. Uses LockingService if available,
     * otherwise falls back to file-based locking for persistence
     * across request boundaries.
     *
     * @param string $contactPubkeyHash Hash of contact's public key
     * @param int $timeout Maximum time to wait for lock (seconds)
     * @return bool True if lock acquired, false if timeout
     */
    public function acquireContactSendLock(string $contactPubkeyHash, int $timeout = 30): bool;

    /**
     * Release a contact send lock
     *
     * @param string $contactPubkeyHash Hash of contact's public key
     * @return void
     */
    public function releaseContactSendLock(string $contactPubkeyHash): void;

    /**
     * Set the contact service (setter injection for circular dependency)
     *
     * @param ContactServiceInterface $contactService Contact service
     * @return void
     */
    public function setContactService(ContactServiceInterface $contactService): void;

    /**
     * Set the P2P service (setter injection for circular dependency)
     *
     * @param P2pServiceInterface $p2pService P2P service
     * @return void
     */
    public function setP2pService(P2pServiceInterface $p2pService): void;

    /**
     * Set the locking service (setter injection)
     *
     * @param LockingServiceInterface $lockingService Locking service
     * @return void
     */
    public function setLockingService(LockingServiceInterface $lockingService): void;
}
