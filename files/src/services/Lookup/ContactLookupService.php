<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Lookup;

use Eiou\Contracts\PluginCallable;
use Eiou\Database\ContactRepository;

/**
 * ContactLookupService
 *
 * Read-only facade over ContactRepository, exposing the single
 * contact-resolution surface sandboxed plugins need to map a
 * pubkey_hash coming off the wire (e.g. inside a transaction.received
 * envelope) back to a human contact name / set of transport
 * addresses. Mirrors the gating + wrapper rationale of
 * TransactionLookupService — see that class for the broader rule
 * about why repository methods don't carry #[PluginCallable] directly.
 *
 * Returned shape is deliberately narrow:
 *   {name, http, https, tor, pubkey_hash}
 *
 * Other contact metadata (status, contact_id, credit limits, the
 * full pubkey, online_status timestamps, etc.) is omitted — plugins
 * that legitimately need that data have a different surface to ask
 * for it through. Exposing only the minimum lets the host tighten
 * later (rename a column, add a new status) without breaking the
 * plugin contract.
 *
 * Demand-driven only by design — no bulk enumeration:
 *
 *   The earlier shape of this service exposed both a per-hash lookup
 *   AND a `listAccepted()` bulk method. That mix turned the surface
 *   into a contact-graph exfiltration primitive: a plugin allow-
 *   listed for the "list" verb could enumerate the operator's
 *   entire address book (including .onion addresses and operator-
 *   private contact labels) regardless of whether it had any
 *   legitimate workflow involving those contacts. By contrast, a
 *   `transaction.received` subscriber learns about contacts
 *   incidentally — only those the plugin actually interacts with.
 *   The bulk surface was removed before any in-tree plugin
 *   depended on it. If a future plugin ships a genuine bulk-list
 *   use case, the right path is a new method with operator-
 *   visible manifest gating that distinguishes "I only resolve
 *   hashes I've already seen" from "I enumerate the address book."
 *
 * Anything mutating (accept/block/delete) belongs in a different
 * service with its own gating; this one is strictly read-only by
 * design.
 */
class ContactLookupService
{
    private ContactRepository $repository;

    public function __construct(ContactRepository $repository)
    {
        $this->repository = $repository;
    }

    #[PluginCallable(
        description: 'Resolve a contact by SHA-256 pubkey_hash to {name, http, https, tor, pubkey_hash}. Returns null when no contact carries that hash. Use when a plugin sees a pubkey_hash on an inbound envelope (e.g. transaction.received) and needs the human name or a transport address to relay back. The host does NOT expose a bulk-enumerate method — plugins are expected to learn pubkey_hashes through events rather than walk the address book.',
        ratePerMinute: 120
    )]
    public function getByPubkeyHash(string $pubkeyHash): ?array
    {
        // Normalise. The repository takes the raw value, but plugins
        // can ship it pre-lowercased or with stray whitespace; absorb
        // that here so a single-character casing difference doesn't
        // appear as a missing contact.
        $hash = strtolower(trim($pubkeyHash));
        if ($hash === '') {
            return null;
        }
        $row = $this->repository->lookupByPubkeyHash($hash);
        if ($row === null) {
            return null;
        }
        return $this->project($row);
    }

    /**
     * Reduce a full contacts-joined-with-addresses row to the narrow
     * shape this service contracts on. Keeping the projection in one
     * place means a future repository column doesn't accidentally
     * leak into a plugin's view of a contact.
     */
    private function project(array $row): array
    {
        return [
            'name'        => isset($row['name']) ? (string) $row['name'] : null,
            'http'        => isset($row['http']) ? (string) $row['http'] : null,
            'https'       => isset($row['https']) ? (string) $row['https'] : null,
            'tor'         => isset($row['tor']) ? (string) $row['tor'] : null,
            'pubkey_hash' => isset($row['pubkey_hash']) ? (string) $row['pubkey_hash'] : null,
        ];
    }
}
