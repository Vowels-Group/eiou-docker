<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Lookup;

use Eiou\Contracts\PluginCallable;
use Eiou\Database\ContactRepository;

/**
 * ContactLookupService
 *
 * Read-only facade over ContactRepository, exposing the contact
 * resolution surface sandboxed plugins need to map a pubkey_hash
 * coming off the wire (e.g. inside a transaction.received envelope)
 * back to a human contact name / set of transport addresses. Mirrors
 * the gating + wrapper rationale of TransactionLookupService — see
 * that class for the broader rule about why repository methods don't
 * carry #[PluginCallable] directly.
 *
 * The shape returned by both methods is deliberately narrow:
 *   {name, http, https, tor, pubkey_hash}
 *
 * Other contact metadata (status, contact_id, credit limits, the
 * full pubkey, online_status timestamps, etc.) is omitted — plugins
 * that legitimately need that data have a different surface to ask
 * for it through. Exposing only the minimum lets the host tighten
 * later (rename a column, add a new status) without breaking the
 * plugin contract.
 *
 * Anything mutating (accept/block/delete) belongs in a different
 * service with its own gating; this one is strictly read-only by
 * design.
 */
class ContactLookupService
{
    /**
     * Hard cap on listAccepted() page size. Aligns with the same
     * "no bulk exfiltration via a single call" stance applied in
     * TransactionLookupService::MAX_PAGE_LIMIT — a plugin that wants
     * the full list paginates rather than asking for it at once.
     */
    public const MAX_PAGE_LIMIT = 500;

    private ContactRepository $repository;

    public function __construct(ContactRepository $repository)
    {
        $this->repository = $repository;
    }

    #[PluginCallable(
        description: 'Resolve a contact by SHA-256 pubkey_hash to {name, http, https, tor, pubkey_hash}. Returns null when no contact carries that hash. Use when a plugin sees a pubkey_hash on an inbound envelope and needs the human name or a transport address to relay back.',
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

    #[PluginCallable(
        description: 'List accepted contacts as {name, http, https, tor, pubkey_hash} rows. $limit is hard-capped at MAX_PAGE_LIMIT regardless of caller value; $offset is the zero-based page offset. Pending and blocked contacts are not exposed through this surface.',
        ratePerMinute: 30
    )]
    public function listAccepted(int $limit = 50, int $offset = 0): array
    {
        $bounded = max(0, min($limit, self::MAX_PAGE_LIMIT));
        $rows = $this->repository->getAcceptedContactsPage($bounded, max(0, $offset));
        $projected = [];
        foreach ($rows as $row) {
            $projected[] = $this->project($row);
        }
        return $projected;
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
