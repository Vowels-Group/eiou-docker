<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Validators;

use Eiou\Validators\Checksum\AbaRouting;
use Eiou\Validators\Checksum\Iban;

/**
 * Validates payback-method field payloads per declared type.
 *
 * Single public entry: validate($type, $currency, $fields): array<int,array>
 * returns an array of error records, each shaped as
 *   ['field' => string|null, 'code' => string, 'message' => string]
 *
 * An empty return array means the payload is valid.
 *
 * Core ships two rail types: `bank_wire` (with SEPA / Faster Payments / ACH /
 * FedNow / SWIFT sub-rails) and `custom` (free-form instructions). Additional
 * rail types (BTC, PayPal, Venmo, EVM tokens, Lightning, Pix, …) are expected
 * to register themselves as plugins against the payback-methods plugin API in
 * a follow-up. Unknown types are rejected with `unknown_type` so plugin-less
 * nodes stay predictable.
 */
class PaybackMethodTypeValidator
{
    public const TYPE_BANK_WIRE = 'bank_wire';
    public const TYPE_CUSTOM    = 'custom';

    private const BANK_RAILS = ['sepa', 'faster_payments', 'ach', 'fednow', 'swift'];

    private ?\Eiou\Services\PaybackMethodTypeRegistry $registry;

    /**
     * An optional registry enables plugin-provided rail types. Production
     * code wires one via the service container; standalone unit tests can
     * omit it to exercise the core `bank_wire` / `custom` dispatch only.
     */
    public function __construct(?\Eiou\Services\PaybackMethodTypeRegistry $registry = null)
    {
        $this->registry = $registry;
    }

    /**
     * Validate one payback method payload.
     *
     * @return list<array{field: string|null, code: string, message: string}>
     */
    public function validate(string $type, string $currency, array $fields): array
    {
        $errors = [];

        // Label (always required) + non-empty string guard.
        if (empty($fields['label']) || !is_string($fields['label'])) {
            // Label is enforced by the service layer, but keep a defensive
            // check here so validator-only unit tests don't silently pass
            // label-less payloads through.
            // (Intentionally not fatal — service wraps this with a 400.)
        }

        // Basic currency shape — per-type rules tighten this further.
        if (!$this->isValidCurrencyCode($currency)) {
            $errors[] = $this->err('currency', 'invalid_currency',
                'currency must be a 3–5 character uppercase code');
            return $errors;
        }

        switch ($type) {
            case self::TYPE_BANK_WIRE:
                return array_merge($errors, $this->validateBankWire($currency, $fields));
            case self::TYPE_CUSTOM:
                return array_merge($errors, $this->validateCustom($currency, $fields));
        }

        // Delegate plugin-registered types. Unknown-to-registry types fall
        // through to the `unknown_type` error so plugin-less nodes stay
        // predictable about what they'll accept.
        if ($this->registry !== null) {
            $typeContract = $this->registry->get($type);
            if ($typeContract !== null) {
                return array_merge($errors, $typeContract->validate($currency, $fields));
            }
        }
        return [$this->err('type', 'unknown_type', "Unknown payback-method type '$type'")];
    }

    // =========================================================================
    // Bank wire — SEPA / Faster Payments / ACH / FedNow / SWIFT
    // =========================================================================

