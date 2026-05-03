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
 */
class ContactEvents
{
    /**
     * Dispatched after a new contact record is created locally.
     *
     * Emitted from ContactSyncService::insertContactWithEvent() (which wraps
     * every `contactRepository->insertContact()` call site), from
     * ContactSyncService::addPendingContactWithEvent() for incoming-request
     * pending rows, and from ContactStatusService when a wallet-restore
     * sync creates a pending row for a previously-known peer. Subscribers
     * for the pending case see `name` = null / `currency` = null; re-query
     * the contact after CONTACT_ACCEPTED fires for the enriched record.
     *
     * Event data:
     *   - pubkey: string           - Contact public key
     *   - name: string|null        - Contact display name (null on pending rows)
     *   - currency: string|null    - Initial currency (null on pending rows)
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
     * Dispatched when an incoming contact request is auto-rejected by the
     * node — currently fires when the request carries a currency that is
     * not in `allowedCurrencies` and `autoRejectUnknownCurrency` is on.
     *
     * Emitted by ContactSyncService::handleContactCreation() before the
     * rejection payload goes on the wire.
     *
     * Event data:
     *   - pubkey: string           - Contact public key
     *   - address: string          - Sender transport address
     *   - reason: string           - Machine-readable reason (e.g. 'currency_not_accepted')
     *   - currency: string|null    - Offending currency code when applicable
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
