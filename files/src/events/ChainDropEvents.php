<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Events;

/**
 * Chain Drop Events
 *
 * Event constants for chain drop agreement operations.
 * These events enable loose coupling between chain-drop-related services.
 */
class ChainDropEvents
{
    /**
     * Dispatched when a chain drop is proposed to a contact
     *
     * Event data:
     *   - proposal_id: string - The proposal identifier
     *   - contact_pubkey: string - Contact's public key
     *   - missing_txid: string - The missing transaction ID
     *   - broken_txid: string - The transaction with the broken chain link
     */
    public const CHAIN_DROP_PROPOSED = 'chain_drop.proposed';

    /**
     * Dispatched when a chain drop proposal is accepted
     *
     * Event data:
     *   - proposal_id: string - The proposal identifier
     *   - contact_pubkey: string - Contact's public key
     *   - direction: string - 'outgoing' or 'incoming'
     */
    public const CHAIN_DROP_ACCEPTED = 'chain_drop.accepted';

    /**
     * Dispatched when a chain drop proposal is rejected
     *
     * Event data:
     *   - proposal_id: string - The proposal identifier
     *   - contact_pubkey: string - Contact's public key
     *   - reason: string - Rejection reason
     */
    public const CHAIN_DROP_REJECTED = 'chain_drop.rejected';

    /**
     * Dispatched when a chain drop has been fully executed on the local node
     *
     * Event data:
     *   - proposal_id: string - The proposal identifier
     *   - contact_pubkey: string - Contact's public key
     *   - missing_txid: string - The dropped transaction ID
     *   - broken_txid: string - The transaction whose previous_txid was updated
     *   - new_previous_txid: string|null - The new previous_txid value
     */
    public const CHAIN_DROP_EXECUTED = 'chain_drop.executed';

    /**
     * Dispatched when a missing transaction is recovered from a database backup
     * instead of requiring a chain drop
     *
     * Event data:
     *   - missing_txid: string - The recovered transaction ID
     *   - contact_pubkey_hash: string - Contact's public key hash
     *   - backup_filename: string - The backup file that contained the transaction
     */
    public const TRANSACTION_RECOVERED_FROM_BACKUP = 'chain_drop.transaction_recovered_from_backup';
}
