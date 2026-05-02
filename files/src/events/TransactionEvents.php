<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Events;

/**
 * Transaction Events
 *
 * Event constants for transaction lifecycle. Plugins subscribe to these via
 * EventDispatcher to react to money movement without modifying core services.
 *
 * Usage:
 *   EventDispatcher::getInstance()->subscribe(TransactionEvents::TRANSACTION_SENT, function($data) {
 *       $txid     = $data['txid'];
 *       $amount   = $data['amount'];
 *       $currency = $data['currency'];
 *       // Post to a webhook, update analytics, etc.
 *   });
 *
 */
class TransactionEvents
{
    /**
     * Dispatched **before** a transaction is validated. Listeners may
     * throw an `\Eiou\Core\TransactionPreValidationException` (or any
     * subclass of `\RuntimeException`) to abort the validation and
     * prevent the transaction from being created — useful for compliance
     * or anti-fraud plugins that need a veto point in the pipeline.
     *
     * Emitted by `TransactionValidationService::checkTransactionPossible()`
     * as the first call in the method, so the abort costs no DB work.
     *
     * Event data:
     *   - request: array - The raw transaction request being validated
     *                      (includes senderPublicKey, receiverPublicKey,
     *                      amount, currency, txid, etc.)
     */
    public const PRE_VALIDATE = 'transaction.pre_validate';

    /**
     * Dispatched when a pending outbound transaction is inserted into the
     * `transactions` table, before any delivery attempt.
     *
     * Emitted by SendOperationService::handleDirectRoute() (direct route)
     * and SendOperationService::sendP2pEiou() (approved P2P route). The
     * `route` field distinguishes the two paths.
     *
     * Event data:
     *   - txid: string                  - Transaction ID
     *   - amount: string|null           - Amount in major units
     *   - currency: string|null         - Currency code
     *   - recipient_address: string|null - Recipient transport address
     *   - recipient_pubkey: string|null  - Recipient public key (if resolved)
     *   - route: string                  - 'direct' or 'p2p'
     */
    public const TRANSACTION_CREATED = 'transaction.created';

    /**
     * Dispatched after a transaction is successfully delivered to the recipient.
     *
     * Emitted by TransactionProcessingService::handleAcceptedTransaction() once
     * the remote peer has acknowledged receipt and the local status has moved
     * to accepted.
     *
     * Event data:
     *   - txid: string          - Transaction ID
     *   - amount: string|null   - Amount in major units (if known at emit time)
     *   - currency: string|null - Currency code (if known at emit time)
     *   - recipient_address: string|null - Recipient transport address
     *   - response: array       - Response payload from the remote peer
     */
    public const TRANSACTION_SENT = 'transaction.sent';

    /**
     * Dispatched when an inbound transaction arrives and is persisted locally.
     *
     * Emitted by TransactionProcessingService::processStandardIncoming() after
     * the incoming tx has been inserted and acknowledged.
     *
     * Event data:
     *   - txid: string          - Transaction ID
     *   - amount: string        - Amount in major units
     *   - currency: string      - Currency code
     *   - sender_pubkey: string - Sender public key
     *   - sender_address: string|null - Sender transport address (if known)
     */
    public const TRANSACTION_RECEIVED = 'transaction.received';

    /**
     * Dispatched when transaction delivery fails with attempts exhausted.
     *
     * Emitted by TransactionProcessingService::processOutgoingDirect() on the
     * failure branch (non-recoverable or attempts-exhausted paths).
     *
     * Event data:
     *   - txid: string             - Transaction ID
     *   - error: string            - Human-readable error summary
     *   - attempts: int            - Number of delivery attempts made
     *   - recipient_address: string|null - Recipient transport address
     */
    public const TRANSACTION_FAILED = 'transaction.failed';
}
