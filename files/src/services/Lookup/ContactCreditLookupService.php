<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Lookup;

use Eiou\Contracts\PluginCallable;
use Eiou\Core\SplitAmount;
use Eiou\Database\ContactCreditRepository;

/**
 * ContactCreditLookupService
 *
 * Read-only facade over ContactCreditRepository, exposing per-contact
 * available-credit state to sandboxed plugins. Auto-settle and
 * credit-policy plugins use this to decide whether a contact is
 * within the operator's extended credit before approving or routing
 * a transaction. Gated on the `contact_credit_read` permission —
 * credit limits are operator-set financial policy, distinct from
 * the contact's identity (resolved without permission) and from the
 * wallet's own balance (gated on `wallet_balance_read`).
 *
 * Returned shape for getCreditState (currency given):
 *
 *   { currency, available_credit: {whole, frac, minor_units, display} }
 *   or null if the contact has no row in that currency (or the
 *   contact doesn't carry an accepted contact-currency entry).
 *
 * For the all-currencies form: a list of the above (without the
 * null-on-missing — empty list when the contact has no credit
 * rows).
 *
 * Same projection convention as BalanceLookupService — minor_units
 * is the lossless signed integer for arithmetic; display is a
 * pre-formatted decimal for UI; whole/frac mirror SplitAmount.
 */
class ContactCreditLookupService
{
    private ContactCreditRepository $repository;

    public function __construct(ContactCreditRepository $repository)
    {
        $this->repository = $repository;
    }

    #[PluginCallable(
        description: 'Read the operator-configured available credit for a contact, by pubkey_hash. Pass a currency to get a single {currency, available_credit} row (null if no entry exists). Omit currency to get a list of {currency, available_credit} rows across every currency the contact has an accepted entry for. `available_credit` is {whole, frac, minor_units, display} — same projection as BalanceLookupService. Auto-settle plugins use this to gate whether a contact is within their extended credit. Requires the contact_credit_read permission.',
        ratePerMinute: 60,
        permission: 'contact_credit_read'
    )]
    public function getCreditState(string $pubkeyHash, ?string $currency = null): mixed
    {
        $hash = strtolower(trim($pubkeyHash));
        if ($hash === '') {
            return $currency === null ? [] : null;
        }

        if ($currency !== null) {
            $trimmed = trim($currency);
            if ($trimmed === '') {
                return null;
            }
            $row = $this->repository->getAvailableCredit($hash, $trimmed);
            if ($row === null) {
                return null;
            }
            return $this->projectRow($row);
        }

        $rows = $this->repository->getAvailableCreditAllCurrencies($hash);
        $out = [];
        foreach ($rows as $row) {
            $projected = $this->projectRow($row);
            if ($projected !== null) {
                $out[] = $projected;
            }
        }
        return $out;
    }

    /**
     * @param array{available_credit:SplitAmount, currency:string} $row
     * @return array{currency:string, available_credit:array{whole:int,frac:int,minor_units:int,display:string}}|null
     */
    private function projectRow(array $row): ?array
    {
        $currency = isset($row['currency']) ? (string) $row['currency'] : '';
        $credit = $row['available_credit'] ?? null;
        if ($currency === '' || !($credit instanceof SplitAmount)) {
            return null;
        }
        return [
            'currency'         => $currency,
            'available_credit' => [
                'whole'       => $credit->whole,
                'frac'        => $credit->frac,
                'minor_units' => $credit->toMinorUnits(),
                'display'     => $credit->toDisplayString(8),
            ],
        ];
    }
}
