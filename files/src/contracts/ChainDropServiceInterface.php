<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Chain Drop Service Interface
 *
 * Defines the contract for managing chain drop agreements between contacts.
 *
 * When both contacts are missing the same transaction in their shared chain,
 * this service coordinates the mutual agreement to drop the missing transaction
 * and relink the chain, including exchange of re-signed transaction copies.
 *
 * Gap Detection (all local — no transaction lists sent over the wire):
 * - send: verifies chain integrity before every send; auto-proposes chain drop if sync fails
 * - sync: verifies chain integrity after syncing transactions; reports gaps in output
 * - ping: verifies local chain integrity (not just chain head comparison); reports chain_valid
 *
 * Flow:
 * 1. Contact A detects chain gap (send auto-proposes, or ping/sync reveals the gap)
 * 2. Contact A calls proposeChainDrop() -> sends proposal to Contact B
 * 3. Contact B receives proposal via handleIncomingProposal() -> verifies gap exists locally
 * 4. Contact B calls acceptProposal() -> executes drop, re-signs own txs, sends acceptance with re-signed data
 * 5. Contact A receives acceptance via handleIncomingAcceptance() -> executes drop, re-signs own txs, sends ack
 * 6. Contact B receives acknowledgment via handleIncomingAcknowledgment() -> stores A's re-signed txs
 *
 * Important: While a chain gap exists, transactions between the two contacts are blocked.
 * SendOperationService verifies chain integrity before every send and halts if a gap is
 * detected (after attempting sync repair, which fails when both parties are missing the
 * same transaction). Rejecting a proposal leaves the gap unresolved, meaning the contacts
 * cannot transact until a new proposal is accepted or the missing transaction is recovered.
 */
interface ChainDropServiceInterface
{
    /**
     * Propose dropping a missing transaction from the chain
     *
     * Auto-detects the gap by looking up the contact and verifying chain integrity.
     * Picks the first detected gap for the proposal.
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @return array Result with keys: success (bool), proposal_id (string|null), missing_txid (string|null), broken_txid (string|null), error (string|null)
     */
    public function proposeChainDrop(string $contactPubkeyHash): array;

    /**
     * Handle an incoming chain drop proposal from a contact
     *
     * @param array $request The incoming proposal message data
     * @return void
     */
    public function handleIncomingProposal(array $request): void;

    /**
     * Accept an incoming chain drop proposal
     *
     * @param string $proposalId The proposal ID to accept
     * @return array Result with keys: success (bool), error (string|null)
     */
    public function acceptProposal(string $proposalId): array;

    /**
     * Reject an incoming chain drop proposal
     *
     * Warning: Rejecting leaves the chain gap unresolved. While the gap exists,
     * transactions between these contacts are blocked (SendOperationService
     * verifies chain integrity before every send). A new proposal must be
     * accepted to restore the ability to transact.
     *
     * @param string $proposalId The proposal ID to reject
     * @return array Result with keys: success (bool), error (string|null)
     */
    public function rejectProposal(string $proposalId): array;

    /**
     * Handle an incoming acceptance of our proposal
     *
     * @param array $request The incoming acceptance message data
     * @return void
     */
    public function handleIncomingAcceptance(array $request): void;

    /**
     * Handle an incoming rejection of our proposal
     *
     * @param array $request The incoming rejection message data
     * @return void
     */
    public function handleIncomingRejection(array $request): void;

    /**
     * Handle an incoming acknowledgment (final step of exchange)
     *
     * @param array $request The incoming acknowledgment message data
     * @return void
     */
    public function handleIncomingAcknowledgment(array $request): void;

    /**
     * Get proposals for a specific contact
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @return array List of proposals
     */
    public function getProposalsForContact(string $contactPubkeyHash): array;

    /**
     * Get all incoming pending proposals (for notifications)
     *
     * @return array List of pending incoming proposals
     */
    public function getIncomingPendingProposals(): array;

    /**
     * Expire stale proposals that have passed their expiration time
     *
     * @return int Number of proposals expired
     */
    public function expireStaleProposals(): int;
}
