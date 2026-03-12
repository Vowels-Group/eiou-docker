<?php
namespace Eiou\Contracts;

/**
 * P2P Transaction Sender Interface
 *
 * Interface for P2P transaction sending operations.
 * This interface exists to break circular dependencies - services
 * that need to send P2P transactions can depend on this interface
 * rather than the full P2pService.
 *
 * Used by:
 * - TransactionService (to trigger P2P sends)
 * - Rp2pService (to complete P2P transactions)
 */
interface P2pTransactionSenderInterface
{
    /**
     * Send a P2P eIOU transaction.
     *
     * Initiates a peer-to-peer eIOU transaction based on the provided
     * request data. This handles the full P2P flow including validation,
     * chain verification, and network broadcast.
     *
     * @param array $request The P2P request data containing:
     *                       - 'amount' (float): Transaction amount
     *                       - 'contact' (string): Contact identifier
     *                       - 'memo' (string|null): Optional memo
     * @return void
     */
    public function sendP2pEiou(array $request): void;
}
