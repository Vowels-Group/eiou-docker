<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

/**
 * Maps (payback-method type, currency) → smallest settleable integer unit.
 *
 * Several rails quote amounts with finer granularity than the node's ledger
 * can represent (Constants::INTERNAL_PRECISION is 8). Those sub-10⁻⁸ quanta
 * are unused at the ledger level. This service publishes the integer unit
 * at which a rail can actually settle so the settlement UI can round up
 * and show the resulting dust.
 *
 * Units are expressed as:
 *   - `settlement_min_unit`: integer count of the currency's smallest divisible unit.
 *   - `settlement_min_unit_exponent`: base-10 exponent relative to the currency's
 *      major unit (satoshi = -8, cent = -2, wei-capped-at-node-floor = -8, msat-
 *      truncated-to-sat = -8, lamport-rounded-to-10^-8 = -8).
 *
 * Callers should round UP when converting owed amount → settled amount so that
 * dust always favours the recipient.
 */
class SettlementPrecisionService
{
    /**
     * Defaults per (type, currency). Returned tuple is [min_unit, exponent].
     * When a (type, currency) pair is not listed, the generic default is 1 × 10⁻²
     * (cent-level) for fiat and 1 × 10⁻⁸ for crypto.
     *
     * Rationale per row:
     *   - BTC settles at satoshi: 1 × 10⁻⁸ BTC.
     *   - Lightning quotes msat but the node ledger is satoshi-capped → 1 × 10⁻⁸.
     *   - EVM native settles at wei but the node ledger floors at 10⁻⁸ → 1 × 10⁻⁸.
     *   - Bank wire settles at cent (10⁻²) regardless of the node's 10⁻⁸.
     *   - Custom uses the generic fallback (fiat → cent, other → 10⁻⁸).
     *
     * Plugin-registered rail types contribute their own precision via the
     * `PaybackMethodTypeContract::defaultPrecision()` method, which this
     * service consults before falling back to `genericFor()`.
     */
    private const DEFAULTS = [
        // bank_wire + custom both defer to the generic fiat/crypto fallback.
        // No per-type override needed — listing them explicitly would duplicate
        // `genericFor()`. Plugin rail types extend this table via the registry.
    ];

    private ?PaybackMethodTypeRegistry $registry;

    public function __construct(?PaybackMethodTypeRegistry $registry = null)
    {
        $this->registry = $registry;
    }

    /**
     * Resolve the default settlement precision for a type + currency.
     *
     * @return array{0: int, 1: int} [min_unit, exponent]
     */
    public function defaultFor(string $type, string $currency): array
    {
        if (isset(self::DEFAULTS[$type][$currency])) {
            return self::DEFAULTS[$type][$currency];
        }
        if (isset(self::DEFAULTS[$type]['*'])) {
            return self::DEFAULTS[$type]['*'];
        }
        // Plugin contract gets a chance before the generic fiat/crypto split.
        if ($this->registry !== null) {
            $contract = $this->registry->get($type);
            if ($contract !== null) {
                $custom = $contract->defaultPrecision($currency);
                if (is_array($custom) && count($custom) === 2
                        && is_int($custom[0]) && is_int($custom[1])) {
                    return $custom;
                }
            }
        }
        return $this->genericFor($currency);
    }

    /**
     * Fallback precision for (type, currency) pairs without a specific entry.
     * Fiat = cent; anything else = 10⁻⁸ to match the node's internal precision.
     */
    private function genericFor(string $currency): array
    {
        $fiat = [
            'USD', 'EUR', 'GBP', 'JPY', 'CHF', 'CAD', 'AUD', 'NZD',
            'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON', 'BGN',
            'BRL', 'MXN', 'COP', 'CLP', 'ARS', 'PEN', 'UYU',
            'INR', 'CNY', 'HKD', 'SGD', 'TWD', 'KRW', 'THB', 'MYR', 'IDR', 'PHP', 'VND',
            'ILS', 'AED', 'SAR', 'QAR', 'ZAR', 'EGP', 'NGN', 'KES', 'MAD', 'TND',
            'TRY', 'UAH', 'RUB',
        ];
        if (in_array($currency, $fiat, true)) {
            // JPY, KRW, CLP have zero decimal places in practice but the ISO table
            // officially lists them with ad-hoc exponents; callers that care about
            // currency-accurate fiat display should override per-method.
            if (in_array($currency, ['JPY', 'KRW', 'CLP', 'VND', 'IDR', 'UYU'], true)) {
                return [1, 0];
            }
            return [1, -2];
        }
        return [1, -8];
    }

    /**
     * Round a bcmath decimal string UP to the nearest multiple of the method's
     * settlement_min_unit (expressed via exponent). Used by the settlement flow
     * to show the user how their owed amount lands in the rail's native units.
     *
     * Example: owed = "0.123456789", exponent = -8 → returns "0.12345679"
     * Example: owed = "10.505",      exponent = -2 → returns "10.51"
     *
     * @param string $owedMajorUnits bcmath decimal string (major units, e.g. "0.123")
     * @param int $exponent negative exponent of the min unit (e.g. -8 for satoshi)
     */
    public function roundUpToMinUnit(string $owedMajorUnits, int $exponent): string
    {
        if ($exponent > 0) {
            // Min unit larger than 1 major unit — unusual but round up to the multiple.
            $scale = 0;
        } else {
            $scale = -$exponent;
        }

        // Normalise input: strip trailing zeros via bcadd with 0.
        $normalised = \bcadd($owedMajorUnits, '0', 40);

        // Already at or below target scale.
        if (\bccomp(\bcadd($normalised, '0', $scale), $normalised, 40) === 0) {
            return \bcadd($normalised, '0', $scale);
        }

        // Round up: add (unit - epsilon) then truncate.
        $unit = $this->unitString($exponent);
        // Smallest epsilon at the input scale.
        $epsilon = '0.' . str_repeat('0', 39) . '1';
        $bumped = \bcsub(\bcadd($normalised, $unit, 40), $epsilon, 40);
        return \bcadd($bumped, '0', $scale);
    }

    /**
     * Build a bcmath-safe decimal string representing 1 × 10^exponent.
     */
    private function unitString(int $exponent): string
    {
        if ($exponent >= 0) {
            return str_pad('1', $exponent + 1, '0', STR_PAD_RIGHT);
        }
        $abs = -$exponent;
        return '0.' . str_repeat('0', $abs - 1) . '1';
    }
}
