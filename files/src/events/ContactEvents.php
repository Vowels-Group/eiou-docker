<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Events;

/**
 * Contact Events
 *
 * Event constants for contact lifecycle. Plugins subscribe via EventDispatcher
 * to react to contact relationship changes.
 *
 * Usage:
 *   EventDispatcher::getInstance()->subscribe(ContactEvents::CONTACT_ACCEPTED, function($data) {
 *       $pubkey = $data['pubkey'];
 *       $name   = $data['name'];
 *       // Welcome webhook, initial audit log entry, etc.
 *   });
 *
 * Not every constant has an emission site in core yet — see docs/PLUGINS.md
 * for current dispatch coverage. Constants are stable so subscriptions
 * against them today will keep working once emit points land.
 */
class ContactEvents
{
    /**
     * Dispatched after a new contact record is created locally.
     *
     * Not yet emitted in core — the contact creation path is split across
     * outgoing-request / incoming-accept / P2P-sync flows without a single
     * service-level commit point. Planned for a future release.
     *
     * Event data (when emitted):
     *   - pubkey: string   - Contact public key
     *   - name: string     - Contact display name
     *   - address: string  - Contact transport address
     */
    public const CONTACT_ADDED = 'contact.added';

    /**
     * Dispatched when a pending contact request is accepted locally.
     *
     * Emitted by ContactManagementService::acceptContact() after the DB-side
     * acceptance succeeds.
     *
     * Event data:
     *   - pubkey: string   - Contact public key
     *   - name: string     - Contact display name
     *   - currency: string - Currency code the contact was accepted with
     *   - fee: float       - Fee percentage configured
     *   - credit: float    - Credit limit configured
     */
    public const CONTACT_ACCEPTED = 'contact.accepted';

    /**
     * Dispatched when a pending contact request is rejected locally.
     *
     * Not yet emitted in core — rejection lives in GUI-only handlers today
     * (no shared service-layer method). Planned for a future release.
     *
     * Event data (when emitted):
     *   - pubkey: string - Contact public key
     *   - reason: string|null - Optional rejection reason
     */
    public const CONTACT_REJECTED = 'contact.rejected';

    /**
     * Dispatched when a contact is blocked.
     *
     * Emitted by ContactManagementService::blockContact() after the block
     * takes effect in the DB.
     *
     * Event data:
     *   - pubkey: string - Contact public key
     *   - address: string|null - Contact transport address (if resolved)
     */
    public const CONTACT_BLOCKED = 'contact.blocked';
}
