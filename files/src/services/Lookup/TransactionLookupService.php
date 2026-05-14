<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Lookup;

use Eiou\Contracts\PluginCallable;
use Eiou\Database\TransactionRepository;

/**
 * TransactionLookupService
 *
 * Read-only facade over TransactionRepository, exposing the subset of
 * transaction queries that sandboxed plugins are allowed to invoke
 * through the gateway. Each plugin-callable method is decorated with
 * #[PluginCallable] so PluginGatewayController's reflection gate admits
 * it; methods without the attribute are refused even if a manifest
 * tries to allow-list them.
 *
 * Why a wrapper instead of decorating the repository directly:
 *
 *   - Repositories are obtained via RepositoryFactory, not direct
 *     ServiceContainer getters, so the gateway's `getXxx()` service-
 *     resolution convention can't reach them. A thin service layer
 *     bridges that without breaking the factory pattern.
 *   - The repository stays a pure data-access class; cross-cutting
 *     concerns (plugin scope, future audit logging, etc.) live here
 *     where they don't pollute the persistence layer.
 *   - Plugin manifests reference a stable service name
 *     ("TransactionLookupService.getByTxid") that is independent of
 *     repository refactors — if a method moves between repositories,
 *     this wrapper absorbs the change.
 *
 * Anything mutating belongs elsewhere by design. If a future plugin
 * needs to write transactions, that surface must be a separate service
 * with its own explicit gating, not an addition to this one.
 */
class TransactionLookupService
{
    /** Hard cap on bulk reads. The repository accepts arbitrary
     *  `$limit` values, which would let a plugin pull the entire
     *  received-transaction history in one call — a bulk-exfiltration
     *  primitive even though the same data is available incrementally
     *  via `transaction.received` events. The cap aligns this service
     *  with the surface policy ("prefer narrow lookups over bulk
     *  listings"); a plugin needing more rows paginates by calling
     *  again with the next page.
     */
    public const MAX_PAGE_LIMIT = 1000;

    private TransactionRepository $repository;

    public function __construct(TransactionRepository $repository)
    {
        $this->repository = $repository;
    }

    #[PluginCallable(description: 'Look up transaction rows by txid. Returns null when neither the live transactions table nor transactions_archive carries the txid.')]
    public function getByTxid(string $txid): ?array
    {
        return $this->repository->getByTxid($txid);
    }

    #[PluginCallable(description: 'Read just the status of a transaction by txid. Falls through to transactions_archive (archived rows are completed by construction).')]
    public function getStatusByTxid(string $txid): ?string
    {
        return $this->repository->getStatusByTxid($txid);
    }

    #[PluginCallable(description: 'Look up a transaction row by memo (the internal routing hash carried on transaction.received and transaction.sent envelopes). Returns null when no transaction carries that memo. Demand-driven — a plugin that has a memo already learned about the transaction\'s existence, so no enumerate-permission is needed.')]
    public function getByMemo(string $memo): ?array
    {
        return $this->repository->getByMemo($memo);
    }

    #[PluginCallable(description: 'Read just the status of a transaction by memo. Same demand-driven path as getByMemo; same trust shape as getStatusByTxid.')]
    public function getStatusByMemo(string $memo): ?string
    {
        return $this->repository->getStatusByMemo($memo);
    }

    #[PluginCallable(description: 'Check whether a txid has been observed in the live transactions table.')]
    public function existingTxid(string $txid): bool
    {
        return $this->repository->existingTxid($txid);
    }

    #[PluginCallable(description: 'Check whether a transaction by txid has reached the completed status.')]
    public function isCompletedByTxid(string $txid): bool
    {
        return $this->repository->isCompletedByTxid($txid);
    }

    #[PluginCallable(
        description: 'List the most-recent received transactions for the wallet user, optionally filtered by currency. $limit is hard-capped at MAX_PAGE_LIMIT regardless of caller value. Requires the transaction_history_enumerate permission in the plugin manifest in addition to the core_services entry.',
        permission: 'transaction_history_enumerate'
    )]
    public function getReceivedUserTransactions(int $limit = 10, ?string $currency = null): array
    {
        $bounded = max(0, min($limit, self::MAX_PAGE_LIMIT));
        return $this->repository->getReceivedUserTransactions($bounded, $currency);
    }

    #[PluginCallable(
        description: 'List the most-recent sent transactions for the wallet user, optionally filtered by currency. Mirrors getReceivedUserTransactions in shape and cap. Reconciliation and accounting plugins need this counterpart to walk what the operator has paid out, not just what they\'ve received. $limit is hard-capped at MAX_PAGE_LIMIT regardless of caller value. Requires the transaction_history_enumerate permission in the plugin manifest in addition to the core_services entry.',
        permission: 'transaction_history_enumerate'
    )]
    public function getSentUserTransactions(int $limit = 10, ?string $currency = null): array
    {
        $bounded = max(0, min($limit, self::MAX_PAGE_LIMIT));
        return $this->repository->getSentUserTransactions($bounded, $currency);
    }

    #[PluginCallable(
        description: 'List transactions between two pubkeys (in either direction). For plugins reconstructing a contact-relationship ledger — split-bill, periodic settlement, per-contact reconciliation. $limit is hard-capped at MAX_PAGE_LIMIT regardless of caller value. Requires the transaction_history_enumerate permission since the caller is implicitly walking the wallet\'s history filtered by counterparty.',
        permission: 'transaction_history_enumerate'
    )]
    public function getTransactionsBetweenPubkeys(string $pubkey1, string $pubkey2, int $limit = 0): array
    {
        // limit == 0 in the repository means "no cap"; the gateway can't
        // accept that since a single call would pull arbitrary history.
        // Clamp 0 / negative / oversized values to MAX_PAGE_LIMIT.
        if ($limit <= 0 || $limit > self::MAX_PAGE_LIMIT) {
            $limit = self::MAX_PAGE_LIMIT;
        }
        return $this->repository->getTransactionsBetweenPubkeys($pubkey1, $pubkey2, $limit);
    }
}
