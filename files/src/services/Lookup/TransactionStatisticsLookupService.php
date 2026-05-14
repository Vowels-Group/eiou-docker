<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Lookup;

use Eiou\Contracts\PluginCallable;
use Eiou\Core\SplitAmount;
use Eiou\Database\TransactionStatisticsRepository;

/**
 * TransactionStatisticsLookupService
 *
 * Read-only facade over TransactionStatisticsRepository, exposing
 * aggregate statistics over the wallet's transaction history to
 * sandboxed plugins. Same Lookup/ subdirectory + #[PluginCallable]
 * gating pattern as TransactionLookupService.
 *
 * Trust shape sits between per-txid lookups and full enumerate:
 * aggregates disclose volume (count, total) without revealing
 * individual counterparties or memos. That makes a separate
 * permission key (`transaction_history_aggregate`) the right tool
 * for dashboard / daily-summary plugins, which can compute totals
 * without unlocking the row-level walks gated by
 * `transaction_history_enumerate`. An operator who is comfortable
 * with "this app shows me my daily volume" but not "this app reads
 * every transaction" can grant aggregate without enumerate.
 *
 * Anything mutating belongs elsewhere by design. If a plugin needs
 * to write to the statistics path, that surface must be a separate
 * service with its own gating.
 */
class TransactionStatisticsLookupService
{
    /**
     * Hard cap on the period a plugin can query in one call. Stops
     * a hostile plugin from pinning the DB on an unbounded scan;
     * roughly five years covers the "I want to see my year-to-date"
     * use case with headroom. Plugins needing longer history can
     * page by calling again with the next window.
     */
    public const MAX_PERIOD_SECONDS = 5 * 365 * 86400;

    private TransactionStatisticsRepository $repository;

    public function __construct(TransactionStatisticsRepository $repository)
    {
        $this->repository = $repository;
    }

    #[PluginCallable(
        description: 'Aggregate count + total_amount for transactions in a Unix-epoch-second time window, optionally filtered by currency. $startTs is inclusive, $endTs is exclusive; the maximum window length is MAX_PERIOD_SECONDS (~5 years) — pass overlapping pages for longer history. Returns {count, total_amount:{whole, frac, minor_units, display}}. Used by dashboard / daily-summary plugins to compute volume without walking individual rows. Requires the transaction_history_aggregate permission in the plugin manifest in addition to the core_services entry — distinct from transaction_history_enumerate because aggregates leak volume but not counterparties.',
        ratePerMinute: 60,
        permission: 'transaction_history_aggregate'
    )]
    public function getStatsForPeriod(int $startTs, int $endTs, ?string $currency = null): array
    {
        // Clamp to a sane window. A plugin passing $endTs < $startTs
        // (or the same value) gets zero rather than a SQL error.
        if ($endTs <= $startTs) {
            return $this->zeroResult();
        }
        // Cap the window so a single call can't trigger an unbounded
        // scan; advisory in nature since the underlying table is
        // indexed on timestamp.
        if ($endTs - $startTs > self::MAX_PERIOD_SECONDS) {
            $endTs = $startTs + self::MAX_PERIOD_SECONDS;
        }

        $normalisedCurrency = null;
        if ($currency !== null) {
            $trimmed = trim($currency);
            if ($trimmed === '') {
                return $this->zeroResult();
            }
            $normalisedCurrency = $trimmed;
        }

        $row = $this->repository->getStatisticsForPeriod($startTs, $endTs, $normalisedCurrency);
        $totalAmount = $row['total_amount'] ?? null;
        if (!($totalAmount instanceof SplitAmount)) {
            return $this->zeroResult();
        }
        return [
            'count'        => (int) ($row['count'] ?? 0),
            'total_amount' => $this->projectAmount($totalAmount),
        ];
    }

    /**
     * @return array{count:int, total_amount:array{whole:int, frac:int, minor_units:int, display:string}}
     */
    private function zeroResult(): array
    {
        return [
            'count'        => 0,
            'total_amount' => $this->projectAmount(SplitAmount::zero()),
        ];
    }

    /**
     * @return array{whole:int, frac:int, minor_units:int, display:string}
     */
    private function projectAmount(SplitAmount $amount): array
    {
        return [
            'whole'       => $amount->whole,
            'frac'        => $amount->frac,
            'minor_units' => $amount->toMinorUnits(),
            'display'     => $amount->toDisplayString(8),
        ];
    }
}