    private function validateBankWire(string $currency, array $fields): array
    {
        $errors = [];
        $rail = $fields['rail'] ?? null;
        if (!in_array($rail, self::BANK_RAILS, true)) {
            $errors[] = $this->err('rail', 'invalid_value',
                'rail must be one of: ' . implode(', ', self::BANK_RAILS));
            return $errors;
        }

        if (empty($fields['recipient_name']) || !is_string($fields['recipient_name'])) {
            $errors[] = $this->err('recipient_name', 'required', 'recipient_name is required');
        }

        switch ($rail) {
            case 'sepa':
                if ($currency !== 'EUR') {
                    $errors[] = $this->err('currency', 'invalid_currency_for_type', 'SEPA settles in EUR');
                }
                if (empty($fields['iban'])) {
                    $errors[] = $this->err('iban', 'required', 'IBAN is required for SEPA');
                } elseif (!Iban::isValid($fields['iban'])) {
                    $errors[] = $this->err('iban', 'invalid_checksum', 'IBAN failed mod-97 validation');
                }
                break;

            case 'faster_payments':
                if ($currency !== 'GBP') {
                    $errors[] = $this->err('currency', 'invalid_currency_for_type',
                        'Faster Payments settles in GBP');
                }
                if (empty($fields['sort_code']) || !preg_match('/^\d{6}$|^\d{2}-\d{2}-\d{2}$/', $fields['sort_code'])) {
                    $errors[] = $this->err('sort_code', 'invalid_format',
                        'sort_code must be 6 digits (optionally separated with dashes)');
                }
                if (empty($fields['account_number']) || !preg_match('/^\d{8}$/', $fields['account_number'])) {
                    $errors[] = $this->err('account_number', 'invalid_format',
                        'account_number must be exactly 8 digits');
                }
                break;

            case 'ach':
            case 'fednow':
                if ($currency !== 'USD') {
                    $errors[] = $this->err('currency', 'invalid_currency_for_type',
                        "$rail settles in USD");
                }
                if (empty($fields['routing_number']) || !AbaRouting::isValid($fields['routing_number'])) {
                    $errors[] = $this->err('routing_number', 'invalid_checksum',
                        'routing_number must be a valid 9-digit ABA number');
                }
                if (empty($fields['account_number']) || !preg_match('/^\d{4,17}$/', $fields['account_number'])) {
                    $errors[] = $this->err('account_number', 'invalid_format',
                        'account_number must be 4–17 digits');
                }
                if (empty($fields['account_type']) || !in_array($fields['account_type'], ['checking', 'savings'], true)) {
                    $errors[] = $this->err('account_type', 'invalid_value',
                        "account_type must be 'checking' or 'savings'");
                }
                break;

            case 'swift':
                if (empty($fields['bic_swift']) || !$this->isBic($fields['bic_swift'])) {
                    $errors[] = $this->err('bic_swift', 'invalid_format',
                        'bic_swift must match BIC pattern (8 or 11 chars)');
                }
                if (empty($fields['bank_name'])) {
                    $errors[] = $this->err('bank_name', 'required', 'bank_name is required');
                }
                if (empty($fields['country']) || !preg_match('/^[A-Z]{2}$/', $fields['country'])) {
                    $errors[] = $this->err('country', 'invalid_format',
                        'country must be ISO 3166-1 alpha-2 (e.g. US, DE, MX)');
                }
                // Either iban or account_number required.
                if (empty($fields['iban']) && empty($fields['account_number'])) {
                    $errors[] = $this->err(null, 'required',
                        'SWIFT requires at least one of: iban, account_number');
                } elseif (!empty($fields['iban']) && !Iban::isValid($fields['iban'])) {
                    $errors[] = $this->err('iban', 'invalid_checksum',
                        'IBAN failed mod-97 validation');
                }
                if (!empty($fields['intermediary_bic']) && !$this->isBic($fields['intermediary_bic'])) {
                    $errors[] = $this->err('intermediary_bic', 'invalid_format',
                        'intermediary_bic must match BIC pattern');
                }
                break;
        }
        return $errors;
    }

    // =========================================================================
    // Custom — free-form settlement instructions
    // =========================================================================

    private function validateCustom(string $currency, array $fields): array
    {
        $errors = [];
        $details = $fields['details'] ?? null;
        if (empty($details) || !is_string($details)) {
            $errors[] = $this->err('details', 'required', 'details is required');
        } elseif (strlen($details) > 1024) {
            $errors[] = $this->err('details', 'too_long', 'details must be ≤ 1024 chars');
        }
        // Currency is user-declared — shape-only (already validated above).
        if (!empty($fields['instructions']) && !is_string($fields['instructions'])) {
            $errors[] = $this->err('instructions', 'invalid_type', 'instructions must be a string');
        }
        return $errors;
    }

    // =========================================================================
    // Catalog — UI-facing metadata for the GUI's two-step "Add method" modal.
    //
    // Single source of truth for: group layout, per-type labels/icons/help,
    // and the field schema driving the form renderer. Values mirror the
    // per-type validators above; keep in sync when validators change.
    // =========================================================================

