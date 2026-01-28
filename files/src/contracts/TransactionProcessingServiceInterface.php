<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Transaction Processing Service Interface
 *
 * Defines the contract for core transaction processing logic including
 * processing incoming transactions, pending transactions, and P2P transactions.
 *
 * This service handles the core processing workflows:
 * - processTransaction(): Process incoming transaction requests (standard and P2P)
 * - processPendingTransactions(): Process pending outbound transactions with atomic claiming
 * - processP2pTransaction(): Process P2P transactions with atomic claiming and retry logic
 *
 * Atomic claiming is used to prevent duplicate processing when multiple
 * processes are running. If a transaction is already being processed,
 * it will be skipped.
 */
interface TransactionProcessingServiceInterface
{
    /**
     * Process incoming transaction request
     *
     * Handles both standard (direct) transactions and P2P transactions.
     * For standard transactions, inserts the transaction as 'received'.
     * For P2P transactions, checks if user is relay or end-recipient.
     *
     * @param array $request The transaction request data containing:
     *                       - memo (string): 'standard' for direct, hash for P2P
     *                       - senderAddress (string): Sender's address
     *                       - txid (string): Transaction ID
     *                       - amount (float): Transaction amount
     *                       - currency (string): Currency code
     *                       - senderPublicKey (string): Sender's public key
     *                       - receiverPublicKey (string): Receiver's public key
     *                       - signature (string): Transaction signature
     *                       - recipientSignature (string, optional): Recipient's signature
     * @return void
     * @throws InvalidArgumentException If required fields are missing
     * @throws PDOException If database operation fails
     */
    public function processTransaction(array $request): void;

    /**
     * Process pending transactions
     *
     * Iterates through pending transactions and processes each one:
     * - Direct transactions: Send to receiver, handle accept/reject responses
     * - P2P transactions: Delegate to processP2pTransaction()
     *
     * Uses atomic claiming to prevent duplicate processing:
     * 1. Atomically claim transaction (PENDING -> SENDING)
     * 2. If claim fails, skip (another process is handling it)
     * 3. Send the transaction
     * 4. Update status based on result (SENDING -> SENT/ACCEPTED/REJECTED)
     *
     * If process crashes while SENDING, TransactionRecoveryService will
     * recover the transaction on next startup.
     *
     * For rejected transactions with invalid_previous_txid:
     * 1. Attempt inline retry with expected_txid if provided
     * 2. Fall back to hold/sync flow if inline retry fails
     * 3. If all else fails, convert to P2P request
     *
     * @return int Number of processed transactions
     */
    public function processPendingTransactions(): int;

    /**
     * Set the sync trigger (interface for loose coupling)
     *
     * Required for transaction chain synchronization when previous_txid
     * validation fails.
     *
     * @param SyncTriggerInterface $sync Sync trigger interface
     * @return void
     */
    public function setSyncTrigger(SyncTriggerInterface $sync): void;

    /**
     * Set the P2P service (setter injection for circular dependency)
     *
     * Required for fallback to P2P when direct transaction is rejected.
     *
     * @param P2pServiceInterface $p2pService P2P service instance
     * @return void
     */
    public function setP2pService(P2pServiceInterface $p2pService): void;

    /**
     * Set the held transaction service (setter injection for circular dependency)
     *
     * Required for holding transactions pending sync completion.
     *
     * @param HeldTransactionServiceInterface $heldTransactionService Held transaction service instance
     * @return void
     */
    public function setHeldTransactionService(HeldTransactionServiceInterface $heldTransactionService): void;
}
