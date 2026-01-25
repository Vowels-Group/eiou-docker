<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Cleanup Service Interface
 *
 * Defines the contract for cleanup management operations.
 * Handles processing of expired messages, P2P chain completion checks,
 * and transaction cancellation.
 *
 * @package Eiou\Contracts
 */
interface CleanupServiceInterface
{
    /**
     * Check if there are any messages that will expire and process them
     *
     * This method retrieves all expired P2P messages from the database
     * (those that have exceeded their expiration time) and marks them as expired.
     * Before expiring, it checks if the transaction was actually completed
     * but the completion message was lost.
     *
     * @return int Number of expired messages processed
     * @throws \PDOException If database query fails
     */
    public function processCleanupMessages(): int;

    /**
     * Expire requests with P2P chain completion check
     *
     * Before expiring a P2P, this method checks if the transaction chain was actually
     * completed but the completion message was lost (e.g., ended up in dead letter queue).
     *
     * The check follows this order:
     * 1. Check locally if a completed transaction already exists for this P2P hash
     * 2. If not found locally, query the P2P sender to check their completion status
     * 3. If sender reports completed, mark as completed and sync transactions
     * 4. Only expire if no completion evidence is found
     *
     * @param array $message The P2P message data containing hash and sender_address
     * @return void
     */
    public function expireMessage($message): void;

    /**
     * Cancel a transaction
     *
     * Marks the transaction as cancelled. The previous_txid chain is
     * preserved unchanged to maintain transaction history integrity.
     *
     * @param string $txid The transaction ID to cancel
     * @return bool True if cancellation was successful, false if transaction not found
     */
    public function cancelTransaction(string $txid): bool;
}