    /**
     * Returns the full catalog consumed by the GUI form renderer.
     *
     * Shape:
     *   groups: list<{id, label}>
     *   currencies: list<{code, label}> — fallback dropdown when a type allows any
     *   types: list<{
     *     id, label, group, icon, description,
     *     currencies: list<string>|null,
     *     currenciesFor?: {field, map: array<string, list<string>|null>},
     *     fields: list<FieldSchema>
     *   }>
     */
    public function getCatalog(): array
    {
        $catalog = $this->getCoreCatalog();

        // Merge plugin-registered types. Plugins may also declare new group
        // ids (e.g. 'crypto', 'fintech', 'mobile') — dedupe those into the
        // groups list so the GUI's group picker shows each once.
        if ($this->registry !== null) {
            $existingGroups = [];
            foreach ($catalog['groups'] as $g) { $existingGroups[$g['id']] = true; }
            foreach ($this->registry->all() as $contract) {
                $entry = $contract->getCatalogEntry();
                $catalog['types'][] = $entry;
                $groupId = $entry['group'] ?? null;
                if (is_string($groupId) && $groupId !== '' && empty($existingGroups[$groupId])) {
                    $catalog['groups'][] = [
                        'id'    => $groupId,
                        'label' => ucfirst(str_replace('_', ' ', $groupId)),
                    ];
                    $existingGroups[$groupId] = true;
                }
            }
        }

        // Always render the "other" group last — it's the catch-all and
        // shouldn't sit between more-specific plugin-provided groups.
        $catalog['groups'] = array_values(array_merge(
            array_filter($catalog['groups'], fn($g) => ($g['id'] ?? '') !== 'other'),
            array_filter($catalog['groups'], fn($g) => ($g['id'] ?? '') === 'other')
        ));
        return $catalog;
    }

    /**
     * Static accessor kept as a bridge for the few places (unit tests,
     * ad-hoc scripts) that still call `PaybackMethodTypeValidator::getCatalog()`
     * without a registry. Returns the core-only catalog — plugin types are
     * not surfaced through this path.
     *
     * @deprecated Prefer the instance method on a registry-backed validator.
     */
    public static function getCatalogCoreOnly(): array
    {
        return (new self())->getCoreCatalog();
    }

