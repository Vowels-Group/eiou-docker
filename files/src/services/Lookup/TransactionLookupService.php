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

    #[PluginCallable(description: 'List the most-recent received transactions for the wallet user, optionally filtered by currency. Bounded by $limit.')]
    public function getReceivedUserTransactions(int $limit = 10, ?string $currency = null): array
    {
        return $this->repository->getReceivedUserTransactions($limit, $currency);
    }
}
