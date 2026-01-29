<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Transaction Validation Service Interface
 *
 * Defines the contract for transaction validation logic including:
 * - Previous transaction ID validation for chain integrity
 * - Available funds checking for transaction authorization
 * - Full transaction possibility validation with proactive sync
 *
 * This interface supports setter injection for circular dependencies
 * with SyncService.
 */
interface TransactionValidationServiceInterface
{
    /**
     * Set the sync trigger (interface for loose coupling)
     *
     * @param SyncTriggerInterface $sync Sync trigger interface
     * @return void
     */
    public function setSyncTrigger(SyncTriggerInterface $sync): void;

    /**
     * Set the transaction service (setter injection for processing)
     *
     * Required because checkTransactionPossible() calls processTransaction()
     * when validation passes.
     *
     * @param TransactionServiceInterface $transactionService Transaction service
     * @return void
     */
    public function setTransactionService(TransactionServiceInterface $transactionService): void;

    /**
     * Check if previous transaction ID is valid
     *
     * Validates that the incoming transaction's previousTxid matches what we expect
     * in the chain. This is critical for maintaining transaction chain integrity
     * between parties.
     *
     * @param array $request The transaction request data containing:
     *                       - senderPublicKey: Sender's public key
     *                       - receiverPublicKey: Receiver's public key
     *                       - previousTxid: Previous transaction ID (may be null for first tx)
     * @return bool True if previous txid is valid or not required, false otherwise
     */
    public function checkPreviousTxid(array $request): bool;

    /**
     * Check if sender has sufficient available funds for transaction
     *
     * Validates that the sender has enough balance (including credit limit)
     * to complete the requested transaction.
     *
     * @param array $request The transaction request data containing:
     *                       - senderPublicKey: Sender's public key
     *                       - amount: Transaction amount
     *                       - currency: Currency code
     * @return bool True if sufficient funds are available, false otherwise
     */
    public function checkAvailableFundsTransaction(array $request): bool;

    /**
     * Check if transaction is possible
     *
     * Performs comprehensive validation including:
     * - Contact blocked status check
     * - Previous txid chain validation (with proactive sync on mismatch)
     * - Available funds validation
     * - Duplicate transaction detection
     * - Chain conflict resolution handling
     *
     * When validation passes, this method processes the transaction and outputs
     * the acceptance response. When validation fails, it outputs the appropriate
     * rejection response.
     *
     * @param array $request The transaction request data
     * @param bool $echo Whether to echo validation responses (default: true)
     * @return bool True if transaction is possible (but note: when echo=true and
     *              transaction is processed, returns false to prevent duplicate processing)
     */
    public function checkTransactionPossible(array $request, bool $echo = true): bool;
}
