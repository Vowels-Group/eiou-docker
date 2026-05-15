<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Lookup;

use Eiou\Contracts\PluginCallable;
use Eiou\Core\SplitAmount;
use Eiou\Database\PaymentRequestRepository;

/**
 * PaymentRequestLookupService
 *
 * Read-only facade over PaymentRequestRepository, exposing payment
 * requests (both incoming and outgoing) to sandboxed plugins. Per-
 * request lookups by id are demand-driven and don't require a
 * permission — a plugin that minted a request via
 * PaymentRequestService.create already has its id and learned about
 * the request through that mint. The enumerate forms reveal the
 * operator's pending-debts and pending-receivables lists, so they
 * gate on `payment_request_enumerate`.
 *
 * Projection shape (per request):
 *
 *   {
 *     request_id, direction, status,
 *     requester_pubkey_hash, recipient_pubkey_hash, contact_name,
 *     amount: {whole, frac, minor_units, display},
 *     currency, description,
 *     created_at, responded_at, resulting_txid
 *   }
 *
 * Repository columns deliberately omitted: `requester_address`
 * (transport detail the host can rework without breaking the plugin
 * contract), `signed_message_content` (raw audit crypto, too large
 * to ship across the gateway), `id` (internal autoincrement),
 * `expires_at` (reserved for future TTL behaviour — exposing now
 * pins us to its current null-by-default shape).
 *
 * Anything mutating belongs in PaymentRequestService — this
 * surface is strictly read-only.
 */
class PaymentRequestLookupService
{
    /**
     * Hard cap on bulk-list page size. Same anti-exfiltration stance
     * as the other Lookup services: a plugin needing more rows pages
     * by calling again with a smaller window rather than asking for
     * everything at once.
     */
    public const MAX_PAGE_LIMIT = 500;

    private PaymentRequestRepository $repository;

    public function __construct(PaymentRequestRepository $repository)
    {
        $this->repository = $repository;
    }

    #[PluginCallable(
        description: 'Look up a payment request by its request_id (the SHA-256 hex returned by PaymentRequestService.create). Returns the projected request shape or null when no request carries that id. Falls through to the archive table so audit/reconciliation paths still resolve historical ids. Demand-driven — no permission required since the plugin already has the id.',
        ratePerMinute: 120
    )]
    public function getByRequestId(string $requestId): ?array
    {
        $trimmed = trim($requestId);
        if ($trimmed === '') {
            return null;
        }
        $row = $this->repository->getByRequestId($trimmed);
        if ($row === null) {
            return null;
        }
        return $this->project($row);
    }

    #[PluginCallable(
        description: 'List incoming payment requests whose status is pending (people who have asked the operator to pay but the operator has not yet responded). Returns most-recent-first. Dunning / "you have outstanding bills" plugins use this. Requires the payment_request_enumerate permission — distinct from per-id lookups because enumeration reveals the operator\'s pending-debts list.',
        ratePerMinute: 30,
        permission: 'payment_request_enumerate'
    )]
    public function listPendingIncoming(): array
    {
        $rows = $this->repository->getPendingIncoming();
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->project($row);
        }
        return $out;
    }

    #[PluginCallable(
        description: 'List outgoing payment requests (those the operator has minted, asking a contact to pay). Returns most-recent-first, all statuses included. $limit is hard-capped at MAX_PAGE_LIMIT. Invoicing dashboards and follow-up plugins use this to track which requests have been answered. Requires the payment_request_enumerate permission.',
        ratePerMinute: 30,
        permission: 'payment_request_enumerate'
    )]
    public function listOutgoing(int $limit = 50): array
    {
        $bounded = max(0, min($limit, self::MAX_PAGE_LIMIT));
        $rows = $this->repository->getAllOutgoing($bounded);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->project($row);
        }
        return $out;
    }

    /**
     * Reduce a payment_requests row to the documented plugin-facing
     * shape. SplitAmount-typed amount fields are projected to the
     * standard {whole, frac, minor_units, display} form; DATETIME
     * columns become Unix epoch seconds.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function project(array $row): array
    {
        $amount = $row['amount'] ?? null;
        if (!($amount instanceof SplitAmount)) {
            // Repository normally returns a SplitAmount for `amount`
            // via mapRows; defence in depth against drift in the
            // contract — fall back to a zero projection rather than
            // returning a malformed row across the gateway.
            $amount = SplitAmount::zero();
        }
        return [
            'request_id'             => (string) ($row['request_id'] ?? ''),
            'direction'              => (string) ($row['direction'] ?? ''),
            'status'                 => (string) ($row['status'] ?? ''),
            'requester_pubkey_hash'  => (string) ($row['requester_pubkey_hash'] ?? ''),
            'recipient_pubkey_hash'  => (string) ($row['recipient_pubkey_hash'] ?? ''),
            'contact_name'           => isset($row['contact_name']) ? (string) $row['contact_name'] : null,
            'amount'                 => [
                'whole'       => $amount->whole,
                'frac'        => $amount->frac,
                'minor_units' => $amount->toMinorUnits(),
                'display'     => $amount->toDisplayString(8),
            ],
            'currency'               => (string) ($row['currency'] ?? ''),
            'description'            => isset($row['description']) ? (string) $row['description'] : null,
            'created_at'             => $this->toEpoch($row['created_at'] ?? null),
            'responded_at'           => $this->toEpoch($row['responded_at'] ?? null),
            'resulting_txid'         => isset($row['resulting_txid']) ? (string) $row['resulting_txid'] : null,
        ];
    }

    /**
     * Convert a DATETIME string (or already-int epoch) to Unix
     * seconds. Plugins work in epoch ints rather than SQL DATETIME
     * strings so there's no ambiguity about timezone or format.
     */
    private function toEpoch(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $ts = strtotime($value);
            return $ts === false ? null : $ts;
        }
        return null;
    }
}
