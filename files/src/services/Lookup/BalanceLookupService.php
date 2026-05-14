<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Lookup;

use Eiou\Contracts\PluginCallable;
use Eiou\Core\SplitAmount;
use Eiou\Database\BalanceRepository;

/**
 * BalanceLookupService
 *
 * Read-only facade over BalanceRepository, exposing the wallet's
 * own balance totals (overall and per-currency) to sandboxed
 * plugins. Same Lookup/ subdirectory + #[PluginCallable] gating
 * pattern as TransactionLookupService and ContactLookupService.
 *
 * Trust shape: every call discloses the operator's net financial
 * position to the plugin — sum of all received minus all sent,
 * per currency. That's qualitatively different from per-txid
 * lookups (one transaction's worth of data) and from contact
 * resolution (no money disclosed at all), so the methods carry
 * the `wallet_balance_read` permission key in addition to the
 * usual `core_services` allow-list. Operators see "this plugin
 * may read your wallet balance" as a distinct line item in the
 * plugin modal before enabling.
 *
 * Returned shape (currency given):
 *
 *   {
 *     currency: "USD",
 *     balance: { whole, frac, minor_units, display }
 *   }
 *
 * Returned shape (currency null):
 *
 *   list of the above, one entry per currency the wallet has
 *   any history in.
 *
 * The balance projection carries multiple representations so
 * plugins doing arithmetic and plugins rendering output can both
 * use the form that fits without re-deriving — `minor_units` is
 * the lossless signed integer form (use this for math); `display`
 * is a decimal string (use this for UI). `whole`/`frac` mirror
 * the internal SplitAmount representation for plugins that
 * already speak it; note that negative amounts are encoded with
 * a negative `whole` plus a non-negative `frac` (e.g. -1.50 is
 * whole=-2, frac=50000000), so plugins doing arithmetic on the
 * pair must mirror that convention or use `minor_units` instead.
 *
 * Anything mutating belongs in WalletOutboundService or
 * BalanceService directly; this surface is strictly read-only.
 */
class BalanceLookupService
{
    private BalanceRepository $repository;

    public function __construct(BalanceRepository $repository)
    {
        $this->repository = $repository;
    }

    #[PluginCallable(
        description: 'Read the wallet\'s total balance. With no $currency: returns a list of {currency, balance} entries, one per currency the wallet has history in. With $currency: returns a single {currency, balance} entry or null when the wallet has no rows in that currency. `balance` is {whole, frac, minor_units, display} — use minor_units for arithmetic (lossless integer), display for UI rendering. Negative balances (net-paid-out) carry whole < 0. Requires the wallet_balance_read permission in the plugin manifest in addition to the core_services entry.',
        ratePerMinute: 60,
        permission: 'wallet_balance_read'
    )]
    public function getUserBalance(?string $currency = null): ?array
    {
        if ($currency !== null) {
            $trimmed = trim($currency);
            if ($trimmed === '') {
                return null;
            }
            $amount = $this->repository->getUserBalanceCurrency($trimmed);
            // Empty currency → SplitAmount::zero(). We can't
            // distinguish that from a real zero balance from this
            // method alone, so return the zero row rather than null;
            // a plugin gating on existence should call with the
            // currency-list form and check for that currency's
            // presence.
            return [
                'currency' => $trimmed,
                'balance'  => $this->projectAmount($amount),
            ];
        }

        $rows = $this->repository->getUserBalance();
        if ($rows === null || $rows === []) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $rowCurrency = isset($row['currency']) ? (string) $row['currency'] : '';
            $totalBalance = $row['total_balance'] ?? null;
            if ($rowCurrency === '' || !($totalBalance instanceof SplitAmount)) {
                continue;
            }
            $out[] = [
                'currency' => $rowCurrency,
                'balance'  => $this->projectAmount($totalBalance),
            ];
        }
        return $out;
    }

    /**
     * Materialise a SplitAmount into the plugin-facing shape.
     *
     * Carrying both `minor_units` and `display` is deliberate:
     * plugins that branch on balance ("don't spend if below X")
     * use the integer form; plugins surfacing the value in their
     * own UI use the display string. Re-deriving one from the
     * other across the gateway boundary would either lose
     * precision (float) or push currency-decimals logic onto
     * every plugin author.
     *
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
