<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Events;

/**
 * Sync Events
 *
 * Event constants for synchronization operations.
 * These events enable loose coupling between sync-related services
 * by allowing them to communicate via events instead of direct dependencies.
 *
 * Usage:
 *   // Subscribe to sync completion
 *   EventDispatcher::getInstance()->subscribe(SyncEvents::SYNC_COMPLETED, function($data) {
 *       $contactPubkey = $data['contact_pubkey'];
 *       $syncedCount = $data['synced_count'];
 *       // Handle sync completion...
 *   });
 *
 *   // Dispatch sync completion event
 *   EventDispatcher::getInstance()->dispatch(SyncEvents::SYNC_COMPLETED, [
 *       'contact_pubkey' => $pubkey,
 *       'synced_count' => 5,
 *       'success' => true
 *   ]);
 */
class SyncEvents
{
    /**
     * Dispatched when a sync operation completes successfully
     *
     * Event data:
     *   - contact_pubkey: string - The public key of the synced contact
     *   - synced_count: int - Number of items synced
     *   - success: bool - Whether sync was successful
     *   - contact_address: string - The address of the synced contact (optional)
     */
    public const SYNC_COMPLETED = 'sync.completed';

    /**
     * Dispatched when a sync operation fails
     *
     * Event data:
     *   - contact_pubkey: string - The public key of the contact
     *   - contact_address: string - The address of the contact
     *   - error: string - Error message describing the failure
     *   - error_code: string - Error code for programmatic handling (optional)
     */
    public const SYNC_FAILED = 'sync.failed';

    /**
     * Dispatched when a gap is detected in the transaction chain
     *
     * Event data:
     *   - contact_pubkey: string - The public key of the contact
     *   - contact_address: string - The address of the contact
     *   - expected_txid: string - The expected transaction ID
     *   - actual_txid: string|null - The actual transaction ID found (or null)
     *   - gap_size: int - Estimated number of missing transactions (optional)
     */
    public const CHAIN_GAP_DETECTED = 'sync.chain_gap_detected';

    /**
     * Dispatched when a contact sync completes
     *
     * Event data:
     *   - contact_pubkey: string - The public key of the synced contact
     *   - contact_address: string - The address of the synced contact
     *   - status: string - The contact status after sync (e.g., 'accepted', 'pending')
     *   - was_pending: bool - Whether the contact was previously pending
     */
    public const CONTACT_SYNCED = 'sync.contact_synced';

    /**
     * Dispatched when a balance sync completes for a contact
     *
     * Event data:
     *   - contact_pubkey: string - The public key of the contact
     *   - currencies: array - List of currencies that were synced
     *   - success: bool - Whether balance sync was successful
     */
    public const BALANCE_SYNCED = 'sync.balance_synced';

    /**
     * Dispatched when a transaction chain conflict is detected and resolved
     *
     * Event data:
     *   - contact_pubkey: string - The public key of the contact
     *   - local_txid: string - The local transaction ID in conflict
     *   - remote_txid: string - The remote transaction ID in conflict
     *   - winner: string - Which transaction won ('local' or 'remote')
     *   - previous_txid: string - The shared previous transaction ID
     */
    public const CHAIN_CONFLICT_RESOLVED = 'sync.chain_conflict_resolved';

    /**
     * Dispatched when a bidirectional sync starts
     *
     * Event data:
     *   - contact_pubkey: string - The public key of the contact
     *   - contact_address: string - The address of the contact
     *   - local_count: int - Number of local transactions
     */
    public const BIDIRECTIONAL_SYNC_STARTED = 'sync.bidirectional_started';

    /**
     * Dispatched when a bidirectional sync completes
     *
     * Event data:
     *   - contact_pubkey: string - The public key of the contact
     *   - contact_address: string - The address of the contact
     *   - received_count: int - Number of transactions received
     *   - sent_count: int - Number of transactions sent
     *   - success: bool - Whether sync was successful
     */
    public const BIDIRECTIONAL_SYNC_COMPLETED = 'sync.bidirectional_completed';

    /**
     * Dispatched when all contacts have been synced
     *
     * Event data:
     *   - total: int - Total number of contacts processed
     *   - synced: int - Number of contacts successfully synced
     *   - failed: int - Number of contacts that failed to sync
     */
    public const ALL_CONTACTS_SYNCED = 'sync.all_contacts_synced';

    /**
     * Dispatched when all transactions have been synced
     *
     * Event data:
     *   - total_contacts: int - Total number of contacts processed
     *   - synced: int - Number of contacts successfully synced
     *   - failed: int - Number of contacts that failed to sync
     *   - total_transactions: int - Total number of transactions synced
     */
    public const ALL_TRANSACTIONS_SYNCED = 'sync.all_transactions_synced';

    /**
     * Dispatched when all balances have been synced
     *
     * Event data:
     *   - total_contacts: int - Total number of contacts processed
     *   - synced: int - Number of contacts successfully synced
     *   - failed: int - Number of contacts that failed to sync
     */
    public const ALL_BALANCES_SYNCED = 'sync.all_balances_synced';
}
