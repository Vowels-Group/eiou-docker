<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Events;

/**
 * P2P Events
 *
 * Event constants for P2P (relayed) transaction lifecycle. Plugins subscribe
 * via EventDispatcher to react to multi-hop transaction routing.
 *
 * Usage:
 *   EventDispatcher::getInstance()->subscribe(P2pEvents::P2P_RECEIVED, function($data) {
 *       // Fire off an analytics event, push a mobile notification, etc.
 *   });
 *
 */
class P2pEvents
{
    /**
     * Dispatched when an inbound P2P transaction arrives (relay leg or final).
     *
     * Emitted by TransactionProcessingService::processIncomingP2p() when the
     * incoming P2P leg has been persisted.
     *
     * Event data:
     *   - p2p_id: string        - P2P route identifier
     *   - txid: string          - Transaction ID for this leg
     *   - amount: string|null   - Amount in major units
     *   - currency: string|null - Currency code
     *   - sender_pubkey: string|null - Sender public key (previous hop)
     */
    public const P2P_RECEIVED = 'p2p.received';

    /**
     * Dispatched after the operator approves a P2P transaction.
     *
     * Emitted by P2pApprovalService::approve() — the shared commit point
     * that CLI, REST API, and GUI all route through, so the event fires
     * once per operator decision regardless of entry surface.
     *
     * Event data:
     *   - p2p_id: string          - P2P route identifier
     *   - amount: string|null     - Amount in major units
     *   - currency: string|null   - Currency code
     *   - sender_address: string|null - Route's immediate sender address
     *   - mode: string            - 'candidate' (explicit selection) or 'fast'
     */
    public const P2P_APPROVED = 'p2p.approved';

    /**
     * Dispatched after the operator rejects a P2P transaction.
     *
     * Emitted by P2pApprovalService::reject() — shared across CLI/API/GUI.
     *
     * Event data:
     *   - p2p_id: string - P2P route identifier
     */
    public const P2P_REJECTED = 'p2p.rejected';

    /**
     * Dispatched when a P2P transaction reaches its final destination.
     *
     * Emitted by TransactionProcessingService::processIncomingP2p() when the
     * local node is the end recipient (not a relay hop).
     *
     * Event data:
     *   - p2p_id: string        - P2P route identifier
     *   - txid: string          - Transaction ID for the final leg
     *   - amount: string|null   - Amount in major units
     *   - currency: string|null - Currency code
     *   - sender_pubkey: string|null - Originator public key (if known)
     */
    public const P2P_COMPLETED = 'p2p.completed';
}