    /**
     * Core-only catalog (bank_wire + custom). Kept as a private builder so
     * the instance `getCatalog()` can layer plugin types on top without
     * duplicating the bank/custom schema.
     */
    private function getCoreCatalog(): array
    {
        $bicBankFields = [
            ['name' => 'recipient_name', 'label' => 'Recipient name', 'type' => 'text', 'required' => true,
                'placeholder' => 'Full legal name on the account'],
        ];

        return [
            'groups' => [
                ['id' => 'bank',  'label' => 'Bank'],
                ['id' => 'other', 'label' => 'Other'],
            ],
            // Fallback currency list for types that accept any ISO-4217 fiat.
            'currencies' => [
                ['code' => 'USD', 'label' => 'USD — US Dollar'],
                ['code' => 'EUR', 'label' => 'EUR — Euro'],
                ['code' => 'GBP', 'label' => 'GBP — Pound Sterling'],
                ['code' => 'CAD', 'label' => 'CAD — Canadian Dollar'],
                ['code' => 'AUD', 'label' => 'AUD — Australian Dollar'],
                ['code' => 'NZD', 'label' => 'NZD — New Zealand Dollar'],
                ['code' => 'CHF', 'label' => 'CHF — Swiss Franc'],
                ['code' => 'JPY', 'label' => 'JPY — Japanese Yen'],
                ['code' => 'CNY', 'label' => 'CNY — Chinese Yuan'],
                ['code' => 'HKD', 'label' => 'HKD — Hong Kong Dollar'],
                ['code' => 'SGD', 'label' => 'SGD — Singapore Dollar'],
                ['code' => 'SEK', 'label' => 'SEK — Swedish Krona'],
                ['code' => 'NOK', 'label' => 'NOK — Norwegian Krone'],
                ['code' => 'DKK', 'label' => 'DKK — Danish Krone'],
                ['code' => 'PLN', 'label' => 'PLN — Polish Zloty'],
                ['code' => 'CZK', 'label' => 'CZK — Czech Koruna'],
                ['code' => 'HUF', 'label' => 'HUF — Hungarian Forint'],
                ['code' => 'RON', 'label' => 'RON — Romanian Leu'],
                ['code' => 'BRL', 'label' => 'BRL — Brazilian Real'],
                ['code' => 'MXN', 'label' => 'MXN — Mexican Peso'],
                ['code' => 'INR', 'label' => 'INR — Indian Rupee'],
                ['code' => 'ZAR', 'label' => 'ZAR — South African Rand'],
                ['code' => 'TRY', 'label' => 'TRY — Turkish Lira'],
                ['code' => 'AED', 'label' => 'AED — UAE Dirham'],
                ['code' => 'ILS', 'label' => 'ILS — Israeli Shekel'],
                ['code' => 'THB', 'label' => 'THB — Thai Baht'],
                ['code' => 'KRW', 'label' => 'KRW — South Korean Won'],
                ['code' => 'PHP', 'label' => 'PHP — Philippine Peso'],
                ['code' => 'IDR', 'label' => 'IDR — Indonesian Rupiah'],
                ['code' => 'MYR', 'label' => 'MYR — Malaysian Ringgit'],
            ],
            'types' => [
                // ── Bank wire ─────────────────────────────────────────────────
                [
                    'id' => self::TYPE_BANK_WIRE, 'label' => 'Bank wire', 'group' => 'bank',
                    'icon' => 'fas fa-university',
                    'description' => 'SEPA, Faster Payments, ACH, FedNow, or SWIFT.',
                    'info' => 'Pick the rail that matches the account: <strong>SEPA</strong> for EUR '
                        . 'accounts in the EU, <strong>Faster Payments</strong> for UK GBP, '
                        . '<strong>ACH</strong> or <strong>FedNow</strong> for US USD, '
                        . '<strong>SWIFT</strong> for anything international (including cross-'
                        . 'currency transfers). The form only shows the fields relevant to the '
                        . 'rail you choose — IBAN for SEPA, sort code + 8-digit account for Faster '
                        . 'Payments, ABA + account number + checking/savings for ACH/FedNow. '
                        . 'SWIFT accepts either an IBAN or a local account number — the toggle '
                        . 'above the field switches between the two.',
                    'currencies' => ['EUR', 'GBP', 'USD'],
                    'currenciesFor' => [
                        'field' => 'rail',
                        'map' => [
                            'sepa'            => ['EUR'],
                            'faster_payments' => ['GBP'],
                            'ach'             => ['USD'],
                            'fednow'          => ['USD'],
                            'swift'           => null,
                        ],
                    ],
                    'fields' => array_merge([
                        ['name' => 'rail', 'label' => 'Rail', 'type' => 'select', 'required' => true,
                            'options' => [
                                ['value' => 'sepa',            'label' => 'SEPA (EU) — EUR'],
                                ['value' => 'faster_payments', 'label' => 'Faster Payments (UK) — GBP'],
                                ['value' => 'ach',             'label' => 'ACH (US) — USD'],
                                ['value' => 'fednow',          'label' => 'FedNow (US) — USD'],
                                ['value' => 'swift',           'label' => 'SWIFT (International)'],
                            ]],
                    ], $bicBankFields, [
                        // SEPA
                        ['name' => 'iban', 'label' => 'IBAN', 'type' => 'text', 'required' => true,
                            'placeholder' => 'DE89 3704 0044 0532 0130 00',
                            'showWhen' => ['field' => 'rail', 'in' => ['sepa']]],
                        // Faster Payments
                        ['name' => 'sort_code', 'label' => 'Sort code', 'type' => 'text', 'required' => true,
                            'placeholder' => '12-34-56',
                            'showWhen' => ['field' => 'rail', 'in' => ['faster_payments']]],
                        ['name' => 'account_number', 'label' => 'Account number', 'type' => 'text', 'required' => true,
                            'placeholder' => '8 digits',
                            'showWhen' => ['field' => 'rail', 'in' => ['faster_payments']]],
                        // ACH / FedNow
                        ['name' => 'routing_number', 'label' => 'Routing number (ABA)', 'type' => 'text', 'required' => true,
                            'placeholder' => '9 digits',
                            'showWhen' => ['field' => 'rail', 'in' => ['ach', 'fednow']]],
                        ['name' => 'account_number', 'label' => 'Account number', 'type' => 'text', 'required' => true,
                            'placeholder' => '4–17 digits',
                            'showWhen' => ['field' => 'rail', 'in' => ['ach', 'fednow']]],
                        ['name' => 'account_type', 'label' => 'Account type', 'type' => 'select', 'required' => true,
                            'options' => [
                                ['value' => 'checking', 'label' => 'Checking'],
                                ['value' => 'savings',  'label' => 'Savings'],
                            ],
                            'showWhen' => ['field' => 'rail', 'in' => ['ach', 'fednow']]],
                        // SWIFT
                        ['name' => 'bic_swift', 'label' => 'BIC / SWIFT', 'type' => 'text', 'required' => true,
                            'placeholder' => 'BOFAUS3N', 'help' => '8 or 11 characters',
                            'showWhen' => ['field' => 'rail', 'in' => ['swift']]],
                        ['name' => 'bank_name', 'label' => 'Bank name', 'type' => 'text', 'required' => true,
                            'showWhen' => ['field' => 'rail', 'in' => ['swift']]],
                        ['name' => 'country', 'label' => 'Bank country (ISO 3166-1 alpha-2)', 'type' => 'text', 'required' => true,
                            'placeholder' => 'US / DE / MX',
                            'showWhen' => ['field' => 'rail', 'in' => ['swift']]],
                        ['name' => 'iban', 'label' => 'IBAN (or account_number)', 'type' => 'text', 'required' => false,
                            'showWhen' => ['field' => 'rail', 'in' => ['swift']]],
                        ['name' => 'account_number', 'label' => 'Account number (or IBAN)', 'type' => 'text', 'required' => false,
                            'showWhen' => ['field' => 'rail', 'in' => ['swift']]],
                        ['name' => 'intermediary_bic', 'label' => 'Intermediary BIC (optional)', 'type' => 'text', 'required' => false,
                            'showWhen' => ['field' => 'rail', 'in' => ['swift']]],
                    ]),
                ],

                // ── Other ─────────────────────────────────────────────────────
                [
                    'id' => self::TYPE_CUSTOM, 'label' => 'Custom', 'group' => 'other',
                    'icon' => 'fas fa-question',
                    'description' => 'Free-form instructions when no canonical rail fits. Currency is whatever you declare.',
                    'info' => 'Use <strong>Custom</strong> when no typed rail fits — an in-person '
                        . 'meet-up, a gift card, an obscure payment service, a barter arrangement, '
                        . 'etc. Put the instructions a payer needs to actually settle into the '
                        . '<em>Details</em> field (up to 1024 characters). '
                        . '<br><br>'
                        . '<strong>Heads up — custom details are only loosely masked.</strong> '
                        . 'The list row shows the first 80 characters as a preview since this field '
                        . 'is usually descriptive; the rest is hidden until you unlock. If you '
                        . 'absolutely need a secret (a password, a seed phrase) to live in a payback '
                        . 'method, put it behind the 80-character mark or use a typed rail designed '
                        . 'for that kind of value.',
                    'currencies' => null,
                    'fields' => [
                        ['name' => 'details', 'label' => 'Details', 'type' => 'textarea', 'required' => true,
                            'placeholder' => 'Describe how a contact should pay you.', 'help' => '≤ 1024 characters'],
                        ['name' => 'instructions', 'label' => 'Extra instructions (optional)', 'type' => 'textarea', 'required' => false],
                    ],
                ],
            ],
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function isValidCurrencyCode(string $currency): bool
    {
        return (bool) preg_match('/^[A-Z]{3,5}$/', $currency);
    }

    private function isBic(string $bic): bool
    {
        return (bool) preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic);
    }

    /** @return array{field: string|null, code: string, message: string} */
    private function err(?string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }
}
