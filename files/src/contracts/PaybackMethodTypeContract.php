<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Contracts;

/**
 * Contract a plugin implements to register a new payback-method rail type
 * (e.g. "btc", "paypal", "bizum") into the node's catalog + validator +
 * masking pipeline.
 *
 * Core ships two types directly — `bank_wire` (with SEPA / Faster Payments
 * / ACH / FedNow / SWIFT sub-rails) and `custom` (free-form instructions).
 * Everything else is expected to arrive via `PaybackMethodTypeRegistry`.
 *
 * Registration happens in a plugin's `register()` phase:
 *
 *     $container
 *         ->getPaybackMethodTypeRegistry()
 *         ->register(new BtcType());
 *
 * The registry is consulted by:
 *   - `PaybackMethodTypeValidator::getCatalog()` — merges registered entries
 *     into the GUI type picker alongside the core entries.
 *   - `PaybackMethodTypeValidator::validate()` — delegates unknown types to
 *     the registered contract.
 *   - `PaybackMethodService::maskForType()` — delegates masking for the
 *     redacted list view.
 *   - `SettlementPrecisionService::defaultFor()` — consults the contract
 *     for rail-specific precision before falling back to generic defaults.
 */
interface PaybackMethodTypeContract
{
    /**
     * Unique machine id for this rail. Written to `payback_methods.type`.
     *
     * Must match `^[a-z][a-z0-9_]{0,31}$`. Must not collide with a core
     * id (`bank_wire`, `custom`) or another registered plugin's id.
     */
    public function getId(): string;

    /**
     * GUI catalog entry. Shape mirrors the per-type rows returned by
     * `PaybackMethodTypeValidator::getCatalog()`:
     *
     *     [
     *       'id'          => 'btc',
     *       'label'       => 'Bitcoin',
     *       'group'       => 'crypto',          // catalog group
     *       'icon'        => 'fab fa-bitcoin', // Font Awesome class
     *       'description' => '...',
     *       'currencies'  => ['BTC'],           // null = any ISO-4217
     *       'fields'      => [
     *         ['name' => 'address', 'label' => 'Address', 'type' => 'text',
     *          'required' => true, 'placeholder' => 'bc1q...'],
     *         ...
     *       ],
     *     ]
     *
     * The catalog is consumed by the GUI's two-step "Add method" modal.
     * Every plugin is responsible for keeping this shape in sync with
     * the fields its `validate()` inspects — there is no cross-check.
     */
    public function getCatalogEntry(): array;

    /**
     * Validate a {currency, fields} payload for this rail.
     *
     * Return an empty array on success or a list of error records shaped
     *   ['field' => string|null, 'code' => string, 'message' => string]
     * (`null` field = top-level error, e.g. currency-mismatch).
     *
     * Do not throw — every expected validation miss should surface as a
     * structured error record so the GUI can inline-highlight the field.
     *
     * @return list<array{field: string|null, code: string, message: string}>
     */
    public function validate(string $currency, array $fields): array;

    /**
     * Short redacted string shown on list rows before full reveal
     * (e.g. `bc1q…x8f2`, `u•••r@example.com`). Keep it under ~30 chars.
     *
     * Called only when the row's `type` matches this contract's id. If
     * the fields array is missing keys, return a graceful fallback
     * (e.g. `'•••'`) rather than throwing.
     */
    public function mask(array $fields): string;

    /**
     * Rail-specific settlement precision for a given currency. Return
     * `[min_unit, exponent]` where `min_unit` is the integer count of
     * the smallest divisible unit and `exponent` is its base-10 power
     * relative to the currency's major unit. Return `null` to defer to
     * `SettlementPrecisionService`'s generic fallback.
     *
     * Example: BTC at satoshi precision → `[1, -8]`. PayPal on any fiat
     * currency → `null` (generic cent-level applies).
     *
     * @return array{0: int, 1: int}|null
     */
    public function defaultPrecision(string $currency): ?array;
}
